<?PHP
#
#   FILE:  PluginUpgrade_1_2_3.php (Developer plugin)
#
#   A plugin upgrade file for the Metavus digital collections platform
#   Copyright 2024-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins\Developer;
use Metavus\Plugins\Developer;
use ScoutLib\PluginUpgrade;

/**
 * Class for upgrading the Developer plugin to version 1.2.3.
 */
class PluginUpgrade_1_2_3 extends PluginUpgrade
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Perform actions necessary to upgrade plugin to version 1.2.3.
     * @return null|string Return NULL if upgrade succeeeded, or string
     *      containing error message if upgrade failed.
     */
    public function performUpgrade()
    {
        $Plugin = Developer::getInstance(true);

        # clear out old upgrade file checksum data
        $Plugin->setConfigSetting("DBUpgradeFileChecksums", null);
        $Plugin->setConfigSetting("SiteUpgradeFileChecksums", null);

        return null;
    }

    # ---- PRIVATE INTERFACE -------------------------------------------------
}
