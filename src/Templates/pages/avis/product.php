<?php
use App\Helpers\Renderer;

/**
 * @var array<string,mixed> $product
 * @var list<array<string,mixed>> $reviews
 * @var int $count
 * @var int $valid_count
 * @var float $avg
 * @var ?string $error
 */
$stars = function (float $g): string {
    $full = (int) floor($g);
    $half = ($g - $full) >= 0.5;
    $out = str_repeat('★', $full);
    if ($half) $out .= '½';
    $empty = 5 - $full - ($half ? 1 : 0);
    $out .= str_repeat('☆', max(0, $empty));
    return $out;
};
$starsForGrade = function (int $g): string {
    return str_repeat('★', $g) . str_repeat('☆', max(0, 5 - $g));
};
?>
<div class="product-detail-header">
    <?php if (!empty($product['image_url'])): ?>
        <div class="product-detail-header__image">
            <img src="<?= Renderer::escape($product['image_url']) ?>"
                 alt="<?= Renderer::escape((string) $product['name']) ?>"
                 onerror="this.outerHTML='<div class=&quot;product-detail-header__no-image&quot;><span>📷</span></div>'">
        </div>
    <?php else: ?>
        <div class="product-detail-header__no-image"><span>📷</span></div>
    <?php endif; ?>

    <div class="product-detail-header__body">
        <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
            <a href="/avis" class="btn btn--ghost btn--sm" style="padding:4px 8px;">←</a>
            <h2 class="page-header__title" style="margin:0;"><?= Renderer::escape((string) $product['name']) ?></h2>
            <a href="/produits/<?= Renderer::escape((string) $product['id']) ?>" class="btn btn--ghost btn--sm">
                Voir la fiche produit →
            </a>
        </div>
        <p class="page-header__subtitle">
            Réf. <?= Renderer::escape((string) ($product['reference'] ?? '')) ?> ·
            <span style="font-size:20px;color:#f59e0b;letter-spacing:1px;"><?= $stars($avg) ?></span>
            <strong style="margin-left:6px;"><?= number_format($avg, 1, ',', ' ') ?> / 5</strong> ·
            <?= $count ?> avis<?= $valid_count !== $count ? ' (' . $valid_count . ' validé' . ($valid_count > 1 ? 's' : '') . ')' : '' ?>
        </p>
    </div>
</div>

<?php if ($error): ?>
    <div class="flash flash--error" style="margin-bottom:20px;"><?= Renderer::escape($error) ?></div>
<?php endif; ?>

<!-- Bloc Générer des avis -->
<?php
    $defaultDateTo = date('Y-m-d');
    $defaultDateFrom = date('Y-m-d', strtotime('-180 days'));
?>
<div class="ai-gen-panel" style="margin-bottom: 24px;">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
        <h3 style="margin:0;font-size:14px;font-weight:700;color:#6b21a8;">✨ Générer des avis</h3>
        <span style="font-size:12px;color:var(--color-text-muted);">L'IA crée des avis variés et les pousse sur PrestaShop</span>
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
        <button type="button" id="gen-reviews-btn" class="btn btn--primary" style="background:#7c3aed;border-color:#7c3aed; flex:0 0 auto;">
            <span class="gen-reviews-label">✨ Générer</span>
            <span class="gen-reviews-spinner" hidden>Génération en cours…</span>
        </button>
    </div>

    <div id="gen-reviews-result" class="ai-gen-result" hidden style="margin-top:10px;"></div>
</div>

<div class="card">
    <div class="card__header">
        <h3 class="card__title">Avis (<?= $count ?>)</h3>
    </div>
    <div class="card__body" style="padding: 0;">
        <?php if (empty($reviews)): ?>
            <div class="empty-state">
                <div class="empty-state__hint">Aucun avis sur ce produit.</div>
            </div>
        <?php else: ?>
            <ul class="reviews-list">
                <?php foreach ($reviews as $r): ?>
                    <li class="review" data-review-id="<?= (int) $r['id'] ?>">
                        <!-- Vue lecture -->
                        <div class="review__view">
                            <div class="review__head">
                                <div>
                                    <strong class="review__author"><?= Renderer::escape($r['customer_name'] !== '' ? $r['customer_name'] : 'Client anonyme') ?></strong>
                                    <span class="review__date">
                                        <?= $r['date_add'] !== '' ? Renderer::escape(date('d/m/Y', strtotime($r['date_add']))) : '' ?>
                                    </span>
                                </div>
                                <div style="display:flex;align-items:center;gap:8px;">
                                    <span class="review__stars" style="color:#f59e0b;letter-spacing:1px;font-size:16px;">
                                        <?= $starsForGrade((int) $r['grade']) ?>
                                    </span>
                                    <?php if ((int) $r['validate'] !== 1): ?>
                                        <span class="badge badge--amber">En attente</span>
                                    <?php endif; ?>
                                    <button type="button" class="btn btn--ghost btn--sm review-edit-btn" title="Modifier">✎</button>
                                    <button type="button" class="btn btn--ghost btn--sm review-delete-btn"
                                            title="Supprimer" style="color:#dc2626;">🗑</button>
                                </div>
                            </div>
                            <?php if ($r['title'] !== ''): ?>
                                <div class="review__title"><?= Renderer::escape($r['title']) ?></div>
                            <?php endif; ?>
                            <div class="review__body"><?= nl2br(Renderer::escape($r['content'])) ?></div>
                        </div>

                        <!-- Éditeur inline (caché par défaut) -->
                        <div class="review__edit" hidden>
                            <div class="review__edit-row">
                                <label class="field" style="flex:1;">
                                    <span class="field__label">Auteur</span>
                                    <input type="text" name="customer_name" maxlength="64" value="<?= Renderer::escape($r['customer_name']) ?>">
                                </label>
                                <label class="field" style="flex:0 0 90px;">
                                    <span class="field__label">Note</span>
                                    <select name="grade">
                                        <?php for ($g = 1; $g <= 5; $g++): ?>
                                            <option value="<?= $g ?>" <?= (int) $r['grade'] === $g ? 'selected' : '' ?>><?= $g ?> ★</option>
                                        <?php endfor; ?>
                                    </select>
                                </label>
                                <label class="field" style="flex:0 0 130px;">
                                    <span class="field__label">Statut</span>
                                    <select name="validate">
                                        <option value="1" <?= (int) $r['validate'] === 1 ? 'selected' : '' ?>>Validé</option>
                                        <option value="0" <?= (int) $r['validate'] === 0 ? 'selected' : '' ?>>En attente</option>
                                    </select>
                                </label>
                            </div>
                            <label class="field">
                                <span class="field__label">Titre</span>
                                <input type="text" name="title" maxlength="64" value="<?= Renderer::escape($r['title']) ?>">
                            </label>
                            <label class="field">
                                <span class="field__label">Contenu</span>
                                <textarea name="content" rows="4"><?= Renderer::escape($r['content']) ?></textarea>
                            </label>
                            <div class="review__edit-actions">
                                <span class="review__edit-status"></span>
                                <button type="button" class="btn btn--ghost btn--sm review-cancel-btn">Annuler</button>
                                <button type="button" class="btn btn--primary btn--sm review-save-btn">Enregistrer</button>
                            </div>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</div>

<script>
(function () {
    const btn = document.getElementById('gen-reviews-btn');
    const label = btn.querySelector('.gen-reviews-label');
    const spinner = btn.querySelector('.gen-reviews-spinner');
    const countEl = document.getElementById('gen-reviews-count');
    const dateFromEl = document.getElementById('gen-reviews-date-from');
    const dateToEl = document.getElementById('gen-reviews-date-to');
    const targetAvgEl = document.getElementById('gen-reviews-target-avg');
    const instrEl = document.getElementById('gen-reviews-instructions');
    const resultEl = document.getElementById('gen-reviews-result');
    const csrf = <?= json_encode($csrf_token) ?>;
    const productId = <?= json_encode($product['id']) ?>;

    // --- Inline edit / delete sur chaque avis
    // (productId est déjà déclaré plus haut dans cette IIFE)
    document.querySelectorAll('.review').forEach((reviewEl) => {
        const reviewId = reviewEl.dataset.reviewId;
        const viewEl = reviewEl.querySelector('.review__view');
        const editEl = reviewEl.querySelector('.review__edit');
        const statusEl = editEl.querySelector('.review__edit-status');

        reviewEl.querySelector('.review-edit-btn').addEventListener('click', () => {
            viewEl.hidden = true;
            editEl.hidden = false;
            statusEl.textContent = '';
        });

        reviewEl.querySelector('.review-cancel-btn').addEventListener('click', () => {
            editEl.hidden = true;
            viewEl.hidden = false;
        });

        reviewEl.querySelector('.review-save-btn').addEventListener('click', async (e) => {
            const btnSave = e.currentTarget;
            btnSave.disabled = true;
            statusEl.textContent = 'Enregistrement…';
            statusEl.style.color = 'var(--color-text-muted)';

            const data = new FormData();
            data.append('_csrf', '<?= Renderer::escape($csrf_token) ?>');
            data.append('customer_name', editEl.querySelector('[name="customer_name"]').value);
            data.append('title', editEl.querySelector('[name="title"]').value);
            data.append('content', editEl.querySelector('[name="content"]').value);
            data.append('grade', editEl.querySelector('[name="grade"]').value);
            data.append('validate', editEl.querySelector('[name="validate"]').value);

            try {
                const res = await fetch('/avis/' + encodeURIComponent(productId)
                    + '/review/' + encodeURIComponent(reviewId) + '/update', {
                    method: 'POST',
                    body: data,
                });
                const json = await res.json();
                if (!json.ok) {
                    statusEl.textContent = json.message || 'Erreur';
                    statusEl.style.color = '#dc2626';
                    btnSave.disabled = false;
                    return;
                }
                statusEl.textContent = 'Enregistré ✓ — rechargement…';
                statusEl.style.color = '#059669';
                setTimeout(() => window.location.reload(), 600);
            } catch (err) {
                statusEl.textContent = 'Erreur réseau : ' + err.message;
                statusEl.style.color = '#dc2626';
                btnSave.disabled = false;
            }
        });

        reviewEl.querySelector('.review-delete-btn').addEventListener('click', async () => {
            if (!confirm('Supprimer définitivement cet avis ?')) return;
            const data = new FormData();
            data.append('_csrf', '<?= Renderer::escape($csrf_token) ?>');
            data.append('hard', '1');

            try {
                const res = await fetch('/avis/' + encodeURIComponent(productId)
                    + '/review/' + encodeURIComponent(reviewId) + '/delete', {
                    method: 'POST',
                    body: data,
                });
                const json = await res.json();
                if (!json.ok) {
                    alert(json.message || 'Erreur');
                    return;
                }
                reviewEl.style.opacity = '0.3';
                setTimeout(() => window.location.reload(), 400);
            } catch (err) {
                alert('Erreur réseau : ' + err.message);
            }
        });
    });

    btn.addEventListener('click', async function () {
        btn.disabled = true;
        label.hidden = true;
        spinner.hidden = false;
        resultEl.hidden = true;
        resultEl.className = 'ai-gen-result';

        // Validations
        const dateFrom = dateFromEl.value;
        const dateTo = dateToEl.value;
        if (!dateFrom || !dateTo) {
            alert('Renseigne les 2 dates.');
            btn.disabled = false; label.hidden = false; spinner.hidden = true;
            return;
        }
        if (dateFrom > dateTo) {
            alert('La date "de" doit être antérieure à la date "à".');
            btn.disabled = false; label.hidden = false; spinner.hidden = true;
            return;
        }
        const targetAvg = parseFloat((targetAvgEl.value || '4.5').replace(',', '.'));
        if (isNaN(targetAvg) || targetAvg < 1 || targetAvg > 5) {
            alert('Note moyenne entre 1.0 et 5.0.');
            btn.disabled = false; label.hidden = false; spinner.hidden = true;
            return;
        }

        const data = new FormData();
        data.append('_csrf', csrf);
        data.append('count', countEl.value || '5');
        data.append('date_from', dateFrom);
        data.append('date_to', dateTo);
        data.append('target_avg', String(targetAvg));
        data.append('instructions', instrEl.value || '');

        try {
            const res = await fetch('/avis/' + encodeURIComponent(productId) + '/generate', {
                method: 'POST',
                body: data,
            });
            const json = await res.json();

            if (!json.ok) {
                resultEl.classList.add('ai-gen-result--ko');
                let msg = json.message || 'Erreur inconnue.';
                if (json.raw) {
                    msg += '\n\n--- Réponse brute ---\n' + json.raw;
                }
                resultEl.textContent = msg;
                resultEl.style.whiteSpace = 'pre-wrap';
                resultEl.style.maxHeight = '300px';
                resultEl.style.overflowY = 'auto';
                resultEl.hidden = false;
                return;
            }

            const u = json.usage || {};
            resultEl.classList.add('ai-gen-result--ok');
            resultEl.textContent = (json.inserted || 0) + ' avis créés, '
                + (json.updated || 0) + ' mis à jour. '
                + ((u.prompt_tokens || 0) + (u.completion_tokens || 0)) + ' tokens · '
                + (u.cost_eur || 0).toFixed(4) + ' €. La page va se recharger…';
            resultEl.hidden = false;
            setTimeout(() => window.location.reload(), 1500);
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
})();
</script>
