<?PHP
#
#   FILE:  PluginUpgrade_1_0_3.php (Blog plugin)
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
 * Class for upgrading the Blog plugin to version 1.0.3.
 */
class PluginUpgrade_1_0_3 extends PluginUpgrade
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Perform actions necessary to upgrade plugin to version 1.0.3.
     * @return null|string Return NULL if upgrade succeeeded, or string
     *      containing error message if upgrade failed.
     */
    public function performUpgrade()
    {
        $Plugin = Blog::getInstance(true);
        $Schema = new MetadataSchema($Plugin->getSchemaId());

        # setup the default privileges for authoring and editing
        $DefaultPrivs = new PrivilegeSet();
        $DefaultPrivs->addPrivilege(PRIV_NEWSADMIN);
        $DefaultPrivs->addPrivilege(PRIV_SYSADMIN);

        # the authoring and viewing privileges are the defaults
        $Schema->authoringPrivileges($DefaultPrivs);
        $Schema->viewingPrivileges($DefaultPrivs);

        # the editing privileges are a bit different
        $EditingPrivs = $DefaultPrivs;
        $EditingPrivs->addCondition($Schema->getField($Plugin::AUTHOR_FIELD_NAME));
        $Schema->editingPrivileges($EditingPrivs);

        # set defaults for the view metrics privileges
        $Plugin->setConfigSetting(
            "BlogManagerPrivs",
            [PRIV_NEWSADMIN, PRIV_SYSADMIN]
        );
        return null;
    }

    # ---- PRIVATE INTERFACE -------------------------------------------------
}
