<?php

declare(strict_types=1);

return [
    'name' => $_ENV['APP_NAME'] ?? 'PIM Musculation',
    'env' => $_ENV['APP_ENV'] ?? 'production',
    'debug' => filter_var($_ENV['APP_DEBUG'] ?? 'false', FILTER_VALIDATE_BOOLEAN),
    'url' => rtrim($_ENV['APP_URL'] ?? 'http://localhost', '/'),
    // Version : lit /VERSION écrit par la GH Action au déploiement (format "0.1.42") ; fallback hardcoded en dev local.
    'version' => (function (): string {
        $f = dirname(__DIR__) . '/VERSION';
        if (is_file($f)) {
            $v = trim((string) @file_get_contents($f));
            if ($v !== '') return $v;
        }
        return '0.1.0-dev';
    })(),
    'locale' => 'fr',
    'timezone' => 'Europe/Paris',

    'session' => [
        'name' => $_ENV['SESSION_NAME'] ?? 'pim_musculation_sess',
        'lifetime' => (int) ($_ENV['SESSION_LIFETIME'] ?? 86400),
    ],

    'security' => [
        'app_secret' => $_ENV['APP_SECRET'] ?? '',
        'bcrypt_cost' => 12,
    ],

    'mail' => [
        'host' => $_ENV['MAIL_HOST'] ?? '',
        'port' => (int) ($_ENV['MAIL_PORT'] ?? 587),
        'user' => $_ENV['MAIL_USER'] ?? '',
        'pass' => $_ENV['MAIL_PASS'] ?? '',
        'encryption' => $_ENV['MAIL_ENCRYPTION'] ?? 'tls',
        'from_email' => $_ENV['MAIL_FROM_EMAIL'] ?? '',
        'from_name' => $_ENV['MAIL_FROM_NAME'] ?? 'PIM Musculation',
    ],

    // Seuils de description (en caractères) pour les pills filtres catégories/produits
    'description_thresholds' => [
        'empty' => 0,
        'short_max' => 300,
    ],

    // Regex pour détecter le contenu CMS-enrichi (Elementor, hooks, images CMS custom)
    'cms_content_regex' => '/\{hook\s|elementor|\/img\/cms\/produit_cms\//i',

    // Token tokens : tokens password reset / invitation
    'tokens' => [
        'reset_lifetime_hours' => 1,
        'invitation_lifetime_days' => 7,
    ],
];
