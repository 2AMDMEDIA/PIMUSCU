<?php
use App\Helpers\Renderer;

/**
 * @var array<string,mixed> $row
 * @var ?string $external_url
 * @var string $csrf_token
 */
$name = (string) $row['name'];
$active = (int) $row['active'] === 1;
$prestaId = (int) $row['presta_id'];

$currentName = (string) ($row['name'] ?? '');
$currentDescription = (string) ($row['description'] ?? '');
$currentAddDescription = (string) ($row['aw_description_2'] ?? '');
$currentMetaTitle = (string) ($row['meta_title'] ?? '');
$currentMetaDesc = (string) ($row['meta_description'] ?? '');
$currentMetaKw = (string) ($row['meta_keywords'] ?? '');

$optName = (string) ($row['optimized_name'] ?? '');
$optDescription = (string) ($row['optimized_description'] ?? '');
$optAddDescription = (string) ($row['optimized_aw_description_2'] ?? '');
$optMetaTitle = (string) ($row['optimized_meta_title'] ?? '');
$optMetaDesc = (string) ($row['optimized_meta_description'] ?? '');
$optMetaKw = (string) ($row['optimized_meta_keywords'] ?? '');
?>
<div class="page-header">
    <div>
        <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
            <a href="/categories" class="btn btn--ghost btn--sm" style="padding:4px 8px;">←</a>
            <h2 class="page-header__title" style="margin:0;"><?= Renderer::escape($name) ?></h2>
            <?php if ($external_url): ?>
                <a href="<?= Renderer::escape($external_url) ?>" target="_blank" rel="noopener" title="Voir sur le site" style="color:var(--color-text-muted);">↗</a>
            <?php endif; ?>
            <?php if ($active): ?>
                <span class="badge badge--blue">Active</span>
            <?php else: ?>
                <span class="badge badge--gray">Inactive</span>
            <?php endif; ?>
        </div>
        <p class="page-header__subtitle">ID Presta : <?= $prestaId ?> · <?= (int) $row['products_count'] ?> produit<?= (int) $row['products_count'] > 1 ? 's' : '' ?></p>
    </div>
</div>

<form method="POST" action="/categories/<?= Renderer::escape($row['id']) ?>/push" class="cat-detail">
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
                    <textarea id="ai-instructions" rows="2" placeholder="Ex: insister sur la qualité artisanale et l'argent 925, audience femmes 25-45..."></textarea>
                </label>
                <label class="field" style="margin:0;">
                    <span class="field__label">Nombre de mots ≈</span>
                    <input type="number" id="ai-word-count" min="50" max="1500" step="50" value="200">
                </label>
            </div>
            <div id="ai-result" class="ai-gen-result" hidden style="margin-top:12px;"></div>
        </div>
    </div>

    <!-- Champs alignés : 1 ligne par champ, avec checkbox de contrôle (IA + Push) -->
    <div class="cat-pairs">

        <!-- Nom de la catégorie -->
        <section class="cat-pair" data-field="name">
            <header class="cat-pair__head">
                <label class="cat-pair__toggle">
                    <input type="checkbox" name="enabled_name" value="1" data-field-toggle="name">
                    <span class="cat-pair__name">Nom de la catégorie</span>
                </label>
                <span class="cat-pair__counter">décoché par défaut — coche pour régénérer/pousser</span>
            </header>
            <div class="cat-pair__body">
                <div class="cat-pair__col">
                    <span class="cat-pair__col-label">Actuel</span>
                    <div class="cat-detail__readonly"><?= Renderer::escape($currentName) ?: '<em style="color:var(--color-text-muted);">—</em>' ?></div>
                </div>
                <div class="cat-pair__col">
                    <span class="cat-pair__col-label">
                        Optimisé
                        <button type="button" class="copy-current-btn" data-copy-current data-target="name-input" data-source-text="<?= Renderer::escape($currentName) ?>" title="Copier la valeur actuelle">↩ Copier l'actuel</button>
                    </span>
                    <input type="text" name="optimized_name" id="name-input" maxlength="500" value="<?= Renderer::escape($optName) ?>" placeholder="Nom court de la catégorie (3-6 mots)">
                </div>
            </div>
        </section>

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

        <!-- Description (haut de page) -->
        <section class="cat-pair" data-field="description">
            <header class="cat-pair__head">
                <label class="cat-pair__toggle">
                    <input type="checkbox" name="enabled_description" value="1" checked data-field-toggle="description">
                    <span class="cat-pair__name">Description</span>
                </label>
                <span class="cat-pair__counter">haut de page</span>
            </header>
            <div class="cat-pair__body">
                <div class="cat-pair__col">
                    <span class="cat-pair__col-label">Actuel</span>
                    <div class="cat-detail__html cat-pair__html-current">
                        <?php if ($currentDescription !== ''): ?>
                            <?= $currentDescription ?>
                        <?php else: ?>
                            <em style="color:var(--color-text-muted);">— (vide)</em>
                        <?php endif; ?>
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
                        <textarea name="optimized_description" id="desc-input" rows="10" hidden placeholder="Saisissez la description optimisée (HTML autorisé)..."><?= Renderer::escape($optDescription) ?></textarea>
                    </div>
                </div>
            </div>
        </section>

        <!-- Description complémentaire (bas de page) -->
        <section class="cat-pair" data-field="aw_description_2">
            <header class="cat-pair__head">
                <label class="cat-pair__toggle">
                    <input type="checkbox" name="enabled_aw_description_2" value="1" checked data-field-toggle="aw_description_2">
                    <span class="cat-pair__name">Description complémentaire</span>
                </label>
                <span class="cat-pair__counter">bas de page · guide d'achat, FAQ</span>
            </header>
            <div class="cat-pair__body">
                <div class="cat-pair__col">
                    <span class="cat-pair__col-label">Actuel</span>
                    <div class="cat-detail__html cat-pair__html-current">
                        <?php if ($currentAddDescription !== ''): ?>
                            <?= $currentAddDescription ?>
                        <?php else: ?>
                            <em style="color:var(--color-text-muted);">— (vide)</em>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="cat-pair__col">
                    <span class="cat-pair__col-label">
                        Optimisé
                        <button type="button" class="copy-current-btn" data-copy-current data-target="ad-input" data-source-html-b64="<?= base64_encode($currentAddDescription) ?>" title="Copier le HTML actuel">↩ Copier l'actuel</button>
                        <span class="html-editor__tabs" data-html-editor="ad">
                            <button type="button" class="html-editor__tab html-editor__tab--active" data-mode="preview">👁 Aperçu</button>
                            <button type="button" class="html-editor__tab" data-mode="code">&lt;/&gt; Code</button>
                        </span>
                    </span>
                    <div class="html-editor" data-target="ad-input">
                        <div class="html-editor__preview cat-detail__html" data-preview="ad-input">
                            <?= $optAddDescription !== '' ? $optAddDescription : '<em style="color:var(--color-text-muted);">— (vide — clique sur Code pour rédiger)</em>' ?>
                        </div>
                        <textarea name="optimized_aw_description_2" id="ad-input" rows="10" hidden placeholder="Guide d'achat, FAQ, conseils d'utilisation, comparatif... (HTML autorisé)"><?= Renderer::escape($optAddDescription) ?></textarea>
                    </div>
                </div>
            </div>
        </section>

        <!-- Actions -->
        <div class="cat-detail__actions">
            <p style="margin:0; color: var(--color-text-muted); font-size: 12px;">
                ℹ Les cases cochées contrôlent à la fois la <strong>génération IA</strong> et le <strong>push PrestaShop</strong>.
                Décoche un champ pour le préserver.
            </p>
            <div style="display:flex; gap:8px;">
                <button type="submit"
                        formaction="/categories/<?= Renderer::escape($row['id']) ?>/save"
                        class="btn btn--secondary">
                    Enregistrer
                </button>
                <button type="submit" class="btn btn--primary">
                    ✈ Pousser sur PrestaShop
                </button>
            </div>
        </div>
    </div>
</form>

<style>
.cat-pairs { display: flex; flex-direction: column; gap: 16px; }
.cat-pair { background: var(--color-surface); border: 1px solid var(--color-border); border-radius: var(--radius); overflow: hidden; transition: opacity .15s; }
.cat-pair--disabled { opacity: 0.5; }
.cat-pair--disabled .cat-pair__col input,
.cat-pair--disabled .cat-pair__col textarea { background: var(--color-bg); }
.cat-pair__head { padding: 10px 14px; border-bottom: 1px solid var(--color-border); background: var(--color-bg); display:flex; justify-content:space-between; align-items:center; gap:12px; }
.cat-pair__toggle { display: flex; align-items: center; gap: 10px; margin: 0; cursor: pointer; user-select: none; }
.cat-pair__toggle input[type="checkbox"] { margin: 0; width: 16px; height: 16px; cursor: pointer; }
.cat-pair__name { font-weight: 600; font-size: 14px; }
.cat-pair__counter { font-size: 11px; color: var(--color-text-muted); }
.cat-pair__body { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; padding: 14px; }
.cat-pair__col { display: flex; flex-direction: column; gap: 6px; }
.cat-pair__col-label { font-size: 10px; text-transform: uppercase; letter-spacing: 0.6px; color: var(--color-text-muted); font-weight: 600; }
.cat-pair__col input, .cat-pair__col textarea { width: 100%; box-sizing: border-box; }
.cat-pair__html-current { max-height: 240px; overflow-y: auto; }
.cat-detail__actions { display: flex; justify-content: space-between; align-items: center; gap: 16px; flex-wrap: wrap; padding: 14px; background: var(--color-bg); border: 1px solid var(--color-border); border-radius: var(--radius); }
@media (max-width: 900px) {
    .cat-pair__body { grid-template-columns: 1fr; }
}

/* Toggle aperçu / code pour les textareas HTML */
.cat-pair__col-label { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.copy-current-btn { background: transparent; border: 1px solid var(--color-border); border-radius: 4px; padding: 3px 8px; font-size: 11px; cursor: pointer; color: var(--color-text); font-family: inherit; }
.copy-current-btn:hover { background: var(--color-bg); border-color: var(--color-text-muted); }
.copy-current-btn:active { transform: translateY(1px); }
.html-editor__tabs { display: inline-flex; border: 1px solid var(--color-border); border-radius: 4px; overflow: hidden; margin-left: auto; }
.html-editor__tab { background: transparent; border: 0; padding: 3px 8px; font-size: 11px; cursor: pointer; color: var(--color-text-muted); border-right: 1px solid var(--color-border); font-family: inherit; }
.html-editor__tab:last-child { border-right: 0; }
.html-editor__tab--active { background: var(--color-primary, #2563eb); color: white; }
.html-editor { border: 1px solid var(--color-border); border-radius: var(--radius); background: var(--color-surface); }
.html-editor__preview { padding: 12px; min-height: 240px; max-height: 480px; overflow-y: auto; background: white; }
.html-editor__preview:empty::before { content: '— (vide)'; color: var(--color-text-muted); font-style: italic; }
.html-editor > textarea { width: 100%; box-sizing: border-box; border: 0; padding: 12px; min-height: 240px; font-family: ui-monospace, SFMono-Regular, monospace; font-size: 12px; resize: vertical; background: #fafafa; }
.html-editor > textarea:focus { outline: 2px solid var(--color-primary, #2563eb); outline-offset: -2px; }
</style>

<script>
(function () {
    const mt = document.getElementById('mt-input');
    const md = document.getElementById('md-input');
    const mtCounter = document.getElementById('mt-counter');
    const mdCounter = document.getElementById('md-counter');

    function updateCounter(input, counter, target) {
        const len = input.value.length;
        counter.textContent = len + ' / ' + target;
        if (len > target) {
            counter.style.color = '#dc2626';
        } else if (len > target * 0.9) {
            counter.style.color = '#d97706';
        } else {
            counter.style.color = 'var(--color-text-muted)';
        }
    }

    mt.addEventListener('input', () => updateCounter(mt, mtCounter, 60));
    md.addEventListener('input', () => updateCounter(md, mdCounter, 155));
    updateCounter(mt, mtCounter, 60);
    updateCounter(md, mdCounter, 155);

    // --- Toggle visuel des blocs selon l'état des checkboxes
    function isFieldEnabled(fieldName) {
        const cb = document.querySelector('[data-field-toggle="' + fieldName + '"]');
        return cb ? cb.checked : true;
    }
    document.querySelectorAll('[data-field-toggle]').forEach((cb) => {
        const fieldName = cb.dataset.fieldToggle;
        const section = document.querySelector('.cat-pair[data-field="' + fieldName + '"]');
        function syncDisabled() {
            if (section) section.classList.toggle('cat-pair--disabled', !cb.checked);
        }
        cb.addEventListener('change', syncDisabled);
        syncDisabled();
    });

    // --- Editeur HTML : toggle Aperçu / Code + sync preview ↔ textarea
    function refreshPreviewFor(textareaId) {
        const ta = document.getElementById(textareaId);
        const preview = document.querySelector('[data-preview="' + textareaId + '"]');
        if (!ta || !preview) return;
        const html = ta.value.trim();
        preview.innerHTML = html !== '' ? html : '<em style="color:var(--color-text-muted);">— (vide — clique sur Code pour rédiger)</em>';
    }
    document.querySelectorAll('.html-editor__tabs').forEach((tabs) => {
        const editorKey = tabs.dataset.htmlEditor; // "desc" ou "ad"
        const targetId = editorKey === 'desc' ? 'desc-input' : 'ad-input';
        const editorBox = document.querySelector('.html-editor[data-target="' + targetId + '"]');
        if (!editorBox) return;
        const preview = editorBox.querySelector('.html-editor__preview');
        const textarea = editorBox.querySelector('textarea');

        tabs.querySelectorAll('.html-editor__tab').forEach((btn) => {
            btn.addEventListener('click', () => {
                tabs.querySelectorAll('.html-editor__tab').forEach((b) => b.classList.remove('html-editor__tab--active'));
                btn.classList.add('html-editor__tab--active');
                const mode = btn.dataset.mode;
                if (mode === 'preview') {
                    refreshPreviewFor(targetId);
                    preview.hidden = false;
                    textarea.hidden = true;
                } else {
                    preview.hidden = true;
                    textarea.hidden = false;
                    textarea.focus();
                }
            });
        });

        // Si la textarea est éditée puis qu'on revient en aperçu, le refresh se fait au toggle.
        // On ne re-render PAS à chaque keypress pour éviter le re-flow inutile pendant l'édition.
    });

    // --- Boutons "Copier l'actuel" : prennent la valeur du champ Actuel et la collent dans Optimisé
    document.querySelectorAll('[data-copy-current]').forEach((btn) => {
        btn.addEventListener('click', () => {
            const targetId = btn.dataset.target;
            const target = document.getElementById(targetId);
            if (!target) return;

            let value = '';
            if (btn.dataset.sourceHtmlB64 !== undefined) {
                // HTML : décode le base64 pour récupérer le HTML brut
                try { value = atob(btn.dataset.sourceHtmlB64); } catch { value = ''; }
                // atob renvoie de l'UTF-8 mal décodé sur les accents → patch pour les caractères non-ASCII
                try { value = decodeURIComponent(escape(value)); } catch {}
            } else {
                value = btn.dataset.sourceText || '';
            }

            target.value = value;
            // Si c'est une textarea HTML, refresh le preview
            if (target.tagName === 'TEXTAREA' && document.querySelector('[data-preview="' + targetId + '"]')) {
                refreshPreviewFor(targetId);
            }
            // Sinon (input texte), update le compteur si applicable
            if (targetId === 'mt-input') updateCounter(mt, mtCounter, 60);
            if (targetId === 'md-input') updateCounter(md, mdCounter, 155);

            // Feedback visuel rapide
            const original = btn.textContent;
            btn.textContent = '✓ Copié';
            setTimeout(() => { btn.textContent = original; }, 1200);
        });
    });

    // --- IA generate
    const btn = document.getElementById('ai-generate-btn');
    const label = btn.querySelector('.ai-gen-label');
    const spinner = btn.querySelector('.ai-gen-spinner');
    const instructionsEl = document.getElementById('ai-instructions');
    const wordCountEl = document.getElementById('ai-word-count');
    const resultEl = document.getElementById('ai-result');
    const descEl = document.querySelector('textarea[name="optimized_description"]');
    const csrf = document.querySelector('input[name="_csrf"]').value;
    const categoryId = <?= json_encode($row['id']) ?>;

    btn.addEventListener('click', async function () {
        btn.disabled = true;
        label.hidden = true;
        spinner.hidden = false;
        resultEl.hidden = true;
        resultEl.className = 'ai-gen-result';

        const data = new FormData();
        data.append('_csrf', csrf);
        data.append('instructions', instructionsEl.value);
        data.append('word_count', wordCountEl.value || '200');

        try {
            const res = await fetch('/categories/' + encodeURIComponent(categoryId) + '/generate', {
                method: 'POST',
                body: data,
            });
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
            const filled = [];
            const skipped = [];
            const adEl = document.getElementById('ad-input');
            const mk = document.getElementById('mk-input');

            const nameEl = document.getElementById('name-input');
            if (nameEl && isFieldEnabled('name')) { nameEl.value = json.name || ''; filled.push('name'); } else if (nameEl) { skipped.push('name'); }
            if (isFieldEnabled('description'))      { descEl.value = json.description || '';        filled.push('description'); refreshPreviewFor('desc-input'); } else { skipped.push('description'); }
            if (adEl && isFieldEnabled('aw_description_2')) { adEl.value = json.aw_description_2 || ''; filled.push('add_description'); refreshPreviewFor('ad-input'); } else if (adEl) { skipped.push('add_description'); }
            if (isFieldEnabled('meta_title'))       { mt.value = json.meta_title || '';             filled.push('meta_title'); } else { skipped.push('meta_title'); }
            if (isFieldEnabled('meta_description')) { md.value = json.meta_description || '';       filled.push('meta_description'); } else { skipped.push('meta_description'); }
            if (mk && isFieldEnabled('meta_keywords')) { mk.value = json.meta_keywords || '';       filled.push('meta_keywords'); } else if (mk) { skipped.push('meta_keywords'); }

            updateCounter(mt, mtCounter, 60);
            updateCounter(md, mdCounter, 155);

            const u = json.usage || {};
            resultEl.classList.add('ai-gen-result--ok');
            let msg = 'Généré (' + (u.model || '?') + ') · '
                + ((u.prompt_tokens || 0) + (u.completion_tokens || 0)) + ' tokens · '
                + (u.cost_eur || 0).toFixed(4) + ' €.';
            if (skipped.length > 0) {
                msg += ' Champs ignorés (décochés) : ' + skipped.join(', ') + '.';
            }
            msg += ' Vous pouvez éditer puis Enregistrer ou Pousser.';
            resultEl.textContent = msg;
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
})();
</script>
