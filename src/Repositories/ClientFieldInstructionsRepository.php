<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;
use PDO;
use Ramsey\Uuid\Uuid;

/**
 * Instructions IA par champ et par client.
 * Stockage de règles textuelles que l'utilisateur saisit dans Paramètres → Champs,
 * et qui sont injectées dans le prompt système à chaque génération IA.
 *
 * Clé unique : (client_id, entity_type, field_name)
 */
final class ClientFieldInstructionsRepository
{
    private function pdo(): PDO
    {
        return Database::pdo();
    }

    /**
     * Récupère toutes les instructions pour un client et une entité donnée.
     * @return array<string,string> Map field_name => instructions
     */
    public function getForEntity(string $clientId, string $entityType): array
    {
        $stmt = $this->pdo()->prepare(
            'SELECT field_name, instructions
               FROM client_field_instructions
              WHERE client_id = :client_id
                AND entity_type = :entity_type'
        );
        $stmt->execute([':client_id' => $clientId, ':entity_type' => $entityType]);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[(string) $row['field_name']] = (string) ($row['instructions'] ?? '');
        }
        return $out;
    }

    /**
     * Upsert d'une instruction (vide = delete, pour ne pas garder de ligne fantôme).
     */
    public function set(string $clientId, string $entityType, string $fieldName, ?string $instructions): void
    {
        $instr = $instructions !== null ? trim($instructions) : '';
        if ($instr === '') {
            $this->delete($clientId, $entityType, $fieldName);
            return;
        }
        $sql = 'INSERT INTO client_field_instructions
                    (id, client_id, entity_type, field_name, instructions)
                VALUES
                    (:id_ins, :client_id_ins, :entity_type_ins, :field_name_ins, :instructions_ins)
                ON DUPLICATE KEY UPDATE
                    instructions = :instructions_upd,
                    updated_at = NOW()';
        // PDO emulate=false : on duplique les placeholders pour le INSERT...ON DUPLICATE
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute([
            ':id_ins' => Uuid::uuid4()->toString(),
            ':client_id_ins' => $clientId,
            ':entity_type_ins' => $entityType,
            ':field_name_ins' => $fieldName,
            ':instructions_ins' => $instr,
            ':instructions_upd' => $instr,
        ]);
    }

    public function delete(string $clientId, string $entityType, string $fieldName): void
    {
        $stmt = $this->pdo()->prepare(
            'DELETE FROM client_field_instructions
              WHERE client_id = :client_id
                AND entity_type = :entity_type
                AND field_name = :field_name'
        );
        $stmt->execute([
            ':client_id' => $clientId,
            ':entity_type' => $entityType,
            ':field_name' => $fieldName,
        ]);
    }

    /**
     * Catalogue statique des entités et champs configurables.
     * Sert à afficher le formulaire et à valider les inputs.
     *
     * @return array<string, array{label:string, status:'active'|'soon', fields:array<string, array{label:string, hint?:string}>}>
     */
    public static function catalog(): array
    {
        return [
            'category' => [
                'label' => 'Catégorie',
                'status' => 'active',
                'fields' => [
                    'name' => [
                        'label' => 'Nom de la catégorie',
                        'hint' => 'Ex: "3-6 mots, sans la marque, sans ponctuation finale, accroche claire."',
                    ],
                    'meta_title' => [
                        'label' => 'Meta title',
                        'hint' => 'Ex: "50-60 caractères max. Toujours finir par | &lt;NomMarque&gt;."',
                    ],
                    'meta_description' => [
                        'label' => 'Meta description',
                        'hint' => 'Ex: "150-155 caractères. CTA léger (Découvrez, Explorez)."',
                    ],
                    'meta_keywords' => [
                        'label' => 'Meta mots-clés',
                        'hint' => 'Ex: "5 à 8 mots-clés français, pas de marque concurrente."',
                    ],
                    'description' => [
                        'label' => 'Description (haut de page)',
                        'hint' => 'Ex: "3-4 lignes max. Ton expert, pas de prix, mentionner le mot complément."',
                    ],
                    'aw_description_2' => [
                        'label' => 'Description complémentaire (bas de page)',
                        'hint' => 'Ex: "Structurer en H3 + paragraphes courts. Inclure une FAQ de 3 questions."',
                    ],
                ],
            ],
            'product' => [
                'label' => 'Produit',
                'status' => 'active',
                'fields' => [
                    'description_short' => [
                        'label' => 'Description courte (teaser)',
                        'hint' => 'Ex: "150-250 caractères, ton émotionnel, mettre en avant le bénéfice clé, pas de fiche technique."',
                    ],
                    'description' => [
                        'label' => 'Description longue',
                        'hint' => 'Ex: "Structurer en H2 + listes à puces, intégrer toujours une section Conseils d\'utilisation, pas de prix."',
                    ],
                    'meta_title' => [
                        'label' => 'Meta title',
                        'hint' => 'Ex: "50-60 caractères max. Format : Nom produit | Bénéfice | &lt;NomMarque&gt;."',
                    ],
                    'meta_description' => [
                        'label' => 'Meta description',
                        'hint' => 'Ex: "150-155 caractères, inclure le prix TTC, finir par un CTA Découvrez/Offrez-vous."',
                    ],
                    'meta_keywords' => [
                        'label' => 'Meta mots-clés',
                        'hint' => 'Ex: "5 à 8 mots-clés, inclure la référence + synonymes du nom produit + bénéfice principal."',
                    ],
                ],
            ],
            'review' => [
                'label' => 'Avis produit',
                'status' => 'soon',
                'fields' => [],
            ],
        ];
    }
}
