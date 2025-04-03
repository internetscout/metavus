<?PHP
#
#   FILE:  PluginUpgrade_1_0_1.php (Blog plugin)
#
#   A plugin upgrade file for the Metavus digital collections platform
#   Copyright 2024 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins\Blog;

use Metavus\Plugins\Blog;
use ScoutLib\Database;
use ScoutLib\PluginUpgrade;

/**
 * Class for upgrading the Blog plugin to version 1.0.1.
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
        $Plugin = Blog::getInstance(true);
        $DB = new Database();
        $SchemaId = $Plugin->getSchemaId();

        # first, see if the schema attributes already exist
        $DB->query("
            SELECT * FROM MetadataSchemas
            WHERE SchemaId = '".intval($SchemaId)."'");

        # if the schema attributes don't already exist
        if (!$DB->numRowsSelected()) {
            $AuthorPriv = [PRIV_NEWSADMIN, PRIV_SYSADMIN];
            $Result = $DB->query("
                INSERT INTO MetadataSchemas
                (SchemaId, Name, AuthoringPrivileges, ViewPage) VALUES (
                '".intval($SchemaId)."',
                'Blog',
                '".$DB->escapeString(serialize($AuthorPriv))."',
                'index.php?P=P_Blog_Entry&ID=\$ID')");

            # if the upgrade failed
            if ($Result === false) {
                return "Could not add the metadata schema attributes";
            }
        }
        return null;
    }

    # ---- PRIVATE INTERFACE -------------------------------------------------
}
