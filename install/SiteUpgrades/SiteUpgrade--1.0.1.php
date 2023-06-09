<?PHP
#
#   FILE:  SiteUpgrade--1.0.1.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2023 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus;
use Exception;

$GLOBALS["G_ErrMsgs"] = SiteUpgrade101_PerformUpgrade();

/**
 * Perform all site upgrades for 1.0.1
 * @return null|array Returns NULL on success or a list of error messages
 *      if an error occurs.
 */
function SiteUpgrade101_PerformUpgrade()
{
    try {
        SiteUpgrade101_MigrateConfigSettings();
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
 * Migrate system configuration settings to interface configuration settings.
 */
function SiteUpgrade101_MigrateConfigSettings(): void
{
    $IntCfg = InterfaceConfiguration::getInstance("default");
    $IntCfg->migrateSystemSettingsToInterfaceSettings();
}
