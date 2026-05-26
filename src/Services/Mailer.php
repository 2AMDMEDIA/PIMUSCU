<?php

declare(strict_types=1);

namespace App\Services;

use App\Bootstrap;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailException;
use RuntimeException;

/**
 * Envoi d'emails via SMTP (PHPMailer). Lit la config depuis app.mail.
 * En APP_DEBUG, écrit l'email dans storage/logs/ au lieu de l'envoyer si SMTP n'est pas configuré.
 */
final class Mailer
{
    public function send(string $toEmail, string $toName, string $subject, string $htmlBody, ?string $textBody = null): void
    {
        $cfg = Bootstrap::config('app.mail') ?? [];

        // Si pas de SMTP configuré → log dans storage/logs (pratique en dev)
        if (empty($cfg['host'])) {
            $this->logEmail($toEmail, $subject, $htmlBody);
            return;
        }

        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host = $cfg['host'];
            $mail->Port = (int) $cfg['port'];
            $mail->SMTPAuth = !empty($cfg['user']);
            $mail->Username = $cfg['user'];
            $mail->Password = $cfg['pass'];
            $mail->CharSet = 'UTF-8';

            $encryption = strtolower((string) ($cfg['encryption'] ?? 'tls'));
            if ($encryption === 'ssl') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            } elseif ($encryption === 'tls') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            }

            $mail->setFrom($cfg['from_email'] ?? 'noreply@example.com', $cfg['from_name'] ?? 'PIM Musculation');
            $mail->addAddress($toEmail, $toName);

            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $htmlBody;
            $mail->AltBody = $textBody ?? strip_tags($htmlBody);

            $mail->send();
        } catch (MailException $e) {
            throw new RuntimeException('Échec d\'envoi email : ' . $mail->ErrorInfo, 0, $e);
        }
    }

    private function logEmail(string $to, string $subject, string $body): void
    {
        $logFile = Bootstrap::rootPath() . '/storage/logs/mail.log';
        $entry = sprintf(
            "[%s] To: %s\nSubject: %s\n%s\n---\n\n",
            date('Y-m-d H:i:s'),
            $to,
            $subject,
            $body
        );
        @file_put_contents($logFile, $entry, FILE_APPEND);
    }
}
