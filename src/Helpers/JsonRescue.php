<?php

declare(strict_types=1);

namespace App\Helpers;

/**
 * Tente d'extraire un JSON valide depuis une réponse IA, même si le modèle
 * a entouré son output de markdown, de prose, ou a fait des erreurs d'échappement.
 *
 * Stratégies (dans l'ordre) :
 *   1. Décodage direct
 *   2. Strip des fences markdown ```json ... ```
 *   3. Extraction de la zone entre la première { et la dernière }
 *   4. Tentative de réparation : échappement des sauts de ligne bruts
 *      à l'intérieur des valeurs string
 */
final class JsonRescue
{
    /** @return array<string,mixed>|null */
    public static function decode(string $text): ?array
    {
        $text = trim($text);

        // 1. Tentative directe
        $decoded = json_decode($text, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        // 2. Strip markdown fences ```json ... ```
        if (str_contains($text, '```')) {
            $stripped = preg_replace('/^.*?```(?:json)?\s*/is', '', $text);
            $stripped = preg_replace('/\s*```.*$/is', '', (string) $stripped);
            $decoded = json_decode((string) $stripped, true);
            if (is_array($decoded)) {
                return $decoded;
            }
            $text = (string) $stripped;
        }

        // 3. Extraction de la zone entre première { et dernière }
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $candidate = substr($text, $start, $end - $start + 1);
            $decoded = json_decode($candidate, true);
            if (is_array($decoded)) {
                return $decoded;
            }
            $text = $candidate;
        }

        // 4. Réparation : échapper les sauts de ligne bruts à l'intérieur des strings.
        //    Repère les paires "..." et remplace \n / \r par \\n.
        $repaired = self::escapeRawNewlinesInStrings($text);
        $decoded = json_decode($repaired, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        return null;
    }

    /**
     * Parcourt le JSON char par char, et remplace les \n/\r bruts trouvés à
     * l'intérieur d'une chaîne (entre " non échappés) par des \\n littéraux.
     */
    private static function escapeRawNewlinesInStrings(string $text): string
    {
        $out = '';
        $len = strlen($text);
        $inString = false;
        $escape = false;

        for ($i = 0; $i < $len; $i++) {
            $c = $text[$i];

            if ($inString) {
                if ($escape) {
                    $out .= $c;
                    $escape = false;
                    continue;
                }
                if ($c === '\\') {
                    $out .= $c;
                    $escape = true;
                    continue;
                }
                if ($c === '"') {
                    $out .= $c;
                    $inString = false;
                    continue;
                }
                if ($c === "\n") {
                    $out .= '\\n';
                    continue;
                }
                if ($c === "\r") {
                    $out .= '\\r';
                    continue;
                }
                if ($c === "\t") {
                    $out .= '\\t';
                    continue;
                }
                $out .= $c;
            } else {
                if ($c === '"') {
                    $inString = true;
                }
                $out .= $c;
            }
        }
        return $out;
    }
}
