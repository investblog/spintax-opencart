<?php
/**
 * Drives all enabled bindings for a saved entity — the body of the OpenCart
 * save-event handler (spec §6.1), kept framework-agnostic so it is testable off
 * the OC runtime. The admin controller's `eventProduct()` is a thin wrapper that
 * builds this with OcDb + the render Engine and calls onProductSave().
 *
 * @package Spintax\Core\Binding
 */

declare(strict_types=1);

namespace Spintax\Core\Binding;

use Spintax\Catalog\LanguageResolver;
use Spintax\Db\DbInterface;
use Spintax\Engine;

final class SaveEventRunner
{
    private DbInterface $db;
    private string $prefix;
    private Applier $applier;
    private BindingRepository $repo;

    /**
     * @param callable|null $cacheFlush fn() => $cache->delete('product'), fired after writes.
     */
    public function __construct(
        DbInterface $db,
        string $prefix,
        Engine $engine,
        LanguageResolver $langs,
        ?callable $cacheFlush = null
    ) {
        $this->db = $db;
        $this->prefix = $prefix;
        $this->applier = new Applier($db, $prefix, $engine, $langs, new Planner(), $cacheFlush);
        $this->repo = new BindingRepository($db, $prefix);
    }

    /**
     * Apply every enabled Product binding to the saved product.
     *
     * @return array<string, array<int, string>> binding_id => (language_id => PlanCode)
     */
    public function onProductSave(int $productId): array
    {
        if ($productId <= 0) {
            return array();
        }

        $results = array();
        foreach ($this->repo->enabledProductBindings() as $item) {
            /** @var ProductBinding $binding */
            $binding = $item['binding'];
            $results[$binding->bindingId] = $this->applier->applyToProduct($productId, $binding, $item['source']);
        }

        return $results;
    }
}
