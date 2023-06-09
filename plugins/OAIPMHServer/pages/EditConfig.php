<?PHP
#
#   FILE:  EditConfig.php (OAI-PMH Server plugin)
#
#   Part of the Metavus digital collections platform
#   Copyright 2023 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#

use ScoutLib\PluginManager;

if (!CheckAuthorization(PRIV_COLLECTIONADMIN, PRIV_SYSADMIN)) {
    return;
}

$Plugin = PluginManager::getInstance()
    ->getPlugin("OAIPMHServer");

# check for format edit button click
$Formats = $Plugin->configSetting("Formats");
$Index = 0;
$FormatToEdit = "";
foreach ($Formats as $FormatName => $Format) {
    if (isset($_POST["FormatEdit".$Index])) {
        $FormatToEdit = $_POST["H_FormatName".$Index];
        $_POST["Submit"] = "Edit";
        break;
    }
    $Index++;
}

# if user clicked a button
if (isset($_POST["Submit"])) {
    # if user did not click "Cancel"
    if ($_POST["Submit"] != "Cancel") {
        # check for required values
        $FormVars = [
            "Name" => "Repository Name",
            "BaseURL" => "Base URL",
            "AdminEmail" => "Administrator Email",
            "IDDomain" => "ID Domain",
            "IDPrefix" => "ID Prefix",
            "EarliestDate" => "Earliest Date",
            "DateGranularity" => "Date Granularity"
        ];
        foreach ($FormVars as $FieldName => $PrintableName) {
            if (!strlen(trim($_POST["F_RepDescr_".$FieldName]))) {
                $H_ErrorMessages[] = "<i>".$PrintableName."</i> is required.";
            } else {
                if ($FieldName == "AdminEmail") {
                    $RepDescr[$FieldName][] = trim($_POST["F_RepDescr_".$FieldName]);
                } else {
                    $RepDescr[$FieldName] = trim($_POST["F_RepDescr_".$FieldName]);
                }
            }
        }

        # if no errors found
        if (!isset($H_ErrorMessages)) {
            # save configuration
            $Plugin->configSetting("RepositoryDescr", $RepDescr);
            $Plugin->configSetting("SQEnabled", $_POST["F_SQEnabled"]);
        } else {
            # reload values for use in HTML
            $H_RepDescr = $RepDescr;
            $H_Formats = $Plugin->configSetting("Formats");
            $H_SQEnabled = $_POST["F_SQEnabled"];
        }
    }

    # take action based on which button was clicked
    switch ($_POST["Submit"]) {
        case "Save Changes":
            # if no errors were found with values
            if (!isset($H_ErrorMessages)) {
                # head back to sys admin page
                $AF->setJumpToPage("SysAdmin");
            }
            break;

        case "Cancel":
            # head back to sys admin page
            $AF->setJumpToPage("SysAdmin");
            break;

        case "Edit":
        case "Add Format":
            # head to format editing page
            $AF->setJumpToPage("P_OAIPMHServer_EditFormat&FN=".$FormatToEdit);
            break;
    }
# coming into page from elsewhere
} else {
    # load values for use in HTML
    $H_RepDescr = $Plugin->configSetting("RepositoryDescr");
    $H_Formats = $Plugin->configSetting("Formats");
    $H_SQEnabled = $Plugin->configSetting("SQEnabled");
}
