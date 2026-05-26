<?php

use App\Helpers\Renderer;

/**
 * @var string $title
 * @var array<int,array{type:string,message:string}> $flashes
 * @var array{name:string,version:string,url:string} $app
 * @var string $content_html
 * @var array<string,mixed>|null $sidebar  Données passées à la sidebar (active item, current_client...)
 * @var array<string,mixed>|null $topbar   Données pour la topbar (user info)
 */
?><!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= Renderer::escape($title) ?> — <?= Renderer::escape($app['name']) ?></title>
    <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body class="app-body">
    <div class="app-shell">
        <?= Renderer::render('partials.sidebar', ['sidebar' => $sidebar ?? [], 'app' => $app]) ?>

        <div class="app-main">
            <?= Renderer::render('partials.topbar', ['topbar' => $topbar ?? []]) ?>

            <main class="app-content">
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
            </main>
        </div>
    </div>
</body>
</html>
