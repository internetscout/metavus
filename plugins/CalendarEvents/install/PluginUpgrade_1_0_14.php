<?PHP
#
#   FILE:  PluginUpgrade_1_0_14.php (CalendarEvents plugin)
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
 * Class for upgrading the CalendarEvents plugin to version 1.0.14.
 */
class PluginUpgrade_1_0_14 extends PluginUpgrade
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Perform actions necessary to upgrade plugin to version 1.0.14.
     * @return null|string Return NULL if upgrade succeeeded, or string
     *      containing error message if upgrade failed.
     */
    public function performUpgrade()
    {
        $Plugin = CalendarEvents::getInstance(true);
        # set our item class name
        $Schema = new MetadataSchema($Plugin->getConfigSetting("MetadataSchemaId"));
        $Schema->setItemClassName("Metavus\\Plugins\\CalendarEvents\\Event");
        return null;
    }

    # ---- PRIVATE INTERFACE -------------------------------------------------
}
