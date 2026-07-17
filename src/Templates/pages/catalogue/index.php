<?php
use App\Helpers\Renderer;

/**
 * @var bool $configured
 * @var ?string $config_message
 * @var list<array{
 *     sku:string, name:string, brand:string, barcode:string,
 *     size:?string, color:?string, flavor:?string, stock:?int,
 *     price_base:?float, price_selling:?float, price_retail:?float,
 *     permalink:?string,
 *     match:?array{type:string, product_uuid:?string, presta_id:int, presta_combination_id?:int, attributes:?string, reference?:string},
 * }> $rows
 * @var ?string $error
 * @var string $filter
 * @var string $search
 * @var string $brand
 * @var list<string> $brands
 * @var string $sort
 * @var string $dir
 * @var ?string $last_synced_at
 * @var array{configured_url:string, product_info_url:string, key_set:bool, key_length:int, url_has_akey:bool, url_has_fields:bool, full_url_masked:string} $debug_info
 * @var array{total:int, linked:int, unlinked:int, filtered:int} $stats
 */
$csrf = \App\Helpers\Csrf::token();
$lastSyncedHuman = $last_synced_at !== null ? date('d/m/Y H:i', strtotime($last_synced_at)) : null;
$fmtPrice = fn(?float $v): string => $v === null ? '—' : number_format($v, 2, ',', ' ') . ' €';
$fmtText = fn(?string $v): string => $v === null || $v === '' ? '—' : Renderer::escape($v);

// Preserve filter/q/brand/sort/dir
$buildUrl = function (?string $newFilter = null, ?string $newSearch = null, ?string $newSort = null, ?string $newDir = null, ?string $newBrand = null) use ($filter, $search, $brand, $sort, $dir): string {
    $q = [];
    $f = $newFilter ?? $filter;
    $s = $newSearch ?? $search;
    $b = $newBrand ?? $brand;
    $so = $newSort ?? $sort;
    $di = $newDir ?? $dir;
    if ($f !== 'all') $q['filter'] = $f;
    if ($s !== '') $q['q'] = $s;
    if ($b !== '') $q['brand'] = $b;
    if ($so !== '') {
        $q['sort'] = $so;
        if ($di === 'desc') $q['dir'] = 'desc';
    }
    return '/catalogue' . ($q !== [] ? '?' . http_build_query($q) : '');
};

// Construit l'URL d'un toggle de header de tri : 3 etats (none -> asc -> desc -> none)
$sortHref = function (string $col) use ($sort, $dir, $buildUrl): string {
    if ($sort !== $col) {
        return $buildUrl(newSort: $col, newDir: 'asc');
    }
    if ($dir === 'asc') {
        return $buildUrl(newSort: $col, newDir: 'desc');
    }
    return $buildUrl(newSort: '', newDir: 'asc');
};
$sortArrow = fn(string $col): string => $sort !== $col ? '<span style="opacity:0.3;">↕</span>' : ($dir === 'asc' ? '↑' : '↓');
?>
<div class="catalogue-fullwidth">
<div class="page-header" style="display:flex; justify-content:space-between; align-items:flex-start; gap:12px;">
    <div>
        <h2 class="page-header__title">Catalogue Nutriweb</h2>
        <?php if ($configured && $error === null): ?>
            <p class="page-header__subtitle">
                <?= $stats['total'] ?> SKU<?= $stats['total'] > 1 ? 's' : '' ?> ·
                <strong style="color:#16a34a;"><?= $stats['linked'] ?> lié<?= $stats['linked'] > 1 ? 's' : '' ?></strong> ·
                <strong style="color:#dc2626;"><?= $stats['unlinked'] ?> non lié<?= $stats['unlinked'] > 1 ? 's' : '' ?></strong>
                <?php if ($search !== '' || $filter !== 'all'): ?>
                    · <em><?= $stats['filtered'] ?> r&eacute;sultat<?= $stats['filtered'] > 1 ? 's' : '' ?> apr&egrave;s filtre</em>
                <?php endif; ?>
            </p>
            <p class="page-header__subtitle" style="font-size:12px;">
                <?php if ($lastSyncedHuman !== null): ?>
                    🕒 Dernière sync : <?= Renderer::escape($lastSyncedHuman) ?>
                <?php else: ?>
                    <em style="color:#dc2626;">⚠ Jamais synchronisé. Clique sur Synchroniser pour charger le catalogue.</em>
                <?php endif; ?>
            </p>
        <?php endif; ?>
    </div>
    <?php if ($configured): ?>
        <form method="POST" action="/catalogue/sync" style="margin:0;">
            <input type="hidden" name="_csrf" value="<?= Renderer::escape($csrf) ?>">
            <button type="submit" class="btn btn--primary">🔄 Synchroniser</button>
        </form>
    <?php endif; ?>
</div>

<details style="margin-bottom:16px; padding:8px 12px; background:#f9fafb; border:1px solid var(--color-border); border-radius:var(--radius); font-size:12px;">
    <summary style="cursor:pointer; font-weight:600; color:var(--color-text-muted);">🐛 Debug Nutriweb (config + URL appelée)</summary>
    <dl style="display:grid; grid-template-columns:220px 1fr; gap:6px 14px; margin:10px 0 0;">
        <dt><strong>URL catalogue (Settings)</strong></dt>
        <dd><?= $debug_info['configured_url'] !== '' ? '<code>' . Renderer::escape($debug_info['configured_url']) . '</code>' : '<em style="color:#dc2626;">⚠ Non configurée</em>' ?></dd>

        <dt><strong>URL infos produit</strong></dt>
        <dd><?= $debug_info['product_info_url'] !== '' ? '<code>' . Renderer::escape($debug_info['product_info_url']) . '</code>' : '<em style="color:var(--color-text-muted);">— (optionnel)</em>' ?></dd>

        <dt><strong>Clé privée</strong></dt>
        <dd>
            <?php if ($debug_info['url_has_akey']): ?>
                <span style="color:#16a34a;">✓ Présente dans l'URL (param <code>akey=</code>)</span>
            <?php elseif ($debug_info['key_set']): ?>
                <span style="color:#16a34a;">✓ Champ dédié configuré</span>
                <span style="color:var(--color-text-muted);"> · longueur chiffrée: <?= $debug_info['key_length'] ?> octets</span>
            <?php else: ?>
                <em style="color:#dc2626;">⚠ Non configurée (ni dans l'URL, ni dans le champ dédié)</em>
            <?php endif; ?>
        </dd>

        <dt><strong>Param <code>fields=</code></strong></dt>
        <dd>
            <?php if ($debug_info['url_has_fields']): ?>
                <span style="color:#16a34a;">✓ Déjà dans l'URL — utilisé tel quel</span>
            <?php else: ?>
                <span style="color:var(--color-text-muted);">Ajouté automatiquement par le client (sku,name,brand,price,barcode,size,color,flavor,image,purchase_price,stock)</span>
            <?php endif; ?>
        </dd>

        <dt><strong>URL complète qui sera appelée</strong></dt>
        <dd>
            <?php if ($debug_info['full_url_masked'] !== ''): ?>
                <code style="word-break:break-all; font-size:11px;"><?= Renderer::escape($debug_info['full_url_masked']) ?></code>
            <?php else: ?>
                <em style="color:var(--color-text-muted);">Configure d'abord URL + clé pour générer l'URL</em>
            <?php endif; ?>
        </dd>

        <dt><strong>Dernière sync</strong></dt>
        <dd><?= $lastSyncedHuman !== null ? Renderer::escape($lastSyncedHuman) : '<em style="color:#dc2626;">Jamais</em>' ?></dd>

        <dt><strong>Comptage en DB</strong></dt>
        <dd><?= $stats['total'] ?> SKUs · <?= $stats['linked'] ?> liés · <?= $stats['unlinked'] ?> non liés</dd>
    </dl>
    <p style="margin:10px 0 0; font-size:11px; color:var(--color-text-muted);">
        La clé est tronquée (6 premiers + 3 derniers chars) pour ne pas exposer le secret entier dans le navigateur.
        L'URL complète avec clé entière est loggée dans <code>error_log</code> serveur quand tu cliques Synchroniser.
        <a href="/settings?tab=nutriweb">→ Éditer Settings → Nutriweb</a>
    </p>
</details>

<?php if (!$configured): ?>
    <div class="card">
        <div class="card__body">
            <div class="empty-state">
                <div class="empty-state__title">⚙️ Configuration Nutriweb manquante</div>
                <div class="empty-state__hint">
                    <?= Renderer::escape($config_message ?? 'Renseignez la clé privée et les URLs.') ?>
                    <br>
                    <a href="/settings?tab=nutriweb" class="btn btn--primary btn--sm" style="margin-top:12px;">→ Paramètres → Nutriweb</a>
                </div>
            </div>
        </div>
    </div>
<?php elseif ($error !== null): ?>
    <div class="card" style="border-color:#dc2626;">
        <div class="card__body">
            <strong style="color:#dc2626;">❌ Erreur lors de l'appel au catalogue Nutriweb</strong>
            <pre style="white-space:pre-wrap; margin-top:8px; font-size:12px; background:#fef2f2; padding:10px; border-radius:6px;"><?= Renderer::escape($error) ?></pre>
        </div>
    </div>
<?php elseif ($stats['total'] === 0): ?>
    <div class="card">
        <div class="card__body">
            <div class="empty-state">
                <div class="empty-state__title">Catalogue vide</div>
                <div class="empty-state__hint">L'API Nutriweb n'a renvoyé aucun produit.</div>
            </div>
        </div>
    </div>
<?php else: ?>
    <form method="GET" action="/catalogue" class="produits-toolbar" style="display:flex; gap:8px; align-items:center; margin-bottom:12px; flex-wrap:wrap;">
        <input type="search" name="q" placeholder="Rechercher par nom, SKU ou code-barres..." value="<?= Renderer::escape($search) ?>"
               class="produits-search" style="flex:1; min-width:240px; max-width:480px; padding:6px 10px; border:1px solid var(--color-border); border-radius:var(--radius); font-size:13px;">

        <select name="brand" onchange="this.form.submit()" style="padding:6px 10px; border:1px solid var(--color-border); border-radius:var(--radius); font-size:13px; background:var(--color-surface); min-width:180px; max-width:280px;" title="Filtrer par marque">
            <option value="">— Toutes marques (<?= count($brands) ?>) —</option>
            <?php foreach ($brands as $b): ?>
                <option value="<?= Renderer::escape($b) ?>" <?= $brand === $b ? 'selected' : '' ?>><?= Renderer::escape($b) ?></option>
            <?php endforeach; ?>
        </select>

        <?php if ($filter !== 'all'): ?>
            <input type="hidden" name="filter" value="<?= Renderer::escape($filter) ?>">
        <?php endif; ?>
        <?php if ($sort !== ''): ?>
            <input type="hidden" name="sort" value="<?= Renderer::escape($sort) ?>">
            <?php if ($dir === 'desc'): ?>
                <input type="hidden" name="dir" value="desc">
            <?php endif; ?>
        <?php endif; ?>
        <button type="submit" class="btn btn--secondary btn--sm">Rechercher</button>
        <?php if ($search !== '' || $brand !== ''): ?>
            <a href="<?= Renderer::escape($buildUrl(newSearch: '', newBrand: '')) ?>" class="btn btn--ghost btn--sm">✕ Effacer</a>
        <?php endif; ?>
    </form>

    <div class="filter-pills" style="margin-bottom: 16px;">
        <a href="<?= Renderer::escape($buildUrl(newFilter: 'all')) ?>"
           class="filter-pill <?= $filter === 'all' ? 'filter-pill--active' : '' ?>">
            Tous (<?= $stats['total'] ?>)
        </a>
        <a href="<?= Renderer::escape($buildUrl(newFilter: 'linked')) ?>"
           class="filter-pill <?= $filter === 'linked' ? 'filter-pill--active' : '' ?>">
            ✓ Liés (<?= $stats['linked'] ?>)
        </a>
        <a href="<?= Renderer::escape($buildUrl(newFilter: 'unlinked')) ?>"
           class="filter-pill <?= $filter === 'unlinked' ? 'filter-pill--active' : '' ?>">
            ✗ Non liés (<?= $stats['unlinked'] ?>)
        </a>
    </div>

    <?php if (empty($rows)): ?>
        <div class="card">
            <div class="card__body">
                <div class="empty-state">
                    <div class="empty-state__title">Aucun SKU dans ce filtre</div>
                    <div class="empty-state__hint">Change de filtre ci-dessus.</div>
                </div>
            </div>
        </div>
    <?php else: ?>
    <div class="card">
        <div class="card__body" style="padding:0;">
            <div style="overflow-x:auto;">
                <table class="catalog-table">
                    <thead>
                        <tr>
                            <th style="width:60px;">Photo</th>
                            <th>SKU</th>
                            <th>
                                <a href="<?= Renderer::escape($sortHref('presta_id')) ?>" class="catalog-table__sort" title="Trier par ID Presta">
                                    ID Presta <?= $sortArrow('presta_id') ?>
                                </a>
                            </th>
                            <th>Réf Presta</th>
                            <th title="Référence fournisseur dans la fiche produit PrestaShop">Réf Fournisseur</th>
                            <th>Lien Presta</th>
                            <th>Code-barres</th>
                            <th>Produit</th>
                            <th>Marque</th>
                            <th>
                                <a href="<?= Renderer::escape($sortHref('size')) ?>" class="catalog-table__sort" title="Trier par taille">
                                    Taille <?= $sortArrow('size') ?>
                                </a>
                            </th>
                            <th>Couleur</th>
                            <th>
                                <a href="<?= Renderer::escape($sortHref('flavor')) ?>" class="catalog-table__sort" title="Trier par saveur">
                                    Saveur <?= $sortArrow('flavor') ?>
                                </a>
                            </th>
                            <th class="catalog-table__num" title="Stock fournisseur (Nutriweb)">Stock</th>
                            <th class="catalog-table__num">Base HT</th>
                            <th class="catalog-table__num">Achat HT</th>
                            <th class="catalog-table__num">Public TTC</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $r): ?>
                            <tr class="<?= $r['match'] !== null ? 'catalog-table__row--linked' : 'catalog-table__row--unlinked' ?>">
                                <td>
                                    <?php if (!empty($r['image_url'])): ?>
                                        <a href="<?= Renderer::escape($r['image_url']) ?>" target="_blank" rel="noopener" title="Voir en grand">
                                            <img data-src="<?= Renderer::escape($r['image_url']) ?>" src="data:image/svg+xml;utf8,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20viewBox%3D%220%200%201%201%22%2F%3E" alt="" loading="lazy" class="catalog-table__thumb lazyimg">
                                        </a>
                                    <?php else: ?>
                                        <span style="color:var(--color-text-muted); font-size:18px;">📷</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="/catalogue/sku/<?= urlencode((string) $r['sku']) ?>" target="_blank" rel="noopener" title="Voir la fiche détaillée du SKU (nouvel onglet)">
                                        <code><?= Renderer::escape($r['sku']) ?></code>
                                    </a>
                                </td>
                                <td>
                                    <?php if ($r['match'] !== null): ?>
                                        <code class="catalog-table__ids">
                                            <?= (int) $r['match']['presta_id'] ?><?php if (!empty($r['match']['presta_combination_id'])): ?> / <?= (int) $r['match']['presta_combination_id'] ?><?php endif; ?>
                                        </code>
                                    <?php else: ?>
                                        <span style="color:var(--color-text-muted);">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($r['match'] !== null && !empty($r['match']['reference'])): ?>
                                        <code style="font-size:11px;"><?= Renderer::escape((string) $r['match']['reference']) ?></code>
                                    <?php else: ?>
                                        <span style="color:var(--color-text-muted);">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($r['match'] !== null && !empty($r['match']['supplier_reference'])): ?>
                                        <code style="font-size:11px;"><?= Renderer::escape((string) $r['match']['supplier_reference']) ?></code>
                                    <?php else: ?>
                                        <span style="color:var(--color-text-muted);">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($r['match'] !== null):
                                        $m = $r['match'];
                                        $isCombo = $m['type'] === 'combination';
                                        $href = $m['product_uuid'] !== null ? '/produits/' . urlencode($m['product_uuid']) : null;
                                        $prodName = trim((string) ($m['product_name'] ?? ''));
                                        if ($isCombo) {
                                            // Nom produit Presta (fallback "D#xxx" si nom absent du cache)
                                            $base = $prodName !== '' ? $prodName : ('D#' . ($m['presta_combination_id'] ?? '?'));
                                            $attrs = $m['attributes'] !== null && $m['attributes'] !== '' ? ' (' . $m['attributes'] . ')' : '';
                                            $badge = $base . $attrs;
                                        } else {
                                            $badge = $prodName !== '' ? $prodName : ('P#' . $m['presta_id']);
                                        }
                                    ?>
                                        <?php if ($href !== null): ?>
                                            <a href="<?= Renderer::escape($href) ?>" class="catalog-link catalog-link--<?= $isCombo ? 'combo' : 'product' ?>" title="<?= Renderer::escape($isCombo ? 'Déclinaison' : 'Produit racine') ?>">
                                                <?= Renderer::escape($badge) ?>
                                            </a>
                                        <?php else: ?>
                                            <span class="catalog-link catalog-link--<?= $isCombo ? 'combo' : 'product' ?>" title="Parent produit non synchronisé">
                                                <?= Renderer::escape($badge) ?>
                                            </span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="catalog-link catalog-link--none">✗ Non lié</span>
                                        <a href="/catalogue/create?sku=<?= urlencode($r['sku']) ?>" class="catalog-link catalog-link--action" title="Créer ce SKU dans PrestaShop">+ Créer</a>
                                    <?php endif; ?>
                                </td>
                                <td><code><?= Renderer::escape($r['barcode']) ?></code></td>
                                <td><?= Renderer::escape($r['name']) ?></td>
                                <td><?= Renderer::escape($r['brand']) ?></td>
                                <td><?= $fmtText($r['size']) ?></td>
                                <td><?= $fmtText($r['color']) ?></td>
                                <td><?= $fmtText($r['flavor']) ?></td>
                                <td class="catalog-table__num">
                                    <?php
                                        $stk = $r['stock'] ?? null;
                                        if ($stk === null) {
                                            echo '<span style="color:var(--color-text-muted);">—</span>';
                                        } elseif ($stk <= 0) {
                                            echo '<span class="catalog-table__stock catalog-table__stock--out">' . (int) $stk . '</span>';
                                        } elseif ($stk < 5) {
                                            echo '<span class="catalog-table__stock catalog-table__stock--low">' . (int) $stk . '</span>';
                                        } else {
                                            echo '<span class="catalog-table__stock catalog-table__stock--ok">' . (int) $stk . '</span>';
                                        }
                                    ?>
                                </td>
                                <td class="catalog-table__num"><?= $fmtPrice($r['price_base']) ?></td>
                                <td class="catalog-table__num"><?= $fmtPrice($r['price_selling']) ?></td>
                                <td class="catalog-table__num"><?= $fmtPrice($r['price_retail']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <style>
    .catalog-table { width: 100%; border-collapse: collapse; font-size: 13px; }
    .catalog-table th, .catalog-table td { padding: 8px 12px; text-align: left; border-bottom: 1px solid var(--color-border); }
    .catalog-table thead th { background: var(--color-bg); font-weight: 600; font-size: 11px; text-transform: uppercase; letter-spacing: 0.04em; color: var(--color-text-muted); position: sticky; top: 0; }
    .catalog-table tbody tr:hover { background: var(--color-bg); }
    .catalog-table__num { text-align: right; font-variant-numeric: tabular-nums; }
    .catalog-table code { font-size: 12px; }
    .catalog-table__row--unlinked { background: #fef9f6; }
    .catalog-link { display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 11px; font-weight: 600; text-decoration: none; white-space: nowrap; }
    .catalog-link--product { background: #dcfce7; color: #166534; }
    .catalog-link--product:hover { background: #bbf7d0; }
    .catalog-link--combo { background: #dbeafe; color: #1e40af; }
    .catalog-link--combo:hover { background: #bfdbfe; }
    .catalog-link--none { background: #fee2e2; color: #991b1b; }
    .catalog-table__thumb { display:block; width:48px; height:48px; object-fit:cover; border-radius:4px; border:1px solid var(--color-border); background:#fff; }
    .lazyimg:not(.lazyimg--loaded) { background: repeating-linear-gradient(45deg, #f3f4f6, #f3f4f6 4px, #e5e7eb 4px, #e5e7eb 8px); }
    .lazyimg--loaded { transition: opacity .15s; }
    .catalog-table__ids { font-size:12px; color:var(--color-text); white-space:nowrap; }
    .catalog-link--action { background: #ede9fe; color: #5b21b6; text-decoration: none; margin-left: 4px; }
    .catalog-link--action:hover { background: #ddd6fe; }
    .catalog-table__sort { color: inherit; text-decoration: none; display: inline-block; }
    .catalog-table__sort:hover { color: var(--color-primary, #2563eb); }
    .catalog-table__stock { display: inline-block; min-width: 32px; padding: 1px 6px; border-radius: 4px; font-size: 12px; font-weight: 600; text-align: center; }
    .catalog-table__stock--ok { background: #dcfce7; color: #166534; }
    .catalog-table__stock--low { background: #fef3c7; color: #92400e; }
    .catalog-table__stock--out { background: #fee2e2; color: #991b1b; }
    </style>
    <script>
    // Lazy load image plus agressif que loading=lazy natif : IntersectionObserver
    // avec 200px d'anticipation. Swap data-src -> src au passage dans le viewport.
    (function () {
        var imgs = document.querySelectorAll('img.lazyimg');
        if (imgs.length === 0) return;
        if (!('IntersectionObserver' in window)) {
            // Fallback : si pas d'IO, swap direct (chargement immediat)
            imgs.forEach(function (img) { img.src = img.dataset.src; img.classList.add('lazyimg--loaded'); });
            return;
        }
        var io = new IntersectionObserver(function (entries) {
            entries.forEach(function (e) {
                if (!e.isIntersecting) return;
                var img = e.target;
                if (img.dataset.src) {
                    img.src = img.dataset.src;
                    img.removeAttribute('data-src');
                    img.addEventListener('load', function () { img.classList.add('lazyimg--loaded'); }, { once: true });
                }
                io.unobserve(img);
            });
        }, { rootMargin: '200px 0px', threshold: 0.01 });
        imgs.forEach(function (img) { io.observe(img); });
    })();
    </script>
    <?php endif; ?>
<?php endif; ?>
</div>

