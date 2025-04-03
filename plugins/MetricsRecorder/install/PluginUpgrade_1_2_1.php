<?PHP
#
#   FILE:  PluginUpgrade_1_2_1.php (MetricsRecorder plugin)
#
#   A plugin upgrade file for the Metavus digital collections platform
#   Copyright 2024 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins\MetricsRecorder;

use Metavus\MetadataSchema;
use Metavus\Plugins\MetricsRecorder;
use ScoutLib\Database;
use ScoutLib\PluginUpgrade;

/**
 * Class for upgrading the MetricsRecorder plugin to version 1.2.1.
 */
class PluginUpgrade_1_2_1 extends PluginUpgrade
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Perform actions necessary to upgrade plugin to version 1.2.1.
     * @return null|string Return NULL if upgrade succeeeded, or string
     *      containing error message if upgrade failed.
     */
    public function performUpgrade()
    {
        # set the errors that can be safely ignored
        $DB = new Database();
        $DB->setQueryErrorsToIgnore([
            '/^RENAME\s+TABLE/i' => '/already\s+exists/i'
        ]);

        # fix the custom event type ID mapping table name
        $DB->query("RENAME TABLE MetricsRecorder_EventTypes
                TO MetricsRecorder_EventTypeIds");

        # remove full record views and resource URL clicks for resources
        # that don't use the default schema
        $DB->query("DELETE ED FROM MetricsRecorder_EventData ED
                LEFT JOIN Records R ON ED.DataOne = R.RecordId
                WHERE (EventType = '".intval(MetricsRecorder::ET_FULLRECORDVIEW)."'
                OR EventType = '".intval(MetricsRecorder::ET_URLFIELDCLICK)."')
                AND R.SchemaId != '".intval(MetadataSchema::SCHEMAID_DEFAULT)."'");
        return null;
    }

    # ---- PRIVATE INTERFACE -------------------------------------------------
}
