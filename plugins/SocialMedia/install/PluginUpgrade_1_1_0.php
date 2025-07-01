<?PHP
#
#   FILE:  PluginUpgrade_1_1_0.php (SocialMedia plugin)
#
#   A plugin upgrade file for the Metavus digital collections platform
#   Copyright 2024 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins\SocialMedia;

use Metavus\MetadataSchema;
use Metavus\Plugins\SocialMedia;
use ScoutLib\PluginUpgrade;

/**
 * Class for upgrading the SocialMedia plugin to version 1.1.0.
 */
class PluginUpgrade_1_1_0 extends PluginUpgrade
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Perform actions necessary to upgrade plugin to version 1.1.0.
     * @return null|string Return NULL if upgrade succeeeded, or string
     *      containing error message if upgrade failed.
     */
    public function performUpgrade()
    {
        $Plugin = SocialMedia::getInstance();
        $SchemaId = MetadataSchema::SCHEMAID_DEFAULT;

        # the default schema was always enabled in prior versions
        $Plugin->setConfigSetting("Enabled/".$SchemaId, true);

        # migrate old field settings
        $Plugin->setConfigSetting(
            "TitleField/".$SchemaId,
            $Plugin->getConfigSetting("TitleField")
        );
        $Plugin->setConfigSetting(
            "DescriptionField/".$SchemaId,
            $Plugin->getConfigSetting("DescriptionField")
        );
        $Plugin->setConfigSetting(
            "ScreenshotField/".$SchemaId,
            $Plugin->getConfigSetting("ScreenshotField")
        );
        return null;
    }

    # ---- PRIVATE INTERFACE -------------------------------------------------
}
