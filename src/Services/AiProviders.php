<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Catalogue des providers IA disponibles (texte + image).
 * Source de vérité unique utilisée par les Settings et le futur AIService.
 */
final class AiProviders
{
    /**
     * @return list<array{
     *     id:string,
     *     name:string,
     *     category:'text'|'image',
     *     recommended:bool,
     *     description:string,
     *     placeholder:string,
     * }>
     */
    public static function all(): array
    {
        return [
            // Texte
            [
                'id' => 'openrouter',
                'name' => 'OpenRouter',
                'category' => 'text',
                'recommended' => true,
                'description' => 'Proxy multi-modèles (Claude, Gemini, GPT-4o, Mistral, …). Recommandé pour le MVP.',
                'placeholder' => 'sk-or-v1-...',
            ],
            [
                'id' => 'anthropic',
                'name' => 'Anthropic Claude',
                'category' => 'text',
                'recommended' => false,
                'description' => 'Accès direct à Claude (sans proxy).',
                'placeholder' => 'sk-ant-...',
            ],
            [
                'id' => 'openai',
                'name' => 'OpenAI',
                'category' => 'text',
                'recommended' => false,
                'description' => 'Accès direct à GPT-4o, GPT-4-turbo, etc.',
                'placeholder' => 'sk-...',
            ],
            [
                'id' => 'gemini',
                'name' => 'Google Gemini',
                'category' => 'text',
                'recommended' => false,
                'description' => 'Accès direct à Gemini Pro / Flash.',
                'placeholder' => 'AIza...',
            ],
            [
                'id' => 'mistral',
                'name' => 'Mistral AI',
                'category' => 'text',
                'recommended' => false,
                'description' => 'Mistral Large / Medium / Small.',
                'placeholder' => '...',
            ],
            // Image
            [
                'id' => 'kie',
                'name' => 'Kie.AI',
                'category' => 'image',
                'recommended' => true,
                'description' => 'Génération d\'images (text → image) pour les visuels secondaires des produits.',
                'placeholder' => '...',
            ],
            // Search / data
            [
                'id' => 'serpapi',
                'name' => 'SerpApi (Google Shopping)',
                'category' => 'image', // pas de categorie dediee, affiche avec les outils image dans Settings
                'recommended' => false,
                'description' => 'Veille concurrentielle : recherche de prix temps réel sur Google Shopping (France) pour le bouton "Étude de prix" sur les fiches produit.',
                'placeholder' => 'serpapi-key-...',
            ],
        ];
    }

    /** @return list<array<string,mixed>> */
    public static function textProviders(): array
    {
        return array_values(array_filter(self::all(), fn ($p) => $p['category'] === 'text'));
    }

    /** @return list<array<string,mixed>> */
    public static function imageProviders(): array
    {
        return array_values(array_filter(self::all(), fn ($p) => $p['category'] === 'image'));
    }

    public static function isValid(string $providerId): bool
    {
        foreach (self::all() as $p) {
            if ($p['id'] === $providerId) {
                return true;
            }
        }
        return false;
    }

    public static function find(string $providerId): ?array
    {
        foreach (self::all() as $p) {
            if ($p['id'] === $providerId) {
                return $p;
            }
        }
        return null;
    }
}
