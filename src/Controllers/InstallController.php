<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Bootstrap;
use App\Database;
use App\Helpers\Csrf;
use App\Repositories\UserRepository;
use App\Services\MigrationRunner;

/**
 * Page d'installation one-shot pour le tout premier déploiement.
 *
 *   GET  /install?token=XXX  → affiche le status (DB, migrations) + form super-admin
 *   POST /install            → applique migrations + crée super-admin + verrouille
 *
 * Le token vient de la variable d'environnement INSTALL_TOKEN dans .env.
 *
 * Une fois exécutée avec succès, un fichier storage/install.lock est créé qui
 * empêche tout futur lancement (réponse 410 Gone). Pour relancer manuellement,
 * supprimer ce fichier.
 */
final class InstallController extends BaseController
{
    private function lockFile(): string
    {
        return Bootstrap::rootPath() . '/storage/install.lock';
    }

    private function isLocked(): bool
    {
        return is_file($this->lockFile());
    }

    private function expectedToken(): string
    {
        return (string) ($_ENV['INSTALL_TOKEN'] ?? '');
    }

    private function checkAuth(): void
    {
        $expected = $this->expectedToken();
        if ($expected === '') {
            http_response_code(403);
            echo '<h1>403 — Installation désactivée</h1><p>Aucun <code>INSTALL_TOKEN</code> configuré dans <code>.env</code>.</p>';
            exit;
        }
        $provided = (string) ($this->input('token') ?? '');
        if (!hash_equals($expected, $provided)) {
            http_response_code(403);
            echo '<h1>403 — Token d\'installation invalide</h1>';
            exit;
        }
    }

    public function show(): void
    {
        $this->checkAuth();

        if ($this->isLocked()) {
            http_response_code(410);
            echo '<h1>410 — Installation déjà effectuée</h1>'
                . '<p>L\'installation a déjà été lancée avec succès. Si tu veux la relancer, supprime manuellement le fichier <code>storage/install.lock</code> sur le serveur.</p>';
            exit;
        }

        // État DB
        $dbProbe = Database::probe();

        // Si DB OK : on peut lister les migrations en attente
        $migrations = [];
        $migrationsError = null;
        $usersExist = false;
        if ($dbProbe['ok']) {
            try {
                $runner = new MigrationRunner();
                $migrations = $runner->listAll();
            } catch (\Throwable $e) {
                $migrationsError = $e->getMessage();
            }

            // Détection : est-ce qu'il y a déjà des users en DB ?
            try {
                $usersExist = (int) Database::pdo()->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0;
            } catch (\Throwable) {
                $usersExist = false; // table n'existe pas encore → install fraîche
            }
        }

        $this->render('pages.install', [
            'title' => 'Installation',
            'token' => $this->expectedToken(),
            'db_probe' => $dbProbe,
            'migrations' => $migrations,
            'migrations_error' => $migrationsError,
            'users_exist' => $usersExist,
            'app_name' => (string) Bootstrap::config('app.name'),
        ]);
    }

    public function run(): void
    {
        $this->checkAuth();

        if ($this->isLocked()) {
            $this->json(['ok' => false, 'message' => 'Installation déjà effectuée.'], 410);
        }

        Csrf::enforce($this->input('_csrf'));

        $email = $this->input('email');
        $password = $this->input('password');
        $fullName = $this->input('full_name') ?? '';

        if ($email === null || $password === null) {
            $this->flashError('Email et mot de passe requis.');
            $this->redirect('/install?token=' . urlencode($this->expectedToken()));
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->flashError('Email invalide.');
            $this->redirect('/install?token=' . urlencode($this->expectedToken()));
        }
        if (strlen($password) < 8) {
            $this->flashError('Mot de passe trop court (8 caractères minimum).');
            $this->redirect('/install?token=' . urlencode($this->expectedToken()));
        }

        // Étape 1 — DB joignable
        $probe = Database::probe();
        if (!$probe['ok']) {
            $this->flashError('Impossible de se connecter à la DB : ' . $probe['error']);
            $this->redirect('/install?token=' . urlencode($this->expectedToken()));
        }

        // Étape 2 — Applique migrations en attente
        $runner = new MigrationRunner();
        $report = $runner->applyPending();
        if ($report['failed'] !== null) {
            $this->flashError(sprintf(
                '%d migration(s) appliquée(s) puis échec sur %s : %s',
                count($report['applied']),
                $report['failed']['name'],
                $report['failed']['error'],
            ));
            $this->redirect('/install?token=' . urlencode($this->expectedToken()));
        }

        // Étape 3 — Création du super-admin si pas déjà existant
        $users = new UserRepository();
        if ($users->findByEmail($email) !== null) {
            $this->flashError('Un utilisateur existe déjà avec cet email.');
            $this->redirect('/install?token=' . urlencode($this->expectedToken()));
        }
        try {
            $user = $users->create(
                email: $email,
                plainPassword: $password,
                fullName: $fullName,
                isSuperAdmin: true,
                needsPasswordSetup: false,
            );
        } catch (\Throwable $e) {
            $this->flashError('Création utilisateur impossible : ' . $e->getMessage());
            $this->redirect('/install?token=' . urlencode($this->expectedToken()));
        }

        // Étape 4 — Verrouille l'installateur
        @file_put_contents($this->lockFile(),
            "Installé le " . date('Y-m-d H:i:s')
            . "\nSuper-admin créé : " . $user->email
            . "\nMigrations appliquées : " . implode(', ', $report['applied'])
        );

        $this->render('pages.install_done', [
            'title' => 'Installation terminée',
            'user_email' => $user->email,
            'applied' => $report['applied'],
        ]);
    }
}
