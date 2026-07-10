<?php
/**
 * Install / uninstall orchestration (spec §6.1, §11.3, §16 items 3–5). Pure
 * DbInterface logic so it is testable off the OC runtime; the admin controller
 * is a thin wrapper that passes OcDb + the current user-group id.
 *
 * install():   create tables, register save/add/delete events, grant permissions,
 *              seed a demo template + (disabled) binding.
 * uninstall(): deregister events + permissions ALWAYS; drop tables only when the
 *              admin opts into "delete all Spintax data" (non-destructive default).
 *
 * @package Spintax\Install
 */

declare(strict_types=1);

namespace Spintax\Install;

use Spintax\Core\Binding\EntityRegistry;
use Spintax\Db\DbInterface;

final class Installer
{
    /** Admin permission route for the extension. */
    public const ROUTE = 'extension/module/spintax_seo';

    /**
     * Module-level (non-entity) events. The storefront credit hook is the one
     * catalog-leg event — catalog/-prefixed so it loads only on the storefront
     * (§12.4). It no-ops unless the merchant enabled the credit.
     *
     * @var array<int, array{0:string,1:string,2:string}>
     */
    private const MODULE_EVENTS = array(
        array('spintax_seo_storefront_credit', 'catalog/view/common/footer/after', 'extension/module/spintax_seo/creditFooter'),
        // Preload the per-entity source tab (OCMOD-injected) on the product form.
        array('spintax_seo_product_form', 'admin/view/catalog/product_form/before', 'extension/module/spintax_seo/eventProductForm'),
    );

    private const DEMO_BINDING_ID = 'bind_demo01';
    private const DEMO_SOURCE = '{Buy|Order|Shop for} %name% at a {great|competitive|fair} price. {Fast|Quick} delivery, {easy|hassle-free} returns.';

    private DbInterface $db;
    private string $prefix;

    public function __construct(DbInterface $db, string $prefix)
    {
        $this->db = $db;
        $this->prefix = $prefix;
    }

    public function install(int $userGroupId, bool $seedDemo = true): void
    {
        foreach (Schema::createStatements($this->prefix) as $sql) {
            $this->db->query($sql);
        }

        $this->registerEvents();
        $this->grantPermissions($userGroupId);
        $this->seedCronSettings();

        if ($seedDemo) {
            $this->seedDemo();
        }
    }

    /**
     * Seed the self-scheduled cron settings (§6): a random secret token gating the
     * storefront cron route, and a default interval. Idempotent.
     */
    private function seedCronSettings(): void
    {
        $exists = $this->db->query(
            "SELECT setting_id FROM `" . $this->prefix . "setting` "
            . "WHERE `code` = 'spintax_seo' AND `key` = 'spintax_seo_cron_token' AND store_id = 0"
        );
        if ($exists->num_rows > 0) {
            return;
        }
        $token = bin2hex(random_bytes(16));
        $this->db->query(
            "INSERT INTO `" . $this->prefix . "setting` SET store_id = 0, `code` = 'spintax_seo', "
            . "`key` = 'spintax_seo_cron_token', `value` = '" . $this->db->escape($token) . "', serialized = '0'"
        );
        $this->db->query(
            "INSERT INTO `" . $this->prefix . "setting` SET store_id = 0, `code` = 'spintax_seo', "
            . "`key` = 'spintax_seo_cron_interval', `value` = '3600', serialized = '0'"
        );
        // Seed last_run so CronRunner::setLastRun can UPDATE a single row (no
        // delete-then-insert races / duplicate rows).
        $this->db->query(
            "INSERT INTO `" . $this->prefix . "setting` SET store_id = 0, `code` = 'spintax_seo', "
            . "`key` = 'spintax_seo_last_run', `value` = '0', serialized = '0'"
        );
    }

    public function uninstall(bool $deleteData = false): void
    {
        // Events + permissions come out regardless (spec §11.3).
        foreach (self::allEvents() as [$code]) {
            $this->db->query("DELETE FROM `" . $this->prefix . "event` WHERE `code` = '" . $this->db->escape($code) . "'");
        }
        $this->revokePermissions();

        if ($deleteData) {
            foreach (Schema::dropStatements($this->prefix) as $sql) {
                $this->db->query($sql);
            }
        }
    }

    // --- events --------------------------------------------------------------

    /**
     * Every event row the extension registers (§6.1): each entity's save/add/
     * delete triggers plus the module-level storefront-credit hook, flattened.
     * Public + static so tests can assert the exact set without duplicating it.
     *
     * @return array<int, array{0:string,1:string,2:string}>
     */
    public static function allEvents(): array
    {
        $events = array();
        foreach (EntityRegistry::all() as $entity) {
            foreach ($entity->events as $event) {
                $events[] = $event;
            }
        }
        foreach (self::MODULE_EVENTS as $event) {
            $events[] = $event;
        }
        return $events;
    }

    private function registerEvents(): void
    {
        foreach (self::allEvents() as [$code, $trigger, $action]) {
            // Idempotent: clear any prior registration first.
            $this->db->query("DELETE FROM `" . $this->prefix . "event` WHERE `code` = '" . $this->db->escape($code) . "'");
            $this->db->query(
                "INSERT INTO `" . $this->prefix . "event` SET "
                . "`code` = '" . $this->db->escape($code) . "', "
                . "`trigger` = '" . $this->db->escape($trigger) . "', "
                . "`action` = '" . $this->db->escape($action) . "', "
                . "`status` = '1', `sort_order` = '0'"
            );
        }
    }

    // --- permissions ---------------------------------------------------------

    private function grantPermissions(int $userGroupId): void
    {
        $perm = $this->readPermission($userGroupId);
        foreach (array('access', 'modify') as $type) {
            if (!isset($perm[$type]) || !is_array($perm[$type])) {
                $perm[$type] = array();
            }
            if (!in_array(self::ROUTE, $perm[$type], true)) {
                $perm[$type][] = self::ROUTE;
            }
        }
        $this->writePermission($userGroupId, $perm);
    }

    private function revokePermissions(): void
    {
        $q = $this->db->query("SELECT user_group_id, permission FROM `" . $this->prefix . "user_group`");
        foreach ($q->rows as $row) {
            $perm = json_decode((string) $row['permission'], true);
            if (!is_array($perm)) {
                continue;
            }
            foreach (array('access', 'modify') as $type) {
                if (isset($perm[$type]) && is_array($perm[$type])) {
                    $perm[$type] = array_values(array_filter($perm[$type], static fn($r): bool => $r !== self::ROUTE));
                }
            }
            $this->writePermission((int) $row['user_group_id'], $perm);
        }
    }

    /** @return array<string, mixed> */
    private function readPermission(int $userGroupId): array
    {
        $q = $this->db->query("SELECT permission FROM `" . $this->prefix . "user_group` WHERE user_group_id = " . (int) $userGroupId);
        $perm = json_decode((string) ($q->row['permission'] ?? ''), true);
        return is_array($perm) ? $perm : array();
    }

    /** @param array<string, mixed> $perm */
    private function writePermission(int $userGroupId, array $perm): void
    {
        $this->db->query(
            "UPDATE `" . $this->prefix . "user_group` SET `permission` = '" . $this->db->escape(json_encode($perm)) . "' "
            . "WHERE user_group_id = " . (int) $userGroupId
        );
    }

    // --- demo seed -----------------------------------------------------------

    private function seedDemo(): void
    {
        // Idempotent: skip if the demo binding already exists.
        $exists = $this->db->query(
            "SELECT binding_id FROM `" . $this->prefix . "spintax_binding` WHERE binding_id = '" . self::DEMO_BINDING_ID . "'"
        );
        if ($exists->num_rows > 0) {
            return;
        }

        $this->db->query(
            "INSERT INTO `" . $this->prefix . "spintax_template` SET "
            . "`name` = 'Demo — product meta description', "
            . "`source` = '" . $this->db->escape(self::DEMO_SOURCE) . "', "
            . "`locale` = '', `date_added` = NOW(), `date_modified` = NOW()"
        );
        $templateId = (int) $this->db->query("SELECT LAST_INSERT_ID() AS id")->row['id'];

        // Zero-config first-run (§15): seeded ENABLED so the merchant gets value
        // in one pass (open → Bulk Apply → Dry run → Apply), but with
        // `trigger_on_save = 0` so NOTHING is written on an ordinary product save
        // — writes happen only through the explicit Dry-run→Apply the operator
        // drives. seed-once + preserve keep it safe (fills only empty meta).
        $this->db->query(
            "INSERT INTO `" . $this->prefix . "spintax_binding` SET "
            . "`binding_id` = '" . self::DEMO_BINDING_ID . "', "
            . "`entity_type` = 'product', `target_kind` = 'description_column', "
            . "`target_column` = 'meta_description', `source_mode` = 'template', "
            . "`template_id` = " . (int) $templateId . ", "
            . "`status` = '1', `trigger_on_save` = '0', `cadence` = 'off', "
            . "`auto_seed_empty` = '1', `preserve_manual_edits` = '1', "
            . "`date_added` = NOW(), `date_modified` = NOW()"
        );
    }
}
