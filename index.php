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

// Line guard: this build targets OJS 3.3. We detect the OJS line by asking
// whether the namespaced application core PKP\core\PKPApplication is declared.
// It exists on OJS 3.4+ (the application object is created at bootstrap, long
// before any plugin's index.php is read) and does NOT exist on OJS 3.3, whose
// core classes are global and non-namespaced. So on 3.4+ this returns and the
// package stays inert; on 3.3 it proceeds and loads the plugin.
//
// The second argument MUST stay false: autoload is deliberately disabled so this
// check never asks a class loader to resolve "PKP\core\PKPApplication". On a 3.3
// site a greedy third-party autoloader (e.g. deiaSurvey) maps PKP\ names onto
// lib/pkp/classes/*.inc.php and require()s them, which re-declares an already
// loaded global class and fatals ("Cannot declare class ...") in CLI tools such
// as upgrade.php and installPluginVersion.php. With autoload off this is a pure
// "already declared?" test and never triggers that loader.
//
// We test PKP\core\PKPApplication, NOT PKP\plugins\GenericPlugin: when OJS reads
// a plugin's index.php the namespaced GenericPlugin is not yet loaded on 3.4/3.5,
// so class_exists(...,false) on it would return false there too, and a 3.3
// package wrongly installed on 3.4/3.5 would load its 3.3 code and fatal ("Call
// to undefined function import()"). PKP\core\PKPApplication is already loaded on
// 3.4+, so it tells the lines apart correctly with autoload off.
if (class_exists('PKP\core\PKPApplication', false)) {
    return;
}

require_once('IndexingPageManagerPlugin.inc.php');

return new IndexingPageManagerPlugin();
