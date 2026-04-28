<?php

return [
    'database_driver' => getenv('SUINDA_DB_DRIVER') ?: 'sqlite',
    'database_path' => getenv('SUINDA_SQLITE_PATH') ?: __DIR__ . '/local-data/database/suinda.sqlite',
    'storage_path' => getenv('SUINDA_STORAGE_PATH') ?: __DIR__ . '/local-data',
    'mysql' => [
        'host' => getenv('SUINDA_DB_HOST') ?: '127.0.0.1',
        'port' => getenv('SUINDA_DB_PORT') ?: '3306',
        'database' => getenv('SUINDA_DB_NAME') ?: 'suinda',
        'username' => getenv('SUINDA_DB_USER') ?: 'root',
        'password' => getenv('SUINDA_DB_PASSWORD') ?: '',
        'charset' => 'utf8mb4',
    ],
    'seed_on_boot' => true,
];
