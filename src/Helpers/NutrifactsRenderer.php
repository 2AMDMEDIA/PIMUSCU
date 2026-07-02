<?php

declare(strict_types=1);

namespace App\Helpers;

/**
 * Rend les blocs `macro` / `micro` d'un payload Nutriweb `nutrifacts` en HTML.
 * Utilisé côté page /catalogue/sku/{sku} pour l'affichage ET côté push mapping
 * vers le module aw_customproductfield → le HTML poussé côté PS est identique
 * au tableau vu dans le PIM (styles inline pour ne pas dépendre du CSS PS).
 */
final class NutrifactsRenderer
{
    /**
     * Rend un arbre de nutriments (macro ou micro) en tableau HTML.
     * Récursif : chaque `children` s'affiche indenté avec un préfixe "↳".
     *
     * @param list<array<string,mixed>> $rows Structure Nutriweb : id, parent_id, label,
     *   unit, nutrifact{value,formatted,label}, nrv{value,ri}, children[].
     */
    public static function renderTree(array $rows, string $title, string $forHeader, string $riHeader, string $riLong = ''): string
    {
        if ($rows === []) return '';

        $renderRows = function (array $items, int $depth = 0) use (&$renderRows): string {
            $out = '';
            foreach ($items as $r) {
                if (!is_array($r)) continue;
                $label = (string) ($r['label'] ?? '—');
                $unit = (string) ($r['unit'] ?? '');
                $val = $r['nutrifact']['label'] ?? $r['nutrifact']['formatted'] ?? null;
                if ($val === null && isset($r['nutrifact']['value'])) {
                    $val = $r['nutrifact']['value'] . ($unit !== '' ? ' ' . $unit : '');
                }
                $ri = $r['nrv']['ri'] ?? null;

                $tdStyle = $depth > 0
                    ? 'padding:6px 10px; padding-left:' . (10 + $depth * 16) . 'px; color:#6b7280; border-bottom:1px solid #e5e7eb; vertical-align:top;'
                    : 'padding:6px 10px; font-weight:600; border-bottom:1px solid #e5e7eb; vertical-align:top;';
                $numStyle = 'padding:6px 10px; text-align:right; font-variant-numeric:tabular-nums; white-space:nowrap; border-bottom:1px solid #e5e7eb; vertical-align:top;';
                $prefix = $depth > 0 ? '↳ ' : '';

                $out .= '<tr>'
                    . '<td style="' . $tdStyle . '">' . $prefix . Renderer::escape($label) . '</td>'
                    . '<td style="' . $numStyle . '">' . ($val !== null ? Renderer::escape((string) $val) : '—') . '</td>'
                    . '<td style="' . $numStyle . '">' . ($ri !== null && $ri !== '' ? Renderer::escape((string) $ri) : '—') . '</td>'
                    . '</tr>';

                if (!empty($r['children']) && is_array($r['children'])) {
                    $out .= $renderRows($r['children'], $depth + 1);
                }
            }
            return $out;
        };

        $thStyle = 'padding:6px 10px; text-align:left; background:#f9fafb; font-weight:600; font-size:11px; text-transform:uppercase; letter-spacing:0.04em; color:#6b7280; border-bottom:1px solid #e5e7eb;';
        $thNumStyle = str_replace('text-align:left', 'text-align:right', $thStyle);
        $riAttr = $riLong !== '' ? ' title="' . Renderer::escape($riLong) . '"' : '';

        return '<table style="width:100%; border-collapse:collapse; font-size:13px; margin-bottom:12px;">'
            . '<thead><tr>'
            . '<th style="' . $thStyle . '">' . Renderer::escape($title) . '</th>'
            . '<th style="' . $thNumStyle . '">' . Renderer::escape($forHeader) . '</th>'
            . '<th style="' . $thNumStyle . '"' . $riAttr . '>' . Renderer::escape($riHeader) . '</th>'
            . '</tr></thead>'
            . '<tbody>' . $renderRows($rows) . '</tbody>'
            . '</table>';
    }

    /**
     * Rend le bloc `nutrifacts` complet : tableau macro + tableau micro (si présent).
     *
     * @param array<string,mixed> $nutrifacts
     */
    public static function renderMacroAndMicro(array $nutrifacts): string
    {
        $tr = $nutrifacts['translations'] ?? [];
        $forHeader = (string) ($tr['nutrifacts_for_x'] ?? ('Valeurs pour ' . ($nutrifacts['nutrifacts_for'] ?? '')));
        $riHeader = (string) ($tr['ri'] ?? 'AR');
        $riLong = (string) ($tr['ri_long'] ?? '');

        $out = '';
        $macro = $nutrifacts['macro'] ?? null;
        if (is_array($macro) && $macro !== []) {
            $out .= self::renderTree($macro, 'Nutriment', $forHeader, $riHeader, $riLong);
        }
        $micro = $nutrifacts['micro'] ?? null;
        if (is_array($micro) && $micro !== []) {
            $out .= self::renderTree($micro, 'Micronutriment', $forHeader, $riHeader, $riLong);
        }
        return $out;
    }
}
