<?PHP
#
#   FILE:  PluginUpgrade_1_0_2.php (Blog plugin)
#
#   A plugin upgrade file for the Metavus digital collections platform
#   Copyright 2024 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins\Blog;

use Metavus\MetadataSchema;
use Metavus\Plugins\Blog;
use Metavus\PrivilegeSet;
use ScoutLib\PluginUpgrade;

/**
 * Class for upgrading the Blog plugin to version 1.0.2.
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
        $Plugin = Blog::getInstance(true);
        # be absolutely sure the privileges are correct
        $AuthoringPrivs = new PrivilegeSet();
        $AuthoringPrivs->addPrivilege(PRIV_NEWSADMIN);
        $AuthoringPrivs->addPrivilege(PRIV_SYSADMIN);
        $MetricsPrivs = [PRIV_NEWSADMIN, PRIV_SYSADMIN];
        $Schema = new MetadataSchema($Plugin->getSchemaId());
        $Schema->authoringPrivileges($AuthoringPrivs);
        $Plugin->setConfigSetting("BlogManagerPrivs", $MetricsPrivs);
        return null;
    }

    # ---- PRIVATE INTERFACE -------------------------------------------------
}
