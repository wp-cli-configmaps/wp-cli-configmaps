<?php
/*
 * ConfigMaps configuration management for WordPress WP-CLI - Tame your wp_options using WP-CLI and git
 *
 * Copyright (C) 2022 Bostjan Skufca Jese
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * version 2 as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, see <https://www.gnu.org/licenses/gpl-2.0.html>.
 */

namespace WP\CLI\ConfigMaps;

use WP\CLI\ConfigMaps\ConfigMapService;
use WP_CLI;
use WP_CLI_Command;

if (!defined('WP_CLI')) {
    throw new Exception("Cannot run outside WP-CLI context");
}

/**
 * Configuration management for your wp_options table
 */
class Commands extends WP_CLI_Command
{

    public function __construct()
    {
        parent::__construct();

        if (is_multisite()) {
            throw new Exception("Multisite installs are currently not (yet) supported by wp-cli-configmaps");
        }

        if (defined('WP_CLI_CONFIGMAPS')) {
            $configMaps = WP_CLI_CONFIGMAPS;
        } else {
            $configMaps = [];
        }
        ConfigMapService::setCustomMaps($configMaps);

        if (ConfigMapService::getMapCount() == 0) {
            WP_CLI::warning("There are no config maps defined. Here are the steps to get you started:

1. Generate your first config map:

    wp configmaps generate --from-db --output=config-map-common.php

2. Configure a config map set in your wp-config.php:

    define('WP_CLI_CONFIGMAPS', [
        'common' => ABSPATH . 'config-map-common.php',
    //  WP_ENV   => ABSPATH . 'config-map-' . WP_ENV . '.php',   // This one is for later, when you'll have a per-environment config map overlays
    ]);

3. Verify/apply your config map(s) to the database:

    wp configmaps apply --dry-run

4. Update your config maps with new options (or update with fresh option values) from the wp_options database:

    wp configmaps update

More information is available at https://github.com/wp-cli-configmaps/wp-cli-configmaps.

");
        }
    }

    /**
     * Generate a config map content (in a form of PHP code)
     *
     * ## OPTIONS
     *
     * [--from-db]
     * : Generate an initial config map from your current wp_options content (default, alternative to --from-map=MAP-ID)
     *
     * [--from-map=<map-id>]
     * : Use existing config map as a template
     *
     * [--values-from-db]
     * : For settings defined in the generated config map, pull the values from the database (default)
     *
     * [--values-from-map=<map-id>]
     * : For settings defined in the generated map, pull the values from another config map
     *
     * [--output=FILE]
     * : Send the output to a FILE (default: STDOUT)
     *
     * ## EXAMPLES
     *
     * wp configmaps generate
     * wp configmaps generate --from-db --values-from-db   # The default
     * wp configmaps generate --from-map=dev --values-from-db --output=conf/map/stg.php   # One way to generate a new environment-specific map
     *
     * @synopsis [--from-db] [--from-map=<map-id>] [--values-from-db] [--values-from-map=<map-id>] [--output=<FILE>]
     */
    public function generate ($args, $assocArgs)
    {
        $cmdArgs = "";

        // Template
        if (isset($assocArgs['from-map'])) {
            $templateConfigMapId = $assocArgs['from-map'];
            if (!ConfigMapService::doesMapIdExist($templateConfigMapId)) {
                WP_CLI::error("Config map with id '$templateConfigMapId' is not defined (hint: `wp configmaps list` to see all defined config maps)");
            }
            $templateConfigMap = ConfigMapService::getMap($templateConfigMapId);

            $cmdArgs .= " --from-map=" . $templateConfigMapId;
        } else {
            $templateConfigMap = ConfigMapService::generateMapFromWpOptions();
            $cmdArgs .= " --from-db";
        }

        // Values
        if (isset($assocArgs['values-from-map'])) {
            $valueMapId = $assocArgs['values-from-map'];
            if (!ConfigMapService::doesMapIdExist($valueMapId)) {
                WP_CLI::error("Config map with id '$mapId' is not defined (hint: `wp configmaps list` to see all defined config maps)");
            }
            $valueMap = ConfigMapService::getMap($valueMapId);
            $cmdArgs .= " --values-from-map=" . $valueMapId;
        } else {
            $valueMap = ConfigMapService::generateMapFromWpOptions();
            $cmdArgs .= " --values-from-db";
        }

        // Merge values into the template map
        $newConfigMap = ConfigMapService::updateMapValues($templateConfigMap, $valueMap, 'add');

        // Generate PHP code
        $header = "// Generated by `wp configmaps generate". $cmdArgs ."` on ". date('c') .".";
        $phpContent = ConfigMapService::getMapAsPhp($newConfigMap, $header);

        // Output
        if (isset($assocArgs['output']) && ($assocArgs['output'] != '-')) {
            $filename = $assocArgs['output'];
            file_put_contents($filename, $phpContent);
            WP_CLI::success("New config map stored in the following file: " . $filename);
        } else {
            WP_CLI::line($phpContent);
        }
    }

    /**
     * List all defined config maps
     *
     * ## OPTIONS
     *
     * (none)
     *
     * ## EXAMPLES
     *
     * wp configmaps list
     *
     * @synopsis
     */
    public function list ()
    {
        $configMaps = ConfigMapService::getMapsMetadata();

        $tableRows = [];
        foreach ($configMaps as $mapId => $mapMetadata) {
            $tableRows[] = [
                'Config map ID' => $mapId,
                'Source file path' => ConfigMapService::getPrintableFilePath($mapMetadata['file']),
            ];
        }

        if (count($tableRows) == 0) {
            WP_CLI::error("There are no config maps defined");
        } else {
            WP_CLI\Utils\format_items('table', $tableRows, array_keys($tableRows[0]));
        }
    }

    /**
     * Show the final merged config map (from all defined maps), or an individual config map
     *
     * ## OPTIONS
     *
     * [<map-id>]
     * : Optional ID of an individual config map to show. When absent, a merged config map will be shown.
     *
     * [--php]
     * : Output the map as PHP code (suitable for updating your config map files)
     *
     * ## EXAMPLES
     *
     * wp configmaps show
     * wp configmaps show common
     * wp configmaps show common --php
     *
     * @synopsis [<map-id>] [--php]
     */
    public function show ($args, $assocArgs)
    {
        // Get the map
        if (isset($args[0])) {
            $mapId = $args[0];
            if (!ConfigMapService::doesMapIdExist($mapId)) {
                WP_CLI::error("Config map with id '$mapId' is not defined (hint: 'wp configmaps list' lists all defined config maps)");
            }
            $configMap = ConfigMapService::getMap($mapId);
        } else {
            $configMap = ConfigMapService::mergeDefinedMapSet();
        }

        // Output
        if (isset($assocArgs['php'])) {
            $outputText = ConfigMapService::getMapAsPhp($mapId);
            WP_CLI::line($outputText);
        } else {

            if (isset($mapId)) {
                $tableRows = self::convertConfigMapToTableRows($configMap);
            } else {
                $tableRows = self::convertConfigMapToTableRows($configMap, "", true);
            }

            if (count($tableRows) == 0) {
                WP_CLI::error("Your config map is empty, which is odd. Use `wp configmaps list` and `wp configmaps show` to list and inspect your config maps");
            }

            WP_CLI\Utils\format_items('table', $tableRows, array_keys($tableRows[0]));
        }
    }

    /**
     * Generate the actual display table content
     */
    public static function convertConfigMapToTableRows($configMap, $parentOptionName="", $showSourceMapId=false)
    {
        $tableRows = [];

        foreach ($configMap as $optionName => $optionSpec) {
            $tableRow = [
                'Option name' => $parentOptionName . $optionName,
                'Type'        => $optionSpec['type'],
            ];
            if ($optionSpec['action-apply'] == 'ignore') {
                $tableRow['Value'] = "(n/a)";
            } else {
                if ($optionSpec['type'] == "array") {
                    $tableRow['Value'] = "(array)";
                } else {
                    $tableRow['Value'] = $optionSpec['value'];
                }
            }
            $tableRow['Apply action'] = $optionSpec['action-apply'];
            if ($showSourceMapId) {
                $tableRow['Source map id'] = $optionSpec['source-map-id'];
            }
            $tableRows[] = $tableRow;

            if (
                ($optionSpec['type'] == "array")
                &&
                (is_array($optionSpec['value']))
            ) {
                $tableRowsForChildren = self::convertConfigMapToTableRows($optionSpec['value'], $parentOptionName . $optionName ." => ", $showSourceMapId);
                $tableRows = array_merge($tableRows, $tableRowsForChildren);
            }
        }

        return $tableRows;
    }

    /**
     * Verify consistency between config maps and database. Same as `configmaps apply --dry-run`, but sets exit status to `1` if inconsistencies (according to defined config maps) are found.
     *
     * ## OPTIONS
     *
     * (none)
     *
     * ## EXAMPLES
     *
     * wp configmaps verify
     *
     * @synopsis
     */
    public function verify ()
    {
        $pendingChanges = self::apply([], ['dry-run'=>true]);

        if (count($pendingChanges) > 0) {
            WP_CLI::error("Exiting with non-zero exit status as there are pending changes to be applied.");
        }
    }

    /**
     * Apply defined config maps (after a merge) to the database's wp_options table
     *
     * ## OPTIONS
     *
     * [--commit]
     * : Actually commit the changes to the database
     *
     * [--dry-run]
     * : Only show what is about to be done (default)
     *
     * ## EXAMPLES
     *
     * wp configmaps apply             # Does a --dry-run too
     * wp configmaps apply --dry-run
     * wp configmaps apply --commit    # Actually manipulates the database values
     *
     * @synopsis [--commit] [--dry-run]
     */
    public function apply ($args, $assocArgs)
    {
        if (!isset($assocArgs['commit'])) {
            $dryRun = true;
        } else {
            $dryRun = false;
        }

        $mergedConfigMap = ConfigMapService::mergeDefinedMapSet();
        $changedItems = ConfigMapService::applyMap($mergedConfigMap, $dryRun);

        if (count($changedItems) == 0) {
            WP_CLI::success("Database table wp_options is already consistent with the defined config maps.");
        } else {
            WP_CLI::line("Change summary:");
            WP_CLI\Utils\format_items('table', $changedItems, array_keys($changedItems[0]));
            if ($dryRun) {
                WP_CLI::warning("DRY RUN: Listed changes were NOT applied to the database. Rerun with `--commit` flag to perform the update.");
            } else {
                WP_CLI::success("All listed changes were applied to the database successfully.");
            }
        }

        // For self::verify()
        return $changedItems;
    }

    /**
     * Update defined config maps with values currently stored in the wp_options table. The item is updated in all active maps where it is defined. For updating an individual map, consult the `wp configmaps update --map=MY-MAP-ID` command.
     *
     * ## OPTIONS
     *
     * [<map-id>]
     * : Optional ID of the map to update. When absent, all defined maps are updated.
     *
     * ## EXAMPLES
     *
     * wp configmaps update          # Update map files for all defined maps (add undefined top-level options to the first map)
     * wp configmaps update common   # Update map file for map with called 'common' (ignore undefined top-level options)
     * wp configmaps update dev      # Update map file for map with ID 'dev'
     *
     * @synopsis [<map-id>]
     */
    public function update ($args, $assocArgs)
    {
        if (isset($args[0])) {
            $mapId = $args[0];
            if (!ConfigMapService::doesMapIdExist($mapId)) {
                WP_CLI::error("Config map with id '$mapId' is not defined (hint: `wp configmaps list` to see all defined config maps)");
            }
            $configMap = ConfigMapService::getMap($mapId);
            $configMaps = [
                $mapId => $configMap,
            ];
        } else {
            $configMap = ConfigMapService::mergeDefinedMapSet();
            $configMaps = ConfigMapService::getMaps();
        }

        // Get the current values from the DB
        $currentWpOptionsValueMap = ConfigMapService::generateMapFromWpOptions();

        // Loop through config maps that need to be updated
        $i = 0;
        foreach ($configMaps as $mapId => $configMap) {
            $i++;

            $undefKeyAction = (isset($mapId) || ($i > 1) ? 'ignore' : 'add');
            $updatedConfigMap = ConfigMapService::updateMapValues($configMap, $currentWpOptionsValueMap, $undefKeyAction);

            $header = "// Generated by `wp configmaps update` on ". date('c') .".";
            ConfigMapService::updateMapFile($mapId, $updatedConfigMap, $header);

            $mapFile = ConfigMapService::getMapFile($mapId);
            WP_CLI::success("Config map refreshed: ". $mapId .", ". ConfigMapService::getPrintableFilePath($mapFile));
        }
        if (!isset($assocArgs['map'])) {
            WP_CLI::success("All defined config map files have been refreshed. Use `git diff` to see the changes.");
        }
    }
}
