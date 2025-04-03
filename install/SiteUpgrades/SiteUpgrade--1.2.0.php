<?PHP
#
#   FILE:  SiteUpgrade--1.2.0.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2024 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus;

use Exception;
use ScoutLib\Database;

$GLOBALS["G_ErrMsgs"] = SiteUpgrade120_PerformUpgrade();

/**
 * Perform all site upgrades for 1.2.0
 * @return null|array Returns NULL on success or a list of error messages
 *      if an error occurs.
 */
function SiteUpgrade120_PerformUpgrade()
{
    try {
        SiteUpgrade120_CleanLegacyPluginSettings();
        SiteUpgrade120_UpdateSelectionCriteria();
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
 * Re-generate objects in saved plugin config settings to avoid
 * deprecation warnings about creating dynamic properties because of namespace
 * changes we've made.
 */
function SiteUpgrade120_CleanLegacyPluginSettings(): void
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
        $Config = @unserialize($ConfigData);
        if (!is_array($Config)) {
            continue;
        }

        foreach ($Config as $Key => &$Val) {
            if ($Val instanceof PrivilegeSet) {
                $Val = new PrivilegeSet($Val->data());
                $Changed = true;
                continue;
            }

            if ($Val instanceof SearchParameterSet) {
                $Val = new SearchParameterSet($Val->data());
                $Changed = true;
                continue;
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

/**
 * Update selection criteria for the "Let's Read!" sample collection.
 */
function SiteUpgrade120_UpdateSelectionCriteria(): void
{
    $CFactory = new CollectionFactory();

    $Collection = $CFactory->getItemByName("Let's Read!");

    if ($Collection === null) {
        return;
    }

    $Criteria = $Collection->get("Selection Criteria");

    $SearchStrings = [];
    foreach ($Criteria->getSearchStrings(true) as $FieldId => $Params) {
        $Field = MetadataField::getField($FieldId);
        $SearchStrings[$Field->name()] = $Params;
    }

    $OldValue = [
        "Resource Type" => [
            "Collection"
        ],
        "Classification" => [
            "book"
        ]
    ];

    if ($SearchStrings == $OldValue) {
        $SearchParams = new SearchParameterSet();
        $SearchParams->addParameter(
            "Collection",
            "Resource Type"
        );

        $Classifications = [
            "^Best books",
            "^Books and reading",
            "^Electronic books",
            "^Illumination of books and manuscripts, Medieval",
            "^Prohibited books"
        ];
        $Subset = new SearchParameterSet();
        $Subset->logic("OR");
        $Subset->addParameter(
            $Classifications,
            "Classification"
        );
        $SearchParams->addSet($Subset);

        $Collection->set("Selection Criteria", $SearchParams);
    }
}
