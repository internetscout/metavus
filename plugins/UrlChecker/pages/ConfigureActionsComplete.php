<?PHP
#
#   FILE:  ConfigureActionsComplete.php (UrlChecker plugin)
#
#   Part of the Metavus digital collections platform
#   Copyright 2011-2024 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#

use Metavus\ChangeSetEditingUI;
use Metavus\MetadataField;
use Metavus\MetadataSchema;
use Metavus\Plugins\UrlChecker;
use ScoutLib\ApplicationFramework;

# ----- MAIN -----------------------------------------------------------------

$MyPlugin = UrlChecker::getInstance();

# configuration for URL Checker Actions
$Schemas = [];
foreach ($MyPlugin->getConfigSetting("FieldsToCheck") as $FieldId) {
    $Field = MetadataField::getField($FieldId);
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
    $MyPlugin->setConfigSetting($Action."Configuration", $Configuration);
}

ApplicationFramework::getInstance()->setJumpToPage("P_UrlChecker_ConfigureActions");
