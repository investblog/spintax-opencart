<?php
/**
 * Drives all enabled bindings for a saved entity — the body of the OpenCart
 * save-event handler (spec §6.1), kept framework-agnostic so it is testable off
 * the OC runtime. The admin controller's `eventSave()` is a thin wrapper that
 * builds this with OcDb + the render Engine and calls onEntitySave().
 *
 * @package Spintax\Core\Binding
 */

declare(strict_types=1);

namespace Spintax\Core\Binding;

use Spintax\Catalog\LanguageResolver;
use Spintax\Core\Log\ActivityLog;
use Spintax\Db\DbInterface;
use Spintax\Engine;

final class SaveEventRunner
{
    private DbInterface $db;
    private string $prefix;
    private Applier $applier;
    private BindingRepository $repo;
    private ?ActivityLog $log;

    /**
     * @param callable|null $cacheFlush fn(string $group) => $cache->delete($group), fired after writes.
     */
    public function __construct(
        DbInterface $db,
        string $prefix,
        Engine $engine,
        LanguageResolver $langs,
        ?callable $cacheFlush = null,
        ?ActivityLog $log = null
    ) {
        $this->db = $db;
        $this->prefix = $prefix;
        $this->applier = new Applier($db, $prefix, $engine, $langs, new Planner(), $cacheFlush);
        $this->repo = new BindingRepository($db, $prefix);
        $this->log = $log;
    }

    /**
     * Apply every enabled, trigger-on-save binding for this entity type to the
     * saved entity.
     *
     * @return array<string, array<int, string>> binding_id => (language_id => PlanCode)
     */
    public function onEntitySave(EntityType $entity, int $entityId): array
    {
        if ($entityId <= 0) {
            return array();
        }

        $results = array();
        foreach ($this->repo->enabledBindingsFor($entity->type) as $item) {
            /** @var EntityBinding $binding */
            $binding = $item['binding'];
            $codes = $this->applier->applyTo($entityId, $binding, $item['source']);
            $results[$binding->bindingId] = $codes;
            $this->log?->recordResult($binding->bindingId, 'save', $entityId, $codes);
        }

        return $results;
    }
}
