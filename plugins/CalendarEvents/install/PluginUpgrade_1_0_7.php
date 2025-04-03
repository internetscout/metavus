<?PHP
#
#   FILE:  PluginUpgrade_1_0_7.php (CalendarEvents plugin)
#
#   A plugin upgrade file for the Metavus digital collections platform
#   Copyright 2024 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins\CalendarEvents;

use Metavus\Plugins\CalendarEvents;
use ScoutLib\Database;
use ScoutLib\PluginUpgrade;

/**
 * Class for upgrading the CalendarEvents plugin to version 1.0.7.
 */
class PluginUpgrade_1_0_7 extends PluginUpgrade
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Perform actions necessary to upgrade plugin to version 1.0.7.
     * @return null|string Return NULL if upgrade succeeeded, or string
     *      containing error message if upgrade failed.
     */
    public function performUpgrade()
    {
        $Plugin = CalendarEvents::getInstance(true);
        $DB = new Database();
        $SCID = $Plugin->getSchemaId();

        $DB->query(
            "CREATE INDEX CalendarEvents_StartDateAllDay "
            ."ON Resources (AllDay".$SCID.", StartDate".$SCID.");"
        );
        return null;
    }

    # ---- PRIVATE INTERFACE -------------------------------------------------
}
