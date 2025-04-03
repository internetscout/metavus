<?PHP
#
#   FILE:  PluginUpgrade_1_0_18.php (Blog plugin)
#
#   A plugin upgrade file for the Metavus digital collections platform
#   Copyright 2024 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins\Blog;

use Metavus\MetadataSchema;
use Metavus\Plugins\Blog;
use Metavus\RecordFactory;
use Metavus\SearchEngine;
use ScoutLib\PluginUpgrade;

/**
 * Class for upgrading the Blog plugin to version 1.0.18.
 */
class PluginUpgrade_1_0_18 extends PluginUpgrade
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Perform actions necessary to upgrade plugin to version 1.0.18.
     * @return null|string Return NULL if upgrade succeeeded, or string
     *      containing error message if upgrade failed.
     */
    public function performUpgrade()
    {
        $Plugin = Blog::getInstance(true);
        # add Summary metadata field
        $Schema = new MetadataSchema($Plugin->getSchemaId());
        if ($Schema->addFieldsFromXmlFile(
            "plugins/".$Plugin->getBaseName()."/install/MetadataSchema--"
            .$Plugin->getBaseName().".xml",
            "Blog"
        ) == false) {
            return "Error Loading Metadata Fields from XML: ".implode(
                " ",
                $Schema->errorMessages("AddFieldsFromXmlFile")
            );
        }

        # populate Summary field for all blog entries
        $BlogEntries = $Plugin->getBlogEntries();
        foreach ($BlogEntries as $BlogEntry) {
            $BlogEntry->set("Summary", $BlogEntry->Teaser(400));
        }

        # queue an update for each entry to reflect changes in search results
        $SearchEngine = new SearchEngine();

        $RFactory = new RecordFactory($Plugin->getSchemaId());
        $Ids = $RFactory->getItemIds();
        foreach ($Ids as $Id) {
            $SearchEngine->queueUpdateForItem($Id);
        }
        return null;
    }

    # ---- PRIVATE INTERFACE -------------------------------------------------
}
