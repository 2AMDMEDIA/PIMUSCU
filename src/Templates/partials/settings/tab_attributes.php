<?php
use App\Helpers\Renderer;

/**
 * @var list<array{id:int, name:string, values:list<array{id:int, label:string}>}> $attribute_groups
 * @var ?string $attribute_error
 * @var ?list<int> $enabled_attribute_group_ids  Null = tous actifs (defaut)
 * @var string $csrf_token
 */
$enabledIds = $enabled_attribute_group_ids;
$allEnabledByDefault = $enabledIds === null;
$isEnabled = function (int $groupId) use ($enabledIds, $allEnabledByDefault): bool {
    if ($allEnabledByDefault) return true;
    return in_array($groupId, $enabledIds, true);
};
$enabledCount = $allEnabledByDefault ? count($attribute_groups) : count($enabledIds ?? []);
?>
<div class="card">
    <div class="card__header"><h3 class="card__title">Groupes d'attributs PrestaShop</h3></div>
    <div class="card__body">
        <p style="margin:0 0 16px; font-size:13px; color:var(--color-text-muted);">
            Coche les groupes d'attributs (Taille, Saveur, Couleur, etc.) qui seront proposés dans le formulaire de
            création de déclinaison sur <a href="/catalogue">Catalogue Nutriweb</a> → SKU non lié → <em>Créer en déclinaison</em>.
            Permet de masquer les groupes Presta non pertinents (Marque, Volume legacy, etc.).
            <br>
            <em>Si aucun choix sauvegardé : tous les groupes sont proposés par défaut.</em>
        </p>

        <?php if ($attribute_error !== null): ?>
            <div style="padding:12px; background:#fef2f2; border:1px solid #fecaca; border-radius:var(--radius); color:#991b1b; font-size:13px; margin-bottom:14px;">
                ❌ Impossible de récupérer les groupes depuis PrestaShop : <?= Renderer::escape($attribute_error) ?>
                <br><small>Vérifie ta clé API dans l'onglet PrestaShop.</small>
            </div>
        <?php elseif (empty($attribute_groups)): ?>
            <div class="empty-state">
                <div class="empty-state__title">Aucun groupe d'attribut détecté côté PrestaShop</div>
                <div class="empty-state__hint">Configure des groupes d'attributs dans PS admin → Catalogue → Attributs &amp; valeurs.</div>
            </div>
        <?php else: ?>
            <form method="POST" action="/settings/attributes">
                <input type="hidden" name="_csrf" value="<?= Renderer::escape($csrf_token) ?>">

                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px; padding:8px 12px; background:var(--color-bg); border-radius:var(--radius);">
                    <span style="font-size:12px; color:var(--color-text-muted);">
                        <strong><?= count($attribute_groups) ?></strong> groupe<?= count($attribute_groups) > 1 ? 's' : '' ?> Presta ·
                        <strong style="color:#16a34a;"><?= $enabledCount ?> actif<?= $enabledCount > 1 ? 's' : '' ?></strong>
                        <?php if ($allEnabledByDefault): ?> (par défaut)<?php endif; ?>
                    </span>
                    <span style="font-size:12px;">
                        <a href="#" id="select-all" style="color:var(--color-text-muted);">Tout cocher</a>
                        ·
                        <a href="#" id="select-none" style="color:var(--color-text-muted);">Tout décocher</a>
                    </span>
                </div>

                <table class="attr-groups-table">
                    <thead>
                        <tr>
                            <th style="width:50px;">Actif</th>
                            <th>Groupe</th>
                            <th>ID Presta</th>
                            <th>Nb valeurs</th>
                            <th>Exemples</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($attribute_groups as $g): ?>
                            <?php
                                $samples = array_slice($g['values'], 0, 3);
                                $sampleLabels = array_map(fn($v) => (string) $v['label'], $samples);
                                $moreCount = count($g['values']) - count($samples);
                            ?>
                            <tr>
                                <td style="text-align:center;">
                                    <input type="checkbox" name="group_ids[]" value="<?= (int) $g['id'] ?>" <?= $isEnabled($g['id']) ? 'checked' : '' ?>>
                                </td>
                                <td><strong><?= Renderer::escape($g['name']) ?></strong></td>
                                <td><code><?= (int) $g['id'] ?></code></td>
                                <td><?= count($g['values']) ?></td>
                                <td style="font-size:12px; color:var(--color-text-muted);">
                                    <?= Renderer::escape(implode(', ', $sampleLabels)) ?>
                                    <?php if ($moreCount > 0): ?> <em>+<?= $moreCount ?></em><?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <div class="form-actions" style="margin-top:14px; justify-content:flex-end;">
                    <button type="submit" class="btn btn--primary">Enregistrer</button>
                </div>
            </form>

            <style>
            .attr-groups-table { width:100%; border-collapse:collapse; font-size:13px; }
            .attr-groups-table th, .attr-groups-table td { padding:8px 12px; text-align:left; border-bottom:1px solid var(--color-border); }
            .attr-groups-table thead th { background:var(--color-bg); font-weight:600; font-size:11px; text-transform:uppercase; letter-spacing:0.04em; color:var(--color-text-muted); }
            .attr-groups-table tbody tr:hover { background:var(--color-bg); }
            </style>

            <script>
            (function () {
                const all = document.getElementById('select-all');
                const none = document.getElementById('select-none');
                const cbs = document.querySelectorAll('input[name="group_ids[]"]');
                if (all) all.addEventListener('click', function (e) { e.preventDefault(); cbs.forEach(cb => cb.checked = true); });
                if (none) none.addEventListener('click', function (e) { e.preventDefault(); cbs.forEach(cb => cb.checked = false); });
            })();
            </script>
        <?php endif; ?>
    </div>
</div>
