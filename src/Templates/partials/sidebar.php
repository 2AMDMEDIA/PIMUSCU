<?php
use App\Helpers\Renderer;

/**
 * @var array{
 *     active?:string,
 *     is_super_admin?:bool,
 *     current_client?:array{id:string,name:string,logo_url:?string}|null,
 * } $sidebar
 * @var array{name:string,version:string,url:string} $app
 */
$active = $sidebar['active'] ?? '';
$isSuperAdmin = !empty($sidebar['is_super_admin']);
$currentClient = $sidebar['current_client'] ?? null;

$items = [];

if ($currentClient !== null) {
    // Items affichés quand un client est sélectionné
    $items[] = ['key' => 'dashboard',   'href' => '/dashboard',   'icon' => 'home',     'label' => 'Tableau de bord'];
    $items[] = ['key' => 'categories',  'href' => '/categories',  'icon' => 'folder',   'label' => 'Catégories'];
    $items[] = ['key' => 'produits',    'href' => '/produits',    'icon' => 'shopping', 'label' => 'Produits'];
    $items[] = ['key' => 'avis',        'href' => '/avis',        'icon' => 'star',     'label' => 'Avis Produit'];
    $items[] = ['key' => 'catalogue',   'href' => '/catalogue',   'icon' => 'catalogue','label' => 'Catalogue Nutriweb'];
    $items[] = ['key' => 'controle',    'href' => '/controle',    'icon' => 'controle', 'label' => 'Contrôle'];
    $items[] = ['key' => 'settings',    'href' => '/settings',    'icon' => 'cog',      'label' => 'Paramètres'];
}

if ($isSuperAdmin) {
    $items[] = ['key' => '__sep_admin__', 'separator' => 'Admin'];
    $items[] = ['key' => 'admin',        'href' => '/admin',         'icon' => 'shield',  'label' => 'Clients'];
}

function icon(string $name): string {
    $icons = [
        'home'     => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12L12 4l9 8"/><path d="M5 10v10h14V10"/></svg>',
        'folder'   => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></svg>',
        'shopping' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><path d="M3 6h18"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>',
        'cog'      =>'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09a1.65 1.65 0 0 0-1-1.51 1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9c.21.4.32.86.33 1.32"/></svg>',
        'shield'   => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>',
        'star'     => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>',
        'catalogue' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/></svg>',
        'controle'  => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>',
    ];
    return $icons[$name] ?? '';
}
?>
<aside class="sidebar">
    <div class="sidebar__brand">
        <span class="sidebar__brand-name"><?= Renderer::escape($app['name']) ?></span>
        <span class="sidebar__brand-version">v<?= Renderer::escape($app['version']) ?></span>
    </div>

    <?php if ($currentClient !== null): ?>
        <div class="sidebar__client">
            <?php if (!empty($currentClient['logo_url'])): ?>
                <img src="<?= Renderer::escape($currentClient['logo_url']) ?>" alt="" class="sidebar__client-logo">
            <?php else: ?>
                <div class="sidebar__client-logo sidebar__client-logo--placeholder">
                    <?= Renderer::escape(mb_strtoupper(mb_substr($currentClient['name'], 0, 1))) ?>
                </div>
            <?php endif; ?>
            <div class="sidebar__client-info">
                <span class="sidebar__client-label">Client actif</span>
                <span class="sidebar__client-name"><?= Renderer::escape($currentClient['name']) ?></span>
            </div>
        </div>
    <?php endif; ?>

    <nav class="sidebar__nav">
        <?php foreach ($items as $item): ?>
            <?php if (!empty($item['separator'])): ?>
                <div class="sidebar__separator"><?= Renderer::escape($item['separator']) ?></div>
            <?php else: ?>
                <a href="<?= Renderer::escape($item['href']) ?>"
                   class="sidebar__item <?= $active === $item['key'] ? 'sidebar__item--active' : '' ?>">
                    <span class="sidebar__icon"><?= icon($item['icon']) ?></span>
                    <span class="sidebar__label"><?= Renderer::escape($item['label']) ?></span>
                    <?php if (!empty($item['badge'])): ?>
                        <span class="sidebar__badge"><?= Renderer::escape($item['badge']) ?></span>
                    <?php endif; ?>
                </a>
            <?php endif; ?>
        <?php endforeach; ?>
    </nav>

    <div class="sidebar__footer">
        <div class="sidebar__version" title="Version déployée">v<?= Renderer::escape($app['version']) ?></div>

        <?php if ($isSuperAdmin && $currentClient !== null): ?>
            <form method="POST" action="/admin/clear-client" class="sidebar__client-switch">
                <input type="hidden" name="_csrf" value="<?= Renderer::escape($GLOBALS['csrf_token'] ?? \App\Helpers\Csrf::token()) ?>">
                <button type="submit" class="sidebar__switch-btn">← Retour à l'admin</button>
            </form>
        <?php endif; ?>
    </div>
</aside>
<style>
.sidebar__footer { margin-top: auto; padding: 12px 16px; border-top: 1px solid var(--color-border); display: flex; flex-direction: column; gap: 8px; }
.sidebar__version { font-size: 11px; color: var(--color-text-muted); text-align: center; letter-spacing: 0.5px; font-family: ui-monospace, SFMono-Regular, monospace; }
</style>
