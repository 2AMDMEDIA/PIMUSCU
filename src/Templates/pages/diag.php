<?php
use App\Helpers\Renderer;

/**
 * @var string $title
 * @var string $token
 * @var array<string,mixed> $db_config
 * @var array{ok:bool, error:?string, message:string} $db_test
 * @var array{ok:bool, error:?string} $probe
 * @var array<string,bool> $extensions
 * @var array<string,mixed> $env_info
 * @var array<string,mixed> $sys_info
 */
$mask = fn ($v) => is_bool($v) ? ($v ? 'oui' : 'non') : Renderer::escape((string) $v);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Diagnostic — PIM Musculation</title>
    <link rel="stylesheet" href="/assets/css/app.css">
    <style>
        .diag-table { width: 100%; font-family: ui-monospace, monospace; font-size: 13px; }
        .diag-table th, .diag-table td { padding: 6px 12px; border-bottom: 1px solid var(--color-border); text-align: left; vertical-align: top; }
        .diag-table th { background: var(--color-bg); width: 220px; font-weight: 600; }
        .diag-section { margin-bottom: 24px; }
    </style>
</head>
<body class="app-body" style="padding: 40px 20px;">
    <main style="max-width: 900px; margin: 0 auto;">
        <h1 style="margin: 0 0 24px;">🔍 Diagnostic PIM Musculation</h1>

        <div class="diag-section card">
            <div class="card__header"><h3 class="card__title">Test de connexion base de données</h3></div>
            <div class="card__body">
                <?php if ($db_test['ok']): ?>
                    <span class="badge badge--green">✓ OK</span>
                    <p style="margin: 8px 0 0; font-size: 14px;"><?= $mask($db_test['message']) ?></p>
                <?php else: ?>
                    <span class="badge badge--red">✕ Échec</span>
                    <p style="margin: 8px 0 0; font-size: 14px; color: #991b1b; font-family: ui-monospace, monospace;"><?= $mask($db_test['error']) ?></p>
                <?php endif; ?>
            </div>
        </div>

        <div class="diag-section card">
            <div class="card__header"><h3 class="card__title">Config DB lue depuis .env</h3></div>
            <div class="card__body" style="padding: 0;">
                <table class="diag-table">
                    <tr><th>DB_HOST</th><td><?= $mask($db_config['host']) ?></td></tr>
                    <tr><th>DB_PORT</th><td><?= $mask($db_config['port']) ?></td></tr>
                    <tr><th>DB_NAME</th><td><?= $mask($db_config['name']) ?></td></tr>
                    <tr><th>DB_USER</th><td><?= $mask($db_config['user']) ?></td></tr>
                    <tr>
                        <th>DB_PASS</th>
                        <td>
                            <?php if ($db_config['pass_set']): ?>
                                défini, longueur = <?= (int) $db_config['pass_length'] ?> caractères
                                <?php if ($db_config['pass_has_leading_space']): ?>
                                    <br><span style="color:#991b1b;">⚠ Espace en DÉBUT (à supprimer)</span>
                                <?php endif; ?>
                                <?php if ($db_config['pass_has_trailing_space']): ?>
                                    <br><span style="color:#991b1b;">⚠ Espace en FIN (à supprimer)</span>
                                <?php endif; ?>
                            <?php else: ?>
                                <span style="color:#991b1b;">⚠ NON DÉFINI</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr><th>DB_CHARSET</th><td><?= $mask($db_config['charset']) ?></td></tr>
                </table>
            </div>
        </div>

        <div class="diag-section card">
            <div class="card__header"><h3 class="card__title">Variables d'environnement</h3></div>
            <div class="card__body" style="padding: 0;">
                <table class="diag-table">
                    <tr><th>APP_ENV</th><td><?= $mask($env_info['app_env']) ?></td></tr>
                    <tr><th>APP_DEBUG</th><td><?= $mask($env_info['app_debug']) ?></td></tr>
                    <tr><th>APP_URL</th><td><?= $mask($env_info['app_url']) ?></td></tr>
                    <tr><th>APP_SECRET</th>
                        <td>
                            <?php if ($env_info['app_secret_set']): ?>
                                défini, longueur = <?= (int) $env_info['app_secret_length'] ?>
                            <?php else: ?>
                                <span style="color:#991b1b;">⚠ NON DÉFINI</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr><th>INSTALL_TOKEN</th><td>défini, longueur = <?= (int) $env_info['install_token_length'] ?></td></tr>
                    <tr><th>APP_TLS_VERIFY</th><td><?= $mask($env_info['tls_verify']) ?></td></tr>
                </table>
            </div>
        </div>

        <div class="diag-section card">
            <div class="card__header"><h3 class="card__title">Système & extensions PHP</h3></div>
            <div class="card__body" style="padding: 0;">
                <table class="diag-table">
                    <tr><th>PHP version</th><td><?= $mask($sys_info['php_version']) ?></td></tr>
                    <tr><th>SAPI</th><td><?= $mask($sys_info['sapi']) ?></td></tr>
                    <tr><th>.env présent</th><td><?= $mask($sys_info['env_file_exists']) ?></td></tr>
                    <tr><th>storage/install.lock</th><td><?= $mask($sys_info['install_locked']) ?></td></tr>
                    <?php foreach ($extensions as $ext => $loaded): ?>
                        <tr>
                            <th>ext-<?= $mask($ext) ?></th>
                            <td>
                                <?php if ($loaded): ?>
                                    <span class="badge badge--green">✓ chargée</span>
                                <?php else: ?>
                                    <span class="badge badge--red">✕ manquante</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            </div>
        </div>

        <p style="text-align: center; margin-top: 32px;">
            <a href="/install?token=<?= urlencode($token) ?>" class="btn btn--primary">→ Aller à /install</a>
        </p>
    </main>
</body>
</html>
