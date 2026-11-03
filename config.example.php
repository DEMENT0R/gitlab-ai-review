<?php
declare(strict_types=1);

/**
 * Скопируйте в config.php рядом с gitlab-mr-review.php и заполните.
 * Файл не должен попадать в git и в публичный доступ.
 */
return [
    'gitlab_url' => 'https://gitlab.example.com',
    'gitlab_token' => 'glpat-xxxxxxxxxxxxxxxxxxxx',   // scope: api

    // Кто делает ревью. Сейчас из коробки настроен только DeepSeek.
    'ai_provider' => 'deepseek',

    // Реестр провайдеров. Добавляйте сюда свои — код менять не нужно,
    // если у API совместимый протокол (POST {base_url}/chat/completions).
    'providers' => [
        'deepseek' => [
            'driver' => 'openai_compatible',
            'label' => 'DeepSeek',
            'api_key' => 'sk-xxxxxxxxxxxxxxxxxxxxxxxx',
            'base_url' => 'https://api.deepseek.com', // можно свой прокси/шлюз
            'model' => 'deepseek-chat',               // или deepseek-reasoner
            'max_tokens' => 4000,
            'timeout' => 300,
            // 'temperature' => 0.2,
        ],

        // Примеры на будущее (сейчас не используются — раскомментируйте
        // и переключите ai_provider либо запускайте с --provider=имя):
        //
        // 'openai' => [
        //     'label' => 'OpenAI',
        //     'api_key' => getenv('OPENAI_API_KEY') ?: '',
        //     'base_url' => 'https://api.openai.com/v1',
        //     'model' => 'gpt-4.1',
        // ],
        //
        // 'openrouter' => [
        //     'label' => 'OpenRouter',
        //     'api_key' => getenv('OPENROUTER_API_KEY') ?: '',
        //     'base_url' => 'https://openrouter.ai/api/v1',
        //     'model' => 'anthropic/claude-sonnet-4',
        // ],
        //
        // 'ollama' => [ // локальная модель, ключ не нужен
        //     'label' => 'Ollama',
        //     'base_url' => 'http://127.0.0.1:11434/v1',
        //     'model' => 'qwen2.5-coder:14b',
        //     'require_api_key' => false,
        // ],
    ],

    'webhook_signing_token' => 'whsec_xxxxxxxxxxxxxxxxxxxx',

    // Дополнительно можно переопределить:
    // 'post_note' => true,
    // 'require_description' => true,
    // 'max_diff_chars' => 120000,
    // 'max_file_diff_chars' => 20000,
    // 'note_marker' => '<!-- deepseek-review -->', // не меняйте без нужды:
    //                                              // по нему находится старый комментарий
];
