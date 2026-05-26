<?php
use App\Helpers\Renderer;

/**
 * @var array{total:int,with_desc:int,without_desc:int,cms_content:int} $product_stats
 * @var int $categories_count
 * @var array{total_calls:int,tokens_total:int,prompt_tokens:int,completion_tokens:int,cost_eur:float} $usage
 */
$total = $product_stats['total'];
$pct = function (int $n) use ($total): int {
    return $total > 0 ? (int) round($n * 100 / $total) : 0;
};
?>
<div class="page-header">
    <div>
        <h2 class="page-header__title">Vue d'ensemble</h2>
        <p class="page-header__subtitle">État de votre boutique PrestaShop</p>
    </div>
</div>

<div class="form-grid form-grid--2col">
    <a href="/produits" class="card kpi-card kpi-card--blue">
        <div class="kpi-card__label">Produits</div>
        <div class="kpi-card__value"><?= number_format($product_stats['total'], 0, ',', ' ') ?></div>
        <div class="kpi-card__hint"><?= number_format($product_stats['with_desc'], 0, ',', ' ') ?> avec description</div>
    </a>

    <a href="/categories" class="card kpi-card kpi-card--purple">
        <div class="kpi-card__label">Catégories</div>
        <div class="kpi-card__value"><?= number_format($categories_count, 0, ',', ' ') ?></div>
        <div class="kpi-card__hint">en cache local</div>
    </a>

    <div class="card kpi-card kpi-card--amber">
        <div class="kpi-card__label">Usage IA (30j)</div>
        <div class="kpi-card__value"><?= number_format($usage['total_calls'], 0, ',', ' ') ?></div>
        <div class="kpi-card__hint"><?= number_format($usage['tokens_total'], 0, ',', ' ') ?> tokens · <?= number_format($usage['cost_eur'], 2, ',', ' ') ?> €</div>
    </div>

    <a href="/settings?tab=prestashop" class="card kpi-card kpi-card--green">
        <div class="kpi-card__label">Connexion Presta</div>
        <div class="kpi-card__value">→</div>
        <div class="kpi-card__hint">Configurer ou tester la connexion</div>
    </a>
</div>

<div class="card" style="margin-top: 32px;">
    <div class="card__header"><h3 class="card__title">Descriptions produits</h3></div>
    <div class="card__body">
        <?php if ($total === 0): ?>
            <div class="empty-state">
                <div class="empty-state__title">Aucun produit synchronisé</div>
                <div class="empty-state__hint">
                    <a href="/settings?tab=prestashop">Configurez votre clé API PrestaShop</a>
                    puis lancez une synchronisation depuis la page Produits.
                </div>
            </div>
        <?php else: ?>
            <?php
            $bars = [
                ['label' => 'Avec description', 'count' => $product_stats['with_desc'], 'color' => '#10b981'],
                ['label' => 'Sans description', 'count' => $product_stats['without_desc'], 'color' => '#f59e0b'],
                ['label' => 'Contenu CMS (non modifiable)', 'count' => $product_stats['cms_content'], 'color' => '#a855f7'],
            ];
            ?>
            <div style="display:flex;flex-direction:column;gap:14px;">
                <?php foreach ($bars as $bar): ?>
                    <div>
                        <div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:6px;">
                            <span><?= Renderer::escape($bar['label']) ?></span>
                            <span style="color:var(--color-text-muted);">
                                <?= number_format($bar['count'], 0, ',', ' ') ?> · <?= $pct($bar['count']) ?>%
                            </span>
                        </div>
                        <div style="background:#e5e7eb;height:8px;border-radius:4px;overflow:hidden;">
                            <div style="background:<?= $bar['color'] ?>;height:100%;width:<?= $pct($bar['count']) ?>%;transition:width 200ms;"></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>
