<?php
use App\Helpers\Renderer;

/**
 * @var list<array{name:string,applied_at:?string,applied:bool,size:int}> $migrations
 * @var int $pending_count
 * @var ?string $error
 * @var string $csrf_token
 */
?>
<div class="page-header">
    <div>
        <h2 class="page-header__title">Migrations DB</h2>
        <p class="page-header__subtitle">
            <?= count($migrations) ?> migration<?= count($migrations) > 1 ? 's' : '' ?> au total
            · <?= $pending_count ?> en attente
        </p>
    </div>
    <a href="/admin" class="btn btn--ghost">← Retour admin</a>
</div>

<?php if ($error): ?>
    <div class="flash flash--error" style="margin-bottom: 20px;">
        <strong>Erreur DB :</strong> <?= Renderer::escape($error) ?>
    </div>
    <div style="font-size: 13px; color: var(--color-text-muted);">
        Vérifie que <code>.env</code> contient les bonnes credentials DB et que la base existe sur le serveur.
    </div>
<?php else: ?>

    <?php if ($pending_count > 0): ?>
        <div class="card" style="margin-bottom: 24px; border-left: 4px solid #f59e0b;">
            <div class="card__body" style="display: flex; align-items: center; justify-content: space-between; gap: 16px;">
                <div>
                    <strong style="color: #92400e;">⚠ <?= $pending_count ?> migration<?= $pending_count > 1 ? 's' : '' ?> en attente</strong>
                    <p style="margin: 4px 0 0; font-size: 13px; color: var(--color-text-muted);">
                        Clique sur "Appliquer" pour exécuter toutes les migrations en attente dans l'ordre.
                    </p>
                </div>
                <form method="POST" action="/admin/migrations/run" style="margin: 0;">
                    <input type="hidden" name="_csrf" value="<?= Renderer::escape($csrf_token) ?>">
                    <button type="submit" class="btn btn--primary"
                            onclick="return confirm('Appliquer les <?= $pending_count ?> migration(s) en attente sur la base de données ?');">
                        ✓ Appliquer les migrations en attente
                    </button>
                </form>
            </div>
        </div>
    <?php else: ?>
        <div class="card" style="margin-bottom: 24px; border-left: 4px solid #10b981;">
            <div class="card__body">
                <strong style="color: #065f46;">✓ Base de données à jour</strong>
                <p style="margin: 4px 0 0; font-size: 13px; color: var(--color-text-muted);">
                    Toutes les migrations sont appliquées.
                </p>
            </div>
        </div>
    <?php endif; ?>

    <div class="card">
        <div class="card__header">
            <h3 class="card__title">Historique des migrations</h3>
        </div>
        <div class="card__body" style="padding: 0;">
            <?php if (empty($migrations)): ?>
                <div class="empty-state">
                    <div class="empty-state__title">Aucune migration trouvée</div>
                    <div class="empty-state__hint">Le dossier <code>migrations/</code> est vide.</div>
                </div>
            <?php else: ?>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Statut</th>
                            <th>Nom</th>
                            <th>Taille</th>
                            <th>Appliquée le</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($migrations as $m): ?>
                            <tr>
                                <td>
                                    <?php if ($m['applied']): ?>
                                        <span class="badge badge--green">✓ Appliquée</span>
                                    <?php else: ?>
                                        <span class="badge badge--amber">⏳ En attente</span>
                                    <?php endif; ?>
                                </td>
                                <td><code><?= Renderer::escape($m['name']) ?></code></td>
                                <td style="color: var(--color-text-muted); font-size: 13px;">
                                    <?= number_format($m['size'] / 1024, 1, ',', ' ') ?> Ko
                                </td>
                                <td style="color: var(--color-text-muted); font-size: 13px;">
                                    <?= $m['applied_at'] ? Renderer::escape(date('d/m/Y H:i', strtotime($m['applied_at']))) : '—' ?>
                                </td>
                                <td style="text-align: right;">
                                    <?php if (!$m['applied']): ?>
                                        <form method="POST" action="/admin/migrations/mark-applied/<?= urlencode($m['name']) ?>" style="display: inline; margin: 0;"
                                              onsubmit="return confirm('Marquer cette migration comme déjà appliquée SANS l\'exécuter ? À faire uniquement si tu as déjà chargé ce SQL à la main via phpMyAdmin.');">
                                            <input type="hidden" name="_csrf" value="<?= Renderer::escape($csrf_token) ?>">
                                            <button type="submit" class="btn btn--ghost btn--sm">Marquer comme appliquée</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

<?php endif; ?>
