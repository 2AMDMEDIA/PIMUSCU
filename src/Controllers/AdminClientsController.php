<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Bootstrap;
use App\Helpers\Csrf;
use App\Middleware\Auth;
use App\Repositories\AdminRepository;
use App\Repositories\ClientRepository;
use App\Repositories\UserClientRepository;
use App\Repositories\UserRepository;
use Ramsey\Uuid\Uuid;

final class AdminClientsController extends BaseController
{
    public function showNew(): void
    {
        Auth::requireSuperAdmin();
        $this->renderApp('pages.admin.client_form', [
            'mode' => 'new',
            'title' => 'Nouveau client',
            'client' => null,
            'admin_email' => '',
            'admin_name' => '',
            'admin_password' => '',
        ], [
            'active' => 'admin',
            'page_title' => 'Nouveau client',
        ]);
    }

    public function create(): void
    {
        Auth::requireSuperAdmin();
        Csrf::enforce($this->input('_csrf'));

        $name = $this->input('name');
        $prestashopUrl = $this->input('prestashop_url') ?? '';
        $footerName = $this->input('footer_name');
        $tokenLimit = (int) ($this->input('token_monthly_limit') ?? '0');

        $adminEmail = $this->input('admin_email');
        $adminPassword = $this->input('admin_password');
        $adminName = $this->input('admin_name') ?? '';

        if ($name === null || $adminEmail === null || $adminPassword === null) {
            $this->flashError('Nom du client, email et mot de passe administrateur requis.');
            $this->redirect('/admin/clients/new');
        }

        if (strlen($adminPassword) < 8) {
            $this->flashError('Mot de passe administrateur trop court (8 caractères minimum).');
            $this->redirect('/admin/clients/new');
        }

        $users = new UserRepository();
        $clients = new ClientRepository();
        $links = new UserClientRepository();

        if ($users->findByEmail($adminEmail) !== null) {
            $this->flashError('Un utilisateur existe déjà avec cet email.');
            $this->redirect('/admin/clients/new');
        }

        // Création client
        $client = $clients->create(
            name: $name,
            prestashopUrl: $prestashopUrl,
            logoUrl: null,
            footerName: $footerName ?? $name,
            tokenMonthlyLimit: max(0, $tokenLimit),
        );

        // Création user admin du client
        $user = $users->create(
            email: $adminEmail,
            plainPassword: $adminPassword,
            fullName: $adminName,
            isSuperAdmin: false,
            needsPasswordSetup: false,
        );

        $links->link($user->id, $client->id);

        // Logo (optionnel, multipart/form-data)
        if (!empty($_FILES['logo']['tmp_name'])) {
            $this->handleLogoUpload($client->id);
        }

        $this->flashSuccess('Client créé avec son administrateur.');
        $this->redirect('/admin/clients/' . $client->id);
    }

    public function showEdit(string $id): void
    {
        Auth::requireSuperAdmin();

        $clients = new ClientRepository();
        $client = $clients->findById($id);
        if ($client === null) {
            http_response_code(404);
            echo 'Client introuvable';
            return;
        }

        $users = $clients->usersForClient($client->id);

        $this->renderApp('pages.admin.client_form', [
            'mode' => 'edit',
            'title' => 'Éditer ' . $client->name,
            'client' => $client,
            'users' => $users,
        ], [
            'active' => 'admin',
            'page_title' => $client->name,
        ]);
    }

    public function update(string $id): void
    {
        Auth::requireSuperAdmin();
        Csrf::enforce($this->input('_csrf'));

        $clients = new ClientRepository();
        $client = $clients->findById($id);
        if ($client === null) {
            http_response_code(404);
            echo 'Client introuvable';
            return;
        }

        $clients->update(
            id: $client->id,
            name: $this->input('name') ?? $client->name,
            prestashopUrl: $this->input('prestashop_url') ?? $client->prestashopUrl,
            logoUrl: $client->logoUrl, // logo modifié séparément via upload
            footerName: $this->input('footer_name'),
            tokenMonthlyLimit: max(0, (int) ($this->input('token_monthly_limit') ?? '0')),
            tokenAlertThreshold: max(0, min(100, (int) ($this->input('token_alert_threshold') ?? '80'))),
        );

        if (!empty($_FILES['logo']['tmp_name'])) {
            $this->handleLogoUpload($client->id);
        }

        $this->flashSuccess('Client mis à jour.');
        $this->redirect('/admin/clients/' . $client->id);
    }

    public function delete(string $id): void
    {
        Auth::requireSuperAdmin();
        Csrf::enforce($this->input('_csrf'));

        $clients = new ClientRepository();
        $client = $clients->findById($id);
        if ($client !== null) {
            $clients->delete($client->id);
            $this->flashSuccess('Client supprimé.');
        }
        $this->redirect('/admin');
    }

    public function resetUserPassword(string $clientId, string $userId): void
    {
        Auth::requireSuperAdmin();
        Csrf::enforce($this->input('_csrf'));

        $newPassword = $this->input('new_password');
        if ($newPassword === null || strlen($newPassword) < 8) {
            $this->flashError('Mot de passe invalide (8 caractères minimum).');
            $this->redirect('/admin/clients/' . $clientId);
        }

        (new UserRepository())->updatePassword($userId, $newPassword);
        $this->flashSuccess('Mot de passe utilisateur réinitialisé.');
        $this->redirect('/admin/clients/' . $clientId);
    }

    public function showUsage(string $id): void
    {
        Auth::requireSuperAdmin();

        $clients = new ClientRepository();
        $client = $clients->findById($id);
        if ($client === null) {
            http_response_code(404);
            echo 'Client introuvable';
            return;
        }

        $admin = new AdminRepository();
        $stats = $admin->usageStatsForClient($client->id, 30);
        $byProvider = $admin->usageByProviderForClient($client->id, 30);

        $this->renderApp('pages.admin.client_usage', [
            'client' => $client,
            'stats' => $stats,
            'by_provider' => $byProvider,
        ], [
            'active' => 'admin',
            'page_title' => 'Usage — ' . $client->name,
        ]);
    }

    private function handleLogoUpload(string $clientId): void
    {
        $tmp = $_FILES['logo']['tmp_name'] ?? '';
        $name = $_FILES['logo']['name'] ?? '';
        if (!is_string($tmp) || $tmp === '' || !is_uploaded_file($tmp)) {
            return;
        }

        $allowed = ['image/png', 'image/jpeg', 'image/webp', 'image/svg+xml'];
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($tmp);
        if (!in_array($mime, $allowed, true)) {
            $this->flashError('Format de logo non supporté (PNG, JPEG, WebP, SVG attendus).');
            return;
        }

        $extMap = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'image/svg+xml' => 'svg'];
        $ext = $extMap[$mime];

        $filename = 'logo-' . $clientId . '.' . $ext;
        $destDir = Bootstrap::rootPath() . '/public/assets/logos';
        if (!is_dir($destDir)) {
            mkdir($destDir, 0755, true);
        }
        $destPath = $destDir . '/' . $filename;

        // Supprimer l'ancien logo (autres extensions)
        foreach (['png', 'jpg', 'webp', 'svg'] as $oldExt) {
            $oldFile = $destDir . '/logo-' . $clientId . '.' . $oldExt;
            if (is_file($oldFile) && $oldFile !== $destPath) {
                @unlink($oldFile);
            }
        }

        if (!move_uploaded_file($tmp, $destPath)) {
            $this->flashError('Échec de l\'upload du logo.');
            return;
        }

        $publicUrl = '/assets/logos/' . $filename;
        (new ClientRepository())->updateLogoUrl($clientId, $publicUrl);
    }
}
