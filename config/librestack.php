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

    'privileged_binaries' => [
        'systemctl',
        'nginx',
        'certbot',
        'ufw',
        'chown',
        'chmod',
        'find',
        'mysql',
        'mysqldump',
        'crontab',
        'journalctl',
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

    'php_versions' => ['8.1', '8.2', '8.3', '8.4'],

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
