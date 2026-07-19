<?php
/**
 * Spintax SEO — admin module controller (OpenCart 3.0.x).
 *
 * Thin OpenCart wrapper over the framework-agnostic engine in
 * system/library/spintax/. install()/uninstall() delegate to Spintax\Install\Installer;
 * the save events delegate to Spintax\Core\Binding\SaveEventRunner. All heavy logic
 * (schema, plan/apply, rendering) lives in the library and is unit/DB-tested.
 */

class ControllerExtensionModuleSpintaxSeo extends Controller
{
    /**
     * The table prefix, mirroring the library classes.
     *
     * The controller issues a handful of queries of its own, and they compose exactly like the
     * library's: through `SqlIdentifiers`, into a `$sql` variable, never concatenated inside the
     * `query()` call. `DB_PREFIX` is captured here rather than referenced inside each statement so
     * there is one place it enters SQL, the same as everywhere else.
     */
    private string $prefix = '';

    public function __construct($registry)
    {
        parent::__construct($registry);
        $this->bootstrap();
        $this->prefix = DB_PREFIX;
    }

    /** Load the engine library's SPL autoloader (not PSR-4 under OpenCart). */
    private function bootstrap(): void
    {
        require_once DIR_SYSTEM . 'library/spintax/autoload.php';
    }

    /**
     * Backtick-quote a prefixed table name. Deliberately a copy, not the `SqlIdentifiers` trait.
     *
     * A `use SomeTrait;` is resolved when PHP **links the class**, which happens the moment
     * OpenCart loads this file — before any method of it runs, and therefore before `bootstrap()`
     * has registered the engine's SPL autoloader. Pulling the trait in here would fatal the whole
     * admin module on load. Fifteen duplicated lines are the cheaper mistake.
     *
     * The guard is identical to the library's on purpose: a table name is an identifier, so
     * `escape()` cannot protect it, and only `^[a-z_]+$` gets through.
     */
    private function table(string $name): string
    {
        if (1 !== preg_match('/^[a-z_]+$/', $name)) {
            throw new \InvalidArgumentException('Table name must be a bare literal, got: ' . $name);
        }

        return '`' . $this->prefix . $name . '`';
    }

    /** Validate a column name and return it unchanged — it already sits in a backticked slot. */
    private function column(string $name): string
    {
        if (1 !== preg_match('/^[a-z_]+$/', $name)) {
            throw new \InvalidArgumentException('Column name must be a bare identifier, got: ' . $name);
        }

        return $name;
    }

    private function db(): \Spintax\Db\OcDb
    {
        return new \Spintax\Db\OcDb($this->db);
    }

    // --- extension lifecycle (called by extension/extension/module) ----------

    public function install(): void
    {
        $this->bootstrap();
        (new \Spintax\Install\Installer($this->db(), DB_PREFIX))->install((int) $this->user->getGroupId());
    }

    public function uninstall(): void
    {
        $this->bootstrap();
        // Non-destructive default (§11.3): keep tables + templates; the opt-in
        // "delete all data" toggle is surfaced in the settings UI (later phase).
        $deleteData = (bool) $this->config->get('spintax_seo_delete_data_on_uninstall');
        (new \Spintax\Install\Installer($this->db(), DB_PREFIX))->uninstall($deleteData);
    }

    // --- settings page -------------------------------------------------------

    public function index(): void
    {
        $this->bootstrap();
        $this->load->language('extension/module/spintax_seo');
        $this->document->setTitle($this->language->get('heading_title'));

        $data = $this->chrome();
        $data['add_binding'] = $this->link('form');
        $data['templates_url'] = $this->link('templates');
        $data['bindings'] = $this->bindingAdmin()->all();
        $data['templates'] = $this->templateRepo()->list();
        $data['languages'] = $this->languageList();
        $data['storefront_credit'] = (bool) $this->config->get('spintax_seo_storefront_credit');
        $data['cron_token'] = (string) $this->config->get('spintax_seo_cron_token');
        $data['cron_path'] = 'index.php?route=extension/module/spintax_seo/cron&amp;token=' . $data['cron_token'];
        $data['endpoints'] = $this->endpoints();

        $this->response->setOutput($this->load->view('extension/module/spintax_seo', $data));
    }

    public function form(): void
    {
        $this->bootstrap();
        $this->load->language('extension/module/spintax_seo');
        $this->document->setTitle($this->language->get('heading_title'));

        $data = $this->chrome();
        $bindingId = (string) ($this->request->get['binding_id'] ?? '');
        $data['binding'] = ('' !== $bindingId) ? $this->bindingAdmin()->find($bindingId) : null;
        $data['binding_json'] = json_encode($data['binding']); // null when adding
        $data['templates'] = $this->templateRepo()->list();
        $data['attributes'] = $this->bindingAdmin()->attributes();
        $data['cancel'] = $this->link('');
        $data['endpoints'] = $this->endpoints();

        $this->response->setOutput($this->load->view('extension/module/spintax_seo_form', $data));
    }

    public function templates(): void
    {
        $this->bootstrap();
        $this->load->language('extension/module/spintax_seo');
        $this->document->setTitle($this->language->get('heading_title'));

        $data = $this->chrome();
        $data['templates'] = $this->templateRepo()->list();
        $data['add_template'] = $this->link('templateForm');
        $data['back'] = $this->link('');
        $data['edit_base'] = $this->link('templateForm');
        $data['endpoints'] = $this->endpoints();

        $this->response->setOutput($this->load->view('extension/module/spintax_seo_templates', $data));
    }

    public function templateForm(): void
    {
        $this->bootstrap();
        $this->load->language('extension/module/spintax_seo');
        $this->document->setTitle($this->language->get('heading_title'));

        $data = $this->chrome();
        $id = (int) ($this->request->get['template_id'] ?? 0);
        $data['template'] = $id > 0 ? $this->templateRepo()->get($id) : null;
        $data['template_json'] = json_encode($data['template']); // null when adding
        $data['languages'] = $this->languageList();
        $data['cancel'] = $this->link('templates');
        $data['endpoints'] = $this->endpoints();

        $this->response->setOutput($this->load->view('extension/module/spintax_seo_template_form', $data));
    }

    // --- view helpers --------------------------------------------------------

    private function link(string $method): string
    {
        $route = 'extension/module/spintax_seo' . ('' !== $method ? '/' . $method : '');
        return $this->url->link($route, 'user_token=' . $this->session->data['user_token'], true);
    }

    /** @return array<string, string> endpoint route → url (JS reads these) */
    private function endpoints(): array
    {
        $out = array();
        foreach (array('save', 'delete', 'targets', 'test', 'initBaseline', 'dryRun', 'apply', 'walk', 'logs', 'releaseLock', 'saveSettings', 'templateSave', 'templateDelete', 'templateValidate', 'templatePreview') as $m) {
            $out[$m] = $this->link($m);
        }
        return $out;
    }

    /** @return array<int, array{language_id:int, code:string}> */
    private function languageList(): array
    {
        $out = array();
        foreach ($this->langs()->activeLanguages() as $id => $code) {
            $out[] = array('language_id' => (int) $id, 'code' => $code);
        }
        return $out;
    }

    /** @return array<string, mixed> common header/footer/breadcrumb chrome */
    private function chrome(): array
    {
        $token = $this->session->data['user_token'];
        return array(
            'heading_title' => $this->language->get('heading_title'),
            'user_token' => $token,
            'config_json' => json_encode(array(
                'endpoints' => $this->endpoints(),
                'token' => $token,
                'languages' => $this->languageList(),
            )),
            'breadcrumbs' => array(
                array('text' => $this->language->get('text_home'), 'href' => $this->url->link('common/dashboard', 'user_token=' . $token, true)),
                array('text' => $this->language->get('text_extension'), 'href' => $this->url->link('marketplace/extension', 'user_token=' . $token . '&type=module', true)),
                array('text' => $this->language->get('heading_title'), 'href' => $this->link('')),
            ),
            'header' => $this->load->controller('common/header'),
            'column_left' => $this->load->controller('common/column_left'),
            'footer' => $this->load->controller('common/footer'),
        );
    }

    private function validate(): bool
    {
        return $this->user->hasPermission('modify', 'extension/module/spintax_seo');
    }

    // --- save-event handlers (registered by the Installer) -------------------

    /**
     * Resolve the entity descriptor from a model event route, e.g.
     * `admin/model/catalog/category/editCategory/after` → the 'category' descriptor.
     */
    private function entityFromRoute(string $route): ?\Spintax\Core\Binding\EntityType
    {
        $parts = explode('/', $route);
        // admin(0)/model(1)/catalog(2)/<entity>(3)/<method>(4)/after(5)
        return \Spintax\Core\Binding\EntityRegistry::get($parts[3] ?? '');
    }

    /**
     * Fired on admin/model/catalog/<entity>/{addX,editX}/after for every registered
     * entity. edit → id is $args[0]; add → the new id is $output (the add* return).
     *
     * @param string     $route
     * @param array      $args
     * @param mixed|null $output
     */
    public function eventSave($route, $args, $output = null): void
    {
        $this->bootstrap();

        $entity = $this->entityFromRoute((string) $route);
        if (null === $entity) {
            return;
        }

        $isEdit = (false !== strpos((string) $route, 'edit'));
        $entityId = $isEdit ? (int) ($args[0] ?? 0) : (int) $output;

        if ($entityId <= 0) {
            return;
        }

        // Capture the per-entity source posted from the form tab (edit → $args[1],
        // add → $args[0] carry the model's $data). Done BEFORE seeding so a
        // trigger-on-save per_entity binding renders the just-saved source. Empty
        // values are dropped (blank = template fallback — PerEntitySource::save).
        $data = $isEdit ? ($args[1] ?? array()) : ($args[0] ?? array());
        if (isset($data['spintax_seo_source']) && is_array($data['spintax_seo_source'])) {
            // Sanitize through the SAME spintax input cleaner as template save
            // (null/control-char strip, UTF-8 normalize) so both source paths match.
            $sources = array();
            foreach ($data['spintax_seo_source'] as $langId => $raw) {
                $sources[(int) $langId] = \Spintax\Support\InputSanitizer::sanitize_spintax((string) $raw);
            }
            (new \Spintax\Core\Binding\PerEntitySource($this->db(), DB_PREFIX))
                ->save($entity->type, $entityId, $sources);
        }

        $engine = new \Spintax\Engine();
        $langs = new \Spintax\Catalog\LanguageResolver($this->db(), DB_PREFIX);
        $cacheFlush = function (string $group): void {
            $this->cache->delete($group);
        };

        (new \Spintax\Core\Binding\SaveEventRunner($this->db(), DB_PREFIX, $engine, $langs, $cacheFlush, $this->activityLog()))
            ->onEntitySave($entity, $entityId);
    }

    /**
     * Fired on admin/model/catalog/<entity>/deleteX/after — orphan purge (§6.2),
     * scoped by entity_type so shared numeric ids across entities never collide.
     *
     * @param string $route
     * @param array  $args
     */
    public function eventDelete($route, $args): void
    {
        $this->bootstrap();

        $entity = $this->entityFromRoute((string) $route);
        if (null === $entity) {
            return;
        }
        $entityId = (int) ($args[0] ?? 0);
        if ($entityId <= 0) {
            return;
        }

        // Signature has no entity_type column; scope the purge by joining the
        // binding (binding_id encodes the entity) so a category id can't purge a
        // product's signatures, and vice-versa.
        $sql = sprintf(
            "DELETE sig FROM %s sig "
            . "JOIN %s b ON sig.binding_id = b.binding_id "
            . "WHERE b.entity_type = '%s' AND sig.entity_id = %d",
            $this->table('spintax_signature'),
            $this->table('spintax_binding'),
            $this->db->escape($entity->type),
            $entityId
        );

        $this->db->query($sql);
        // Purge the entity's per-entity sources + bump per_entity bindings so any
        // pending dry-run snapshot is invalidated (§7.1).
        (new \Spintax\Core\Binding\PerEntitySource($this->db(), DB_PREFIX))->purge($entity->type, $entityId);
    }

    /**
     * Fired on admin/view/catalog/product_form/before — preload the OCMOD tab's
     * per-language source textareas from oc_spintax_source. Modifies $data by
     * reference so the injected Twig `{{ spintax_seo_source[language_id] }}` shows
     * the saved values.
     *
     * @param string $route
     * @param array  $data  template data (by reference)
     */
    public function eventProductForm(&$route, &$data): void
    {
        $this->bootstrap();
        // The product being edited comes from the request (the form controller
        // does not expose product_id in the view $data). Absent on add → no sources.
        $productId = (int) ($this->request->get['product_id'] ?? 0);
        $data['spintax_seo_source'] = ($productId > 0)
            ? (new \Spintax\Core\Binding\PerEntitySource($this->db(), DB_PREFIX))->loadAll('product', $productId)
            : array();
    }

    // --- library factories ---------------------------------------------------

    private function langs(): \Spintax\Catalog\LanguageResolver
    {
        return new \Spintax\Catalog\LanguageResolver($this->db(), DB_PREFIX);
    }

    private function applier(): \Spintax\Core\Binding\Applier
    {
        // Group-aware: the Applier flushes the bound entity's cache group after writes.
        $cacheFlush = function (string $group): void {
            $this->cache->delete($group);
        };
        return new \Spintax\Core\Binding\Applier($this->db(), DB_PREFIX, new \Spintax\Engine(), $this->langs(), null, $cacheFlush);
    }

    private function walkEngine(): \Spintax\Core\Binding\Walk
    {
        return new \Spintax\Core\Binding\Walk($this->db(), DB_PREFIX, $this->applier(), $this->langs());
    }

    private function bindingAdmin(): \Spintax\Core\Binding\BindingAdmin
    {
        return new \Spintax\Core\Binding\BindingAdmin($this->db(), DB_PREFIX);
    }

    private function templateRepo(): \Spintax\Core\Template\TemplateRepository
    {
        return new \Spintax\Core\Template\TemplateRepository($this->db(), DB_PREFIX);
    }

    private function activityLog(): \Spintax\Core\Log\ActivityLog
    {
        return new \Spintax\Core\Log\ActivityLog($this->db(), DB_PREFIX);
    }

    /**
     * Resolve a binding's template source. Used directly in template mode, and as
     * the per-cell FALLBACK in per_entity mode (the Applier overrides it with the
     * entity's own stored source when present). null = no template → unresolved.
     */
    private function sourceFor(array $bindingRow): ?string
    {
        if ((int) ($bindingRow['template_id'] ?? 0) > 0) {
            $sql = sprintf(
                'SELECT source FROM %s WHERE template_id = %d',
                $this->table('spintax_template'),
                $bindingRow['template_id']
            );

            $q = $this->db->query($sql);
            return $q->num_rows > 0 ? (string) $q->row['source'] : null;
        }
        return null;
    }

    private function json($data): void
    {
        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($data));
    }

    private function denied(): bool
    {
        return !$this->user->hasPermission('modify', 'extension/module/spintax_seo');
    }

    // --- binding endpoints (AJAX JSON) --------------------------------------

    public function targets(): void
    {
        $this->bootstrap();
        $this->json($this->bindingAdmin()->legalTargets((string) ($this->request->post['entity_type'] ?? $this->request->get['entity_type'] ?? '')));
    }

    public function save(): void
    {
        $this->bootstrap();
        if ($this->denied()) {
            $this->json(array('error' => $this->permissionError()));
            return;
        }
        $this->json($this->bindingAdmin()->save($this->request->post));
    }

    public function delete(): void
    {
        $this->bootstrap();
        if ($this->denied()) {
            $this->json(array('error' => $this->permissionError()));
            return;
        }
        $this->bindingAdmin()->delete((string) ($this->request->post['binding_id'] ?? ''));
        $this->json(array('success' => true));
    }

    // --- Test panel ----------------------------------------------------------

    public function test(): void
    {
        $this->bootstrap();
        $binding = $this->bindingAdmin()->find((string) ($this->request->post['binding_id'] ?? ''));
        if (null === $binding) {
            $this->json(array('error' => 'BINDING_NOT_FOUND'));
            return;
        }
        $entityBinding = \Spintax\Core\Binding\EntityBinding::fromRow($binding);
        $cell = $this->applier()->explainCell(
            (int) ($this->request->post['entity_id'] ?? 0),
            $entityBinding,
            $this->sourceFor($binding),
            (int) ($this->request->post['language_id'] ?? 0)
        );
        $this->json($cell);
    }

    public function initBaseline(): void
    {
        $this->bootstrap();
        if ($this->denied()) {
            $this->json(array('error' => $this->permissionError()));
            return;
        }
        $binding = $this->bindingAdmin()->find((string) ($this->request->post['binding_id'] ?? ''));
        if (null === $binding) {
            $this->json(array('error' => 'BINDING_NOT_FOUND'));
            return;
        }
        $this->json($this->applier()->initBaseline(
            \Spintax\Core\Binding\EntityBinding::fromRow($binding),
            $this->sourceFor($binding),
            (int) ($this->request->post['entity_id'] ?? 0),
            (int) ($this->request->post['language_id'] ?? 0)
        ));
    }

    // --- Bulk Apply ----------------------------------------------------------

    public function dryRun(): void
    {
        $this->bootstrap();
        if ($this->denied()) {
            $this->json(array('error' => $this->permissionError()));
            return;
        }
        $binding = $this->bindingAdmin()->find((string) ($this->request->post['binding_id'] ?? ''));
        if (null === $binding) {
            $this->json(array('error' => 'BINDING_NOT_FOUND'));
            return;
        }
        $this->json($this->walkEngine()->dryRun($binding, $this->sourceFor($binding)));
    }

    public function apply(): void
    {
        $this->bootstrap();
        if ($this->denied()) {
            $this->json(array('error' => $this->permissionError()));
            return;
        }
        $binding = $this->bindingAdmin()->find((string) ($this->request->post['binding_id'] ?? ''));
        if (null === $binding) {
            $this->json(array('error' => 'BINDING_NOT_FOUND'));
            return;
        }
        $chunk = isset($this->request->post['chunk']) ? (int) $this->request->post['chunk'] : null;
        $lockTs = isset($this->request->post['lock_ts']) ? (int) $this->request->post['lock_ts'] : null;
        $result = $this->walkEngine()->applyChunk(
            $binding,
            $this->sourceFor($binding),
            (string) ($this->request->post['dry_run_token'] ?? ''),
            $chunk,
            $lockTs
        );
        if (!isset($result['error'])) {
            $this->activityLog()->record(
                (string) $binding['binding_id'],
                'bulk',
                null,
                (int) ($result['written'] ?? 0),
                (int) ($result['skipped'] ?? 0),
                (int) ($result['blocked'] ?? 0)
            );
        }
        $this->json($result);
    }

    public function walk(): void
    {
        $this->bootstrap();
        $state = $this->walkEngine()->loadWalk((string) ($this->request->get['binding_id'] ?? ''));
        $this->json($state ?? array());
    }

    /** Recent activity log (§15) — JSON for the Logs panel. */
    public function logs(): void
    {
        $this->bootstrap();
        if ($this->denied()) {
            $this->json(array('error' => $this->permissionError()));
            return;
        }
        $this->json(array('rows' => $this->activityLog()->recent(100)));
    }

    public function releaseLock(): void
    {
        $this->bootstrap();
        if ($this->denied()) {
            $this->json(array('error' => $this->permissionError()));
            return;
        }
        $this->json($this->walkEngine()->releaseLock((string) ($this->request->post['binding_id'] ?? '')));
    }

    // --- Settings ------------------------------------------------------------

    /**
     * Persist module settings. Currently the single opt-in storefront-credit
     * toggle (§12.4, default OFF). Merges into the existing `spintax_seo` group so
     * other keys are preserved (editSetting replaces the whole group).
     */
    public function saveSettings(): void
    {
        $this->bootstrap();
        if ($this->denied()) {
            $this->json(array('error' => $this->permissionError()));
            return;
        }
        $this->load->model('setting/setting');
        $settings = $this->model_setting_setting->getSetting('spintax_seo');
        $settings['spintax_seo_storefront_credit'] = (int) !empty($this->request->post['storefront_credit']);
        $this->model_setting_setting->editSetting('spintax_seo', $settings);
        $this->json(array('success' => true, 'storefront_credit' => (bool) $settings['spintax_seo_storefront_credit']));
    }

    // --- Templates -----------------------------------------------------------

    public function templateSave(): void
    {
        $this->bootstrap();
        if ($this->denied()) {
            $this->json(array('error' => $this->permissionError()));
            return;
        }
        $this->json($this->templateRepo()->save(
            (int) ($this->request->post['template_id'] ?? 0),
            (string) ($this->request->post['name'] ?? ''),
            \Spintax\Support\InputSanitizer::sanitize_spintax((string) ($this->request->post['source'] ?? '')),
            (string) ($this->request->post['locale'] ?? '')
        ));
    }

    public function templateDelete(): void
    {
        $this->bootstrap();
        if ($this->denied()) {
            $this->json(array('error' => $this->permissionError()));
            return;
        }
        $this->json($this->templateRepo()->delete((int) ($this->request->post['template_id'] ?? 0)));
    }

    public function templateValidate(): void
    {
        $this->bootstrap();
        $source = \Spintax\Support\InputSanitizer::sanitize_spintax((string) ($this->request->post['source'] ?? ''));
        $validator = new \Spintax\Core\Engine\Validator();
        $this->json($validator->validate($source, array(), array(), (string) ($this->request->post['locale'] ?? '')));
    }

    public function templatePreview(): void
    {
        $this->bootstrap();
        $source = \Spintax\Support\InputSanitizer::sanitize_spintax((string) ($this->request->post['source'] ?? ''));
        $vars = array();
        // Optional sample entity: pull its name/title so %name% resolves in the
        // preview, from the previewed entity's description table (default product).
        $entityId = (int) ($this->request->post['entity_id'] ?? 0);
        $languageId = (int) ($this->request->post['language_id'] ?? 0);
        $entity = \Spintax\Core\Binding\EntityRegistry::get((string) ($this->request->post['entity_type'] ?? 'product'));
        if (null !== $entity && $entity->hasDescriptionTable() && $entityId > 0 && $languageId > 0) {
            $sql = sprintf(
                "SELECT `%s` AS n FROM %s "
                . "WHERE `%s` = %d AND language_id = %d",
                $this->column($entity->nameColumn),
                $this->table((string) $entity->descriptionTable),
                $this->column($entity->idColumn),
                $entityId,
                $languageId
            );

            $q = $this->db->query($sql);
            if ($q->num_rows) {
                $vars['name'] = (string) $q->row['n'];
            }
        }
        $code = $this->langs()->activeLanguages()[$languageId] ?? '';
        $engine = new \Spintax\Engine();
        // Resolve #include in the preview too (same output as Test/Apply).
        $resolver = (false !== strpos($source, '#include'))
            ? \Spintax\Core\Template\IncludeResolver::build($engine, function (string $name): ?string {
                $sql = sprintf(
                    "SELECT source FROM %s WHERE name = '%s' ORDER BY template_id LIMIT 1",
                    $this->table('spintax_template'),
                    $this->db->escape($name)
                );

                $q = $this->db->query($sql);
                return isset($q->row['source']) ? (string) $q->row['source'] : null;
            }, $vars, $code)
            : null;
        $this->json(array('rendered' => $engine->renderPlain($source, $vars, $code, $resolver)));
    }

    private function permissionError(): string
    {
        $this->load->language('extension/module/spintax_seo');
        return $this->language->get('error_permission');
    }
}
