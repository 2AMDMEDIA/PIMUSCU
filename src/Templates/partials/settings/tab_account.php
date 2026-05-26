<?php
use App\Helpers\Renderer;
use App\Session;

/** @var string $csrf_token */
?>
<div class="card">
    <div class="card__header"><h3 class="card__title">Mon compte</h3></div>
    <div class="card__body">
        <form method="POST" action="/settings/account">
            <input type="hidden" name="_csrf" value="<?= Renderer::escape($csrf_token) ?>">

            <div class="form-grid form-grid--2col">
                <label class="field">
                    <span class="field__label">Email</span>
                    <input type="email" value="<?= Renderer::escape((string) Session::get('user_email', '')) ?>" disabled>
                </label>

                <label class="field">
                    <span class="field__label">Nom complet</span>
                    <input type="text" name="full_name" value="<?= Renderer::escape((string) Session::get('user_full_name', '')) ?>">
                </label>

                <label class="field" style="grid-column: 1 / -1;">
                    <span class="field__label">Nouveau mot de passe</span>
                    <input type="password" name="new_password" minlength="8" placeholder="Laisser vide pour ne pas changer" autocomplete="new-password">
                    <span class="field__hint">8 caractères minimum.</span>
                </label>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn--primary">Enregistrer</button>
            </div>
        </form>
    </div>
</div>
