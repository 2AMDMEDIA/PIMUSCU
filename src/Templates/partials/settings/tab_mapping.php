<?php
use App\Helpers\Renderer;

/**
 * @var string $csrf_token
 * @var array<string, list<array{key:string, label:string, hint?:string}>> $nutriweb_sources
 * @var array<string, list<array{key:string, label:string}>> $presta_destinations
 * @var array<string,string> $current_mapping
 * @var ?string $custom_fields_error
 * @var int $custom_fields_count
 * @var string $custom_fields_url
 * @var string $custom_fields_raw
 */
$custom_fields_error = $custom_fields_error ?? null;
$custom_fields_count = $custom_fields_count ?? 0;
$custom_fields_url = $custom_fields_url ?? '';
$custom_fields_raw = $custom_fields_raw ?? '';
// Masque la clé dans l'URL affichée (X-API-Key est dans le header, mais on masque aussi
// tout ?api_key= si jamais).
$maskUrl = function (string $u): string {
    return preg_replace_callback('/(api_key=)([^&]+)/', fn($m) => $m[1] . substr($m[2], 0, 6) . '***', $u) ?? $u;
};
?>
<div class="tab-content">
    <div class="card">
        <div class="card__header">
            <h3 class="card__title">🔀 Mapping Nutriweb → PrestaShop</h3>
        </div>
        <div class="card__body">
            <p style="font-size:13px; color:var(--color-text-muted); margin:0 0 12px;">
                Associe chaque champ du flux Nutriweb à un champ PrestaShop.
                Les correspondances définies ici seront utilisées pour peupler les champs à
                la création/mise à jour du produit (natif : produit / déclinaison ; ou champ personnalisé
                du module <code>aw_customproductfield</code>).
            </p>

            <?php if ($custom_fields_error !== null): ?>
                <div style="margin-bottom:16px; padding:10px 12px; background:#fef3c7; border:1px solid #fcd34d; border-radius:var(--radius); font-size:13px;">
                    ⚠ Champs custom <code>aw_customproductfield</code> non chargés : <?= Renderer::escape($custom_fields_error) ?>
                    <div style="font-size:12px; color:var(--color-text-muted); margin-top:4px;">
                        Le bloc « Champ custom » est absent tant que l'API n'est pas joignable.
                        Vérifie la clé dans <a href="/settings?tab=prestashop">Paramètres → PrestaShop</a>
                        et l'installation du module côté boutique.
                    </div>
                </div>
            <?php elseif ($custom_fields_count > 0): ?>
                <div style="margin-bottom:16px; padding:8px 12px; background:#f0fdf4; border:1px solid #86efac; border-radius:var(--radius); font-size:12px; color:#166534;">
                    ✓ <?= (int) $custom_fields_count ?> champ<?= $custom_fields_count > 1 ? 's' : '' ?> personnalisé<?= $custom_fields_count > 1 ? 's' : '' ?> chargé<?= $custom_fields_count > 1 ? 's' : '' ?> en direct depuis
                    <code>aw_customproductfield/api.php?action=schema</code>.
                </div>
            <?php elseif ($custom_fields_url !== ''): ?>
                <div style="margin-bottom:16px; padding:10px 12px; background:#fef3c7; border:1px solid #fcd34d; border-radius:var(--radius); font-size:13px;">
                    ⚠ L'API du module a répondu mais <strong>aucun champ n'a pu être extrait</strong>
                    (mon parser attend une clé <code>fields</code>, <code>schema</code>, <code>data</code>, <code>result</code>
                    ou un tableau direct, avec des items ayant <code>key</code>/<code>label</code>/<code>type</code>).
                    Regarde le JSON brut ci-dessous et envoie-le moi, j'ajusterai le parseur.
                </div>
            <?php endif; ?>

            <?php /* Bloc debug de l'appel API : URL + réponse brute (visible seulement si un appel a été fait). */ ?>
            <?php if ($custom_fields_url !== '' || $custom_fields_raw !== ''): ?>
                <details style="margin-bottom:16px; padding:8px 12px; background:#f9fafb; border:1px solid var(--color-border); border-radius:var(--radius); font-size:12px;">
                    <summary style="cursor:pointer; font-weight:600; color:var(--color-text-muted);">🐛 Debug appel aw_customproductfield (URL + payload)</summary>
                    <?php if ($custom_fields_url !== ''): ?>
                        <div style="margin-top:8px;">
                            <strong>URL appelée :</strong>
                            <code style="word-break:break-all; display:block; margin-top:4px;"><?= Renderer::escape($maskUrl($custom_fields_url)) ?></code>
                            <span style="color:var(--color-text-muted);">(header envoyé : <code>X-API-Key: ***</code>)</span>
                        </div>
                    <?php endif; ?>
                    <?php if ($custom_fields_raw !== ''): ?>
                        <div style="margin-top:8px;">
                            <strong>Réponse brute :</strong>
                            <pre style="background:#0f172a; color:#e2e8f0; padding:10px; border-radius:var(--radius); font-size:11px; line-height:1.4; max-height:320px; overflow:auto; white-space:pre-wrap;"><?php
                                $decoded = json_decode($custom_fields_raw, true);
                                echo Renderer::escape(is_array($decoded)
                                    ? json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                                    : $custom_fields_raw);
                            ?></pre>
                        </div>
                    <?php endif; ?>
                </details>
            <?php endif; ?>

            <form method="POST" action="/settings/mapping">
                <input type="hidden" name="_csrf" value="<?= Renderer::escape($csrf_token) ?>">

                <div style="overflow-x:auto;">
                    <table class="mapping-table">
                        <thead>
                            <tr>
                                <th style="width:45%;">Champ Nutriweb (source)</th>
                                <th style="width:55%;">Champ PrestaShop (destination)</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($nutriweb_sources as $groupName => $items): ?>
                            <tr class="mapping-table__group">
                                <td colspan="2"><?= Renderer::escape($groupName) ?></td>
                            </tr>
                            <?php foreach ($items as $it):
                                $current = (string) ($current_mapping[$it['key']] ?? '');
                            ?>
                                <tr>
                                    <td>
                                        <strong><?= Renderer::escape($it['label']) ?></strong>
                                        <div style="font-size:11px; color:var(--color-text-muted); margin-top:2px;">
                                            <code><?= Renderer::escape($it['key']) ?></code>
                                            <?php if (!empty($it['hint'])): ?>
                                                · <?= Renderer::escape($it['hint']) ?>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <select name="mapping[<?= Renderer::escape($it['key']) ?>]" class="mapping-select">
                                            <option value="">— non mappé —</option>
                                            <?php foreach ($presta_destinations as $destGroupName => $destItems): ?>
                                                <optgroup label="<?= Renderer::escape($destGroupName) ?>">
                                                    <?php foreach ($destItems as $d): ?>
                                                        <option value="<?= Renderer::escape($d['key']) ?>"
                                                            <?= $current === $d['key'] ? 'selected' : '' ?>>
                                                            <?= Renderer::escape($d['label']) ?>
                                                            <span style="color:#94a3b8;"> — <?= Renderer::escape($d['key']) ?></span>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </optgroup>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="form-actions" style="margin-top:16px;">
                    <button type="submit" class="btn btn--primary">Enregistrer le mapping</button>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
.mapping-table { width:100%; border-collapse:collapse; font-size:13px; }
.mapping-table th, .mapping-table td { padding:10px 12px; text-align:left; border-bottom:1px solid var(--color-border); vertical-align:top; }
.mapping-table thead th { background:var(--color-bg); font-weight:600; font-size:11px; text-transform:uppercase; letter-spacing:0.04em; color:var(--color-text-muted); }
.mapping-table__group td {
    background:#f1f5f9; color:var(--color-text-muted);
    font-weight:600; font-size:11px; text-transform:uppercase; letter-spacing:0.04em;
    padding:6px 12px;
}
.mapping-select { width:100%; padding:6px 8px; border:1px solid var(--color-border); border-radius:var(--radius); font-size:13px; background:var(--color-surface); }
</style>
