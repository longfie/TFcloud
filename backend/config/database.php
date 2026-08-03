<?php

return [
    'default' => 'mysql',
    'connections' => [
        'mysql' => [
            'driver' => 'mysql',
            'host' => getenv('DB_HOST') ?: '127.0.0.1',
            'port' => (int)(getenv('DB_PORT') ?: 3306),
            'database' => getenv('DB_NAME_OVERRIDE') ?: (getenv('DB_NAME') ?: 'tf_sign'),
            'username' => getenv('DB_USER') ?: 'tf_sign',
            'password' => getenv('DB_PASSWORD') ?: '',
            'unix_socket' => getenv('DB_SOCKET') ?: '',
            'charset' => 'utf8mb4',
            'collation' => getenv('DB_COLLATION') ?: 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => true,
            'engine' => 'InnoDB',
            'options' => [
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_STRINGIFY_FETCHES => false,
            ],
        ],
    ],
];
