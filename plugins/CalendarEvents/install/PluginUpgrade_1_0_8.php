<?PHP
#
#   FILE:  PluginUpgrade_1_0_8.php (CalendarEvents plugin)
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
 * Class for upgrading the CalendarEvents plugin to version 1.0.8.
 */
class PluginUpgrade_1_0_8 extends PluginUpgrade
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Perform actions necessary to upgrade plugin to version 1.0.8.
     * @return null|string Return NULL if upgrade succeeeded, or string
     *      containing error message if upgrade failed.
     */
    public function performUpgrade()
    {
        $Plugin = CalendarEvents::getInstance(true);
        # change schema name
        $Schema = new MetadataSchema($Plugin->getSchemaId());
        $Schema->name("Events");
        $Schema->resourceName("Event");
        return null;
    }

    # ---- PRIVATE INTERFACE -------------------------------------------------
}
