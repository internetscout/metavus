<?PHP
#
#   FILE:  PluginUpgrade_1_3_0.php (Mailer plugin)
#
#   A plugin upgrade file for the Metavus digital collections platform
#   Copyright 2024 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins\Mailer;

use Metavus\Plugins\Mailer;
use ScoutLib\PluginUpgrade;

/**
 * Class for upgrading the Mailer plugin to version 1.3.0.
 */
class PluginUpgrade_1_3_0 extends PluginUpgrade
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Perform actions necessary to upgrade plugin to version 1.3.0.
     * @return null|string Return NULL if upgrade succeeeded, or string
     *      containing error message if upgrade failed.
     */
    public function performUpgrade()
    {
        $Plugin = Mailer::getInstance(true);

        $Templates = $Plugin->getConfigSetting("Templates");
        foreach ($Templates as &$Template) {
            $Template["ConfirmMode"] = false;
            $Template["EmailPerResource"] = false;
        }
        $Plugin->setConfigSetting("Templates", $Templates);

        $Result = $this->createMissingTables();
        if ($Result !== null) {
            return $Result;
        }

        return null;
    }

    # ---- PRIVATE INTERFACE -------------------------------------------------
}
