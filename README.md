# DeepSeek review для merge request

Вебхук GitLab → PHP → DeepSeek → комментарий в MR.

Ревью запускается, когда:

| Событие | Условие | `reason` в логе |
|---|---|---|
| MR создан | `action=open` и MR не черновик | `created` |
| В MR добавлены коммиты | `action=update` + `object_attributes.oldrev` | `commits` |
| С MR сняли Draft/WIP | `changes.draft.current=false` или `changes.title` без префикса | `ready` |

Черновики (`draft`, `work_in_progress`, заголовок `Draft:`/`WIP:`) пропускаются, закрытые и смерженные — тоже.
Текст задачи берётся из **описания MR**; если оно пустое, ревью не запускается, а в MR пишется просьба добавить описание (`require_description`).

## Установка

1. PHP 8.0+, `ext-curl`. Каталог скрипта должен быть доступен на запись пользователю php-fpm (лог и dedup-файл).
2. Скопировать `gitlab-mr-review.php` и сделать `config.php` из `config.example.php`, заполнить токены.
3. GitLab-токен (project или group access token, scope `api`) должен иметь минимум роль Developer в проекте: читать MR/diff и писать комментарии. Лучше завести отдельного бота — иначе комментарий будет от вашего имени.
4. Settings → Webhooks: URL на `gitlab-mr-review.php`, Trigger = **Merge request events**, **Signing token** = `webhook_signing_token` в конфиге.
5. Проверить без сети и без GitLab:

```bash
php gitlab-mr-review.php --payload=examples/mr-open.json --diffs=examples/diffs.json --dry-run
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
| `deepseek_api_key` | — | ключ DeepSeek |
| `deepseek_base_url` | `https://api.deepseek.com` | можно свой шлюз/прокси |
| `deepseek_model` | `deepseek-chat` | `deepseek-reasoner` даёт более въедливое ревью, но дороже и медленнее |
| `webhook_signing_token` | — | `whsec_...` из настроек вебхука |
| `require_description` | `true` | не ревьюить MR без описания |
| `post_note` | `true` | публиковать результат в MR |
| `max_diff_chars` / `max_file_diff_chars` | 120000 / 20000 | бюджет промпта |
| `deepseek_max_tokens` / `deepseek_timeout` | 4000 / 300 | параметры запроса |

## Поведение

- Комментарий в MR один: он помечен `<!-- deepseek-review -->`, при следующем пуше **обновляется**, а не дублируется.
- Повторные доставки вебхука отсекаются по `webhook-id` (`seen_file`), подпись проверяется по HMAC-SHA256 + окно 5 минут от replay.
- В лог (`gitlab-mr-review.log`) пишутся решение по каждому событию, промпт (размер), ответ и ошибки; текст ревью — нет.
- Diff обрезается по бюджету: при огромных MR модель увидит не все файлы (в промпте это явно сказано).

## Ограничения и что улучшить

- Замечания публикуются одним общим комментарием. Построчные (inline) замечания — через discussions API с `position`, это отдельная доработка.
- Каждый пуш = один вызов DeepSeek. Если нужно реже — фильтруйте по метке (`payload.labels`) или проверяйте, что изменились не только `*.md`.
- MR из форка: ревью идёт по target-проекту, diff доступен токену с ролью Developer; секреты в промпт не попадают (только код и текст задачи).
- Не является «required check»: чтобы MR нельзя было смержить без ревью, нужен external status check (Ultimate) либо job в CI, дергающий этот же скрипт.
