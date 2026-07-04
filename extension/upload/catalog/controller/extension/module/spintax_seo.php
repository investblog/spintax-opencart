<?php
/**
 * Spintax SEO — storefront (catalog) controller.
 *
 * The ONLY catalog-leg code the extension needs (it is a pre-generation system,
 * §10.1). A single view event, `catalog/view/common/footer/after`, calls
 * creditFooter() which injects the opt-in storefront credit — and ONLY when the
 * merchant has enabled it in Settings (default OFF, §12.4).
 */

class ControllerExtensionModuleSpintaxSeo extends Controller
{
    /**
     * Fired on view/common/footer/after (storefront only — the event is
     * catalog/-prefixed). Returns the footer HTML with the credit appended when
     * the setting is on; returns null when off so OpenCart keeps the original
     * output byte-for-byte.
     *
     * @param string $route
     * @param array  $data
     * @param string $output the rendered footer HTML
     * @return string|null
     */
    public function creditFooter($route, $data, $output)
    {
        if (!$this->config->get('spintax_seo_storefront_credit')) {
            return null;
        }
        require_once DIR_SYSTEM . 'library/spintax/autoload.php';
        return \Spintax\Catalog\StorefrontCredit::inject(true, (string) $output);
    }

    /**
     * Self-scheduled cron endpoint (spec §6, §16 item 18) — OC3 has no cron/cron.
     * A system/web cron hits index.php?route=extension/module/spintax_seo/cron&token=<secret>
     * on a schedule; the secret token (seeded at install) gates it. Returns a JSON
     * summary. Self-schedules on last_run so frequent hits are cheap no-ops.
     */
    public function cron(): void
    {
        require_once DIR_SYSTEM . 'library/spintax/autoload.php';

        $given = (string) ($this->request->get['token'] ?? '');
        $stored = (string) $this->config->get('spintax_seo_cron_token');
        if ('' === $stored || !hash_equals($stored, $given)) {
            $this->response->addHeader('HTTP/1.1 403 Forbidden');
            $this->response->setOutput('forbidden');
            return;
        }

        $db = new \Spintax\Db\OcDb($this->db);
        $langs = new \Spintax\Catalog\LanguageResolver($db, DB_PREFIX);
        $cacheFlush = function (string $group): void {
            $this->cache->delete($group);
        };
        $applier = new \Spintax\Core\Binding\Applier($db, DB_PREFIX, new \Spintax\Engine(), $langs, null, $cacheFlush);
        $walk = new \Spintax\Core\Binding\Walk($db, DB_PREFIX, $applier, $langs);
        $runner = new \Spintax\Core\Cron\CronRunner($db, DB_PREFIX, $walk, new \Spintax\Core\Log\ActivityLog($db, DB_PREFIX));

        $interval = (int) $this->config->get('spintax_seo_cron_interval');
        $result = $runner->run(time(), $interval > 0 ? $interval : 3600);

        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($result));
    }
}
