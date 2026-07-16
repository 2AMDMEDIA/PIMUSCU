<?php
/**
 * Page de diagnostic reseau STANDALONE — accessible sans authentification.
 * URL : https://pimuscu.2amd-media.com/network-diag.php
 * Objectif : partager cette URL avec l'hebergeur pour qu'il voie l'etat de
 * connectivite sortante du serveur PIM (blocages, timeouts, IP filtrees...).
 *
 * SECURITE : aucune donnee sensible n'est exposee (pas de cle API, pas d'acces DB).
 * Les cibles sont hardcodees, aucun input utilisateur n'est accepte.
 * Anti-abus : rate limit fichier (1 exec toutes les 15s).
 */

declare(strict_types=1);

@set_time_limit(60);
header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');

// ---- Rate limit simple (fichier de timestamp) --------------------------------
$rateFile = sys_get_temp_dir() . '/pim-netdiag-last.txt';
$lastRun = is_file($rateFile) ? (int) @file_get_contents($rateFile) : 0;
$since = time() - $lastRun;
if ($since < 15) {
    http_response_code(429);
    echo '<h1>⏱ Trop de requêtes</h1>';
    echo '<p>Attendre ' . (15 - $since) . ' seconde(s) avant de relancer le diagnostic.</p>';
    exit;
}
@file_put_contents($rateFile, (string) time());

// ---- Load app (Bootstrap + DB) pour recuperer les cles API du client -------
// Best-effort : si l'app plante, on tombe sur des probes anonymes uniquement.
$psApiKey = null;
$awKey = null;
$SHOP_URL = 'https://www.musculation.com'; // fallback si pas de client
try {
    require_once __DIR__ . '/../vendor/autoload.php';
    \App\Bootstrap::boot(__DIR__ . '/..');
    $pdo = \App\Database::pdo();
    // Premier client actif (setup mono-boutique musculation)
    $stmt = $pdo->query('SELECT prestashop_url, prestashop_api_key_encrypted, aw_cpf_api_key_encrypted FROM clients ORDER BY created_at ASC LIMIT 1');
    $row = $stmt !== false ? $stmt->fetch(\PDO::FETCH_ASSOC) : null;
    if ($row !== null) {
        if (!empty($row['prestashop_url'])) $SHOP_URL = rtrim((string) $row['prestashop_url'], '/');
        if (!empty($row['prestashop_api_key_encrypted'])) {
            $psApiKey = \App\Helpers\Encryption::decrypt((string) $row['prestashop_api_key_encrypted']);
        }
        if (!empty($row['aw_cpf_api_key_encrypted'])) {
            $awKey = \App\Helpers\Encryption::decrypt((string) $row['aw_cpf_api_key_encrypted']);
        }
    }
} catch (\Throwable $e) {
    // silencieux : on continue sans les cles
}

$SHOP_HOST = (string) (parse_url($SHOP_URL, PHP_URL_HOST) ?? 'www.musculation.com');

$tcpTargets = [
    [$SHOP_HOST,       443, 'Boutique PrestaShop cible (' . $SHOP_HOST . ')'],
    ['www.google.com', 443, 'Google (contrôle Internet OK)'],
    ['www.cloudflare.com', 443, 'Cloudflare (contrôle CDN OK)'],
    ['1.1.1.1',        443, 'Cloudflare DNS (contrôle réseau brut)'],
];

// Probes HTTP. Chaque entree : [method, url, label, headers_optional, auth_optional].
$httpProbes = [
    ['GET', $SHOP_URL . '/',              'Homepage boutique',               [], null],
    ['GET', $SHOP_URL . '/api/',          'PrestaShop Webservice racine (auth)', [], $psApiKey],
    ['GET', $SHOP_URL . '/api/products?display=[id]&limit=0,1', 'PS /api/products?limit=0,1 (auth)', [], $psApiKey],
    ['GET', $SHOP_URL . '/modules/aw_customproductfield/api.php?action=schema', 'aw_customproductfield ?action=schema (auth X-API-Key)',
        $awKey !== null ? ['X-API-Key: ' . $awKey] : [], null],
    ['GET', 'https://www.google.com/',    'Google homepage (contrôle)',      [], null],
];

// ---- Helpers -----------------------------------------------------------------
function tcpProbe(string $host, int $port, int $timeoutSec = 5): array {
    $start = microtime(true);
    $errno = 0; $errstr = '';
    $fp = @fsockopen($host, $port, $errno, $errstr, $timeoutSec);
    $ms = round(1000 * (microtime(true) - $start), 1);
    if ($fp === false) {
        return ['ok' => false, 'ms' => $ms, 'error' => $errstr !== '' ? $errstr : "code $errno"];
    }
    fclose($fp);
    return ['ok' => true, 'ms' => $ms, 'error' => null];
}

/**
 * @param list<string> $headers Headers HTTP additionnels (ex: 'X-API-Key: xxx')
 * @param ?string $basicAuth Cle pour Basic Auth (utilisee comme user, password vide)
 */
function httpProbe(string $method, string $url, array $headers = [], ?string $basicAuth = null): array {
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => array_merge(['Accept: */*'], $headers),
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36 PIM-Musculation-NetDiag/1.0',
        CURLOPT_NOBODY => $method === 'HEAD',
    ];
    if ($basicAuth !== null && $basicAuth !== '') {
        $opts[CURLOPT_USERPWD] = $basicAuth . ':';
        $opts[CURLOPT_HTTPAUTH] = CURLAUTH_BASIC;
    }
    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch);
    $info = curl_getinfo($ch);
    $err = curl_error($ch);
    curl_close($ch);
    return [
        'http_code' => (int) ($info['http_code'] ?? 0),
        'error' => $err !== '' ? $err : null,
        'dns_ms' => round(1000 * (float) ($info['namelookup_time'] ?? 0), 1),
        'connect_ms' => round(1000 * ((float) ($info['connect_time'] ?? 0) - (float) ($info['namelookup_time'] ?? 0)), 1),
        'tls_ms' => round(1000 * ((float) ($info['appconnect_time'] ?? 0) - (float) ($info['connect_time'] ?? 0)), 1),
        'total_ms' => round(1000 * (float) ($info['total_time'] ?? 0), 1),
        'body_size' => $body === false ? 0 : strlen((string) $body),
    ];
}

function publicIp(): ?string {
    foreach (['https://api.ipify.org', 'https://ifconfig.me/ip', 'https://icanhazip.com'] as $u) {
        $ch = curl_init($u);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_USERAGENT => 'PIM-Musculation-NetDiag/1.0',
        ]);
        $ip = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($ip !== false && $code === 200) {
            $ip = trim((string) $ip);
            if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
        }
    }
    return null;
}

function e(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

// ---- Collecte ----------------------------------------------------------------
$serverInfo = [
    'server_name' => (string) ($_SERVER['SERVER_NAME'] ?? 'unknown'),
    'php_version' => PHP_VERSION,
    'server_software' => (string) ($_SERVER['SERVER_SOFTWARE'] ?? 'unknown'),
    'hostname' => gethostname() ?: 'unknown',
    'timestamp' => date('Y-m-d H:i:s T'),
    'php_sapi' => PHP_SAPI,
];
$ip = publicIp();
$tcpResults = [];
foreach ($tcpTargets as [$h, $p, $lbl]) {
    $r = tcpProbe($h, $p);
    $r['host'] = $h; $r['port'] = $p; $r['label'] = $lbl;
    $tcpResults[] = $r;
}
$httpResults = [];
foreach ($httpProbes as $probe) {
    [$m, $u, $lbl, $hdrs, $basic] = $probe;
    $r = httpProbe($m, $u, $hdrs, $basic);
    $r['method'] = $m;
    $r['url'] = $u;
    $r['label'] = $lbl;
    // Marque si la probe utilisait une auth (pour l'affichage) sans exposer la cle.
    $r['auth'] = null;
    if ($basic !== null) $r['auth'] = 'Basic ' . substr($basic, 0, 4) . '***';
    foreach ($hdrs as $h) {
        if (stripos($h, 'X-API-Key:') === 0) {
            $val = trim(substr($h, strlen('X-API-Key:')));
            $r['auth'] = 'X-API-Key: ' . substr($val, 0, 4) . '***';
        }
    }
    $httpResults[] = $r;
}
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="robots" content="noindex, nofollow">
<title>Network Diagnostic — PIM Musculation</title>
<style>
  body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; margin: 0; padding: 24px; background: #f7f9fc; color: #1f2937; }
  h1 { margin: 0 0 6px; font-size: 22px; }
  h2 { margin: 24px 0 8px; font-size: 15px; text-transform: uppercase; letter-spacing: 0.06em; color: #6b7280; }
  .wrap { max-width: 1200px; margin: 0 auto; }
  .card { background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 16px; margin-bottom: 16px; }
  .kv { display: grid; grid-template-columns: 200px 1fr; gap: 4px 12px; font-size: 13px; }
  .kv dt { color: #6b7280; }
  .kv dd { margin: 0; font-family: ui-monospace, Menlo, Consolas, monospace; }
  table { width: 100%; border-collapse: collapse; font-size: 13px; }
  th, td { padding: 8px 10px; text-align: left; border-bottom: 1px solid #e5e7eb; vertical-align: top; }
  th { background: #f9fafb; font-weight: 600; font-size: 11px; text-transform: uppercase; letter-spacing: 0.04em; color: #6b7280; }
  .num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
  .ok { color: #166534; font-weight: 600; }
  .ko { color: #dc2626; font-weight: 600; }
  .ip-banner { background: #eff6ff; border: 1px solid #bfdbfe; padding: 14px 16px; border-radius: 8px; margin-bottom: 16px; }
  .ip-banner code { font-size: 16px; background: #fff; padding: 2px 10px; border-radius: 4px; }
  .note { background: #fef3c7; border: 1px solid #fcd34d; padding: 10px 12px; border-radius: 8px; font-size: 12px; margin-bottom: 16px; color: #92400e; }
  .verdict { background: #f0fdf4; border: 1px solid #86efac; padding: 12px 14px; border-radius: 8px; margin-top: 16px; font-size: 13px; color: #166534; }
  .verdict.bad { background: #fef2f2; border-color: #fecaca; color: #991b1b; }
  code { font-family: ui-monospace, Menlo, Consolas, monospace; }
</style>
</head>
<body>
<div class="wrap">
  <h1>🩺 Diagnostic réseau PIM Musculation</h1>
  <p style="color:#6b7280; font-size:13px; margin: 0 0 16px;">
    Cette page teste la connectivité sortante du serveur PIM (<code><?= e($serverInfo['server_name']) ?></code>) vers <code><?= e($SHOP_HOST) ?></code>
    et des cibles de contrôle. À partager avec l'hébergeur en cas de problème réseau.
  </p>

  <div class="ip-banner">
    <strong>🌐 IP publique du serveur PIM :</strong>
    <code><?= $ip !== null ? e($ip) : '(indisponible — pas d\'accès sortant du tout)' ?></code>
    <div style="margin-top:4px; font-size:12px; color:#1e40af;">
      C'est cette IP que la boutique cible voit lorsqu'elle reçoit une requête depuis ce serveur.
    </div>
  </div>

  <div class="note">
    ℹ Cette page est <strong>publique</strong> pour permettre à l'hébergeur d'y accéder sans compte.
    Les clés API stockées dans le PIM sont utilisées pour les probes authentifiées,
    mais <strong>jamais affichées</strong> (masquées en <code>xxxx***</code>).
    Rate-limitée à 1 exécution / 15 secondes.
    <div style="margin-top:6px;">
      Clés détectées : <strong>PS Webservice</strong> <?= $psApiKey !== null ? '✓' : '✗ (non configurée)' ?>
      · <strong>aw_customproductfield</strong> <?= $awKey !== null ? '✓' : '✗ (non configurée)' ?>
    </div>
  </div>

  <div class="card">
    <h2>Informations serveur</h2>
    <dl class="kv">
      <dt>Serveur HTTP</dt><dd><?= e($serverInfo['server_software']) ?></dd>
      <dt>SAPI PHP</dt><dd><?= e($serverInfo['php_sapi']) ?> (<?= e($serverInfo['php_version']) ?>)</dd>
      <dt>Hostname</dt><dd><?= e($serverInfo['hostname']) ?></dd>
      <dt>Server name</dt><dd><?= e($serverInfo['server_name']) ?></dd>
      <dt>Timestamp</dt><dd><?= e($serverInfo['timestamp']) ?></dd>
      <dt>IP publique</dt><dd><?= $ip !== null ? e($ip) : '(indisponible)' ?></dd>
    </dl>
  </div>

  <div class="card">
    <h2>⚡ Smoke tests TCP (fsockopen, timeout 5s)</h2>
    <table>
      <thead><tr><th>Host</th><th>Port</th><th class="num">Temps</th><th>Résultat</th></tr></thead>
      <tbody>
        <?php foreach ($tcpResults as $r): ?>
          <tr>
            <td>
              <strong><?= e($r['label']) ?></strong>
              <div style="font-size:11px; color:#6b7280;"><code><?= e($r['host']) ?></code></div>
            </td>
            <td class="num"><?= $r['port'] ?></td>
            <td class="num <?= $r['ok'] ? 'ok' : 'ko' ?>"><?= number_format((float) $r['ms'], 1, ',', ' ') ?> ms</td>
            <td>
              <?php if ($r['ok']): ?>
                <span class="ok">✓ TCP OK</span>
              <?php else: ?>
                <span class="ko">✗ Bloqué</span>
                <span style="color:#6b7280; font-size:12px; margin-left:6px;"><?= e((string) $r['error']) ?></span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div class="card">
    <h2>🔍 Probes HTTP (cURL, 10s connect / 15s total)</h2>
    <table>
      <thead>
        <tr>
          <th>Endpoint</th><th class="num">HTTP</th><th class="num">DNS</th><th class="num">Connect</th>
          <th class="num">TLS</th><th class="num">Total</th><th class="num">Body</th><th>Erreur</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($httpResults as $r): ?>
          <?php
            $good = $r['error'] === null && $r['http_code'] > 0 && $r['http_code'] < 500;
            $totCol = $r['total_ms'] > 10000 ? 'ko' : ($r['total_ms'] > 3000 ? '' : 'ok');
          ?>
          <tr>
            <td>
              <strong><?= e($r['label']) ?></strong>
              <div style="font-size:11px; color:#6b7280; word-break:break-all;"><code><?= e($r['method'] . ' ' . $r['url']) ?></code></div>
              <?php if (!empty($r['auth'])): ?>
                <div style="font-size:11px; color:#0369a1; margin-top:2px;">🔑 <?= e($r['auth']) ?></div>
              <?php endif; ?>
            </td>
            <td class="num <?= $good ? 'ok' : 'ko' ?>">
              <?= $r['error'] !== null ? '—' : ($r['http_code'] ?: '0') ?>
            </td>
            <td class="num"><?= number_format((float) $r['dns_ms'], 1, ',', ' ') ?> ms</td>
            <td class="num"><?= number_format((float) $r['connect_ms'], 1, ',', ' ') ?> ms</td>
            <td class="num"><?= number_format((float) $r['tls_ms'], 1, ',', ' ') ?> ms</td>
            <td class="num <?= $totCol ?>"><?= number_format((float) $r['total_ms'], 1, ',', ' ') ?> ms</td>
            <td class="num"><?= number_format((int) $r['body_size'], 0, ',', ' ') ?></td>
            <td style="font-size:12px; color:#dc2626; max-width:280px; word-break:break-word;">
              <?= $r['error'] !== null ? e($r['error']) : '' ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <?php
    // Verdict synthetique
    $shopTcp = $tcpResults[0]['ok']; // musculation.com
    $googleTcp = $tcpResults[1]['ok'];
    $cfTcp = $tcpResults[2]['ok'];
    $shopHttp = $httpResults[0]['error'] === null; // homepage boutique
    $googleHttp = ($httpResults[4] ?? ['error' => 'x'])['error'] === null; // google homepage
  ?>
  <?php if ($shopTcp && $shopHttp): ?>
    <div class="verdict">✓ Tout est fonctionnel : le serveur PIM peut atteindre <?= e($SHOP_HOST) ?> normalement.</div>
  <?php elseif (!$googleTcp && !$cfTcp && !$shopTcp): ?>
    <div class="verdict bad">
      ✗ <strong>Aucune connectivité sortante</strong> depuis ce serveur (ni Google, ni Cloudflare, ni la boutique).<br>
      → Problème d'infrastructure côté hébergeur : firewall sortant, config réseau, DNS.
      À corriger côté hébergeur avant toute autre action.
    </div>
  <?php elseif ($googleTcp && !$shopTcp): ?>
    <div class="verdict bad">
      ✗ Le serveur PIM peut atteindre Internet (Google/Cloudflare OK) MAIS <strong><?= e($SHOP_HOST) ?> est spécifiquement bloqué</strong> depuis l'IP <code><?= e($ip ?? '?') ?></code>.<br>
      → L'IP ci-dessus est probablement blacklistée côté <?= e($SHOP_HOST) ?> (Cloudflare, WAF, .htaccess, fail2ban, module sécurité PrestaShop).<br>
      Action : <strong>demander le whitelist de <code><?= e($ip ?? '?') ?></code></strong> à l'admin de <?= e($SHOP_HOST) ?>.
    </div>
  <?php elseif ($shopTcp && !$shopHttp): ?>
    <div class="verdict bad">
      ⚠ Le TCP passe mais HTTP échoue → filtre au niveau applicatif (HTTPS/WAF).
      Peut être un filtrage User-Agent, TLS fingerprint, ou rate limit.
    </div>
  <?php else: ?>
    <div class="verdict bad">Cas mixte à investiguer, voir les résultats détaillés ci-dessus.</div>
  <?php endif; ?>

  <p style="margin-top:24px; font-size:11px; color:#9ca3af; text-align:center;">
    Diagnostic généré le <?= e($serverInfo['timestamp']) ?>
    · Rate limit : 1 exécution / 15 secondes
  </p>
</div>
</body>
</html>
