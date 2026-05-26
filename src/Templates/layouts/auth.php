<?php

use App\Helpers\Renderer;

/**
 * @var string $title
 * @var array<int,array{type:string,message:string}> $flashes
 * @var array{name:string,version:string,url:string} $app
 * @var string $content_html  Contenu de la page rendu en amont
 */
?><!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= Renderer::escape($title) ?> — <?= Renderer::escape($app['name']) ?></title>
    <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body class="auth-body">
    <main class="auth-shell">
        <div class="auth-card">
            <header class="auth-header">
                <h1 class="auth-brand"><?= Renderer::escape($app['name']) ?></h1>
                <p class="auth-version">v<?= Renderer::escape($app['version']) ?></p>
            </header>

            <?php if (!empty($flashes)): ?>
                <div class="flashes">
                    <?php foreach ($flashes as $flash): ?>
                        <div class="flash flash--<?= Renderer::escape($flash['type']) ?>">
                            <?= Renderer::escape($flash['message']) ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?= $content_html ?>
        </div>
    </main>
</body>
</html>
