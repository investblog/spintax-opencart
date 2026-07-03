<?php
/**
 * The subset of a binding config the Phase-1 MVP applier needs: Product entity,
 * description_column target, the four behavior flags. The full binding row (§4.4)
 * carries more; this is the slice exercised by save-event seeding.
 *
 * @package Spintax\Core\Binding
 */

declare(strict_types=1);

namespace Spintax\Core\Binding;

final class ProductBinding
{
    /** The legal MVP description_column targets (spec §3.1 / §15). */
    public const COLUMNS = array('meta_title', 'meta_description', 'meta_keyword', 'description');

    /** Columns the OC admin refuses to re-save empty (never cleared — §8.1 guard). */
    public const REQUIRED_COLUMNS = array('meta_title');

    public function __construct(
        public string $bindingId,
        public string $targetColumn,
        public bool $autoSeedEmpty = true,
        public bool $regenerateOnSave = false,
        public bool $preserveManualEdits = true,
        public bool $clearOnEmpty = false
    ) {
    }

    /**
     * Build from an oc_spintax_binding row.
     *
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            (string) $row['binding_id'],
            (string) $row['target_column'],
            (bool) (int) ($row['auto_seed_empty'] ?? 1),
            (bool) (int) ($row['regenerate_on_save'] ?? 0),
            (bool) (int) ($row['preserve_manual_edits'] ?? 1),
            (bool) (int) ($row['clear_on_empty'] ?? 0)
        );
    }

    public function isValidColumn(): bool
    {
        return in_array($this->targetColumn, self::COLUMNS, true);
    }

    public function isRequiredColumn(): bool
    {
        return in_array($this->targetColumn, self::REQUIRED_COLUMNS, true);
    }

    /** description is HTML (sanitize); meta_* are plain text. */
    public function isHtmlColumn(): bool
    {
        return 'description' === $this->targetColumn;
    }
}
