<?PHP
#
#   FILE:  PluginUpgrade_1_2_6.php (MetricsRecorder plugin)
#
#   A plugin upgrade file for the Metavus digital collections platform
#   Copyright 2024 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins\MetricsRecorder;

use Exception;
use Metavus\Plugins\MetricsRecorder;
use Metavus\SearchParameterSet;
use ScoutLib\Database;
use ScoutLib\PluginUpgrade;
use ScoutLib\StdLib;

/**
 * Class for upgrading the MetricsRecorder plugin to version 1.2.6.
 */
class PluginUpgrade_1_2_6 extends PluginUpgrade
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Perform actions necessary to upgrade plugin to version 1.2.6.
     * @return null|string Return NULL if upgrade succeeeded, or string
     *      containing error message if upgrade failed.
     */
    public function performUpgrade()
    {
        # this may take a while, avoid timing out
        set_time_limit(3600);

        # find all the places where we might have stored legacy format search URLs
        # load them into SearchParameterSets, then stuff in the Data() from them
        $DB = new Database();
        $DB->caching(false);

        # this is less than ideal, but the LIKE clauses below are meant to
        #  prevent already-updated rows that don't contain SearchParameter data
        # from being re-updated if two requests try to run the
        # migration one after another

        # Note, it's necessary to specify an explicit
        # EventDate to avoid mysql's "helpful" behavior of
        # auto-updating the first TIMESTAMP column in a table

        # get the event IDs that use the old format (not pulling the data to avoid
        #   potentially running out of memory)
        $DB->query("SELECT EventId FROM MetricsRecorder_EventData WHERE "
                ."EventType IN (".MetricsRecorder::ET_SEARCH.","
                                 .MetricsRecorder::ET_ADVANCEDSEARCH.") "
                ."AND DataOne IS NOT NULL "
                ."AND LENGTH(DataOne)>0 "
                ."AND DataOne NOT LIKE 'a:%'");
        $EventIds = $DB->fetchColumn("EventId");

        foreach ($EventIds as $EventId) {
            $DB->query("SELECT DataOne, EventDate FROM "
                        ."MetricsRecorder_EventData WHERE "
                        ."EventId=".$EventId);
            $Row = $DB->fetchRow();
            if ($Row === false) {
                throw new Exception("Unable to retrieve data for event ID "
                        .$EventId.".");
            }

            # if this event has already been converted, don't try to re-convert it
            if (StdLib::isSerializedData($Row["DataOne"])) {
                continue;
            }

            # attempt to convert to the new format, saving if we succeed
            try {
                $SearchParams = new SearchParameterSet();
                $SearchParams->setFromLegacyUrl($Row["DataOne"]);

                $DB->query("UPDATE MetricsRecorder_EventData "
                            ."SET DataOne='".addslashes($SearchParams->data())."', "
                            ."EventDate='".$Row["EventDate"]."' "
                            ."WHERE EventId=".$EventId);
            } catch (Exception $e) {
                ; # continue in the case of invalid metadata fields
            }
        }

        # pull out Full Record views that have search data
        $DB->query("SELECT EventId FROM MetricsRecorder_EventData WHERE "
                ."EventType=".MetricsRecorder::ET_FULLRECORDVIEW." "
                ."AND DataTwo IS NOT NULL "
                ."AND LENGTH(DataTwo)>0 "
                ."AND DataTwo NOT LIKE 'a:%'");
        $EventIds = $DB->fetchColumn("EventId");

        # iterate over them, converting each to a
        # SearchParameterSet and updating the DB
        foreach ($EventIds as $EventId) {
            $DB->query("SELECT DataTwo, EventDate FROM "
                        ."MetricsRecorder_EventData WHERE "
                        ."EventId=".$EventId);
            $Row = $DB->fetchRow();
            if ($Row === false) {
                throw new Exception("Unable to retrieve data for event ID "
                        .$EventId.".");
            }

            # if this event has already been converted, don't try to re-convert it
            if (StdLib::isSerializedData($Row["DataTwo"])) {
                continue;
            }

            try {
                $SearchParams = new SearchParameterSet();
                $SearchParams->setFromLegacyUrl($Row["DataTwo"]);

                $DB->query("UPDATE MetricsRecorder_EventData "
                            ."SET DataTwo='".addslashes($SearchParams->data())."', "
                            ."EventDate='".$Row["EventDate"]."' "
                            ."WHERE EventId=".$EventId);
            } catch (Exception $e) {
                ; # continue in the case of invalid metadata fields
            }
        }

        return null;
    }

    # ---- PRIVATE INTERFACE -------------------------------------------------
}
