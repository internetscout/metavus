<?PHP
#
#   FILE:  ConfigureActionsComplete.php (UrlChecker plugin)
#
#   Part of the Metavus digital collections platform
#   Copyright 2011-2022 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#

use Metavus\ChangeSetEditingUI;
use Metavus\MetadataField;
use Metavus\MetadataSchema;
use ScoutLib\ApplicationFramework;
use ScoutLib\PluginManager;

# ----- MAIN -----------------------------------------------------------------

$MyPlugin = PluginManager::getInstance()->getPlugin("UrlChecker");

# configuration for URL Checker Actions
$Schemas = [];
foreach ($MyPlugin->ConfigSetting("FieldsToCheck") as $FieldId) {
    $Field = new MetadataField($FieldId);
    $SCId = $Field->SchemaId();

    $Schemas[$SCId] = new MetadataSchema($SCId);
}

$Actions = ["Release", "Withhold", "Autofix"];
foreach ($Actions as $Action) {
    $Configuration = [];
    foreach ($Schemas as $SchemaId => $Schema) {
        $FEUI = new ChangeSetEditingUI($Action."_".$SchemaId, $SchemaId);
        $Configuration[$SchemaId] = $FEUI->getValuesFromFormData();
    }
    $MyPlugin->ConfigSetting($Action."Configuration", $Configuration);
}

ApplicationFramework::getInstance()->setJumpToPage("P_UrlChecker_ConfigureActions");
