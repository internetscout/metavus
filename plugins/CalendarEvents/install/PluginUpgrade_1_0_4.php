<?PHP
#
#   FILE:  PluginUpgrade_1_0_4.php (CalendarEvents plugin)
#
#   A plugin upgrade file for the Metavus digital collections platform
#   Copyright 2024 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins\CalendarEvents;

use Metavus\MetadataSchema;
use Metavus\Plugins\CalendarEvents;
use ScoutLib\PluginUpgrade;

/**
 * Class for upgrading the CalendarEvents plugin to version 1.0.4.
 */
class PluginUpgrade_1_0_4 extends PluginUpgrade
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Perform actions necessary to upgrade plugin to version 1.0.4.
     * @return null|string Return NULL if upgrade succeeeded, or string
     *      containing error message if upgrade failed.
     */
    public function performUpgrade()
    {
        $Plugin = CalendarEvents::getInstance(true);
        # set the resource name for the schema
        $Schema = new MetadataSchema($Plugin->getSchemaId());
        $Schema->resourceName("Calendar Event");
        return null;
    }

    # ---- PRIVATE INTERFACE -------------------------------------------------
}
