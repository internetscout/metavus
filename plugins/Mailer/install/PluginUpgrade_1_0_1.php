<?PHP
#
#   FILE:  PluginUpgrade_1_0_1.php (Mailer plugin)
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
 * Class for upgrading the Mailer plugin to version 1.0.1.
 */
class PluginUpgrade_1_0_1 extends PluginUpgrade
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Perform actions necessary to upgrade plugin to version 1.0.1.
     * @return null|string Return NULL if upgrade succeeeded, or string
     *      containing error message if upgrade failed.
     */
    public function performUpgrade()
    {
        $Plugin = Mailer::getInstance(true);

        # get the current list of templates
        $Templates = $Plugin->getConfigSetting("Templates");

        # add the "CollapseBodyMargins" setting
        foreach ($Templates as $Id => $Template) {
            $Templates[$Id]["CollapseBodyMargins"] = false;
        }

        # set the updated templates
        $Plugin->setConfigSetting("Templates", $Templates);

        return null;
    }

    # ---- PRIVATE INTERFACE -------------------------------------------------
}
