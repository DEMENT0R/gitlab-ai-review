<?php
declare(strict_types=1);

/**
 * GitLab MR -> DeepSeek review.
 *
 * Ловит вебхук "Merge request events" и запускает ревью в DeepSeek, когда:
 *   - MR создан и он НЕ черновик (action=open, draft=false);
 *   - в MR добавлены коммиты (action=update, object_attributes.oldrev заполнен);
 *   - MR перестал быть черновиком (снят Draft:/WIP:/флаг draft).
 * Текст задачи берётся из описания MR (description).
 *
 * Результат публикуется комментарием в MR. Один и тот же комментарий
 * обновляется при каждом следующем прогоне (ищется по маркеру note_marker),
 * поэтому MR не зарастает дублями.
 *
 * Webhook: Settings -> Webhooks -> URL на этот файл, Trigger = "Merge request events",
 * Signing token = секрет в webhook_signing_token.
 *
 * CLI:
 *   php gitlab-mr-review.php --project=42 --mr=17       принудительное ревью
 *   php gitlab-mr-review.php --payload=f.json --dry-run  проверить решение, без сети
 *   php gitlab-mr-review.php --help
 */

// ================================ CONFIG ================================

$CONFIG = [
    // DeepSeek (https://platform.deepseek.com)
    'deepseek_api_key' => (string) (getenv('DEEPSEEK_API_KEY') ?: ''),
    'deepseek_base_url' => (string) (getenv('DEEPSEEK_BASE_URL') ?: 'https://api.deepseek.com'),
    'deepseek_model' => (string) (getenv('DEEPSEEK_MODEL') ?: 'deepseek-chat'),
    'deepseek_max_tokens' => 4000,
    'deepseek_timeout' => 300,

    // GitLab: токен с правами api (project/group access token или PAT бота)
    'gitlab_url' => (string) (getenv('GITLAB_URL') ?: 'https://gitlab.example.com'),
    'gitlab_token' => (string) (getenv('GITLAB_TOKEN') ?: ''),

    // Проверка подлинности вебхука (Settings -> Webhooks)
    'webhook_signing_token' => (string) (getenv('GITLAB_WEBHOOK_SIGNING_TOKEN') ?: ''),
    'webhook_legacy_secret' => (string) (getenv('GITLAB_WEBHOOK_SECRET') ?: ''),
    'webhook_max_skew' => 300,

    // Поведение
    'require_description' => true,   // без описания ревью не запускать
    'post_note' => true,             // публиковать результат в MR
    'note_marker' => '<!-- deepseek-review -->',

    // Бюджет на diff (символы, не токены)
    'max_diff_chars' => 120000,
    'max_file_diff_chars' => 20000,

    'log_file' => __DIR__ . '/gitlab-mr-review.log',
    'seen_file' => __DIR__ . '/gitlab-mr-review-seen.json',
    'seen_limit' => 500,
];

// Секреты лучше держать в отдельном файле (вне git):
// return ['gitlab_token' => '...', 'deepseek_api_key' => '...', ...];
$configFile = __DIR__ . '/config.php';
if (is_file($configFile)) {
    $override = require $configFile;
    if (is_array($override)) {
        $CONFIG = array_merge($CONFIG, $override);
    }
}

// ================================ HELPERS ===============================

function log_line(array $cfg, string $line): void
{
    @file_put_contents(
        $cfg['log_file'],
        sprintf("[%s] %s\n", date('c'), $line),
        FILE_APPEND | LOCK_EX
    );
}

function respond(int $status, string $body = ''): void
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    if ($body !== '') {
        echo $body;
    }
    exit;
}

/**
 * Ответить 2xx и продолжить работу после того, как клиент получил ответ.
 * GitLab отключает вебхуки, которые отвечают медленно, поэтому отвечаем сразу.
 */
function respond_async(int $status, string $body = ''): void
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    header('Connection: close');
    if ($body !== '') {
        echo $body;
    }

    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();

        return;
    }

    ignore_user_abort(true);
    if (function_exists('litespeed_finish_request')) {
        litespeed_finish_request();

        return;
    }
    flush();
}

function request_header(string $name): string
{
    $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));

    return (string) ($_SERVER[$key] ?? '');
}

/**
 * Standard Webhooks: webhook-signature = "v1," . base64(hmac_sha256(key, "{id}.{ts}.{body}")),
 * key = base64_decode(signing_token без префикса "whsec_").
 */
function verify_signature(array $cfg, string $raw): bool
{
    $id = request_header('webhook-id');
    $ts = request_header('webhook-timestamp');
    $sig = request_header('webhook-signature');

    if ($id === '' || $ts === '' || $sig === '') {
        return false;
    }
    if (abs(time() - (int) $ts) > (int) $cfg['webhook_max_skew']) {
        return false;
    }

    $token = (string) $cfg['webhook_signing_token'];
    if (str_starts_with($token, 'whsec_')) {
        $token = substr($token, strlen('whsec_'));
    }
    $key = base64_decode($token, true);
    if ($key === false || $key === '') {
        return false;
    }

    $expected = 'v1,' . base64_encode(hash_hmac('sha256', $id . '.' . $ts . '.' . $raw, $key, true));

    foreach (explode(' ', $sig) as $candidate) {
        if (hash_equals($expected, trim($candidate))) {
            return true;
        }
    }

    return false;
}

function verify_legacy(array $cfg): bool
{
    $got = request_header('X-Gitlab-Token');

    return $got !== '' && hash_equals((string) $cfg['webhook_legacy_secret'], $got);
}

/**
 * GitLab повторяет доставку при таймауте/5xx — не делаем ревью дважды.
 */
function already_seen(array $cfg, string $id): bool
{
    if ($id === '') {
        return false;
    }

    $fp = @fopen($cfg['seen_file'], 'c+');
    if ($fp === false) {
        return false;
    }

    try {
        if (!flock($fp, LOCK_EX)) {
            return false;
        }

        $seen = [];
        $stat = fstat($fp);
        if ($stat !== false && $stat['size'] > 0) {
            $json = stream_get_contents($fp);
            $seen = is_string($json) ? (json_decode($json, true) ?: []) : [];
        }

        if (in_array($id, $seen, true)) {
            return true;
        }

        $seen[] = $id;
        $seen = array_slice($seen, -(int) $cfg['seen_limit']);

        rewind($fp);
        ftruncate($fp, 0);
        fwrite($fp, json_encode($seen, JSON_UNESCAPED_SLASHES));
        fflush($fp);

        return false;
    } finally {
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}

/**
 * @return array{0:int, 1:string, 2:array<string,string>} [http code, body, headers]
 */
function http_request(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 60): array
{
    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('curl_init failed');
    }

    $opts = [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_FOLLOWLOCATION => false,
    ];
    if ($body !== null) {
        $opts[CURLOPT_POSTFIELDS] = $body;
    }

    $responseHeaders = [];
    $opts[CURLOPT_HEADERFUNCTION] = static function ($ch, string $line) use (&$responseHeaders): int {
        $parts = explode(':', $line, 2);
        if (count($parts) === 2) {
            $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
        }

        return strlen($line);
    };

    curl_setopt_array($ch, $opts);

    $raw = curl_exec($ch);
    if ($raw === false) {
        throw new RuntimeException(sprintf('curl %s %s: %s', $method, $url, curl_error($ch)));
    }

    return [(int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), (string) $raw, $responseHeaders];
}

/**
 * @return array{0:mixed, 1:array<string,string>}
 */
function gitlab_request(array $cfg, string $method, string $path, array $query = [], ?array $jsonBody = null): array
{
    $url = rtrim((string) $cfg['gitlab_url'], '/') . '/api/v4' . $path;
    if ($query !== []) {
        $url .= '?' . http_build_query($query);
    }

    $headers = ['PRIVATE-TOKEN: ' . $cfg['gitlab_token'], 'Accept: application/json'];
    $body = null;
    if ($jsonBody !== null) {
        $headers[] = 'Content-Type: application/json';
        $body = json_encode($jsonBody, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    [$code, $raw, $headersOut] = http_request($method, $url, $headers, $body, 60);
    if ($code < 200 || $code >= 300) {
        throw new RuntimeException(sprintf(
            'GitLab API %s %s -> HTTP %d: %s',
            $method,
            $path,
            $code,
            mb_substr($raw, 0, 500)
        ));
    }

    return [json_decode($raw, true), $headersOut];
}

function gitlab_get(array $cfg, string $path, array $query = []): array
{
    [$data] = gitlab_request($cfg, 'GET', $path, $query);

    return is_array($data) ? $data : [];
}

function fetch_diffs_page(array $cfg, int $projectId, int $iid, string $endpoint): array
{
    $diffs = [];
    $page = 1;

    do {
        [$data, $headers] = gitlab_request(
            $cfg,
            'GET',
            sprintf('/projects/%d/merge_requests/%d/%s', $projectId, $iid, $endpoint),
            ['per_page' => 100, 'page' => $page]
        );
        if (!is_array($data)) {
            break;
        }
        foreach ($data as $item) {
            if (is_array($item)) {
                $diffs[] = $item;
            }
        }
        $next = (int) ($headers['x-next-page'] ?? 0);
        $page = $next > 0 ? $next : 0;
    } while ($page > 0 && $page <= 20);

    return $diffs;
}

/**
 * Diff MR: сначала /diffs (новый endpoint), при 404 — /changes (старый).
 */
function fetch_diffs(array $cfg, int $projectId, int $iid): array
{
    try {
        return fetch_diffs_page($cfg, $projectId, $iid, 'diffs');
    } catch (RuntimeException $e) {
        log_line($cfg, 'diffs endpoint failed, fallback to changes: ' . $e->getMessage());
        $data = gitlab_get($cfg, sprintf('/projects/%d/merge_requests/%d/changes', $projectId, $iid));
        $changes = $data['changes'] ?? [];

        return is_array($changes) ? $changes : [];
    }
}

function is_draft_title(string $title): bool
{
    return (bool) preg_match('/^\s*(?:\[|\()?\s*(?:draft|wip)\b/i', $title);
}

function is_draft(array $mr): bool
{
    if (!empty($mr['draft']) || !empty($mr['work_in_progress'])) {
        return true;
    }

    return is_draft_title((string) ($mr['title'] ?? ''));
}

/**
 * Решение по вебхуку: запускать ли ревью и почему.
 *
 * @return array{0:bool, 1:string}
 */
function decide(array $payload, array $cfg): array
{
    if (($payload['object_kind'] ?? '') !== 'merge_request') {
        return [false, 'not a merge request event'];
    }

    $mr = $payload['object_attributes'] ?? [];
    if (!is_array($mr)) {
        return [false, 'no object_attributes'];
    }

    $action = (string) ($mr['action'] ?? '');
    $state = (string) ($mr['state'] ?? 'opened');
    if ($state !== 'opened') {
        return [false, 'state=' . $state];
    }

    $changes = $payload['changes'] ?? [];
    $changes = is_array($changes) ? $changes : [];

    // Коммиты добавили: у update-события появляется oldrev.
    $commitsPushed = !empty($mr['oldrev']) || isset($changes['oldrev']);

    // Черновик сняли.
    $becameReady = false;
    foreach (['draft', 'work_in_progress'] as $key) {
        if (
            isset($changes[$key]) && is_array($changes[$key])
            && empty($changes[$key]['current']) && !empty($changes[$key]['previous'])
        ) {
            $becameReady = true;
        }
    }
    if (isset($changes['title']) && is_array($changes['title'])) {
        $before = (string) ($changes['title']['previous'] ?? '');
        $after = (string) ($changes['title']['current'] ?? '');
        if (is_draft_title($before) && !is_draft_title($after)) {
            $becameReady = true;
        }
    }

    if (is_draft($mr)) {
        return [false, 'draft'];
    }

    $triggers = [];
    if ($action === 'open') {
        $triggers[] = 'created';
    }
    if ($commitsPushed) {
        $triggers[] = 'commits';
    }
    if ($becameReady) {
        $triggers[] = 'ready';
    }

    if ($triggers === []) {
        return [false, 'action=' . $action . ' (без новых коммитов)'];
    }

    return [true, implode('+', $triggers)];
}

/**
 * @return array{0:string, 1:string} [system prompt, user prompt]
 */
function build_prompt(array $cfg, array $mr, array $diffs): array
{
    $task = trim((string) ($mr['description'] ?? ''));
    $title = (string) ($mr['title'] ?? '');
    $source = (string) ($mr['source_branch'] ?? '');
    $target = (string) ($mr['target_branch'] ?? '');
    $author = (string) ($mr['author']['username'] ?? '');
    $url = (string) ($mr['web_url'] ?? '');

    $system = <<<'TXT'
Ты — senior-ревьюер кода. Тебе дают описание задачи и diff merge request'а.
Определи, решает ли diff поставленную задачу, и найди проблемы.

Требования к ответу:
- Пиши по-русски, в Markdown, не длиннее ~700 слов.
- Первая строка: "**Вердикт:** ..." — одно из: "соответствует задаче", "соответствует с замечаниями", "не соответствует задаче", "недостаточно данных".
- Разделы: "Соответствие задаче", "Ошибки и риски", "Безопасность", "Стиль и поддерживаемость", "Тесты".
- Каждое замечание — с файлом (и строкой из diff, если видно) и конкретным предложением, что изменить.
- Не пересказывай diff и не переписывай весь код: только то, что требует внимания.
- Если в описании нет требований — скажи это в "Соответствие задаче" и оцени по общим критериям.
TXT;

    $lines = [];
    $lines[] = '# Merge request';
    $lines[] = '- Заголовок: ' . ($title !== '' ? $title : '(без заголовка)');
    $lines[] = '- Ветки: ' . $source . ' -> ' . $target;
    $lines[] = '- Автор: ' . ($author !== '' ? $author : 'неизвестен');
    if ($url !== '') {
        $lines[] = '- URL: ' . $url;
    }
    $lines[] = '';
    $lines[] = '# Задача (описание MR)';
    $lines[] = $task !== '' ? $task : '(описание пустое)';
    $lines[] = '';
    $lines[] = '# Diff';

    $budget = (int) $cfg['max_diff_chars'];
    $perFile = (int) $cfg['max_file_diff_chars'];
    $used = 0;
    $shown = 0;
    $truncated = false;

    foreach ($diffs as $diff) {
        if (!is_array($diff)) {
            continue;
        }

        $path = (string) ($diff['new_path'] ?? $diff['old_path'] ?? '?');
        $flags = [];
        if (!empty($diff['new_file'])) {
            $flags[] = 'новый';
        }
        if (!empty($diff['deleted_file'])) {
            $flags[] = 'удалён';
        }
        if (!empty($diff['renamed_file'])) {
            $flags[] = 'переименован';
        }

        $header = '## ' . $path . ($flags !== [] ? ' (' . implode(', ', $flags) . ')' : '');
        $patch = (string) ($diff['diff'] ?? '');

        if ($patch === '') {
            $lines[] = $header . "\n(бинарный файл или diff недоступен)";
            continue;
        }
        if (mb_strlen($patch) > $perFile) {
            $patch = mb_substr($patch, 0, $perFile) . "\n... [diff обрезан]";
        }
        if ($used + mb_strlen($patch) > $budget) {
            $truncated = true;
            break;
        }

        $used += mb_strlen($patch);
        $shown++;
        $lines[] = $header;
        $lines[] = "```diff\n" . $patch . "\n```";
    }

    if ($truncated) {
        $lines[] = sprintf('_Diff обрезан: показаны %d из %d файлов (лимит %d символов)._', $shown, count($diffs), $budget);
    }
    if ($shown === 0) {
        $lines[] = '(изменений нет)';
    }

    return [$system, implode("\n", $lines)];
}

function ask_deepseek(array $cfg, string $system, string $user): string
{
    if ((string) $cfg['deepseek_api_key'] === '') {
        throw new RuntimeException('deepseek_api_key не задан');
    }

    $url = rtrim((string) $cfg['deepseek_base_url'], '/') . '/chat/completions';
    $payload = [
        'model' => (string) $cfg['deepseek_model'],
        'messages' => [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $user],
        ],
        'temperature' => 0.2,
        'max_tokens' => (int) $cfg['deepseek_max_tokens'],
        'stream' => false,
    ];

    $body = json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
    );

    [$code, $raw] = http_request('POST', $url, [
        'Authorization: Bearer ' . $cfg['deepseek_api_key'],
        'Content-Type: application/json',
    ], $body, (int) $cfg['deepseek_timeout']);

    $data = json_decode($raw, true);

    if ($code < 200 || $code >= 300) {
        $message = is_array($data)
            ? (string) ($data['error']['message'] ?? $raw)
            : $raw;
        throw new RuntimeException(sprintf('DeepSeek HTTP %d: %s', $code, mb_substr($message, 0, 500)));
    }

    $content = $data['choices'][0]['message']['content'] ?? null;
    if (!is_string($content) || trim($content) === '') {
        throw new RuntimeException('DeepSeek вернул пустой ответ');
    }

    return trim($content);
}

function render_note(array $cfg, array $mr, string $review, string $trigger): string
{
    $sha = (string) ($mr['sha'] ?? '');
    $shaShort = $sha !== '' ? substr($sha, 0, 8) : '?';

    return implode("\n", [
        '### 🤖 DeepSeek review',
        sprintf('<sub>триггер: %s · коммит `%s` · %s</sub>', $trigger, $shaShort, date('Y-m-d H:i')),
        '',
        $review,
        '',
        (string) $cfg['note_marker'],
    ]);
}

/**
 * Обновляет существующий комментарий бота (по маркеру) или создаёт новый.
 */
function upsert_note(array $cfg, int $projectId, int $iid, string $body): void
{
    $path = sprintf('/projects/%d/merge_requests/%d/notes', $projectId, $iid);

    $notes = gitlab_get($cfg, $path, [
        'sort' => 'desc',
        'order_by' => 'updated_at',
        'per_page' => 100,
    ]);

    $existingId = 0;
    foreach ($notes as $note) {
        if (!is_array($note) || !empty($note['system'])) {
            continue;
        }
        if (str_contains((string) ($note['body'] ?? ''), (string) $cfg['note_marker'])) {
            $existingId = (int) ($note['id'] ?? 0);
            break;
        }
    }

    if ($existingId > 0) {
        gitlab_request($cfg, 'PUT', $path . '/' . $existingId, [], ['body' => $body]);
        log_line($cfg, sprintf('note updated id=%d mr=!%d', $existingId, $iid));

        return;
    }

    gitlab_request($cfg, 'POST', $path, [], ['body' => $body]);
    log_line($cfg, sprintf('note created mr=!%d', $iid));
}

/**
 * Полное ревью: забрать MR и diff из GitLab, спросить DeepSeek, опубликовать комментарий.
 */
function review_now(array $cfg, int $projectId, int $iid, string $trigger): string
{
    $mr = gitlab_get($cfg, sprintf('/projects/%d/merge_requests/%d', $projectId, $iid));
    if ($mr === []) {
        throw new RuntimeException(sprintf('MR не найден: project=%d mr=!%d', $projectId, $iid));
    }

    $state = (string) ($mr['state'] ?? '');
    if ($state !== 'opened') {
        return 'пропуск: state=' . $state;
    }
    if (is_draft($mr)) {
        return 'пропуск: черновик';
    }

    $task = trim((string) ($mr['description'] ?? ''));
    if ((bool) $cfg['require_description'] && $task === '') {
        if ((bool) $cfg['post_note']) {
            upsert_note($cfg, $projectId, $iid, implode("\n", [
                '### 🤖 DeepSeek review',
                '',
                'В описании MR нет текста задачи — проверять нечего. Добавьте описание, ревью придёт при следующем коммите.',
                '',
                (string) $cfg['note_marker'],
            ]));
        }

        return 'пропуск: пустое описание';
    }

    $diffs = fetch_diffs($cfg, $projectId, $iid);
    [$system, $user] = build_prompt($cfg, $mr, $diffs);

    log_line($cfg, sprintf(
        'deepseek request mr=!%d files=%d prompt=%d chars',
        $iid,
        count($diffs),
        mb_strlen($user)
    ));

    $review = ask_deepseek($cfg, $system, $user);

    if ((bool) $cfg['post_note']) {
        upsert_note($cfg, $projectId, $iid, render_note($cfg, $mr, $review, $trigger));
    }

    return 'ok: ' . mb_strlen($review) . ' символов ревью';
}

// ================================= WEBHOOK ================================

function handle_webhook(array $cfg): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        header('Allow: POST');
        respond(405, 'POST only');
    }

    $raw = file_get_contents('php://input');
    if (!is_string($raw) || $raw === '') {
        respond(400, 'empty body');
    }

    if ((string) $cfg['webhook_signing_token'] !== '') {
        if (!verify_signature($cfg, $raw)) {
            log_line($cfg, '401 signature mismatch');
            respond(401, 'bad signature');
        }
    } elseif ((string) $cfg['webhook_legacy_secret'] !== '') {
        if (!verify_legacy($cfg)) {
            log_line($cfg, '401 bad X-Gitlab-Token');
            respond(401, 'bad token');
        }
    }

    $deliveryId = request_header('webhook-id');
    if ($deliveryId === '') {
        $deliveryId = request_header('X-Gitlab-Event-UUID');
    }
    if (already_seen($cfg, $deliveryId)) {
        respond(204);
    }

    $payload = json_decode($raw, true);
    if (!is_array($payload)) {
        respond(400, 'bad json');
    }

    [$run, $reason] = decide($payload, $cfg);

    $mr = $payload['object_attributes'] ?? [];
    $projectId = (int) ($payload['project']['id'] ?? 0);
    $iid = (int) (is_array($mr) ? ($mr['iid'] ?? 0) : 0);

    log_line($cfg, sprintf(
        'event=%s project=%d mr=!%d action=%s run=%s reason=%s',
        request_header('X-Gitlab-Event'),
        $projectId,
        $iid,
        (string) (is_array($mr) ? ($mr['action'] ?? '') : ''),
        $run ? 'yes' : 'no',
        $reason
    ));

    // Отвечаем сразу: ревью занимает десятки секунд.
    respond_async(202, 'accepted');

    if (!$run) {
        return;
    }
    if ($projectId <= 0 || $iid <= 0) {
        log_line($cfg, 'ERROR: нет project.id или iid в payload');
        return;
    }

    ignore_user_abort(true);
    set_time_limit(0);

    try {
        log_line($cfg, sprintf('review start mr=!%d trigger=%s', $iid, $reason));
        log_line($cfg, 'review done ' . review_now($cfg, $projectId, $iid, $reason));
    } catch (Throwable $e) {
        log_line($cfg, 'ERROR ' . $e->getMessage());
        if ((bool) $cfg['post_note']) {
            try {
                upsert_note($cfg, $projectId, $iid, implode("\n", [
                    '### 🤖 DeepSeek review',
                    '',
                    'Не удалось получить ревью: `' . $e->getMessage() . '`',
                    '',
                    (string) $cfg['note_marker'],
                ]));
            } catch (Throwable $inner) {
                log_line($cfg, 'ERROR при публикации ошибки: ' . $inner->getMessage());
            }
        }
    }
}

// =================================== CLI ==================================

function cli_usage(): string
{
    return <<<'TXT'
Использование:
  php gitlab-mr-review.php --project=42 --mr=17
      Принудительно проверить MR (нужны gitlab_token и deepseek_api_key).

  php gitlab-mr-review.php --payload=examples/mr-open.json [--diffs=examples/diffs.json] --dry-run
      Прогнать решение по сохранённому payload и напечатать промпт. Без сети.

  php gitlab-mr-review.php --help

TXT;
}

function cli_main(array $argv, array $cfg): int
{
    $opts = [];
    foreach (array_slice($argv, 1) as $arg) {
        if (!str_starts_with($arg, '--')) {
            continue;
        }
        $pair = explode('=', $arg, 2);
        $opts[substr($pair[0], 2)] = $pair[1] ?? '1';
    }

    if (isset($opts['help']) || $opts === []) {
        echo cli_usage();

        return $opts === [] ? 1 : 0;
    }

    $dryRun = isset($opts['dry-run']);

    if (isset($opts['payload'])) {
        $json = @file_get_contents($opts['payload']);
        $payload = is_string($json) ? json_decode($json, true) : null;
        if (!is_array($payload)) {
            fwrite(STDERR, "Не удалось прочитать payload: {$opts['payload']}\n");

            return 2;
        }

        [$run, $reason] = decide($payload, $cfg);
        printf("run=%s reason=%s\n", $run ? 'yes' : 'no', $reason);

        if (!$run) {
            return 0;
        }

        $diffs = [];
        if (isset($opts['diffs'])) {
            $json = @file_get_contents($opts['diffs']);
            $decoded = is_string($json) ? json_decode($json, true) : null;
            if (is_array($decoded)) {
                $diffs = $decoded;
            }
        }

        if ($dryRun) {
            [$system, $user] = build_prompt($cfg, is_array($payload['object_attributes'] ?? null) ? $payload['object_attributes'] : [], $diffs);
            echo "----- SYSTEM -----\n", $system, "\n----- USER -----\n", $user, "\n";

            return 0;
        }

        $projectId = (int) ($payload['project']['id'] ?? 0);
        $iid = (int) ($payload['object_attributes']['iid'] ?? 0);
        echo review_now($cfg, $projectId, $iid, $reason), "\n";

        return 0;
    }

    if (isset($opts['project'], $opts['mr'])) {
        $projectId = (int) $opts['project'];
        $iid = (int) $opts['mr'];
        if ($dryRun) {
            echo "dry-run: сетевые запросы пропущены\n";

            return 0;
        }
        echo review_now($cfg, $projectId, $iid, 'manual'), "\n";

        return 0;
    }

    fwrite(STDERR, cli_usage());

    return 1;
}

// ================================= DISPATCH ================================

if (PHP_SAPI === 'cli') {
    exit(cli_main($argv, $CONFIG));
}

handle_webhook($CONFIG);
