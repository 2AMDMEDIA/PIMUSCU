<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Construit les prompts (system + user) pour la génération SEO de catégorie Presta.
 * Intègre la ligne éditoriale du client + les interdictions.
 */
final class CategoryPromptBuilder
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
     * @param array<string,string> $fieldInstructions Instructions par champ (depuis Paramètres → Champs).
     *   Clés attendues : meta_title, meta_description, meta_keywords, description.
     * @return array{system_prompt:string, user_prompt:string}
     */
    public function build(
        string $categoryName,
        ?string $currentDescription,
        string $userInstructions,
        int $wordCount,
        array $fieldInstructions = [],
    ): array {
        $system = $this->buildSystemPrompt($wordCount, $fieldInstructions);
        $user = $this->buildUserPrompt($categoryName, $currentDescription, $userInstructions);
        return ['system_prompt' => $system, 'user_prompt' => $user];
    }

    /**
     * Construit un bloc texte "Instructions spécifiques pour ce champ"
     * formaté en bullet points pour être visible par le modèle.
     *
     * @param array<string,string> $fieldInstructions
     */
    private function fieldRule(array $fieldInstructions, string $field): string
    {
        $instr = trim($fieldInstructions[$field] ?? '');
        if ($instr === '') {
            return '';
        }
        $lines = preg_split('/\r?\n/', $instr) ?: [];
        $bullets = array_filter(array_map('trim', $lines));
        if (empty($bullets)) {
            return '';
        }
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
                $forbiddenBlock = "\n\nINTERDICTIONS ABSOLUES (ne jamais faire) :\n- " . implode("\n- ", $rules);
            }
        }

        return <<<PROMPT
Tu es un expert SEO e-commerce spécialisé dans le secteur : {$sector}.

Tu rédiges pour la boutique : {$media}.
Positionnement éditorial : {$line}
Lectorat cible : {$audience}{$forbiddenBlock}

Ta mission : générer la description SEO d'une catégorie de produits e-commerce. Tu dois produire 5 éléments :

1. **name** : nom court et clair de la catégorie (3-6 mots maximum), SANS le nom du média, SANS punctuation finale. Sert de label utilisé partout sur le site (menu, breadcrumb, titre H1). Doit être plus naturel et lisible que le titre actuel s'il est médiocre. Si le nom actuel est déjà bien, garde-le quasi-identique.{$this->fieldRule($fieldInstructions, 'name')}

2. **description** : description longue de ~{$wordCount} mots en HTML (utilise <p>, <h2>, <strong>, <em>, <ul>, <li>). Affichée sur la page catégorie. Inclus le mot-clé principal dans la première phrase et dans un H2. Le ton doit être informatif, expert et orienté conversion.{$this->fieldRule($fieldInstructions, 'description')}

3. **meta_title** : 50-60 caractères, inclut le mot-clé principal au début, accrocheur, finit avec le nom du média.{$this->fieldRule($fieldInstructions, 'meta_title')}

4. **meta_description** : 150-155 caractères, résume le contenu de la catégorie avec un CTA léger ("Découvrez...", "Explorez...").{$this->fieldRule($fieldInstructions, 'meta_description')}

5. **meta_keywords** : 5 à 10 mots-clés ou expressions, séparés par des virgules. Inclus le mot-clé principal, des synonymes, des variantes longue-traîne et des termes secondaires liés à l'intention de recherche. Pas de majuscules superflues, pas de répétitions. Format : "mot-clé 1, mot-clé 2, expression longue, synonyme, ..."{$this->fieldRule($fieldInstructions, 'meta_keywords')}

Réponds UNIQUEMENT en JSON strict (pas de markdown, pas de backticks). RÈGLES CRITIQUES JSON :
- Échappe TOUS les guillemets doubles dans le HTML avec \\" (ex: <a href=\\"...\\">)
- Échappe TOUS les sauts de ligne dans les valeurs string avec \\n
- Garde la structure exacte ci-dessous, sans texte autour :

{
  "name": "Nom court",
  "description": "...HTML...",
  "meta_title": "...",
  "meta_description": "...",
  "meta_keywords": "mot-clé 1, mot-clé 2, ..."
}
PROMPT;
    }

    private function buildUserPrompt(
        string $categoryName,
        ?string $currentDescription,
        string $userInstructions,
    ): string {
        $context = "Catégorie à optimiser : **{$categoryName}**";

        if ($currentDescription !== null && trim(strip_tags($currentDescription)) !== '') {
            $existing = mb_substr(strip_tags($currentDescription), 0, 1500);
            $context .= "\n\nDescription actuelle (à améliorer ou remplacer si médiocre) :\n{$existing}";
        } else {
            $context .= "\n\nAucune description actuelle — pars de zéro.";
        }

        if (trim($userInstructions) !== '') {
            $context .= "\n\nInstructions complémentaires de l'éditeur :\n{$userInstructions}";
        }

        return $context;
    }
}
