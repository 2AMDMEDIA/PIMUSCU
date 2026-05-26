<?php
use App\Helpers\Csrf;
use App\Helpers\Renderer;

/**
 * @var array{
 *     user_name?:string,
 *     user_email?:string,
 *     is_super_admin?:bool,
 *     page_title?:string,
 * } $topbar
 */
$userName = $topbar['user_name'] ?? '';
$userEmail = $topbar['user_email'] ?? '';
$isSuperAdmin = !empty($topbar['is_super_admin']);
$pageTitle = $topbar['page_title'] ?? '';
$initial = $userName !== '' ? mb_substr($userName, 0, 1) : (mb_substr($userEmail, 0, 1) ?: '?');
?>
<header class="topbar">
    <h1 class="topbar__title"><?= Renderer::escape($pageTitle) ?></h1>

    <details class="topbar__user">
        <summary class="topbar__user-summary">
            <span class="topbar__avatar"><?= Renderer::escape(mb_strtoupper($initial)) ?></span>
            <span class="topbar__user-name"><?= Renderer::escape($userName !== '' ? $userName : $userEmail) ?></span>
            <?php if ($isSuperAdmin): ?>
                <span class="topbar__badge">Super-admin</span>
            <?php endif; ?>
        </summary>
        <div class="topbar__menu">
            <div class="topbar__menu-info">
                <strong><?= Renderer::escape($userName) ?></strong>
                <span><?= Renderer::escape($userEmail) ?></span>
            </div>
            <a href="/settings?tab=account" class="topbar__menu-link">Mon compte</a>
            <form method="POST" action="/logout" class="topbar__menu-form">
                <input type="hidden" name="_csrf" value="<?= Renderer::escape(Csrf::token()) ?>">
                <button type="submit" class="topbar__menu-link topbar__menu-link--danger">Se déconnecter</button>
            </form>
        </div>
    </details>
</header>
