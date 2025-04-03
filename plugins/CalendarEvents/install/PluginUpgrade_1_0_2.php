<?PHP
#
#   FILE:  PluginUpgrade_1_0_2.php (CalendarEvents plugin)
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
 * Class for upgrading the CalendarEvents plugin to version 1.0.2.
 */
class PluginUpgrade_1_0_2 extends PluginUpgrade
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Perform actions necessary to upgrade plugin to version 1.0.2.
     * @return null|string Return NULL if upgrade succeeeded, or string
     *      containing error message if upgrade failed.
     */
    public function performUpgrade()
    {
        $Plugin = CalendarEvents::getInstance(true);
        $Schema = new MetadataSchema($Plugin->getSchemaId());

        # convert the categories field into an option
        $CategoriesField = $Schema->getField("Categories");
        $CategoriesField->type(MetadataSchema::MDFTYPE_OPTION);
        $CategoriesField->allowMultiple(true);
        return null;
    }

    # ---- PRIVATE INTERFACE -------------------------------------------------
}
