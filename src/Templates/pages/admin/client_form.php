<?php
use App\Helpers\Renderer;
use App\Models\Client;

/**
 * @var string $mode  'new' | 'edit'
 * @var string $title
 * @var ?Client $client
 * @var array<int,array<string,mixed>> $users
 * @var string $csrf_token
 */
$isEdit = $mode === 'edit';
$action = $isEdit ? '/admin/clients/' . $client->id : '/admin/clients';
?>
<div class="page-header">
    <div>
        <h2 class="page-header__title"><?= Renderer::escape($title) ?></h2>
        <?php if ($isEdit): ?>
            <p class="page-header__subtitle">ID : <code><?= Renderer::escape($client->id) ?></code></p>
        <?php endif; ?>
    </div>
    <a href="/admin" class="btn btn--ghost">← Retour</a>
</div>

<form method="POST" action="<?= Renderer::escape($action) ?>" enctype="multipart/form-data">
    <input type="hidden" name="_csrf" value="<?= Renderer::escape($csrf_token) ?>">

    <div class="card">
        <div class="card__header"><h3 class="card__title">Informations du client</h3></div>
        <div class="card__body">
            <div class="form-grid form-grid--2col">
                <label class="field">
                    <span class="field__label">Nom du client *</span>
                    <input type="text" name="name" required value="<?= Renderer::escape($isEdit ? $client->name : '') ?>" placeholder="Ex: Ma Boutique">
                </label>

                <label class="field">
                    <span class="field__label">Nom dans le footer</span>
                    <input type="text" name="footer_name" value="<?= Renderer::escape($isEdit ? ($client->footerName ?? '') : '') ?>" placeholder="Repris du nom si vide">
                </label>

                <label class="field" style="grid-column: 1 / -1;">
                    <span class="field__label">URL boutique PrestaShop *</span>
                    <input type="url" name="prestashop_url" required value="<?= Renderer::escape($isEdit ? $client->prestashopUrl : '') ?>" placeholder="https://maboutique.com">
                    <span class="field__hint">Sans le slash final. La clé API Webservice se configure ensuite dans Paramètres.</span>
                </label>

                <label class="field">
                    <span class="field__label">Limite tokens / mois</span>
                    <input type="number" name="token_monthly_limit" min="0" value="<?= $isEdit ? (int) $client->tokenMonthlyLimit : 0 ?>">
                    <span class="field__hint">0 = pas de limite</span>
                </label>

                <?php if ($isEdit): ?>
                    <label class="field">
                        <span class="field__label">Seuil d'alerte (%)</span>
                        <input type="number" name="token_alert_threshold" min="0" max="100" value="<?= (int) $client->tokenAlertThreshold ?>">
                        <span class="field__hint">Notifie l'admin quand X % de la limite est atteint.</span>
                    </label>
                <?php endif; ?>

                <label class="field" style="grid-column: 1 / -1;">
                    <span class="field__label">Logo (PNG / JPEG / WebP / SVG)</span>
                    <input type="file" name="logo" accept="image/png,image/jpeg,image/webp,image/svg+xml">
                    <?php if ($isEdit && !empty($client->logoUrl)): ?>
                        <span class="field__hint">Logo actuel : <img src="<?= Renderer::escape($client->logoUrl) ?>" style="height:24px;vertical-align:middle;margin-left:8px;"></span>
                    <?php endif; ?>
                </label>
            </div>
        </div>
    </div>

    <?php if (!$isEdit): ?>
        <div class="card" style="margin-top: 24px;">
            <div class="card__header"><h3 class="card__title">Compte administrateur du client</h3></div>
            <div class="card__body">
                <p style="font-size:13px;color:var(--color-text-muted);margin:0 0 16px;">
                    Crée un utilisateur lié à ce client. Il pourra se connecter et accéder à son dashboard.
                </p>
                <div class="form-grid form-grid--2col">
                    <label class="field">
                        <span class="field__label">Email *</span>
                        <input type="email" name="admin_email" required>
                    </label>
                    <label class="field">
                        <span class="field__label">Nom complet</span>
                        <input type="text" name="admin_name">
                    </label>
                    <label class="field" style="grid-column: 1 / -1;">
                        <span class="field__label">Mot de passe (8+ caractères) *</span>
                        <input type="password" name="admin_password" required minlength="8">
                    </label>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <div class="form-actions form-actions--end">
        <a href="/admin" class="btn btn--ghost">Annuler</a>
        <button type="submit" class="btn btn--primary"><?= $isEdit ? 'Enregistrer' : 'Créer le client' ?></button>
    </div>
</form>

<?php if ($isEdit): ?>
    <div class="card" style="margin-top: 32px;">
        <div class="card__header" style="display:flex;justify-content:space-between;align-items:center;">
            <h3 class="card__title">Utilisateurs du client</h3>
            <span style="font-size:13px;color:var(--color-text-muted);"><?= count($users ?? []) ?></span>
        </div>
        <div class="card__body" style="padding: 0;">
            <?php if (empty($users)): ?>
                <div class="empty-state">
                    <div class="empty-state__hint">Aucun utilisateur lié à ce client.</div>
                </div>
            <?php else: ?>
                <table class="table">
                    <thead>
                        <tr><th>Email</th><th>Nom</th><th>Dernière connexion</th><th>Réinitialiser le mot de passe</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $u): ?>
                            <tr>
                                <td><?= Renderer::escape($u['email']) ?></td>
                                <td><?= Renderer::escape($u['full_name']) ?></td>
                                <td style="color:var(--color-text-muted);font-size:13px;">
                                    <?= $u['last_login_at'] ? Renderer::escape(date('d/m/Y H:i', strtotime($u['last_login_at']))) : 'Jamais' ?>
                                </td>
                                <td style="text-align:right;">
                                    <form method="POST"
                                          action="/admin/clients/<?= Renderer::escape($client->id) ?>/users/<?= Renderer::escape($u['id']) ?>/reset-password"
                                          style="display:flex;gap:8px;justify-content:flex-end;margin:0;">
                                        <input type="hidden" name="_csrf" value="<?= Renderer::escape($csrf_token) ?>">
                                        <input type="password" name="new_password" placeholder="Nouveau mot de passe (8+ car.)" minlength="8" required style="font-size:13px;flex:0 1 220px;padding:6px 10px;border:1px solid var(--color-border);border-radius:6px;">
                                        <button type="submit" class="btn btn--primary btn--sm">Reset</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <div class="card" style="margin-top: 32px;border:1px solid #fecaca;">
        <div class="card__header" style="background:#fef2f2;">
            <h3 class="card__title" style="color:#991b1b;">Zone dangereuse</h3>
        </div>
        <div class="card__body" style="display:flex;justify-content:space-between;align-items:center;gap:16px;">
            <div>
                <strong style="font-size:14px;">Supprimer ce client</strong>
                <p style="margin:4px 0 0;font-size:13px;color:var(--color-text-muted);">
                    Supprime définitivement le client, ses utilisateurs liés et toutes ses données (catégories, produits, logs).
                </p>
            </div>
            <form method="POST" action="/admin/clients/<?= Renderer::escape($client->id) ?>/delete"
                  onsubmit="return confirm('Supprimer définitivement ce client et toutes ses données ? Cette action est irréversible.');"
                  style="margin:0;">
                <input type="hidden" name="_csrf" value="<?= Renderer::escape($csrf_token) ?>">
                <button type="submit" class="btn btn--danger">Supprimer le client</button>
            </form>
        </div>
    </div>
<?php endif; ?>
