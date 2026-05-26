<?php

declare(strict_types=1);

namespace App\Helpers;

use RuntimeException;

/**
 * Moteur de templates "PHP plein". Charge un fichier de vue depuis src/Templates,
 * extrait les variables fournies, et capture la sortie. Aucun moteur tiers (pas de Twig).
 */
final class Renderer
{
    private static string $basePath = '';
    private static array $shared = [];

    public static function init(string $basePath): void
    {
        self::$basePath = rtrim($basePath, '/\\');
    }

    public static function share(string $key, mixed $value): void
    {
        self::$shared[$key] = $value;
    }

    public static function render(string $view, array $data = []): string
    {
        $file = self::$basePath . '/' . str_replace('.', '/', $view) . '.php';
        if (!is_file($file)) {
            throw new RuntimeException("Vue introuvable : {$view} ({$file})");
        }
        $merged = array_merge(self::$shared, $data);
        extract($merged, EXTR_SKIP);
        ob_start();
        require $file;
        return (string) ob_get_clean();
    }

    public static function output(string $view, array $data = []): void
    {
        echo self::render($view, $data);
    }

    /**
     * Rend une page dans un layout. Le contenu rendu de la page est injecté
     * dans le layout via la variable $content_html.
     */
    public static function renderWithLayout(string $layout, string $view, array $data = []): string
    {
        $content = self::render($view, $data);
        return self::render($layout, array_merge($data, ['content_html' => $content]));
    }

    public static function outputWithLayout(string $layout, string $view, array $data = []): void
    {
        echo self::renderWithLayout($layout, $view, $data);
    }

    public static function escape(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
