<?php
/**
 * Immutable descriptor for one catalog entity type (spec §3). Holds every
 * product-specific fact the gateway/scope/wiring layers used to hardcode: the
 * base + description tables, id/status/name columns, the legal `description_column`
 * target set (+ required/HTML subsets), the OpenCart cache group, the seo-url query
 * prefix (Phase 3 `seo_keyword`), and the model save/add/delete event triggers.
 *
 * All entities share the SAME decision core (Planner) and gateway (Applier); only
 * these facts differ. Verified against live OC 3.0.5.0 (2026-07-03):
 * information's display field is `title` (not `name`); category has no `tag`;
 * product/category/information all have a `status` column, manufacturer does not.
 *
 * @package Spintax\Core\Binding
 */

declare(strict_types=1);

namespace Spintax\Core\Binding;

final class EntityType
{
    /**
     * @param string[]                                    $columns         legal description_column targets
     * @param string[]                                    $requiredColumns never-clear subset (§8.1 guard)
     * @param string[]                                    $htmlColumns     sanitized-as-HTML subset
     * @param array<int, array{0:string,1:string,2:string}> $events         [code, model trigger, action route]
     */
    public function __construct(
        public readonly string $type,
        public readonly string $label,
        public readonly string $baseTable,
        public readonly string $idColumn,
        public readonly ?string $statusColumn,
        public readonly ?string $descriptionTable,
        public readonly string $nameColumn,
        public readonly array $columns,
        public readonly array $requiredColumns,
        public readonly array $htmlColumns,
        public readonly string $cacheGroup,
        public readonly string $seoQueryPrefix,
        public readonly array $events
    ) {
    }

    public function isValidColumn(string $column): bool
    {
        return in_array($column, $this->columns, true);
    }

    public function isRequiredColumn(string $column): bool
    {
        return in_array($column, $this->requiredColumns, true);
    }

    /** description bodies are HTML (sanitize); meta_* are plain text. */
    public function isHtmlColumn(string $column): bool
    {
        return in_array($column, $this->htmlColumns, true);
    }

    public function hasDescriptionTable(): bool
    {
        return null !== $this->descriptionTable;
    }

    /** Entities with no status column (manufacturer) are treated as always enabled. */
    public function hasStatus(): bool
    {
        return null !== $this->statusColumn;
    }
}
