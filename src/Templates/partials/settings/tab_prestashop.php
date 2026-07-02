<?php
use App\Helpers\Renderer;

/**
 * @var \App\Models\Client $client
 * @var bool $has_api_key
 * @var bool $has_blog_api_key
 * @var bool $has_reviews_api_key
 * @var bool $has_aw_cpf_api_key
 * @var string $csrf_token
 */
?>
<div class="card">
    <div class="card__header"><h3 class="card__title">Configuration PrestaShop</h3></div>
    <div class="card__body">
        <form method="POST" action="/settings/prestashop">
            <input type="hidden" name="_csrf" value="<?= Renderer::escape($csrf_token) ?>">

            <div class="form-grid">
                <label class="field">
                    <span class="field__label">URL de la boutique</span>
                    <input type="url" value="<?= Renderer::escape($client->prestashopUrl) ?>" disabled>
                    <span class="field__hint">Définie par l'administrateur lors de la création du client.</span>
                </label>

                <label class="field">
                    <span class="field__label">
                        Clé API Webservice
                        <?php if ($has_api_key): ?>
                            <span class="badge badge--green" style="margin-left:6px;">Configurée</span>
                        <?php else: ?>
                            <span class="badge badge--gray" style="margin-left:6px;">Non configurée</span>
                        <?php endif; ?>
                    </span>
                    <input type="password" name="prestashop_api_key" placeholder="<?= $has_api_key ? 'Laisser vide pour conserver l\'actuelle' : 'Coller votre clé Webservice' ?>" autocomplete="off">
                    <span class="field__hint">
                        À créer dans PrestaShop Admin → Paramètres avancés → Webservice. Activer les ressources
                        <code>categories</code>, <code>products</code>, <code>image_types</code>, <code>images</code>.
                    </span>
                </label>

                <label class="field">
                    <span class="field__label">
                        Clé API Blog Avancé (optionnelle)
                        <?php if ($has_blog_api_key): ?>
                            <span class="badge badge--green" style="margin-left:6px;">Configurée</span>
                        <?php endif; ?>
                    </span>
                    <input type="password" name="prestashop_blog_api_key" placeholder="<?= $has_blog_api_key ? 'Laisser vide pour conserver l\'actuelle' : 'Si vous utilisez le module Blog Avancé' ?>" autocomplete="off">
                    <span class="field__hint">À renseigner uniquement si vous prévoyez d'utiliser le module Blog Avancé en V2.</span>
                </label>

                <label class="field">
                    <span class="field__label">
                        Clé API Avis (module ws_productreviews)
                        <?php if ($has_reviews_api_key): ?>
                            <span class="badge badge--green" style="margin-left:6px;">Configurée</span>
                        <?php endif; ?>
                    </span>
                    <input type="password" name="prestashop_reviews_api_key"
                           placeholder="<?= $has_reviews_api_key ? 'Laisser vide pour conserver l\'actuelle' : 'Choisissez une clé secrète (ex: mots+chiffres aléatoires)' ?>"
                           autocomplete="off">
                    <span class="field__hint">
                        Clé arbitraire que vous choisissez. Elle servira à sécuriser le fichier <code>api_reviews.php</code>
                        à uploader à la racine de votre shop. Téléchargeable ci-dessous après enregistrement.
                    </span>
                </label>

                <?php if ($has_reviews_api_key): ?>
                    <div style="padding: 12px; background:#f0f9ff; border:1px solid #bfdbfe; border-radius:8px;">
                        <strong style="font-size:13px;">📄 Fichier <code>api_reviews.php</code></strong>
                        <p style="font-size:13px; color:var(--color-text-muted); margin: 6px 0 10px;">
                            Téléchargez ce fichier puis uploadez-le à la <strong>racine de votre PrestaShop</strong>
                            (au même niveau que <code>config/</code>). Le module <code>ws_productreviews</code> doit être
                            installé pour que la table <code>ws_product_comment</code> existe.
                        </p>
                        <a href="/settings/prestashop/download-reviews-api" class="btn btn--secondary btn--sm">
                            ⬇ Télécharger api_reviews.php
                        </a>
                    </div>
                <?php endif; ?>

                <label class="field">
                    <span class="field__label">
                        Clé API Champs personnalisés (aw_customproductfield)
                        <?php if ($has_aw_cpf_api_key ?? false): ?>
                            <span class="badge badge--green" style="margin-left:6px;">Configurée</span>
                        <?php endif; ?>
                    </span>
                    <input type="password" name="aw_cpf_api_key"
                           placeholder="<?= ($has_aw_cpf_api_key ?? false) ? 'Laisser vide pour conserver l\'actuelle' : 'Coller la clé du module (X-API-Key)' ?>"
                           autocomplete="off">
                    <span class="field__hint">
                        Clé du module <code>aw_customproductfield</code> installé sur la boutique
                        (<code>/modules/aw_customproductfield/api.php</code>). Sert à écrire les champs personnalisés
                        (ex : <code>price_nutriweb</code>, <code>ingredients</code>, <code>dluo</code>…) depuis le PIM.
                        Récupérable côté Presta via <code>SELECT value FROM ps_configuration WHERE name = 'AW_CPF_API_KEY'</code>.
                    </span>
                </label>

                <label class="field">
                    <span class="field__label">ID Fournisseur</span>
                    <input type="number" name="supplier_id" min="1" step="1"
                           value="<?= $client->supplierId !== null ? (int) $client->supplierId : '' ?>"
                           placeholder="ex: 12">
                    <span class="field__hint">
                        ID Presta du fournisseur principal de la boutique (table <code>ps_supplier</code>).
                        Au sync produits, on récupère <code>product_supplier_reference</code> pour ce fournisseur
                        et on l'affiche à côté de la référence du produit. Laisser vide pour désactiver.
                    </span>
                </label>

                <label class="field">
                    <span class="field__label">Préfixe Référence</span>
                    <input type="text" name="reference_prefix" maxlength="20"
                           value="<?= Renderer::escape($client->referencePrefix ?? '') ?>"
                           placeholder="ex: MUSCU-">
                    <span class="field__hint">
                        Collé devant la référence produit à la création depuis <em>Catalogue Nutriweb → Créer dans PrestaShop</em>.
                        Ex : SKU Nutriweb <code>1004</code> + préfixe <code>MUSCU-</code> → référence Presta <code>MUSCU-1004</code>.
                        La <code>supplier_reference</code> (côté product_supplier) reste l'identifiant brut Nutriweb sans préfixe.
                        Laisser vide pour pousser la référence brute.
                    </span>
                </label>
            </div>

            <?php
            /** @var list<array{id:int, name:string, depth:int, indented_name:string}> $categories_flat */
            $categories_flat = $categories_flat ?? [];
            $ignored_category_ids = $ignored_category_ids ?? [];
            $categories_error = $categories_error ?? null;
            ?>
            <div class="field" style="margin-top:8px;">
                <span class="field__label">Catégories à ignorer</span>
                <span class="field__hint" style="margin-bottom:8px;">
                    Les produits appartenant aux catégories cochées seront <strong>ignorés à la synchronisation</strong>
                    (non importés dans le PIM ; ceux déjà importés sont retirés au prochain sync produits).
                    <?php if ($categories_error !== null): ?>
                        <br><span style="color:#dc2626;">⚠ Impossible de charger les catégories : <?= Renderer::escape($categories_error) ?></span>
                    <?php elseif (empty($categories_flat)): ?>
                        <br><span style="color:var(--color-text-muted);">Configure d'abord la clé API PrestaShop puis recharge la page.</span>
                    <?php endif; ?>
                </span>
                <input type="hidden" name="ignored_category_ids_present" value="1">
                <?php if (!empty($categories_flat)): ?>
                    <input type="search" id="ps-cat-filter" placeholder="Filtrer les catégories…"
                           style="width:100%; box-sizing:border-box; padding:6px 10px; border:1px solid var(--color-border); border-radius:var(--radius); font-size:13px; margin-bottom:8px;">
                    <div style="max-height:280px; overflow-y:auto; border:1px solid var(--color-border); border-radius:var(--radius); padding:8px 12px; background:var(--color-surface);">
                        <?php foreach ($categories_flat as $cat): ?>
                            <label class="ps-cat-row" data-name="<?= Renderer::escape(mb_strtolower($cat['name'])) ?>"
                                   style="display:flex; align-items:center; gap:8px; padding:3px 0; font-size:13px; cursor:pointer;">
                                <input type="checkbox" name="ignored_category_ids[]" value="<?= (int) $cat['id'] ?>"
                                       <?= in_array((int) $cat['id'], $ignored_category_ids, true) ? 'checked' : '' ?>>
                                <span style="white-space:pre;"><?= Renderer::escape($cat['indented_name']) ?></span>
                                <span style="color:var(--color-text-muted); font-size:11px;">#<?= (int) $cat['id'] ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div id="ps-test-result" class="ps-test-result" hidden></div>

            <div class="form-actions">
                <button type="button" id="ps-test-btn" class="btn btn--secondary">
                    <span class="ps-test-btn__label">Tester la connexion</span>
                    <span class="ps-test-btn__spinner" hidden>…</span>
                </button>
                <button type="submit" class="btn btn--primary">Enregistrer</button>
            </div>
        </form>
    </div>
</div>

<script>
// Filtre client-side de la liste des catégories à ignorer.
(function () {
    const filter = document.getElementById('ps-cat-filter');
    if (!filter) return;
    const rows = document.querySelectorAll('.ps-cat-row');
    filter.addEventListener('input', function () {
        const q = filter.value.trim().toLowerCase();
        rows.forEach(function (row) {
            const name = row.getAttribute('data-name') || '';
            row.style.display = (q === '' || name.indexOf(q) !== -1) ? '' : 'none';
        });
    });
})();
(function () {
    const btn = document.getElementById('ps-test-btn');
    const result = document.getElementById('ps-test-result');
    const form = btn.closest('form');
    const label = btn.querySelector('.ps-test-btn__label');
    const spinner = btn.querySelector('.ps-test-btn__spinner');

    btn.addEventListener('click', async function () {
        btn.disabled = true;
        label.hidden = true;
        spinner.hidden = false;
        result.hidden = true;
        result.className = 'ps-test-result';

        const data = new FormData();
        data.append('_csrf', form.querySelector('input[name="_csrf"]').value);
        const newKey = form.querySelector('input[name="prestashop_api_key"]').value;
        if (newKey) {
            data.append('prestashop_api_key', newKey);
        }

        try {
            const res = await fetch('/settings/prestashop/test', { method: 'POST', body: data });
            const json = await res.json();
            result.classList.add(json.ok ? 'ps-test-result--ok' : 'ps-test-result--ko');
            let msg = json.message || (json.ok ? 'Connexion réussie.' : 'Erreur inconnue.');
            if (json.ok && json.api_version) {
                msg += ' (PrestaShop ' + json.api_version + ')';
            }
            result.textContent = msg;
            result.hidden = false;
        } catch (err) {
            result.classList.add('ps-test-result--ko');
            result.textContent = 'Erreur réseau : ' + err.message;
            result.hidden = false;
        } finally {
            btn.disabled = false;
            label.hidden = false;
            spinner.hidden = true;
        }
    });
})();
</script>
