<?PHP
#
#   FILE:  EditNavigation.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2011-2020 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#

use ScoutLib\StdLib;
use Metavus\FormUI;

# ----- LOCAL FUNCTIONS ------------------------------------------------------

/**
 * Set a NavEditor configuration value.
 * @param string $Key configuration key
 * @param mixed $Value configuration value
 */
function setConfigValue(string $Key, $Value)
{
    $GLOBALS["AF"]->signalEvent("NAVEDITOR_SET_CONFIGURATION", [$Key, $Value]);
}

# ----- MAIN -----------------------------------------------------------------

PageTitle("Edit Navigation");
CheckAuthorization(PRIV_SYSADMIN);

$Configuration = $GLOBALS["AF"]->signalEvent("NAVEDITOR_GET_CONFIGURATION");

# form fields definition
$FormFields = [
    "Enable" => [
        "Type" => FormUI::FTYPE_FLAG,
        "Label" => "Enable",
        "Help" => "(customized navigation is only applied when enabled)",
        "Value" => $Configuration["ModifyPrimaryNav"],
    ],
    "PrimaryNav" => [
        "Type" => FormUI::FTYPE_PARAGRAPH,
        "Label" => "Primary Navigation",
        "Help" => "Format: Link Text=Page/URL=Display Only If Logged In=Required Privileges. "
            ."URLs that include an = must be wrapped in \" characters.",
        "Value" => $Configuration["PrimaryNav"],
    ]
];

# instantiate FormUI using form fields
$H_FormUI = new FormUI($FormFields);

$ButtonPushed = StdLib::getFormValue("Submit");
switch ($ButtonPushed) {
    case "Save":
        # check values and bail out if any are invalid
        if ($H_FormUI->ValidateFieldInput()) {
            return;
        }

        $NewValues = $H_FormUI->GetNewValuesFromForm();

        setConfigValue("ModifyPrimaryNav", $NewValues["Enable"]);
        setConfigValue("PrimaryNav", $NewValues["PrimaryNav"]);
        # jump to SysAdmin after saving
    case "Cancel":
        $GLOBALS["AF"]->SetJumpToPage("SysAdmin");
        break;
}
