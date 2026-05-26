<?php
use App\Helpers\Renderer;
use App\Services\AiProviders;

/**
 * @var array<int,array<string,mixed>> $providers
 * @var array{default_text_provider:string,default_image_provider:string} $prefs
 * @var array<string,array{has_key:bool,masked:?string}> $api_keys
 * @var string $csrf_token
 */
$textProviders = AiProviders::textProviders();
$imageProviders = AiProviders::imageProviders();
?>
<div class="card">
    <div class="card__header"><h3 class="card__title">Provider par défaut</h3></div>
    <div class="card__body">
        <form method="POST" action="/settings/ai-preferences">
            <input type="hidden" name="_csrf" value="<?= Renderer::escape($csrf_token) ?>">
            <div class="form-grid form-grid--2col">
                <label class="field">
                    <span class="field__label">Rédaction (texte)</span>
                    <select name="default_text_provider">
                        <?php foreach ($textProviders as $p): ?>
                            <option value="<?= Renderer::escape($p['id']) ?>" <?= $prefs['default_text_provider'] === $p['id'] ? 'selected' : '' ?>>
                                <?= Renderer::escape($p['name']) ?>
                                <?= !empty($p['recommended']) ? ' ★' : '' ?>
                                <?= !empty($api_keys[$p['id']]['has_key']) ? ' ✓' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="field">
                    <span class="field__label">Images</span>
                    <select name="default_image_provider">
                        <?php foreach ($imageProviders as $p): ?>
                            <option value="<?= Renderer::escape($p['id']) ?>" <?= $prefs['default_image_provider'] === $p['id'] ? 'selected' : '' ?>>
                                <?= Renderer::escape($p['name']) ?>
                                <?= !empty($api_keys[$p['id']]['has_key']) ? ' ✓' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>
            <div class="form-actions">
                <button type="submit" class="btn btn--primary">Enregistrer les préférences</button>
            </div>
        </form>
    </div>
</div>

<div class="card" style="margin-top: 24px;">
    <div class="card__header"><h3 class="card__title">Clés API — Rédaction</h3></div>
    <div class="card__body">
        <?php foreach ($textProviders as $p): ?>
            <?php $hasKey = !empty($api_keys[$p['id']]['has_key']); ?>
            <div class="api-key-row">
                <div class="api-key-row__head">
                    <div>
                        <strong><?= Renderer::escape($p['name']) ?></strong>
                        <?php if (!empty($p['recommended'])): ?>
                            <span class="badge badge--blue" style="margin-left:8px;">Recommandé</span>
                        <?php endif; ?>
                        <?php if ($hasKey): ?>
                            <span class="badge badge--green" style="margin-left:8px;">Configurée</span>
                        <?php endif; ?>
                    </div>
                    <?php if ($hasKey): ?>
                        <form method="POST" action="/settings/api-keys/<?= Renderer::escape($p['id']) ?>/delete" style="margin:0;"
                              onsubmit="return confirm('Supprimer la clé <?= Renderer::escape($p['name']) ?> ?');">
                            <input type="hidden" name="_csrf" value="<?= Renderer::escape($csrf_token) ?>">
                            <button type="submit" class="btn btn--ghost btn--sm" style="color:#dc2626;">Supprimer</button>
                        </form>
                    <?php endif; ?>
                </div>
                <p class="api-key-row__desc"><?= Renderer::escape($p['description']) ?></p>
                <?php if ($hasKey && !empty($api_keys[$p['id']]['masked'])): ?>
                    <p class="api-key-row__masked"><?= Renderer::escape($api_keys[$p['id']]['masked']) ?></p>
                <?php endif; ?>
                <form method="POST" action="/settings/api-keys" class="api-key-row__form">
                    <input type="hidden" name="_csrf" value="<?= Renderer::escape($csrf_token) ?>">
                    <input type="hidden" name="provider" value="<?= Renderer::escape($p['id']) ?>">
                    <input type="password" name="api_key" placeholder="<?= Renderer::escape($p['placeholder']) ?>" autocomplete="off" required>
                    <button type="submit" class="btn btn--primary btn--sm">Enregistrer</button>
                </form>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<div class="card" style="margin-top: 24px;">
    <div class="card__header"><h3 class="card__title">Clés API — Images</h3></div>
    <div class="card__body">
        <?php foreach ($imageProviders as $p): ?>
            <?php $hasKey = !empty($api_keys[$p['id']]['has_key']); ?>
            <div class="api-key-row">
                <div class="api-key-row__head">
                    <div>
                        <strong><?= Renderer::escape($p['name']) ?></strong>
                        <?php if ($hasKey): ?>
                            <span class="badge badge--green" style="margin-left:8px;">Configurée</span>
                        <?php endif; ?>
                    </div>
                    <?php if ($hasKey): ?>
                        <form method="POST" action="/settings/api-keys/<?= Renderer::escape($p['id']) ?>/delete" style="margin:0;"
                              onsubmit="return confirm('Supprimer la clé <?= Renderer::escape($p['name']) ?> ?');">
                            <input type="hidden" name="_csrf" value="<?= Renderer::escape($csrf_token) ?>">
                            <button type="submit" class="btn btn--ghost btn--sm" style="color:#dc2626;">Supprimer</button>
                        </form>
                    <?php endif; ?>
                </div>
                <p class="api-key-row__desc"><?= Renderer::escape($p['description']) ?></p>
                <?php if ($hasKey && !empty($api_keys[$p['id']]['masked'])): ?>
                    <p class="api-key-row__masked"><?= Renderer::escape($api_keys[$p['id']]['masked']) ?></p>
                <?php endif; ?>
                <form method="POST" action="/settings/api-keys" class="api-key-row__form">
                    <input type="hidden" name="_csrf" value="<?= Renderer::escape($csrf_token) ?>">
                    <input type="hidden" name="provider" value="<?= Renderer::escape($p['id']) ?>">
                    <input type="password" name="api_key" placeholder="<?= Renderer::escape($p['placeholder']) ?>" autocomplete="off" required>
                    <button type="submit" class="btn btn--primary btn--sm">Enregistrer</button>
                </form>
            </div>
        <?php endforeach; ?>
    </div>
</div>
