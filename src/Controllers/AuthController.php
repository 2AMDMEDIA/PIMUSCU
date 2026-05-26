<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Bootstrap;
use App\Helpers\Csrf;
use App\Repositories\PasswordTokenRepository;
use App\Repositories\UserRepository;
use App\Services\Mailer;
use App\Session;

final class AuthController extends BaseController
{
    private UserRepository $users;
    private PasswordTokenRepository $tokens;

    public function __construct()
    {
        $this->users = new UserRepository();
        $this->tokens = new PasswordTokenRepository();
    }

    // -------------------------------------------------------------------------
    // LOGIN
    // -------------------------------------------------------------------------

    public function showLogin(): void
    {
        if (Session::isLoggedIn()) {
            $this->redirect('/dashboard');
        }
        $this->render('pages.auth.login', layout: 'layouts.auth', data: [
            'title' => 'Connexion',
            'email' => $_GET['email'] ?? '',
        ]);
    }

    public function login(): void
    {
        Csrf::enforce($this->input('_csrf'));

        $email = $this->input('email');
        $password = $this->input('password');

        if ($email === null || $password === null) {
            $this->flashError('Email et mot de passe requis.');
            $this->redirect('/login');
        }

        $user = $this->users->findByEmail($email);
        if ($user === null || !$user->verifyPassword($password)) {
            $this->flashError('Identifiants invalides.');
            $this->redirect('/login?email=' . urlencode($email));
        }

        if ($user->needsPasswordSetup) {
            $this->flashError('Vous devez d\'abord configurer votre mot de passe via le lien d\'invitation reçu par email.');
            $this->redirect('/login');
        }

        Session::regenerate();
        Session::set('user_id', $user->id);
        Session::set('user_email', $user->email);
        Session::set('user_full_name', $user->fullName);
        Session::set('is_super_admin', $user->isSuperAdmin);
        Csrf::regenerate();

        $this->users->touchLastLogin($user->id);
        $this->redirect($user->isSuperAdmin ? '/admin' : '/dashboard');
    }

    public function logout(): void
    {
        Csrf::enforce($this->input('_csrf'));
        Session::destroy();
        $this->redirect('/login');
    }

    // -------------------------------------------------------------------------
    // FORGOT PASSWORD
    // -------------------------------------------------------------------------

    public function showForgotPassword(): void
    {
        $this->render('pages.auth.forgot_password', layout: 'layouts.auth', data: ['title' => 'Mot de passe oublié']);
    }

    public function sendResetLink(): void
    {
        Csrf::enforce($this->input('_csrf'));
        $email = $this->input('email');

        if ($email === null) {
            $this->flashError('Email requis.');
            $this->redirect('/forgot-password');
        }

        $user = $this->users->findByEmail($email);

        // Toujours afficher le même message pour ne pas révéler les emails inscrits.
        if ($user !== null && !$user->needsPasswordSetup) {
            $this->tokens->purgeForUser($user->id, 'reset');
            $hours = (int) (Bootstrap::config('app.tokens.reset_lifetime_hours') ?? 1);
            $token = $this->tokens->create($user->id, 'reset', $hours * 3600);

            $url = rtrim((string) Bootstrap::config('app.url'), '/')
                . '/reset-password?token=' . urlencode($token);

            $body = '<p>Bonjour,</p>'
                . '<p>Vous avez demandé la réinitialisation de votre mot de passe sur ' . htmlspecialchars((string) Bootstrap::config('app.name')) . '.</p>'
                . '<p><a href="' . htmlspecialchars($url) . '">Cliquez ici pour définir un nouveau mot de passe</a> (lien valable ' . $hours . 'h).</p>'
                . '<p>Si vous n\'avez pas fait cette demande, ignorez cet email.</p>';

            (new Mailer())->send($user->email, $user->displayName(), 'Réinitialisation de mot de passe', $body);
        }

        $this->flashSuccess('Si un compte existe avec cet email, un lien de réinitialisation vient d\'être envoyé.');
        $this->redirect('/login');
    }

    // -------------------------------------------------------------------------
    // RESET PASSWORD
    // -------------------------------------------------------------------------

    public function showResetPassword(): void
    {
        $token = $this->input('token');
        if ($token === null) {
            $this->flashError('Lien invalide.');
            $this->redirect('/login');
        }
        $row = $this->tokens->findValid($token, 'reset');
        if ($row === null) {
            $this->flashError('Lien expiré ou invalide. Demandez un nouveau lien.');
            $this->redirect('/forgot-password');
        }
        $this->render('pages.auth.reset_password', layout: 'layouts.auth', data: [
            'title' => 'Nouveau mot de passe',
            'token' => $token,
        ]);
    }

    public function resetPassword(): void
    {
        Csrf::enforce($this->input('_csrf'));
        $token = $this->input('token');
        $password = $this->input('password');
        $confirm = $this->input('password_confirm');

        if ($token === null || $password === null || $confirm === null) {
            $this->flashError('Tous les champs sont requis.');
            $this->redirect('/login');
        }

        if ($password !== $confirm) {
            $this->flashError('Les mots de passe ne correspondent pas.');
            $this->redirect('/reset-password?token=' . urlencode($token));
        }

        if (strlen($password) < 8) {
            $this->flashError('Le mot de passe doit contenir au moins 8 caractères.');
            $this->redirect('/reset-password?token=' . urlencode($token));
        }

        $row = $this->tokens->findValid($token, 'reset');
        if ($row === null) {
            $this->flashError('Lien expiré ou invalide.');
            $this->redirect('/forgot-password');
        }

        $this->users->updatePassword($row['user_id'], $password);
        $this->tokens->markUsed($row['id']);

        $this->flashSuccess('Mot de passe mis à jour. Vous pouvez vous connecter.');
        $this->redirect('/login');
    }

    // -------------------------------------------------------------------------
    // SET PASSWORD (primo-connexion via invitation)
    // -------------------------------------------------------------------------

    public function showSetPassword(): void
    {
        $token = $this->input('token');
        if ($token === null) {
            $this->flashError('Lien invalide.');
            $this->redirect('/login');
        }
        $row = $this->tokens->findValid($token, 'invitation');
        if ($row === null) {
            $this->flashError('Lien d\'invitation expiré ou invalide.');
            $this->redirect('/login');
        }
        $this->render('pages.auth.set_password', layout: 'layouts.auth', data: [
            'title' => 'Définir votre mot de passe',
            'token' => $token,
        ]);
    }

    public function setPassword(): void
    {
        Csrf::enforce($this->input('_csrf'));
        $token = $this->input('token');
        $password = $this->input('password');
        $confirm = $this->input('password_confirm');

        if ($token === null || $password === null || $confirm === null) {
            $this->flashError('Tous les champs sont requis.');
            $this->redirect('/login');
        }

        if ($password !== $confirm) {
            $this->flashError('Les mots de passe ne correspondent pas.');
            $this->redirect('/set-password?token=' . urlencode($token));
        }

        if (strlen($password) < 8) {
            $this->flashError('Le mot de passe doit contenir au moins 8 caractères.');
            $this->redirect('/set-password?token=' . urlencode($token));
        }

        $row = $this->tokens->findValid($token, 'invitation');
        if ($row === null) {
            $this->flashError('Lien d\'invitation expiré ou invalide.');
            $this->redirect('/login');
        }

        $this->users->updatePassword($row['user_id'], $password);
        $this->tokens->markUsed($row['id']);

        $this->flashSuccess('Mot de passe défini. Vous pouvez vous connecter.');
        $this->redirect('/login');
    }
}
