<?php
use App\Helpers\Renderer;

/**
 * @var list<array<string,mixed>> $rows
 * @var list<array{row:array<string,mixed>,depth:int}> $tree
 * @var array{all:int,complete:int,short:int,empty:int} $counts
 * @var array{empty_max:int,short_max:int} $thresholds
 * @var bool $has_api_key
 * @var string $csrf_token
 */
$activeFilter = $_GET['filter'] ?? 'all';
?>
<div class="page-header">
    <div>
        <h2 class="page-header__title">Catégories</h2>
        <p class="page-header__subtitle"><?= $counts['all'] ?> catégorie<?= $counts['all'] > 1 ? 's' : '' ?></p>
    </div>
    <div style="display:flex;gap:8px;">
        <?php if ($has_api_key): ?>
            <form method="POST" action="/categories/sync" style="margin:0;">
                <input type="hidden" name="_csrf" value="<?= Renderer::escape($csrf_token) ?>">
                <button type="submit" class="btn btn--primary">Synchroniser</button>
            </form>
        <?php else: ?>
            <a href="/settings?tab=prestashop" class="btn btn--primary">Configurer Presta</a>
        <?php endif; ?>
    </div>
</div>

<?php if (empty($rows)): ?>
    <div class="card">
        <div class="card__body">
            <div class="empty-state">
                <div class="empty-state__title">Aucune catégorie synchronisée</div>
                <div class="empty-state__hint">
                    <?php if (!$has_api_key): ?>
                        <a href="/settings?tab=prestashop">Configurez votre clé API PrestaShop</a>
                        avant de synchroniser.
                    <?php else: ?>
                        Cliquez sur <strong>Synchroniser</strong> pour récupérer les catégories de la boutique.
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
<?php else: ?>
    <div class="filter-pills" style="margin-bottom: 16px;">
        <a href="/categories?filter=all" class="filter-pill <?= $activeFilter === 'all' ? 'filter-pill--active' : '' ?>">Toutes (<?= $counts['all'] ?>)</a>
        <a href="/categories?filter=complete" class="filter-pill <?= $activeFilter === 'complete' ? 'filter-pill--active' : '' ?>">✓ Complètes (<?= $counts['complete'] ?>)</a>
        <a href="/categories?filter=short" class="filter-pill <?= $activeFilter === 'short' ? 'filter-pill--active' : '' ?>">⚠ Courtes (<?= $counts['short'] ?>)</a>
        <a href="/categories?filter=empty" class="filter-pill <?= $activeFilter === 'empty' ? 'filter-pill--active' : '' ?>">✕ Vides (<?= $counts['empty'] ?>)</a>
        <span style="border-left:1px solid var(--color-border); margin: 0 4px;"></span>
        <a href="/categories?filter=optimized" class="filter-pill <?= $activeFilter === 'optimized' ? 'filter-pill--active' : '' ?>">✨ Optimisées (<?= $counts['optimized'] ?>)</a>
        <a href="/categories?filter=not_optimized" class="filter-pill <?= $activeFilter === 'not_optimized' ? 'filter-pill--active' : '' ?>">⊘ Pas encore (<?= $counts['not_optimized'] ?>)</a>
    </div>

    <div class="card">
        <div class="card__body" style="padding: 0;">
            <!-- Barre d'actions multi-sélection (apparait quand ≥1 case cochée) -->
            <div id="bulk-bar" class="bulk-bar" hidden>
                <div class="bulk-bar__info">
                    <span id="bulk-count">0</span> catégorie<span id="bulk-count-s"></span> sélectionnée<span id="bulk-count-s2"></span>
                </div>
                <div class="bulk-bar__actions">
                    <button type="button" id="bulk-clear" class="btn btn--ghost btn--sm">Désélectionner</button>
                    <button type="button" id="bulk-generate-btn" class="btn btn--primary btn--sm" style="background:#7c3aed;border-color:#7c3aed;">
                        ✨ Générer en masse (sélection uniquement)
                    </button>
                </div>
                <div id="bulk-progress" hidden style="flex-basis:100%; margin-top:8px;">
                    <div class="bulk-bar__progress-track">
                        <div id="bulk-progress-fill" class="bulk-bar__progress-fill" style="width:0%"></div>
                    </div>
                    <small id="bulk-progress-label" style="color:var(--color-text-muted); font-size: 11px;">Préparation…</small>
                </div>
            </div>

            <table class="table">
                <thead>
                    <tr>
                        <th style="width:32px;"><input type="checkbox" id="bulk-toggle-all" title="Tout sélectionner / désélectionner"></th>
                        <th>Catégorie</th>
                        <th>Meta title</th>
                        <th>Produits</th>
                        <th>Statut</th>
                        <th>Description</th>
                        <th>Optimisé</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($tree as $node):
                        $row = $node['row'];
                        $depth = $node['depth'];
                        $descLen = mb_strlen(strip_tags((string) ($row['description'] ?? '')));
                        if ($descLen <= $thresholds['empty_max']) {
                            $descBadge = ['emoji' => '✕', 'color' => 'red', 'label' => 'Vide'];
                        } elseif ($descLen <= $thresholds['short_max']) {
                            $descBadge = ['emoji' => '⚠', 'color' => 'amber', 'label' => 'Courte'];
                        } else {
                            $descBadge = ['emoji' => '✓', 'color' => 'green', 'label' => 'Complète'];
                        }
                        $descTooltip = $descLen . ' caractères';

                        // Détection des champs optimisés (non vides) pour cette catégorie
                        $optFields = [];
                        if (!empty($row['optimized_description']))            $optFields[] = 'description';
                        if (!empty($row['optimized_meta_title']))             $optFields[] = 'meta title';
                        if (!empty($row['optimized_meta_description']))       $optFields[] = 'meta description';
                        if (!empty($row['optimized_meta_keywords']))          $optFields[] = 'mots-clés';
                        $isOptimized = !empty($optFields);
                        $optCount = count($optFields);
                        $optTooltip = $isOptimized
                            ? $optCount . ' champ' . ($optCount > 1 ? 's' : '') . ' optimisé' . ($optCount > 1 ? 's' : '') . ' : ' . implode(', ', $optFields)
                                . (!empty($row['optimized_at']) ? "\nDernière optimisation : " . $row['optimized_at'] : '')
                            : 'Aucun champ optimisé localement';

                        // Filtrage côté affichage
                        if ($activeFilter !== 'all') {
                            $matches = ($activeFilter === 'complete' && $descLen > $thresholds['short_max'])
                                || ($activeFilter === 'short' && $descLen > $thresholds['empty_max'] && $descLen <= $thresholds['short_max'])
                                || ($activeFilter === 'empty' && $descLen <= $thresholds['empty_max'])
                                || ($activeFilter === 'optimized' && $isOptimized)
                                || ($activeFilter === 'not_optimized' && !$isOptimized);
                            if (!$matches) continue;
                        }
                    ?>
                        <tr data-row-id="<?= Renderer::escape((string) $row['id']) ?>" data-row-url="/categories/<?= Renderer::escape((string) $row['id']) ?>" class="cat-row">
                            <td class="cat-row__checkbox-cell" onclick="event.stopPropagation();">
                                <input type="checkbox" class="bulk-row-checkbox" value="<?= Renderer::escape((string) $row['id']) ?>" data-name="<?= Renderer::escape((string) $row['name']) ?>">
                            </td>
                            <td>
                                <span style="display:inline-block;padding-left:<?= $depth * 20 ?>px;">
                                    <?php if ($depth > 0): ?>
                                        <span style="color:#cbd5e1;">↳</span>
                                    <?php endif; ?>
                                    <?= Renderer::escape((string) $row['name']) ?>
                                </span>
                            </td>
                            <td style="color:var(--color-text-muted);font-size:13px;max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                                <?= Renderer::escape((string) ($row['meta_title'] ?? '')) ?: '—' ?>
                            </td>
                            <td><?= (int) $row['products_count'] ?></td>
                            <td>
                                <?php if ((int) $row['active'] === 1): ?>
                                    <span class="badge badge--blue">Actif</span>
                                <?php else: ?>
                                    <span class="badge badge--gray">Inactif</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge badge--<?= Renderer::escape($descBadge['color']) ?>" title="<?= Renderer::escape($descTooltip) ?>">
                                    <?= Renderer::escape($descBadge['emoji']) ?> <?= Renderer::escape($descBadge['label']) ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($isOptimized): ?>
                                    <span class="badge badge--green" title="<?= Renderer::escape($optTooltip) ?>">
                                        ✨ <?= $optCount ?>/4
                                    </span>
                                <?php else: ?>
                                    <span class="badge badge--gray" title="<?= Renderer::escape($optTooltip) ?>">
                                        —
                                    </span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <style>
    .cat-row { cursor: pointer; }
    .cat-row:hover { background: var(--color-bg); }
    .cat-row__checkbox-cell { cursor: default; text-align: center; }
    .bulk-bar { display: flex; align-items: center; justify-content: space-between; gap: 16px; padding: 10px 16px; background: #fef3c7; border: 1px solid #fcd34d; border-radius: var(--radius); margin-bottom: 12px; flex-wrap: wrap; position: sticky; top: 0; z-index: 5; }
    .bulk-bar[hidden] { display: none; }
    .bulk-bar__info { font-size: 13px; color: #78350f; font-weight: 600; }
    .bulk-bar__actions { display: flex; gap: 8px; }
    .bulk-bar__progress-track { height: 6px; background: rgba(120, 53, 15, 0.15); border-radius: 3px; overflow: hidden; }
    .bulk-bar__progress-fill { height: 100%; background: #7c3aed; transition: width .2s; }
    </style>

    <script>
    (function () {
        const toggleAll = document.getElementById('bulk-toggle-all');
        const rowCbs = () => document.querySelectorAll('.bulk-row-checkbox');
        const bar = document.getElementById('bulk-bar');
        const countEl = document.getElementById('bulk-count');
        const countSEl1 = document.getElementById('bulk-count-s');
        const countSEl2 = document.getElementById('bulk-count-s2');
        const clearBtn = document.getElementById('bulk-clear');
        const generateBtn = document.getElementById('bulk-generate-btn');
        const progressEl = document.getElementById('bulk-progress');
        const progressFillEl = document.getElementById('bulk-progress-fill');
        const progressLabelEl = document.getElementById('bulk-progress-label');

        function syncBar() {
            const selected = Array.from(rowCbs()).filter(cb => cb.checked);
            const n = selected.length;
            bar.hidden = n === 0;
            countEl.textContent = n;
            const plural = n > 1 ? 's' : '';
            countSEl1.textContent = plural;
            countSEl2.textContent = plural;
            // état "indeterminate" du toggle-all si sélection partielle
            const total = rowCbs().length;
            toggleAll.indeterminate = n > 0 && n < total;
            toggleAll.checked = n === total && total > 0;
        }

        toggleAll.addEventListener('change', () => {
            const check = toggleAll.checked;
            rowCbs().forEach(cb => { cb.checked = check; });
            syncBar();
        });

        document.querySelectorAll('.cat-row').forEach(row => {
            // Clic sur la ligne (sauf cellule checkbox) → ouvre le détail
            row.addEventListener('click', (e) => {
                if (e.target.closest('.cat-row__checkbox-cell')) return;
                const url = row.dataset.rowUrl;
                if (url) window.location.href = url;
            });
        });

        rowCbs().forEach(cb => cb.addEventListener('change', syncBar));

        clearBtn.addEventListener('click', () => {
            rowCbs().forEach(cb => { cb.checked = false; });
            toggleAll.checked = false;
            toggleAll.indeterminate = false;
            syncBar();
        });

        generateBtn.addEventListener('click', async () => {
            const selected = Array.from(rowCbs()).filter(cb => cb.checked);
            if (selected.length === 0) return;
            if (!confirm('Générer ' + selected.length + ' catégorie' + (selected.length > 1 ? 's' : '') + ' par IA ?\nChaque génération prend 10-30s. Tu peux laisser tourner.')) return;

            generateBtn.disabled = true;
            clearBtn.disabled = true;
            toggleAll.disabled = true;
            progressEl.hidden = false;
            const csrf = '<?= Renderer::escape($csrf_token) ?>';

            let done = 0;
            let success = 0;
            let failed = 0;
            for (const cb of selected) {
                const id = cb.value;
                const name = cb.dataset.name;
                progressLabelEl.textContent = 'Traitement (' + (done + 1) + '/' + selected.length + ') : ' + name + ' …';

                try {
                    // 1) Génération IA
                    const fd = new FormData();
                    fd.append('_csrf', csrf);
                    fd.append('word_count', '200');
                    const gen = await fetch('/categories/' + encodeURIComponent(id) + '/generate', { method: 'POST', body: fd });
                    const genJson = await gen.json();
                    if (!genJson.ok) throw new Error(genJson.message || 'Génération échouée');

                    // 2) Sauvegarde via /save
                    //    Note : on n'envoie PAS optimized_name en bulk pour éviter de renommer
                    //    accidentellement des catégories. À régénérer manuellement par catégorie.
                    const sd = new FormData();
                    sd.append('_csrf', csrf);
                    sd.append('optimized_description', genJson.description || '');
                    sd.append('optimized_meta_title', genJson.meta_title || '');
                    sd.append('optimized_meta_description', genJson.meta_description || '');
                    sd.append('optimized_meta_keywords', genJson.meta_keywords || '');
                    const sv = await fetch('/categories/' + encodeURIComponent(id) + '/save', { method: 'POST', body: sd });
                    if (!sv.ok && sv.status !== 302) throw new Error('Save HTTP ' + sv.status);

                    success++;
                    const tr = document.querySelector('tr[data-row-id="' + id + '"]');
                    if (tr) tr.style.background = '#dcfce7';
                } catch (err) {
                    failed++;
                    const tr = document.querySelector('tr[data-row-id="' + id + '"]');
                    if (tr) tr.style.background = '#fee2e2';
                }

                done++;
                progressFillEl.style.width = Math.round((done / selected.length) * 100) + '%';
            }

            progressLabelEl.textContent = 'Terminé. ' + success + ' succès, ' + failed + ' erreur' + (failed > 1 ? 's' : '') + '.';
            generateBtn.disabled = false;
            clearBtn.disabled = false;
            toggleAll.disabled = false;
            setTimeout(() => location.reload(), 1500);
        });
    })();
    </script>
<?php endif; ?>
