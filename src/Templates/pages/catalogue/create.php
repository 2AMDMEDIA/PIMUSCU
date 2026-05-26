<?php
use App\Helpers\Renderer;
use App\Helpers\Csrf;

/**
 * @var array $row  Ligne Nutriweb du SKU
 * @var \App\Models\Client $client
 * @var list<array{id:int, name:string, values:list<array{id:int, label:string}>}> $attr_groups
 * @var list<int> $preselected_ids
 * @var list<array{id:int, name:string, depth:int, indented_name:string}> $categories_flat
 * @var list<array{id:int, name:string}> $manufacturers
 * @var list<array{id:int, name:string}> $tax_groups
 * @var int $preselected_manufacturer_id
 * @var list<array{sku:string, barcode:string, size:string, flavor:string, color:string, image_url:string, presta_product_id:int, presta_combination_id:int, is_current:bool, is_linked:bool, attrs_by_group:array<int, int>}> $siblings
 */
$hasSiblings = count($siblings) > 1;  // > 1 car le SKU courant est dedans aussi
$sibLabel = function (array $s): string {
    $parts = array_filter([$s['size'] ?? '', $s['flavor'] ?? '', $s['color'] ?? '']);
    return implode(' · ', array_map(fn($v) => trim((string) $v), $parts));
};
$fmtText = fn(?string $v): string => $v === null || $v === '' ? '<em style="color:var(--color-text-muted);">—</em>' : Renderer::escape($v);
$csrf = Csrf::token();

$defaultName = (string) ($row['name'] ?? '');
$defaultMetaTitle = $defaultName;
?>
<div class="catalogue-fullwidth">
<div class="page-header">
    <div>
        <h2 class="page-header__title">Créer dans PrestaShop</h2>
        <p class="page-header__subtitle">
            <a href="/catalogue" style="color:var(--color-text-muted);">← Catalogue Nutriweb</a>
        </p>
    </div>
</div>

<div class="card" style="margin-bottom: 16px;">
    <div class="card__header"><h3 class="card__title">Données Nutriweb du SKU</h3></div>
    <div class="card__body">
        <div style="display:flex; gap:20px; align-items:flex-start;">
            <?php if (!empty($row['image_url'])): ?>
                <a href="<?= Renderer::escape($row['image_url']) ?>" target="_blank" rel="noopener" title="Voir en grand" style="flex-shrink:0;">
                    <img src="<?= Renderer::escape($row['image_url']) ?>" alt="" style="width:180px; height:180px; object-fit:cover; border-radius:6px; border:1px solid var(--color-border); background:#fff;">
                </a>
            <?php else: ?>
                <div style="width:180px; height:180px; display:flex; align-items:center; justify-content:center; background:#f9fafb; border-radius:6px; color:var(--color-text-muted); font-size:48px; flex-shrink:0;">📷</div>
            <?php endif; ?>
            <dl style="display:grid; grid-template-columns: 160px 1fr; gap: 8px 16px; margin:0; flex:1;">
                <dt><strong>SKU</strong></dt><dd><code><?= Renderer::escape($row['sku']) ?></code></dd>
                <dt><strong>Code-barres</strong></dt><dd><code><?= Renderer::escape((string) ($row['barcode'] ?? '')) ?></code></dd>
                <dt><strong>Nom</strong></dt><dd><?= Renderer::escape($defaultName) ?></dd>
                <dt><strong>Marque</strong></dt><dd><?= $fmtText($row['brand'] ?? null) ?></dd>
                <dt><strong>Taille</strong></dt><dd><?= $fmtText($row['size'] ?? null) ?></dd>
                <dt><strong>Couleur</strong></dt><dd><?= $fmtText($row['color'] ?? null) ?></dd>
                <dt><strong>Saveur</strong></dt><dd><?= $fmtText($row['flavor'] ?? null) ?></dd>
                <dt><strong>Permalink</strong></dt><dd><?= $fmtText($row['permalink'] ?? null) ?></dd>
            </dl>
        </div>
    </div>
</div>

<form method="POST" action="/catalogue/create" class="card">
    <input type="hidden" name="_csrf" value="<?= Renderer::escape($csrf) ?>">
    <input type="hidden" name="sku" value="<?= Renderer::escape($row['sku']) ?>">

    <div class="card__header"><h3 class="card__title">Type de création</h3></div>
    <div class="card__body" style="display:flex; flex-direction:column; gap:14px;">

        <label style="display:flex; align-items:flex-start; gap:10px; cursor:pointer; padding:12px; border:1px solid var(--color-border); border-radius:var(--radius);">
            <input type="radio" name="type" value="product" checked style="margin-top:4px;" onchange="toggleType()">
            <span>
                <strong>Produit parent</strong>
                <p style="margin:4px 0 0; font-size:13px; color:var(--color-text-muted);">
                    Crée un nouveau produit racine. Champs configurables ci-dessous.
                    <?php if ($client->supplierId !== null && $client->supplierId > 0): ?>
                        Liaison fournisseur #<?= $client->supplierId ?> auto avec ref <code><?= Renderer::escape($row['sku']) ?></code>.
                    <?php else: ?>
                        ⚠ Aucun ID Fournisseur configuré dans Settings → PrestaShop, la liaison ne sera pas créée.
                    <?php endif; ?>
                </p>
            </span>
        </label>

        <label style="display:flex; align-items:flex-start; gap:10px; cursor:pointer; padding:12px; border:1px solid var(--color-border); border-radius:var(--radius);">
            <input type="radio" name="type" value="combination" style="margin-top:4px;" onchange="toggleType()">
            <span>
                <strong>Déclinaison d'un produit existant</strong>
                <p style="margin:4px 0 0; font-size:13px; color:var(--color-text-muted);">
                    Crée une combination sous un produit Presta existant. Indique l'<code>id_product</code> parent.
                </p>
            </span>
        </label>

        <?php /* ---------- BLOC PRODUIT PARENT ---------- */ ?>
        <div id="product-block" style="padding:0; display:flex; flex-direction:column; gap:18px;">

            <fieldset class="create-section">
                <legend>Type de produit</legend>
                <label style="display:flex; align-items:flex-start; gap:10px; cursor:pointer; padding:8px 0;">
                    <input type="radio" name="product_sub_type" value="simple" checked onchange="toggleSubType()">
                    <span>
                        <strong>Produit simple</strong>
                        <p style="margin:2px 0 0; font-size:12px; color:var(--color-text-muted);">
                            Crée un seul produit Presta avec ce SKU (<code><?= Renderer::escape($row['sku']) ?></code>) comme référence.
                            EAN13 = <code><?= Renderer::escape((string) ($row['barcode'] ?? '')) ?></code>.
                        </p>
                    </span>
                </label>
                <label style="display:flex; align-items:flex-start; gap:10px; cursor:pointer; padding:8px 0; <?= !$hasSiblings ? 'opacity:0.5;' : '' ?>">
                    <input type="radio" name="product_sub_type" value="with_combinations" <?= !$hasSiblings ? 'disabled' : '' ?> onchange="toggleSubType()">
                    <span>
                        <strong>Produit avec déclinaisons</strong>
                        <?php if ($hasSiblings): ?>
                            <p style="margin:2px 0 0; font-size:12px; color:var(--color-text-muted);">
                                Crée un produit parent (ref = <code><?= Renderer::escape((string) ($row['permalink'] ?? $row['sku'])) ?></code>, sans EAN) et une déclinaison par SKU coché ci-dessous.
                            </p>
                        <?php else: ?>
                            <p style="margin:2px 0 0; font-size:12px; color:var(--color-text-muted);">
                                ⚠ Aucun SKU sibling (même <code>permalink</code> dans Nutriweb). Re-sync le catalogue si tu en attends.
                            </p>
                        <?php endif; ?>
                    </span>
                </label>

                <?php if ($hasSiblings): ?>
                    <div id="siblings-block" style="display:none; margin-top:10px;">
                        <div style="font-size:11px; text-transform:uppercase; letter-spacing:0.6px; color:var(--color-text-muted); font-weight:600; margin-bottom:6px;">
                            SKUs siblings (<?= count($siblings) ?>)
                        </div>
                        <table class="siblings-table">
                            <thead>
                                <tr>
                                    <th style="width:32px;">✓</th>
                                    <th style="width:48px;">Photo</th>
                                    <th>SKU</th>
                                    <th>EAN</th>
                                    <th>Labels Nutriweb</th>
                                    <?php foreach ($attr_groups as $g): ?>
                                        <th>
                                            <div><?= Renderer::escape($g['name']) ?></div>
                                            <input type="search"
                                                   class="siblings-group-filter"
                                                   data-attr-group-id="<?= (int) $g['id'] ?>"
                                                   placeholder="Filtrer..."
                                                   autocomplete="off"
                                                   style="width:100%; margin-top:4px; padding:3px 6px; border:1px solid var(--color-border); border-radius:var(--radius); font-size:11px; font-weight:normal; text-transform:none; letter-spacing:0;">
                                        </th>
                                    <?php endforeach; ?>
                                    <th>Statut</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($siblings as $s):
                                    $disabled = $s['is_linked'];
                                    $checked = $s['is_current'] && !$disabled;
                                ?>
                                    <tr class="<?= $disabled ? 'siblings-table__row--locked' : '' ?> <?= $s['is_current'] ? 'siblings-table__row--current' : '' ?>">
                                        <td style="text-align:center;">
                                            <?php if ($disabled): ?>
                                                🔒
                                            <?php else: ?>
                                                <input type="checkbox" name="sibling_skus[]" value="<?= Renderer::escape($s['sku']) ?>" <?= $checked ? 'checked' : '' ?>>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (!empty($s['image_url'])): ?>
                                                <img src="<?= Renderer::escape($s['image_url']) ?>" alt="" loading="lazy" style="width:36px; height:36px; object-fit:cover; border-radius:4px; border:1px solid var(--color-border);">
                                            <?php else: ?>
                                                <span style="color:var(--color-text-muted);">📷</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <code><?= Renderer::escape($s['sku']) ?></code>
                                            <?php if ($s['is_current']): ?>
                                                <span style="font-size:10px; color:#7c3aed; font-weight:600; margin-left:4px;">⬩ courant</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><code style="font-size:11px;"><?= Renderer::escape($s['barcode']) ?></code></td>
                                        <td style="font-size:11px; color:var(--color-text-muted);"><?= Renderer::escape($sibLabel($s)) ?: '<em>—</em>' ?></td>
                                        <?php foreach ($attr_groups as $g):
                                            $preselectedValueId = $s['attrs_by_group'][$g['id']] ?? 0;
                                        ?>
                                            <td>
                                                <?php if ($disabled): ?>
                                                    <span style="color:var(--color-text-muted); font-size:11px;">—</span>
                                                <?php else: ?>
                                                    <select name="sibling_attrs[<?= Renderer::escape($s['sku']) ?>][<?= (int) $g['id'] ?>]" data-sib-group="<?= (int) $g['id'] ?>" style="font-size:12px; padding:3px 4px; max-width:140px;" <?= $preselectedValueId > 0 ? 'class="sib-select--auto"' : '' ?>>
                                                        <option value="">— —</option>
                                                        <?php foreach ($g['values'] as $v): ?>
                                                            <option value="<?= (int) $v['id'] ?>" <?= (int) $v['id'] === $preselectedValueId ? 'selected' : '' ?>>
                                                                <?= Renderer::escape($v['label']) ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                <?php endif; ?>
                                            </td>
                                        <?php endforeach; ?>
                                        <td style="font-size:11px;">
                                            <?php if ($disabled): ?>
                                                <?php if ($s['presta_combination_id'] > 0): ?>
                                                    <span style="color:#2563eb;">déjà liée D#<?= $s['presta_combination_id'] ?></span>
                                                <?php else: ?>
                                                    <span style="color:#16a34a;">déjà liée P#<?= $s['presta_product_id'] ?></span>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span style="color:var(--color-text-muted);">🆕 sera créée</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <p style="font-size:11px; color:var(--color-text-muted); margin:6px 0 0;">
                            Le SKU courant est coché par défaut. Coche les siblings à créer en déclinaisons. Les selects sont pré-remplis avec les valeurs Nutriweb (matchées contre Presta, insensible casse) — modifie si besoin avant de submit.
                        </p>
                    </div>
                <?php endif; ?>
            </fieldset>

            <fieldset class="create-section">
                <legend>Identité</legend>
                <label class="field" style="margin:0;">
                    <span class="field__label">Nom du produit *</span>
                    <input type="text" name="name" value="<?= Renderer::escape($defaultName) ?>" required>
                    <span class="field__hint">Pré-rempli depuis Nutriweb. Éditable. Commun à toutes les déclinaisons si tu en crées.</span>
                </label>
            </fieldset>

            <fieldset class="create-section">
                <legend>Catalogue</legend>
                <div class="create-grid">
                    <div>
                        <label style="display:block; font-weight:600; font-size:13px; margin-bottom:4px;">Catégories *</label>
                        <input type="search" id="cat-filter" placeholder="Filtrer (<?= count($categories_flat) ?> catégories)..." style="width:100%; padding:6px 8px; border:1px solid var(--color-border); border-radius:var(--radius); font-size:12px; margin-bottom:6px;" autocomplete="off">
                        <div style="max-height:240px; overflow-y:auto; border:1px solid var(--color-border); border-radius:var(--radius); padding:8px; background:#fff;">
                            <?php if (empty($categories_flat)): ?>
                                <em style="color:var(--color-text-muted); font-size:12px;">Aucune catégorie chargée. Verifie ta cle API.</em>
                            <?php else: ?>
                                <?php foreach ($categories_flat as $cat): ?>
                                    <label class="cat-row" data-cat-name="<?= Renderer::escape(mb_strtolower($cat['name'])) ?>" style="display:flex; align-items:center; gap:8px; padding:2px 0; font-size:13px; cursor:pointer;">
                                        <input type="checkbox" name="category_ids[]" value="<?= (int) $cat['id'] ?>" <?= $cat['id'] === 2 ? 'checked' : '' ?>>
                                        <span><?= Renderer::escape($cat['indented_name']) ?> <code style="font-size:10px; color:var(--color-text-muted);">#<?= (int) $cat['id'] ?></code></span>
                                    </label>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                        <span class="field__hint">La 1ère cochée devient la catégorie par défaut (id_category_default).</span>
                    </div>

                    <div>
                        <label class="field" style="margin:0 0 12px;">
                            <span class="field__label">Marque (manufacturer)
                                <?php if ($preselected_manufacturer_id > 0): ?>
                                    <span style="font-size:10px; color:#16a34a; font-weight:normal;">✓ auto</span>
                                <?php endif; ?>
                            </span>
                            <select name="manufacturer_id">
                                <option value="0">— Aucune —</option>
                                <?php foreach ($manufacturers as $m): ?>
                                    <option value="<?= (int) $m['id'] ?>" <?= $m['id'] === $preselected_manufacturer_id ? 'selected' : '' ?>>
                                        <?= Renderer::escape($m['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>

                        <label class="field" style="margin:0 0 12px;">
                            <span class="field__label">Statut</span>
                            <label style="display:flex; align-items:center; gap:8px; font-size:13px; padding:6px 0;">
                                <input type="checkbox" name="active" value="1">
                                <span>Actif (en vente immédiatement)</span>
                            </label>
                            <span class="field__hint">Décoché par défaut — tu valides dans Presta admin.</span>
                        </label>

                        <label class="field" style="margin:0;">
                            <span class="field__label">Visibilité</span>
                            <select name="visibility">
                                <option value="both" selected>Partout (catalogue + recherche)</option>
                                <option value="catalog">Catalogue uniquement</option>
                                <option value="search">Recherche uniquement</option>
                                <option value="none">Nulle part (lien direct)</option>
                            </select>
                        </label>
                    </div>
                </div>
            </fieldset>

            <fieldset class="create-section">
                <legend>Contenu</legend>
                <label class="field" style="margin:0 0 12px;">
                    <span class="field__label">Meta title</span>
                    <input type="text" name="meta_title" value="<?= Renderer::escape($defaultMetaTitle) ?>" maxlength="160">
                    <span class="field__hint">Pré-rempli avec le nom. ~60 chars recommandés.</span>
                </label>
                <label class="field" style="margin:0 0 12px;">
                    <span class="field__label">Meta description</span>
                    <textarea name="meta_description" rows="2" maxlength="320"></textarea>
                    <span class="field__hint">~150-160 chars recommandés.</span>
                </label>
                <label class="field" style="margin:0 0 12px;">
                    <span class="field__label">Meta keywords</span>
                    <input type="text" name="meta_keywords">
                </label>
                <label class="field" style="margin:0 0 12px;">
                    <span class="field__label">Description courte (teaser)</span>
                    <textarea name="description_short" rows="3"></textarea>
                </label>
                <label class="field" style="margin:0;">
                    <span class="field__label">Description longue</span>
                    <textarea name="description" rows="6"></textarea>
                    <span class="field__hint">HTML autorisé.</span>
                </label>
            </fieldset>

            <fieldset class="create-section">
                <legend>Logistique</legend>
                <div class="create-grid">
                    <label class="field" style="margin:0;">
                        <span class="field__label">Poids (kg)</span>
                        <input type="text" name="weight" placeholder="ex: 1.820" inputmode="decimal">
                    </label>
                    <div class="create-grid" style="grid-template-columns:repeat(3, 1fr); gap:8px;">
                        <label class="field" style="margin:0;">
                            <span class="field__label">Largeur (cm)</span>
                            <input type="text" name="width" placeholder="0" inputmode="decimal">
                        </label>
                        <label class="field" style="margin:0;">
                            <span class="field__label">Hauteur (cm)</span>
                            <input type="text" name="height" placeholder="0" inputmode="decimal">
                        </label>
                        <label class="field" style="margin:0;">
                            <span class="field__label">Profondeur (cm)</span>
                            <input type="text" name="depth" placeholder="0" inputmode="decimal">
                        </label>
                    </div>
                </div>
            </fieldset>

            <fieldset class="create-section">
                <legend>Règle de taxe</legend>
                <?php
                    // Mappe 3 categories metier (reduit/normal/none) sur les tax_rule_groups Presta.
                    // 'none' = id=0 hardcoded (= 'aucune taxe' dans PS, pas une entree tax_rule_group).
                    // 2 passes pour reduit/normal : specifique d'abord, generique en fallback.
                    $taxMap = ['reduit' => 0, 'normal' => 0, 'none' => 0];
                    $taxLabels = ['reduit' => '', 'normal' => '', 'none' => 'Aucune taxe (id=0)'];
                    $passes = [
                        // Pass 1 : keywords specifiques (prioritaires)
                        ['reduit' => ['5,5', '5.5'], 'normal' => ['20%', '(20']],
                        // Pass 2 : keywords generiques (fallback)
                        ['reduit' => ['réd', 'red'],  'normal' => ['normal', 'standard']],
                    ];
                    foreach ($passes as $kwsByKind) {
                        foreach ($tax_groups as $t) {
                            $name = (string) $t['name'];
                            $isSuper = mb_stripos($name, 'super') !== false;
                            foreach ($kwsByKind as $kind => $keys) {
                                if ($taxMap[$kind] > 0) continue;
                                if ($kind === 'reduit' && $isSuper) continue;
                                foreach ($keys as $k) {
                                    if (mb_stripos($name, $k) !== false) {
                                        $taxMap[$kind] = (int) $t['id'];
                                        $taxLabels[$kind] = $name;
                                        break;
                                    }
                                }
                            }
                        }
                    }
                    // Default = reduit (compl. alimentaire). Fallback = normal puis none.
                    $defaultKind = $taxMap['reduit'] > 0 ? 'reduit'
                        : ($taxMap['normal'] > 0 ? 'normal' : 'none');
                ?>
                <?php
                    $taxRates = ['reduit' => 0.055, 'normal' => 0.20, 'none' => 0];
                ?>
                <input type="hidden" name="tax_rate" id="tax-rate-hidden" value="<?= $taxRates[$defaultKind] ?? 0 ?>">
                <div style="display:flex; flex-direction:column; gap:8px;">
                    <?php foreach (['reduit' => 'Taux réduit', 'normal' => 'Taux normal', 'none' => 'Sans TVA'] as $kind => $label):
                        $id = $taxMap[$kind];
                        // 'none' est toujours disponible (id=0 = pas de tax_rule_group assigne cote PS)
                        $disabled = $kind !== 'none' && $id === 0;
                    ?>
                        <label style="display:flex; align-items:center; gap:10px; padding:8px 12px; border:1px solid var(--color-border); border-radius:var(--radius); cursor:<?= $disabled ? 'not-allowed' : 'pointer' ?>; <?= $disabled ? 'opacity:0.5;' : '' ?>">
                            <input type="radio" name="tax_rules_group_id" value="<?= $id ?>"
                                data-tax-rate="<?= $taxRates[$kind] ?? 0 ?>"
                                <?= $kind === $defaultKind && !$disabled ? 'checked' : '' ?>
                                <?= $disabled ? 'disabled' : '' ?>>
                            <span>
                                <strong><?= $label ?></strong>
                                <?php if ($disabled): ?>
                                    <span style="font-size:11px; color:#dc2626; margin-left:6px;">⚠ aucun tax_rule_group trouvé</span>
                                <?php elseif ($kind === 'none'): ?>
                                    <span style="font-size:11px; color:var(--color-text-muted); margin-left:6px;">→ aucune règle de taxe assignée · prix HT = TTC (0% TVA)</span>
                                <?php else: ?>
                                    <span style="font-size:11px; color:var(--color-text-muted); margin-left:6px;">→ <code><?= Renderer::escape($taxLabels[$kind]) ?></code> (#<?= $id ?>) · <?= number_format(($taxRates[$kind] ?? 0) * 100, $kind === 'reduit' ? 1 : 0, ',', '') ?>%</span>
                                <?php endif; ?>
                            </span>
                        </label>
                    <?php endforeach; ?>
                </div>
                <p style="margin:8px 0 0; font-size:11px; color:var(--color-text-muted);">
                    Le prix HT pousse a PrestaShop = <code>price.retail Nutriweb / (1 + taux)</code>. PS recalcule le TTC final cote front avec la regle de taxe.
                </p>
                <span class="field__hint">
                    Mappage automatique vers les <code>tax_rule_groups</code> Presta par mots-clés du label
                    (<em>réduit/5,5</em>, <em>normal/standard/20</em>, <em>aucun/no&nbsp;tax</em>).
                    Si un mapping manque, ajuste le label dans PS admin → Localisation → Règles de taxes.
                </span>
            </fieldset>

            <?php if (!empty($row['image_url'])): ?>
                <fieldset class="create-section">
                    <legend>Image</legend>
                    <label style="display:flex; align-items:flex-start; gap:10px; cursor:pointer;">
                        <input type="checkbox" name="push_image" value="1" checked style="margin-top:4px;">
                        <span>
                            <strong>Pousser l'image Nutriweb comme cover du produit</strong>
                            <p style="margin:4px 0 0; font-size:12px; color:var(--color-text-muted);">
                                Télécharge <code>image_url</code> Nutriweb et l'upload via <code>/api/images/products/{id}</code>
                                comme image principale après création du produit. Si ça échoue, le produit reste créé sans image.
                            </p>
                        </span>
                    </label>
                </fieldset>
            <?php endif; ?>
        </div>

        <?php /* ---------- BLOC DECLINAISON ---------- */ ?>
        <div id="combination-block" style="display:none; padding:12px; background:var(--color-bg); border-radius:var(--radius); flex-direction:column; gap:14px;">
            <div class="field" style="margin:0; position:relative;">
                <span class="field__label">Produit parent Presta *</span>
                <input type="hidden" name="parent_id" id="parent-id-hidden">
                <input type="search" id="parent-search" placeholder="Tape ≥ 2 caractères (nom, référence, sku fournisseur)..." autocomplete="off" style="width:100%;">
                <div id="parent-search-results" style="display:none; position:absolute; top:100%; left:0; right:0; background:#fff; border:1px solid var(--color-border); border-top:none; border-radius:0 0 var(--radius) var(--radius); max-height:280px; overflow-y:auto; z-index:10; box-shadow:0 4px 12px rgba(0,0,0,0.08);"></div>
                <div id="parent-selected" style="margin-top:6px; padding:6px 10px; background:#dcfce7; border:1px solid #16a34a; border-radius:var(--radius); font-size:13px; display:none;"></div>
                <span class="field__hint">Recherche dans presta_products (cache local — lance /produits/sync pour le mettre à jour). Tu peux aussi coller un ID Presta directement (champ ID brut, ↓ ci-dessous).</span>
                <details style="margin-top:6px;">
                    <summary style="cursor:pointer; font-size:11px; color:var(--color-text-muted);">Ou saisir l'ID Presta directement</summary>
                    <input type="number" id="parent-id-manual" min="1" step="1" placeholder="ex: 205" style="margin-top:4px; max-width:160px;">
                </details>
            </div>

            <?php if (empty($attr_groups)): ?>
                <div style="font-size:13px; color:var(--color-text-muted); padding:10px; background:#fef3c7; border-radius:var(--radius);">
                    ⚠ Aucun groupe d'attribut détecté côté PrestaShop. La combination sera créée sans attributs.
                </div>
            <?php else: ?>
                <div>
                    <div style="font-size:11px; text-transform:uppercase; letter-spacing:0.6px; color:var(--color-text-muted); font-weight:600; margin-bottom:8px;">
                        Attributs de la déclinaison
                    </div>
                    <p style="margin:0 0 12px; font-size:12px; color:var(--color-text-muted);">
                        Pré-sélection automatique si match Nutriweb ↔ Presta. Tape pour filtrer.
                    </p>
                    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(280px, 1fr)); gap:12px;">
                        <?php foreach ($attr_groups as $group): ?>
                            <?php
                                $selId = 'opt-group-' . $group['id'];
                                $filterId = 'flt-group-' . $group['id'];
                                $preselectedInGroup = 0;
                                foreach ($group['values'] as $v) {
                                    if (in_array($v['id'], $preselected_ids, true)) {
                                        $preselectedInGroup = $v['id'];
                                        break;
                                    }
                                }
                            ?>
                            <div>
                                <label for="<?= Renderer::escape($selId) ?>" style="display:block; font-weight:600; font-size:13px; margin-bottom:4px;">
                                    <?= Renderer::escape($group['name']) ?>
                                    <?php if ($preselectedInGroup > 0): ?>
                                        <span style="font-size:10px; color:#16a34a; font-weight:normal;">✓ auto</span>
                                    <?php endif; ?>
                                </label>
                                <input type="search" id="<?= Renderer::escape($filterId) ?>" data-filter-for="<?= Renderer::escape($selId) ?>" placeholder="Filtrer (<?= count($group['values']) ?> valeurs)..." style="width:100%; padding:6px 8px; border:1px solid var(--color-border); border-radius:var(--radius); font-size:12px; margin-bottom:4px;" autocomplete="off">
                                <select name="option_value_ids[]" id="<?= Renderer::escape($selId) ?>" style="width:100%; padding:6px 8px; border:1px solid var(--color-border); border-radius:var(--radius); font-size:13px;">
                                    <option value="">— Aucune valeur pour ce groupe —</option>
                                    <?php foreach ($group['values'] as $v): ?>
                                        <option value="<?= (int) $v['id'] ?>" <?= $v['id'] === $preselectedInGroup ? 'selected' : '' ?>>
                                            <?= Renderer::escape($v['label']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="form-actions" style="padding:14px;">
        <a href="/catalogue" class="btn btn--ghost">Annuler</a>
        <button type="submit" class="btn btn--primary">Créer dans PrestaShop</button>
    </div>
</form>

<style>
.create-section { border:1px solid var(--color-border); border-radius:var(--radius); padding:14px; margin:0; }
.create-section > legend { padding:0 8px; font-weight:600; font-size:12px; text-transform:uppercase; letter-spacing:0.05em; color:var(--color-text-muted); }
.create-grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(280px, 1fr)); gap:14px; }
.cat-row:hover { background:var(--color-bg); }
.siblings-table { width:100%; border-collapse:collapse; font-size:13px; margin-top:4px; }
.siblings-table th, .siblings-table td { padding:6px 10px; text-align:left; border-bottom:1px solid var(--color-border); vertical-align:middle; }
.siblings-table thead th { background:var(--color-bg); font-weight:600; font-size:11px; text-transform:uppercase; letter-spacing:0.04em; color:var(--color-text-muted); }
.siblings-table tbody tr:hover { background:var(--color-bg); }
.siblings-table__row--locked { opacity:0.5; }
.siblings-table__row--current { background:#faf5ff; }
.sib-select--auto { background:#f0fdf4; border-color:#86efac; }
</style>

<script>
function toggleType() {
    var t = document.querySelector('input[name="type"]:checked').value;
    document.getElementById('product-block').style.display = (t === 'product') ? 'flex' : 'none';
    document.getElementById('combination-block').style.display = (t === 'combination') ? 'flex' : 'none';
}
function toggleSubType() {
    var sib = document.getElementById('siblings-block');
    if (!sib) return;
    var st = document.querySelector('input[name="product_sub_type"]:checked');
    sib.style.display = (st && st.value === 'with_combinations') ? 'block' : 'none';
}
// Sync hidden tax_rate quand on change de radio TVA
function syncTaxRate() {
    var hid = document.getElementById('tax-rate-hidden');
    var r = document.querySelector('input[name="tax_rules_group_id"]:checked');
    if (hid && r) hid.value = r.dataset.taxRate || '0';
}
document.querySelectorAll('input[name="tax_rules_group_id"]').forEach(function (r) {
    r.addEventListener('change', syncTaxRate);
});
toggleType();
toggleSubType();
syncTaxRate();

// Filtre les options des selects par groupe d'attribut (decli)
document.querySelectorAll('[data-filter-for]').forEach(function (input) {
    var select = document.getElementById(input.dataset.filterFor);
    if (!select) return;
    input.addEventListener('input', function () {
        var q = input.value.trim().toLowerCase();
        Array.from(select.options).forEach(function (opt) {
            if (opt.value === '') return;
            var label = (opt.text || '').toLowerCase();
            opt.hidden = q !== '' && !label.includes(q);
        });
    });
});

// Filtre les selects siblings par colonne d'attribut (1 input dans le th -> filtre toutes les
// options des selects [data-sib-group="ID"] de la table)
document.querySelectorAll('.siblings-group-filter').forEach(function (input) {
    var gid = input.dataset.attrGroupId;
    if (!gid) return;
    input.addEventListener('input', function () {
        var q = input.value.trim().toLowerCase();
        document.querySelectorAll('select[data-sib-group="' + gid + '"]').forEach(function (sel) {
            Array.from(sel.options).forEach(function (opt) {
                if (opt.value === '') return;
                var label = (opt.text || '').toLowerCase();
                opt.hidden = q !== '' && !label.includes(q);
            });
        });
    });
});

// Autocomplete produit parent Presta (mode 'declinaison')
(function () {
    var input = document.getElementById('parent-search');
    var manualInput = document.getElementById('parent-id-manual');
    var hidden = document.getElementById('parent-id-hidden');
    var dropdown = document.getElementById('parent-search-results');
    var selected = document.getElementById('parent-selected');
    if (!input || !hidden || !dropdown) return;
    var lastTerm = '';
    var timer = null;

    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, function (c) {
            return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
        });
    }

    function pickProduct(r) {
        hidden.value = String(r.presta_id);
        if (manualInput) manualInput.value = '';
        selected.style.display = 'block';
        selected.innerHTML = '✓ Sélectionné : <strong>' + escapeHtml(r.name) + '</strong> '
            + '<code style="font-size:11px;">P#' + r.presta_id + '</code>'
            + (r.reference ? ' · ref <code style="font-size:11px;">' + escapeHtml(r.reference) + '</code>' : '');
        dropdown.style.display = 'none';
        input.value = r.name;
    }

    function renderResults(items) {
        if (items.length === 0) {
            dropdown.innerHTML = '<div style="padding:8px 12px; font-size:12px; color:var(--color-text-muted);">Aucun produit trouvé. Lance /produits/sync si besoin.</div>';
        } else {
            dropdown.innerHTML = items.map(function (r) {
                return '<div class="parent-result" data-pid="' + r.presta_id + '" '
                    + 'style="padding:6px 12px; cursor:pointer; border-bottom:1px solid var(--color-border); font-size:13px;">'
                    + '<strong>' + escapeHtml(r.name) + '</strong> '
                    + '<code style="font-size:10px; color:var(--color-text-muted);">P#' + r.presta_id + '</code>'
                    + (r.reference ? ' <span style="color:var(--color-text-muted); font-size:11px;">· ref ' + escapeHtml(r.reference) + '</span>' : '')
                    + (r.supplier_reference ? ' <span style="color:var(--color-text-muted); font-size:11px;">· fourn ' + escapeHtml(r.supplier_reference) + '</span>' : '')
                    + '</div>';
            }).join('');
            dropdown.querySelectorAll('.parent-result').forEach(function (el) {
                el.addEventListener('mouseenter', function () { el.style.background = 'var(--color-bg)'; });
                el.addEventListener('mouseleave', function () { el.style.background = ''; });
                el.addEventListener('click', function () {
                    var idx = parseInt(el.dataset.pid, 10);
                    var found = items.find(function (r) { return r.presta_id === idx; });
                    if (found) pickProduct(found);
                });
            });
        }
        dropdown.style.display = 'block';
    }

    function doSearch(q) {
        if (q.length < 2) { dropdown.style.display = 'none'; return; }
        fetch('/catalogue/search-presta-products?q=' + encodeURIComponent(q), { headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (j) { renderResults(j.results || []); })
            .catch(function () { dropdown.style.display = 'none'; });
    }

    input.addEventListener('input', function () {
        var q = input.value.trim();
        if (q === lastTerm) return;
        lastTerm = q;
        if (timer) clearTimeout(timer);
        timer = setTimeout(function () { doSearch(q); }, 250);
    });
    input.addEventListener('focus', function () { if (input.value.trim().length >= 2) doSearch(input.value.trim()); });
    document.addEventListener('click', function (e) {
        if (e.target !== input && !dropdown.contains(e.target)) dropdown.style.display = 'none';
    });

    // Saisie manuelle ID -> override
    if (manualInput) {
        manualInput.addEventListener('input', function () {
            var v = parseInt(manualInput.value, 10);
            if (v > 0) {
                hidden.value = String(v);
                selected.style.display = 'block';
                selected.innerHTML = '✓ ID Presta manuel : <code>P#' + v + '</code>';
                input.value = '';
            } else {
                hidden.value = '';
                selected.style.display = 'none';
            }
        });
    }
})();

// Filtre les checkboxes de categories
var catFilter = document.getElementById('cat-filter');
if (catFilter) {
    catFilter.addEventListener('input', function () {
        var q = catFilter.value.trim().toLowerCase();
        document.querySelectorAll('.cat-row').forEach(function (row) {
            var name = row.dataset.catName || '';
            row.style.display = (q === '' || name.includes(q)) ? '' : 'none';
        });
    });
}
</script>
</div>

