<?PHP
#
#   FILE:  PluginUpgrade_1_0_6.php (Blog plugin)
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
 * Class for upgrading the Blog plugin to version 1.0.6.
 */
class PluginUpgrade_1_0_6 extends PluginUpgrade
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Perform actions necessary to upgrade plugin to version 1.0.6.
     * @return null|string Return NULL if upgrade succeeeded, or string
     *      containing error message if upgrade failed.
     */
    public function performUpgrade()
    {
        $Plugin = Blog::getInstance(true);
        $Schema = new MetadataSchema($Plugin->getSchemaId());

        # get the publication date field
        $PublicationDate = $Schema->getField($Plugin::PUBLICATION_DATE_FIELD_NAME);

        # setup the privileges for viewing
        $ViewingPrivs = new PrivilegeSet();
        $ViewingPrivs->addPrivilege(PRIV_NEWSADMIN);
        $ViewingPrivs->addPrivilege(PRIV_SYSADMIN);
        $ViewingPrivs->addCondition($PublicationDate, "now", "<");

        # set the viewing privileges
        $Schema->viewingPrivileges($ViewingPrivs);
        return null;
    }

    # ---- PRIVATE INTERFACE -------------------------------------------------
}
