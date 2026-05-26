<?php
use App\Helpers\Renderer;

/**
 * @var array{
 *     private_key_encrypted:?string,
 *     catalogue_url:string,
 *     product_info_url:string,
 * } $nutriweb_settings
 * @var string $csrf_token
 */
$hasPrivateKey = $nutriweb_settings['private_key_encrypted'] !== null
    && $nutriweb_settings['private_key_encrypted'] !== '';
?>
<div class="card">
    <div class="card__header"><h3 class="card__title">Configuration Nutriweb</h3></div>
    <div class="card__body">
        <p style="font-size:13px;color:var(--color-text-muted);margin:0 0 20px;">
            Identifiants et endpoints du service Nutriweb pour ce client.
        </p>

        <form method="POST" action="/settings/nutriweb">
            <input type="hidden" name="_csrf" value="<?= Renderer::escape($csrf_token) ?>">

            <div class="form-grid">
                <label class="field">
                    <span class="field__label">
                        Clé privée
                        <?php if ($hasPrivateKey): ?>
                            <span class="badge badge--green" style="margin-left:6px;">Configurée</span>
                        <?php else: ?>
                            <span class="badge badge--gray" style="margin-left:6px;">Non configurée</span>
                        <?php endif; ?>
                    </span>
                    <input type="password"
                           name="private_key"
                           placeholder="<?= $hasPrivateKey ? 'Laisser vide pour conserver l\'actuelle' : 'Coller votre clé privée Nutriweb' ?>"
                           autocomplete="off">
                    <span class="field__hint">Stockée chiffrée en base via <code>APP_SECRET</code>.</span>
                </label>

                <label class="field">
                    <span class="field__label">URL du catalogue</span>
                    <input type="url"
                           name="catalogue_url"
                           value="<?= Renderer::escape($nutriweb_settings['catalogue_url']) ?>"
                           placeholder="https://api.nutriweb.example/catalogue">
                </label>

                <label class="field">
                    <span class="field__label">URL des infos produit</span>
                    <input type="url"
                           name="product_info_url"
                           value="<?= Renderer::escape($nutriweb_settings['product_info_url']) ?>"
                           placeholder="https://api.nutriweb.example/products/{id}">
                </label>
            </div>

            <div class="form-actions" style="margin-top: 20px;">
                <button type="submit" class="btn btn--primary">Enregistrer</button>
            </div>
        </form>
    </div>
</div>
