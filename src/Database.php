<?php

declare(strict_types=1);

namespace App;

use PDO;
use PDOException;
use RuntimeException;

/**
 * Singleton PDO avec connexion paresseuse :
 *  - Bootstrap::boot() appelle configure($cfg) qui ne fait que stocker
 *  - La connexion réelle s'ouvre au premier appel à pdo()
 *
 * Ceci permet aux pages qui n'ont pas besoin de la DB (ex: /install au tout
 * premier déploiement) de fonctionner même si la DB est encore inaccessible.
 */
final class Database
{
    private static ?PDO $instance = null;
    /** @var array<string,mixed> */
    private static array $config = [];

    private function __construct() {}

    /** Stocke la config sans ouvrir la connexion. */
    public static function configure(array $config): void
    {
        self::$config = $config;
    }

    /**
     * Ouvre la connexion la première fois, retourne l'instance ensuite.
     */
    public static function pdo(): PDO
    {
        if (self::$instance instanceof PDO) {
            return self::$instance;
        }
        if (empty(self::$config)) {
            throw new RuntimeException('Database non configurée (Bootstrap::boot() avant tout appel pdo()).');
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            self::$config['host'],
            self::$config['port'],
            self::$config['name'],
            self::$config['charset']
        );

        try {
            self::$instance = new PDO(
                $dsn,
                self::$config['user'],
                self::$config['pass'],
                self::$config['options'] ?? []
            );
        } catch (PDOException $e) {
            throw new RuntimeException('Connexion à la base de données impossible : ' . $e->getMessage(), 0, $e);
        }
        return self::$instance;
    }

    /**
     * Helper : essaie d'ouvrir une connexion et retourne (true, null) ou (false, message).
     * Utile pour afficher un état "DB OK / KO" sans crasher la requête.
     *
     * @return array{ok:bool, error:?string}
     */
    public static function probe(): array
    {
        try {
            self::pdo();
            return ['ok' => true, 'error' => null];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /** Alias historique : équivalent à configure() pour ne pas casser l'API. */
    public static function connect(array $config): PDO
    {
        self::configure($config);
        return self::pdo();
    }
}
