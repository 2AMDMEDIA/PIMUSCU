<?php
use App\Helpers\Renderer;
use App\Models\Client;

/**
 * @var Client $client
 * @var array{total_calls:int,tokens_total:int,prompt_tokens:int,completion_tokens:int,cost_eur:float} $stats
 * @var array<int,array{provider:string,tokens_total:int,calls:int,cost_eur:float}> $by_provider
 */
$limit = $client->tokenMonthlyLimit;
$percent = $limit > 0 ? min(100, round(($stats['tokens_total'] / $limit) * 100, 1)) : 0;
?>
<div class="page-header">
    <div>
        <h2 class="page-header__title">Usage IA — <?= Renderer::escape($client->name) ?></h2>
        <p class="page-header__subtitle">30 derniers jours</p>
    </div>
    <a href="/admin/clients/<?= Renderer::escape($client->id) ?>" class="btn btn--ghost">← Retour client</a>
</div>

<div class="form-grid form-grid--2col">
    <div class="card">
        <div class="card__header"><h3 class="card__title">Tokens consommés</h3></div>
        <div class="card__body">
            <div style="font-size:32px;font-weight:700;line-height:1;"><?= number_format($stats['tokens_total'], 0, ',', ' ') ?></div>
            <div style="color:var(--color-text-muted);font-size:13px;margin-top:6px;">
                <?= number_format($stats['prompt_tokens'], 0, ',', ' ') ?> prompt + <?= number_format($stats['completion_tokens'], 0, ',', ' ') ?> completion
            </div>
            <?php if ($limit > 0): ?>
                <div style="margin-top:16px;">
                    <div style="font-size:12px;color:var(--color-text-muted);margin-bottom:6px;">
                        <?= $percent ?>% de la limite mensuelle (<?= number_format($limit, 0, ',', ' ') ?>)
                    </div>
                    <div style="background:#e5e7eb;height:8px;border-radius:4px;overflow:hidden;">
                        <div style="background:<?= $percent >= 90 ? '#dc2626' : ($percent >= $client->tokenAlertThreshold ? '#f59e0b' : '#10b981') ?>;height:100%;width:<?= $percent ?>%;transition:width 200ms;"></div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
        <div class="card__header"><h3 class="card__title">Coût estimé</h3></div>
        <div class="card__body">
            <div style="font-size:32px;font-weight:700;line-height:1;"><?= number_format($stats['cost_eur'], 2, ',', ' ') ?> €</div>
            <div style="color:var(--color-text-muted);font-size:13px;margin-top:6px;">
                <?= number_format($stats['total_calls'], 0, ',', ' ') ?> appel<?= $stats['total_calls'] > 1 ? 's' : '' ?> IA
            </div>
        </div>
    </div>
</div>

<div class="card" style="margin-top: 24px;">
    <div class="card__header"><h3 class="card__title">Par provider</h3></div>
    <div class="card__body" style="padding: 0;">
        <?php if (empty($by_provider)): ?>
            <div class="empty-state">
                <div class="empty-state__hint">Aucun appel IA sur les 30 derniers jours.</div>
            </div>
        <?php else: ?>
            <table class="table">
                <thead>
                    <tr><th>Provider</th><th>Appels</th><th>Tokens</th><th>Coût</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($by_provider as $p): ?>
                        <tr>
                            <td><strong><?= Renderer::escape($p['provider']) ?></strong></td>
                            <td><?= number_format((int) $p['calls'], 0, ',', ' ') ?></td>
                            <td><?= number_format((int) $p['tokens_total'], 0, ',', ' ') ?></td>
                            <td><?= number_format((float) $p['cost_eur'], 2, ',', ' ') ?> €</td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>
