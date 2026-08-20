<?php

/**
 * Pinroll canonical config (complete schema).
 *
 * Project overlay: .pinoox/pinroll.config.php (gitignored with .pinoox/).
 * Override any key there — including gate.site, gate.token, and FTP password.
 * Optional CI overlay: PINROLL_* in .env (HostEnv, last wins).
 *
 * Load order: this file → project overlay (deep merge) → HostEnv.
 */

return [
    'storage_path' => sys_get_temp_dir() . '/pinroll',
    'releases_path' => 'pinroll/releases',
    'backups_path' => 'pinroll/backups',
    'staging_path' => 'pinroll/staging',
    'sessions_path' => 'pinroll/sessions',
    'incoming_path' => 'pinroll/incoming',
    'history_file' => 'pinroll/history.jsonl',
    'lock_file' => 'pinroll/deploy.lock',
    'lock_timeout' => 3600,
    'gate_path' => '_pinoox/gate',
    'default_transport' => 'pinion',
    'chunk_size' => 5 * 1024 * 1024,

    'default_host' => 'production',
    'keep' => 3,
    'store' => 'remote',
    'auto_clean' => true,
    'clean_before_deploy' => true,
    'stale_days' => 7,
    'lang' => 'en',

    'provision' => [
        'db' => [
            'host' => 'localhost',
            'database' => 'pinoox',
            'username' => '',
            'password' => '',
            'connection' => 'mysql',
            'port' => '3306',
            'prefix' => 'pin_',
            'timezone' => '+03:30',
        ],
        'user' => [
            'fname' => 'support',
            'lname' => 'pinoox',
            'email' => 'info@pinoox.com',
            'username' => 'admin',
            'password' => '123456',
        ],
    ],

    'build' => [
        'exclude' => [],
        'include' => [],
    ],

    'hosts' => [
        'production' => [
            'deploy_path' => 'public_html',
            'web_path' => '',
            'via' => 'ftp',
            'gate' => [
                'site' => '',
                'token' => '',
            ],
            'ftp' => [
                'host' => '',
                'user' => '',
                'password' => '',
            ],
        ],
    ],
];
