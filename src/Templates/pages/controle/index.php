<?php
use App\Helpers\Renderer;

/**
 * @var ?int $supplier_id
 * @var ?string $control_error
 * @var list<array{id:?string, presta_id:int, name:string, reference:string, supplier_reference:string, nb_combinations:int}> $supplier_ref_misplaced
 * @var list<string> $sql_queue
 */
?>
<div class="page-fullwidth">
<div class="page-header">
    <div>
        <h2 class="page-header__title">Contrôle</h2>
        <p class="page-header__subtitle">Contrôles qualité au niveau des produits / déclinaisons.</p>
    </div>
</div>

<div class="controle-layout">
    <div class="controle-main">
        <?php /* ============ Contrôle #1 : ref fournisseur au niveau produit, pas décli ============ */ ?>
        <?php $count1 = count($supplier_ref_misplaced); ?>
        <div class="card" style="margin-bottom:20px;">
            <div class="card__header" style="display:flex; justify-content:space-between; align-items:center; gap:8px;">
                <h3 class="card__title">
                    🏭 Réf fournisseur au niveau produit sur un produit à déclinaisons
                    <span class="badge <?= $count1 > 0 ? 'badge--amber' : 'badge--green' ?>" style="margin-left:8px;">
                        <?= $count1 ?>
                    </span>
                </h3>
            </div>
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
                <?php elseif ($count1 === 0): ?>
                    <div style="padding:10px 12px; background:#f0fdf4; border:1px solid #86efac; border-radius:var(--radius); font-size:13px; color:#166534;">
                        ✓ Aucune anomalie détectée. Pense à lancer <a href="/produits">Produits → Synchroniser</a> pour rafraîchir.
                    </div>
                <?php else: ?>
                    <div style="overflow-x:auto;">
                        <table class="controle-table">
                            <thead>
                                <tr>
                                    <th>Produit</th>
                                    <th>ID Presta</th>
                                    <th>Réf produit</th>
                                    <th>Réf fournisseur (produit)</th>
                                    <th class="controle-table__num">Nb déclinaisons</th>
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
                                        <td><code>P#<?= (int) $p['presta_id'] ?></code></td>
                                        <td><?= $p['reference'] !== '' ? '<code>' . Renderer::escape($p['reference']) . '</code>' : '<span style="color:var(--color-text-muted);">—</span>' ?></td>
                                        <td><code><?= Renderer::escape($p['supplier_reference']) ?></code></td>
                                        <td class="controle-table__num"><?= (int) $p['nb_combinations'] ?></td>
                                        <td style="text-align:right;">
                                            <form method="POST" action="/controle/fix-supplier-ref" style="margin:0;">
                                                <input type="hidden" name="_csrf" value="<?= Renderer::escape(\App\Helpers\Csrf::token()) ?>">
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
    </div>

    <?php /* ============ Bloc latéral : requêtes SQL à jouer manuellement ============ */ ?>
    <?php $sqlCount = count($sql_queue); ?>
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
                        <input type="hidden" name="_csrf" value="<?= Renderer::escape(\App\Helpers\Csrf::token()) ?>">
                        <button type="submit" class="btn btn--secondary btn--sm">🗑️ Vider</button>
                    </form>
                <?php endif; ?>
            </div>
            <div class="card__body">
                <?php if ($sqlCount === 0): ?>
                    <p style="margin:0; font-size:13px; color:var(--color-text-muted);">
                        Clique sur <strong>« Générer SQL »</strong> dans le tableau pour empiler ici les requêtes
                        <code>DELETE</code> correspondantes. Tu les copieras ensuite pour les jouer toi-même en base.
                    </p>
                <?php else: ?>
                    <p style="margin:0 0 10px; font-size:12px; color:var(--color-text-muted);">
                        Copie ces requêtes et joue-les en base (phpMyAdmin). ⚠ Vérifie le préfixe de table
                        (<code>ps_</code> par défaut) avant exécution.
                    </p>
                    <textarea id="sqlQueue" readonly rows="<?= max(4, min(20, $sqlCount + 1)) ?>"
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
.controle-layout { display:flex; gap:20px; align-items:flex-start; }
.controle-main { flex:1; min-width:0; }
.controle-sql { width:400px; flex-shrink:0; position:sticky; top:20px; }
@media (max-width: 1100px) {
    .controle-layout { flex-direction:column; }
    .controle-sql { width:100%; position:static; }
}
.controle-table { width:100%; border-collapse:collapse; font-size:13px; }
.controle-table th, .controle-table td { padding:8px 12px; text-align:left; border-bottom:1px solid var(--color-border); }
.controle-table thead th { background:var(--color-bg); font-weight:600; font-size:11px; text-transform:uppercase; letter-spacing:0.04em; color:var(--color-text-muted); }
.controle-table tbody tr:hover { background:var(--color-bg); }
.controle-table__num { text-align:right; font-variant-numeric:tabular-nums; }
</style>
