<?PHP
#
#   FILE:  PluginUpgrade_1_0_28.php (Blog plugin)
#
#   A plugin upgrade file for the Metavus digital collections platform
#   Copyright 2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins\Blog;

use Metavus\MetadataSchema;
use Metavus\Plugins\Blog;
use ScoutLib\PluginUpgrade;

/**
 * Class for upgrading the Blog plugin to version 1.0.28.
 */
class PluginUpgrade_1_0_28 extends PluginUpgrade
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Perform actions necessary to upgrade plugin to version 1.0.28.
     * @return null|string Return NULL if upgrade succeeded, or string
     *      containing error message if upgrade failed.
     */
    public function performUpgrade(): ?string
    {
        # set VocabularyEditable to `FALSE` for all fields in blog schema with
        # Option, Tree, and ControlledName types
        $Plugin = Blog::getInstance(true);
        $Schema = new MetadataSchema($Plugin->getSchemaId());
        $Fields = $Schema->getFields(MetadataSchema::MDFTYPE_OPTION
            | MetadataSchema::MDFTYPE_TREE
            | MetadataSchema::MDFTYPE_CONTROLLEDNAME);
        foreach ($Fields as $Field) {
            $Field->vocabularyEditable(false);
        }
        return null;
    }
}
