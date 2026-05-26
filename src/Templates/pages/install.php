<?php
use App\Helpers\Renderer;

/**
 * @var string $title
 * @var string $token
 * @var array{ok:bool, error:?string} $db_probe
 * @var list<array{name:string,applied_at:?string,applied:bool,size:int}> $migrations
 * @var ?string $migrations_error
 * @var bool $users_exist
 * @var string $app_name
 * @var string $csrf_token
 * @var array<int,array{type:string,message:string}> $flashes
 */
$pending = array_filter($migrations, fn ($m) => !$m['applied']);
$pendingCount = count($pending);
$canInstall = $db_probe['ok'] && !$users_exist;
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Installation — <?= Renderer::escape($app_name) ?></title>
    <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body class="app-body" style="padding: 40px 20px;">
    <main style="max-width: 720px; margin: 0 auto;">
        <h1 style="margin: 0 0 24px;">🔧 Installation de <?= Renderer::escape($app_name) ?></h1>

        <?php if (!empty($flashes)): ?>
            <div class="flashes" style="margin-bottom: 20px;">
                <?php foreach ($flashes as $f): ?>
                    <div class="flash flash--<?= Renderer::escape($f['type']) ?>">
                        <?= Renderer::escape($f['message']) ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- État DB -->
        <div class="card" style="margin-bottom: 16px;">
            <div class="card__header">
                <h3 class="card__title">1. Connexion base de données</h3>
            </div>
            <div class="card__body">
                <?php if ($db_probe['ok']): ?>
                    <span class="badge badge--green">✓ Connectée</span>
                <?php else: ?>
                    <span class="badge badge--red">✕ Erreur</span>
                    <p style="margin: 8px 0 0; font-size: 13px; color: #991b1b; font-family: ui-monospace, monospace;">
                        <?= Renderer::escape($db_probe['error'] ?? 'Erreur inconnue') ?>
                    </p>
                    <p style="margin: 12px 0 0; font-size: 13px; color: var(--color-text-muted);">
                        Vérifie les valeurs <code>DB_HOST</code>, <code>DB_NAME</code>, <code>DB_USER</code>, <code>DB_PASS</code> dans <code>.env</code>.
                    </p>
                <?php endif; ?>
            </div>
        </div>

        <!-- État migrations -->
        <?php if ($db_probe['ok']): ?>
            <div class="card" style="margin-bottom: 16px;">
                <div class="card__header">
                    <h3 class="card__title">2. Migrations DB</h3>
                </div>
                <div class="card__body">
                    <?php if ($migrations_error): ?>
                        <p style="color: #991b1b; font-family: ui-monospace, monospace; font-size: 13px;">
                            <?= Renderer::escape($migrations_error) ?>
                        </p>
                    <?php elseif ($pendingCount === 0): ?>
                        <span class="badge badge--green">✓ À jour</span>
                        <span style="margin-left: 8px; font-size: 13px; color: var(--color-text-muted);">
                            <?= count($migrations) ?> migration<?= count($migrations) > 1 ? 's' : '' ?> appliquée<?= count($migrations) > 1 ? 's' : '' ?>
                        </span>
                    <?php else: ?>
                        <span class="badge badge--amber">⏳ <?= $pendingCount ?> en attente</span>
                        <ul style="margin: 12px 0 0; padding-left: 20px; font-size: 13px;">
                            <?php foreach ($pending as $m): ?>
                                <li><code><?= Renderer::escape($m['name']) ?></code></li>
                            <?php endforeach; ?>
                        </ul>
                        <p style="margin: 12px 0 0; font-size: 13px; color: var(--color-text-muted);">
                            Ces migrations seront appliquées automatiquement au lancement de l'install.
                        </p>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- État super-admin -->
        <?php if ($db_probe['ok']): ?>
            <div class="card" style="margin-bottom: 16px;">
                <div class="card__header">
                    <h3 class="card__title">3. Compte super-admin</h3>
                </div>
                <div class="card__body">
                    <?php if ($users_exist): ?>
                        <span class="badge badge--amber">⚠ Un ou plusieurs utilisateurs existent déjà</span>
                        <p style="margin: 12px 0 0; font-size: 13px; color: var(--color-text-muted);">
                            L'installation n'a pas besoin d'être relancée. Va directement sur
                            <a href="/login">/login</a> pour te connecter, puis utilise
                            <a href="/admin/migrations">/admin/migrations</a> pour les futures migrations.
                        </p>
                    <?php else: ?>
                        <span class="badge badge--gray">Aucun utilisateur en base</span>
                        <p style="margin: 8px 0 0; font-size: 13px; color: var(--color-text-muted);">
                            Crée le premier compte super-admin ci-dessous.
                        </p>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- Formulaire d'installation -->
        <?php if ($canInstall): ?>
            <form method="POST" action="/install" class="card">
                <div class="card__header">
                    <h3 class="card__title">Lancer l'installation</h3>
                </div>
                <div class="card__body" style="display: flex; flex-direction: column; gap: 16px;">
                    <input type="hidden" name="_csrf" value="<?= Renderer::escape($csrf_token) ?>">
                    <input type="hidden" name="token" value="<?= Renderer::escape($token) ?>">

                    <label class="field">
                        <span class="field__label">Email super-admin *</span>
                        <input type="email" name="email" required autofocus>
                    </label>
                    <label class="field">
                        <span class="field__label">Nom complet</span>
                        <input type="text" name="full_name">
                    </label>
                    <label class="field">
                        <span class="field__label">Mot de passe (8+ caractères) *</span>
                        <input type="password" name="password" required minlength="8" autocomplete="new-password">
                    </label>

                    <button type="submit" class="btn btn--primary btn--block"
                            onclick="return confirm('Lancer l\'installation ? Cette action est irréversible (l\'installateur sera ensuite verrouillé).');">
                        🚀 Lancer l'installation
                    </button>
                </div>
            </form>
        <?php endif; ?>
    </main>
</body>
</html>
