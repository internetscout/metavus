<?PHP
#
#   FILE:  PluginUpgrade_2_1_0.php (Rules plugin)
#
#   A plugin upgrade file for the Metavus digital collections platform
#   Copyright 2024 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins\Rules;
use Metavus\Plugins\Rules;
use Metavus\UserFactory;
use ScoutLib\PluginUpgrade;

/**
 * Class for upgrading the Rules plugin to version 2.1.0.
 */
class PluginUpgrade_2_1_0 extends PluginUpgrade
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Perform actions necessary to upgrade plugin to version 2.1.0.
     * @return null|string Return NULL if upgrade succeeeded, or string
     *      containing error message if upgrade failed.
     */
    public function performUpgrade()
    {
        $Plugin = Rules::getInstance(true);
        $Plugin->setConfigSetting(
            "DefaultUser",
            [ (new UserFactory())->getSiteOwner() ]
        );
        return null;
    }

    # ---- PRIVATE INTERFACE -------------------------------------------------
}
