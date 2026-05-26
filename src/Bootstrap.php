<?php

declare(strict_types=1);

namespace App;

use App\Helpers\Renderer;
use Dotenv\Dotenv;

/**
 * Initialisation globale : autoloader, .env, config, DB, session, templates.
 * Appelé une fois par requête depuis public/index.php (ou un script CLI).
 */
final class Bootstrap
{
    /** @var array<string,mixed> */
    private static array $config = [];
    private static bool $booted = false;

    public static function boot(string $rootPath): void
    {
        if (self::$booted) {
            return;
        }

        $rootPath = rtrim($rootPath, '/\\');

        require_once $rootPath . '/vendor/autoload.php';

        // Charger .env (silencieux si absent — utile pour les hébergements qui mettent les vars ailleurs)
        if (is_file($rootPath . '/.env')) {
            Dotenv::createImmutable($rootPath)->load();
        }

        $appConfig = require $rootPath . '/config/app.php';
        $dbConfig = require $rootPath . '/config/database.php';

        self::$config = [
            'app' => $appConfig,
            'database' => $dbConfig,
            'root' => $rootPath,
        ];

        // Timezone
        date_default_timezone_set($appConfig['timezone']);

        // Affichage des erreurs selon APP_DEBUG
        if ($appConfig['debug']) {
            error_reporting(E_ALL);
            ini_set('display_errors', '1');
        } else {
            error_reporting(E_ALL);
            ini_set('display_errors', '0');
            ini_set('log_errors', '1');
            ini_set('error_log', $rootPath . '/storage/logs/php-errors.log');
        }

        // DB en mode paresseux : on stocke la config, la connexion s'ouvre
        // seulement au premier pdo(). Permet à /install de fonctionner avec
        // une DB encore vide ou inaccessible.
        Database::configure($dbConfig);

        // Session (cookies sécurisés)
        Session::start($appConfig['session']);

        // Templates
        Renderer::init($rootPath . '/src/Templates');
        Renderer::share('app', [
            'name' => $appConfig['name'],
            'version' => $appConfig['version'],
            'url' => $appConfig['url'],
        ]);

        self::$booted = true;
    }

    public static function config(string $key, mixed $default = null): mixed
    {
        $segments = explode('.', $key);
        $value = self::$config;
        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }
        return $value;
    }

    public static function rootPath(): string
    {
        return self::$config['root'] ?? '';
    }
}
