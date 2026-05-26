<?php
use App\Helpers\Renderer;

/**
 * @var array<string, array{label:string, status:string, fields:array<string, array{label:string, hint?:string}>}> $fields_catalog
 * @var array<string, array<string,string>> $fields_instructions
 * @var string $csrf_token
 */
?>
<div class="card" style="margin-bottom: 16px;">
    <div class="card__body">
        <p style="margin:0; color: var(--color-text-muted); font-size: 13px;">
            Définissez ici des <strong>instructions par champ</strong> que l'IA appliquera à chaque génération.
            Ces règles s'ajoutent au prompt système et permettent d'imposer un format, une longueur, un ton ou
            des contraintes spécifiques à ce client (ex : <em>"3-4 lignes max, pas de prix, finir par | &lt;NomMarque&gt;"</em>).
        </p>
    </div>
</div>

<form method="POST" action="/settings/field-instructions">
    <input type="hidden" name="_csrf" value="<?= Renderer::escape($csrf_token) ?>">

    <?php foreach ($fields_catalog as $entityType => $meta): ?>
        <?php
            $isActive = ($meta['status'] ?? '') === 'active';
            $values = $fields_instructions[$entityType] ?? [];
        ?>
        <div class="card field-instr-block" style="margin-bottom: 16px; <?= !$isActive ? 'opacity:0.55;' : '' ?>">
            <div class="card__header" style="display:flex; justify-content:space-between; align-items:center;">
                <h3 class="card__title">
                    <?= Renderer::escape(mb_strtoupper($meta['label'])) ?>
                    <?php if (!$isActive): ?>
                        <span class="badge badge--gray" style="margin-left: 8px; font-size: 10px;">À venir</span>
                    <?php endif; ?>
                </h3>
                <span style="color: var(--color-text-muted); font-size: 11px;">
                    <?= count($meta['fields']) ?> champ<?= count($meta['fields']) > 1 ? 's' : '' ?>
                </span>
            </div>
            <div class="card__body">
                <?php if (!$isActive): ?>
                    <p style="margin:0; font-size: 13px; color: var(--color-text-muted);">
                        Les instructions par champ pour cette entité seront ajoutées dans une future livraison.
                    </p>
                <?php elseif (empty($meta['fields'])): ?>
                    <p style="margin:0; font-size: 13px; color: var(--color-text-muted);">Aucun champ disponible.</p>
                <?php else: ?>
                    <div class="field-instr-rows">
                        <?php foreach ($meta['fields'] as $fieldName => $fieldMeta): ?>
                            <div class="field-instr-row">
                                <div class="field-instr-row__label">
                                    <strong><?= Renderer::escape($fieldMeta['label']) ?></strong>
                                    <code style="display:block; font-size: 10px; color: var(--color-text-muted); margin-top: 2px;"><?= Renderer::escape($fieldName) ?></code>
                                </div>
                                <div class="field-instr-row__input">
                                    <textarea
                                        name="instructions[<?= Renderer::escape($entityType) ?>][<?= Renderer::escape($fieldName) ?>]"
                                        rows="3"
                                        placeholder="<?= Renderer::escape($fieldMeta['hint'] ?? 'Instructions spécifiques (longueur, ton, contraintes)…') ?>"
                                    ><?= Renderer::escape($values[$fieldName] ?? '') ?></textarea>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>

    <div class="form-actions" style="justify-content: flex-end; padding: 14px; background: var(--color-bg); border: 1px solid var(--color-border); border-radius: var(--radius);">
        <button type="submit" class="btn btn--primary">💾 Enregistrer toutes les instructions</button>
    </div>
</form>

<style>
.field-instr-rows { display: flex; flex-direction: column; gap: 14px; }
.field-instr-row { display: grid; grid-template-columns: 220px 1fr; gap: 16px; align-items: start; }
.field-instr-row__label { padding-top: 6px; }
.field-instr-row__input textarea { width: 100%; box-sizing: border-box; font-family: inherit; font-size: 13px; }
@media (max-width: 800px) {
    .field-instr-row { grid-template-columns: 1fr; }
}
</style>
