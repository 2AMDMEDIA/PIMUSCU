<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Csrf;
use App\Middleware\Auth;
use App\Repositories\AdminRepository;
use App\Repositories\ClientRepository;
use App\Repositories\UserRepository;
use App\Services\ClientResolver;

final class AdminController extends BaseController
{
    public function index(): void
    {
        Auth::requireSuperAdmin();

        $clientsRepo = new ClientRepository();
        $adminRepo = new AdminRepository();

        $clients = $clientsRepo->listAllWithStats();
        $superAdmins = $adminRepo->listSuperAdmins();

        $this->renderApp('pages.admin.index', [
            'clients' => $clients,
            'super_admins' => $superAdmins,
        ], [
            'active' => 'admin',
            'page_title' => 'Administration',
        ]);
    }

    /** Sélectionne un client (cookie x-client-id) puis redirige vers /dashboard. */
    public function switchClient(string $id): void
    {
        Auth::requireSuperAdmin();
        Csrf::enforce($this->input('_csrf'));

        $clients = new ClientRepository();
        $client = $clients->findById($id);
        if ($client === null) {
            $this->flashError('Client introuvable.');
            $this->redirect('/admin');
        }

        (new ClientResolver())->setActiveClient($client->id);
        $this->redirect('/dashboard');
    }

    /** Efface le cookie x-client-id (revient à la vue admin). */
    public function clearClient(): void
    {
        Auth::requireSuperAdmin();
        Csrf::enforce($this->input('_csrf'));
        ClientResolver::clearCookie();
        $this->redirect('/admin');
    }

    // -------------------------------------------------------------------------
    // Super-admins
    // -------------------------------------------------------------------------

    public function addSuperAdmin(): void
    {
        Auth::requireSuperAdmin();
        Csrf::enforce($this->input('_csrf'));

        $email = $this->input('email');
        $password = $this->input('password');
        $name = $this->input('full_name') ?? '';

        if ($email === null || $password === null) {
            $this->flashError('Email et mot de passe requis.');
            $this->redirect('/admin');
        }

        if (strlen($password) < 8) {
            $this->flashError('Mot de passe trop court (8 caractères minimum).');
            $this->redirect('/admin');
        }

        $users = new UserRepository();
        $existing = $users->findByEmail($email);

        if ($existing !== null) {
            // Promote existing user to super-admin
            (new AdminRepository())->setSuperAdmin($existing->id, true);
            $this->flashSuccess('Utilisateur existant promu super-admin.');
        } else {
            $users->create($email, $password, $name, isSuperAdmin: true);
            $this->flashSuccess('Super-admin créé.');
        }

        $this->redirect('/admin');
    }

    public function removeSuperAdmin(string $userId): void
    {
        Auth::requireSuperAdmin();
        Csrf::enforce($this->input('_csrf'));

        // Empêcher de se retirer soi-même les droits (sinon plus aucun super-admin)
        if ($userId === \App\Session::userId()) {
            $this->flashError('Vous ne pouvez pas retirer vos propres droits super-admin.');
            $this->redirect('/admin');
        }

        (new AdminRepository())->setSuperAdmin($userId, false);
        $this->flashSuccess('Droits super-admin retirés.');
        $this->redirect('/admin');
    }
}
