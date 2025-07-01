<?PHP
#
#   FILE:  PluginUpgrade_2_1_13.php (UrlChecker plugin)
#
#   A plugin upgrade file for the Metavus digital collections platform
#   Copyright 2024 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins\UrlChecker;

use Metavus\MetadataSchema;
use Metavus\Plugins\UrlChecker;
use ScoutLib\PluginUpgrade;

/**
 * Class for upgrading the UrlChecker plugin to version 2.1.13.
 */
class PluginUpgrade_2_1_13 extends PluginUpgrade
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Perform actions necessary to upgrade plugin to version 2.1.13.
     * @return null|string Return NULL if upgrade succeeeded, or string
     *      containing error message if upgrade failed.
     */
    public function performUpgrade()
    {
        $Plugin = UrlChecker::getInstance();
        # If people have left the default in place,
        # change it to the new default.
        if ($Plugin->getConfigSetting("NumToCheck") == 500) {
            $Plugin->setConfigSetting("NumToCheck", 250);
        }

        # Default to checking all URL fields:
        $FieldsToCheck = [];
        $AllSchemas = MetadataSchema::getAllSchemas();
        foreach ($AllSchemas as $Schema) {
            $UrlFields = $Schema->getFields(MetadataSchema::MDFTYPE_URL);
            foreach ($UrlFields as $Field) {
                $FieldsToCheck[] = $Field->id();
            }
        }
        $Plugin->setConfigSetting("FieldsToCheck", $FieldsToCheck);
        return null;
    }

    # ---- PRIVATE INTERFACE -------------------------------------------------
}
