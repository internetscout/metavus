<?PHP
#
#   FILE:  PluginUpgrade_1_0_7.php (Blog plugin)
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
 * Class for upgrading the Blog plugin to version 1.0.7.
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
        $Plugin = Blog::getInstance(true);
        $Schema = new MetadataSchema($Plugin->getSchemaId());

        # try to create the notifications field if necessary
        if (!$Schema->fieldExists($Plugin::NOTIFICATIONS_FIELD_NAME)) {
            if ($Schema->addFieldsFromXmlFile(
                "plugins/".$Plugin->getBaseName()."/install/MetadataSchema--"
                .$Plugin->getBaseName().".xml"
            ) === false) {
                return "Error loading Blog metadata fields from XML: ".implode(
                    " ",
                    $Schema->errorMessages("AddFieldsFromXmlFile")
                );
            }
        }

        $UserSchema = new MetadataSchema(MetadataSchema::SCHEMAID_USER);

        # try to create the subscription field if necessary
        if (!$UserSchema->fieldExists($Plugin::SUBSCRIPTION_FIELD_NAME)) {
            if ($Schema->addFieldsFromXmlFile(
                "plugins/".$Plugin->getBaseName()."/install/MetadataSchema--"
                ."User.xml"
            ) === false) {
                return "Error loading User metadata fields from XML: ".implode(
                    " ",
                    $Schema->errorMessages("AddFieldsFromXmlFile")
                );
            }

            # disable the subscribe field until an notification e-mail template is
            # selected
            $Field = $UserSchema->getField($Plugin::SUBSCRIPTION_FIELD_NAME);
            $Field->enabled(false);
        }
        return null;
    }

    # ---- PRIVATE INTERFACE -------------------------------------------------
}
