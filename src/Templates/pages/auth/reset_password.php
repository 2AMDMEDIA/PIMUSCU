<?php
use App\Helpers\Renderer;

/**
 * @var string $csrf_token
 * @var string $token
 */
?>
<form method="POST" action="/reset-password" class="auth-form">
    <input type="hidden" name="_csrf" value="<?= Renderer::escape($csrf_token) ?>">
    <input type="hidden" name="token" value="<?= Renderer::escape($token) ?>">

    <h2 class="auth-title">Nouveau mot de passe</h2>

    <label class="field">
        <span class="field__label">Mot de passe</span>
        <input type="password" name="password" required minlength="8" autocomplete="new-password" autofocus>
    </label>

    <label class="field">
        <span class="field__label">Confirmation</span>
        <input type="password" name="password_confirm" required minlength="8" autocomplete="new-password">
    </label>

    <button type="submit" class="btn btn--primary btn--block">Réinitialiser</button>
</form>
