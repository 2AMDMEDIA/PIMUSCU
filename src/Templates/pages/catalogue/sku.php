<?php
use App\Helpers\Renderer;

/**
 * @var array<string,mixed> $row
 * @var string $sku
 * @var ?array<string,mixed> $nutrifacts_payload
 * @var ?string $nutrifacts_error
 * @var string $nutrifacts_url_masked
 */

// Cherche récursivement la première occurrence de la clé "nutrifacts" dans le payload
// pour éviter les suppositions sur la profondeur exacte (catalog[0].variants[0].nutrifacts, etc.).
$findNutrifacts = function ($node) use (&$findNutrifacts) {
    if (!is_array($node)) return null;
    if (isset($node['nutrifacts']) && (is_array($node['nutrifacts']) || is_string($node['nutrifacts']))) {
        return $node['nutrifacts'];
    }
    foreach ($node as $child) {
        if (is_array($child)) {
            $found = $findNutrifacts($child);
            if ($found !== null) return $found;
        }
    }
    return null;
};

$nutrifacts = $nutrifacts_payload !== null ? $findNutrifacts($nutrifacts_payload) : null;
$match = $row['match'] ?? null;

$fmtPrice = fn($v): string => $v === null ? '—' : number_format((float) $v, 2, ',', ' ') . ' €';
$fmtText = fn($v): string => $v === null || $v === '' ? '—' : Renderer::escape((string) $v);
?>
<div class="page-fullwidth">

<div class="page-header" style="display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
    <a href="/catalogue" class="btn btn--ghost btn--sm" title="Retour au catalogue">←</a>
    <div>
        <h2 class="page-header__title" style="margin:0;">
            SKU <code style="font-size:18px;"><?= Renderer::escape($sku) ?></code>
            <?php if (!empty($row['name'])): ?>
                <span style="font-weight:400; color:var(--color-text-muted); font-size:15px;">— <?= Renderer::escape((string) $row['name']) ?></span>
            <?php endif; ?>
        </h2>
    </div>
</div>

<div class="sku-shell" style="display:grid; grid-template-columns:1fr 1fr; gap:20px; align-items:flex-start;">

    <?php /* ============ Colonne gauche : détails SKU (cache local) ============ */ ?>
    <div class="card">
        <div class="card__header">
            <h3 class="card__title">📋 Détails Nutriweb (cache local)</h3>
        </div>
        <div class="card__body">
            <?php if (!empty($row['image_url'])): ?>
                <div style="text-align:center; margin-bottom:16px;">
                    <a href="<?= Renderer::escape((string) $row['image_url']) ?>" target="_blank" rel="noopener">
                        <img src="<?= Renderer::escape((string) $row['image_url']) ?>" alt="" style="max-width:220px; max-height:220px; border-radius:var(--radius); border:1px solid var(--color-border); background:#fff;">
                    </a>
                </div>
            <?php endif; ?>

            <dl class="sku-dl">
                <dt>SKU</dt>              <dd><code><?= Renderer::escape($sku) ?></code></dd>
                <dt>Nom</dt>              <dd><?= $fmtText($row['name'] ?? null) ?></dd>
                <dt>Marque</dt>           <dd><?= $fmtText($row['brand'] ?? null) ?></dd>
                <dt>Code-barres</dt>      <dd><?= !empty($row['barcode']) ? '<code>' . Renderer::escape((string) $row['barcode']) . '</code>' : '—' ?></dd>
                <dt>Taille</dt>           <dd><?= $fmtText($row['size'] ?? null) ?></dd>
                <dt>Couleur</dt>          <dd><?= $fmtText($row['color'] ?? null) ?></dd>
                <dt>Saveur</dt>           <dd><?= $fmtText($row['flavor'] ?? null) ?></dd>
                <dt>Stock</dt>            <dd><?= isset($row['stock']) && $row['stock'] !== null ? (int) $row['stock'] : '—' ?></dd>
                <dt>Prix base HT</dt>     <dd><?= $fmtPrice($row['price_base'] ?? null) ?></dd>
                <dt>Prix Achat HT</dt>    <dd><?= $fmtPrice($row['price_selling'] ?? null) ?></dd>
                <dt>Prix public TTC</dt>  <dd><?= $fmtPrice($row['price_retail'] ?? null) ?></dd>
                <dt>Prix d'achat amont</dt><dd><?= $fmtPrice($row['purchase_price'] ?? null) ?></dd>
                <dt>Permalink</dt>        <dd><?= $fmtText($row['permalink'] ?? null) ?></dd>
                <dt>Sync le</dt>          <dd><?= !empty($row['last_synced_at']) ? Renderer::escape(date('d/m/Y H:i', strtotime((string) $row['last_synced_at']))) : '—' ?></dd>
            </dl>

            <?php /* Match Presta */ ?>
            <div style="margin-top:14px; padding-top:14px; border-top:1px solid var(--color-border);">
                <strong style="font-size:13px;">🔗 Lien PrestaShop</strong>
                <?php if ($match !== null):
                    $isCombo = ($match['type'] ?? '') === 'combination';
                    $href = !empty($match['product_uuid']) ? '/produits/' . urlencode((string) $match['product_uuid']) : null;
                ?>
                    <div style="margin-top:6px; font-size:13px;">
                        <?php if ($href !== null): ?>
                            <a href="<?= Renderer::escape($href) ?>" target="_blank" rel="noopener">
                                <?= Renderer::escape((string) ($match['product_name'] ?? 'Produit'))
                                    ?><?php if ($isCombo && !empty($match['attributes'])): ?> (<?= Renderer::escape((string) $match['attributes']) ?>)<?php endif; ?>
                            </a>
                        <?php else: ?>
                            <span style="color:var(--color-text-muted);">Produit sans UUID en cache</span>
                        <?php endif; ?>
                        <div style="font-size:12px; color:var(--color-text-muted); margin-top:4px;">
                            ID Presta : <code>P#<?= (int) ($match['presta_id'] ?? 0) ?></code>
                            <?php if (!empty($match['presta_combination_id'])): ?> / <code>D#<?= (int) $match['presta_combination_id'] ?></code><?php endif; ?>
                            <?php if (!empty($match['reference'])): ?> · Réf <code><?= Renderer::escape((string) $match['reference']) ?></code><?php endif; ?>
                            <?php if (!empty($match['supplier_reference'])): ?> · Réf fournisseur <code><?= Renderer::escape((string) $match['supplier_reference']) ?></code><?php endif; ?>
                        </div>
                    </div>
                <?php else: ?>
                    <div style="margin-top:6px; font-size:13px; color:var(--color-text-muted);">
                        Aucun produit PrestaShop lié à ce SKU.
                        <a href="/catalogue/create?sku=<?= urlencode($sku) ?>">+ Créer</a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php /* ============ Colonne droite : nutrifacts (live Nutriweb) ============ */ ?>
    <div class="card">
        <div class="card__header">
            <h3 class="card__title">🥗 Nutrifacts <span style="font-size:12px; font-weight:400; color:var(--color-text-muted);">(live Nutriweb)</span></h3>
        </div>
        <div class="card__body">
            <?php if ($nutrifacts_error !== null): ?>
                <div style="padding:10px 12px; background:#fef2f2; border:1px solid #fecaca; border-radius:var(--radius); font-size:13px; color:#991b1b;">
                    ❌ <?= Renderer::escape($nutrifacts_error) ?>
                </div>
            <?php elseif ($nutrifacts === null && $nutrifacts_payload === null): ?>
                <p style="color:var(--color-text-muted); font-size:13px;">Pas de réponse Nutriweb.</p>
            <?php elseif ($nutrifacts === null): ?>
                <p style="color:var(--color-text-muted); font-size:13px;">
                    Aucune clé <code>nutrifacts</code> trouvée dans la réponse. Payload brut ci-dessous :
                </p>
            <?php elseif (is_string($nutrifacts)): ?>
                <div style="font-size:13px; white-space:pre-wrap;"><?= Renderer::escape($nutrifacts) ?></div>
            <?php else:
                $tr = $nutrifacts['translations'] ?? [];
                $forHeader = (string) ($tr['nutrifacts_for_x'] ?? ('Valeurs pour ' . ($nutrifacts['nutrifacts_for'] ?? '')));
                $riHeader = (string) ($tr['ri'] ?? 'AR');
                $riLong = (string) ($tr['ri_long'] ?? '');
                $macro = $nutrifacts['macro'] ?? null;
                $ingredient = trim((string) ($nutrifacts['ingredient'] ?? ''));
                $allergen = trim((string) ($nutrifacts['allergen'] ?? ''));
                $warnings = trim((string) ($nutrifacts['warnings'] ?? ''));
                $info = $nutrifacts['info'] ?? [];
                // Sanitize : autorise <b>/<strong>/<em>/<br> (l'API renvoie du HTML pour les allergènes en gras).
                $safeHtml = fn(string $s): string => strip_tags($s, '<b><strong><em><br>');

                // Rendu délégué à App\Helpers\NutrifactsRenderer (partagé avec le push mapping,
                // pour que le HTML poussé côté Presta soit identique à celui affiché ici).
                $macroMicroHtml = \App\Helpers\NutrifactsRenderer::renderMacroAndMicro($nutrifacts);
                $micro = $nutrifacts['micro'] ?? null;
            ?>
                <?php if ($macroMicroHtml !== ''): ?>
                    <div style="overflow-x:auto;">
                        <?= $macroMicroHtml ?>
                    </div>
                <?php else: ?>
                    <p style="color:var(--color-text-muted); font-size:13px;">Pas de bloc <code>macro</code> exploitable dans la réponse.</p>
                <?php endif; ?>

                <?php if (!empty($info) && is_array($info)): ?>
                    <div style="margin-top:14px; display:flex; flex-wrap:wrap; gap:8px;">
                        <?php foreach ($info as $item):
                            if (!is_array($item)) continue;
                            $lbl = trim((string) ($item['label'] ?? ''));
                            $val = $item['formatted'] ?? $item['value'] ?? '';
                            if ($lbl === '' || $val === '') continue;
                        ?>
                            <div style="padding:6px 10px; background:#f8fafc; border:1px solid var(--color-border); border-radius:var(--radius); font-size:12px;">
                                <span style="color:var(--color-text-muted);"><?= Renderer::escape($lbl) ?> :</span>
                                <strong><?= Renderer::escape((string) $val) ?></strong>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php if ($ingredient !== ''): ?>
                    <div style="margin-top:16px; font-size:13px;">
                        <strong><?= Renderer::escape((string) ($tr['ingredient'] ?? 'Ingrédients')) ?> :</strong>
                        <div style="margin-top:4px; color:var(--color-text-muted);"><?= $safeHtml($ingredient) ?></div>
                    </div>
                <?php endif; ?>

                <?php if ($allergen !== ''): ?>
                    <div style="margin-top:12px; font-size:13px;">
                        <strong><?= Renderer::escape((string) ($tr['allergen'] ?? 'Allergènes')) ?> :</strong>
                        <div style="margin-top:4px; color:var(--color-text-muted);"><?= $safeHtml($allergen) ?></div>
                    </div>
                <?php endif; ?>

                <?php if ($warnings !== ''): ?>
                    <div style="margin-top:12px; padding:8px 12px; background:#fef9c3; border:1px solid #fde68a; border-radius:var(--radius); font-size:12px; color:#92400e;">
                        <strong>⚠ <?= Renderer::escape((string) ($tr['warnings'] ?? 'Avertissements')) ?> :</strong>
                        <?= Renderer::escape($warnings) ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <?php if ($nutrifacts_payload !== null): ?>
                <details style="margin-top:14px; font-size:12px;">
                    <summary style="cursor:pointer; color:var(--color-text-muted);">🐛 Réponse Nutriweb brute (JSON)</summary>
                    <?php if ($nutrifacts_url_masked !== ''): ?>
                        <div style="margin:6px 0; font-size:11px; color:var(--color-text-muted);">
                            URL appelée : <code style="word-break:break-all;"><?= Renderer::escape($nutrifacts_url_masked) ?></code>
                        </div>
                    <?php endif; ?>
                    <pre style="background:#0f172a; color:#e2e8f0; padding:10px; border-radius:var(--radius); font-size:11px; line-height:1.4; max-height:400px; overflow:auto; white-space:pre-wrap;"><?= Renderer::escape(json_encode($nutrifacts_payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre>
                </details>
            <?php endif; ?>
        </div>
    </div>

</div>
</div>

<style>
.sku-dl { display:grid; grid-template-columns:150px 1fr; gap:4px 12px; font-size:13px; margin:0; }
.sku-dl dt { color:var(--color-text-muted); font-weight:500; }
.sku-dl dd { margin:0; }
.nutrifacts-table { width:100%; border-collapse:collapse; font-size:13px; }
.nutrifacts-table th, .nutrifacts-table td { padding:6px 10px; text-align:left; border-bottom:1px solid var(--color-border); }
.nutrifacts-table thead th { background:var(--color-bg); font-weight:600; font-size:11px; text-transform:uppercase; letter-spacing:0.04em; color:var(--color-text-muted); }
.nutrifacts-table tbody th, .nutrifacts-table tbody td { font-weight:normal; color:var(--color-text); vertical-align:top; }
.nutrifacts-table__num { text-align:right; font-variant-numeric:tabular-nums; white-space:nowrap; }
@media (max-width: 1000px) {
    .sku-shell { grid-template-columns:1fr; }
}
</style>
