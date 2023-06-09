<?PHP
#
#   FILE:  SiteUpgrade--4.0.1.php
#
#   Part of the Collection Workflow Integration System (CWIS)
#   Copyright 2019-2021 Edward Almasy and Internet Scout
#   http://scout.wisc.edu/cwis
#
# @scout:phpstan

use Metavus\MetadataSchema;
use Metavus\SystemConfiguration;
use ScoutLib\Database;

$GLOBALS["G_ErrMsgs"] = SiteUpgrade401_PerformUpgrade();

/**
* Perform all of the site upgrades for 4.0.1
* @return null|array Returns NULL on success and an array of error messages otherwise.
*/
function SiteUpgrade401_PerformUpgrade()
{
    try {
        $GLOBALS["G_MsgFunc"](1, "Migrating Saved Search settings into Saved Search Mailings plugin...");
        SiteUpgrade401_SavedSearchMailings();
    } catch (Exception $Exception) {
        return array($Exception->getMessage(),
                "Exception Trace:<br/><pre>"
                        .$Exception->getTraceAsString()."</pre>");
    }
    return null;
}

/**
* Migrate settings for saved search mailings into plugin.
*/
function SiteUpgrade401_SavedSearchMailings(): void
{
    $DB = new Database();

    # check if sysconfig table has the saved searches setting
    # if it does not, then we've been run already and should exit.
    if (!$DB->fieldExists("SystemConfiguration", "UserAgentsEnabled")) {
        return;
    }

    $SysConfig = SystemConfiguration::getInstance();
    $SavedSearchEnabled = $SysConfig->getBool("UserAgentsEnabled");

    if ($SavedSearchEnabled) {
        # enable the ss mailing plugin
        $GLOBALS["G_PluginManager"]->pluginEnabled("SavedSearchMailings", true);

        $SSPlugin = $GLOBALS["G_PluginManager"]->getPlugin("SavedSearchMailings", true);

        # get the currently configured template
        $SSTemplate = $DB->queryValue("SELECT SavedSeachMailTemplate"
                ." FROM SystemConfiguration", "SavedSearchMailTemplate");

        # if it wasn't "Default"
        if ($SSTemplate != -1) {
            # configure the plugin to use it
            $SSPlugin->configSetting(
                "EmailTemplate_".MetadataSchema::SCHEMAID_DEFAULT,
                $SSTemplate
            );
        }
    }

    # drop the ss configuration columns from the database
    $DB->query(
        "ALTER TABLE SystemConfiguration DROP COLUMN UserAgentsEnabled"
    );
    $DB->query(
        "ALTER TABLE SystemConfiguration DROP COLUMN SavedSearchMailTemplate"
    );
}
