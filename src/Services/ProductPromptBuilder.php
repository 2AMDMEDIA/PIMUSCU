<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Prompts pour la génération SEO de fiche produit Presta (description courte + longue + meta).
 */
final class ProductPromptBuilder
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
     * @param array<string,string> $fieldInstructions Instructions par champ (Paramètres → Champs → PRODUIT).
     *   Clés attendues : description_short, description, meta_title, meta_description.
     * @return array{system_prompt:string, user_prompt:string}
     */
    public function build(
        string $productName,
        string $reference,
        float $price,
        ?string $currentDescription,
        ?string $currentDescriptionShort,
        string $userInstructions,
        int $wordCount,
        array $fieldInstructions = [],
    ): array {
        return [
            'system_prompt' => $this->buildSystemPrompt($wordCount, $fieldInstructions),
            'user_prompt' => $this->buildUserPrompt($productName, $reference, $price, $currentDescription, $currentDescriptionShort, $userInstructions),
        ];
    }

    /**
     * Construit un bloc texte "RÈGLES CLIENT PRIORITAIRES" pour le champ.
     * Ces règles priment sur les défauts qui les précèdent dans le prompt.
     *
     * @param array<string,string> $fieldInstructions
     */
    private function fieldRule(array $fieldInstructions, string $field): string
    {
        $instr = trim($fieldInstructions[$field] ?? '');
        if ($instr === '') return '';
        $lines = preg_split('/\r?\n/', $instr) ?: [];
        $bullets = array_filter(array_map('trim', $lines));
        if (empty($bullets)) return '';
        return "\n   ⚡ RÈGLES CLIENT (PRIORITÉ ABSOLUE — si elles contredisent les règles par défaut ci-dessus, applique CELLES-CI) :\n   - " . implode("\n   - ", $bullets);
    }

    /**
     * @param array<string,string> $fieldInstructions
     */
    private function buildSystemPrompt(int $wordCount, array $fieldInstructions = []): string
    {
        $media = $this->editorial['media_name'] ?: '(non précisé)';
        $sector = $this->editorial['industry_sector'] ?: '(non précisé)';
        $line = $this->editorial['editorial_line'] ?: '(non précisé)';
        $audience = $this->editorial['target_audience'] ?: '(non précisé)';
        $forbidden = trim($this->editorial['editorial_forbidden']);

        $forbiddenBlock = '';
        if ($forbidden !== '') {
            $rules = array_filter(array_map('trim', preg_split('/\r?\n/', $forbidden) ?: []));
            if ($rules !== []) {
                $forbiddenBlock = "\n\nINTERDICTIONS ABSOLUES :\n- " . implode("\n- ", $rules);
            }
        }

        return <<<PROMPT
Tu es un expert SEO et copywriter e-commerce spécialisé dans le secteur : {$sector}.

Tu rédiges pour : {$media}.
Positionnement : {$line}
Audience cible : {$audience}{$forbiddenBlock}

Ta mission : générer la fiche produit complète d'un article e-commerce. Tu dois produire 5 éléments :

1. **description_short** : description courte (résumé teaser), 150-300 caractères, en HTML simple (<p>, <strong>). Accroche émotionnelle ou bénéfice clé.{$this->fieldRule($fieldInstructions, 'description_short')}

2. **description** : description longue d'environ {$wordCount} mots en HTML structuré. Utilise <p>, <h2>, <strong>, <ul>, <li>. Structure conseillée :
   - Accroche (1 paragraphe)
   - <h2>Caractéristiques</h2> avec une liste à puces
   - <h2>Pour qui ?</h2> ou <h2>Pourquoi le choisir ?</h2>
   - Paragraphe de clôture orienté conversion{$this->fieldRule($fieldInstructions, 'description')}

3. **meta_title** : 50-60 caractères, inclut le nom du produit + le bénéfice principal + nom de la boutique.{$this->fieldRule($fieldInstructions, 'meta_title')}

4. **meta_description** : 150-155 caractères, accrocheuse, avec un CTA léger ("Découvrez...", "Offrez-vous...").{$this->fieldRule($fieldInstructions, 'meta_description')}

5. **meta_keywords** : 5 à 10 mots-clés ou expressions, séparés par des virgules. Inclus le nom du produit, des synonymes, des variantes longue-traîne, des termes secondaires liés à l'intention de recherche. Pas de répétitions. Format : "mot-clé 1, mot-clé 2, expression longue, ..."{$this->fieldRule($fieldInstructions, 'meta_keywords')}

Réponds UNIQUEMENT en JSON strict (pas de markdown, pas de backticks). RÈGLES CRITIQUES JSON :
- Échappe TOUS les guillemets doubles dans le HTML avec \\" (ex: <a href=\\"...\\">)
- Échappe TOUS les sauts de ligne dans les valeurs string avec \\n
- Garde la structure exacte ci-dessous, sans texte autour :

{
  "description_short": "...HTML...",
  "description": "...HTML...",
  "meta_title": "...",
  "meta_description": "...",
  "meta_keywords": "mot-clé 1, mot-clé 2, ..."
}
PROMPT;
    }

    private function buildUserPrompt(
        string $productName,
        string $reference,
        float $price,
        ?string $currentDescription,
        ?string $currentDescriptionShort,
        string $userInstructions,
    ): string {
        $context = "Produit à optimiser : **{$productName}**\nRéférence : {$reference}\nPrix : " . number_format($price * 1.20, 2, ',', ' ') . ' € TTC';

        $currentShort = trim(strip_tags((string) $currentDescriptionShort));
        if ($currentShort !== '') {
            $context .= "\n\nDescription courte actuelle :\n" . mb_substr($currentShort, 0, 500);
        }

        $currentLong = trim(strip_tags((string) $currentDescription));
        if ($currentLong !== '') {
            $context .= "\n\nDescription longue actuelle (à améliorer ou remplacer si médiocre) :\n" . mb_substr($currentLong, 0, 2000);
        } else {
            $context .= "\n\nAucune description longue actuelle — pars de zéro.";
        }

        if (trim($userInstructions) !== '') {
            $context .= "\n\nInstructions complémentaires de l'éditeur :\n{$userInstructions}";
        }

        return $context;
    }
}
