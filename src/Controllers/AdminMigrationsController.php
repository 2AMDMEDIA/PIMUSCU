<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Csrf;
use App\Middleware\Auth;
use App\Services\MigrationRunner;

final class AdminMigrationsController extends BaseController
{
    public function index(): void
    {
        Auth::requireSuperAdmin();

        $runner = new MigrationRunner();

        // Capture l'erreur si la DB n'est pas accessible (utile en premier deploy)
        $error = null;
        $migrations = [];
        try {
            $migrations = $runner->listAll();
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        $pending = array_filter($migrations, fn ($m) => !$m['applied']);

        $this->renderApp('pages.admin.migrations', [
            'migrations' => $migrations,
            'pending_count' => count($pending),
            'error' => $error,
        ], [
            'active' => 'admin',
            'page_title' => 'Migrations DB',
        ]);
    }

    public function run(): void
    {
        Auth::requireSuperAdmin();
        Csrf::enforce($this->input('_csrf'));

        $report = (new MigrationRunner())->applyPending();

        if ($report['failed'] !== null) {
            $applied = $report['applied'];
            $failedName = $report['failed']['name'];
            $failedError = $report['failed']['error'];
            $msg = sprintf(
                '%d migration%s appliquée%s puis échec sur %s : %s',
                count($applied),
                count($applied) > 1 ? 's' : '',
                count($applied) > 1 ? 's' : '',
                $failedName,
                $failedError,
            );
            $this->flashError($msg);
        } else {
            $count = count($report['applied']);
            if ($count === 0) {
                $this->flashSuccess('Aucune migration en attente — tout est à jour.');
            } else {
                $this->flashSuccess($count . ' migration' . ($count > 1 ? 's' : '') . ' appliquée' . ($count > 1 ? 's' : '') . ' avec succès.');
            }
        }

        $this->redirect('/admin/migrations');
    }

    /**
     * Marque une migration spécifique comme déjà appliquée SANS l'exécuter.
     * Utile si on a importé le SQL à la main via phpMyAdmin et qu'on veut
     * synchroniser le tracking.
     */
    public function markApplied(string $name): void
    {
        Auth::requireSuperAdmin();
        Csrf::enforce($this->input('_csrf'));

        $name = basename($name); // sécurité : pas de path traversal

        try {
            (new MigrationRunner())->markAsApplied($name);
            $this->flashSuccess('Migration marquée comme déjà appliquée : ' . $name);
        } catch (\Throwable $e) {
            $this->flashError('Erreur : ' . $e->getMessage());
        }
        $this->redirect('/admin/migrations');
    }
}
