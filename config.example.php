<?php
declare(strict_types=1);

/**
 * Скопируйте в config.php рядом с gitlab-mr-review.php и заполните.
 * Файл не должен попадать в git и в публичный доступ.
 */
return [
    'gitlab_url' => 'https://gitlab.example.com',
    'gitlab_token' => 'glpat-xxxxxxxxxxxxxxxxxxxx',   // scope: api

    'deepseek_api_key' => 'sk-xxxxxxxxxxxxxxxxxxxxxxxx',
    'deepseek_base_url' => 'https://api.deepseek.com', // можно свой прокси/шлюз
    'deepseek_model' => 'deepseek-chat',               // или deepseek-reasoner

    'webhook_signing_token' => 'whsec_xxxxxxxxxxxxxxxxxxxx',

    // Дополнительно можно переопределить:
    // 'post_note' => true,
    // 'require_description' => true,
    // 'max_diff_chars' => 120000,
    // 'max_file_diff_chars' => 20000,
    // 'deepseek_max_tokens' => 4000,
    // 'deepseek_timeout' => 300,
];
