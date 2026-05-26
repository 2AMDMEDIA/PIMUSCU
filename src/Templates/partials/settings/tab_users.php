<?php
use App\Helpers\Renderer;

/**
 * @var array<int,array<string,mixed>> $users
 * @var string $csrf_token
 * @var string $current_user_id
 */
?>
<div class="card">
    <div class="card__header" style="display:flex;justify-content:space-between;align-items:center;">
        <h3 class="card__title">Utilisateurs du client</h3>
        <span style="font-size:13px;color:var(--color-text-muted);"><?= count($users ?? []) ?></span>
    </div>
    <div class="card__body" style="padding: 0;">
        <?php if (empty($users)): ?>
            <div class="empty-state"><div class="empty-state__hint">Aucun utilisateur pour l'instant.</div></div>
        <?php else: ?>
            <table class="table">
                <thead><tr><th>Email</th><th>Nom</th><th>Dernière connexion</th><th></th></tr></thead>
                <tbody>
                    <?php foreach ($users as $u): ?>
                        <tr>
                            <td><?= Renderer::escape($u['email']) ?></td>
                            <td><?= Renderer::escape($u['full_name']) ?></td>
                            <td style="color:var(--color-text-muted);font-size:13px;">
                                <?= $u['last_login_at'] ? Renderer::escape(date('d/m/Y H:i', strtotime($u['last_login_at']))) : 'Jamais' ?>
                            </td>
                            <td style="text-align:right;">
                                <?php if ($u['id'] !== $current_user_id): ?>
                                    <form method="POST" action="/settings/users/<?= Renderer::escape($u['id']) ?>/unlink" style="display:inline;"
                                          onsubmit="return confirm('Retirer cet utilisateur du client ?');">
                                        <input type="hidden" name="_csrf" value="<?= Renderer::escape($csrf_token) ?>">
                                        <button type="submit" class="btn btn--ghost btn--sm" style="color:#dc2626;">Retirer</button>
                                    </form>
                                <?php else: ?>
                                    <span class="badge badge--gray">vous</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<div class="card" style="margin-top: 24px;">
    <div class="card__header"><h3 class="card__title">Inviter un utilisateur</h3></div>
    <div class="card__body">
        <form method="POST" action="/settings/users/invite" class="form-grid form-grid--2col">
            <input type="hidden" name="_csrf" value="<?= Renderer::escape($csrf_token) ?>">
            <label class="field">
                <span class="field__label">Email *</span>
                <input type="email" name="email" required>
            </label>
            <label class="field">
                <span class="field__label">Nom complet</span>
                <input type="text" name="full_name">
            </label>
            <div style="grid-column: 1 / -1;">
                <button type="submit" class="btn btn--primary">Envoyer l'invitation</button>
                <span class="field__hint" style="margin-left: 12px;">L'utilisateur recevra un email avec un lien pour définir son mot de passe.</span>
            </div>
        </form>
    </div>
</div>
