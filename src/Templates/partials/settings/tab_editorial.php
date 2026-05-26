<?php
use App\Helpers\Renderer;

/**
 * @var array{
 *     media_name:string, industry_sector:string, editorial_line:string,
 *     target_audience:string, editorial_forbidden:string, image_prompt_instructions:string,
 * } $editorial
 * @var string $csrf_token
 */
?>
<div class="card">
    <div class="card__header"><h3 class="card__title">Ligne éditoriale</h3></div>
    <div class="card__body">
        <p style="font-size:13px;color:var(--color-text-muted);margin:0 0 20px;">
            Ces informations contextualisent les prompts envoyés à l'IA pour générer des descriptions cohérentes avec votre marque.
        </p>

        <form method="POST" action="/settings/editorial">
            <input type="hidden" name="_csrf" value="<?= Renderer::escape($csrf_token) ?>">

            <div class="form-grid form-grid--2col">
                <label class="field">
                    <span class="field__label">Nom du média / boutique</span>
                    <input type="text" name="media_name" value="<?= Renderer::escape($editorial['media_name']) ?>" placeholder="ex: Ma Boutique">
                </label>

                <label class="field">
                    <span class="field__label">Secteur d'activité</span>
                    <input type="text" name="industry_sector" value="<?= Renderer::escape($editorial['industry_sector']) ?>" placeholder="ex: bijouterie, mode, accessoires">
                </label>

                <label class="field" style="grid-column: 1 / -1;">
                    <span class="field__label">Positionnement éditorial</span>
                    <input type="text" name="editorial_line" value="<?= Renderer::escape($editorial['editorial_line']) ?>" placeholder="ex: bijouterie artisanale française avec rapport qualité-prix premium">
                </label>

                <label class="field" style="grid-column: 1 / -1;">
                    <span class="field__label">Lectorat cible</span>
                    <textarea name="target_audience" rows="3" placeholder="Décrivez votre audience cible — l'IA adaptera le ton et le vocabulaire"><?= Renderer::escape($editorial['target_audience']) ?></textarea>
                </label>

                <label class="field" style="grid-column: 1 / -1;">
                    <span class="field__label">Interdictions rédactionnelles</span>
                    <textarea name="editorial_forbidden" rows="4" placeholder="Une interdiction par ligne. Ex:&#10;Utiliser des superlatifs vides&#10;Commencer par 'Dans un monde où...'&#10;Mentionner les concurrents"><?= Renderer::escape($editorial['editorial_forbidden']) ?></textarea>
                    <span class="field__hint">Une règle par ligne — ce que l'IA ne doit jamais faire.</span>
                </label>

                <label class="field" style="grid-column: 1 / -1;">
                    <span class="field__label">Instructions prompt image (Kie.AI)</span>
                    <textarea name="image_prompt_instructions" rows="3" placeholder="ex: photo studio fond blanc, lumière douce, focus sur le bijou, ambiance épurée et premium, format carré 1:1"><?= Renderer::escape($editorial['image_prompt_instructions']) ?></textarea>
                    <span class="field__hint">Style à appliquer aux images générées. Si vide, un prompt par défaut est utilisé.</span>
                </label>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn--primary">Enregistrer</button>
            </div>
        </form>
    </div>
</div>
