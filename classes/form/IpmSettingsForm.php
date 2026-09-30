<?php
/**
 * @file classes/form/IpmSettingsForm.php
 *
 * Indexing Page Manager — full settings form.
 *
 * Persists per-journal plugin settings:
 *   - pageTitle       (multilingual) — heading shown above the showcase
 *   - introText       (multilingual) — short description under the heading
 *   - pageSlug        (string)       — URL slug under /about/, default "databases"
 *   - displayTemplate (string)       — logos | named
 *   - displayColumns  (int)          — 3 | 4 | 5
 *   - enableSchemaOrg (bool)
 */

namespace APP\plugins\generic\indexingPageManager\classes\form;

use APP\core\Application;
use APP\plugins\generic\indexingPageManager\IndexingPageManagerPlugin;
use APP\template\TemplateManager;
use PKP\core\PKPApplication;
use PKP\db\DAORegistry;
use PKP\facades\Locale;
use PKP\form\Form;
use PKP\form\validation\FormValidatorCSRF;
use PKP\form\validation\FormValidatorInSet;
use PKP\form\validation\FormValidatorPost;
use PKP\form\validation\FormValidatorRegExp;

class IpmSettingsForm extends Form
{
    /** @var IndexingPageManagerPlugin */
    private $plugin;

    /** @var int */
    private $contextId;

    public function __construct($plugin, $contextId)
    {
        parent::__construct($plugin->getTemplateResource('admin/settings.tpl'));
        $this->plugin = $plugin;
        $this->contextId = (int) $contextId;

        $this->addCheck(new FormValidatorPost($this));
        $this->addCheck(new FormValidatorCSRF($this));

        $this->addCheck(new FormValidatorInSet(
            $this, 'displayTemplate', 'required',
            'plugins.generic.indexingPageManager.template.error.invalid',
            IndexingPageManagerPlugin::TEMPLATES
        ));
        $this->addCheck(new FormValidatorRegExp(
            $this, 'pageSlug', 'required',
            'plugins.generic.indexingPageManager.settings.error.pageSlug',
            '/^[a-zA-Z][a-zA-Z0-9-]{0,99}$/'
        ));
    }

    public function initData()
    {
        $g = function ($name, $default = null) {
            $v = $this->plugin->getSetting($this->contextId, $name);
            return ($v === null || $v === '') ? $default : $v;
        };

        $this->_data = [
            'pageTitle'       => $g('pageTitle', []),
            'introText'       => $g('introText', []),
            'pageSlug'        => $g('pageSlug', IndexingPageManagerPlugin::DEFAULT_SLUG),
            'displayTemplate' => IndexingPageManagerPlugin::normalizeTemplate($g('displayTemplate', IndexingPageManagerPlugin::DEFAULT_TEMPLATE)),
            'displayColumns'  => (int) $g('displayColumns', 4),
            'enableSchemaOrg' => $g('enableSchemaOrg', true) ? 1 : 0,
        ];
    }

    public function readInputData()
    {
        $this->readUserVars([
            'pageTitle', 'introText', 'pageSlug',
            'displayTemplate', 'displayColumns', 'enableSchemaOrg',
        ]);

        // Coerce slug to the allowed charset; blank → default.
        $slug = trim((string) $this->getData('pageSlug'));
        if ($slug === '' || !preg_match('/^[a-zA-Z][a-zA-Z0-9-]{0,99}$/', $slug)) {
            $slug = IndexingPageManagerPlugin::DEFAULT_SLUG;
        }
        $this->setData('pageSlug', $slug);

        // Coerce columns to one of the allowed values.
        $cols = (int) $this->getData('displayColumns');
        if (!in_array($cols, IndexingPageManagerPlugin::COLUMN_OPTIONS, true)) {
            $cols = 4;
        }
        $this->setData('displayColumns', $cols);
    }

    public function fetch($request, $template = null, $display = false)
    {
        $tm = TemplateManager::getManager($request);

        /** @var IpmSectionDAO|null $sectionDao */
        $sectionDao = DAORegistry::getDAO('IpmSectionDAO');
        $sectionCount = $sectionDao ? count($sectionDao->getByJournalId($this->contextId)) : 0;

        $context = $request->getContext();
        $slug = $this->getData('pageSlug') ?: IndexingPageManagerPlugin::DEFAULT_SLUG;
        $publicUrl = $request->getDispatcher()->url(
            $request, PKPApplication::ROUTE_PAGE,
            $context ? $context->getPath() : null,
            'about', $slug
        );

        $tm->assign([
            'pluginName'       => $this->plugin->getName(),
            'sectionCount'     => $sectionCount,
            'supportedLocales' => $this->_supportedLocales(),
            'primaryLocale'    => Locale::getPrimaryLocale(),
            'localeNames'      => Locale::getFormattedDisplayNames(),
            'templates'        => IndexingPageManagerPlugin::TEMPLATES,
            'columnOptions'    => IndexingPageManagerPlugin::COLUMN_OPTIONS,
            'publicUrl'        => $publicUrl,
        ]);
        return parent::fetch($request, $template, $display);
    }

    public function execute(...$args)
    {
        $this->plugin->updateSetting($this->contextId, 'pageTitle',       $this->getData('pageTitle'), 'object');
        $this->plugin->updateSetting($this->contextId, 'introText',       $this->getData('introText'), 'object');
        $this->plugin->updateSetting($this->contextId, 'pageSlug',        $this->getData('pageSlug'), 'string');
        $this->plugin->updateSetting($this->contextId, 'displayTemplate', $this->getData('displayTemplate'), 'string');
        $this->plugin->updateSetting($this->contextId, 'displayColumns',  (int) $this->getData('displayColumns'), 'int');
        $this->plugin->updateSetting($this->contextId, 'enableSchemaOrg', (int) $this->getData('enableSchemaOrg') ? true : false, 'bool');
        parent::execute(...$args);
    }

    private function _supportedLocales()
    {
        $request = Application::get()->getRequest();
        $context = $request->getContext();
        if ($context && method_exists($context, 'getSupportedFormLocales')) {
            $locales = $context->getSupportedFormLocales();
            if (!empty($locales)) return $locales;
        }
        $supported = Locale::getSupportedLocales();
        return $supported ? array_keys($supported) : [Locale::getPrimaryLocale()];
    }
}
