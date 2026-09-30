<?php
/**
 * @file classes/IndexingPageManagerAdminController.php
 *
 * Indexing Page Manager — admin controller.
 *
 * Renders the list/form fragments and handles the AJAX verbs for index +
 * section CRUD. Each method returns a JSONMessage whose content is the
 * rendered fragment (lists/forms) or a status payload (toggle/delete/reorder).
 * The URL-based IndexingPageManagerManageHandler wraps these.
 */

namespace APP\plugins\generic\indexingPageManager\classes;

use APP\plugins\generic\indexingPageManager\IndexingPageManagerPlugin;
use APP\plugins\generic\indexingPageManager\classes\form\IpmIndexForm;
use APP\plugins\generic\indexingPageManager\classes\form\IpmSectionForm;
use APP\plugins\generic\indexingPageManager\classes\form\IpmSettingsForm;
use APP\plugins\generic\indexingPageManager\classes\form\IpmTemplateForm;
use APP\template\TemplateManager;
use PKP\core\JSONMessage;
use PKP\core\PKPApplication;
use PKP\db\DAORegistry;

class IndexingPageManagerAdminController
{
    /** @var IndexingPageManagerPlugin */
    private $plugin;

    public function __construct($plugin)
    {
        $this->plugin = $plugin;
    }

    // -----------------------------------------------------------------
    // Index list
    // -----------------------------------------------------------------

    public function indexList($request)
    {
        $contextId = $this->_contextId($request);

        /** @var IpmSectionDAO $sectionDao */
        $sectionDao = DAORegistry::getDAO('IpmSectionDAO');
        /** @var IpmIndexDAO $indexDao */
        $indexDao = DAORegistry::getDAO('IpmIndexDAO');

        $sections = $sectionDao->getByJournalId($contextId);
        $payload = [];
        $totalIndexes = 0;
        $activeIndexes = 0;
        $usedSectionCount = 0;

        foreach ($sections as $section) {
            $indexes = $indexDao->getBySectionId($section->getId(), false);
            if (!empty($indexes)) {
                $usedSectionCount++;
            }
            $payload[] = ['section' => $section, 'indexes' => $indexes];
        }

        foreach ($indexDao->getByJournalId($contextId, false) as $idx) {
            $totalIndexes++;
            if ($idx->getIsActive()) $activeIndexes++;
        }

        $template = IndexingPageManagerPlugin::normalizeTemplate($this->plugin->getSetting($contextId, 'displayTemplate'));
        $columns  = (int) ($this->plugin->getSetting($contextId, 'displayColumns') ?: 4);

        $slug = $this->plugin->getPageSlug($contextId);
        $previewUrl = $request->getDispatcher()->url(
            $request, PKPApplication::ROUTE_PAGE,
            $request->getContext() ? $request->getContext()->getPath() : null,
            'about', $slug
        );

        $tm = TemplateManager::getManager($request);
        $tm->assign([
            'pluginName'       => $this->plugin->getName(),
            'indexBlocks'      => $payload,
            'totalIndexes'     => $totalIndexes,
            'activeIndexes'    => $activeIndexes,
            'totalSections'    => count($sections),
            'usedSectionCount' => $usedSectionCount,
            'activeTemplate'   => $template,
            'activeColumns'    => $columns,
            'contextId'        => $contextId,
            'csrfToken'        => IpmCompat::csrfToken($request),
            'previewUrl'       => $previewUrl,
        ]);

        return new JSONMessage(true, $tm->fetch($this->plugin->getTemplateResource('admin/indexList.tpl')));
    }

    // -----------------------------------------------------------------
    // Index add/edit form
    // -----------------------------------------------------------------

    public function indexForm($request)
    {
        $contextId = $this->_contextId($request);
        $indexId = (int) $request->getUserVar('indexId') ?: null;

        $form = new IpmIndexForm($this->plugin, $contextId, $indexId);
        $form->initData();

        return new JSONMessage(true, $form->fetch($request));
    }

    public function indexDelete($request)
    {
        $this->_assertPostAndCsrf($request);
        $contextId = $this->_contextId($request);
        $indexId = (int) $request->getUserVar('indexId');
        if (!$indexId) {
            return new JSONMessage(false, __('common.error'));
        }

        /** @var IpmIndexDAO $indexDao */
        $indexDao = DAORegistry::getDAO('IpmIndexDAO');
        $index = $indexDao->getById($indexId, $contextId);
        if (!$index) {
            return new JSONMessage(false, __('common.error'));
        }

        if ($index->getLogoPath()) {
            IpmLogoStore::deleteByPath($contextId, $index->getLogoPath());
        }
        $indexDao->deleteObject($index);

        return new JSONMessage(true);
    }

    public function indexToggle($request)
    {
        $this->_assertPostAndCsrf($request);
        $contextId = $this->_contextId($request);
        $indexId = (int) $request->getUserVar('indexId');
        $isActive = (int) $request->getUserVar('isActive') ? true : false;

        /** @var IpmIndexDAO $indexDao */
        $indexDao = DAORegistry::getDAO('IpmIndexDAO');
        $index = $indexDao->getById($indexId, $contextId);
        if (!$index) {
            return new JSONMessage(false, __('common.error'));
        }
        $indexDao->setActive($indexId, $isActive);
        return new JSONMessage(true, ['isActive' => $isActive]);
    }

    public function indexReorder($request)
    {
        $this->_assertPostAndCsrf($request);
        $contextId = $this->_contextId($request);
        $sectionId = (int) $request->getUserVar('sectionId');
        $order = (array) $request->getUserVar('order');

        /** @var IpmSectionDAO $sectionDao */
        $sectionDao = DAORegistry::getDAO('IpmSectionDAO');
        $section = $sectionDao->getById($sectionId, $contextId);
        if (!$section) {
            return new JSONMessage(false, __('common.error'));
        }

        /** @var IpmIndexSectionDAO $pivotDao */
        $pivotDao = DAORegistry::getDAO('IpmIndexSectionDAO');
        $pivotDao->reorderIndexesInSection($sectionId, array_map('intval', $order));

        return new JSONMessage(true);
    }

    // -----------------------------------------------------------------
    // Sections
    // -----------------------------------------------------------------

    public function sectionList($request)
    {
        $contextId = $this->_contextId($request);

        /** @var IpmSectionDAO $sectionDao */
        $sectionDao = DAORegistry::getDAO('IpmSectionDAO');
        /** @var IpmIndexSectionDAO $pivotDao */
        $pivotDao = DAORegistry::getDAO('IpmIndexSectionDAO');

        $rows = [];
        foreach ($sectionDao->getByJournalId($contextId) as $section) {
            $rows[] = [
                'section'       => $section,
                'totalIndexes'  => $pivotDao->countIndexesInSection($section->getId(), false),
                'activeIndexes' => $pivotDao->countIndexesInSection($section->getId(), true),
            ];
        }

        $tm = TemplateManager::getManager($request);
        $tm->assign([
            'pluginName' => $this->plugin->getName(),
            'rows'       => $rows,
            'csrfToken'  => IpmCompat::csrfToken($request),
        ]);

        return new JSONMessage(true, $tm->fetch($this->plugin->getTemplateResource('admin/sectionList.tpl')));
    }

    public function sectionForm($request)
    {
        $contextId = $this->_contextId($request);
        $sectionId = (int) $request->getUserVar('sectionId') ?: null;

        $form = new IpmSectionForm($this->plugin, $contextId, $sectionId);
        $form->initData();

        return new JSONMessage(true, $form->fetch($request));
    }

    public function sectionDelete($request)
    {
        $this->_assertPostAndCsrf($request);
        $contextId = $this->_contextId($request);
        $sectionId = (int) $request->getUserVar('sectionId');

        /** @var IpmSectionDAO $sectionDao */
        $sectionDao = DAORegistry::getDAO('IpmSectionDAO');
        $section = $sectionDao->getById($sectionId, $contextId);
        if (!$section) {
            return new JSONMessage(false, __('common.error'));
        }
        if ($section->getIsBuiltIn()) {
            return new JSONMessage(false, __('plugins.generic.indexingPageManager.admin.sections.error.cannotDeleteBuiltIn'));
        }

        $sectionDao->deleteObject($section);
        return new JSONMessage(true);
    }

    public function sectionToggle($request)
    {
        $this->_assertPostAndCsrf($request);
        $contextId = $this->_contextId($request);
        $sectionId = (int) $request->getUserVar('sectionId');
        $isActive = (int) $request->getUserVar('isActive') ? true : false;

        /** @var IpmSectionDAO $sectionDao */
        $sectionDao = DAORegistry::getDAO('IpmSectionDAO');
        $section = $sectionDao->getById($sectionId, $contextId);
        if (!$section) {
            return new JSONMessage(false, __('common.error'));
        }
        $section->setIsActive($isActive);
        $sectionDao->updateObject($section);
        return new JSONMessage(true);
    }

    public function sectionReorder($request)
    {
        $this->_assertPostAndCsrf($request);
        $contextId = $this->_contextId($request);
        $order = array_map('intval', (array) $request->getUserVar('order'));

        /** @var IpmSectionDAO $sectionDao */
        $sectionDao = DAORegistry::getDAO('IpmSectionDAO');

        $seq = 0;
        foreach ($order as $sectionId) {
            $section = $sectionDao->getById($sectionId, $contextId);
            if (!$section) continue;
            $section->setSeq($seq++);
            $sectionDao->updateObject($section);
        }

        return new JSONMessage(true);
    }

    // -----------------------------------------------------------------
    // Template selector + settings
    // -----------------------------------------------------------------

    public function templateSelect($request)
    {
        $contextId = $this->_contextId($request);
        $form = new IpmTemplateForm($this->plugin, $contextId);

        if ($request->getUserVar('save')) {
            $form->readInputData();
            if (!$form->validate()) {
                return new JSONMessage(true, $form->fetch($request));
            }
            $form->execute();
            return new JSONMessage(true);
        }

        $form->initData();
        return new JSONMessage(true, $form->fetch($request));
    }

    public function settings($request)
    {
        $contextId = $this->_contextId($request);
        $form = new IpmSettingsForm($this->plugin, $contextId);

        if ($request->getUserVar('save')) {
            $form->readInputData();
            if (!$form->validate()) {
                return new JSONMessage(true, $form->fetch($request));
            }
            $form->execute();
            return new JSONMessage(true);
        }
        $form->initData();
        return new JSONMessage(true, $form->fetch($request));
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function _contextId($request)
    {
        $context = $request->getContext();
        return $context ? (int) $context->getId() : 0;
    }

    private function _assertPostAndCsrf($request)
    {
        if (!$request->isPost()) {
            throw new \Exception('POST required');
        }
        $session = $request->getSession();
        $expected = $session ? IpmCompat::csrfToken($request) : null;
        $supplied = (string) $request->getUserVar('csrfToken');
        if (!$expected || !hash_equals($expected, $supplied)) {
            throw new \Exception('Invalid CSRF token');
        }
    }
}
