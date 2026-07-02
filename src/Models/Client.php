<?php

declare(strict_types=1);

namespace App\Models;

final class Client
{
    public function __construct(
        public string $id,
        public string $name,
        public string $prestashopUrl,
        public ?string $prestashopApiKeyEncrypted,
        public ?string $prestashopBlogApiKeyEncrypted,
        public ?string $prestashopReviewsApiKeyEncrypted,
        public ?string $awCpfApiKeyEncrypted,
        public ?int $supplierId,
        public ?string $referencePrefix,
        public ?array $enabledAttributeGroupIds,
        public ?array $ignoredCategoryIds,
        public ?array $fieldMapping,
        public ?string $logoUrl,
        public ?string $footerName,
        public int $tokenMonthlyLimit,
        public int $tokenAlertThreshold,
        public ?array $enabledModules,
        public ?string $customFieldsCategories,
        public ?string $customFieldsProducts,
        public ?array $customFieldsPrompts,
        public string $createdAt,
        public string $updatedAt,
    ) {
    }

    public static function fromRow(array $row): self
    {
        return new self(
            id: $row['id'],
            name: $row['name'],
            prestashopUrl: $row['prestashop_url'] ?? '',
            prestashopApiKeyEncrypted: $row['prestashop_api_key_encrypted'] ?? null,
            prestashopBlogApiKeyEncrypted: $row['prestashop_blog_api_key_encrypted'] ?? null,
            prestashopReviewsApiKeyEncrypted: $row['prestashop_reviews_api_key_encrypted'] ?? null,
            awCpfApiKeyEncrypted: $row['aw_cpf_api_key_encrypted'] ?? null,
            supplierId: isset($row['supplier_id']) && $row['supplier_id'] !== null ? (int) $row['supplier_id'] : null,
            referencePrefix: isset($row['reference_prefix']) && $row['reference_prefix'] !== null && $row['reference_prefix'] !== ''
                ? (string) $row['reference_prefix'] : null,
            enabledAttributeGroupIds: isset($row['enabled_attribute_group_ids']) && $row['enabled_attribute_group_ids'] !== null
                ? (is_array($d = json_decode((string) $row['enabled_attribute_group_ids'], true)) ? array_map('intval', $d) : null)
                : null,
            ignoredCategoryIds: isset($row['ignored_category_ids']) && $row['ignored_category_ids'] !== null
                ? (is_array($ic = json_decode((string) $row['ignored_category_ids'], true)) ? array_map('intval', $ic) : null)
                : null,
            fieldMapping: isset($row['field_mapping']) && $row['field_mapping'] !== null
                ? (is_array($fm = json_decode((string) $row['field_mapping'], true)) ? $fm : null)
                : null,
            logoUrl: $row['logo_url'] ?? null,
            footerName: $row['footer_name'] ?? null,
            tokenMonthlyLimit: (int) ($row['token_monthly_limit'] ?? 0),
            tokenAlertThreshold: (int) ($row['token_alert_threshold'] ?? 80),
            enabledModules: isset($row['enabled_modules']) && $row['enabled_modules'] !== null ? json_decode((string) $row['enabled_modules'], true) : null,
            customFieldsCategories: $row['custom_fields_categories'] ?? null,
            customFieldsProducts: $row['custom_fields_products'] ?? null,
            customFieldsPrompts: isset($row['custom_fields_prompts']) && $row['custom_fields_prompts'] !== null ? json_decode((string) $row['custom_fields_prompts'], true) : null,
            createdAt: $row['created_at'],
            updatedAt: $row['updated_at'],
        );
    }
}
