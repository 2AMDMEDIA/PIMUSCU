<?php
use App\Helpers\Renderer;

/**
 * @var ?int $supplier_id
 * @var ?string $control_error
 * @var list<array{id:?string, presta_id:int, name:string, reference:string, supplier_reference:string, brand:string, nb_combinations:int}> $supplier_ref_misplaced
 * @var list<array{presta_product_id:int, presta_combination_id:int, reference:?string, supplier_reference:?string, attributes_label:?string, option_value_ids:?string, product_uuid:?string, product_name:?string, product_reference:?string, brand:?string}> $multi_attr_combinations
 * @var list<array{presta_product_id:int, presta_combination_id:int, reference:?string, supplier_reference:?string, attributes_label:?string, option_value_ids:?string, product_uuid:?string, product_name:?string, product_reference:?string, brand:?string}> $single_combo_products
 * @var list<string> $sql_queue
 * @var int $tab
 * @var int $page
 * @var int $per_page
 * @var int $total1
 * @var int $total2
 * @var int $total3
 * @var int $distinct_products2
 * @var int $total_pages
 * @var string $search
 * @var string $brand
 * @var string $active
 * @var list<string> $brands1
 * @var list<string> $brands2
 * @var list<string> $brands3
 */
$sqlCount = count($sql_queue);
$csrf = \App\Helpers\Csrf::token();
$activeBrands = match ($tab) { 2 => $brands2, 3 => $brands3, default => $brands1 };
$activeTotal = match ($tab) { 2 => $total2, 3 => $total3, default => $total1 };

// Construit une URL /controle en surchargeant des params (tab/page/q/brand/active).
$url = function (array $ov = []) use ($tab, $page, $search, $brand, $active): string {
    $t = $ov['tab'] ?? $tab;
    $pg = $ov['page'] ?? $page;
    $q = array_key_exists('q', $ov) ? $ov['q'] : $search;
    $b = array_key_exists('brand', $ov) ? $ov['brand'] : $brand;
    $a = array_key_exists('active', $ov) ? $ov['active'] : $active;
    $p = [];
    if ((int) $t > 1) $p['tab'] = (string) (int) $t;
    if ((int) $pg > 1) $p['page'] = (int) $pg;
    if ($q !== '') $p['q'] = $q;
    if ($b !== '') $p['brand'] = $b;
    if ($a === '0' || $a === '1') $p['active'] = $a;
    return '/controle' . ($p !== [] ? '?' . http_build_query($p) : '');
};
// Hidden fields de contexte pour les POST (redirectBack côté contrôleur).
$ctx = function () use ($tab, $page, $search, $brand, $active): string {
    $h = '<input type="hidden" name="tab" value="' . (int) $tab . '">';
    if ($page > 1) $h .= '<input type="hidden" name="page" value="' . (int) $page . '">';
    if ($search !== '') $h .= '<input type="hidden" name="q" value="' . Renderer::escape($search) . '">';
    if ($brand !== '') $h .= '<input type="hidden" name="brand" value="' . Renderer::escape($brand) . '">';
    if ($active === '0' || $active === '1') $h .= '<input type="hidden" name="active" value="' . $active . '">';
    return $h;
};
?>
<div class="page-fullwidth">
<div class="page-header">
    <div>
        <h2 class="page-header__title">Contrôle</h2>
        <p class="page-header__subtitle">Contrôles qualité au niveau des produits / déclinaisons.</p>
    </div>
</div>

<div class="controle-shell">
    <div class="controle-content">
        <div class="controle-tabs" role="tablist">
            <a href="<?= Renderer::escape($url(['tab' => 1, 'page' => 1, 'q' => '', 'brand' => '', 'active' => ''])) ?>"
               class="controle-tab <?= $tab === 1 ? 'is-active' : '' ?>">
                🏭 Réf fournisseur mal placée
                <?php if ($tab === 1): ?><span class="badge <?= $total1 > 0 ? 'badge--amber' : 'badge--green' ?>"><?= $total1 ?></span><?php endif; ?>
            </a>
            <a href="<?= Renderer::escape($url(['tab' => 2, 'page' => 1, 'q' => '', 'brand' => '', 'active' => ''])) ?>"
               class="controle-tab <?= $tab === 2 ? 'is-active' : '' ?>">
                🧬 Déclinaisons à 2 attributs ou +
                <span class="badge <?= $total2 > 0 ? 'badge--blue' : 'badge--green' ?>" title="Nombre de déclinaisons (lignes)"><?= $total2 ?> décli.</span>
                <span class="badge badge--gray" title="Nombre de produits distincts concernés"><?= $distinct_products2 ?> prod.</span>
            </a>
            <a href="<?= Renderer::escape($url(['tab' => 3, 'page' => 1, 'q' => '', 'brand' => '', 'active' => ''])) ?>"
               class="controle-tab <?= $tab === 3 ? 'is-active' : '' ?>">
                ⚠️ Produits à 1 seule déclinaison
                <span class="badge <?= $total3 > 0 ? 'badge--amber' : 'badge--green' ?>"><?= $total3 ?></span>
            </a>
        </div>

        <?php /* ----- Barre de filtre (commune, scope = onglet actif) ----- */ ?>
        <form method="GET" action="/controle" style="display:flex; gap:8px; align-items:center; margin-bottom:16px; flex-wrap:wrap;">
            <input type="hidden" name="tab" value="<?= (int) $tab ?>">
            <input type="search" name="q" value="<?= Renderer::escape($search) ?>"
                   placeholder="Rechercher (nom produit, réf, réf fournisseur)…"
                   style="flex:1; min-width:240px; max-width:420px; padding:6px 10px; border:1px solid var(--color-border); border-radius:var(--radius); font-size:13px;">
            <select name="brand" onchange="this.form.submit()"
                    style="padding:6px 10px; border:1px solid var(--color-border); border-radius:var(--radius); font-size:13px; background:var(--color-surface); min-width:180px; max-width:260px;">
                <option value="">— Toutes marques (<?= count($activeBrands) ?>) —</option>
                <?php foreach ($activeBrands as $b): ?>
                    <option value="<?= Renderer::escape($b) ?>" <?= $brand === $b ? 'selected' : '' ?>><?= Renderer::escape($b) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="active" onchange="this.form.submit()"
                    style="padding:6px 10px; border:1px solid var(--color-border); border-radius:var(--radius); font-size:13px; background:var(--color-surface);" title="Statut du produit">
                <option value="" <?= $active === '' ? 'selected' : '' ?>>— Tous statuts —</option>
                <option value="1" <?= $active === '1' ? 'selected' : '' ?>>✓ Actifs</option>
                <option value="0" <?= $active === '0' ? 'selected' : '' ?>>✕ Inactifs</option>
            </select>
            <button type="submit" class="btn btn--secondary btn--sm">Rechercher</button>
            <?php if ($search !== '' || $brand !== '' || $active !== ''): ?>
                <a href="<?= Renderer::escape($url(['page' => 1, 'q' => '', 'brand' => '', 'active' => ''])) ?>" class="btn btn--ghost btn--sm">✕ Effacer</a>
            <?php endif; ?>
            <span style="margin-left:auto; font-size:12px; color:var(--color-text-muted);">
                <?= $activeTotal ?> résultat<?= $activeTotal > 1 ? 's' : '' ?>
            </span>
        </form>

        <?php /* ====================== ONGLET 1 ====================== */ ?>
        <?php if ($tab === 1): ?>
        <section class="controle-panel is-active">
            <div class="card">
                <div class="card__body">
                    <p style="margin:0 0 14px; font-size:13px; color:var(--color-text-muted);">
                        Produits qui ont des déclinaisons et une référence fournisseur
                        <?= $supplier_id ? '(fournisseur #' . (int) $supplier_id . ') ' : '' ?>
                        définie au niveau <strong>produit</strong>. Sur un produit à déclinaisons, la réf
                        fournisseur devrait être portée par les déclinaisons, pas par le produit racine.
                    </p>

                    <?php if ($supplier_id === null || $supplier_id <= 0): ?>
                        <div style="padding:10px 12px; background:#fef3c7; border:1px solid #fcd34d; border-radius:var(--radius); font-size:13px;">
                            ⚠ Aucun fournisseur configuré. Renseigne l'<strong>ID Fournisseur</strong> dans
                            <a href="/settings?tab=prestashop">Paramètres → PrestaShop</a> puis lance <a href="/produits">Produits → Synchroniser</a>.
                        </div>
                    <?php elseif ($control_error !== null): ?>
                        <div style="padding:10px 12px; background:#fef2f2; border:1px solid #fecaca; border-radius:var(--radius); font-size:13px; color:#991b1b;">
                            ❌ Erreur lors de l'appel PrestaShop : <?= Renderer::escape($control_error) ?>
                        </div>
                    <?php elseif ($total1 === 0): ?>
                        <div style="padding:10px 12px; background:#f0fdf4; border:1px solid #86efac; border-radius:var(--radius); font-size:13px; color:#166534;">
                            ✓ Aucune anomalie (ou aucun résultat pour ce filtre). Pense à lancer <a href="/produits">Produits → Synchroniser</a>.
                        </div>
                    <?php else: ?>
                        <div style="overflow-x:auto;">
                            <table class="controle-table">
                                <thead>
                                    <tr>
                                        <th>Produit</th>
                                        <th>Marque</th>
                                        <th>ID Presta</th>
                                        <th>Réf produit</th>
                                        <th>Réf fournisseur (produit)</th>
                                        <th class="controle-table__num">Nb décli.</th>
                                        <th style="width:130px;"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($supplier_ref_misplaced as $p): ?>
                                        <tr>
                                            <td>
                                                <?php if (!empty($p['id'])): ?>
                                                    <a href="/produits/<?= urlencode($p['id']) ?>"><?= Renderer::escape($p['name']) ?></a>
                                                <?php else: ?>
                                                    <?= Renderer::escape($p['name']) ?> <span style="font-size:11px; color:var(--color-text-muted);">(pas en cache — sync produits)</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?= $p['brand'] !== '' ? Renderer::escape($p['brand']) : '<span style="color:var(--color-text-muted);">—</span>' ?></td>
                                            <td><code>P#<?= (int) $p['presta_id'] ?></code></td>
                                            <td><?= $p['reference'] !== '' ? '<code>' . Renderer::escape($p['reference']) . '</code>' : '<span style="color:var(--color-text-muted);">—</span>' ?></td>
                                            <td><code><?= Renderer::escape($p['supplier_reference']) ?></code></td>
                                            <td class="controle-table__num"><?= (int) $p['nb_combinations'] ?></td>
                                            <td style="text-align:right;">
                                                <form method="POST" action="/controle/fix-supplier-ref" style="margin:0;">
                                                    <input type="hidden" name="_csrf" value="<?= Renderer::escape($csrf) ?>">
                                                    <?= $ctx() ?>
                                                    <input type="hidden" name="presta_product_id" value="<?= (int) $p['presta_id'] ?>">
                                                    <button type="submit" class="btn btn--secondary btn--sm" title="Empile une requête SQL DELETE dans le bloc de droite (ne modifie rien en base)">➕ Générer SQL</button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </section>
        <?php endif; ?>

        <?php /* ====================== ONGLET 2 ====================== */ ?>
        <?php if ($tab === 2): ?>
        <section class="controle-panel is-active">
            <div class="card">
                <div class="card__body">
                    <p style="margin:0 0 14px; font-size:13px; color:var(--color-text-muted);">
                        Déclinaisons combinant plusieurs attributs (ex. <em>taille</em> + <em>saveur</em>).
                        La croix <span class="controle-x" style="position:static; display:inline-flex;">✕</span> à côté d'un attribut
                        empile un <code>DELETE</code> sur <code>ps_product_attribute_combination</code> (retire ce lien
                        attribut↔décli) dans le bloc de droite — <strong>rien n'est exécuté en base</strong>.
                        Source : cache local (lance <a href="/produits">Produits → Synchroniser</a> pour rafraîchir).
                    </p>

                    <?php if ($total2 === 0): ?>
                        <div style="padding:10px 12px; background:#f0fdf4; border:1px solid #86efac; border-radius:var(--radius); font-size:13px; color:#166534;">
                            ✓ Aucune déclinaison multi-attributs (ou aucun résultat pour ce filtre — pense à synchroniser les produits).
                        </div>
                    <?php else: ?>
                        <div style="overflow-x:auto;">
                            <table class="controle-table">
                                <thead>
                                    <tr>
                                        <th>Produit</th>
                                        <th>Marque</th>
                                        <th>ID Presta</th>
                                        <th>ID Décli</th>
                                        <th title="Chaque attribut avec son id_product_option_value (= id_attribute). La croix empile un DELETE.">Attributs (id)</th>
                                        <th>Réf décli</th>
                                        <th>Réf fournisseur</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($multi_attr_combinations as $c):
                                        $comboId = (int) $c['presta_combination_id'];
                                        $attrs = (string) ($c['attributes_label'] ?? '');
                                        $labelParts = $attrs === '' ? [] : array_values(array_filter(array_map('trim', explode(' · ', $attrs)), fn($s) => $s !== ''));
                                        $nbAttrs = count($labelParts);
                                        $ovRaw = (string) ($c['option_value_ids'] ?? '');
                                        $ovIds = $ovRaw === '' ? [] : array_values(array_filter(array_map('trim', explode(',', $ovRaw)), fn($s) => $s !== ''));
                                        $cBrand = (string) ($c['brand'] ?? '');
                                    ?>
                                        <tr>
                                            <td>
                                                <?php if (!empty($c['product_uuid'])): ?>
                                                    <a href="/produits/<?= urlencode((string) $c['product_uuid']) ?>"><?= Renderer::escape((string) ($c['product_name'] ?? ('Produit #' . (int) $c['presta_product_id']))) ?></a>
                                                <?php else: ?>
                                                    <?= Renderer::escape((string) ($c['product_name'] ?? ('Produit #' . (int) $c['presta_product_id']))) ?>
                                                    <span style="font-size:11px; color:var(--color-text-muted);">(pas en cache — sync produits)</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?= $cBrand !== '' ? Renderer::escape($cBrand) : '<span style="color:var(--color-text-muted);">—</span>' ?></td>
                                            <td><code>P#<?= (int) $c['presta_product_id'] ?></code></td>
                                            <td><code>D#<?= $comboId ?></code></td>
                                            <td>
                                                <?php if ($labelParts === []): ?>
                                                    <span style="color:var(--color-text-muted);">—</span>
                                                <?php else: ?>
                                                    <span class="controle-attrs">
                                                    <?php foreach ($labelParts as $i => $lbl):
                                                        $idAttr = $ovIds[$i] ?? null;
                                                    ?>
                                                        <span class="controle-attr">
                                                            <?= Renderer::escape($lbl) ?><?php if ($idAttr !== null): ?> <span style="color:var(--color-text-muted); font-size:11px;">(id: <code><?= Renderer::escape($idAttr) ?></code>)</span><?php endif; ?>
                                                            <?php if ($idAttr !== null && (int) $idAttr > 0 && $comboId > 0): ?>
                                                                <form method="POST" action="/controle/fix-combination-attribute" style="display:inline; margin:0;"
                                                                      title="Empile un DELETE pour retirer l'attribut #<?= (int) $idAttr ?> de la décli #<?= $comboId ?>">
                                                                    <input type="hidden" name="_csrf" value="<?= Renderer::escape($csrf) ?>">
                                                                    <?= $ctx() ?>
                                                                    <input type="hidden" name="id_product_attribute" value="<?= $comboId ?>">
                                                                    <input type="hidden" name="id_attribute" value="<?= (int) $idAttr ?>">
                                                                    <button type="submit" class="controle-x">✕</button>
                                                                </form>
                                                            <?php endif; ?>
                                                        </span><?php if ($i < $nbAttrs - 1): ?> <span style="color:var(--color-text-muted);">·</span> <?php endif; ?>
                                                    <?php endforeach; ?>
                                                    </span>
                                                    <span class="badge badge--gray" style="margin-left:6px;"><?= $nbAttrs ?> attr.</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?= !empty($c['reference']) ? '<code style="font-size:11px;">' . Renderer::escape((string) $c['reference']) . '</code>' : '<span style="color:var(--color-text-muted);">—</span>' ?></td>
                                            <td><?= !empty($c['supplier_reference']) ? '<code style="font-size:11px;">' . Renderer::escape((string) $c['supplier_reference']) . '</code>' : '<span style="color:var(--color-text-muted);">—</span>' ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </section>
        <?php endif; ?>

        <?php /* ====================== ONGLET 3 ====================== */ ?>
        <?php if ($tab === 3): ?>
        <section class="controle-panel is-active">
            <div class="card">
                <div class="card__body">
                    <p style="margin:0 0 14px; font-size:13px; color:var(--color-text-muted);">
                        Produits qui n'ont qu'<strong>une seule déclinaison</strong>. C'est souvent une anomalie :
                        soit le produit devrait être simple (sans déclinaison), soit il manque les autres déclinaisons.
                        Source : cache local (lance <a href="/produits">Produits → Synchroniser</a> pour rafraîchir).
                    </p>

                    <?php if ($total3 === 0): ?>
                        <div style="padding:10px 12px; background:#f0fdf4; border:1px solid #86efac; border-radius:var(--radius); font-size:13px; color:#166534;">
                            ✓ Aucun produit à déclinaison unique (ou aucun résultat pour ce filtre — pense à synchroniser les produits).
                        </div>
                    <?php else: ?>
                        <div style="overflow-x:auto;">
                            <table class="controle-table">
                                <thead>
                                    <tr>
                                        <th>Produit</th>
                                        <th>Marque</th>
                                        <th>ID Presta</th>
                                        <th>ID Décli</th>
                                        <th>Attributs</th>
                                        <th>Réf décli</th>
                                        <th>Réf fournisseur</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($single_combo_products as $c):
                                        $comboId = (int) $c['presta_combination_id'];
                                        $attrs = (string) ($c['attributes_label'] ?? '');
                                        $cBrand = (string) ($c['brand'] ?? '');
                                    ?>
                                        <tr>
                                            <td>
                                                <?php if (!empty($c['product_uuid'])): ?>
                                                    <a href="/produits/<?= urlencode((string) $c['product_uuid']) ?>"><?= Renderer::escape((string) ($c['product_name'] ?? ('Produit #' . (int) $c['presta_product_id']))) ?></a>
                                                <?php else: ?>
                                                    <?= Renderer::escape((string) ($c['product_name'] ?? ('Produit #' . (int) $c['presta_product_id']))) ?>
                                                    <span style="font-size:11px; color:var(--color-text-muted);">(pas en cache — sync produits)</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?= $cBrand !== '' ? Renderer::escape($cBrand) : '<span style="color:var(--color-text-muted);">—</span>' ?></td>
                                            <td><code>P#<?= (int) $c['presta_product_id'] ?></code></td>
                                            <td><code>D#<?= $comboId ?></code></td>
                                            <td><?= $attrs !== '' ? Renderer::escape($attrs) : '<span style="color:var(--color-text-muted);">—</span>' ?></td>
                                            <td><?= !empty($c['reference']) ? '<code style="font-size:11px;">' . Renderer::escape((string) $c['reference']) . '</code>' : '<span style="color:var(--color-text-muted);">—</span>' ?></td>
                                            <td><?= !empty($c['supplier_reference']) ? '<code style="font-size:11px;">' . Renderer::escape((string) $c['supplier_reference']) . '</code>' : '<span style="color:var(--color-text-muted);">—</span>' ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </section>
        <?php endif; ?>

        <?php /* ----- Pagination (onglet actif) ----- */ ?>
        <?php if ($total_pages > 1): ?>
            <div class="controle-pager">
                <?php if ($page > 1): ?>
                    <a href="<?= Renderer::escape($url(['page' => 1])) ?>" class="btn btn--ghost btn--sm">« 1</a>
                    <a href="<?= Renderer::escape($url(['page' => $page - 1])) ?>" class="btn btn--secondary btn--sm">‹ Précédent</a>
                <?php endif; ?>
                <span style="font-size:13px; color:var(--color-text-muted); padding:0 8px;">
                    Page <?= $page ?> / <?= $total_pages ?>
                </span>
                <?php if ($page < $total_pages): ?>
                    <a href="<?= Renderer::escape($url(['page' => $page + 1])) ?>" class="btn btn--secondary btn--sm">Suivant ›</a>
                    <a href="<?= Renderer::escape($url(['page' => $total_pages])) ?>" class="btn btn--ghost btn--sm"><?= $total_pages ?> »</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <?php /* ============ Bloc latéral PARTAGÉ : requêtes SQL à jouer manuellement ============ */ ?>
    <aside class="controle-sql">
        <div class="card">
            <div class="card__header" style="display:flex; justify-content:space-between; align-items:center; gap:8px;">
                <h3 class="card__title" style="font-size:14px;">
                    🗃️ Requêtes SQL à jouer
                    <span class="badge <?= $sqlCount > 0 ? 'badge--amber' : 'badge--green' ?>" style="margin-left:6px;"><?= $sqlCount ?></span>
                </h3>
                <?php if ($sqlCount > 0): ?>
                    <form method="POST" action="/controle/clear-sql" style="margin:0;"
                          onsubmit="return confirm('Vider la liste des requêtes SQL en attente ?');">
                        <input type="hidden" name="_csrf" value="<?= Renderer::escape($csrf) ?>">
                        <?= $ctx() ?>
                        <button type="submit" class="btn btn--secondary btn--sm">🗑️ Vider</button>
                    </form>
                <?php endif; ?>
            </div>
            <div class="card__body">
                <?php if ($sqlCount === 0): ?>
                    <p style="margin:0; font-size:13px; color:var(--color-text-muted);">
                        Utilise <strong>« Générer SQL »</strong> (onglet 1) ou la croix <strong>✕</strong> (onglet 2)
                        pour empiler ici les requêtes <code>DELETE</code>. Tu les copieras ensuite pour les jouer
                        toi-même en base.
                    </p>
                <?php else: ?>
                    <p style="margin:0 0 10px; font-size:12px; color:var(--color-text-muted);">
                        Copie ces requêtes et joue-les en base (phpMyAdmin). ⚠ Vérifie le préfixe de table
                        (<code>ps_</code> par défaut) avant exécution.
                    </p>
                    <textarea id="sqlQueue" readonly rows="<?= max(4, min(24, $sqlCount + 1)) ?>"
                              style="width:100%; box-sizing:border-box; font-family:ui-monospace,Menlo,Consolas,monospace; font-size:12px; line-height:1.5; padding:10px; border:1px solid var(--color-border); border-radius:var(--radius); background:#0f172a; color:#e2e8f0; resize:vertical; white-space:pre;"><?= Renderer::escape(implode("\n", $sql_queue)) ?></textarea>
                    <div style="display:flex; gap:8px; margin-top:10px;">
                        <button type="button" class="btn btn--primary btn--sm" onclick="copyControleSql(this)" style="flex:1;">📋 Copier tout</button>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </aside>
</div>
</div>

<script>
function copyControleSql(btn) {
    var ta = document.getElementById('sqlQueue');
    if (!ta) return;
    ta.select();
    ta.setSelectionRange(0, 99999);
    var done = function () {
        var old = btn.textContent;
        btn.textContent = '✓ Copié';
        setTimeout(function () { btn.textContent = old; }, 1500);
    };
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(ta.value).then(done, function () { document.execCommand('copy'); done(); });
    } else {
        document.execCommand('copy');
        done();
    }
}
</script>

<style>
.controle-shell { display:flex; gap:20px; align-items:flex-start; }
.controle-content { flex:1; min-width:0; }
.controle-sql { width:400px; flex-shrink:0; position:sticky; top:20px; }
@media (max-width: 1100px) {
    .controle-shell { flex-direction:column; }
    .controle-sql { width:100%; position:static; }
}

.controle-tabs { display:flex; gap:4px; border-bottom:2px solid var(--color-border); margin-bottom:16px; flex-wrap:wrap; }
.controle-tab {
    text-decoration:none;
    padding:10px 16px; font-size:14px; font-weight:600; color:var(--color-text-muted);
    border-bottom:2px solid transparent; margin-bottom:-2px; display:inline-flex; align-items:center; gap:6px;
}
.controle-tab:hover { color:var(--color-text); }
.controle-tab.is-active { color:var(--color-primary, #2563eb); border-bottom-color:var(--color-primary, #2563eb); }

.controle-table { width:100%; border-collapse:collapse; font-size:13px; }
.controle-table th, .controle-table td { padding:8px 12px; text-align:left; border-bottom:1px solid var(--color-border); }
.controle-table thead th { background:var(--color-bg); font-weight:600; font-size:11px; text-transform:uppercase; letter-spacing:0.04em; color:var(--color-text-muted); }
.controle-table tbody tr:hover { background:var(--color-bg); }
.controle-table__num { text-align:right; font-variant-numeric:tabular-nums; }

.controle-attrs { display:inline; }
.controle-attr { white-space:nowrap; }
.controle-x {
    appearance:none; border:none; background:#fee2e2; color:#991b1b; cursor:pointer;
    width:16px; height:16px; line-height:14px; border-radius:50%; font-size:10px; font-weight:700;
    display:inline-flex; align-items:center; justify-content:center; vertical-align:middle; margin-left:2px; padding:0;
}
.controle-x:hover { background:#fecaca; }

.controle-pager { display:flex; gap:6px; align-items:center; justify-content:center; margin-top:16px; flex-wrap:wrap; }
</style>
