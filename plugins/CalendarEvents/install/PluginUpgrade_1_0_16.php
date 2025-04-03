<?PHP
#
#   FILE:  PluginUpgrade_1_0_16.php (CalendarEvents plugin)
#
#   A plugin upgrade file for the Metavus digital collections platform
#   Copyright 2024 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins\CalendarEvents;

use Metavus\MetadataSchema;
use Metavus\Plugins\CalendarEvents;
use ScoutLib\Database;
use ScoutLib\PluginUpgrade;

/**
 * Class for upgrading the CalendarEvents plugin to version 1.0.16.
 */
class PluginUpgrade_1_0_16 extends PluginUpgrade
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Perform actions necessary to upgrade plugin to version 1.0.16.
     * @return null|string Return NULL if upgrade succeeeded, or string
     *      containing error message if upgrade failed.
     */
    public function performUpgrade()
    {
        $Plugin = CalendarEvents::getInstance(true);
        $Schema = new MetadataSchema($Plugin->getSchemaId());
        $EndDateDBName = $Schema->getField("End Date")->dBFieldName();
        $AllDayDBName = $Schema->getField("All Day")->dBFieldName();
        $DB = new Database();
        $DB->query(
            "UPDATE Records SET ".$EndDateDBName." = ".
            "CONCAT(DATE(".$EndDateDBName."), ' 23:59:59') ".
            "WHERE ".$AllDayDBName." = 1"
        );
        return null;
    }

    # ---- PRIVATE INTERFACE -------------------------------------------------
}
