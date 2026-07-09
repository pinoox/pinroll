<?php

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
    'default_bundle' => 'single-app',
    'chunk_size' => 5 * 1024 * 1024,
];
