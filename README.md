# AI-ревью merge request

Вебхук GitLab → PHP → LLM (по умолчанию DeepSeek) → комментарий в MR.

Ревью запускается, когда:

| Событие | Условие | `reason` в логе |
|---|---|---|
| MR создан | `action=open` и MR не черновик | `created` |
| В MR добавлены коммиты | `action=update` + `object_attributes.oldrev` | `commits` |
| С MR сняли Draft/WIP | `changes.draft.current=false` или `changes.title` без префикса | `ready` |

Черновики (`draft`, `work_in_progress`, заголовок `Draft:`/`WIP:`) пропускаются, закрытые и смерженные — тоже.
Текст задачи берётся из **описания MR**; если оно пустое, ревью не запускается, а в MR пишется просьба добавить описание (`require_description`).

## Провайдеры

Из коробки настроен только DeepSeek (`deepseek-chat`), он же используется по умолчанию. Модель выбирается в `config.php`:

```php
'ai_provider' => 'deepseek',

'providers' => [
    'deepseek' => [
        'driver' => 'openai_compatible',
        'label' => 'DeepSeek',
        'api_key' => '...',
        'base_url' => 'https://api.deepseek.com',
        'model' => 'deepseek-chat',
        'max_tokens' => 4000,
        'timeout' => 300,
    ],
],
```

Другой провайдер добавляется **без правки кода** — новой записью в `providers`, если у его API совместимый протокол (`POST {base_url}/chat/completions`): OpenAI, OpenRouter, Ollama, vLLM, корпоративный шлюз. Примеры есть в `config.example.php`. Затем переключите `ai_provider` (или задайте `AI_PROVIDER` в окружении), либо запустите разово:

```bash
php gitlab-mr-review.php --project=42 --mr=17 --provider=openai
php gitlab-mr-review.php --list-providers
```

Что важно знать:

- `driver` — протокол общения с API. Сейчас реализован один: `openai_compatible`. Для API с другим протоколом (например, Anthropic Messages API) добавьте новую функцию-драйвер и запись в `llm_drivers()`.
- `api_key` необязателен при `'require_api_key' => false` — так подключаются локальные модели (Ollama).
- `base_url` можно указывать с `/v1` или сразу с `/chat/completions` — оба варианта работают.
- Старый формат конфига (`deepseek_api_key`, `deepseek_model`, …) продолжает работать: значения подставляются в `providers.deepseek`, если там поле не задано. В новом конфиге используйте `providers`.
- В заголовке комментария и в логе пишется `label` провайдера и имя модели, поэтому видно, кто именно делал ревью.

## Установка

1. PHP 8.0+, `ext-curl`. Каталог скрипта должен быть доступен на запись пользователю php-fpm (лог и dedup-файл).
2. Скопировать `gitlab-mr-review.php` и сделать `config.php` из `config.example.php`, заполнить токены.
3. GitLab-токен (project или group access token, scope `api`) должен иметь минимум роль Developer в проекте: читать MR/diff и писать комментарии. Лучше завести отдельного бота — иначе комментарий будет от вашего имени.
4. Settings → Webhooks: URL на `gitlab-mr-review.php`, Trigger = **Merge request events**, **Signing token** = `webhook_signing_token` в конфиге.
5. Проверить без сети и без GitLab:

```bash
php gitlab-mr-review.php --payload=examples/mr-open.json --diffs=examples/diffs.json --dry-run
php gitlab-mr-review.php --list-providers
php gitlab-mr-review.php --project=42 --mr=17        # боевой прогон
```

## Как это не мешает GitLab

Вебхук отвечает `202` сразу, а ревью делает после `fastcgi_finish_request()`. Но FPM убивает запрос по `request_terminate_timeout` (по умолчанию 30 с), поэтому в пуле:

```ini
request_terminate_timeout = 300
```

Скрипт рассчитан на nginx + php-fpm. Под Apache mod_php `flush()` не закрывает соединение, и GitLab будет ждать ответа до таймаута вебхука — тогда лучше вынести запуск в отдельный CLI-воркер по cron.

## Настройки (`config.php`)

| Ключ | По умолчанию | Смысл |
|---|---|---|
| `gitlab_url` | — | базовый URL инстанса |
| `gitlab_token` | — | токен `api` |
| `ai_provider` | `deepseek` | имя записи из `providers` |
| `providers.<имя>.driver` | `openai_compatible` | протокол запроса |
| `providers.<имя>.label` | имя | как провайдер подписан в комментарии |
| `providers.<имя>.api_key` | — | ключ провайдера |
| `providers.<имя>.base_url` | — | базовый URL; можно свой шлюз/прокси |
| `providers.<имя>.model` | — | `deepseek-chat`; `deepseek-reasoner` даёт более въедливое ревью, но дороже и медленнее |
| `providers.<имя>.max_tokens` / `timeout` | 4000 / 300 | параметры запроса |
| `providers.<имя>.require_api_key` | `true` | `false` — для локальных моделей без ключа |
| `webhook_signing_token` | — | `whsec_...` из настроек вебхука |
| `require_description` | `true` | не ревьюить MR без описания |
| `post_note` | `true` | публиковать результат в MR |
| `note_marker` | `<!-- deepseek-review -->` | маркер комментария бота; не зависит от провайдера |
| `max_diff_chars` / `max_file_diff_chars` | 120000 / 20000 | бюджет промпта |

## Поведение

- Комментарий в MR один: он помечен `<!-- deepseek-review -->`, при следующем пуше **обновляется**, а не дублируется. При смене провайдера обновляется тот же комментарий.
- Повторные доставки вебхука отсекаются по `webhook-id` (`seen_file`), подпись проверяется по HMAC-SHA256 + окно 5 минут от replay.
- В лог (`gitlab-mr-review.log`) пишутся решение по каждому событию, провайдер и модель, размер промпта, ответ и ошибки; текст ревью — нет.
- Diff обрезается по бюджету: при огромных MR модель увидит не все файлы (в промпте это явно сказано).

## Ограничения и что улучшить

- Замечания публикуются одним общим комментарием. Построчные (inline) замечания — через discussions API с `position`, это отдельная доработка.
- Каждый пуш = один вызов модели. Если нужно реже — фильтруйте по метке (`payload.labels`) или проверяйте, что изменились не только `*.md`.
- MR из форка: ревью идёт по target-проекту, diff доступен токену с ролью Developer; секреты в промпт не попадают (только код и текст задачи).
- Не является «required check»: чтобы MR нельзя было смержить без ревью, нужен external status check (Ultimate) либо job в CI, дергающий этот же скрипт.
- Реализован только транспорт `openai_compatible`; остальные протоколы добавляются в `llm_drivers()`.
