<?php
use App\Helpers\Renderer;

/** @var string $csrf_token */
?>
<form method="POST" action="/forgot-password" class="auth-form">
    <input type="hidden" name="_csrf" value="<?= Renderer::escape($csrf_token) ?>">

    <h2 class="auth-title">Mot de passe oublié</h2>
    <p class="auth-helper">Saisissez votre email pour recevoir un lien de réinitialisation.</p>

    <label class="field">
        <span class="field__label">Email</span>
        <input type="email" name="email" required autofocus autocomplete="email">
    </label>

    <button type="submit" class="btn btn--primary btn--block">Envoyer le lien</button>

    <p class="auth-link"><a href="/login">Retour à la connexion</a></p>
</form>
