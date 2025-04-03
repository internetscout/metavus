<?PHP
#
#   FILE:  PluginUpgrade_1_0_19.php (Blog plugin)
#
#   A plugin upgrade file for the Metavus digital collections platform
#   Copyright 2024 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins\Blog;

use Metavus\MetadataField;
use Metavus\MetadataSchema;
use Metavus\Plugins\Blog;
use ScoutLib\PluginUpgrade;

/**
 * Class for upgrading the Blog plugin to version 1.0.19.
 */
class PluginUpgrade_1_0_19 extends PluginUpgrade
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Perform actions necessary to upgrade plugin to version 1.0.19.
     * @return null|string Return NULL if upgrade succeeeded, or string
     *      containing error message if upgrade failed.
     */
    public function performUpgrade()
    {
        $Plugin = Blog::getInstance(true);
        $Schema = new MetadataSchema($Plugin->getSchemaId());

        # set UpdateMethod for Author and Editor fields
        $UpdateModes = [
            $Plugin::AUTHOR_FIELD_NAME =>
                MetadataField::UPDATEMETHOD_ONRECORDCREATE,
            $Plugin::EDITOR_FIELD_NAME =>
                MetadataField::UPDATEMETHOD_ONRECORDEDIT,
        ];

        foreach ($UpdateModes as $FieldName => $UpdateMode) {
            $Field = $Schema->getField($FieldName);
            $Field->updateMethod($UpdateMode);
        }

        # clear CopyOnResourceDuplcation for NotificationsSent
        $Field = $Schema->getField($Plugin::NOTIFICATIONS_FIELD_NAME);
        $Field->copyOnResourceDuplication(false);
        return null;
    }

    # ---- PRIVATE INTERFACE -------------------------------------------------
}
