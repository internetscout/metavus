<?PHP
#
#   FILE:  SiteUpgrade--3.2.0.php
#
#   Part of the Collection Workflow Integration System (CWIS)
#   Copyright 2019-2022 Edward Almasy and Internet Scout
#   http://scout.wisc.edu/cwis
#
# @scout:phpstan

namespace Metavus;

use Exception;
use ScoutLib\Database;

$GLOBALS["G_ErrMsgs"] = SiteUpgrade320_PerformUpgrade();

/**
* Perform all of the site upgrades for 3.2.0.
* @return null|array Returns NULL on success and an eror message list if an error occurs.
*/
function SiteUpgrade320_PerformUpgrade()
{
    try {
        $GLOBALS["G_MsgFunc"](1, "Migrating Google Analytics settings to plugin...");
        SiteUpgrade320_TransferGoogleAnalyticsSetting();

        $GLOBALS["G_MsgFunc"](1, "Fixing HTML entity encoding in the database...");
        SiteUpgrade320_FixEntityEncoding();

        $GLOBALS["G_MsgFunc"](1, "Populating LastMatchingIds column of the database...");
        SiteUpgrade320_PopulateSavedSearchIds();
    } catch (Exception $Exception) {
        return array($Exception->getMessage(),
            "Exception Trace:<br/><pre>"
                        .$Exception->getTraceAsString()."</pre>"
        );
    }
    return null;
}

/**
* Remove entity encoding from the database where it was helpfully added by CKEditor
*/
function SiteUpgrade320_FixEntityEncoding(): void
{
    $DB = new Database();
    if (!$DB->tableExists("Resources")) {
        return;
    }

    # iterate over all the schemas
    foreach (MetadataSchema::getAllSchemas() as $Schema) {
        # and iterate over the fields that might contain entities
        foreach ($Schema->getFields(MetadataSchema::MDFTYPE_TEXT |
                                    MetadataSchema::MDFTYPE_PARAGRAPH) as $Field) {
            # pull out all the values for this field
            $DBName = $Field->dBFieldName();
            $DB->query("SELECT ResourceId, ".$DBName." AS Val "
                       ."FROM Resources WHERE ".$DBName." IS NOT NULL");
            $Values = $DB->fetchColumn("Val", "ResourceId");

            # iterate over all of those
            foreach ($Values as $Id => $Val) {
                # if stripping entities out of a value changes it,
                # update the database with the new, stripped value
                $ValStripped = html_entity_decode(
                    $Val,
                    ENT_COMPAT,
                    InterfaceConfiguration::getInstance()->getString("DefaultCharacterSet")
                );
                if ($Val != $ValStripped) {
                    $DB->query(
                        "UPDATE Resources SET ".$DBName."='".addslashes($ValStripped)."'"
                        ." WHERE ResourceId=".$Id
                    );
                }
            }
        }
    }
}

/**
* Populate the LastUpdatedIds column for saved searches.
*/
function SiteUpgrade320_PopulateSavedSearchIds(): void
{
    $SSFactory = new SavedSearchFactory();
    $SearchEngine = new SearchEngine();
    $RFactory = new RecordFactory();
    $DB = new Database();

    $SearchIds = $SSFactory->getItemIds();
    # for each search
    foreach ($SearchIds as $SearchId) {
        # attempt to load saved search
        try {
            $Search = new SavedSearch($SearchId);
        } catch (Exception $e) {
            # if search data was invalid, just delete this search
            $DB->query(
                "DELETE FROM SavedSearches WHERE SearchId = ".$SearchId
            );
            continue;
        }

        # if LastMatches for this search is already populated,
        # continue along to the next search
        if (count($Search->lastMatches()) > 0) {
            continue;
        }

        # retrieve search criteria and target user
        $EndUser = new User(intval($Search->userId()));

        # attempt to perform search
        try {
            $SearchResults = $SearchEngine->searchAll(
                $Search->searchParameters()
            );
        } catch (Exception $e) {
            # if the search failed for any reason (e.g., it references
            # a deleted field) continue on to the next search
            continue;
        }

        # build the list of results the user can see
        $NewItemIds = [];
        foreach ($SearchResults as $SchemaId => $SchemaResults) {
            $RFactory = new RecordFactory($SchemaId);
            $SchemaItemIds = $RFactory->filterOutUnviewableRecords(
                array_keys($SchemaResults),
                $EndUser
            );
            $NewItemIds = array_merge(
                $NewItemIds,
                $SchemaItemIds
            );
        }

        # if visible search results were found, save them
        if (count($NewItemIds)) {
            $Search->saveLastMatches($NewItemIds);
        }
    }
}

/**
* Transfer Google Analytics settings to plugin and DROP necessary
* column from SystemConfiguration
*/
function SiteUpgrade320_TransferGoogleAnalyticsSetting(): void
{
    # check if transfer has already been done
    $DB = new Database();
    $TransferComplete = $DB->fieldExists("SystemConfiguration", "AddGoogleAnalytics") ?
                      false : true;
    if ($TransferComplete) {
        return;
    }

    # migrate to plugin if plugin is not enabled
    $Enabled = $GLOBALS["G_PluginManager"]->pluginEnabled("GoogleAnalytics");

    if (!$Enabled) {
        $GoogleAnalyticsPlugin =
                $GLOBALS["G_PluginManager"]->getPlugin("GoogleAnalytics", true);

        $DB->query("SELECT * FROM SystemConfiguration");
        $UseGoogleAnalytics = $DB->fetchField("AddGoogleAnalytics");
        $DB->query("SELECT * FROM SystemConfiguration");
        $GoogleAnalyticsCode = $DB->fetchField("GoogleAnalyticsCode");

        # validate Analytics Code
        $Pattern = "/UA-[0-9]{6}-[0-9]+/";
        $GoogleAnalyticsCodeValid = preg_match($Pattern, $GoogleAnalyticsCode, $Match);


        # if user used Google Analytics before
        if ($UseGoogleAnalytics && $GoogleAnalyticsCodeValid) {
            $GoogleAnalyticsCode = $Match[0];
            $GoogleAnalyticsPlugin->configSetting("TrackingId", $GoogleAnalyticsCode);

            # enable Google Analytics
            $GLOBALS["G_PluginManager"]->pluginEnabled("GoogleAnalytics", true);
        }
    }


    # drop the unnecessary field from SystemConfiguration
    $DB->query("ALTER TABLE SystemConfiguration DROP COLUMN `AddGoogleAnalytics`");
    $DB->query("ALTER TABLE SystemConfiguration DROP COLUMN `GoogleAnalyticsCode`");
}
