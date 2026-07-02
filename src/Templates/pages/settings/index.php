<?php
use App\Helpers\Renderer;

/**
 * @var string $active_tab
 * @var \App\Models\Client $client
 */
$tabs = [
    ['key' => 'prestashop', 'label' => 'PrestaShop'],
    ['key' => 'account',    'label' => 'Compte'],
    ['key' => 'users',      'label' => 'Utilisateurs'],
    ['key' => 'ai-tools',   'label' => 'Outils IA'],
    ['key' => 'editorial',  'label' => 'Ligne éditoriale'],
    ['key' => 'nutriweb',   'label' => 'Nutriweb'],
    ['key' => 'attributes', 'label' => 'Attributs'],
    ['key' => 'fields',     'label' => 'Champs'],
    ['key' => 'mapping',    'label' => 'Mapping'],
];
?>
<div class="page-header">
    <div>
        <h2 class="page-header__title">Paramètres</h2>
        <p class="page-header__subtitle">Configuration de la boutique <?= Renderer::escape($client->name) ?></p>
    </div>
</div>

<div class="tabs">
    <?php foreach ($tabs as $tab): ?>
        <a href="/settings?tab=<?= Renderer::escape($tab['key']) ?>"
           class="tabs__item <?= $active_tab === $tab['key'] ? 'tabs__item--active' : '' ?>">
            <?= Renderer::escape($tab['label']) ?>
        </a>
    <?php endforeach; ?>
</div>

<div class="tabs__panel">
    <?php
    $partials = [
        'prestashop' => 'partials.settings.tab_prestashop',
        'account'    => 'partials.settings.tab_account',
        'users'      => 'partials.settings.tab_users',
        'ai-tools'   => 'partials.settings.tab_ai_tools',
        'editorial'  => 'partials.settings.tab_editorial',
        'nutriweb'   => 'partials.settings.tab_nutriweb',
        'attributes' => 'partials.settings.tab_attributes',
        'fields'     => 'partials.settings.tab_fields',
        'mapping'    => 'partials.settings.tab_mapping',
    ];
    echo Renderer::render($partials[$active_tab], get_defined_vars());
    ?>
</div>
