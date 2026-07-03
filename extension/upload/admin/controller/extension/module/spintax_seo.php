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
    /** Load the engine library's SPL autoloader (not PSR-4 under OpenCart). */
    private function bootstrap(): void
    {
        require_once DIR_SYSTEM . 'library/spintax/autoload.php';
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
        foreach (array('save', 'delete', 'targets', 'test', 'initBaseline', 'dryRun', 'apply', 'walk', 'releaseLock', 'templateSave', 'templateDelete', 'templateValidate', 'templatePreview') as $m) {
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
     * Fired on admin/model/catalog/product/{editProduct,addProduct}/after.
     * edit → product_id is $args[0]; add → the new id is $output (addProduct's return).
     *
     * @param string     $route
     * @param array      $args
     * @param mixed|null $output
     */
    public function eventProduct($route, $args, $output = null): void
    {
        $this->bootstrap();

        $productId = (false !== strpos((string) $route, 'edit'))
            ? (int) ($args[0] ?? 0)
            : (int) $output;

        if ($productId <= 0) {
            return;
        }

        $engine = new \Spintax\Engine();
        $langs = new \Spintax\Catalog\LanguageResolver($this->db(), DB_PREFIX);
        $cacheFlush = function (): void {
            $this->cache->delete('product');
        };

        (new \Spintax\Core\Binding\SaveEventRunner($this->db(), DB_PREFIX, $engine, $langs, $cacheFlush))
            ->onProductSave($productId);
    }

    /**
     * Fired on admin/model/catalog/product/deleteProduct/after — orphan purge (§6.2).
     *
     * @param string $route
     * @param array  $args
     */
    public function eventProductDelete($route, $args): void
    {
        $productId = (int) ($args[0] ?? 0);
        if ($productId <= 0) {
            return;
        }
        $prefix = DB_PREFIX;
        $this->db->query("DELETE FROM `{$prefix}spintax_signature` WHERE entity_id = " . $productId);
        $this->db->query("DELETE FROM `{$prefix}spintax_source` WHERE entity_type = 'product' AND entity_id = " . $productId);
    }

    // --- library factories ---------------------------------------------------

    private function langs(): \Spintax\Catalog\LanguageResolver
    {
        return new \Spintax\Catalog\LanguageResolver($this->db(), DB_PREFIX);
    }

    private function applier(): \Spintax\Core\Binding\Applier
    {
        $cacheFlush = function (): void {
            $this->cache->delete('product');
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

    /** Resolve a binding's spintax source (template mode → template.source; null if unresolved). */
    private function sourceFor(array $bindingRow): ?string
    {
        if (('template' === ($bindingRow['source_mode'] ?? '')) && (int) ($bindingRow['template_id'] ?? 0) > 0) {
            $q = $this->db->query("SELECT source FROM `" . DB_PREFIX . "spintax_template` WHERE template_id = " . (int) $bindingRow['template_id']);
            return $q->num_rows > 0 ? (string) $q->row['source'] : null;
        }
        return null; // per_entity (Phase 2) / missing
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
        $productBinding = \Spintax\Core\Binding\ProductBinding::fromRow($binding);
        $cell = $this->applier()->explainCell(
            (int) ($this->request->post['entity_id'] ?? 0),
            $productBinding,
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
            \Spintax\Core\Binding\ProductBinding::fromRow($binding),
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
        $this->json($this->walkEngine()->applyChunk(
            $binding,
            $this->sourceFor($binding),
            (string) ($this->request->post['dry_run_token'] ?? ''),
            $chunk,
            $lockTs
        ));
    }

    public function walk(): void
    {
        $this->bootstrap();
        $state = $this->walkEngine()->loadWalk((string) ($this->request->get['binding_id'] ?? ''));
        $this->json($state ?? array());
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
        // Optional sample entity: pull its name so %name% resolves in the preview.
        $entityId = (int) ($this->request->post['entity_id'] ?? 0);
        $languageId = (int) ($this->request->post['language_id'] ?? 0);
        if ($entityId > 0 && $languageId > 0) {
            $q = $this->db->query("SELECT name FROM `" . DB_PREFIX . "product_description` WHERE product_id = {$entityId} AND language_id = {$languageId}");
            if ($q->num_rows) {
                $vars['name'] = (string) $q->row['name'];
            }
        }
        $code = $this->langs()->activeLanguages()[$languageId] ?? '';
        $this->json(array('rendered' => (new \Spintax\Engine())->renderPlain($source, $vars, $code)));
    }

    private function permissionError(): string
    {
        $this->load->language('extension/module/spintax_seo');
        return $this->language->get('error_permission');
    }
}
