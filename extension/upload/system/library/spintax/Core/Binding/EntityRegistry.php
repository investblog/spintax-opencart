<?php
/**
 * The entity-type registry (spec §3.1). Single source of truth mapping an
 * `entity_type` string → its {@see EntityType} descriptor. Every product-specific
 * fact now lives here; the gateway (Applier), scope (BindingRepository), admin
 * legality (BindingAdmin), enumeration (Walk) and event wiring (Installer,
 * controller) all read from it, so adding an entity is a one-entry change.
 *
 * Phase 2 registers Product + Category + Information (all `description_column`).
 * Manufacturer is Phase 3 (no status/description table → `seo_keyword` only) and
 * is intentionally absent until that target kind ships.
 *
 * @package Spintax\Core\Binding
 */

declare(strict_types=1);

namespace Spintax\Core\Binding;

final class EntityRegistry
{
    /** The four legal description_column targets shared by product/category/information. */
    private const META_COLUMNS = array('meta_title', 'meta_description', 'meta_keyword', 'description');
    private const REQUIRED = array('meta_title');
    private const HTML = array('description');

    /** @var array<string, EntityType>|null */
    private static ?array $types = null;

    public static function get(string $type): ?EntityType
    {
        self::$types ??= self::build();
        return self::$types[$type] ?? null;
    }

    /** @return array<string, EntityType> */
    public static function all(): array
    {
        self::$types ??= self::build();
        return self::$types;
    }

    /** @return array<string, EntityType> */
    private static function build(): array
    {
        return array(
            'product' => new EntityType(
                'product',
                'Product',
                'product',
                'product_id',
                'status',
                'product_description',
                'name',
                self::META_COLUMNS,
                self::REQUIRED,
                self::HTML,
                'product',
                'product_id=',
                self::events('product', 'Product')
            ),
            'category' => new EntityType(
                'category',
                'Category',
                'category',
                'category_id',
                'status',
                'category_description',
                'name',
                self::META_COLUMNS,
                self::REQUIRED,
                self::HTML,
                'category',
                'category_id=',
                self::events('category', 'Category')
            ),
            'information' => new EntityType(
                'information',
                'Information',
                'information',
                'information_id',
                'status',
                'information_description',
                // Verified live: information's display field is `title`, not `name`.
                'title',
                self::META_COLUMNS,
                self::REQUIRED,
                self::HTML,
                'information',
                'information_id=',
                self::events('information', 'Information')
            ),
            'manufacturer' => new EntityType(
                'manufacturer',
                'Manufacturer',
                'manufacturer',
                'manufacturer_id',
                // No status column and NO description/meta table (verified live): the
                // manufacturer's only SEO surface is its oc_seo_url keyword, and its
                // name lives on the base row — so it supports seo_keyword ONLY.
                null,
                null,
                'name',
                array(),
                array(),
                array(),
                'manufacturer',
                'manufacturer_id=',
                self::events('manufacturer', 'Manufacturer')
            ),
        );
    }

    /**
     * The three model save/add/delete event triggers for an entity. Verified live:
     * add returns the new id ($output); edit and delete take the id as $args[0].
     *
     * @return array<int, array{0:string,1:string,2:string}>
     */
    private static function events(string $type, string $model): array
    {
        $base = "admin/model/catalog/{$type}/";
        return array(
            array("spintax_seo_{$type}_add", "{$base}add{$model}/after", 'extension/module/spintax_seo/eventSave'),
            array("spintax_seo_{$type}_edit", "{$base}edit{$model}/after", 'extension/module/spintax_seo/eventSave'),
            array("spintax_seo_{$type}_delete", "{$base}delete{$model}/after", 'extension/module/spintax_seo/eventDelete'),
        );
    }
}
