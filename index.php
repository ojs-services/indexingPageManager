<?php

/**
 * @defgroup plugins_generic_indexingPageManager Indexing Page Manager
 */

/**
 * @file index.php
 *
 * Distributed under the GNU GPL v2. For full terms see the file LICENSE.
 *
 * @ingroup plugins_generic_indexingPageManager
 * @brief Wrapper for the Indexing Page Manager plugin. OJS's PluginRegistry
 *        loads each generic plugin by including its index.php and using the
 *        returned object — so this thin wrapper is required for the plugin
 *        to appear in the plugin manager list.
 */

// Line guard: this build targets OJS 3.3, whose plugin class uses the global,
// non-namespaced GenericPlugin. On OJS 3.4+ the core is namespaced; if the
// namespaced plugin base exists we are on the wrong line, so return quietly and
// let OJS skip this plugin instead of risking an error on the site or CLI tools.
if (class_exists('PKP\plugins\GenericPlugin')) {
    return;
}

require_once('IndexingPageManagerPlugin.inc.php');

return new IndexingPageManagerPlugin();
