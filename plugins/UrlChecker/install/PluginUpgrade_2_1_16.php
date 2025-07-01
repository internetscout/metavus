<?PHP
#
#   FILE:  PluginUpgrade_2_1_16.php (UrlChecker plugin)
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
 * Class for upgrading the UrlChecker plugin to version 2.1.16.
 */
class PluginUpgrade_2_1_16 extends PluginUpgrade
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Perform actions necessary to upgrade plugin to version 2.1.16.
     * @return null|string Return NULL if upgrade succeeeded, or string
     *      containing error message if upgrade failed.
     */
    public function performUpgrade()
    {
        $Plugin = UrlChecker::getInstance();
        # translate Release and Withhold configurations to the new format that
        # supports multiple schemas
        $Actions = ["Withhold", "Release"];
        foreach ($Actions as $Action) {
            $NewSetting = [];
            $Setting = $Plugin->getConfigSetting($Action."Configuration");
            if ($Setting !== null) {
                $NewSetting = [MetadataSchema::SCHEMAID_DEFAULT => $Setting];
            }
            $Plugin->setConfigSetting($Action."Configuration", $NewSetting);
        }
        # default to doing nothing when withholding resources
        $Plugin->setConfigSetting("WithholdConfiguration", []);
        return null;
    }

    # ---- PRIVATE INTERFACE -------------------------------------------------
}
