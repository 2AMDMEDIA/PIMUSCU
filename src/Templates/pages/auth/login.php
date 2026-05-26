<?php
use App\Helpers\Renderer;

/**
 * @var string $csrf_token
 * @var string $email
 */
?>
<form method="POST" action="/login" class="auth-form">
    <input type="hidden" name="_csrf" value="<?= Renderer::escape($csrf_token) ?>">

    <h2 class="auth-title">Connexion</h2>

    <label class="field">
        <span class="field__label">Email</span>
        <input type="email" name="email" required autofocus
               value="<?= Renderer::escape($email ?? '') ?>"
               autocomplete="email">
    </label>

    <label class="field">
        <span class="field__label">Mot de passe</span>
        <input type="password" name="password" required
               autocomplete="current-password" minlength="8">
    </label>

    <button type="submit" class="btn btn--primary btn--block">Se connecter</button>

    <p class="auth-link">
        <a href="/forgot-password">Mot de passe oublié ?</a>
    </p>
</form>
