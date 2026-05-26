<?php
use App\Helpers\Renderer;

/**
 * @var list<array<string,mixed>> $products
 * @var int $total_reviews
 * @var ?string $error
 */

/** Helper pour afficher des étoiles (Unicode) */
$stars = function (float $g): string {
    $full = (int) floor($g);
    $half = ($g - $full) >= 0.5;
    $out = str_repeat('★', $full);
    if ($half) $out .= '½';
    $empty = 5 - $full - ($half ? 1 : 0);
    $out .= str_repeat('☆', max(0, $empty));
    return $out;
};
?>
<div class="page-header">
    <div>
        <h2 class="page-header__title">Avis Produit</h2>
        <p class="page-header__subtitle">
            <?= count($products) ?> produit<?= count($products) > 1 ? 's' : '' ?> avec avis ·
            <?= $total_reviews ?> avis au total
        </p>
    </div>
</div>

<?php if ($error): ?>
    <div class="flash flash--error" style="margin-bottom:20px;"><?= Renderer::escape($error) ?></div>
<?php endif; ?>

<?php if (empty($products)): ?>
    <div class="card">
        <div class="card__body">
            <div class="empty-state">
                <div class="empty-state__title">Aucun avis pour l'instant</div>
                <div class="empty-state__hint">
                    Vérifiez que <code>api_reviews.php</code> est bien à la racine de votre shop et que la clé API correspond.
                </div>
            </div>
        </div>
    </div>
<?php else: ?>
    <div class="card">
        <div class="card__body" style="padding: 0;">
            <table class="table table--clickable">
                <thead>
                    <tr>
                        <th></th>
                        <th>Produit</th>
                        <th>Note moyenne</th>
                        <th>Avis</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($products as $p): ?>
                        <tr onclick="window.location.href='/avis/<?= Renderer::escape($p['id']) ?>'">
                            <td style="width:60px;">
                                <?php if (!empty($p['image_url'])): ?>
                                    <img src="<?= Renderer::escape($p['image_url']) ?>" alt=""
                                         style="width:44px;height:44px;border-radius:6px;object-fit:cover;"
                                         onerror="this.style.display='none'">
                                <?php endif; ?>
                            </td>
                            <td>
                                <strong><?= Renderer::escape((string) $p['name']) ?></strong>
                                <div style="font-size:11px;color:var(--color-text-muted);font-family:ui-monospace,monospace;">
                                    <?= Renderer::escape((string) ($p['reference'] ?? '')) ?>
                                </div>
                            </td>
                            <td>
                                <span style="font-size:18px;color:#f59e0b;letter-spacing:1px;">
                                    <?= $stars((float) $p['avg_grade']) ?>
                                </span>
                                <span style="margin-left:8px;color:var(--color-text-muted);font-size:13px;">
                                    <?= number_format((float) $p['avg_grade'], 1, ',', ' ') ?> / 5
                                </span>
                            </td>
                            <td>
                                <span class="badge badge--blue"><?= (int) $p['count'] ?> avis</span>
                            </td>
                            <td style="text-align:right;color:var(--color-text-muted);">→</td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>
