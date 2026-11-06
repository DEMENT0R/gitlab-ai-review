# История изменений

Все заметные изменения проекта **AI-ревью merge request** (вебхук GitLab → PHP → LLM → комментарий в MR).
Записи сгруппированы по датам коммитов (от новых к старым), внутри даты — в обратном хронологическом порядке.

## 2026-11-05

### Добавлено

- **`.gitignore`** (`2fe54b6`) — исключены секреты (`config.php`, `.env`, ключи), рантайм-артефакты вебхука
  (лог и dedup-файл), временные файлы, зависимости и настройки IDE/ОС.

## 2026-11-03

### Добавлено

- **Основная механика ревью MR через DeepSeek** (`1ab5ee0`)
  - `gitlab-mr-review.php`: вебхук Merge request events с проверкой signing token.
  - Триггеры: `open`, `update` (новые коммиты), снятие Draft.
  - Выгрузка diff из GitLab, ревью в DeepSeek и публикация одного обновляемого комментария
    в MR (маркер `note_marker`).
  - Локальная проверка без сети: `--dry-run`, `--payload`; dedup через seen-файл; CLI-режим
    `--project`/`--mr`.
  - `config.example.php` — шаблон конфига.
- **Поддержка LLM-провайдеров кроме DeepSeek** (`1f81381`)
  - Реестр `providers`: `driver`, `label`, `api_key`, `base_url`, `model` и лимиты.
  - Драйвер `openai_compatible` (DeepSeek, OpenAI, OpenRouter, Ollama, vLLM).
  - Выбор провайдера: `ai_provider`, переменная `AI_PROVIDER`, флаг `--provider=имя`.
  - `--list-providers` показывает реестр и активного провайдера.
  - Устаревшие ключи `deepseek_*` продолжают работать.
  - `label` и модель провайдера выводятся в заголовке комментария и в логе.
- **README и примеры payload'ов для локальной проверки** (`b470e2e`)
  - `README.md`: правила срабатывания, установка, настройка FPM (`request_terminate_timeout`),
    описание конфига.
  - `examples/`: `mr-open`, `mr-update-commits`, `mr-ready`, `mr-draft` и `diffs.json`
    для прогона `--payload=... --diffs=... --dry-run`.

### Изменено

- **README и пример конфига под работу с провайдерами** (`c677734`)
  - Раздел о выборе и добавлении провайдеров.
  - Таблица настроек `providers.*` и `ai_provider`.
  - Примеры OpenAI, OpenRouter и Ollama в `config.example.php` (закомментированы).
