<?php
use App\Helpers\Renderer;

/**
 * @var string $shop_url
 * @var bool $has_api_key
 * @var bool $has_aw_key
 * @var ?string $public_ip
 * @var list<array{label:string, url:string, http_code:int, error:?string,
 *   dns_ms:float, connect_ms:float, tls_ms:float, total_ms:float, body_size:int, body_snippet:string}> $results
 */
$public_ip = $public_ip ?? null;
$fmtMs = fn(float $ms): string => number_format($ms, 1, ',', ' ') . ' ms';
?>
<div class="page-fullwidth">

<div class="page-header" style="display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
    <a href="/settings?tab=prestashop" class="btn btn--ghost btn--sm">←</a>
    <div>
        <h2 class="page-header__title" style="margin:0;">🩺 Diagnostic cURL PrestaShop</h2>
        <p class="page-header__subtitle" style="margin:2px 0 0;">
            Cible : <code><?= Renderer::escape($shop_url) ?></code>
            · Clé PS : <?= $has_api_key ? '<span style="color:#166534;">✓</span>' : '<span style="color:#dc2626;">✗</span>' ?>
            · Clé aw_cpf : <?= $has_aw_key ? '<span style="color:#166534;">✓</span>' : '<span style="color:#dc2626;">✗</span>' ?>
        </p>
    </div>
    <a href="/settings/prestashop/curl-test" class="btn btn--secondary btn--sm" style="margin-left:auto;" title="Relancer les tests">🔄 Relancer</a>
</div>

<?php if ($public_ip !== null): ?>
    <div style="margin-bottom:16px; padding:12px 14px; background:#eff6ff; border:1px solid #bfdbfe; border-radius:var(--radius); font-size:13px;">
        <strong>🌐 IP publique du serveur PIM :</strong>
        <code style="font-size:14px; background:#fff; padding:2px 8px; border-radius:4px; margin-left:6px;"><?= Renderer::escape($public_ip) ?></code>
        <div style="margin-top:6px; color:var(--color-text-muted); font-size:12px;">
            C'est l'IP que ta boutique PrestaShop voit lorsqu'on l'appelle depuis ici.
            Si tu suspectes un blacklist / firewall côté musculation.com (Cloudflare, ModSecurity,
            filtre IP dans l'admin), demande à l'admin de <strong>whitelister cette IP</strong>.
        </div>
    </div>
<?php else: ?>
    <div style="margin-bottom:16px; padding:10px 12px; background:#fef3c7; border:1px solid #fcd34d; border-radius:var(--radius); font-size:12px;">
        ⚠ Impossible de récupérer l'IP publique du serveur PIM (les services ipify/ifconfig sont eux aussi injoignables).
        Le serveur a probablement une connectivité réseau très limitée / firewall sortant strict.
    </div>
<?php endif; ?>

<div class="card">
    <div class="card__body" style="padding:0;">
        <div style="overflow-x:auto;">
            <table class="diag-table">
                <thead>
                    <tr>
                        <th style="width:32%;">Endpoint</th>
                        <th class="diag-table__num">HTTP</th>
                        <th class="diag-table__num" title="Résolution DNS">DNS</th>
                        <th class="diag-table__num" title="Établissement TCP (SYN)">Connect</th>
                        <th class="diag-table__num" title="Handshake TLS/SSL">TLS</th>
                        <th class="diag-table__num" title="Temps total (DNS + connect + TLS + réponse)"><strong>Total</strong></th>
                        <th class="diag-table__num" title="Octets reçus">Body</th>
                        <th>Erreur / Extrait</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($results as $r):
                        $ok = $r['error'] === null && $r['http_code'] > 0;
                        $good = $ok && $r['http_code'] < 400;
                        $totalCol = $r['total_ms'] > 10000 ? '#dc2626' : ($r['total_ms'] > 3000 ? '#92400e' : '#166534');
                    ?>
                        <tr>
                            <td>
                                <strong><?= Renderer::escape($r['label']) ?></strong>
                                <div style="font-size:11px; color:var(--color-text-muted); word-break:break-all; margin-top:2px;">
                                    <code><?= Renderer::escape($r['url']) ?></code>
                                </div>
                            </td>
                            <td class="diag-table__num">
                                <?php if ($r['error'] !== null): ?>
                                    <span style="color:#dc2626;">—</span>
                                <?php else: ?>
                                    <span style="color:<?= $good ? '#166534' : '#dc2626' ?>; font-weight:600;"><?= $r['http_code'] ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="diag-table__num"><?= $fmtMs($r['dns_ms']) ?></td>
                            <td class="diag-table__num"><?= $fmtMs($r['connect_ms']) ?></td>
                            <td class="diag-table__num"><?= $fmtMs($r['tls_ms']) ?></td>
                            <td class="diag-table__num" style="color:<?= $totalCol ?>; font-weight:600;"><?= $fmtMs($r['total_ms']) ?></td>
                            <td class="diag-table__num"><?= number_format($r['body_size'], 0, ',', ' ') ?></td>
                            <td>
                                <?php if ($r['error'] !== null): ?>
                                    <span style="color:#dc2626; font-size:12px;"><?= Renderer::escape($r['error']) ?></span>
                                <?php elseif ($r['body_snippet'] !== ''): ?>
                                    <details style="font-size:11px;">
                                        <summary style="cursor:pointer; color:var(--color-text-muted);">Extrait réponse (<?= min(300, $r['body_size']) ?> octets)</summary>
                                        <pre style="margin:4px 0 0; padding:6px 8px; background:#f1f5f9; border-radius:4px; white-space:pre-wrap; word-break:break-all;"><?= Renderer::escape($r['body_snippet']) ?></pre>
                                    </details>
                                <?php else: ?>
                                    <span style="color:var(--color-text-muted);">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="card" style="margin-top:16px;">
    <div class="card__body" style="font-size:13px; line-height:1.6; color:var(--color-text-muted);">
        <strong style="color:var(--color-text);">Comment lire ce diagnostic :</strong>
        <ul style="margin:8px 0 0; padding-left:22px;">
            <li><strong>DNS &gt; 500ms</strong> → problème de résolution DNS côté serveur PIM (rare).</li>
            <li><strong>Connect &gt; 3000ms</strong> → latence réseau ou firewall côté musculation.com qui met du temps à accepter la connexion depuis l'IP du serveur PIM.</li>
            <li><strong>TLS &gt; 3000ms</strong> → handshake SSL lent (charge serveur PS ou négociation SNI).</li>
            <li><strong>Total = 30 000+ ms</strong> avec erreur « Connection timeout » → l'un des trois précédents dépasse le timeout ; c'est probablement Connect (TCP) qui est bloqué.</li>
            <li><strong>HTTP 401/403 sur `/api/`</strong> → clé Webservice PrestaShop invalide ou pas de permission.</li>
            <li><strong>HTTP 404 sur `aw_customproductfield`</strong> → le module n'est pas installé/activé sur cette boutique.</li>
        </ul>
    </div>
</div>

</div>

<style>
.diag-table { width:100%; border-collapse:collapse; font-size:13px; }
.diag-table th, .diag-table td { padding:8px 10px; text-align:left; border-bottom:1px solid var(--color-border); vertical-align:top; }
.diag-table thead th { background:var(--color-bg); font-weight:600; font-size:11px; text-transform:uppercase; letter-spacing:0.04em; color:var(--color-text-muted); }
.diag-table__num { text-align:right; font-variant-numeric:tabular-nums; white-space:nowrap; }
</style>
