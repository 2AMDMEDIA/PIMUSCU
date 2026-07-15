<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Csrf;
use App\Helpers\Encryption;
use App\Middleware\Auth;
use App\Repositories\ClientAiPreferencesRepository;
use App\Repositories\ClientApiKeyRepository;
use App\Repositories\ClientEditorialRepository;
use App\Repositories\ClientFieldInstructionsRepository;
use App\Repositories\ClientNutriwebSettingsRepository;
use App\Repositories\ClientRepository;
use App\Repositories\PasswordTokenRepository;
use App\Repositories\UserClientRepository;
use App\Repositories\UserRepository;
use App\Services\AiProviders;
use App\Services\AwCpfClient;
use App\Services\ClientResolver;
use App\Services\Mailer;
use App\Services\PrestaShopClient;
use App\Services\ReviewsApiFileGenerator;
use App\Bootstrap;
use App\Session;

final class SettingsController extends BaseController
{
    private const VALID_TABS = ['prestashop', 'account', 'users', 'ai-tools', 'editorial', 'nutriweb', 'attributes', 'fields', 'mapping'];

    /**
     * Catalogue des champs Nutriweb (source du mapping). Groupé pour l'affichage.
     * @return array<string, list<array{key:string, label:string, hint?:string}>>
     */
    public static function nutriwebSources(): array
    {
        return [
            'Identifiants' => [
                ['key' => 'sku',        'label' => 'SKU'],
                ['key' => 'barcode',    'label' => 'Code-barres (EAN)'],
                ['key' => 'permalink',  'label' => 'Permalink'],
            ],
            'Descriptif' => [
                ['key' => 'name',       'label' => 'Nom'],
                ['key' => 'brand',      'label' => 'Marque'],
                ['key' => 'size',       'label' => 'Taille'],
                ['key' => 'color',      'label' => 'Couleur'],
                ['key' => 'flavor',     'label' => 'Saveur'],
                ['key' => 'image_url',  'label' => 'URL image'],
            ],
            'Prix / Stock' => [
                ['key' => 'price_base',     'label' => 'Prix base HT',       'hint' => 'price.base.value'],
                ['key' => 'price_selling',  'label' => 'Prix Achat HT',      'hint' => 'price.selling.value'],
                ['key' => 'price_retail',   'label' => 'Prix public TTC',    'hint' => 'price.retail.value'],
                ['key' => 'purchase_price', 'label' => 'Prix d\'achat amont'],
                ['key' => 'stock',          'label' => 'Stock'],
            ],
            'Nutrifacts (live)' => [
                ['key' => 'nutrifacts.ingredient', 'label' => 'Ingrédients (HTML)'],
                ['key' => 'nutrifacts.allergen',   'label' => 'Allergènes (HTML)'],
                ['key' => 'nutrifacts.warnings',   'label' => 'Avertissements'],
                ['key' => 'nutrifacts.macro',      'label' => 'Table nutrition (JSON)'],
            ],
        ];
    }

    /**
     * Catalogue des destinations PrestaShop. Groupé pour l'affichage.
     * Convention de clé : `product.<field>`, `combination.<field>`, `custom.<key>`.
     *
     * @param list<array{key:string, label:string}> $customFields Liste des champs
     *        custom récupérée en live via AwCpfClient (schema). Vide = pas de bloc custom.
     * @return array<string, list<array{key:string, label:string}>>
     */
    public static function prestaDestinations(array $customFields = []): array
    {
        $out = [
            'Produit (natif)' => [
                ['key' => 'product.name',              'label' => 'Nom'],
                ['key' => 'product.reference',         'label' => 'Référence'],
                ['key' => 'product.ean13',             'label' => 'EAN13'],
                ['key' => 'product.price',             'label' => 'Prix HT'],
                ['key' => 'product.wholesale_price',   'label' => 'Prix d\'achat'],
                ['key' => 'product.description',       'label' => 'Description longue'],
                ['key' => 'product.description_short', 'label' => 'Description courte'],
                ['key' => 'product.meta_title',        'label' => 'Meta title'],
                ['key' => 'product.meta_description',  'label' => 'Meta description'],
                ['key' => 'product.meta_keywords',     'label' => 'Meta keywords'],
                ['key' => 'product.link_rewrite',      'label' => 'Slug (link_rewrite)'],
                ['key' => 'product.id_manufacturer',   'label' => 'Marque (id)'],
                ['key' => 'product.weight',            'label' => 'Poids'],
            ],
            'Déclinaison (natif)' => [
                ['key' => 'combination.reference',           'label' => 'Référence décli'],
                ['key' => 'combination.ean13',               'label' => 'EAN13 décli'],
                ['key' => 'combination.supplier_reference',  'label' => 'Réf fournisseur décli'],
                ['key' => 'combination.wholesale_price',     'label' => 'Prix achat décli'],
                ['key' => 'combination.price_impact',        'label' => 'Delta prix décli'],
            ],
        ];
        if ($customFields !== []) {
            $out['Champ custom (aw_customproductfield)'] = $customFields;
        }
        return $out;
    }

    public function show(): void
    {
        Auth::require();

        $tab = $this->input('tab') ?? 'prestashop';
        if (!in_array($tab, self::VALID_TABS, true)) {
            $tab = 'prestashop';
        }

        $client = (new ClientResolver())->resolveCurrent();
        if ($client === null) {
            $this->renderApp('pages.dashboard.no_client', [], ['page_title' => 'Aucun client']);
            return;
        }

        // Données spécifiques selon le tab actif
        $data = ['active_tab' => $tab, 'client' => $client];

        if ($tab === 'ai-tools') {
            $apiKeyRepo = new ClientApiKeyRepository();
            $prefs = (new ClientAiPreferencesRepository())->get($client->id);
            $data['providers'] = AiProviders::all();
            $data['prefs'] = $prefs;
            $data['api_keys'] = $apiKeyRepo->listForClient($client->id);
        }

        if ($tab === 'editorial') {
            $data['editorial'] = (new ClientEditorialRepository())->get($client->id);
        }

        if ($tab === 'nutriweb') {
            $data['nutriweb_settings'] = (new ClientNutriwebSettingsRepository())->get($client->id);
        }

        if ($tab === 'attributes') {
            // Liste live des groupes Presta. Best-effort : si l'API plante, on affiche un message.
            $attrGroups = [];
            $attrError = null;
            try {
                $attrGroups = (new PrestaShopClient($client))->fetchAttributeGroupsWithValues();
            } catch (\Throwable $e) {
                $attrError = $e->getMessage();
            }
            $data['attribute_groups'] = $attrGroups;
            $data['attribute_error'] = $attrError;
            // Si null en DB : tout est actif (par defaut). Si tableau : seuls les ids listes.
            $data['enabled_attribute_group_ids'] = $client->enabledAttributeGroupIds;
        }

        if ($tab === 'users') {
            $clients = new ClientRepository();
            $data['users'] = $clients->usersForClient($client->id);
        }

        if ($tab === 'prestashop') {
            $data['has_api_key'] = $client->prestashopApiKeyEncrypted !== null;
            $data['has_blog_api_key'] = $client->prestashopBlogApiKeyEncrypted !== null;
            $data['has_reviews_api_key'] = $client->prestashopReviewsApiKeyEncrypted !== null;
            $data['has_aw_cpf_api_key'] = $client->awCpfApiKeyEncrypted !== null;
            // Liste des catégories Presta (pour le sélecteur "catégories à ignorer").
            // Best-effort : si l'API plante ou pas de clé, on n'affiche pas le sélecteur.
            // Cache en session (10 min) pour éviter de re-payer l'appel long au
            // rechargement de la page. Force rafraîchissement avec ?refresh_cats=1.
            $categoriesFlat = [];
            $categoriesError = null;
            $categoriesFromCache = false;
            if ($client->prestashopApiKeyEncrypted !== null) {
                $cacheKey = 'settings_categories_flat_' . $client->id;
                $cache = Session::get($cacheKey);
                $forceRefresh = $this->input('refresh_cats') === '1';
                if (!$forceRefresh && is_array($cache)
                    && isset($cache['at'], $cache['data'])
                    && (time() - (int) $cache['at']) < 600
                ) {
                    $categoriesFlat = $cache['data'];
                    $categoriesFromCache = true;
                } else {
                    try {
                        $categoriesFlat = (new PrestaShopClient($client))->fetchCategoriesFlat();
                        Session::set($cacheKey, ['at' => time(), 'data' => $categoriesFlat]);
                    } catch (\Throwable $e) {
                        $categoriesError = $e->getMessage();
                        // En cas d'erreur, on tombe sur l'ancien cache si dispo (même expiré)
                        // pour ne pas casser l'UI si le shop est momentanément down.
                        if (is_array($cache) && isset($cache['data'])) {
                            $categoriesFlat = $cache['data'];
                            $categoriesFromCache = true;
                        }
                    }
                }
            }
            $data['categories_flat'] = $categoriesFlat;
            $data['categories_error'] = $categoriesError;
            $data['categories_from_cache'] = $categoriesFromCache;
            $data['ignored_category_ids'] = $client->ignoredCategoryIds ?? [];
        }

        if ($tab === 'fields') {
            $catalog = ClientFieldInstructionsRepository::catalog();
            $repo = new ClientFieldInstructionsRepository();
            $byEntity = [];
            foreach ($catalog as $entityType => $meta) {
                $byEntity[$entityType] = $repo->getForEntity($client->id, $entityType);
            }
            $data['fields_catalog'] = $catalog;
            $data['fields_instructions'] = $byEntity;
        }

        if ($tab === 'mapping') {
            // Récupère en live le schéma des champs custom aw_customproductfield
            // (best-effort : si l'API plante ou la clé est absente, on continue
            // avec un bloc custom vide et un message).
            $customFields = [];
            $customError = null;
            $customUrl = '';
            $customRaw = '';
            $aw = new AwCpfClient($client);
            if ($aw->isConfigured()) {
                try {
                    $schema = $aw->fetchSchema();
                    foreach ($schema as $f) {
                        if (empty($f['enabled'])) continue;
                        $label = $f['label'] !== '' ? $f['label'] : $f['key'];
                        $customFields[] = [
                            'key' => 'custom.' . $f['key'],
                            'label' => $label . ' (' . $f['type'] . ($f['lang'] ? ', lang' : '') . ')',
                        ];
                    }
                } catch (\Throwable $e) {
                    $customError = $e->getMessage();
                }
                $customUrl = $aw->getLastCalledUrl();
                $customRaw = $aw->getLastRawBody();
            } else {
                $customError = 'Clé API aw_customproductfield non configurée (Paramètres → PrestaShop).';
            }
            $data['nutriweb_sources'] = self::nutriwebSources();
            $data['presta_destinations'] = self::prestaDestinations($customFields);
            $data['current_mapping'] = $client->fieldMapping ?? [];
            $data['custom_fields_error'] = $customError;
            $data['custom_fields_count'] = count($customFields);
            $data['custom_fields_url'] = $customUrl;
            $data['custom_fields_raw'] = $customRaw;
        }

        $this->renderApp('pages.settings.index', $data, [
            'active' => 'settings',
            'page_title' => 'Paramètres',
        ]);
    }

    // -------------------------------------------------------------------------
    // PrestaShop tab
    // -------------------------------------------------------------------------

    public function savePrestashop(): void
    {
        Auth::require();
        Csrf::enforce($this->input('_csrf'));

        $client = $this->requireClientOrRedirect();

        $apiKey = $this->input('prestashop_api_key');
        $apiKey = $apiKey !== null ? trim($apiKey) : null;
        $blogApiKey = $this->input('prestashop_blog_api_key');
        $blogApiKey = $blogApiKey !== null ? trim($blogApiKey) : null;
        $reviewsApiKey = $this->input('prestashop_reviews_api_key');
        $reviewsApiKey = $reviewsApiKey !== null ? trim($reviewsApiKey) : null;
        $awCpfApiKey = $this->input('aw_cpf_api_key');
        $awCpfApiKey = $awCpfApiKey !== null ? trim($awCpfApiKey) : null;
        $supplierIdRaw = $this->input('supplier_id');
        $referencePrefix = $this->input('reference_prefix');
        $referencePrefix = $referencePrefix !== null ? trim($referencePrefix) : null;

        $pdo = \App\Database::pdo();

        // Mise à jour conditionnelle : on ne touche au champ encrypted que si une nouvelle valeur est fournie
        $sets = [];
        $params = [':id' => $client->id];

        if ($apiKey !== null && $apiKey !== '') {
            $sets[] = 'prestashop_api_key_encrypted = :api_key';
            $params[':api_key'] = Encryption::encrypt($apiKey);
        }
        if ($blogApiKey !== null && $blogApiKey !== '') {
            $sets[] = 'prestashop_blog_api_key_encrypted = :blog_api_key';
            $params[':blog_api_key'] = Encryption::encrypt($blogApiKey);
        }
        if ($reviewsApiKey !== null && $reviewsApiKey !== '') {
            $sets[] = 'prestashop_reviews_api_key_encrypted = :reviews_api_key';
            $params[':reviews_api_key'] = Encryption::encrypt($reviewsApiKey);
        }
        if ($awCpfApiKey !== null && $awCpfApiKey !== '') {
            $sets[] = 'aw_cpf_api_key_encrypted = :aw_cpf_api_key';
            $params[':aw_cpf_api_key'] = Encryption::encrypt($awCpfApiKey);
        }

        // supplier_id : toujours mis à jour (peut être vidé en saisissant chaîne vide)
        if ($supplierIdRaw !== null) {
            $supplierIdRaw = trim($supplierIdRaw);
            $sets[] = 'supplier_id = :supplier_id';
            $params[':supplier_id'] = ($supplierIdRaw === '' || !ctype_digit($supplierIdRaw)) ? null : (int) $supplierIdRaw;
        }
        // reference_prefix : idem, toujours UPDATE (peut etre vide)
        if ($referencePrefix !== null) {
            $sets[] = 'reference_prefix = :ref_prefix';
            $params[':ref_prefix'] = $referencePrefix === '' ? null : mb_substr($referencePrefix, 0, 20);
        }

        // ignored_category_ids : catégories dont les produits sont ignorés à la sync.
        // Toujours mis à jour si le champ est présent dans le POST (coché ou non).
        if (isset($_POST['ignored_category_ids']) || $this->input('ignored_category_ids_present') !== null) {
            $rawCats = $_POST['ignored_category_ids'] ?? [];
            $catIds = [];
            if (is_array($rawCats)) {
                foreach ($rawCats as $cid) {
                    $i = (int) $cid;
                    if ($i > 0 && !in_array($i, $catIds, true)) {
                        $catIds[] = $i;
                    }
                }
            }
            sort($catIds);
            $sets[] = 'ignored_category_ids = :ignored_cats';
            $params[':ignored_cats'] = $catIds === [] ? null : json_encode($catIds, JSON_UNESCAPED_UNICODE);
        }

        if ($sets !== []) {
            $sql = 'UPDATE clients SET ' . implode(', ', $sets) . ', updated_at = NOW() WHERE id = :id';
            $pdo->prepare($sql)->execute($params);
            $this->flashSuccess('Configuration PrestaShop enregistrée.');
        } else {
            $this->flashError('Aucune modification.');
        }

        $this->redirect('/settings?tab=prestashop');
    }

    /**
     * Sert le fichier api_reviews.php avec la clé API du client pré-remplie.
     */
    public function downloadReviewsApiFile(): void
    {
        Auth::require();
        $client = $this->requireClientOrRedirect();

        if ($client->prestashopReviewsApiKeyEncrypted === null) {
            $this->flashError('Configurez d\'abord une clé API Avis dans les paramètres, puis téléchargez le fichier.');
            $this->redirect('/settings?tab=prestashop');
        }

        $apiKey = Encryption::decrypt($client->prestashopReviewsApiKeyEncrypted);
        $php = ReviewsApiFileGenerator::generate($apiKey);

        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="api_reviews.php"');
        header('Content-Length: ' . strlen($php));
        echo $php;
        exit;
    }

    /**
     * Endpoint AJAX (JSON) — test de connexion au Webservice PrestaShop avec la clé
     * actuellement en base (ou la nouvelle saisie). Permet de valider avant sauvegarde.
     */
    public function testPrestashopConnection(): void
    {
        Auth::require();
        Csrf::enforce($this->input('_csrf'));

        $client = (new ClientResolver())->resolveCurrent();
        if ($client === null) {
            $this->json(['ok' => false, 'message' => 'Aucun client actif.'], 400);
        }

        // Si une nouvelle clé est saisie dans le formulaire, on l'utilise pour le test
        // (sans la persister en base — le user doit cliquer Enregistrer s'il veut la garder).
        $newApiKey = $this->input('prestashop_api_key');
        $newApiKey = $newApiKey !== null ? trim($newApiKey) : null;
        if ($newApiKey !== null && $newApiKey !== '') {
            $testClient = new \App\Models\Client(
                id: $client->id,
                name: $client->name,
                prestashopUrl: $client->prestashopUrl,
                prestashopApiKeyEncrypted: Encryption::encrypt($newApiKey),
                prestashopBlogApiKeyEncrypted: $client->prestashopBlogApiKeyEncrypted,
                prestashopReviewsApiKeyEncrypted: $client->prestashopReviewsApiKeyEncrypted,
                supplierId: $client->supplierId,
                referencePrefix: $client->referencePrefix,
                enabledAttributeGroupIds: $client->enabledAttributeGroupIds,
                logoUrl: $client->logoUrl,
                footerName: $client->footerName,
                tokenMonthlyLimit: $client->tokenMonthlyLimit,
                tokenAlertThreshold: $client->tokenAlertThreshold,
                enabledModules: $client->enabledModules,
                customFieldsCategories: $client->customFieldsCategories,
                customFieldsProducts: $client->customFieldsProducts,
                customFieldsPrompts: $client->customFieldsPrompts,
                createdAt: $client->createdAt,
                updatedAt: $client->updatedAt,
            );
        } else {
            $testClient = $client;
        }

        $result = (new PrestaShopClient($testClient))->testConnection();
        $this->json($result, $result['ok'] ? 200 : 400);
    }

    // -------------------------------------------------------------------------
    // Account tab
    // -------------------------------------------------------------------------

    public function saveAccount(): void
    {
        Auth::require();
        Csrf::enforce($this->input('_csrf'));

        $userId = Session::userId();
        $users = new UserRepository();
        $name = $this->input('full_name') ?? '';

        $users->updateFullName($userId, $name);
        Session::set('user_full_name', $name);

        $newPassword = $this->input('new_password');
        if ($newPassword !== null && $newPassword !== '') {
            if (strlen($newPassword) < 8) {
                $this->flashError('Mot de passe trop court (8 caractères minimum).');
                $this->redirect('/settings?tab=account');
            }
            $users->updatePassword($userId, $newPassword);
            $this->flashSuccess('Profil et mot de passe mis à jour.');
        } else {
            $this->flashSuccess('Profil mis à jour.');
        }

        $this->redirect('/settings?tab=account');
    }

    // -------------------------------------------------------------------------
    // Users tab
    // -------------------------------------------------------------------------

    public function inviteUser(): void
    {
        Auth::require();
        Csrf::enforce($this->input('_csrf'));

        $client = $this->requireClientOrRedirect();
        $email = $this->input('email');
        $name = $this->input('full_name') ?? '';

        if ($email === null) {
            $this->flashError('Email requis.');
            $this->redirect('/settings?tab=users');
        }

        $users = new UserRepository();
        $existing = $users->findByEmail($email);

        if ($existing !== null) {
            // Linker l'utilisateur existant au client courant
            (new UserClientRepository())->link($existing->id, $client->id);
            $this->flashSuccess('Utilisateur existant rattaché au client.');
            $this->redirect('/settings?tab=users');
        }

        // Création utilisateur sans mot de passe (set via lien d'invitation)
        $newUser = $users->create(
            email: $email,
            plainPassword: null,
            fullName: $name,
            isSuperAdmin: false,
            needsPasswordSetup: true,
        );
        (new UserClientRepository())->link($newUser->id, $client->id);

        // Envoi du mail d'invitation
        $tokens = new PasswordTokenRepository();
        $days = (int) (Bootstrap::config('app.tokens.invitation_lifetime_days') ?? 7);
        $token = $tokens->create($newUser->id, 'invitation', $days * 86400);

        $url = rtrim((string) Bootstrap::config('app.url'), '/') . '/set-password?token=' . urlencode($token);
        $body = '<p>Bonjour,</p>'
            . '<p>Vous avez été invité à rejoindre <strong>' . htmlspecialchars($client->name) . '</strong> sur ' . htmlspecialchars((string) Bootstrap::config('app.name')) . '.</p>'
            . '<p><a href="' . htmlspecialchars($url) . '">Cliquez ici pour définir votre mot de passe</a> (lien valable ' . $days . ' jours).</p>';

        try {
            (new Mailer())->send($email, $name, 'Invitation à rejoindre ' . $client->name, $body);
            $this->flashSuccess('Invitation envoyée à ' . $email . '.');
        } catch (\Throwable $e) {
            $this->flashError('Utilisateur créé, mais l\'envoi de l\'email a échoué (vérifiez les logs).');
        }

        $this->redirect('/settings?tab=users');
    }

    public function unlinkUser(string $userId): void
    {
        Auth::require();
        Csrf::enforce($this->input('_csrf'));

        $client = $this->requireClientOrRedirect();
        if ($userId === Session::userId()) {
            $this->flashError('Vous ne pouvez pas vous retirer vous-même du client.');
            $this->redirect('/settings?tab=users');
        }

        (new UserClientRepository())->unlink($userId, $client->id);
        $this->flashSuccess('Utilisateur retiré du client.');
        $this->redirect('/settings?tab=users');
    }

    // -------------------------------------------------------------------------
    // AI Tools tab
    // -------------------------------------------------------------------------

    public function savePreferences(): void
    {
        Auth::require();
        Csrf::enforce($this->input('_csrf'));
        $client = $this->requireClientOrRedirect();

        $textProvider = $this->input('default_text_provider') ?? 'openrouter';
        $imageProvider = $this->input('default_image_provider') ?? 'kie';

        if (!AiProviders::isValid($textProvider) || !AiProviders::isValid($imageProvider)) {
            $this->flashError('Provider invalide.');
            $this->redirect('/settings?tab=ai-tools');
        }

        (new ClientAiPreferencesRepository())->save($client->id, $textProvider, $imageProvider);
        $this->flashSuccess('Préférences IA enregistrées.');
        $this->redirect('/settings?tab=ai-tools');
    }

    public function saveApiKey(): void
    {
        Auth::require();
        Csrf::enforce($this->input('_csrf'));
        $client = $this->requireClientOrRedirect();

        $provider = $this->input('provider');
        $apiKey = $this->input('api_key');

        if ($provider === null || $apiKey === null || !AiProviders::isValid($provider)) {
            $this->flashError('Provider ou clé invalide.');
            $this->redirect('/settings?tab=ai-tools');
        }

        (new ClientApiKeyRepository())->save($client->id, $provider, $apiKey);
        $this->flashSuccess('Clé API enregistrée pour ' . AiProviders::find($provider)['name'] . '.');
        $this->redirect('/settings?tab=ai-tools');
    }

    public function deleteApiKey(string $provider): void
    {
        Auth::require();
        Csrf::enforce($this->input('_csrf'));
        $client = $this->requireClientOrRedirect();

        if (!AiProviders::isValid($provider)) {
            $this->flashError('Provider invalide.');
            $this->redirect('/settings?tab=ai-tools');
        }

        (new ClientApiKeyRepository())->delete($client->id, $provider);
        $this->flashSuccess('Clé API supprimée.');
        $this->redirect('/settings?tab=ai-tools');
    }

    // -------------------------------------------------------------------------
    // Editorial tab
    // -------------------------------------------------------------------------

    public function saveEditorial(): void
    {
        Auth::require();
        Csrf::enforce($this->input('_csrf'));
        $client = $this->requireClientOrRedirect();

        (new ClientEditorialRepository())->save($client->id, [
            'media_name' => $this->input('media_name') ?? '',
            'industry_sector' => $this->input('industry_sector') ?? '',
            'editorial_line' => $this->input('editorial_line') ?? '',
            'target_audience' => $this->input('target_audience') ?? '',
            'editorial_forbidden' => $this->input('editorial_forbidden') ?? '',
            'image_prompt_instructions' => $this->input('image_prompt_instructions') ?? '',
        ]);

        $this->flashSuccess('Ligne éditoriale enregistrée.');
        $this->redirect('/settings?tab=editorial');
    }

    // -------------------------------------------------------------------------
    // Attributes tab (filtre des groupes Presta proposes dans /catalogue/create)
    // -------------------------------------------------------------------------

    public function saveAttributes(): void
    {
        Auth::require();
        Csrf::enforce($this->input('_csrf'));
        $client = $this->requireClientOrRedirect();

        $rawIds = $_POST['group_ids'] ?? [];
        $ids = [];
        if (is_array($rawIds)) {
            foreach ($rawIds as $rid) {
                $i = (int) $rid;
                if ($i > 0 && !in_array($i, $ids, true)) {
                    $ids[] = $i;
                }
            }
        }
        // Tri pour stockage stable
        sort($ids);

        $pdo = \App\Database::pdo();
        $stmt = $pdo->prepare(
            'UPDATE clients SET enabled_attribute_group_ids = :ids, updated_at = NOW() WHERE id = :id'
        );
        $stmt->execute([
            ':ids' => json_encode($ids, JSON_UNESCAPED_UNICODE),
            ':id' => $client->id,
        ]);

        $this->flashSuccess(count($ids) . ' groupe' . (count($ids) > 1 ? 's' : '') . ' d\'attribut sélectionné' . (count($ids) > 1 ? 's' : '') . '.');
        $this->redirect('/settings?tab=attributes');
    }

    // -------------------------------------------------------------------------
    // Mapping tab (Nutriweb -> PrestaShop)
    // -------------------------------------------------------------------------

    public function saveMapping(): void
    {
        Auth::require();
        Csrf::enforce($this->input('_csrf'));
        $client = $this->requireClientOrRedirect();

        // Whitelist des sources (statique) et des destinations natives.
        // Les destinations `custom.*` sont dynamiques (venues du schema live) : on les
        // accepte via un préfixe pour ne pas rejeter les nouveaux champs custom quand
        // l'API du module n'est pas atteignable au moment du save.
        $validSources = [];
        foreach (self::nutriwebSources() as $group) {
            foreach ($group as $it) $validSources[$it['key']] = true;
        }
        $validNativeDests = [];
        foreach (self::prestaDestinations() as $group) {
            foreach ($group as $it) $validNativeDests[$it['key']] = true;
        }

        $raw = $_POST['mapping'] ?? [];
        $clean = [];
        if (is_array($raw)) {
            foreach ($raw as $src => $dest) {
                $src = trim((string) $src);
                $dest = trim((string) $dest);
                if ($src === '' || $dest === '') continue;
                if (!isset($validSources[$src])) continue;
                $isNative = isset($validNativeDests[$dest]);
                $isCustom = str_starts_with($dest, 'custom.') && preg_match('/^custom\.[a-zA-Z0-9_.-]+$/', $dest) === 1;
                if (!$isNative && !$isCustom) continue;
                $clean[$src] = $dest;
            }
        }

        $pdo = \App\Database::pdo();
        $pdo->prepare('UPDATE clients SET field_mapping = :m, updated_at = NOW() WHERE id = :id')
            ->execute([
                ':m' => $clean === [] ? null : json_encode($clean, JSON_UNESCAPED_UNICODE),
                ':id' => $client->id,
            ]);

        $n = count($clean);
        $this->flashSuccess($n . ' correspondance' . ($n > 1 ? 's' : '') . ' enregistrée' . ($n > 1 ? 's' : '') . '.');
        $this->redirect('/settings?tab=mapping');
    }

    // -------------------------------------------------------------------------
    // Nutriweb tab
    // -------------------------------------------------------------------------

    public function saveNutriweb(): void
    {
        Auth::require();
        Csrf::enforce($this->input('_csrf'));
        $client = $this->requireClientOrRedirect();

        $privateKey = $this->input('private_key');
        $privateKey = $privateKey !== null ? trim($privateKey) : null;
        $catalogueUrl = trim((string) ($this->input('catalogue_url') ?? ''));
        $productInfoUrl = trim((string) ($this->input('product_info_url') ?? ''));

        $privateKeyEncrypted = null;
        if ($privateKey !== null && $privateKey !== '') {
            $privateKeyEncrypted = Encryption::encrypt($privateKey);
        }

        (new ClientNutriwebSettingsRepository())->save(
            $client->id,
            $privateKeyEncrypted,
            $catalogueUrl,
            $productInfoUrl,
        );

        $this->flashSuccess('Configuration Nutriweb enregistrée.');
        $this->redirect('/settings?tab=nutriweb');
    }

    /**
     * Sauvegarde groupée des instructions IA par champ pour toutes les entités configurables.
     * Form POST : input name = "instructions[<entity_type>][<field_name>]"
     */
    public function saveFieldInstructions(): void
    {
        Auth::require();
        Csrf::enforce($this->input('_csrf'));
        $client = $this->requireClientOrRedirect();

        $raw = $_POST['instructions'] ?? [];
        if (!is_array($raw)) {
            $this->flashError('Données invalides.');
            $this->redirect('/settings?tab=fields');
        }

        $catalog = ClientFieldInstructionsRepository::catalog();
        $repo = new ClientFieldInstructionsRepository();
        $savedCount = 0;

        foreach ($catalog as $entityType => $meta) {
            // On ne traite que les entités "active" (les autres sont des placeholders)
            if (($meta['status'] ?? '') !== 'active') {
                continue;
            }
            $entityValues = is_array($raw[$entityType] ?? null) ? $raw[$entityType] : [];
            foreach ($meta['fields'] as $fieldName => $fieldMeta) {
                $value = $entityValues[$fieldName] ?? null;
                if (!is_string($value) && $value !== null) {
                    continue;
                }
                $repo->set($client->id, $entityType, $fieldName, $value);
                $savedCount++;
            }
        }

        $this->flashSuccess($savedCount . ' instruction' . ($savedCount > 1 ? 's' : '') . ' enregistrée' . ($savedCount > 1 ? 's' : '') . '.');
        $this->redirect('/settings?tab=fields');
    }

    private function requireClientOrRedirect(): \App\Models\Client
    {
        $client = (new ClientResolver())->resolveCurrent();
        if ($client === null) {
            $this->flashError('Aucun client actif.');
            $this->redirect('/admin');
        }
        return $client;
    }
}
