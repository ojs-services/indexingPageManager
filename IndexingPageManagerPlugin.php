<?php
/**
 * @file IndexingPageManagerPlugin.php
 *
 * Indexing Page Manager — main plugin class (OJS 3.5 generic plugin).
 *
 * Plugin behaviour summary
 *   - Idempotently seeds 4 built-in sections per journal on enable
 *   - Registers DAOs and Smarty helpers (incl. the {ipm_blocks} theme embed)
 *   - Mounts a public showcase page at /about/<slug> (default "databases")
 *   - Registers that page as a selectable Navigation Menu destination
 *   - Hosts all admin UIs (index list, section list, index form with logo
 *     upload, settings, template selector) via URL-addressable backend pages
 */


namespace APP\plugins\generic\indexingPageManager;

use APP\core\Application;
use APP\file\PublicFileManager;
use APP\plugins\generic\indexingPageManager\classes\IndexingPageManagerAdminController;
use APP\plugins\generic\indexingPageManager\classes\IndexingPageManagerSmartyHelper;
use APP\plugins\generic\indexingPageManager\classes\IpmIndexDAO;
use APP\plugins\generic\indexingPageManager\classes\IpmIndexSectionDAO;
use APP\plugins\generic\indexingPageManager\classes\IpmLogoStore;
use APP\plugins\generic\indexingPageManager\classes\IpmSectionDAO;
use APP\template\TemplateManager;
use Illuminate\Support\Facades\Schema;
use PKP\core\PKPApplication;
use PKP\db\DAORegistry;
use PKP\linkAction\LinkAction;
use PKP\linkAction\request\RedirectAction;
use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;
use PKP\security\Role;

class IndexingPageManagerPlugin extends GenericPlugin
{
    /**
     * Custom Navigation Menu Item type — lets the journal manager drop the
     * public databases page into any nav menu via Settings → Website →
     * Navigation Menus → Add Item.
     */
    public const NMI_TYPE_IPM_DATABASES = 'NMI_TYPE_IPM_DATABASES';

    /** Built-in section seed data. Slugs are immutable; rename only the display name. */
    private const BUILT_IN_SECTIONS = [
        ['slug' => 'indexing-and-abstracting',     'en' => 'Indexing & Abstracting',     'tr' => 'Dizinleme ve Özetleme'],
        ['slug' => 'discovery-and-search',         'en' => 'Discovery & Search',         'tr' => 'Keşif ve Arama'],
        ['slug' => 'identifiers-and-registration', 'en' => 'Identifiers & Registration', 'tr' => 'Tanımlayıcılar ve Kayıt'],
        ['slug' => 'archiving-and-preservation',   'en' => 'Archiving & Preservation',   'tr' => 'Arşivleme ve Koruma'],
    ];

    /**
     * Allowed values for the displayTemplate setting (4 layouts):
     *   logos          → logo only
     *   logo-name      → logo + name
     *   logo-name-desc → logo + name + description
     *   logo-desc      → logo + description
     */
    public const TEMPLATES = ['logos', 'logo-name', 'logo-name-desc', 'logo-desc'];

    /** Default template. */
    public const DEFAULT_TEMPLATE = 'logo-name-desc';

    /**
     * Resolve which caption parts a template shows.
     * @return array{name:bool,desc:bool}
     */
    public static function templateFlags($template)
    {
        switch ($template) {
            case 'logos':     return ['name' => false, 'desc' => false];
            case 'logo-name': return ['name' => true,  'desc' => false];
            case 'logo-desc': return ['name' => false, 'desc' => true];
            case 'logo-name-desc':
            default:          return ['name' => true,  'desc' => true];
        }
    }

    /**
     * Normalise a stored/posted template value: map the legacy "named" key to
     * "logo-name-desc" and fall back to the default for anything unknown.
     */
    public static function normalizeTemplate($template)
    {
        if ($template === 'named') return 'logo-name-desc'; // legacy (≤0.1.4)
        return in_array($template, self::TEMPLATES, true) ? $template : self::DEFAULT_TEMPLATE;
    }

    /** Allowed values for the displayColumns setting. */
    public const COLUMN_OPTIONS = [3, 4, 5];

    /** Default URL slug under /about/. */
    public const DEFAULT_SLUG = 'databases';

    public function getDisplayName()
    {
        return __('plugins.generic.indexingPageManager.name');
    }

    public function getDescription()
    {
        return __('plugins.generic.indexingPageManager.description');
    }

    public function register($category, $path, $mainContextId = null)
    {
        $success = parent::register($category, $path, $mainContextId);
        if (!$success) {
            return false;
        }

        $this->_registerDAOs();

        if ($this->getEnabled($mainContextId)) {
            // OJS 3.3's setEnabled() does NOT fire the Installer hook, so the
            // migration is only triggered by a fresh OJS install. Run it
            // self-healingly here on the first request after enable. The
            // migration is idempotent (hasTable guards), so this is safe and
            // effectively free once the tables exist.
            if (!$this->_tablesExist()) {
                $this->_runMigrationDirect();
            }
            if ($this->_tablesExist()) {
                $this->_maybeSeedBuiltIns($mainContextId);
                $this->_maybeSeedDefaultSettings($mainContextId);
                // Demo build only: if a `demo-data/` payload ships alongside
                // this plugin, auto-seed the bundled indexes + logos on the
                // first request after enable. The production build does NOT
                // ship `demo-data/`, so these methods are guaranteed no-ops.
                $this->_maybeSeedDemoIndexes($mainContextId);
                $this->_maybeUpgradeDemoIndexes($mainContextId);
            }

            // Override /about/<slug> with our frontend handler.
            Hook::add('LoadHandler', [$this, 'loadHandler']);

            // Register Smarty helpers (incl. {ipm_blocks}) on display.
            Hook::add('TemplateManager::display', [$this, 'registerSmartyHelpers']);

            // Inject the admin sidebar entry on backend pages. 3.5 moved this
            // signal from TemplateManager::display to setupBackendPage.
            Hook::add('TemplateManager::setupBackendPage', [$this, 'addSidebarLink']);

            // Register the public page as a selectable Navigation Menu type.
            Hook::add('NavigationMenus::itemTypes', [$this, 'addNavigationMenuItemTypes']);
            Hook::add('NavigationMenus::displaySettings', [$this, 'setNavigationMenuItemDisplaySettings']);

            // Clean up plugin data when its journal is deleted.
            Hook::add('Context::delete', [$this, 'cleanupOnJournalDelete']);
        }

        return true;
    }

    /**
     * @copydoc Plugin::getInstallMigration()
     */
    public function getInstallMigration()
    {
        return new IndexingPageManagerSchemaMigration();
    }

    /**
     * Cheap, driver-agnostic existence check used to keep register() safe
     * when tables haven't been migrated yet.
     */
    private function _tablesExist()
    {
        try {
            return Schema::hasTable('ipm_sections');
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Run the schema migration directly. Used as a self-healing fallback
     * when the plugin is marked enabled but tables don't exist. Idempotent.
     */
    private function _runMigrationDirect()
    {
        try {
            (new IndexingPageManagerSchemaMigration())->up();
        } catch (\Throwable $e) {
            error_log('[indexingPageManager] migration failed: ' . $e->getMessage());
        }
    }

    /**
     * LoadHandler dispatcher.
     *
     *   1. Backend admin pages  (/indexingPageManager/...)
     *      → IndexingPageManagerManageHandler
     *   2. Frontend public page (/about/<slug>, default /about/databases)
     *      → IndexingPageManagerHandler
     */
    public function loadHandler($hookName, $args)
    {
        // 3.5 LoadHandler signature: [&$page, &$op, &$sourceFile, &$handler].
        // Claim a route by SETTING $handler to a handler instance; the 3.3
        // define('HANDLER_CLASS', ...) constant is rejected by PKPPageRouter.
        $page = & $args[0];
        $op   = & $args[1];
        $handler = & $args[3];

        // (1) Backend admin route family.
        if ($page === 'indexingPageManager') {
            $handler = new \APP\plugins\generic\indexingPageManager\pages\IndexingPageManagerManageHandler();
            return true;
        }

        // (2) Frontend public page route — /about/<configured-slug>.
        if ($page !== 'about') {
            return false;
        }
        $slug = $this->_resolvePageSlug();
        if ($op !== $slug) {
            return false;
        }

        // PKPPageRouter checks in_array($op, get_class_methods($handler)) after
        // the hook returns, so $op MUST be a real method name on the handler.
        $handler = new \APP\plugins\generic\indexingPageManager\pages\IndexingPageManagerHandler();
        $op = 'index';
        return true;
    }

    /**
     * Resolve the public-page slug for the current context. Defaults to
     * "databases". Sanitised to the slug charset so it can never inject an
     * unexpected route segment.
     */
    private function _resolvePageSlug($contextId = null)
    {
        if ($contextId === null) {
            $contextId = $this->_currentContextId();
        }
        $slug = $contextId ? (string) $this->getSetting($contextId, 'pageSlug') : '';
        $slug = trim($slug);
        if ($slug === '' || !preg_match('/^[a-zA-Z][a-zA-Z0-9-]{0,99}$/', $slug)) {
            return self::DEFAULT_SLUG;
        }
        return $slug;
    }

    /** Public accessor used by handlers/forms to resolve the active slug. */
    public function getPageSlug($contextId = null)
    {
        return $this->_resolvePageSlug($contextId);
    }

    /**
     * Registers Smarty modifiers/functions used by the templates (incl. the
     * {ipm_blocks} theme-embed function). Idempotent.
     */
    public function registerSmartyHelpers($hookName, $args)
    {
        /** @var TemplateManager $templateMgr */
        $templateMgr = $args[0];

        IndexingPageManagerSmartyHelper::register($templateMgr, $this);
        return false;
    }

    /**
     * Inject an "Indexing Page" entry into the OJS admin sidebar menu.
     *
     * Detection strategy: infer "this is a backend page" from the presence of
     * the `menu` Vue state (populated by setupBackendPage()) rather than
     * allowlisting template paths. Visible to journal managers + site admins only.
     */
    public function addSidebarLink($hookName, $args)
    {
        // 3.5 fires TemplateManager::setupBackendPage with NO args — fetch the
        // request + TemplateManager ourselves. It runs AFTER the core menu tree
        // is pushed into state, so getState('menu') returns the built menu.
        $request = Application::get()->getRequest();
        $context = $request->getContext();
        if (!$context) return false;

        // Managers + site admins only. 3.5's User model exposes hasRole()
        // directly (the old UserGroupDAO round-trip is gone).
        $user = $request->getUser();
        if (!$user) return false;
        if (!$user->hasRole([Role::ROLE_ID_SITE_ADMIN, Role::ROLE_ID_MANAGER], $context->getId())) {
            return false;
        }

        $templateMgr = TemplateManager::getManager($request);
        $menu = $templateMgr->getState('menu');
        if (!is_array($menu)) {
            return false;
        }

        $dispatcher = $request->getDispatcher();
        $manageUrl  = $dispatcher->url(
            $request, PKPApplication::ROUTE_PAGE,
            $context->getPath(),
            'indexingPageManager', 'indexes'
        );

        $router      = $request->getRouter();
        $currentPage = method_exists($router, 'getRequestedPage')
            ? $router->getRequestedPage($request)
            : '';

        $menu['indexingPageManager'] = [
            'name'      => __('plugins.generic.indexingPageManager.action.manage'),
            'url'       => $manageUrl,
            'isCurrent' => ($currentPage === 'indexingPageManager'),
            // OJS 3.5's side menu shows an icon per entry (a name from its
            // icon set); OJS 3.4's menu has no icons and ignores it.
            'icon'      => 'Globe',
        ];
        $templateMgr->setState(['menu' => $menu]);

        return false;
    }

    /**
     * Hook: NavigationMenus::itemTypes — register the databases page as a
     * selectable navigation-menu destination type.
     */
    public function addNavigationMenuItemTypes($hookName, $args)
    {
        $types =& $args[0];
        $types[self::NMI_TYPE_IPM_DATABASES] = [
            'title'       => __('plugins.generic.indexingPageManager.navMenuItem.title'),
            'description' => __('plugins.generic.indexingPageManager.navMenuItem.description'),
        ];
        return false;
    }

    /**
     * Hook: NavigationMenus::displaySettings — resolve the URL + visibility
     * for our custom menu item type at render time.
     */
    public function setNavigationMenuItemDisplaySettings($hookName, $args)
    {
        $navigationMenuItem = $args[0];
        if ($navigationMenuItem->getType() !== self::NMI_TYPE_IPM_DATABASES) {
            return false;
        }

        $request = Application::get()->getRequest();
        $dispatcher = $request->getDispatcher();
        $context = $request->getContext();

        $isVisible = (bool) $context;
        $navigationMenuItem->setIsDisplayed($isVisible);

        if ($context) {
            $navigationMenuItem->setUrl($dispatcher->url(
                $request,
                PKPApplication::ROUTE_PAGE,
                $context->getPath(),
                'about',
                $this->_resolvePageSlug((int) $context->getId())
            ));
        }
        return false;
    }

    /**
     * Plugin actions in the plugin manager grid. Only "Manage" is exposed —
     * it opens the URL-based admin (IndexingPageManagerManageHandler).
     */
    public function getActions($request, $actionArgs)
    {
        $actions = parent::getActions($request, $actionArgs);
        if (!$this->getEnabled()) {
            return $actions;
        }

        $dispatcher = $request->getDispatcher();
        $contextPath = $request->getContext() ? $request->getContext()->getPath() : null;

        $manageUrl = $dispatcher->url(
            $request, PKPApplication::ROUTE_PAGE, $contextPath, 'indexingPageManager', 'indexes'
        );

        $manageAction = new LinkAction(
            'indexingPageManagerManage',
            new RedirectAction($manageUrl),
            __('plugins.generic.indexingPageManager.action.manage'),
            null
        );

        array_unshift($actions, $manageAction);
        return $actions;
    }

    /**
     * Plugin manager dispatcher. Routes the verb to the admin controller.
     * Retained for parity with the plugin-grid verb flow; the primary admin
     * UI is the URL-based manage handler.
     */
    public function manage($args, $request)
    {

        $verb = $request->getUserVar('verb');
        $controller = new IndexingPageManagerAdminController($this);

        // Read + mutation verbs that have controller methods. Form SAVES are
        // handled exclusively by the URL-based IndexingPageManagerManageHandler
        // (multipart fetch + clean JSON envelope), so they are intentionally
        // not dispatched here.
        switch ($verb) {
            case 'manage':        return $controller->indexList($request);
            case 'indexForm':     return $controller->indexForm($request);
            case 'indexDelete':   return $controller->indexDelete($request);
            case 'indexToggle':   return $controller->indexToggle($request);
            case 'indexReorder':  return $controller->indexReorder($request);

            case 'sections':      return $controller->sectionList($request);
            case 'sectionForm':   return $controller->sectionForm($request);
            case 'sectionDelete': return $controller->sectionDelete($request);
            case 'sectionToggle': return $controller->sectionToggle($request);
            case 'sectionReorder':return $controller->sectionReorder($request);

            case 'templateSelect':return $controller->templateSelect($request);
            case 'settings':      return $controller->settings($request);
        }

        return parent::manage($args, $request);
    }

    /**
     * Wire DAOs into the global registry. Safe to call repeatedly.
     */
    private function _registerDAOs()
    {

        DAORegistry::registerDAO('IpmSectionDAO',      new IpmSectionDAO());
        DAORegistry::registerDAO('IpmIndexDAO',        new IpmIndexDAO());
        DAORegistry::registerDAO('IpmIndexSectionDAO', new IpmIndexSectionDAO());
    }

    /**
     * Seed 3 built-in sections on first sight of a journal. Idempotent at the
     * slug level — missing slugs are inserted on the next call and existing
     * rows are left untouched.
     */
    private function _maybeSeedBuiltIns($mainContextId = null)
    {
        $journalId = $this->_resolveContextId($mainContextId);
        if (!$journalId) {
            return;
        }

        /** @var IpmSectionDAO $dao */
        $dao = DAORegistry::getDAO('IpmSectionDAO');
        if (!$dao) {
            return;
        }

        $existing = [];
        foreach ($dao->getByJournalId($journalId) as $section) {
            $existing[$section->getSlug()] = true;
        }

        $needed = array_column(self::BUILT_IN_SECTIONS, 'slug');
        if (!array_diff($needed, array_keys($existing))) {
            return;
        }

        foreach (self::BUILT_IN_SECTIONS as $i => $row) {
            if (isset($existing[$row['slug']])) {
                continue;
            }
            $section = $dao->newDataObject();
            $section->setJournalId($journalId);
            $section->setSlug($row['slug']);
            $section->setIsBuiltIn(true);
            $section->setIsActive(true);
            $section->setSeq($i);
            $section->setDisplayName($row['en'], 'en');
            $section->setDisplayName($row['tr'], 'tr');
            $dao->insertObject($section);
        }
    }

    /**
     * Seed sensible defaults for every plugin setting on first enable so the
     * admin lands on a Settings page that is already filled out. Each setting
     * is only persisted if not already present.
     */
    private function _maybeSeedDefaultSettings($mainContextId = null)
    {
        $journalId = $this->_resolveContextId($mainContextId);
        if (!$journalId) return;

        $defaults = [
            'pageTitle'       => ['object', ['en' => 'Indexes & Databases', 'tr' => 'İndeksler ve Veritabanları']],
            'introText'       => ['object', ['en' => '', 'tr' => '']],
            'pageSlug'        => ['string', self::DEFAULT_SLUG],
            'displayTemplate' => ['string', self::DEFAULT_TEMPLATE],
            'displayColumns'  => ['int',    4],
            'enableSchemaOrg' => ['bool',   true],
        ];

        foreach ($defaults as $name => $spec) {
            list($type, $value) = $spec;
            $current = $this->getSetting($journalId, $name);
            $isUnset = ($current === null)
                    || ($current === '')
                    || (is_array($current) && empty($current));
            if ($isUnset) {
                $this->updateSetting($journalId, $name, $value, $type);
            }
        }
    }

    /**
     * Auto-seed the bundled demo indexes + logos on first activation — ONLY
     * when the plugin was distributed in its "demo build" form, i.e. when a
     * `demo-data/` directory ships alongside the plugin.
     *
     *   - Detects the demo payload by looking for `demo-data/indexes.json`
     *     and `demo-data/logos/` next to this plugin file.
     *   - Guards re-runs with the `demoIndexesSeeded` plugin setting.
     *   - Bails when the demo payload is absent (production build no-op) or
     *     when the journal already has indexes (admin content preserved).
     */
    private function _maybeSeedDemoIndexes($mainContextId = null)
    {
        $journalId = $this->_resolveContextId($mainContextId);
        if (!$journalId) return;

        if ($this->getSetting($journalId, 'demoIndexesSeeded')) return;

        $demoDir  = $this->getPluginPath() . '/demo-data';
        $jsonPath = $demoDir . '/indexes.json';
        $logosDir = $demoDir . '/logos';
        if (!is_dir($demoDir) || !is_file($jsonPath) || !is_dir($logosDir)) {
            return; // production build: no demo payload
        }

        $rows = @json_decode(@file_get_contents($jsonPath), true);
        if (!is_array($rows) || empty($rows)) return;

        /** @var IpmSectionDAO $sectionDao */
        $sectionDao = DAORegistry::getDAO('IpmSectionDAO');
        /** @var IpmIndexDAO $indexDao */
        $indexDao   = DAORegistry::getDAO('IpmIndexDAO');
        /** @var IpmIndexSectionDAO $pivot */
        $pivot      = DAORegistry::getDAO('IpmIndexSectionDAO');
        if (!$sectionDao || !$indexDao || !$pivot) return;

        // If the journal already has indexes, don't overwrite — only the
        // built-in sections were seeded above.
        foreach ($indexDao->getByJournalId($journalId, false) as $_) {
            $this->updateSetting($journalId, 'demoIndexesSeeded', true, 'bool');
            return;
        }

        $sectionsBySlug = [];
        foreach ($sectionDao->getByJournalId($journalId) as $section) {
            $sectionsBySlug[$section->getSlug()] = $section;
        }
        if (empty($sectionsBySlug)) return;

        $publicFileManager = new PublicFileManager();
        $targetDir = $publicFileManager->getContextFilesPath((int) $journalId)
            . DIRECTORY_SEPARATOR . 'indexingPageManager' . DIRECTORY_SEPARATOR . 'logos';
        if (!is_dir($targetDir)) @mkdir($targetDir, 0755, true);

        foreach ($rows as $row) {
            $slug = $row['slug'] ?? null;
            if (!$slug || !isset($sectionsBySlug[$slug])) continue;

            $index = $indexDao->newDataObject();
            $index->setJournalId($journalId);
            foreach (['tr', 'en'] as $loc) {
                $index->setName(       $row['name'][$loc]        ?? '', $loc);
                $index->setDescription($row['description'][$loc] ?? '', $loc);
            }
            $index->setUrl($row['url'] ?? null);
            $index->setIsActive(true);
            $indexId = $indexDao->insertObject($index);

            // Copy the bundled logo into the journal's public dir.
            $logoFile = isset($row['logo']) ? basename((string) $row['logo']) : '';
            $src = $logosDir . DIRECTORY_SEPARATOR . $logoFile;
            if ($logoFile !== '' && is_readable($src)) {
                $ext = strtolower(pathinfo($logoFile, PATHINFO_EXTENSION));
                if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
                    $filename = sprintf('index-%d-%s.%s',
                        (int) $indexId,
                        substr(md5($indexId . microtime(true)), 0, 8),
                        $ext);
                    $dst = $targetDir . DIRECTORY_SEPARATOR . $filename;
                    if (@copy($src, $dst)) {
                        @chmod($dst, 0644);
                        $index->setLogoPath('indexingPageManager/logos/' . $filename);
                        $indexDao->updateObject($index);
                    }
                }
            }

            $pivot->attach($indexId, $sectionsBySlug[$slug]->getId());
        }

        $this->updateSetting($journalId, 'demoIndexesSeeded', true, 'bool');
    }

    /**
     * Stamp recorded against the `demoIndexesPatchVersion` setting. Bump this
     * WHENEVER `demo-data/indexes.json` gains richer data that older demo
     * installs should pick up on upgrade. The patcher fills only *empty*
     * fields on demo-seeded rows (matched by URL), so admin edits are kept.
     */
    const DEMO_INDEXES_PATCH_VERSION = '0.1.0';

    /**
     * Apply the latest demo dataset to previously seeded indexes on upgrade.
     * No-op on production (no demo-data/), on un-seeded journals, and on
     * journals already at the current stamp.
     */
    private function _maybeUpgradeDemoIndexes($mainContextId = null)
    {
        $journalId = $this->_resolveContextId($mainContextId);
        if (!$journalId) return;

        $stamp = (string) $this->getSetting($journalId, 'demoIndexesPatchVersion');
        if ($stamp === self::DEMO_INDEXES_PATCH_VERSION) return;

        if (!$this->getSetting($journalId, 'demoIndexesSeeded')) return;

        $jsonPath = $this->getPluginPath() . '/demo-data/indexes.json';
        if (!is_file($jsonPath)) return; // production build → no demo payload

        $rows = @json_decode(@file_get_contents($jsonPath), true);
        if (!is_array($rows) || empty($rows)) return;

        /** @var IpmIndexDAO $indexDao */
        $indexDao = DAORegistry::getDAO('IpmIndexDAO');
        if (!$indexDao) return;

        $byUrl = [];
        foreach ($indexDao->getByJournalId($journalId, false) as $existing) {
            $key = (string) $existing->getUrl();
            if ($key !== '') $byUrl[$key] = $existing;
        }
        if (!$byUrl) {
            $this->updateSetting($journalId, 'demoIndexesPatchVersion',
                self::DEMO_INDEXES_PATCH_VERSION, 'string');
            return;
        }

        foreach ($rows as $row) {
            $url = (string) ($row['url'] ?? '');
            if ($url === '' || !isset($byUrl[$url])) continue;
            $index   = $byUrl[$url];
            $touched = false;
            foreach (['tr', 'en'] as $loc) {
                $newDesc = (string) ($row['description'][$loc] ?? '');
                if ($newDesc === '') continue;
                $current = (string) $index->getDescription($loc);
                if ($current !== '') continue; // never overwrite admin edits
                $index->setDescription($newDesc, $loc);
                $touched = true;
            }
            if ($touched) $indexDao->updateObject($index);
        }

        $this->updateSetting($journalId, 'demoIndexesPatchVersion',
            self::DEMO_INDEXES_PATCH_VERSION, 'string');
    }

    /**
     * Hook callback fired by Context::delete. Sweeps every ipm_* row that
     * references the deleted journal_id, plus its logo files. The hook passes
     * the Context being deleted by reference (array(&$context)).
     *
     * @return false  Don't short-circuit other listeners.
     */
    public function cleanupOnJournalDelete($hookName, $args)
    {
        $context = $args[0] ?? null;
        $journalId = is_object($context) ? (int) $context->getId() : (int) $context;
        if (!$journalId) return false;
        if (!$this->_tablesExist()) return false;

        /** @var IpmIndexDAO $indexDao */
        $indexDao = DAORegistry::getDAO('IpmIndexDAO');
        /** @var IpmSectionDAO $sectionDao */
        $sectionDao = DAORegistry::getDAO('IpmSectionDAO');

        if ($indexDao) {
            foreach ($indexDao->getByJournalId($journalId, false) as $index) {
                if ($index->getLogoPath()) {
                    IpmLogoStore::deleteByPath($journalId, $index->getLogoPath());
                }
                $indexDao->deleteObject($index);
            }
        }
        if ($sectionDao) {
            foreach ($sectionDao->getByJournalId($journalId) as $section) {
                $sectionDao->deleteObject($section);
            }
        }
        return false;
    }

    /**
     * Resolve the active journal id, preferring the explicit $mainContextId
     * passed to register() and falling back to the current request context.
     */
    private function _resolveContextId($mainContextId = null)
    {
        if ($mainContextId !== null && $mainContextId !== PKPApplication::CONTEXT_SITE) {
            return (int) $mainContextId;
        }
        return $this->_currentContextId();
    }

    private function _currentContextId()
    {
        $request = Application::get()->getRequest();
        if (!$request) return null;
        $context = $request->getContext();
        return $context ? (int) $context->getId() : null;
    }

    /**
     * Built-in slugs — used by the section form to lock slug rename and by
     * the delete handler to refuse deletion.
     *
     * @return string[]
     */
    public static function getBuiltInSlugs()
    {
        return array_column(self::BUILT_IN_SECTIONS, 'slug');
    }

    /**
     * Full built-in section definitions ([slug, en_US, tr_TR]). Exposed so the
     * demo seeder can (re)create the built-in sections in a CLI context where
     * the plugin's register()/seed hooks don't run.
     *
     * @return array[]
     */
    public static function getBuiltInSections()
    {
        return self::BUILT_IN_SECTIONS;
    }
}
