<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Prompts pour la génération d'avis clients réalistes sur un produit.
 */
final class ReviewsPromptBuilder
{
    /**
     * @param array{
     *     media_name:string,
     *     industry_sector:string,
     *     editorial_line:string,
     *     target_audience:string,
     *     editorial_forbidden:string,
     * } $editorial
     */
    public function __construct(
        private readonly array $editorial,
    ) {
    }

    /**
     * @return array{system_prompt:string, user_prompt:string}
     */
    public function build(string $productName, string $productDescription, int $count, string $userInstructions): array
    {
        return [
            'system_prompt' => $this->buildSystemPrompt($count),
            'user_prompt' => $this->buildUserPrompt($productName, $productDescription, $userInstructions, $count),
        ];
    }

    private function buildSystemPrompt(int $count): string
    {
        $sector = $this->editorial['industry_sector'] ?: '(non précisé)';
        $audience = $this->editorial['target_audience'] ?: '(non précisé)';

        return <<<PROMPT
Tu es un assistant qui rédige des avis clients réalistes en français pour un produit e-commerce du secteur : {$sector}.

Audience type : {$audience}

Tu dois générer EXACTEMENT {$count} avis variés et crédibles. Règles :

1. **Prénoms français variés** suivis d'une initiale de nom (ex: "Marie L.", "Sophie D.", "Catherine M.", "Julie P.", "Anne-Laure B.")
2. **Notes réalistes** : majoritairement 5★ et 4★ (~75%), quelques 3★ (~15%) avec critique mesurée, parfois 2★ (~10%) avec critique honnête mais pas violente
3. **Longueur variable** : certains avis courts (1 phrase), d'autres plus développés (3-4 phrases)
4. **Ton naturel et varié** : éviter le copier-coller, varier le vocabulaire, les angles (cadeau, achat perso, comparaison qualité-prix, livraison, packaging, taille...)
5. **Titres** : courts, 3-8 mots, parfois en français parfait, parfois plus relâchés (le client écrit comme il parle)
6. **Pas de superlatifs vides** ("incroyable, magnifique, trop bien")
7. **Pas de mention** de codes promo, de concurrents, du nom de la boutique
8. **Authenticité** : 1-2 avis peuvent mentionner un défaut mineur (un délai, une teinte différente de la photo, une taille un peu petite/grande)

Réponds UNIQUEMENT en JSON strict, structure :
{
  "reviews": [
    {
      "customer_name": "Prénom L.",
      "title": "...",
      "content": "...",
      "grade": 5
    },
    ...
  ]
}
PROMPT;
    }

    private function buildUserPrompt(string $productName, string $productDescription, string $userInstructions, int $count): string
    {
        $context = "Produit : **{$productName}**";

        $shortDesc = trim(strip_tags($productDescription));
        if ($shortDesc !== '') {
            $context .= "\n\nDescription du produit :\n" . mb_substr($shortDesc, 0, 1500);
        }

        if (trim($userInstructions) !== '') {
            $context .= "\n\nInstructions complémentaires :\n{$userInstructions}";
        }

        $context .= "\n\nGénère {$count} avis client variés et réalistes pour ce produit.";

        return $context;
    }
}
