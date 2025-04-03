<?PHP
#
#   FILE:  PluginUpgrade_1_0_9.php (CalendarEvents plugin)
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
 * Class for upgrading the CalendarEvents plugin to version 1.0.9.
 */
class PluginUpgrade_1_0_9 extends PluginUpgrade
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Perform actions necessary to upgrade plugin to version 1.0.9.
     * @return null|string Return NULL if upgrade succeeeded, or string
     *      containing error message if upgrade failed.
     */
    public function performUpgrade()
    {
        $Plugin = CalendarEvents::getInstance(true);
        $Schema = new MetadataSchema($Plugin->getSchemaId());

        # try to create the Owner field if necessary
        if (!$Schema->fieldExists("Owner")) {
            if ($Schema->addFieldsFromXmlFile("plugins/".$Plugin->getBaseName()
                ."/install/MetadataSchema--".$Plugin->getBaseName().".xml") === false) {
                return "Error loading ".$Plugin->getBaseName()." metadata fields from XML: "
                .implode(" ", $Schema->errorMessages("AddFieldsFromXmlFile"));
            }
        }
        return null;
    }

    # ---- PRIVATE INTERFACE -------------------------------------------------
}
