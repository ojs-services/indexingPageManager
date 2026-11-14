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
 * @brief Wrapper for the Indexing Page Manager plugin (OJS 3.4 / 3.5).
 *
 * OJS 3.4 and 3.5 load the namespaced plugin class directly. OJS 3.3 always
 * loads plugins through this file, so this is where a package installed on the
 * wrong OJS line is stopped: on OJS 3.3 the namespaced class cannot load, and a
 * fatal here (or a null return, which trips PluginRegistry's
 * assert(is_a($plugin, 'Plugin'))) would break the whole site and its
 * command-line tools. There we return an inert placeholder that adds no hooks
 * and tells the administrator in the plugin list which package to install.
 */

if (!class_exists('PKP\\plugins\\GenericPlugin')) {
    // OJS 3.3: wrong package.
    // tara-yoksay: P04 — import() runs only on OJS 3.3 (this wrong-line branch); import() exists there.
    import('lib.pkp.classes.plugins.GenericPlugin');
    if (!class_exists('IndexingPageManagerPlugin', false)) {
        class IndexingPageManagerPlugin extends GenericPlugin
        {
            public function register($category, $path, $mainContextId = null)
            {
                parent::register($category, $path, $mainContextId);
                return true; // listed, but adds no hooks: the site is not touched
            }

            public function getDisplayName()
            {
                return 'Indexing Page Manager (wrong package)';
            }

            public function getDescription()
            {
                return 'This package is for OJS 3.4 and 3.5 and does nothing on this site. '
                    . 'Delete it and install the OJS 3.3 package (indexingPageManager-ojs3.3-<version>.tar.gz).';
            }
        }
    }
    return new IndexingPageManagerPlugin();
}

return new \APP\plugins\generic\indexingPageManager\IndexingPageManagerPlugin();
