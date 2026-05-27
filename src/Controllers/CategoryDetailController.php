<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Csrf;
use App\Helpers\JsonRescue;
use App\Middleware\Auth;
use App\Repositories\ClientEditorialRepository;
use App\Repositories\ClientFieldInstructionsRepository;
use App\Repositories\PrestaCategoryRepository;
use App\Services\AiService;
use App\Services\CategoryPromptBuilder;
use App\Services\ClientResolver;
use App\Services\PrestaShopClient;

final class CategoryDetailController extends BaseController
{
    public function show(string $id): void
    {
        Auth::require();
        $client = (new ClientResolver())->resolveCurrent();
        if ($client === null) {
            $this->redirect('/dashboard');
        }

        $repo = new PrestaCategoryRepository();
        $row = $repo->findById($client->id, $id);
        if ($row === null) {
            http_response_code(404);
            $this->renderApp('pages.errors.404', ['title' => 'Catégorie introuvable'], [
                'active' => 'categories',
                'page_title' => 'Catégorie introuvable',
            ]);
            return;
        }

        $shopUrl = rtrim($client->prestashopUrl, '/');
        $linkRewrite = (string) ($row['link_rewrite'] ?? '');
        $externalUrl = $linkRewrite !== '' ? $shopUrl . '/' . $linkRewrite : null;

        $this->renderApp('pages.categories.detail', [
            'row' => $row,
            'external_url' => $externalUrl,
        ], [
            'active' => 'categories',
            'page_title' => (string) $row['name'],
        ]);
    }

    public function saveOptimized(string $id): void
    {
        Auth::require();
        Csrf::enforce($this->input('_csrf'));

        $client = (new ClientResolver())->resolveCurrent();
        if ($client === null) {
            $this->redirect('/dashboard');
        }

        $repo = new PrestaCategoryRepository();
        $row = $repo->findById($client->id, $id);
        if ($row === null) {
            http_response_code(404);
            echo 'Catégorie introuvable';
            return;
        }

        $repo->saveOptimized(
            clientId: $client->id,
            id: $id,
            description: $this->emptyToNull($this->input('optimized_description')),
            metaTitle: $this->emptyToNull($this->input('optimized_meta_title')),
            metaDescription: $this->emptyToNull($this->input('optimized_meta_description')),
            metaKeywords: $this->emptyToNull($this->input('optimized_meta_keywords')),
            name: $this->emptyToNull($this->input('optimized_name')),
        );

        $this->flashSuccess('Version optimisée enregistrée.');
        $this->redirect('/categories/' . $id);
    }

    public function push(string $id): void
    {
        Auth::require();
        Csrf::enforce($this->input('_csrf'));

        $client = (new ClientResolver())->resolveCurrent();
        if ($client === null) {
            $this->redirect('/dashboard');
        }

        $repo = new PrestaCategoryRepository();
        $row = $repo->findById($client->id, $id);
        if ($row === null) {
            http_response_code(404);
            echo 'Catégorie introuvable';
            return;
        }

        // 1. Save first (toujours, comme spécifié — pousser = save + send)
        $optName = $this->emptyToNull($this->input('optimized_name'));
        $optDesc = $this->emptyToNull($this->input('optimized_description'));
        $optMetaTitle = $this->emptyToNull($this->input('optimized_meta_title'));
        $optMetaDesc = $this->emptyToNull($this->input('optimized_meta_description'));
        $optMetaKeywords = $this->emptyToNull($this->input('optimized_meta_keywords'));
        $repo->saveOptimized($client->id, $id, $optDesc, $optMetaTitle, $optMetaDesc, $optMetaKeywords, $optName);

        // 2. Build the fields to push : 1 checkbox par champ.
        //    Une checkbox cochée + une valeur non vide = on push.
        $fieldsToPush = [];
        if ($this->inputBool('enabled_name') && $optName !== null) {
            $fieldsToPush['name'] = $optName;
        }
        if ($this->inputBool('enabled_description') && $optDesc !== null) {
            $fieldsToPush['description'] = $optDesc;
        }
        if ($this->inputBool('enabled_meta_title') && $optMetaTitle !== null) {
            $fieldsToPush['meta_title'] = $optMetaTitle;
        }
        if ($this->inputBool('enabled_meta_description') && $optMetaDesc !== null) {
            $fieldsToPush['meta_description'] = $optMetaDesc;
        }
        if ($this->inputBool('enabled_meta_keywords') && $optMetaKeywords !== null) {
            $fieldsToPush['meta_keywords'] = $optMetaKeywords;
        }

        if ($fieldsToPush === []) {
            $this->flashError('Aucun champ à pousser (cochez au moins une case et remplissez la version optimisée).');
            $this->redirect('/categories/' . $id);
        }

        // 3. Push to PrestaShop
        $service = new PrestaShopClient($client);
        if (!$service->isConfigured()) {
            $this->flashError('Clé API PrestaShop non configurée.');
            $this->redirect('/settings?tab=prestashop');
        }

        try {
            $service->updateCategoryFields((int) $row['presta_id'], $fieldsToPush);
        } catch (\Throwable $e) {
            $this->flashError('Échec du push PrestaShop : ' . $e->getMessage());
            $this->redirect('/categories/' . $id);
        }

        // 4. Sync local "Version actuelle" depuis les champs poussés
        $repo->applyAfterPush(
            $client->id,
            $id,
            $fieldsToPush['description'] ?? null,
            $fieldsToPush['meta_title'] ?? null,
            $fieldsToPush['meta_description'] ?? null,
            $fieldsToPush['meta_keywords'] ?? null,
            $fieldsToPush['name'] ?? null,
        );

        $this->flashSuccess(count($fieldsToPush) . ' champ' . (count($fieldsToPush) > 1 ? 's' : '') . ' poussé' . (count($fieldsToPush) > 1 ? 's' : '') . ' sur PrestaShop.');
        $this->redirect('/categories/' . $id);
    }

    /**
     * Endpoint AJAX JSON : génère description / meta title / meta description via le provider IA
     * configuré pour le client. Ne sauvegarde rien — le front se charge de remplir le formulaire,
     * l'utilisateur clique ensuite Enregistrer ou Pousser.
     */
    public function generate(string $id): void
    {
        Auth::require();
        Csrf::enforce($this->input('_csrf'));

        $client = (new ClientResolver())->resolveCurrent();
        if ($client === null) {
            $this->json(['ok' => false, 'message' => 'Aucun client actif.'], 400);
        }

        $repo = new PrestaCategoryRepository();
        $row = $repo->findById($client->id, $id);
        if ($row === null) {
            $this->json(['ok' => false, 'message' => 'Catégorie introuvable.'], 404);
        }

        $userInstructions = $this->input('instructions') ?? '';
        $wordCount = (int) ($this->input('word_count') ?? '200');
        if ($wordCount < 50) { $wordCount = 50; }
        if ($wordCount > 1500) { $wordCount = 1500; }

        $editorial = (new ClientEditorialRepository())->get($client->id);
        $fieldInstructions = (new ClientFieldInstructionsRepository())->getForEntity($client->id, 'category');

        $prompts = (new CategoryPromptBuilder($editorial))->build(
            categoryName: (string) $row['name'],
            currentDescription: (string) ($row['description'] ?? ''),
            userInstructions: $userInstructions,
            wordCount: $wordCount,
            fieldInstructions: $fieldInstructions,
        );

        try {
            $result = (new AiService($client))->generate([
                'system_prompt' => $prompts['system_prompt'],
                'user_prompt' => $prompts['user_prompt'],
                'max_tokens' => 2000,
                'temperature' => 0.7,
                'json_mode' => true,
                'entity_type' => 'category',
                'entity_id' => $row['id'],
            ]);
        } catch (\Throwable $e) {
            $this->json(['ok' => false, 'message' => $e->getMessage()], 500);
        }

        $payload = JsonRescue::decode($result['text']);
        if ($payload === null) {
            $this->json([
                'ok' => false,
                'message' => 'Le modèle a renvoyé une réponse non parsable. Réessayez.',
                'raw' => mb_substr($result['text'], 0, 2000),
            ], 502);
        }

        $this->json([
            'ok' => true,
            'name' => $payload['name'] ?? '',
            'description' => $payload['description'] ?? '',
            'meta_title' => $payload['meta_title'] ?? '',
            'meta_description' => $payload['meta_description'] ?? '',
            'meta_keywords' => $payload['meta_keywords'] ?? '',
            'usage' => [
                'model' => $result['model'],
                'prompt_tokens' => $result['prompt_tokens'],
                'completion_tokens' => $result['completion_tokens'],
                'cost_eur' => $result['cost_eur'],
            ],
        ]);
    }

    private function emptyToNull(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim($value);
        return $trimmed === '' ? null : $trimmed;
    }
}
