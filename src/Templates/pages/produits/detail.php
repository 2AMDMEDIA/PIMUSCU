<?php
use App\Helpers\Renderer;

/**
 * @var array<string,mixed> $row
 * @var ?string $external_url
 * @var string $csrf_token
 */
$name = (string) $row['name'];
$reference = (string) ($row['reference'] ?? '');
$active = (int) $row['active'] === 1;
$prestaId = (int) $row['presta_id'];
$priceHt = (float) $row['price'];
$priceTTC = $priceHt * 1.20;
$wholesalePrice = (float) ($row['wholesale_price'] ?? 0);
$marginEur = $priceHt - $wholesalePrice;
$marginPctOnSale = ($priceHt > 0 && $wholesalePrice > 0) ? ($marginEur / $priceHt * 100) : null;
$markupPct = ($wholesalePrice > 0) ? ($marginEur / $wholesalePrice * 100) : null;

$currentDescShort = (string) ($row['description_short'] ?? '');
$currentDescription = (string) ($row['description'] ?? '');
$currentMetaTitle = (string) ($row['meta_title'] ?? '');
$currentMetaDesc = (string) ($row['meta_description'] ?? '');
$currentMetaKw = (string) ($row['meta_keywords'] ?? '');
$hasCmsContent = (int) ($row['has_cms_content'] ?? 0) === 1;

$optDescShort = (string) ($row['optimized_description_short'] ?? '');
$optDescription = (string) ($row['optimized_description'] ?? '');
$optMetaTitle = (string) ($row['optimized_meta_title'] ?? '');
$optMetaDesc = (string) ($row['optimized_meta_description'] ?? '');
$optMetaKw = (string) ($row['optimized_meta_keywords'] ?? '');
?>
<div class="page-fullwidth">
<div class="product-detail-header">
    <?php if (!empty($row['image_url'])): ?>
        <a href="<?= Renderer::escape($external_url ?? $row['image_url']) ?>"
           target="_blank" rel="noopener"
           class="product-detail-header__image">
            <img src="<?= Renderer::escape($row['image_url']) ?>" alt="<?= Renderer::escape($name) ?>"
                 onerror="this.outerHTML='<div class=&quot;product-detail-header__no-image&quot;><span>📷</span></div>'">
        </a>
    <?php else: ?>
        <div class="product-detail-header__no-image">
            <span>📷</span>
        </div>
    <?php endif; ?>

    <div class="product-detail-header__body">
        <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
            <a href="/produits" class="btn btn--ghost btn--sm" style="padding:4px 8px;">←</a>
            <h2 class="page-header__title" style="margin:0;"><?= Renderer::escape($name) ?></h2>
            <?php if ($external_url): ?>
                <a href="<?= Renderer::escape($external_url) ?>" target="_blank" rel="noopener" title="Voir sur le site" style="color:var(--color-text-muted);">↗</a>
            <?php endif; ?>
            <?php if ($active): ?>
                <span class="badge badge--blue">Actif</span>
            <?php else: ?>
                <span class="badge badge--gray">Inactif</span>
            <?php endif; ?>
            <?php if ($hasCmsContent): ?>
                <span class="badge badge--purple">◆ Contenu CMS</span>
            <?php endif; ?>
        </div>
        <p class="page-header__subtitle">
            Réf. <?= Renderer::escape($reference) ?> ·
            <?php if (!empty($row['supplier_reference'])): ?>
                Réf. fournisseur <?= Renderer::escape((string) $row['supplier_reference']) ?> ·
            <?php endif; ?>
            <?= number_format($priceTTC, 2, ',', ' ') ?> € TTC
            (<?= number_format($priceHt, 2, ',', ' ') ?> € HT) ·
            ID Presta : <?= $prestaId ?>
        </p>

        <?php if ($wholesalePrice > 0): ?>
            <div class="margin-box">
                <span class="margin-box__cell">
                    <span class="margin-box__label">Prix d'achat</span>
                    <strong><?= number_format($wholesalePrice, 2, ',', ' ') ?> € HT</strong>
                </span>
                <span class="margin-box__cell">
                    <span class="margin-box__label">Marge</span>
                    <strong class="<?= $marginEur >= 0 ? 'margin-box__positive' : 'margin-box__negative' ?>">
                        <?= ($marginEur >= 0 ? '+' : '') . number_format($marginEur, 2, ',', ' ') ?> € HT
                    </strong>
                </span>
                <?php if ($marginPctOnSale !== null): ?>
                    <span class="margin-box__cell">
                        <span class="margin-box__label">Taux de marge</span>
                        <strong class="<?= $marginPctOnSale >= 0 ? 'margin-box__positive' : 'margin-box__negative' ?>">
                            <?= number_format($marginPctOnSale, 1, ',', ' ') ?> %
                        </strong>
                    </span>
                <?php endif; ?>
                <?php if ($markupPct !== null): ?>
                    <span class="margin-box__cell">
                        <span class="margin-box__label">Coefficient</span>
                        <strong>×<?= number_format($priceHt / $wholesalePrice, 2, ',', ' ') ?></strong>
                    </span>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <p class="page-header__subtitle" style="margin-top:6px;color:#92400e;">
                ⚠ Prix d'achat non renseigné sur PrestaShop — marge non calculable.
            </p>
        <?php endif; ?>
    </div>
</div>

<?php if ($hasCmsContent): ?>
    <div class="flash flash--amber" style="background:#fef3c7;color:#92400e;padding:12px 14px;border-radius:8px;margin-bottom:20px;">
        ⚠ Ce produit contient du contenu enrichi (Elementor, hooks ou images CMS custom). L'IA peut générer une version simple,
        mais soyez vigilant avant de pousser : vous écraserez le contenu sur-mesure.
    </div>
<?php endif; ?>

<nav class="prod-tabs" role="tablist">
    <button type="button" class="prod-tabs__item prod-tabs__item--active" data-tab-target="description">📝 Description</button>
    <button type="button" class="prod-tabs__item" data-tab-target="prix">💰 Prix</button>
    <button type="button" class="prod-tabs__item" data-tab-target="avis">⭐ Avis</button>
    <button type="button" class="prod-tabs__item" data-tab-target="galerie">🖼 Galerie</button>
    <button type="button" class="prod-tabs__item" data-tab-target="declinaisons">🎨 Déclinaisons<?= !empty($combinations) ? ' (' . count($combinations) . ')' : '' ?></button>
</nav>

<section class="prod-tab" data-tab="declinaisons" hidden>
<?php if (!empty($combinations)): ?>
    <div class="card" style="margin-bottom: 20px;">
        <div class="card__header" style="display:flex; justify-content:space-between; align-items:center;">
            <h3 class="card__title">
                Déclinaisons
                <span style="font-size:12px; color:var(--color-text-muted); font-weight:400; margin-left:6px;">
                    (<?= count($combinations) ?>)
                </span>
            </h3>
        </div>
        <div class="card__body" style="padding:0;">
            <div style="overflow-x:auto;">
                <table class="combinations-table">
                    <thead>
                        <tr>
                            <th>Attributs</th>
                            <th>Référence</th>
                            <th>Code-barres</th>
                            <th>Réf. fournisseur</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($combinations as $c): ?>
                            <tr>
                                <td><?= !empty($c['attributes_label']) ? Renderer::escape((string) $c['attributes_label']) : '<em style="color:var(--color-text-muted);">— sans attribut —</em>' ?></td>
                                <td><?= !empty($c['reference']) ? '<code>' . Renderer::escape((string) $c['reference']) . '</code>' : '<span style="color:var(--color-text-muted);">—</span>' ?></td>
                                <td><?= !empty($c['barcode']) ? '<code>' . Renderer::escape((string) $c['barcode']) . '</code>' : '<span style="color:var(--color-text-muted);">—</span>' ?></td>
                                <td><?= !empty($c['supplier_reference']) ? '<code>' . Renderer::escape((string) $c['supplier_reference']) . '</code>' : '<span style="color:var(--color-text-muted);">—</span>' ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <style>
    .combinations-table { width: 100%; border-collapse: collapse; font-size: 13px; }
    .combinations-table th, .combinations-table td { padding: 8px 12px; text-align: left; border-bottom: 1px solid var(--color-border); }
    .combinations-table thead th { background: var(--color-bg); font-weight: 600; font-size: 11px; text-transform: uppercase; letter-spacing: 0.04em; color: var(--color-text-muted); }
    .combinations-table tbody tr:hover { background: var(--color-bg); }
    .combinations-table code { font-size: 12px; }
    </style>
<?php else: ?>
    <div class="card"><div class="card__body"><div class="empty-state"><div class="empty-state__hint">Aucune déclinaison sur ce produit (produit simple).</div></div></div></div>
<?php endif; ?>
</section>

<?php
    // ---- Panel PRIX : promo flash + etude de prix SerpApi ----
    $priceTTC = (float) ($row['price'] ?? 0) * 1.20;
    $promoDateFrom = date('Y-m-d');
    $promoDateTo = date('Y-m-d', strtotime('+7 days'));
?>
<section class="prod-tab" data-tab="prix" hidden>
    <div class="card" style="margin-bottom:16px;">
        <div class="card__header"><h3 class="card__title">⚡ Promo flash</h3></div>
        <div class="card__body">
            <p style="margin:0 0 12px; font-size:13px; color:var(--color-text-muted);">
                Crée une <code>specific_price</code> PrestaShop (prix barré + nouveau prix sur la fiche). Prix actuel : <strong><?= number_format($priceTTC, 2, ',', ' ') ?> € TTC</strong>.
            </p>
            <?php if (!empty($active_promos)): ?>
                <div style="margin-bottom:12px; padding:10px 12px; background:#fef3c7; border:1px solid #fcd34d; border-radius:var(--radius); font-size:13px;">
                    <strong><?= count($active_promos) ?> promo(s) active(s)</strong> sur ce produit :
                    <ul style="margin:6px 0 0; padding-left:18px;">
                        <?php foreach ($active_promos as $p):
                            $lbl = $p['reduction_type'] === 'percentage'
                                ? '-' . number_format($p['reduction'] * 100, 1, ',', ' ') . ' %'
                                : '-' . number_format($p['reduction'], 2, ',', ' ') . ' € (' . ($p['reduction_tax'] ? 'TTC' : 'HT') . ')';
                            $du = $p['from'] !== '' && !str_starts_with($p['from'], '0000') ? date('d/m/Y', strtotime($p['from'])) : '∞';
                            $au = $p['to'] !== '' && !str_starts_with($p['to'], '0000') ? date('d/m/Y', strtotime($p['to'])) : '∞';
                        ?>
                            <li><?= Renderer::escape($lbl) ?> · du <?= $du ?> au <?= $au ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <form method="POST" action="/produits/<?= Renderer::escape($row['id']) ?>/flash-promo/delete" style="margin:8px 0 0;" onsubmit="return confirm('Supprimer toutes les promos actives de ce produit ?');">
                        <input type="hidden" name="_csrf" value="<?= Renderer::escape($csrf_token) ?>">
                        <button type="submit" class="btn btn--ghost btn--sm">🗑 Supprimer les promos</button>
                    </form>
                </div>
            <?php endif; ?>
            <form method="POST" action="/produits/<?= Renderer::escape($row['id']) ?>/flash-promo">
                <input type="hidden" name="_csrf" value="<?= Renderer::escape($csrf_token) ?>">
                <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(160px,1fr)); gap:12px; align-items:end;">
                    <label class="field" style="margin:0;">
                        <span class="field__label">Nouveau prix TTC (€)</span>
                        <input type="text" name="new_price_ttc" inputmode="decimal" placeholder="ex: 59,90">
                    </label>
                    <div style="text-align:center; color:var(--color-text-muted); font-size:12px; padding-bottom:8px;">— OU —</div>
                    <label class="field" style="margin:0;">
                        <span class="field__label">Remise (%)</span>
                        <input type="text" name="discount_pct" inputmode="decimal" placeholder="ex: 20">
                    </label>
                    <label class="field" style="margin:0;">
                        <span class="field__label">Du</span>
                        <input type="date" name="date_from" value="<?= $promoDateFrom ?>">
                    </label>
                    <label class="field" style="margin:0;">
                        <span class="field__label">Au</span>
                        <input type="date" name="date_to" value="<?= $promoDateTo ?>">
                    </label>
                </div>
                <div style="margin-top:12px; text-align:right;">
                    <button type="submit" class="btn btn--primary">Créer la promo</button>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card__header" style="display:flex; justify-content:space-between; align-items:center; gap:8px;">
            <h3 class="card__title">📊 Étude de prix concurrentielle</h3>
            <button type="button" id="cp-btn" class="btn btn--primary btn--sm" data-product-id="<?= Renderer::escape($row['id']) ?>">
                <span class="btn-label">🔍 Comparer les prix</span>
                <span class="btn-spinner" hidden>Recherche… (10-20s)</span>
            </button>
        </div>
        <div class="card__body">
            <p style="margin:0 0 10px; font-size:12px; color:var(--color-text-muted);">
                Recherche les prix concurrents sur Google Shopping France (via SerpApi). <a href="/settings?tab=ai-tools">Configurer la clé SerpApi</a>.
            </p>
            <div id="cp-status" style="font-size:13px; margin-bottom:10px;"></div>
            <div id="cp-result" <?= $price_analysis === null ? 'hidden' : '' ?>>
                <?php if ($price_analysis !== null): ?>
                    <div id="cp-summary" style="padding:10px 12px; background:var(--color-bg); border-radius:var(--radius); font-size:13px; margin-bottom:10px;">
                        <?= Renderer::escape($price_analysis['summary']) ?>
                        <div style="font-size:11px; color:var(--color-text-muted); margin-top:4px;">Dernière analyse : <?= date('d/m/Y H:i', strtotime($price_analysis['created_at'])) ?></div>
                    </div>
                    <div id="cp-stats" style="display:flex; gap:18px; flex-wrap:wrap; font-size:13px; margin-bottom:10px;">
                        <span>Moy. <strong><?= $price_analysis['avg_price_eur'] !== null ? number_format($price_analysis['avg_price_eur'], 2, ',', ' ') . ' €' : '—' ?></strong></span>
                        <span>Min <strong><?= $price_analysis['min_price_eur'] !== null ? number_format($price_analysis['min_price_eur'], 2, ',', ' ') . ' €' : '—' ?></strong></span>
                        <span>Max <strong><?= $price_analysis['max_price_eur'] !== null ? number_format($price_analysis['max_price_eur'], 2, ',', ' ') . ' €' : '—' ?></strong></span>
                        <span>Médiane <strong><?= $price_analysis['median_price_eur'] !== null ? number_format($price_analysis['median_price_eur'], 2, ',', ' ') . ' €' : '—' ?></strong></span>
                    </div>
                    <div id="cp-rows">
                        <table class="cp-table">
                            <thead><tr><th>Marchand</th><th>Produit</th><th class="catalog-table__num">Prix</th></tr></thead>
                            <tbody>
                                <?php foreach ($price_analysis['results'] as $res): ?>
                                    <tr>
                                        <td><?= Renderer::escape((string) ($res['site'] ?? '—')) ?></td>
                                        <td><?php $u = $res['url'] ?? null; $nm = (string) ($res['name'] ?? ''); ?>
                                            <?= $u ? '<a href="' . Renderer::escape((string) $u) . '" target="_blank" rel="noopener">' . Renderer::escape($nm) . '</a>' : Renderer::escape($nm) ?>
                                        </td>
                                        <td class="catalog-table__num"><?= isset($res['price_eur']) && $res['price_eur'] !== null ? number_format((float) $res['price_eur'], 2, ',', ' ') . ' €' : '—' ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <style>
    .cp-table { width:100%; border-collapse:collapse; font-size:13px; }
    .cp-table th, .cp-table td { padding:6px 10px; text-align:left; border-bottom:1px solid var(--color-border); }
    .cp-table thead th { background:var(--color-bg); font-weight:600; font-size:11px; text-transform:uppercase; color:var(--color-text-muted); }
    </style>
</section>

<section class="prod-tab" data-tab="description">
<form method="POST" action="/produits/<?= Renderer::escape($row['id']) ?>/push" class="cat-detail">
    <input type="hidden" name="_csrf" value="<?= Renderer::escape($csrf_token) ?>">

    <!-- Bloc Instructions IA en haut, pleine largeur -->
    <div class="card" style="margin-bottom: 16px;">
        <div class="card__header" style="display:flex;justify-content:space-between;align-items:center;gap:8px;">
            <h3 class="card__title">Génération IA</h3>
            <button type="button" id="ai-generate-btn" class="btn btn--primary btn--sm" style="background:#7c3aed;border-color:#7c3aed;">
                <span class="ai-gen-label">✨ Générer par IA</span>
                <span class="ai-gen-spinner" hidden>Génération en cours…</span>
            </button>
        </div>
        <div class="card__body">
            <div class="ai-gen-panel" style="display:grid;grid-template-columns: 1fr 220px;gap:16px;align-items:start;">
                <label class="field" style="margin:0;">
                    <span class="field__label">Instructions pour l'IA (optionnel)</span>
                    <textarea id="ai-instructions" rows="2" placeholder="Ex: insister sur l'aspect cadeau, audience femmes 30-50, ton chaleureux..."></textarea>
                </label>
                <label class="field" style="margin:0;">
                    <span class="field__label">Description longue ≈ mots</span>
                    <input type="number" id="ai-word-count" min="50" max="1500" step="50" value="300">
                </label>
            </div>
            <div id="ai-result" class="ai-gen-result" hidden style="margin-top:12px;"></div>
        </div>
    </div>

    <!-- Champs alignés : 1 bloc par champ, avec checkbox de contrôle (IA + Push) -->
    <div class="cat-pairs">

        <!-- Meta title -->
        <section class="cat-pair" data-field="meta_title">
            <header class="cat-pair__head">
                <label class="cat-pair__toggle">
                    <input type="checkbox" name="enabled_meta_title" value="1" checked data-field-toggle="meta_title">
                    <span class="cat-pair__name">Meta title</span>
                </label>
                <span class="cat-pair__counter" id="mt-counter">0 / 60</span>
            </header>
            <div class="cat-pair__body">
                <div class="cat-pair__col">
                    <span class="cat-pair__col-label">Actuel</span>
                    <div class="cat-detail__readonly"><?= Renderer::escape($currentMetaTitle) ?: '<em style="color:var(--color-text-muted);">—</em>' ?></div>
                </div>
                <div class="cat-pair__col">
                    <span class="cat-pair__col-label">
                        Optimisé
                        <button type="button" class="copy-current-btn" data-copy-current data-target="mt-input" data-source-text="<?= Renderer::escape($currentMetaTitle) ?>" title="Copier la valeur actuelle">↩ Copier l'actuel</button>
                    </span>
                    <input type="text" name="optimized_meta_title" id="mt-input" maxlength="80" value="<?= Renderer::escape($optMetaTitle) ?>">
                </div>
            </div>
        </section>

        <!-- Meta description -->
        <section class="cat-pair" data-field="meta_description">
            <header class="cat-pair__head">
                <label class="cat-pair__toggle">
                    <input type="checkbox" name="enabled_meta_description" value="1" checked data-field-toggle="meta_description">
                    <span class="cat-pair__name">Meta description</span>
                </label>
                <span class="cat-pair__counter" id="md-counter">0 / 155</span>
            </header>
            <div class="cat-pair__body">
                <div class="cat-pair__col">
                    <span class="cat-pair__col-label">Actuel</span>
                    <div class="cat-detail__readonly"><?= Renderer::escape($currentMetaDesc) ?: '<em style="color:var(--color-text-muted);">—</em>' ?></div>
                </div>
                <div class="cat-pair__col">
                    <span class="cat-pair__col-label">
                        Optimisé
                        <button type="button" class="copy-current-btn" data-copy-current data-target="md-input" data-source-text="<?= Renderer::escape($currentMetaDesc) ?>" title="Copier la valeur actuelle">↩ Copier l'actuel</button>
                    </span>
                    <textarea name="optimized_meta_description" id="md-input" rows="3" maxlength="200"><?= Renderer::escape($optMetaDesc) ?></textarea>
                </div>
            </div>
        </section>

        <!-- Meta keywords -->
        <section class="cat-pair" data-field="meta_keywords">
            <header class="cat-pair__head">
                <label class="cat-pair__toggle">
                    <input type="checkbox" name="enabled_meta_keywords" value="1" checked data-field-toggle="meta_keywords">
                    <span class="cat-pair__name">Meta mots-clés</span>
                </label>
                <span class="cat-pair__counter">séparés par des virgules</span>
            </header>
            <div class="cat-pair__body">
                <div class="cat-pair__col">
                    <span class="cat-pair__col-label">Actuel</span>
                    <div class="cat-detail__readonly"><?= Renderer::escape($currentMetaKw) ?: '<em style="color:var(--color-text-muted);">—</em>' ?></div>
                </div>
                <div class="cat-pair__col">
                    <span class="cat-pair__col-label">
                        Optimisé
                        <button type="button" class="copy-current-btn" data-copy-current data-target="mk-input" data-source-text="<?= Renderer::escape($currentMetaKw) ?>" title="Copier la valeur actuelle">↩ Copier l'actuel</button>
                    </span>
                    <input type="text" name="optimized_meta_keywords" id="mk-input" maxlength="1000" value="<?= Renderer::escape($optMetaKw) ?>" placeholder="mot-clé 1, mot-clé 2, expression longue, ...">
                </div>
            </div>
        </section>

        <!-- Description courte (teaser) -->
        <section class="cat-pair" data-field="description_short">
            <header class="cat-pair__head">
                <label class="cat-pair__toggle">
                    <input type="checkbox" name="enabled_description_short" value="1" checked data-field-toggle="description_short">
                    <span class="cat-pair__name">Description courte (teaser)</span>
                </label>
            </header>
            <div class="cat-pair__body">
                <div class="cat-pair__col">
                    <span class="cat-pair__col-label">Actuel</span>
                    <div class="cat-detail__html cat-pair__html-current" style="max-height:240px;">
                        <?= $currentDescShort !== '' ? $currentDescShort : '<em style="color:var(--color-text-muted);">— (vide)</em>' ?>
                    </div>
                </div>
                <div class="cat-pair__col">
                    <span class="cat-pair__col-label">
                        Optimisé
                        <button type="button" class="copy-current-btn" data-copy-current data-target="ds-input" data-source-html-b64="<?= base64_encode($currentDescShort) ?>" title="Copier le HTML actuel">↩ Copier l'actuel</button>
                        <span class="html-editor__tabs" data-html-editor="ds">
                            <button type="button" class="html-editor__tab html-editor__tab--active" data-mode="preview">👁 Aperçu</button>
                            <button type="button" class="html-editor__tab" data-mode="code">&lt;/&gt; Code</button>
                        </span>
                    </span>
                    <div class="html-editor" data-target="ds-input">
                        <div class="html-editor__preview cat-detail__html" data-preview="ds-input">
                            <?= $optDescShort !== '' ? $optDescShort : '<em style="color:var(--color-text-muted);">— (vide — clique sur Code pour rédiger)</em>' ?>
                        </div>
                        <textarea name="optimized_description_short" id="ds-input" rows="6" hidden placeholder="Phrase d'accroche courte..."><?= Renderer::escape($optDescShort) ?></textarea>
                    </div>
                </div>
            </div>
        </section>

        <!-- Description longue -->
        <section class="cat-pair" data-field="description">
            <header class="cat-pair__head">
                <label class="cat-pair__toggle">
                    <input type="checkbox" name="enabled_description" value="1" <?= $hasCmsContent ? '' : 'checked' ?> data-field-toggle="description">
                    <span class="cat-pair__name">Description longue</span>
                </label>
                <?php if ($hasCmsContent): ?>
                    <span style="color:#dc2626;font-size:11px;">⚠ Contenu CMS détecté — décoché par sécurité</span>
                <?php endif; ?>
            </header>
            <div class="cat-pair__body">
                <div class="cat-pair__col">
                    <span class="cat-pair__col-label">Actuel</span>
                    <div class="cat-detail__html cat-pair__html-current">
                        <?= $currentDescription !== '' ? $currentDescription : '<em style="color:var(--color-text-muted);">— (vide)</em>' ?>
                    </div>
                </div>
                <div class="cat-pair__col">
                    <span class="cat-pair__col-label">
                        Optimisé
                        <button type="button" class="copy-current-btn" data-copy-current data-target="desc-input" data-source-html-b64="<?= base64_encode($currentDescription) ?>" title="Copier le HTML actuel">↩ Copier l'actuel</button>
                        <span class="html-editor__tabs" data-html-editor="desc">
                            <button type="button" class="html-editor__tab html-editor__tab--active" data-mode="preview">👁 Aperçu</button>
                            <button type="button" class="html-editor__tab" data-mode="code">&lt;/&gt; Code</button>
                        </span>
                    </span>
                    <div class="html-editor" data-target="desc-input">
                        <div class="html-editor__preview cat-detail__html" data-preview="desc-input">
                            <?= $optDescription !== '' ? $optDescription : '<em style="color:var(--color-text-muted);">— (vide — clique sur Code pour rédiger)</em>' ?>
                        </div>
                        <textarea name="optimized_description" id="desc-input" rows="10" hidden placeholder="Description complète HTML..."><?= Renderer::escape($optDescription) ?></textarea>
                    </div>
                </div>
            </div>
        </section>

        <!-- Actions -->
        <div class="cat-detail__actions">
            <p style="margin:0; color: var(--color-text-muted); font-size: 12px;">
                ℹ Les cases cochées contrôlent à la fois la <strong>génération IA</strong> et le <strong>push PrestaShop</strong>.
            </p>
            <div style="display:flex; gap:8px;">
                <button type="submit"
                        formaction="/produits/<?= Renderer::escape($row['id']) ?>/save"
                        class="btn btn--secondary">Enregistrer</button>
                <button type="submit" class="btn btn--primary">✈ Pousser sur PrestaShop</button>
            </div>
        </div>
    </div>
</form>
</section>

<style>
/* Mêmes styles que la page catégorie (champs alignés + éditeur HTML) */
.cat-pairs { display: flex; flex-direction: column; gap: 16px; }
.cat-pair { background: var(--color-surface); border: 1px solid var(--color-border); border-radius: var(--radius); overflow: hidden; transition: opacity .15s; }
.cat-pair--disabled { opacity: 0.5; }
.cat-pair--disabled .cat-pair__col input,
.cat-pair--disabled .cat-pair__col textarea { background: var(--color-bg); }
.cat-pair__head { padding: 10px 14px; border-bottom: 1px solid var(--color-border); background: var(--color-bg); display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap: wrap; }
.cat-pair__toggle { display: flex; align-items: center; gap: 10px; margin: 0; cursor: pointer; user-select: none; }
.cat-pair__toggle input[type="checkbox"] { margin: 0; width: 16px; height: 16px; cursor: pointer; }
.cat-pair__name { font-weight: 600; font-size: 14px; }
.cat-pair__counter { font-size: 11px; color: var(--color-text-muted); }
.cat-pair__body { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; padding: 14px; }
.cat-pair__col { display: flex; flex-direction: column; gap: 6px; }
.cat-pair__col-label { font-size: 10px; text-transform: uppercase; letter-spacing: 0.6px; color: var(--color-text-muted); font-weight: 600; display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.cat-pair__col input, .cat-pair__col textarea { width: 100%; box-sizing: border-box; }
.cat-pair__html-current { max-height: 320px; overflow-y: auto; }
.cat-detail__actions { display: flex; justify-content: space-between; align-items: center; gap: 16px; flex-wrap: wrap; padding: 14px; background: var(--color-bg); border: 1px solid var(--color-border); border-radius: var(--radius); }
@media (max-width: 900px) { .cat-pair__body { grid-template-columns: 1fr; } }

.copy-current-btn { background: transparent; border: 1px solid var(--color-border); border-radius: 4px; padding: 3px 8px; font-size: 11px; cursor: pointer; color: var(--color-text); font-family: inherit; }
.copy-current-btn:hover { background: var(--color-bg); border-color: var(--color-text-muted); }
.copy-current-btn:active { transform: translateY(1px); }
.html-editor__tabs { display: inline-flex; border: 1px solid var(--color-border); border-radius: 4px; overflow: hidden; margin-left: auto; }
.html-editor__tab { background: transparent; border: 0; padding: 3px 8px; font-size: 11px; cursor: pointer; color: var(--color-text-muted); border-right: 1px solid var(--color-border); font-family: inherit; }
.html-editor__tab:last-child { border-right: 0; }
.html-editor__tab--active { background: var(--color-primary, #2563eb); color: white; }
.html-editor { border: 1px solid var(--color-border); border-radius: var(--radius); background: var(--color-surface); }
.html-editor__preview { padding: 12px; min-height: 160px; max-height: 480px; overflow-y: auto; background: white; }
.html-editor > textarea { width: 100%; box-sizing: border-box; border: 0; padding: 12px; min-height: 160px; font-family: ui-monospace, SFMono-Regular, monospace; font-size: 12px; resize: vertical; background: #fafafa; }
.html-editor > textarea:focus { outline: 2px solid var(--color-primary, #2563eb); outline-offset: -2px; }
</style>

<section class="prod-tab" data-tab="avis" hidden>
<!-- Bloc Avis produits (au-dessus de la galerie) -->
<?php
    $reviewsCount = $row['reviews_count'] !== null ? (int) $row['reviews_count'] : 0;
    $reviewsAvg = $row['reviews_avg'] !== null ? (float) $row['reviews_avg'] : 0.0;
    $reviewsSynced = $row['reviews_synced_at'] !== null;
    $defaultDateTo = date('Y-m-d');
    $defaultDateFrom = date('Y-m-d', strtotime('-180 days'));
?>
<div class="card" style="margin-top:24px;">
    <div class="card__header" style="display:flex; justify-content:space-between; align-items:center; gap:8px;">
        <h3 class="card__title">⭐ Avis produit</h3>
        <a href="/avis/<?= Renderer::escape($row['id']) ?>" class="btn btn--ghost btn--sm">↗ Voir / éditer les avis</a>
    </div>
    <div class="card__body">
        <!-- Stats actuelles -->
        <div style="display:flex; gap:24px; align-items:baseline; padding:12px 16px; background:var(--color-bg); border-radius:var(--radius); margin-bottom:16px;">
            <?php if (!$reviewsSynced): ?>
                <em style="color:var(--color-text-muted);">Stats avis pas encore synchronisées — lance "Synchroniser" depuis la liste produits.</em>
            <?php elseif ($reviewsCount === 0): ?>
                <em style="color:var(--color-text-muted);">Aucun avis sur ce produit pour l'instant.</em>
            <?php else: ?>
                <div>
                    <div style="font-size:11px; text-transform:uppercase; letter-spacing:0.6px; color:var(--color-text-muted); font-weight:600;">Nombre d'avis</div>
                    <div id="reviews-count-display" style="font-size:28px; font-weight:700;"><?= $reviewsCount ?></div>
                </div>
                <div>
                    <div style="font-size:11px; text-transform:uppercase; letter-spacing:0.6px; color:var(--color-text-muted); font-weight:600;">Note moyenne</div>
                    <div id="reviews-avg-display" style="font-size:28px; font-weight:700; color:#f59e0b;">★ <?= number_format($reviewsAvg, 2, ',', ' ') ?> / 5</div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Bloc Générer des avis -->
        <div class="ai-gen-panel" style="background: #faf5ff; border: 1px solid #d8b4fe; border-radius: var(--radius); padding: 14px;">
            <div style="display:flex; justify-content:space-between; align-items:center; gap:8px; margin-bottom:12px;">
                <strong style="font-size:14px; color:#6b21a8;">✨ Générer des avis automatiquement</strong>
                <span style="font-size:11px; color: var(--color-text-muted);">L'IA crée des avis variés et les pousse sur PrestaShop</span>
            </div>

            <div style="display:flex; flex-wrap:wrap; gap:10px; align-items:flex-end;">
                <label class="field" style="margin:0; flex:0 0 90px;">
                    <span class="field__label">Nombre</span>
                    <input type="number" id="gen-reviews-count" min="1" max="20" value="5">
                </label>
                <label class="field" style="margin:0; flex:0 0 150px;">
                    <span class="field__label">Date de</span>
                    <input type="date" id="gen-reviews-date-from" value="<?= Renderer::escape($defaultDateFrom) ?>">
                </label>
                <label class="field" style="margin:0; flex:0 0 150px;">
                    <span class="field__label">Date à</span>
                    <input type="date" id="gen-reviews-date-to" value="<?= Renderer::escape($defaultDateTo) ?>">
                </label>
                <label class="field" style="margin:0; flex:0 0 130px;">
                    <span class="field__label">Note moyenne</span>
                    <input type="number" id="gen-reviews-target-avg" min="1" max="5" step="0.1" value="4.5">
                </label>
                <label class="field" style="margin:0; flex:1 1 240px; min-width: 240px;">
                    <span class="field__label">Instructions (optionnel)</span>
                    <input type="text" id="gen-reviews-instructions" placeholder="Ex: insister sur la qualité, ton chaleureux...">
                </label>
                <button type="button" id="gen-reviews-btn" class="btn btn--primary btn--sm" style="background:#7c3aed; border-color:#7c3aed; flex:0 0 auto;">
                    <span class="gen-reviews-label">✨ Générer</span>
                    <span class="gen-reviews-spinner" hidden>Génération…</span>
                </button>
            </div>
            <div id="gen-reviews-result" class="ai-gen-result" hidden style="margin-top:10px;"></div>
        </div>
    </div>
</div>

</section>

<section class="prod-tab" data-tab="galerie" hidden>
<?php if (!empty($gallery_images)): ?>
    <div class="card" style="margin-top:24px;">
        <div class="card__header" style="display:flex;justify-content:space-between;align-items:center;">
            <h3 class="card__title">Galerie</h3>
            <span style="font-size:13px;color:var(--color-text-muted);"><?= count($gallery_images) ?> image<?= count($gallery_images) > 1 ? 's' : '' ?></span>
        </div>
        <div class="card__body">
            <p style="margin: 0 0 10px; font-size: 12px; color: var(--color-text-muted);">
                💡 <strong>Glisse-dépose</strong> les images pour réordonner. La nouvelle position est sauvegardée sur PrestaShop automatiquement.
            </p>
            <div class="product-gallery" id="product-gallery-sortable">
                <?php foreach ($gallery_images as $img): ?>
                    <div class="product-gallery__item" draggable="true" data-image-id="<?= (int) $img['id'] ?>">
                        <a href="<?= Renderer::escape($img['large_url']) ?>" target="_blank" rel="noopener" class="product-gallery__link">
                            <img src="<?= Renderer::escape($img['thumb_url']) ?>"
                                 alt="Image <?= (int) $img['id'] ?>"
                                 loading="lazy"
                                 draggable="false"
                                 onerror="this.parentElement.parentElement.style.display='none'">
                        </a>
                        <span class="product-gallery__handle" title="Glisse pour déplacer">⠿</span>
                    </div>
                <?php endforeach; ?>
            </div>
            <div id="gallery-reorder-status" style="margin-top:8px; font-size:12px; min-height:18px;"></div>
            <style>
            .product-gallery__item { position: relative; cursor: grab; user-select: none; transition: opacity .15s, transform .15s; }
            .product-gallery__item:active { cursor: grabbing; }
            .product-gallery__item.dragging { opacity: 0.4; }
            .product-gallery__item.drop-target { transform: scale(1.05); box-shadow: 0 0 0 3px #7c3aed; }
            .product-gallery__handle { position:absolute; top:4px; left:4px; background: rgba(0,0,0,.5); color:white; padding: 2px 6px; border-radius:4px; font-size:14px; line-height:1; pointer-events:none; }
            </style>

            <?php /* ---- Generateur d'image IA ---- */ ?>
            <div class="ai-image-panel" style="margin-top:24px; padding:14px; border:1px solid var(--color-border); border-radius:var(--radius); background:var(--color-bg);">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
                    <strong style="font-size:14px;">✨ Générer une variante d'image par IA</strong>
                    <span style="font-size:11px; color:var(--color-text-muted);">Kie.AI Nano Banana 2 · 1080×1080</span>
                </div>

                <?php if (!$kie_configured): ?>
                    <p style="margin:0; font-size:12px; color:var(--color-text-muted);">
                        🔑 Renseigne ta clé Kie.AI dans <a href="/settings?tab=ai-tools">Paramètres → Outils IA</a> pour activer.
                    </p>
                <?php else: ?>
                    <p style="margin:0 0 10px; font-size:12px; color:var(--color-text-muted);">
                        Coche 1 à 5 images source ci-dessus (clic) puis décris la variante voulue.
                    </p>

                    <div style="display:flex; gap:8px; flex-wrap:wrap; margin-bottom:8px;">
                        <?php foreach ($gallery_images as $i => $img): ?>
                            <label class="ai-img-thumb <?= $i === 0 ? 'ai-img-thumb--on' : '' ?>" title="Image #<?= (int) $img['id'] ?>">
                                <input type="checkbox" name="ai-src" value="<?= (int) $img['id'] ?>" <?= $i === 0 ? 'checked' : '' ?>>
                                <img src="<?= Renderer::escape($img['thumb_url']) ?>" alt="" loading="lazy">
                                <span class="ai-img-check">✓</span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <div id="ai-src-count" style="font-size:11px; color:var(--color-text-muted); margin-bottom:8px;">1 image source sélectionnée</div>

                    <label class="field" style="margin:0 0 10px;">
                        <span class="field__label">Prompt *</span>
                        <textarea id="ai-prompt" rows="3" placeholder="Ex: Photographie lifestyle posée sur une table en marbre blanc, lumière dorée du matin, ambiance cosy hivernale..."></textarea>
                    </label>
                    <div style="display:flex; justify-content:flex-end;">
                        <button type="button" id="ai-gen-btn" class="btn btn--primary btn--sm" style="background:#7c3aed;border-color:#7c3aed;">
                            <span class="btn-label">✨ Générer</span>
                            <span class="btn-spinner" hidden>Génération… (30-60s)</span>
                        </button>
                    </div>
                    <div id="ai-status" style="margin-top:10px; font-size:12px;"></div>

                    <div id="ai-result" hidden style="margin-top:14px;">
                        <a id="ai-result-link" href="#" target="_blank" rel="noopener">
                            <img id="ai-result-img" src="" alt="" style="max-width:100%; max-height:480px; border-radius:var(--radius); border:1px solid var(--color-border);">
                        </a>
                        <div style="margin-top:8px; display:flex; gap:6px; flex-wrap:wrap;">
                            <button type="button" id="ai-refine-toggle" class="btn btn--secondary btn--sm">✏ Modifier</button>
                            <button type="button" id="ai-push-btn" class="btn btn--primary btn--sm" style="background:#16a34a;border-color:#16a34a;">📥 Ajouter à la galerie Presta</button>
                            <a id="ai-download" href="#" download class="btn btn--ghost btn--sm">⬇ Télécharger</a>
                        </div>
                        <div id="ai-refine-panel" hidden style="margin-top:10px; padding:10px; background:#eff6ff; border:1px solid #bfdbfe; border-radius:var(--radius);">
                            <label class="field" style="margin:0 0 6px;">
                                <span class="field__label" style="font-size:12px;">Modification à appliquer</span>
                                <input type="text" id="ai-refine-prompt" placeholder="Ex: change le fond pour un mur en bois clair...">
                            </label>
                            <div style="display:flex; gap:6px;">
                                <button type="button" id="ai-refine-btn" class="btn btn--primary btn--sm" style="background:#2563eb;border-color:#2563eb;">Appliquer</button>
                                <button type="button" id="ai-refine-cancel" class="btn btn--ghost btn--sm">Annuler</button>
                            </div>
                        </div>
                    </div>

                    <style>
                    .ai-img-thumb { cursor:pointer; position:relative; border:2px solid var(--color-border); border-radius:6px; overflow:hidden; padding:0; display:block; transition:border-color .1s; }
                    .ai-img-thumb:hover { border-color:var(--color-text-muted); }
                    .ai-img-thumb--on { border-color:#7c3aed !important; box-shadow:0 0 0 2px rgba(124,58,237,.25); }
                    .ai-img-thumb input { position:absolute; opacity:0; pointer-events:none; }
                    .ai-img-thumb img { display:block; width:72px; height:72px; object-fit:cover; }
                    .ai-img-check { position:absolute; top:2px; right:2px; width:18px; height:18px; line-height:18px; text-align:center; background:#7c3aed; color:#fff; border-radius:50%; font-size:11px; font-weight:700; opacity:0; transform:scale(.7); transition:.1s; }
                    .ai-img-thumb--on .ai-img-check { opacity:1; transform:scale(1); }
                    </style>

                    <?php if (!empty($generations)): ?>
                        <details style="margin-top:18px;">
                            <summary style="cursor:pointer; font-size:13px; font-weight:600;">
                                Historique (<?= count($generations) ?>)
                            </summary>
                            <div style="margin-top:10px; display:grid; grid-template-columns:repeat(auto-fill, minmax(160px, 1fr)); gap:10px;">
                                <?php foreach ($generations as $g): ?>
                                    <div style="border:1px solid var(--color-border); border-radius:var(--radius); padding:6px; background:#fff;">
                                        <?php if ($g['status'] === 'success' && !empty($g['image_url'])): ?>
                                            <a href="<?= Renderer::escape($g['image_url']) ?>" target="_blank" rel="noopener">
                                                <img src="<?= Renderer::escape($g['image_url']) ?>" alt="" style="width:100%; aspect-ratio:1/1; object-fit:cover; border-radius:4px;">
                                            </a>
                                        <?php elseif ($g['status'] === 'pending'): ?>
                                            <div style="aspect-ratio:1/1; display:flex; align-items:center; justify-content:center; background:#f9fafb; border-radius:4px; font-size:12px; color:var(--color-text-muted);">⏳ En cours…</div>
                                        <?php else: ?>
                                            <div style="aspect-ratio:1/1; display:flex; align-items:center; justify-content:center; background:#fef2f2; color:#dc2626; border-radius:4px; font-size:11px; padding:6px; text-align:center;">❌ <?= Renderer::escape(mb_substr((string) $g['error_message'], 0, 60)) ?></div>
                                        <?php endif; ?>
                                        <p style="margin:6px 0 4px; font-size:11px; line-height:1.3; max-height:42px; overflow:hidden;" title="<?= Renderer::escape((string) $g['prompt']) ?>"><?= Renderer::escape(mb_substr((string) $g['prompt'], 0, 80)) ?></p>
                                        <div style="display:flex; gap:4px; align-items:center;">
                                            <?php if (!empty($g['pushed_image_id'])): ?>
                                                <span style="font-size:10px; background:#dcfce7; color:#166534; padding:1px 6px; border-radius:3px;" title="Pushed sur Presta">📥 #<?= (int) $g['pushed_image_id'] ?></span>
                                            <?php endif; ?>
                                            <form method="POST" action="/produits/<?= Renderer::escape($row['id']) ?>/images/<?= Renderer::escape($g['id']) ?>/delete" style="margin:0; margin-left:auto;" onsubmit="return confirm('Supprimer cette generation de l\'historique ?');">
                                                <input type="hidden" name="_csrf" value="<?= Renderer::escape($csrf_token) ?>">
                                                <button type="submit" class="btn btn--ghost btn--sm" style="padding:1px 6px; font-size:10px;">×</button>
                                            </form>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </details>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

        </div>
    </div>
<?php endif; ?>
</section>

<style>
.prod-tabs { display:flex; gap:4px; flex-wrap:wrap; border-bottom:2px solid var(--color-border); margin-bottom:20px; }
.prod-tabs__item { background:transparent; border:0; border-bottom:2px solid transparent; margin-bottom:-2px; padding:10px 16px; font-size:14px; font-weight:600; color:var(--color-text-muted); cursor:pointer; font-family:inherit; }
.prod-tabs__item:hover { color:var(--color-text); }
.prod-tabs__item--active { color:var(--color-primary, #2563eb); border-bottom-color:var(--color-primary, #2563eb); }
</style>

<script>
// Onglets fiche produit
(function () {
    const tabs = document.querySelectorAll('.prod-tabs__item');
    const panels = document.querySelectorAll('.prod-tab');
    tabs.forEach(function (btn) {
        btn.addEventListener('click', function () {
            const target = btn.dataset.tabTarget;
            tabs.forEach(t => t.classList.toggle('prod-tabs__item--active', t === btn));
            panels.forEach(p => { p.hidden = p.dataset.tab !== target; });
        });
    });

    // Etude de prix SerpApi
    const cpBtn = document.getElementById('cp-btn');
    if (cpBtn) {
        const lbl = cpBtn.querySelector('.btn-label');
        const spin = cpBtn.querySelector('.btn-spinner');
        const statusEl = document.getElementById('cp-status');
        const resultEl = document.getElementById('cp-result');
        const csrf = document.querySelector('input[name="_csrf"]')?.value || '';
        const productId = cpBtn.dataset.productId;
        const fmt = (v) => v === null || v === undefined ? '—' : new Intl.NumberFormat('fr-FR', {minimumFractionDigits:2, maximumFractionDigits:2}).format(v) + ' €';
        const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

        cpBtn.addEventListener('click', async function () {
            cpBtn.disabled = true; if (lbl) lbl.hidden = true; if (spin) spin.hidden = false;
            statusEl.textContent = ''; statusEl.style.color = 'var(--color-text-muted)';
            try {
                const fd = new FormData(); fd.append('_csrf', csrf);
                const res = await fetch('/produits/' + encodeURIComponent(productId) + '/compare-prices', { method:'POST', body:fd, headers:{'Accept':'application/json'} });
                const j = await res.json();
                if (!j.ok) { statusEl.style.color = '#dc2626'; statusEl.textContent = '❌ ' + (j.message || 'Erreur'); return; }
                const s = j.stats || {};
                let html = '<div style="padding:10px 12px; background:var(--color-bg); border-radius:var(--radius); font-size:13px; margin-bottom:10px;">' + esc(j.summary) + '</div>';
                html += '<div style="display:flex; gap:18px; flex-wrap:wrap; font-size:13px; margin-bottom:10px;">'
                     + '<span>Moy. <strong>' + fmt(s.avg_price_eur) + '</strong></span>'
                     + '<span>Min <strong>' + fmt(s.min_price_eur) + '</strong></span>'
                     + '<span>Max <strong>' + fmt(s.max_price_eur) + '</strong></span>'
                     + '<span>Médiane <strong>' + fmt(s.median_price_eur) + '</strong></span></div>';
                html += '<table class="cp-table"><thead><tr><th>Marchand</th><th>Produit</th><th class="catalog-table__num">Prix</th></tr></thead><tbody>';
                (j.results || []).forEach(function (r) {
                    const nm = r.url ? '<a href="' + esc(r.url) + '" target="_blank" rel="noopener">' + esc(r.name) + '</a>' : esc(r.name);
                    html += '<tr><td>' + esc(r.site) + '</td><td>' + nm + '</td><td class="catalog-table__num">' + (r.price_eur != null ? fmt(r.price_eur) : '—') + '</td></tr>';
                });
                html += '</tbody></table>';
                resultEl.innerHTML = html;
                resultEl.hidden = false;
                statusEl.style.color = '#16a34a'; statusEl.textContent = '✓ ' + (s.found_count || 0) + ' résultat(s).';
            } catch (err) {
                statusEl.style.color = '#dc2626'; statusEl.textContent = 'Erreur réseau : ' + err.message;
            } finally {
                cpBtn.disabled = false; if (lbl) lbl.hidden = false; if (spin) spin.hidden = true;
            }
        });
    }
})();
</script>

<script>
(function () {
    const mt = document.getElementById('mt-input');
    const md = document.getElementById('md-input');
    const mtCounter = document.getElementById('mt-counter');
    const mdCounter = document.getElementById('md-counter');

    function updateCounter(input, counter, target) {
        const len = input.value.length;
        counter.textContent = len + ' / ' + target;
        if (len > target) counter.style.color = '#dc2626';
        else if (len > target * 0.9) counter.style.color = '#d97706';
        else counter.style.color = 'var(--color-text-muted)';
    }
    mt.addEventListener('input', () => updateCounter(mt, mtCounter, 60));
    md.addEventListener('input', () => updateCounter(md, mdCounter, 155));
    updateCounter(mt, mtCounter, 60);
    updateCounter(md, mdCounter, 155);

    const btn = document.getElementById('ai-generate-btn');
    const label = btn.querySelector('.ai-gen-label');
    const spinner = btn.querySelector('.ai-gen-spinner');
    const instructionsEl = document.getElementById('ai-instructions');
    const wordCountEl = document.getElementById('ai-word-count');
    const resultEl = document.getElementById('ai-result');
    const descShortEl = document.getElementById('ds-input');
    const descEl = document.getElementById('desc-input');
    const csrf = document.querySelector('input[name="_csrf"]').value;
    const productId = <?= json_encode($row['id']) ?>;

    // --- Toggle visuel selon checkbox de chaque champ
    function isFieldEnabled(fieldName) {
        const cb = document.querySelector('[data-field-toggle="' + fieldName + '"]');
        return cb ? cb.checked : true;
    }
    document.querySelectorAll('[data-field-toggle]').forEach((cb) => {
        const fieldName = cb.dataset.fieldToggle;
        const section = document.querySelector('.cat-pair[data-field="' + fieldName + '"]');
        const sync = () => { if (section) section.classList.toggle('cat-pair--disabled', !cb.checked); };
        cb.addEventListener('change', sync); sync();
    });

    // --- Editeur HTML : toggle Aperçu / Code
    function refreshPreviewFor(textareaId) {
        const ta = document.getElementById(textareaId);
        const preview = document.querySelector('[data-preview="' + textareaId + '"]');
        if (!ta || !preview) return;
        const html = ta.value.trim();
        preview.innerHTML = html !== '' ? html : '<em style="color:var(--color-text-muted);">— (vide — clique sur Code pour rédiger)</em>';
    }
    document.querySelectorAll('.html-editor__tabs').forEach((tabs) => {
        const editorKey = tabs.dataset.htmlEditor; // "ds" ou "desc"
        const targetId = editorKey === 'ds' ? 'ds-input' : 'desc-input';
        const editorBox = document.querySelector('.html-editor[data-target="' + targetId + '"]');
        if (!editorBox) return;
        const preview = editorBox.querySelector('.html-editor__preview');
        const textarea = editorBox.querySelector('textarea');
        tabs.querySelectorAll('.html-editor__tab').forEach((b) => {
            b.addEventListener('click', () => {
                tabs.querySelectorAll('.html-editor__tab').forEach((bb) => bb.classList.remove('html-editor__tab--active'));
                b.classList.add('html-editor__tab--active');
                if (b.dataset.mode === 'preview') {
                    refreshPreviewFor(targetId);
                    preview.hidden = false; textarea.hidden = true;
                } else {
                    preview.hidden = true; textarea.hidden = false; textarea.focus();
                }
            });
        });
    });

    // --- Boutons "Copier l'actuel"
    document.querySelectorAll('[data-copy-current]').forEach((cBtn) => {
        cBtn.addEventListener('click', () => {
            const targetId = cBtn.dataset.target;
            const target = document.getElementById(targetId);
            if (!target) return;
            let value = '';
            if (cBtn.dataset.sourceHtmlB64 !== undefined) {
                try { value = atob(cBtn.dataset.sourceHtmlB64); } catch { value = ''; }
                try { value = decodeURIComponent(escape(value)); } catch {}
            } else {
                value = cBtn.dataset.sourceText || '';
            }
            target.value = value;
            if (target.tagName === 'TEXTAREA' && document.querySelector('[data-preview="' + targetId + '"]')) {
                refreshPreviewFor(targetId);
            }
            if (targetId === 'mt-input') updateCounter(mt, mtCounter, 60);
            if (targetId === 'md-input') updateCounter(md, mdCounter, 155);
            const original = cBtn.textContent;
            cBtn.textContent = '✓ Copié';
            setTimeout(() => { cBtn.textContent = original; }, 1200);
        });
    });

    btn.addEventListener('click', async function () {
        btn.disabled = true;
        label.hidden = true;
        spinner.hidden = false;
        resultEl.hidden = true;
        resultEl.className = 'ai-gen-result';

        const data = new FormData();
        data.append('_csrf', csrf);
        data.append('instructions', instructionsEl.value);
        data.append('word_count', wordCountEl.value || '300');

        try {
            const res = await fetch('/produits/' + encodeURIComponent(productId) + '/generate', { method: 'POST', body: data });
            const json = await res.json();

            if (!json.ok) {
                resultEl.classList.add('ai-gen-result--ko');
                let msg = json.message || 'Erreur inconnue.';
                if (json.raw) {
                    msg += '\n\n--- Réponse brute du modèle ---\n' + json.raw;
                }
                resultEl.textContent = msg;
                resultEl.style.whiteSpace = 'pre-wrap';
                resultEl.style.maxHeight = '300px';
                resultEl.style.overflowY = 'auto';
                resultEl.style.fontFamily = 'ui-monospace, monospace';
                resultEl.style.fontSize = '12px';
                resultEl.hidden = false;
                return;
            }

            // Remplit uniquement les champs dont la checkbox est cochée
            const skipped = [];
            const mkEl = document.getElementById('mk-input');
            if (isFieldEnabled('description_short')) { descShortEl.value = json.description_short || ''; refreshPreviewFor('ds-input'); } else { skipped.push('description courte'); }
            if (isFieldEnabled('description'))       { descEl.value = json.description || '';            refreshPreviewFor('desc-input'); } else { skipped.push('description longue'); }
            if (isFieldEnabled('meta_title'))        { mt.value = json.meta_title || ''; }                                                else { skipped.push('meta title'); }
            if (isFieldEnabled('meta_description'))  { md.value = json.meta_description || ''; }                                          else { skipped.push('meta description'); }
            if (mkEl && isFieldEnabled('meta_keywords')) { mkEl.value = json.meta_keywords || ''; }                                       else if (mkEl) { skipped.push('meta keywords'); }

            updateCounter(mt, mtCounter, 60);
            updateCounter(md, mdCounter, 155);

            const u = json.usage || {};
            resultEl.classList.add('ai-gen-result--ok');
            let msg = 'Généré (' + (u.model || '?') + ') · '
                + ((u.prompt_tokens || 0) + (u.completion_tokens || 0)) + ' tokens · '
                + (u.cost_eur || 0).toFixed(4) + ' €.';
            if (skipped.length > 0) msg += ' Champs ignorés (décochés) : ' + skipped.join(', ') + '.';
            msg += ' Éditez puis Enregistrez ou Poussez.';

            // Construit l'élément résultat avec en plus un <details> pour voir le prompt envoyé
            resultEl.innerHTML = '';
            const txtEl = document.createElement('div');
            txtEl.textContent = msg;
            resultEl.appendChild(txtEl);

            if (json.debug_system_prompt) {
                const det = document.createElement('details');
                det.style.marginTop = '8px';
                det.innerHTML = '<summary style="cursor:pointer; font-size:11px; color: var(--color-text-muted);">▾ Voir le prompt système envoyé à l\'IA (pour vérifier que tes instructions Paramètres → Champs sont bien dedans)</summary>'
                    + '<pre style="margin-top:6px; padding:8px; background:#f8fafc; border:1px solid var(--color-border); border-radius:4px; font-size:11px; max-height:400px; overflow:auto; white-space:pre-wrap; word-break:break-word;"></pre>';
                det.querySelector('pre').textContent = json.debug_system_prompt;
                resultEl.appendChild(det);
            }
            resultEl.hidden = false;
        } catch (err) {
            resultEl.classList.add('ai-gen-result--ko');
            resultEl.textContent = 'Erreur réseau : ' + err.message;
            resultEl.hidden = false;
        } finally {
            btn.disabled = false;
            label.hidden = false;
            spinner.hidden = true;
        }
    });

    // -------------------------------------------------------------------------
    // Générateur d'avis IA (avec dates encadrantes + note moyenne cible)
    // -------------------------------------------------------------------------
    const genReviewsBtn = document.getElementById('gen-reviews-btn');
    if (genReviewsBtn) {
        const grLabel = genReviewsBtn.querySelector('.gen-reviews-label');
        const grSpinner = genReviewsBtn.querySelector('.gen-reviews-spinner');
        const grResultEl = document.getElementById('gen-reviews-result');
        const grCountEl = document.getElementById('gen-reviews-count');
        const grDateFromEl = document.getElementById('gen-reviews-date-from');
        const grDateToEl = document.getElementById('gen-reviews-date-to');
        const grTargetAvgEl = document.getElementById('gen-reviews-target-avg');
        const grInstrEl = document.getElementById('gen-reviews-instructions');

        function grShow(state, msg) {
            grResultEl.className = 'ai-gen-result ai-gen-result--' + state;
            grResultEl.textContent = msg;
            grResultEl.hidden = false;
        }

        genReviewsBtn.addEventListener('click', async () => {
            const count = parseInt(grCountEl.value || '5', 10);
            if (count < 1 || count > 20) { alert('Nombre d\'avis entre 1 et 20.'); return; }

            const dateFrom = grDateFromEl.value;
            const dateTo = grDateToEl.value;
            if (!dateFrom || !dateTo) { alert('Renseigne les 2 dates.'); return; }
            if (dateFrom > dateTo) { alert('La date "de" doit être antérieure à la date "à".'); return; }

            const targetAvg = parseFloat((grTargetAvgEl.value || '4.5').replace(',', '.'));
            if (isNaN(targetAvg) || targetAvg < 1 || targetAvg > 5) { alert('Note moyenne entre 1.0 et 5.0.'); return; }

            genReviewsBtn.disabled = true;
            grLabel.hidden = true;
            grSpinner.hidden = false;
            grShow('ok', 'Génération en cours…');

            const fd = new FormData();
            fd.append('_csrf', csrf);
            fd.append('count', String(count));
            fd.append('date_from', dateFrom);
            fd.append('date_to', dateTo);
            fd.append('target_avg', String(targetAvg));
            fd.append('instructions', grInstrEl.value);

            try {
                const res = await fetch('/avis/' + encodeURIComponent(productId) + '/generate', {
                    method: 'POST', body: fd, headers: { 'Accept': 'application/json' },
                });
                const json = await res.json();
                if (!json.ok) {
                    grShow('ko', json.message || 'Erreur génération');
                } else {
                    const inserted = json.inserted || 0;
                    const updated = json.updated || 0;
                    grShow('ok', '✓ ' + inserted + ' avis inséré' + (inserted > 1 ? 's' : '')
                        + (updated > 0 ? ' (+ ' + updated + ' mis à jour)' : '')
                        + '. Note moyenne cible : ' + targetAvg.toFixed(1)
                        + (json.usage ? ' · ' + (json.usage.cost_eur || 0).toFixed(4) + ' €' : ''));

                    // Refresh des stats affichées sans recharger la page
                    if (json.new_stats) {
                        const newCount = parseInt(json.new_stats.count || 0, 10);
                        const newAvg = parseFloat(json.new_stats.avg_grade || 0);
                        const countDisp = document.getElementById('reviews-count-display');
                        const avgDisp = document.getElementById('reviews-avg-display');
                        if (countDisp) countDisp.textContent = newCount;
                        if (avgDisp) avgDisp.textContent = '★ ' + newAvg.toFixed(2).replace('.', ',') + ' / 5';
                    }
                }
            } catch (err) {
                grShow('ko', 'Erreur réseau : ' + err.message);
            } finally {
                genReviewsBtn.disabled = false;
                grLabel.hidden = false;
                grSpinner.hidden = true;
            }
        });
    }

    // -------------------------------------------------------------------------
    // Generateur d'image IA (Kie.AI Nano Banana 2)
    // -------------------------------------------------------------------------
    const aiBtn = document.getElementById('ai-gen-btn');
    if (aiBtn) {
        const aiPrompt = document.getElementById('ai-prompt');
        const aiStatus = document.getElementById('ai-status');
        const aiResult = document.getElementById('ai-result');
        const aiResultImg = document.getElementById('ai-result-img');
        const aiResultLink = document.getElementById('ai-result-link');
        const aiDownload = document.getElementById('ai-download');
        const aiPushBtn = document.getElementById('ai-push-btn');
        const aiRefineToggle = document.getElementById('ai-refine-toggle');
        const aiRefinePanel = document.getElementById('ai-refine-panel');
        const aiRefineBtn = document.getElementById('ai-refine-btn');
        const aiRefineCancel = document.getElementById('ai-refine-cancel');
        const aiRefinePrompt = document.getElementById('ai-refine-prompt');
        const aiLabel = aiBtn.querySelector('.btn-label');
        const aiSpinner = aiBtn.querySelector('.btn-spinner');
        const srcCountEl = document.getElementById('ai-src-count');
        let currentGenId = null;

        function aiShow(kind, msg) {
            aiStatus.textContent = msg;
            aiStatus.style.color = kind === 'ok' ? '#16a34a' : (kind === 'ko' ? '#dc2626' : 'var(--color-text-muted)');
        }
        function aiReset() {
            aiBtn.disabled = false; aiLabel.hidden = false; aiSpinner.hidden = true;
        }

        // Toggle visuel + compteur
        function updateCount() {
            const n = document.querySelectorAll('.ai-img-thumb input:checked').length;
            srcCountEl.textContent = n + ' image source' + (n > 1 ? 's' : '') + ' sélectionnée' + (n > 1 ? 's' : '');
        }
        document.querySelectorAll('.ai-img-thumb input').forEach((cb) => {
            cb.addEventListener('change', () => {
                const checked = document.querySelectorAll('.ai-img-thumb input:checked').length;
                if (checked > 5 && cb.checked) { cb.checked = false; alert('Maximum 5 images source.'); return; }
                if (checked === 0 && !cb.checked) { cb.checked = true; return; }
                cb.closest('.ai-img-thumb').classList.toggle('ai-img-thumb--on', cb.checked);
                updateCount();
            });
        });

        // Polling helper
        async function pollGeneration(genId) {
            aiShow('info', '⏳ Génération en cours… (30-60s)');
            const maxTries = 60; // 60 * 3s = 3min
            for (let i = 0; i < maxTries; i++) {
                await new Promise(r => setTimeout(r, 3000));
                try {
                    const res = await fetch('/produits/' + encodeURIComponent(productId) + '/images/' + encodeURIComponent(genId) + '/status', { headers: { 'Accept': 'application/json' } });
                    const j = await res.json();
                    if (j.state === 'success' && j.image_url) {
                        aiShow('ok', '✓ Image générée.');
                        aiResultImg.src = j.image_url;
                        aiResultLink.href = j.image_url;
                        aiDownload.href = j.image_url;
                        aiDownload.setAttribute('download', 'variante-' + Date.now() + '.png');
                        aiResult.hidden = false;
                        currentGenId = genId;
                        aiPushBtn.dataset.genId = genId;
                        aiReset();
                        return;
                    }
                    if (j.state === 'error') {
                        aiShow('ko', '❌ ' + (j.error || j.message || 'Échec'));
                        aiReset();
                        return;
                    }
                } catch (err) {
                    aiShow('ko', 'Erreur réseau (poll) : ' + err.message);
                    aiReset();
                    return;
                }
            }
            aiShow('ko', '⏱ Timeout 3 min. Va dans l\'historique pour voir si l\'image est arrivee.');
            aiReset();
        }

        // Submit
        aiBtn.addEventListener('click', async () => {
            const prompt = (aiPrompt.value || '').trim();
            if (!prompt) { aiShow('ko', 'Prompt obligatoire.'); return; }
            aiBtn.disabled = true; aiLabel.hidden = true; aiSpinner.hidden = false;
            aiResult.hidden = true;
            aiShow('info', 'Soumission…');

            const fd = new FormData();
            fd.append('_csrf', csrf);
            fd.append('prompt', prompt);
            document.querySelectorAll('.ai-img-thumb input:checked').forEach(cb => fd.append('image_ids[]', cb.value));

            try {
                const res = await fetch('/produits/' + encodeURIComponent(productId) + '/images/generate', { method: 'POST', body: fd, headers: { 'Accept': 'application/json' } });
                const j = await res.json();
                if (!j.ok) { aiShow('ko', j.message || 'Erreur'); aiReset(); return; }
                await pollGeneration(j.generation_id);
            } catch (err) {
                aiShow('ko', 'Erreur réseau : ' + err.message);
                aiReset();
            }
        });

        // Push to gallery
        aiPushBtn.addEventListener('click', async () => {
            const genId = aiPushBtn.dataset.genId || currentGenId;
            if (!genId) { alert('Aucune image à pousser.'); return; }
            aiPushBtn.disabled = true;
            const fd = new FormData(); fd.append('_csrf', csrf);
            try {
                const res = await fetch('/produits/' + encodeURIComponent(productId) + '/images/' + encodeURIComponent(genId) + '/add-to-gallery', { method: 'POST', body: fd, headers: { 'Accept': 'application/json' } });
                const j = await res.json();
                if (!j.ok) { aiShow('ko', j.message || 'Échec push'); aiPushBtn.disabled = false; return; }
                aiShow('ok', '✓ ' + j.message + ' Reload la page pour voir la galerie.');
            } catch (err) {
                aiShow('ko', 'Erreur réseau : ' + err.message);
                aiPushBtn.disabled = false;
            }
        });

        // Refine toggle
        aiRefineToggle.addEventListener('click', () => {
            aiRefinePanel.hidden = !aiRefinePanel.hidden;
            if (!aiRefinePanel.hidden) aiRefinePrompt.focus();
        });
        aiRefineCancel.addEventListener('click', () => { aiRefinePanel.hidden = true; });
        aiRefineBtn.addEventListener('click', async () => {
            const prompt = (aiRefinePrompt.value || '').trim();
            if (!prompt) { alert('Prompt de modification requis.'); return; }
            const genId = currentGenId;
            if (!genId) { alert('Aucune image source.'); return; }
            aiRefineBtn.disabled = true;
            aiShow('info', 'Soumission du raffinement…');
            const fd = new FormData();
            fd.append('_csrf', csrf);
            fd.append('prompt', prompt);
            try {
                const res = await fetch('/produits/' + encodeURIComponent(productId) + '/images/' + encodeURIComponent(genId) + '/refine', { method: 'POST', body: fd, headers: { 'Accept': 'application/json' } });
                const j = await res.json();
                aiRefineBtn.disabled = false;
                if (!j.ok) { aiShow('ko', j.message || 'Erreur'); return; }
                aiRefinePanel.hidden = true;
                aiRefinePrompt.value = '';
                await pollGeneration(j.generation_id);
            } catch (err) {
                aiShow('ko', 'Erreur réseau : ' + err.message);
                aiRefineBtn.disabled = false;
            }
        });
    }
})();
</script>
</div>

