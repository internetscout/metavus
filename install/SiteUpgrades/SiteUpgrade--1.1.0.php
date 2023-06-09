<?PHP
#
#   FILE:  SiteUpgrade--1.1.0.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2023 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus;

use Exception;
use ScoutLib\Database;

$GLOBALS["G_ErrMsgs"] = SiteUpgrade110_PerformUpgrade();

/**
 * Perform all site upgrades for 1.1.0
 * @return null|array Returns NULL on success or a list of error messages
 *      if an error occurs.
 */
function SiteUpgrade110_PerformUpgrade()
{
    try {
        SiteUpgrade110_MigrateSavedSearchParamsForPlugins();
    } catch (Exception $Exception) {
        return [
            $Exception->getMessage(),
            "Exception Trace:<br/><pre>"
            .$Exception->getTraceAsString()."</pre>"
        ];
    }
    return null;
}

/**
 * Convert stored instances of ScoutLib\SearchParameterSet in plugin
 * configurations to Metavus\SearchParameterSet.
 */
function SiteUpgrade110_MigrateSavedSearchParamsForPlugins(): void
{
    $DB = new Database();

    $DB->query("LOCK TABLES PluginInfo WRITE");

    $DB->query("SELECT PluginId, Cfg FROM PluginInfo");
    $PluginConfigs = $DB->fetchColumn("Cfg", "PluginId");

    foreach ($PluginConfigs as $PluginId => $ConfigData) {
        if ($ConfigData === null) {
            continue;
        }

        $Changed = false;
        $Config = unserialize($ConfigData);
        if (!is_array($Config)) {
            continue;
        }

        foreach ($Config as $Key => &$Val) {
            if ($Val instanceof \ScoutLib\SearchParameterSet) {
                $Val = new \Metavus\SearchParameterSet(
                    $Val->data()
                );
                $Changed = true;
            }
        }

        if ($Changed) {
            $DB->query(
                "UPDATE PluginInfo "
                ."SET Cfg='".$DB->escapeString(serialize($Config))."' "
                ." WHERE PluginId=".$PluginId
            );
        }
    }

    $DB->query("UNLOCK TABLES");
}
