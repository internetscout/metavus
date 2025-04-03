<?PHP
#
#   FILE:  PluginUpgrade_1_0_7.php (OAIPMHServer plugin)
#
#   A plugin upgrade file for the Metavus digital collections platform
#   Copyright 2024 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins\OAIPMHServer;

use Metavus\Plugins\OAIPMHServer;
use ScoutLib\ApplicationFramework;
use ScoutLib\PluginUpgrade;

/**
 * Class for upgrading the OAIPMHServer plugin to version 1.0.7.
 */
class PluginUpgrade_1_0_7 extends PluginUpgrade
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Perform actions necessary to upgrade plugin to version 1.0.7.
     * @return null|string Return NULL if upgrade succeeeded, or string
     *      containing error message if upgrade failed.
     */
    public function performUpgrade()
    {
        $Plugin = OAIPMHServer::getInstance(true);
        $RepDescr = $Plugin->getConfigSetting("RepositoryDescr");
        $InvalidDefaultBaseUrl = ApplicationFramework::baseUrl()."installmv.php";
        # update the current base url value only if it's the invalid default
        if ($RepDescr["BaseURL"] == $InvalidDefaultBaseUrl) {
            $RepDescr["BaseURL"] = ApplicationFramework::baseUrl()."OAI";
            $Plugin->setConfigSetting("RepositoryDescr", $RepDescr);
        }

        return null;
    }

    # ---- PRIVATE INTERFACE -------------------------------------------------
}
