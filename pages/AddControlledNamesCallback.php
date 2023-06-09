<?PHP
#
#   FILE:  AddControlledNamesCallback.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2011-2020 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#

use Metavus\ControlledName;
use Metavus\MetadataSchema;
use ScoutLib\StdLib;

# ----- MAIN -----------------------------------------------------------------

$GLOBALS["AF"]->beginAjaxResponse();

# check that the user is authorized to view the page
if (!CheckAuthorization(PRIV_NAMEADMIN)) {
    $Result = [
        "status" => "Error",
        "message" => "You are not authorized to create controlled names.",
    ];
    print json_encode($Result);
    return;
}

if (!isset($_POST["ID"]) || !isset($_POST["Terms"])) {
    $Result = [
        "status" => "Error",
        "message" => "Required parameters not provided.",
    ];
    print json_encode($Result);
    return;
}

$FieldId = intval($_POST["ID"]);
if (!MetadataSchema::fieldExistsInAnySchema($FieldId)) {
    $Result = [
        "status" => "Error",
        "message" => "Invalid FieldId provided.",
    ];
    print json_encode($Result);
    return;
}

$TermsCreated = [];
foreach ($_POST["Terms"] as $Term) {
    $TermID = $Term["tid"];
    $TermName = trim($Term["name"]);

    if (strlen($TermName) == 0) {
        $TermsCreated[$TermID] = null;
        continue;
    }

    # (note ControlledName::create() will return an existing term when one is found)
    $CN = ControlledName::create($TermName, $FieldId);

    $TermsCreated[$TermID] = $CN->id();
}

$Result = [
    "status" => "OK",
    "termsCreated" => $TermsCreated,
];
print json_encode($Result);
