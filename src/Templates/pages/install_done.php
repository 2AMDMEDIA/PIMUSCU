<?php
use App\Helpers\Renderer;

/**
 * @var string $title
 * @var string $user_email
 * @var list<string> $applied
 */
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Installation terminée</title>
    <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body class="auth-body">
    <main class="auth-shell">
        <div class="auth-card">
            <h1 class="auth-title">✅ Installation terminée</h1>

            <div style="text-align:left; margin: 16px 0;">
                <p style="font-size: 14px;">
                    <strong>Migrations appliquées :</strong> <?= count($applied) ?>
                </p>
                <?php if (!empty($applied)): ?>
                    <ul style="font-size: 13px; color: var(--color-text-muted); margin: 4px 0 16px 20px;">
                        <?php foreach ($applied as $name): ?>
                            <li><code><?= Renderer::escape($name) ?></code></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                <p style="font-size: 14px;">
                    <strong>Super-admin créé :</strong> <code><?= Renderer::escape($user_email) ?></code>
                </p>
            </div>

            <div style="background:#fff7ed;border:1px solid #fed7aa;padding:10px 12px;border-radius:8px;font-size:13px;color:#92400e;margin:16px 0;">
                ⚠ L'installateur est désormais verrouillé via <code>storage/install.lock</code>.
                Tu peux retirer <code>INSTALL_TOKEN</code> de ton <code>.env</code> par sécurité supplémentaire.
            </div>

            <a href="/login" class="btn btn--primary btn--block">Se connecter</a>
        </div>
    </main>
</body>
</html>
