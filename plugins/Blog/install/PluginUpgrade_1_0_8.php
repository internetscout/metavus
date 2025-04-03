<?PHP
#
#   FILE:  PluginUpgrade_1_0_8.php (Blog plugin)
#
#   A plugin upgrade file for the Metavus digital collections platform
#   Copyright 2024 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins\Blog;

use Metavus\MetadataSchema;
use Metavus\Plugins\Blog;
use ScoutLib\PluginUpgrade;

/**
 * Class for upgrading the Blog plugin to version 1.0.8.
 */
class PluginUpgrade_1_0_8 extends PluginUpgrade
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Perform actions necessary to upgrade plugin to version 1.0.8.
     * @return null|string Return NULL if upgrade succeeeded, or string
     *      containing error message if upgrade failed.
     */
    public function performUpgrade()
    {
        $Plugin = Blog::getInstance(true);
        $UserSchema = new MetadataSchema(MetadataSchema::SCHEMAID_USER);
        $SubscribeField = $UserSchema->getField($Plugin::SUBSCRIPTION_FIELD_NAME);
        $BlogName = $Plugin->getConfigSetting("BlogName");

        # if a non-blank blog name is available
        if (strlen(trim($BlogName))) {
            # change the subscribe field's label to reflect the blog name
            $SubscribeField->label("Subscribe to ".$BlogName);
        # otherwise clear the label
        } else {
            $SubscribeField->label(null);
        }
        return null;
    }

    # ---- PRIVATE INTERFACE -------------------------------------------------
}
