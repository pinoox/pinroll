<?php

/**
 * Pinroll canonical config (complete schema).
 *
 * Project overlay: .pinoox/pinroll.config.php (gitignored with .pinoox/).
 * Override any key there — including gate.site, gate.token, and FTP password.
 * Optional CI overlay: PINROLL_* in .env (HostEnv, last wins).
 *
 * Load order: this file → project overlay (deep merge) → HostEnv.
 * pinroll:init writes commented samples in the overlay — uncomment to override.
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
    /** Seconds before a stale deploy lock is ignored. */
    'lock_timeout' => 3600,
    /** Internal PinGate path prefix. Public entry is pingate.php?route= — leave default. */
    'gate_path' => '_pinoox/gate',
    /** Fallback transport when a host omits `via`: ftp | ssh | pinion | local. */
    'default_transport' => 'pinion',
    /** Pinion HTTP upload chunk size in bytes (default 5 MiB). */
    'chunk_size' => 5 * 1024 * 1024,

    /** Host used when CLI omits the name. */
    'default_host' => 'production',
    /** Newest N archives to keep; 0 disables count-based prune. */
    'keep' => 3,
    /** Where archives live after install: local | remote | both. */
    'store' => 'remote',
    /** After a successful install, prune beyond `keep`. */
    'auto_clean' => true,
    /** Before each upload/deploy, prune leftover archives/tmp/zips. */
    'clean_before_deploy' => true,
    /** Also delete archives/zips older than N days; 0 = keep-count only. */
    'stale_days' => 7,
    /** Installer / provision locale (en, fa, …). */
    'lang' => 'en',

    /** When false (default), pingate.php has no embedded token — use storage/pinroll/tokens/{label}.php on the host. */
    'gate_embed_token' => false,

    /** First-time host install (`pinroll:provision`) — same fields as the web installer. */
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

    /** Extra platform zip rules, merged with platform/build.config.php (lists concatenated). */
    'build' => [
        'exclude' => [], // e.g. ['docs', 'tests']
        'include' => [],
    ],

    'hosts' => [
        'production' => [
            /** FTP/SSH folder at account root (e.g. public_html, apps). */
            'deploy_path' => 'public_html',
            /** URL subfolder (e.g. shop); '' = domain or subdomain document root. */
            'web_path' => '',
            /** ftp | ssh | pinion | local */
            'via' => 'ftp',
            'gate' => [
                'site' => '',  // origin only, e.g. https://pinoox.com — not …/pingate.php
                'token' => '', // shared host token
            ],
            'ftp' => [
                'host' => '',
                'user' => '',
                'password' => '',
            ],
            // 'ssh' => ['host' => '', 'user' => '', 'key' => ''],
            // 'apps' => ['com_pinoox_account'],
            // 'hooks' => [
            //     'before_push' => ['npm run build'],
            //     'after_install' => ['php pinoox cache:build'],
            // ],
        ],
    ],
];
