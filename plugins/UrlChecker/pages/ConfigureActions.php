<?PHP
#
#   FILE:  ConfigureActions.php (UrlChecker plugin)
#
#   Part of the Metavus digital collections platform
#   Copyright 2011-2022 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#

use Metavus\ChangeSetEditingUI;
use Metavus\MetadataField;
use Metavus\MetadataSchema;
use ScoutLib\PluginManager;

# ----- MAIN -----------------------------------------------------------------

$MyPlugin = PluginManager::getInstance()->getPlugin("UrlChecker");

# configuration for URL Checker Actions
$H_Schemas = [];
foreach ($MyPlugin->ConfigSetting("FieldsToCheck") as $FieldId) {
    $Field = new MetadataField($FieldId);
    $SCId = $Field->SchemaId();

    $H_Schemas[$SCId] = new MetadataSchema($SCId);
}

$H_ActionUIs = [];
$Actions = ["Release", "Withhold", "Autofix"];
foreach ($Actions as $Action) {
    $H_ActionUIs[$Action] = [];

    $Configuration = $MyPlugin->ConfigSetting($Action."Configuration");
    foreach ($H_Schemas as $SchemaId => $Schema) {
        $H_ActionUIs[$Action][$SchemaId] = new ChangeSetEditingUI($Action."_".$SchemaId, $SchemaId);
        if (isset($Configuration[$SchemaId])) {
            $H_ActionUIs[$Action][$SchemaId]->LoadConfiguration($Configuration[$SchemaId]);
        }
        $H_ActionUIs[$Action][$SchemaId]->AddFieldButton();
    }
}
