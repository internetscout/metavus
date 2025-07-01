<?PHP
#
#   FILE:  PluginUpgrade_1_1_0.php (ResourceExporter plugin)
#
#   A plugin upgrade file for the Metavus digital collections platform
#   Copyright 2024 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins\ResourceExporter;

use Metavus\Plugins\ResourceExporter;
use ScoutLib\PluginUpgrade;
use ScoutLib\StdLib;

/**
 * Class for upgrading the ResourceExporter plugin to version 1.1.0.
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
        $Plugin = ResourceExporter::getInstance();
        $IdLists = $Plugin->getConfigSetting("SelectedFieldIdLists");

        $Formats = $Plugin->getConfigSetting("SelectedFormats");
        $DefaultFormat = current($Plugin->getFormats());
        $FormatParameters = $Plugin->getConfigSetting("FormatParameterValues");

        if (is_array($IdLists)) {
            $ExportConfigs = [];
            foreach ($IdLists as $UserId => $FieldIds) {
                $ExportConfigs[$UserId]["Default"] = [
                    "FieldIds" => $FieldIds,
                    "Format" => StdLib::getArrayValue($Formats, $UserId, $DefaultFormat),
                    "FormatParams" => $FormatParameters,
                ];
            }
            $Plugin->setConfigSetting("ExportConfigs", $ExportConfigs);

            foreach (
                ["SelectedFieldIdLists", "SelectedFormats",
                    "FormatParameterValues"
                ] as $Var
            ) {
                $Plugin->setConfigSetting($Var, null);
            }
        }

        return null;
    }

    # ---- PRIVATE INTERFACE -------------------------------------------------
}
