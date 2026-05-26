<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Bootstrap;
use App\Database;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailException;

/**
 * Page de diagnostic — affiche en clair la config DB que PHP voit, teste la
 * connexion, liste les extensions PHP, version, .env présent, etc.
 *
 *   /diag?token=XXX
 *
 * Protégée par le même INSTALL_TOKEN que /install. Utile pour debugger les
 * problèmes de credentials DB sur un nouveau déploiement sans avoir à
 * activer APP_DEBUG (qui exposerait des stack traces).
 */
final class DiagnosticController extends BaseController
{
    private function checkAuth(): void
    {
        $expected = (string) ($_ENV['INSTALL_TOKEN'] ?? '');
        if ($expected === '') {
            http_response_code(403);
            echo '<h1>403 — Diagnostic désactivé</h1><p>Aucun <code>INSTALL_TOKEN</code> configuré dans <code>.env</code>.</p>';
            exit;
        }
        $provided = (string) ($this->input('token') ?? '');
        if (!hash_equals($expected, $provided)) {
            http_response_code(403);
            echo '<h1>403 — Token invalide</h1>';
            exit;
        }
    }

    public function show(): void
    {
        $this->checkAuth();

        // Config DB (sans le password)
        $dbConfig = [
            'host' => $_ENV['DB_HOST'] ?? '(non défini)',
            'port' => $_ENV['DB_PORT'] ?? '(non défini)',
            'name' => $_ENV['DB_NAME'] ?? '(non défini)',
            'user' => $_ENV['DB_USER'] ?? '(non défini)',
            'pass_set' => isset($_ENV['DB_PASS']) && $_ENV['DB_PASS'] !== '',
            'pass_length' => isset($_ENV['DB_PASS']) ? strlen((string) $_ENV['DB_PASS']) : 0,
            'pass_has_leading_space' => isset($_ENV['DB_PASS']) && str_starts_with((string) $_ENV['DB_PASS'], ' '),
            'pass_has_trailing_space' => isset($_ENV['DB_PASS']) && str_ends_with((string) $_ENV['DB_PASS'], ' '),
            'charset' => $_ENV['DB_CHARSET'] ?? '(non défini)',
        ];

        // Test direct PDO avec ces valeurs (sans passer par Database singleton, pour pouvoir
        // retenter avec d'autres valeurs si besoin)
        $dbTest = $this->testConnection($dbConfig);

        // Test via la Database app (chemin réel utilisé en prod)
        $probe = Database::probe();

        // Extensions PHP requises
        $extensions = [
            'pdo_mysql' => extension_loaded('pdo_mysql'),
            'curl' => extension_loaded('curl'),
            'openssl' => extension_loaded('openssl'),
            'mbstring' => extension_loaded('mbstring'),
            'json' => extension_loaded('json'),
        ];

        $envInfo = [
            'app_env' => $_ENV['APP_ENV'] ?? '(non défini)',
            'app_debug' => $_ENV['APP_DEBUG'] ?? '(non défini)',
            'app_url' => $_ENV['APP_URL'] ?? '(non défini)',
            'app_secret_set' => isset($_ENV['APP_SECRET']) && $_ENV['APP_SECRET'] !== '',
            'app_secret_length' => isset($_ENV['APP_SECRET']) ? strlen((string) $_ENV['APP_SECRET']) : 0,
            'install_token_length' => isset($_ENV['INSTALL_TOKEN']) ? strlen((string) $_ENV['INSTALL_TOKEN']) : 0,
            'tls_verify' => $_ENV['APP_TLS_VERIFY'] ?? '(non défini)',
        ];

        $sysInfo = [
            'php_version' => PHP_VERSION,
            'sapi' => PHP_SAPI,
            'env_file_exists' => is_file(Bootstrap::rootPath() . '/.env'),
            'install_locked' => is_file(Bootstrap::rootPath() . '/storage/install.lock'),
        ];

        $this->render('pages.diag', [
            'title' => 'Diagnostic',
            'token' => $expected,
            'db_config' => $dbConfig,
            'db_test' => $dbTest,
            'probe' => $probe,
            'extensions' => $extensions,
            'env_info' => $envInfo,
            'sys_info' => $sysInfo,
        ]);
    }

    /**
     * /diag-mail?token=XXX&to=destinataire@example.com
     *
     * Lance un envoi test via PHPMailer avec SMTPDebug=3 et affiche tout le
     * dialogue SMTP en clair (HELO, AUTH, MAIL FROM, RCPT TO, codes retour).
     * Sortie text/plain pour lecture facile.
     */
    public function mail(): void
    {
        $this->checkAuth();

        header('Content-Type: text/plain; charset=utf-8');

        $cfg = [
            'host' => (string) ($_ENV['MAIL_HOST'] ?? ''),
            'port' => (int) ($_ENV['MAIL_PORT'] ?? 587),
            'user' => (string) ($_ENV['MAIL_USER'] ?? ''),
            'pass' => (string) ($_ENV['MAIL_PASS'] ?? ''),
            'encryption' => strtolower((string) ($_ENV['MAIL_ENCRYPTION'] ?? 'tls')),
            'from_email' => (string) ($_ENV['MAIL_FROM_EMAIL'] ?? ''),
            'from_name' => (string) ($_ENV['MAIL_FROM_NAME'] ?? 'PIM Musculation'),
        ];

        $to = (string) ($this->input('to') ?? $cfg['from_email']);

        echo "=== DIAG MAIL ===\n\n";
        echo "Config SMTP (lue depuis .env) :\n";
        echo '  MAIL_HOST       = ' . ($cfg['host'] !== '' ? $cfg['host'] : '(VIDE — fallback log file)') . "\n";
        echo '  MAIL_PORT       = ' . $cfg['port'] . "\n";
        echo '  MAIL_USER       = ' . ($cfg['user'] !== '' ? $cfg['user'] : '(vide)') . "\n";
        echo '  MAIL_PASS       = ' . ($cfg['pass'] !== '' ? '(défini, ' . strlen($cfg['pass']) . ' caractères)' : '(VIDE)') . "\n";
        echo '  MAIL_ENCRYPTION = ' . $cfg['encryption'] . "\n";
        echo '  MAIL_FROM_EMAIL = ' . ($cfg['from_email'] !== '' ? $cfg['from_email'] : '(VIDE)') . "\n";
        echo '  MAIL_FROM_NAME  = ' . $cfg['from_name'] . "\n\n";
        echo "Destinataire test : {$to}\n\n";

        if ($cfg['host'] === '') {
            echo "❌ MAIL_HOST est vide. Aucune tentative SMTP — les emails sont écrits dans storage/logs/mail.log.\n";
            return;
        }
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            echo "❌ Destinataire invalide. Ajoute ?to=ton@email.tld dans l'URL.\n";
            return;
        }

        echo "--- TRANSCRIPT SMTP ---\n";

        $mail = new PHPMailer(true);
        $mail->SMTPDebug = 3; // CLIENT + SERVER + CONNECTION
        $mail->Debugoutput = function (string $str, int $level) {
            echo trim($str) . "\n";
        };

        try {
            $mail->isSMTP();
            $mail->Host = $cfg['host'];
            $mail->Port = $cfg['port'];
            $mail->SMTPAuth = $cfg['user'] !== '';
            $mail->Username = $cfg['user'];
            $mail->Password = $cfg['pass'];
            $mail->CharSet = 'UTF-8';
            $mail->Timeout = 15;

            if ($cfg['encryption'] === 'ssl') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            } elseif ($cfg['encryption'] === 'tls') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            }

            $mail->setFrom($cfg['from_email'] ?: 'noreply@example.com', $cfg['from_name']);
            $mail->addAddress($to);
            $mail->Subject = '[PIM Musculation] Test SMTP ' . date('Y-m-d H:i:s');
            $mail->isHTML(true);
            $mail->Body = '<p>Ceci est un email de test envoyé depuis /diag-mail.</p><p>Si tu le reçois, le SMTP est OK ✅</p>';
            $mail->AltBody = "Email de test depuis /diag-mail. SMTP OK.";

            $mail->send();
            echo "\n--- RESULT ---\n";
            echo "✅ Envoyé. Vérifie la boite de réception (et les spams).\n";
        } catch (MailException $e) {
            echo "\n--- RESULT ---\n";
            echo "❌ ÉCHEC.\n";
            echo "PHPMailer ErrorInfo : " . $mail->ErrorInfo . "\n";
            echo "Exception message   : " . $e->getMessage() . "\n";
        } catch (\Throwable $e) {
            echo "\n--- RESULT ---\n";
            echo "❌ EXCEPTION non-PHPMailer : " . get_class($e) . "\n";
            echo "Message : " . $e->getMessage() . "\n";
        }
    }

    /**
     * @param array<string,mixed> $config
     * @return array{ok:bool, error:?string, message:string}
     */
    private function testConnection(array $config): array
    {
        if ($config['user'] === '(non défini)' || $config['host'] === '(non défini)') {
            return ['ok' => false, 'error' => 'Variables DB manquantes', 'message' => 'DB_HOST ou DB_USER non défini dans .env'];
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            $config['host'],
            $config['port'],
            $config['name'],
            $config['charset'],
        );

        try {
            $pdo = new \PDO(
                $dsn,
                (string) $config['user'],
                (string) ($_ENV['DB_PASS'] ?? ''),
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_TIMEOUT => 5],
            );
            $version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
            return ['ok' => true, 'error' => null, 'message' => 'Connexion réussie. MySQL/MariaDB version : ' . $version];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage(), 'message' => 'Échec'];
        }
    }
}
