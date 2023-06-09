<?PHP
#
#   FILE:  VocabularySearchCallback.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2011-2020 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#

use Metavus\MetadataField;
use Metavus\MetadataSchema;
use Metavus\QuickSearchHelper;
use ScoutLib\StdLib;

# ----- MAIN -----------------------------------------------------------------
$MinSearchLength = 3;
$NumberOfResults = 10;

# set headers to control caching
$GLOBALS["AF"]->beginAjaxResponse();
$GLOBALS["AF"]->setBrowserCacheExpirationTime(0);

$FieldId = StdLib::getFormValue("ID");

if (is_null($FieldId)) {
    print json_encode([
        "status" => "error",
        "error_message" => "ID parameter is required.",
    ]);
    return;
}

if (!MetadataSchema::fieldExistsInAnySchema($FieldId)) {
    print json_encode([
        "status" => "error",
        "error_message" => "Non-existent MetadataField provided.",
    ]);
    return;
}

$Field = new MetadataField($FieldId);
$ValidTypes = [
    MetadataSchema::MDFTYPE_CONTROLLEDNAME,
    MetadataSchema::MDFTYPE_TREE,
];

if (!in_array($Field->type(), $ValidTypes)) {
    print json_encode([
        "status" => "error",
        "error_message" => "Invalid MetadataField provided.",
    ]);
    return;
}

$NumResults = StdLib::getFormValue("N", $NumberOfResults);
$StartIndex = StdLib::getFormValue("SI", 0);
$Exclusions = StdLib::getFormValue("EX", "");
$Exclusions = strlen($Exclusions) ? explode("-", $Exclusions) : [];

$SearchString = StdLib::getFormValue("SS", "");

$Factory = $Field->getFactory();

if (strlen($SearchString) < $MinSearchLength) {
    $Items = $Factory->getItemNames(null, $NumResults, $StartIndex, $Exclusions);
    $Count = $Factory->getItemCount(null, false, $Exclusions);
} else {
    $SearchString = QuickSearchHelper::prepareSearchString(
        $SearchString
    );
    $Items = $Factory->searchForItemNames(
        $SearchString,
        $NumResults,
        false,
        true,
        $StartIndex,
        $Exclusions
    );
    $Count = $Factory->getCountForItemNames(
        $SearchString,
        false,
        true,
        $Exclusions
    );
}

# create results in the format expected by RecordEditingUI.js,
# (note that the 'tid' and 'name' array keys are explicitly referenced there,
#  e.g. in createInputRow() and update() )
$Results = [
    "terms" => [],
    "count" => $Count,
];
foreach ($Items as $ItemId => $ItemName) {
    $Results["terms"][] = ["tid" => $ItemId, "name" => $ItemName];
}

print json_encode($Results);
