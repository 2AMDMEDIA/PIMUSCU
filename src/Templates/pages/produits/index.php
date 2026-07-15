<style>
.produits-category-select { padding: 6px 10px; border: 1px solid var(--color-border); border-radius: var(--radius); background: var(--color-surface); font-size: 13px; min-width: 220px; max-width: 320px; cursor: pointer; }
.produits-category-select:focus { outline: 2px solid var(--color-primary, #2563eb); outline-offset: -1px; }

/* Stickers sur la miniature produit */
.product-card__image { position: relative; }
.product-card__sticker {
    position: absolute; top: 6px; left: 6px; z-index: 1;
    padding: 2px 7px;
    font-size: 10px; font-weight: 600; letter-spacing: 0.3px;
    border-radius: 4px;
    text-shadow: 0 1px 2px rgba(0,0,0,.2);
    pointer-events: none;
}
.product-card__sticker--active { background: #16a34a; color: white; }
.product-card__sticker--inactive { background: #9ca3af; color: white; opacity: 0.85; }
.product-card--inactive .product-card__image img { filter: grayscale(0.6) opacity(0.7); }

/* Avis à la place de la référence */
.product-card__reviews { color: #f59e0b; font-weight: 600; }
/* Ref fournisseur sous la ligne ref/avis */
.product-card__supplier-ref { font-size: 11px; color: var(--color-text-muted); margin-top: 2px; }
</style>
<?php
use App\Helpers\Renderer;

/**
 * @var list<array<string,mixed>> $products
 * @var array{total:int,with_desc:int,without_desc:int,cms:int,active:int,inactive:int,catalog_in:int,catalog_out:int} $stats
 * @var int $total_filtered
 * @var string $search
 * @var string $filter
 * @var string $status
 * @var string $catalog
 * @var int $category
 * @var list<array{presta_id:int,name:string,product_count:int}> $category_options
 * @var int $page
 * @var int $total_pages
 * @var bool $has_api_key
 * @var string $csrf_token
 */

// Helper pour construire les URLs en préservant les autres params (filter, status, catalog, category, q)
$buildUrl = function (?string $newFilter = null, ?string $newStatus = null, ?int $newPage = null, ?int $newCategory = null, ?string $newCatalog = null) use ($filter, $status, $catalog, $category, $search) {
    $q = [
        'filter' => $newFilter ?? $filter,
        'status' => $newStatus ?? $status,
    ];
    $cat = $newCatalog ?? $catalog;
    if ($cat !== 'all' && $cat !== '') $q['catalog'] = $cat;
    $catVal = $newCategory ?? $category;
    if ($catVal > 0) $q['category'] = $catVal;
    if ($newPage !== null) $q['page'] = $newPage;
    if ($search !== '') $q['q'] = $search;
    return '/produits?' . http_build_query($q);
};
?>
<div class="page-header">
    <div>
        <h2 class="page-header__title">Produits</h2>
        <p class="page-header__subtitle">
            <?= $stats['total'] ?> produit<?= $stats['total'] > 1 ? 's' : '' ?>
            — <?= $stats['with_desc'] ?> avec description, <?= $stats['without_desc'] ?> sans
            <?= $stats['cms'] > 0 ? ', ' . $stats['cms'] . ' CMS' : '' ?>
        </p>
    </div>
    <div style="display:flex;gap:8px;">
        <?php if ($has_api_key): ?>
            <form method="POST" action="/produits/sync" style="margin:0;">
                <input type="hidden" name="_csrf" value="<?= Renderer::escape($csrf_token) ?>">
                <button type="submit" class="btn btn--primary">Synchroniser</button>
            </form>
        <?php else: ?>
            <a href="/settings?tab=prestashop" class="btn btn--primary">Configurer Presta</a>
        <?php endif; ?>
    </div>
</div>

<?php if ($stats['total'] === 0): ?>
    <div class="card">
        <div class="card__body">
            <div class="empty-state">
                <div class="empty-state__title">Aucun produit synchronisé</div>
                <div class="empty-state__hint">
                    <?php if (!$has_api_key): ?>
                        <a href="/settings?tab=prestashop">Configurez votre clé API PrestaShop</a> avant de synchroniser.
                    <?php else: ?>
                        Cliquez sur <strong>Synchroniser</strong> pour récupérer les produits.
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
<?php else: ?>
    <form method="GET" action="/produits" class="produits-toolbar" id="produits-filter-form">
        <input type="search" name="q" placeholder="Rechercher par nom ou référence..." value="<?= Renderer::escape($search) ?>" class="produits-search">

        <select name="category" onchange="this.form.submit()" class="produits-category-select" title="Filtrer par catégorie">
            <option value="0">— Toutes catégories (<?= $stats['total'] ?>) —</option>
            <?php foreach ($category_options as $opt): ?>
                <option value="<?= (int) $opt['presta_id'] ?>" <?= $category === $opt['presta_id'] ? 'selected' : '' ?>>
                    <?= Renderer::escape($opt['name']) ?> (<?= $opt['product_count'] ?>)
                </option>
            <?php endforeach; ?>
        </select>

        <!-- Conserve les autres filtres en cours (le form GET n'envoie que ses inputs). -->
        <input type="hidden" name="filter" value="<?= Renderer::escape($filter) ?>">
        <input type="hidden" name="status" value="<?= Renderer::escape($status) ?>">
        <input type="hidden" name="catalog" value="<?= Renderer::escape($catalog) ?>">

        <button type="submit" class="btn btn--secondary btn--sm">Rechercher</button>
        <?php if ($search !== '' || $category > 0): ?>
            <a href="<?= Renderer::escape($buildUrl(newCategory: 0)) ?>&q=" class="btn btn--ghost btn--sm">✕ Effacer</a>
        <?php endif; ?>
    </form>

    <div class="filter-pills" style="margin-bottom: 16px;">
        <a href="<?= Renderer::escape($buildUrl(newFilter: 'all')) ?>"
           class="filter-pill <?= $filter === 'all' ? 'filter-pill--active' : '' ?>">
            Tous (<?= $stats['total'] ?>)
        </a>
        <a href="<?= Renderer::escape($buildUrl(newFilter: 'with_desc')) ?>"
           class="filter-pill <?= $filter === 'with_desc' ? 'filter-pill--active' : '' ?>">
            ✓ Avec desc. (<?= $stats['with_desc'] ?>)
        </a>
        <a href="<?= Renderer::escape($buildUrl(newFilter: 'without_desc')) ?>"
           class="filter-pill <?= $filter === 'without_desc' ? 'filter-pill--active' : '' ?>">
            ✕ Sans desc. (<?= $stats['without_desc'] ?>)
        </a>
        <?php if ($stats['cms'] > 0): ?>
            <a href="<?= Renderer::escape($buildUrl(newFilter: 'cms')) ?>"
               class="filter-pill <?= $filter === 'cms' ? 'filter-pill--active' : '' ?>">
                CMS (<?= $stats['cms'] ?>)
            </a>
        <?php endif; ?>

        <span style="border-left:1px solid var(--color-border); margin: 0 4px;"></span>

        <a href="<?= Renderer::escape($buildUrl(newStatus: 'all')) ?>"
           class="filter-pill <?= $status === 'all' ? 'filter-pill--active' : '' ?>">
            Tous statuts
        </a>
        <a href="<?= Renderer::escape($buildUrl(newStatus: 'active')) ?>"
           class="filter-pill <?= $status === 'active' ? 'filter-pill--active' : '' ?>">
            ● Actifs (<?= $stats['active'] ?>)
        </a>
        <a href="<?= Renderer::escape($buildUrl(newStatus: 'inactive')) ?>"
           class="filter-pill <?= $status === 'inactive' ? 'filter-pill--active' : '' ?>">
            ○ Inactifs (<?= $stats['inactive'] ?>)
        </a>

        <span style="border-left:1px solid var(--color-border); margin: 0 4px;"></span>

        <a href="<?= Renderer::escape($buildUrl(newCatalog: 'all')) ?>"
           class="filter-pill <?= $catalog === 'all' ? 'filter-pill--active' : '' ?>">
            Tous catalogue
        </a>
        <a href="<?= Renderer::escape($buildUrl(newCatalog: 'in')) ?>"
           class="filter-pill <?= $catalog === 'in' ? 'filter-pill--active' : '' ?>"
           title="Produits liés à au moins un SKU du catalogue Nutriweb">
            ✓ Présent catalogue (<?= (int) ($stats['catalog_in'] ?? 0) ?>)
        </a>
        <a href="<?= Renderer::escape($buildUrl(newCatalog: 'out')) ?>"
           class="filter-pill <?= $catalog === 'out' ? 'filter-pill--active' : '' ?>"
           title="Produits non liés au catalogue Nutriweb">
            ✕ Absent catalogue (<?= (int) ($stats['catalog_out'] ?? 0) ?>)
        </a>
    </div>

    <?php if (empty($products)): ?>
        <div class="empty-state">
            <div class="empty-state__title">Aucun produit ne correspond aux filtres</div>
            <div class="empty-state__hint">Essayez d'élargir votre recherche.</div>
        </div>
    <?php else: ?>
        <div class="product-grid">
            <?php foreach ($products as $p):
                $priceTTC = (float) $p['price'] * 1.20;
                $isActive = (int) ($p['active'] ?? 0) === 1;

                if ($p['has_cms_content']) {
                    $badge = ['label' => 'CMS', 'color' => 'purple', 'icon' => '◆'];
                } elseif ($p['has_description']) {
                    $badge = ['label' => 'Desc.', 'color' => 'green', 'icon' => '✓'];
                } else {
                    $badge = ['label' => 'À générer', 'color' => 'amber', 'icon' => '✨'];
                }
            ?>
                <a href="/produits/<?= Renderer::escape((string) $p['id']) ?>" class="product-card<?= $isActive ? '' : ' product-card--inactive' ?>">
                    <div class="product-card__image">
                        <?php if (!empty($p['image_url'])): ?>
                            <img src="<?= Renderer::escape($p['image_url']) ?>"
                                 alt=""
                                 loading="lazy"
                                 onerror="this.outerHTML='<div class=&quot;product-card__no-image&quot;><span>📷</span><small>No photo</small></div>'">
                        <?php else: ?>
                            <div class="product-card__no-image">
                                <span>📷</span>
                                <small>No photo</small>
                            </div>
                        <?php endif; ?>

                        <!-- Sticker statut "Actif" en haut à gauche -->
                        <?php if ($isActive): ?>
                            <span class="product-card__sticker product-card__sticker--active" title="Produit en ligne">● Actif</span>
                        <?php else: ?>
                            <span class="product-card__sticker product-card__sticker--inactive" title="Produit hors ligne">○ Inactif</span>
                        <?php endif; ?>
                    </div>
                    <div class="product-card__body">
                        <div class="product-card__name"><?= Renderer::escape((string) $p['name']) ?></div>
                        <?php
                            // Stats avis stockées au sync : reviews_count NULL si pas encore sync, 0 si sync mais 0 avis.
                            $reviewsCount = $p['reviews_count'] !== null ? (int) $p['reviews_count'] : null;
                            $reviewsAvg = $p['reviews_avg'] !== null ? (float) $p['reviews_avg'] : null;
                            $hasReviewsSync = $p['reviews_synced_at'] !== null;
                        ?>
                        <?php if ($hasReviewsSync && $reviewsCount !== null && $reviewsCount > 0): ?>
                            <div class="product-card__ref product-card__reviews" title="<?= $reviewsCount ?> avis · note moyenne <?= number_format((float) $reviewsAvg, 2, ',', ' ') ?>/5">
                                <?= $reviewsCount ?> avis · ★ <?= number_format((float) $reviewsAvg, 1, ',', ' ') ?>
                            </div>
                        <?php elseif ($hasReviewsSync): ?>
                            <div class="product-card__ref" style="color:var(--color-text-muted);font-style:italic;">Aucun avis</div>
                        <?php else: ?>
                            <div class="product-card__ref"><?= Renderer::escape((string) ($p['reference'] ?? '')) ?></div>
                        <?php endif; ?>
                        <?php if (!empty($p['supplier_reference'])): ?>
                            <div class="product-card__supplier-ref" title="Référence fournisseur">
                                🏭 <?= Renderer::escape((string) $p['supplier_reference']) ?>
                            </div>
                        <?php endif; ?>
                        <div class="product-card__footer">
                            <span class="product-card__price"><?= number_format($priceTTC, 2, ',', ' ') ?> € TTC</span>
                            <span class="badge badge--<?= Renderer::escape($badge['color']) ?>">
                                <?= Renderer::escape($badge['icon']) ?> <?= Renderer::escape($badge['label']) ?>
                            </span>
                        </div>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>

        <?php if ($total_pages > 1): ?>
            <nav class="pagination">
                <a href="<?= Renderer::escape($buildUrl(newPage: max(1, $page - 1))) ?>"
                   class="btn btn--secondary btn--sm <?= $page === 1 ? 'is-disabled' : '' ?>">
                    ← Précédent
                </a>
                <span class="pagination__info">Page <?= $page ?> / <?= $total_pages ?> · <?= $total_filtered ?> résultats</span>
                <a href="<?= Renderer::escape($buildUrl(newPage: min($total_pages, $page + 1))) ?>"
                   class="btn btn--secondary btn--sm <?= $page === $total_pages ? 'is-disabled' : '' ?>">
                    Suivant →
                </a>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
<?php endif; ?>
