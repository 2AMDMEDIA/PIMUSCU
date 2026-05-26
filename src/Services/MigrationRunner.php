<?php

declare(strict_types=1);

namespace App\Services;

use App\Bootstrap;
use App\Database;
use PDO;
use PDOException;
use Ramsey\Uuid\Uuid;

/**
 * Système de migrations versionnées :
 *  - Lit les fichiers .sql dans migrations/ par ordre alphabétique
 *  - Track les migrations appliquées dans la table `schema_migrations`
 *  - N'applique JAMAIS 2 fois la même migration
 *  - Sûr à exécuter sur prod (contrairement à scripts/migrate.php qui DROP)
 */
final class MigrationRunner
{
    private function pdo(): PDO
    {
        return Database::pdo();
    }

    private function migrationsDir(): string
    {
        return Bootstrap::rootPath() . '/migrations';
    }

    /** Crée la table schema_migrations si elle n'existe pas. */
    public function ensureTable(): void
    {
        $this->pdo()->exec(
            'CREATE TABLE IF NOT EXISTS `schema_migrations` (
                `id` CHAR(36) NOT NULL,
                `name` VARCHAR(255) NOT NULL,
                `applied_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_schema_migrations_name` (`name`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    /** @return list<string> Noms des migrations déjà appliquées */
    public function listApplied(): array
    {
        $this->ensureTable();
        $rows = $this->pdo()->query('SELECT name FROM schema_migrations ORDER BY name ASC')->fetchAll(PDO::FETCH_COLUMN);
        return is_array($rows) ? $rows : [];
    }

    /** @return list<array{name:string,applied_at:?string,applied:bool,size:int}> */
    public function listAll(): array
    {
        $this->ensureTable();
        $applied = [];
        foreach ($this->pdo()->query('SELECT name, applied_at FROM schema_migrations')->fetchAll() as $row) {
            $applied[$row['name']] = $row['applied_at'];
        }

        $files = glob($this->migrationsDir() . '/*.sql') ?: [];
        sort($files);

        $result = [];
        foreach ($files as $file) {
            $name = basename($file);
            $result[] = [
                'name' => $name,
                'applied_at' => $applied[$name] ?? null,
                'applied' => array_key_exists($name, $applied),
                'size' => filesize($file) ?: 0,
            ];
        }
        return $result;
    }

    /** @return list<string> Noms des migrations en attente */
    public function listPending(): array
    {
        $applied = array_flip($this->listApplied());
        $pending = [];
        foreach ($this->listAll() as $m) {
            if (!isset($applied[$m['name']])) {
                $pending[] = $m['name'];
            }
        }
        return $pending;
    }

    /**
     * Marque une migration comme déjà appliquée SANS l'exécuter.
     * Utile pour bootstrapper sur une DB déjà initialisée à la main.
     */
    public function markAsApplied(string $name): void
    {
        $this->ensureTable();
        $stmt = $this->pdo()->prepare(
            'INSERT IGNORE INTO schema_migrations (id, name) VALUES (:id, :name)'
        );
        $stmt->execute([
            ':id' => Uuid::uuid4()->toString(),
            ':name' => $name,
        ]);
    }

    /**
     * Applique une migration : exécute le SQL puis l'enregistre.
     * Lance une PDOException en cas d'échec (la migration N'EST PAS enregistrée).
     */
    public function apply(string $name): void
    {
        $path = $this->migrationsDir() . '/' . $name;
        if (!is_file($path)) {
            throw new \RuntimeException("Migration introuvable : {$name}");
        }
        $sql = file_get_contents($path);
        if ($sql === false || trim($sql) === '') {
            throw new \RuntimeException("Migration vide ou illisible : {$name}");
        }

        $this->ensureTable();
        $pdo = $this->pdo();

        try {
            $pdo->exec($sql);
        } catch (PDOException $e) {
            throw new \RuntimeException("Échec d'exécution de {$name} : " . $e->getMessage(), 0, $e);
        }

        $stmt = $pdo->prepare(
            'INSERT INTO schema_migrations (id, name) VALUES (:id, :name)'
        );
        $stmt->execute([
            ':id' => Uuid::uuid4()->toString(),
            ':name' => $name,
        ]);
    }

    /**
     * Applique toutes les migrations en attente dans l'ordre.
     * S'arrête à la première erreur. Retourne le rapport d'exécution.
     *
     * @return array{applied:list<string>, failed:?array{name:string,error:string}}
     */
    public function applyPending(): array
    {
        $pending = $this->listPending();
        $applied = [];
        foreach ($pending as $name) {
            try {
                $this->apply($name);
                $applied[] = $name;
            } catch (\Throwable $e) {
                return ['applied' => $applied, 'failed' => ['name' => $name, 'error' => $e->getMessage()]];
            }
        }
        return ['applied' => $applied, 'failed' => null];
    }
}
