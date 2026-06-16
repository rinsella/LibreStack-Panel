<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Setup / installation
    |--------------------------------------------------------------------------
    */
    'setup_completed' => env('LIBRESTACK_SETUP_COMPLETED', false),

    /*
    |--------------------------------------------------------------------------
    | System mode
    |--------------------------------------------------------------------------
    | When false (local/dev), the panel will not run privileged system commands.
    | The CommandRunner returns a "disabled" result instead so the UI keeps
    | working without a real Linux server underneath.
    */
    'system_enabled' => (bool) env('LIBRESTACK_SYSTEM_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Privilege escalation (sudoers allowlist)
    |--------------------------------------------------------------------------
    | The panel runs as an unprivileged user (www-data). Privileged binaries are
    | executed through `sudo -n` using a tightly scoped /etc/sudoers.d/librestack
    | allowlist installed by scripts/install.sh. Arbitrary shell is never used —
    | the CommandRunner only runs allowlisted binaries with array arguments.
    */
    'use_sudo' => (bool) env('LIBRESTACK_USE_SUDO', false),

    /*
    | Absolute path to the privileged-operation wrapper script. www-data is
    | granted NOPASSWD sudo access to ONLY this script; it forwards to the
    | allowlisted `librestack:safe-op` artisan command.
    */
    'safe_op_script' => env('LIBRESTACK_SAFE_OP_SCRIPT', '/opt/librestack/scripts/librestack-safe-op'),

    'privileged_binaries' => [
        'systemctl',
        'nginx',
        'certbot',
        'ufw',
        'mysql',
        'mysqldump',
        'crontab',
        'journalctl',
        'useradd',
    ],

    /*
    |--------------------------------------------------------------------------
    | Base paths
    |--------------------------------------------------------------------------
    */
    'paths' => [
        'web_root'        => env('LIBRESTACK_WEB_ROOT', '/home'),
        'backups'         => env('LIBRESTACK_BACKUP_PATH', '/var/lib/librestack/backups'),
        'nginx_available' => env('LIBRESTACK_NGINX_AVAILABLE', '/etc/nginx/sites-available'),
        'nginx_enabled'   => env('LIBRESTACK_NGINX_ENABLED', '/etc/nginx/sites-enabled'),
        'logs'            => env('LIBRESTACK_LOG_PATH', '/var/log/librestack'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Default PHP version
    |--------------------------------------------------------------------------
    | The default PHP version used when generating PHP-FPM site configs. The
    | installer detects the PHP-FPM version actually present on the host and
    | writes it here; NginxService also falls back to any existing FPM socket.
    */
    'default_php' => env('LIBRESTACK_DEFAULT_PHP', '8.3'),

    /*
    |--------------------------------------------------------------------------
    | Allowlisted binaries
    |--------------------------------------------------------------------------
    | The safe CommandRunner will ONLY execute binaries listed here. Anything
    | else is rejected. User input is never concatenated into a shell string —
    | arguments are always passed as an array to Symfony Process.
    */
    'allowed_binaries' => [
        'hostnamectl',
        'hostname',
        'uname',
        'uptime',
        'free',
        'df',
        'systemctl',
        'journalctl',
        'nginx',
        'certbot',
        'mysql',
        'mysqldump',
        'ufw',
        'php',
        'wp',
        'tar',
        'unzip',
        'zip',
        'cp',
        'mv',
        'rm',
        'mkdir',
        'chown',
        'chmod',
        'find',
        'curl',
        'crontab',
        'sudo',
        'useradd',
        'id',
    ],

    /*
    |--------------------------------------------------------------------------
    | Allowlisted services (service manager / journal)
    |--------------------------------------------------------------------------
    */
    'allowed_services' => [
        'nginx',
        'mariadb',
        'mysql',
        'php8.1-fpm',
        'php8.2-fpm',
        'php8.3-fpm',
        'php8.4-fpm',
        'php8.5-fpm',
        'redis-server',
        'ufw',
        'fail2ban',
    ],

    /*
    |--------------------------------------------------------------------------
    | Supported site types
    |--------------------------------------------------------------------------
    */
    'site_types' => [
        'static'        => 'Static (HTML/CSS/JS)',
        'php'           => 'PHP (PHP-FPM)',
        'wordpress'     => 'WordPress',
        'node_proxy'    => 'Node.js (reverse proxy)',
        'reverse_proxy' => 'Reverse proxy',
    ],

    /*
    |--------------------------------------------------------------------------
    | Manageable per-site PHP settings (php.ini directives)
    |--------------------------------------------------------------------------
    | Each PHP/WordPress site runs in its own PHP-FPM pool, so these directives
    | are injected as php_admin_value entries in that pool. This is the panel's
    | equivalent of a "PHP settings manager": operators can raise the upload
    | limit, memory, execution time, etc. without touching the global php.ini.
    |
    | 'type'  => 'size' (e.g. 64M / 1G) or 'int' (seconds / count).
    | 'min' / 'max' bound the value (sizes in bytes); 'default' matches a stock
    | php.ini. 'nginx_body' marks the directive that also drives nginx's
    | client_max_body_size so large uploads are not rejected before reaching PHP.
    */
    'php_settings' => [
        'upload_max_filesize' => [
            'label' => 'Maximum upload file size', 'type' => 'size',
            'default' => '2M', 'min' => 1048576, 'max' => 2147483648, 'nginx_body' => true,
        ],
        'post_max_size' => [
            'label' => 'Maximum POST size', 'type' => 'size',
            'default' => '8M', 'min' => 1048576, 'max' => 2147483648, 'nginx_body' => true,
        ],
        'memory_limit' => [
            'label' => 'Memory limit', 'type' => 'size',
            'default' => '256M', 'min' => 33554432, 'max' => 4294967296,
        ],
        'max_execution_time' => [
            'label' => 'Max execution time (seconds)', 'type' => 'int',
            'default' => '30', 'min' => 5, 'max' => 3600,
        ],
        'max_input_time' => [
            'label' => 'Max input time (seconds)', 'type' => 'int',
            'default' => '60', 'min' => 5, 'max' => 3600,
        ],
        'max_input_vars' => [
            'label' => 'Max input variables', 'type' => 'int',
            'default' => '1000', 'min' => 100, 'max' => 100000,
        ],
    ],

    'php_versions' => ['8.1', '8.2', '8.3', '8.4', '8.5'],

    /*
    |--------------------------------------------------------------------------
    | Firewall presets
    |--------------------------------------------------------------------------
    */
    'firewall_presets' => [
        ['label' => 'SSH (22)',        'port' => 22,   'proto' => 'tcp', 'danger' => false],
        ['label' => 'HTTP (80)',       'port' => 80,   'proto' => 'tcp', 'danger' => false],
        ['label' => 'HTTPS (443)',     'port' => 443,  'proto' => 'tcp', 'danger' => false],
        ['label' => 'Panel (8080)',    'port' => 8080, 'proto' => 'tcp', 'danger' => false],
        ['label' => 'MySQL (3306)',    'port' => 3306, 'proto' => 'tcp', 'danger' => true],
    ],

    /*
    |--------------------------------------------------------------------------
    | Settings that must be encrypted at rest
    |--------------------------------------------------------------------------
    */
    'encrypted_settings' => [
        'db_admin_password',
    ],
];
