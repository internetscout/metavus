<?PHP
#
#   FILE:  PluginUpgrade_1_0_1.php (BatchEdit plugin)
#
#   A plugin upgrade file for the Metavus digital collections platform
#   Copyright 2024 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins\BatchEdit;

use Metavus\Plugins\BatchEdit;
use Metavus\PrivilegeSet;
use ScoutLib\PluginUpgrade;

/**
 * Class for upgrading the BatchEdit plugin to version 1.0.1.
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
        $Plugin = BatchEdit::getInstance(true);
        if (is_array($Plugin->getConfigSetting("RequiredPrivs"))) {
            $RequiredPrivs = new PrivilegeSet();
            $RequiredPrivs->addPrivilege($Plugin->getConfigSetting("RequiredPrivs"));
            $Plugin->setConfigSetting("RequiredPrivs", $RequiredPrivs);
        }
        return null;
    }

    # ---- PRIVATE INTERFACE -------------------------------------------------
}
