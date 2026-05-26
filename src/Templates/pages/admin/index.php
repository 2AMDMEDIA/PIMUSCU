<?php
use App\Helpers\Csrf;
use App\Helpers\Renderer;

/**
 * @var array<int,array<string,mixed>> $clients
 * @var array<int,array<string,mixed>> $super_admins
 * @var string $csrf_token
 */
?>
<div class="page-header">
    <div>
        <h2 class="page-header__title">Clients</h2>
        <p class="page-header__subtitle"><?= count($clients) ?> client<?= count($clients) > 1 ? 's' : '' ?></p>
    </div>
    <div style="display: flex; gap: 8px;">
        <a href="/admin/migrations" class="btn btn--secondary">🛠 Migrations DB</a>
        <a href="/admin/clients/new" class="btn btn--primary">+ Nouveau client</a>
    </div>
</div>

<div class="card">
    <div class="card__body" style="padding: 0;">
        <?php if (empty($clients)): ?>
            <div class="empty-state">
                <div class="empty-state__title">Aucun client pour l'instant</div>
                <div class="empty-state__hint">Crée ton premier client pour démarrer.</div>
            </div>
        <?php else: ?>
            <table class="table">
                <thead>
                    <tr>
                        <th>Nom</th>
                        <th>URL PrestaShop</th>
                        <th>Utilisateurs</th>
                        <th>Tokens (30j)</th>
                        <th>Limite</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($clients as $c): ?>
                        <tr>
                            <td>
                                <div style="display: flex; align-items: center; gap: 10px;">
                                    <?php if (!empty($c['logo_url'])): ?>
                                        <img src="<?= Renderer::escape($c['logo_url']) ?>" alt="" style="width:28px;height:28px;border-radius:4px;object-fit:cover;">
                                    <?php else: ?>
                                        <div style="width:28px;height:28px;border-radius:4px;background:#e5e7eb;color:#475569;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:12px;">
                                            <?= Renderer::escape(mb_strtoupper(mb_substr($c['name'], 0, 1))) ?>
                                        </div>
                                    <?php endif; ?>
                                    <strong><?= Renderer::escape($c['name']) ?></strong>
                                </div>
                            </td>
                            <td style="color:var(--color-text-muted);font-size:13px;">
                                <?php if ($c['prestashop_url']): ?>
                                    <a href="<?= Renderer::escape($c['prestashop_url']) ?>" target="_blank" rel="noopener">
                                        <?= Renderer::escape(parse_url($c['prestashop_url'], PHP_URL_HOST) ?: $c['prestashop_url']) ?>
                                    </a>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                            <td><?= (int) $c['user_count'] ?></td>
                            <td><?= number_format((int) $c['tokens_30d'], 0, ',', ' ') ?></td>
                            <td><?= (int) $c['token_monthly_limit'] === 0 ? '∞' : number_format((int) $c['token_monthly_limit'], 0, ',', ' ') ?></td>
                            <td style="text-align:right;white-space:nowrap;">
                                <form method="POST" action="/admin/clients/<?= Renderer::escape($c['id']) ?>/switch" style="display:inline;">
                                    <input type="hidden" name="_csrf" value="<?= Renderer::escape($csrf_token) ?>">
                                    <button type="submit" class="btn btn--secondary btn--sm">Ouvrir</button>
                                </form>
                                <a href="/admin/clients/<?= Renderer::escape($c['id']) ?>" class="btn btn--ghost btn--sm">Éditer</a>
                                <a href="/admin/clients/<?= Renderer::escape($c['id']) ?>/usage" class="btn btn--ghost btn--sm">Usage</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<div class="card" style="margin-top: 32px;">
    <div class="card__header" style="display:flex;justify-content:space-between;align-items:center;">
        <h3 class="card__title">Super-admins</h3>
        <span style="font-size:13px;color:var(--color-text-muted);"><?= count($super_admins) ?> compte<?= count($super_admins) > 1 ? 's' : '' ?></span>
    </div>
    <div class="card__body">
        <?php if (!empty($super_admins)): ?>
            <table class="table" style="margin-bottom: 24px;">
                <thead>
                    <tr><th>Email</th><th>Nom</th><th>Dernière connexion</th><th></th></tr>
                </thead>
                <tbody>
                    <?php foreach ($super_admins as $sa): ?>
                        <tr>
                            <td><?= Renderer::escape($sa['email']) ?></td>
                            <td><?= Renderer::escape($sa['full_name']) ?></td>
                            <td style="color:var(--color-text-muted);font-size:13px;">
                                <?= $sa['last_login_at'] ? Renderer::escape(date('d/m/Y H:i', strtotime($sa['last_login_at']))) : 'Jamais' ?>
                            </td>
                            <td style="text-align:right;">
                                <?php if ($sa['id'] !== $current_user_id): ?>
                                    <form method="POST" action="/admin/super-admins/<?= Renderer::escape($sa['id']) ?>/remove" style="display:inline;"
                                          onsubmit="return confirm('Retirer les droits super-admin de <?= Renderer::escape($sa['email']) ?> ?');">
                                        <input type="hidden" name="_csrf" value="<?= Renderer::escape($csrf_token) ?>">
                                        <button type="submit" class="btn btn--ghost btn--sm" style="color:#dc2626;">Retirer</button>
                                    </form>
                                <?php else: ?>
                                    <span class="badge badge--gray">vous</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <h4 style="margin: 0 0 12px; font-size: 13px; font-weight: 600;">Ajouter un super-admin</h4>
        <form method="POST" action="/admin/super-admins" class="form-grid form-grid--2col">
            <input type="hidden" name="_csrf" value="<?= Renderer::escape($csrf_token) ?>">
            <label class="field">
                <span class="field__label">Email *</span>
                <input type="email" name="email" required>
            </label>
            <label class="field">
                <span class="field__label">Nom complet</span>
                <input type="text" name="full_name">
            </label>
            <label class="field" style="grid-column: 1 / -1;">
                <span class="field__label">Mot de passe (8+ caractères) *</span>
                <input type="password" name="password" required minlength="8">
                <span class="field__hint">Si l'email existe déjà, le compte sera promu super-admin (mot de passe ignoré).</span>
            </label>
            <div style="grid-column: 1 / -1;">
                <button type="submit" class="btn btn--primary">Ajouter</button>
            </div>
        </form>
    </div>
</div>
