<?php
/**
 * The runtime slice of a binding config the applier needs: the entity descriptor,
 * the target (kind + column) and the four behavior flags. The full binding row
 * (§4.4) carries more; this is the slice the save-event/bulk path exercises.
 *
 * Column legality/required/HTML classification is delegated to the entity
 * {@see EntityType} descriptor, so one code path serves product/category/
 * information (and future entities) with no per-entity branching here.
 *
 * @package Spintax\Core\Binding
 */

declare(strict_types=1);

namespace Spintax\Core\Binding;

final class EntityBinding
{
    public function __construct(
        public string $bindingId,
        public EntityType $entity,
        public string $targetKind,
        public string $targetColumn,
        public bool $autoSeedEmpty = true,
        public bool $regenerateOnSave = false,
        public bool $preserveManualEdits = true,
        public bool $clearOnEmpty = false,
        public string $sourceMode = 'template',
        public bool $seoDisambiguate = false,
        public string $storeScope = 'ALL',
        public int $attributeId = 0
    ) {
    }

    /** eav_attribute: the target is a product custom attribute (oc_product_attribute). */
    public function isEav(): bool
    {
        return 'eav_attribute' === $this->targetKind;
    }

    /** per_entity: render the entity's own stored source when present, else the template. */
    public function isPerEntity(): bool
    {
        return 'per_entity' === $this->sourceMode;
    }

    /** seo_keyword: the target is the entity's oc_seo_url keyword, not a description column. */
    public function isSeoKeyword(): bool
    {
        return 'seo_keyword' === $this->targetKind;
    }

    /**
     * Build from an oc_spintax_binding row. Resolves the entity descriptor from
     * the row's `entity_type` via the registry.
     *
     * @param array<string, mixed> $row
     * @throws \InvalidArgumentException on an unregistered entity_type.
     */
    public static function fromRow(array $row): self
    {
        $type = (string) ($row['entity_type'] ?? '');
        $entity = EntityRegistry::get($type);
        if (null === $entity) {
            throw new \InvalidArgumentException("unregistered entity_type: '{$type}'");
        }

        return new self(
            (string) $row['binding_id'],
            $entity,
            (string) ($row['target_kind'] ?? 'description_column'),
            (string) $row['target_column'],
            (bool) (int) ($row['auto_seed_empty'] ?? 1),
            (bool) (int) ($row['regenerate_on_save'] ?? 0),
            (bool) (int) ($row['preserve_manual_edits'] ?? 1),
            (bool) (int) ($row['clear_on_empty'] ?? 0),
            (string) ($row['source_mode'] ?? 'template'),
            (bool) (int) ($row['seo_disambiguate'] ?? 0),
            (string) ($row['store_scope'] ?? 'ALL'),
            (int) ($row['attribute_id'] ?? 0)
        );
    }

    public function isValidColumn(): bool
    {
        return $this->entity->isValidColumn($this->targetColumn);
    }

    public function isRequiredColumn(): bool
    {
        return $this->entity->isRequiredColumn($this->targetColumn);
    }

    public function isHtmlColumn(): bool
    {
        return $this->entity->isHtmlColumn($this->targetColumn);
    }
}
