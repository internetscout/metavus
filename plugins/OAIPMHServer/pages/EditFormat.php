<?PHP
#
#   FILE:  EditFormat.php (OAI-PMH Server plugin)
#
#   Part of the Metavus digital collections platform
#   Copyright 2017-2024 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

use Metavus\File;
use Metavus\Plugins\OAIPMHServer;
use ScoutLib\ApplicationFramework;
use ScoutLib\PluginManager;

if (!CheckAuthorization(PRIV_COLLECTIONADMIN)) {
    return;
}

/**
 * Remove format.
 * @param string $FormatName Name of format.
 */
function DeleteFormat($FormatName): void
{
    # load existing formats
    $OAIPMHServerPlugin = OAIPMHServer::getInstance();
    $Formats = $OAIPMHServerPlugin->getConfigSetting("Formats");

    unset($Formats[$FormatName]);

    $OAIPMHServerPlugin->setConfigSetting("Formats", $Formats);
}

/**
 * Save changes to format.
 * @param array $Format New format settings.
 * @param string $FormatName Name of format.
 */
function SaveChanges($Format, $FormatName): void
{
    # load existing formats
    $OAIPMHServerPlugin = OAIPMHServer::getInstance();
    $Formats = $OAIPMHServerPlugin->getConfigSetting("Formats");

    # if format name changed
    $OldFormatName = $_POST["H_FormatName"];
    if (strlen($OldFormatName) && ($Format["FormatName"] != $OldFormatName)) {
        # clear format under old name
        unset($Formats[$OldFormatName]);

        # set new format name to save under
        $FormatName = $Format["FormatName"];
    } elseif (strlen($FormatName) == 0) {
        # Format is new, and didn't previously exist
        $FormatName = $Format["FormatName"];
    }

    # clean up format item ordering
    ksort($Format["Namespaces"]);
    ksort($Format["Elements"]);
    ksort($Format["Qualifiers"]);

    # save format
    $Formats[$FormatName] = $Format;
    $OAIPMHServerPlugin->setConfigSetting("Formats", $Formats);
}

# define a template format with default values
$FormatTemplate = [
    "FormatName" => "",
    "TagName" => "",
    "SchemaNamespace" => "",
    "SchemaDefinition" => "",
    "SchemaVersion" => "",
    "Namespaces" => [" " => ""],
    "Elements" => ["" => -1],
    "Qualifiers" => ["" => -1],
    "Defaults" => []
];

$AF = ApplicationFramework::getInstance();

# if we are coming in from format editing form
if (isset($_POST["H_FormatName"])) {
    # retrieve format name
    $FormatName = $_POST["H_FormatName"];

    # check for "Delete" button click
    $ItemTypes = ["Namespace", "Element", "Qualifier", "Default"];
    foreach ($ItemTypes as $ItemType) {
        $Index = 0;
        while (isset($_POST["F_".$ItemType."Name".$Index])) {
            if (isset($_POST["Delete".$ItemType.$Index])) {
                $ItemTypeToDelete = $ItemType;
                $ItemToDelete = $_POST["H_".$ItemType."Index".$Index];
                $_POST["Submit"] = "Delete Item";
                break 2;
            }
            $Index++;
        }
    }

    if (isset($_POST["DeleteXsltFile"])) {
        $_POST["Submit"] = "Delete File";
    }

    # check for required values
    $FormVars = [
        "FormatName" => "Format Name",
        "TagName" => "Tag Name",
        "SchemaNamespace" => "Schema Namespace URI",
        "SchemaDefinition" => "Schema Definition URL"
    ];
    foreach ($FormVars as $FieldName => $PrintableName) {
        if (!strlen(trim($_POST["F_".$FieldName]))) {
            $H_ErrorMessages[] = $PrintableName." is required.";
        } else {
            $Format[$FieldName] = trim($_POST["F_".$FieldName]);
        }
    }

    # transfer optional values
    $FormVars = ["SchemaVersion" => "Schema Version"];
    foreach ($FormVars as $FieldName => $PrintableName) {
        $Format[$FieldName] = trim($_POST["F_".$FieldName]);
    }

    if (isset($_POST["H_XsltFileId"])) {
        $Format["XsltFileId"] = trim($_POST["H_XsltFileId"]);
    }

    # for each item type (namespace/element/qualifier)
    foreach ($ItemTypes as $ItemType) {
        # for each old item
        $Index = 0;
        $VFType = ($ItemType == "Default") ? "Element" : $ItemType;
        $VFName = ($ItemType == "Namespace") ? "Url" : "Mapping";
        while (isset($_POST["F_".$VFType."Name".$Index])) {
            # retrieve values from form
            $OrigIndex = $_POST["H_".$VFType."Index".$Index];
            $NewIndex = trim($_POST["F_".$VFType."Name".$Index]);
            $NewValue = trim($_POST["F_".$ItemType.$VFName.$Index]);

            # if index value has changed
            if ($NewIndex != $OrigIndex) {
                # if new index value is blank
                if (!strlen($NewIndex) && strlen($NewValue) && ($NewValue != -1)) {
                    # restore index value and warn user
                    $NewIndex = $OrigIndex;
                    $H_ErrorMessages[] = "Blank ".strtolower($ItemType)
                            ."  names are not allowed."
                            ." (Reverted to previous value)";
                } elseif (isset($Format[$ItemType."s"][$NewIndex])) {
                    # else if new index value is a duplicate
                    # restore index value and warn user
                    $NewIndex = $OrigIndex;
                    $H_ErrorMessages[] = "Duplicate ".strtolower($ItemType)
                            ." names are not allowed."
                            ." (Reverted to previous value)";
                } else {
                    # unset value at location of old index
                    if (isset($Format[$ItemType."s"][$OrigIndex])) {
                        unset($Format[$ItemType."s"][$OrigIndex]);
                    }
                }
            }

            # if index is blank or for a default and value is blank/unselected
            if ((!strlen($NewIndex) || $ItemType == "Default") &&
                 (!strlen($NewValue) || ($NewValue == -1))) {
                # delete item
                if (isset($Format[$ItemType."s"][$NewIndex])) {
                    unset($Format[$ItemType."s"][$NewIndex]);
                }
            } else {
                # save new item value
                $Format[$ItemType."s"][$NewIndex] = $NewValue;
            }
            $Index++;
        }

        # if there were new items
        $ItemCount = $_POST["H_".$ItemType."Count"];
        if ($Index < $ItemCount) {
            # for each new item
            for ($Index--; $Index <= $ItemCount; $Index++) {
                # retrieve new values from form
                $NewIndex = trim($_POST["F_".$VFType."Name".$Index]);
                $NewValue = trim($_POST["F_".$ItemType.$VFName.$Index]);

                # if new index value is blank
                if (!strlen($NewIndex) && strlen($NewValue) && ($NewValue != -1)) {
                    # warn user
                    $H_ErrorMessages[] = "Blank ".strtolower($ItemType)
                            ."  names are not allowed.";
                } elseif (isset($Format[$ItemType."s"][$NewIndex])) {
                    # else if new index value is a duplicate
                    # clear index value and warn user
                    $NewIndex = "";
                    $H_ErrorMessages[] = "Duplicate ".strtolower($ItemType)
                            ." names are not allowed.";
                }

                # if new index and value look valid
                if (strlen($NewIndex) && strlen($NewValue) && ($NewValue != -1)) {
                    # save new item value
                    $Format[$ItemType."s"][$NewIndex] = $NewValue;
                }
            }
        }
    }

    # be sure the format includes expected entries
    if (!isset($Format["FormatName"])) {
        $Format["FormatName"] = $FormatName;
    }
    foreach ($FormatTemplate as $Key => $DefaultValue) {
        if (!isset($Format[$Key])) {
            $Format[$Key] = $DefaultValue;
        }
    }

    # if user requested to save changes to format and no errors found
    if (($_POST["Submit"] == "Save Changes") && !isset($H_ErrorMessages)) {
        SaveChanges($Format, $FormatName);
    }

    # take action based on which button was clicked
    switch ($_POST["Submit"]) {
        case "Cancel":
            # head back to configuration editing page
            $AF->SetJumpToPage("P_OAIPMHServer_EditConfig");
            break;

        case "Save Changes":
            # head back to configuration editing page only if no errors found
            if (!isset($H_ErrorMessages)) {
                $AF->SetJumpToPage("P_OAIPMHServer_EditConfig");
            }
            break;

        case "Add Namespace":
            # add blank namespace entry
            $Format["Namespaces"][" "] = " ";
            break;

        case "Add Element":
            # add blank element entry
            $Format["Elements"][" "] = -1;
            break;

        case "Add Qualifier":
            # add blank qualifier entry
            $Format["Qualifiers"][" "] = -1;
            break;

        case "Delete Format":
            DeleteFormat($FormatName);
            $AF->SetJumpToPage("P_OAIPMHServer_EditConfig");
            break;

        case "Delete Item":
            if (isset($ItemTypeToDelete) && isset($ItemToDelete)) {
                unset($Format[$ItemTypeToDelete."s"][$ItemToDelete]);
            }
            SaveChanges($Format, $FormatName);
            break;

        case "Delete File":
            $XsltFile = new File(intval($Format["XsltFileId"]));
            $XsltFile->destroy();

            unset($Format["XsltFileId"]);
            SaveChanges($Format, $FormatName);
            break;

        case "Upload File":
            # XSLT Files:
            if (isset($_FILES["F_XsltFile"]["tmp_name"])) {
                if (!(is_dir("tmp") && is_writeable("tmp"))) {
                    $H_ErrorMessages[] = "tmp does not exist or is not writeable, ".
                        "contact the site administrator with this error.";
                } elseif (!is_dir(File::GetStorageDirectory())
                        || !is_writeable(File::GetStorageDirectory())) {
                    $H_ErrorMessages[] = File::GetStorageDirectory()
                            ." does not exist or is not writeable,"
                            ." contact the site administrator with this error.";
                } else {
                    $TmpFileName = $_FILES["F_XsltFile"]["tmp_name"];
                    $NewFile = File::Create(
                        $TmpFileName,
                        $_FILES["F_XsltFile"]["name"]
                    );

                    # if file save failed
                    if (!is_object($NewFile)) {
                        # set error message and error out
                        switch ($NewFile) {
                            case File::FILESTAT_ZEROLENGTH:
                                $H_ErrorMessages[] = "Uploaded file was zero length.";
                                break;

                            default:
                                $H_ErrorMessages[] = "File upload error.";
                                break;
                        }
                    } else {
                        # Save worked
                        $Format["XsltFileId"] = $NewFile->Id();
                        SaveChanges($Format, $FormatName);
                    }

                    unlink($TmpFileName);
                }
            }
            break;
    }

    # load values for possible use in HTML
    if (isset($Format)) {
        $H_Format = $Format;
    } else {
        $H_Format = $FormatTemplate;
    }
    $H_FormatName = $FormatName;
} else {
    # if new format requested
    if (isset($_GET["FN"]) && ($_GET["FN"] == "")) {
        # start with blank format
        $H_FormatName = "";
        $H_Format = $FormatTemplate;
    } else {
        # if specified format is available
        $OAIPMHServerPlugin = OAIPMHServer::getInstance();
        $Formats = $OAIPMHServerPlugin->getConfigSetting("Formats");
        $H_FormatName = $_GET["FN"];
        if (isset($Formats[$H_FormatName])) {
            # retrieve format to be edited
            $H_Format = $Formats[$H_FormatName];

            # make sure internal format name is set
            if (!isset($H_Format["FormatName"])) {
                $H_Format["FormatName"] = $H_FormatName;
            }

            # be sure the format includes expected entries
            foreach ($FormatTemplate as $Key => $DefaultValue) {
                if (!isset($H_Format[$Key])) {
                    $H_Format[$Key] = $DefaultValue;
                }
            }
        } else {
            # return to configuration editing page
            $AF->SetJumpToPage("P_OAIPMHServer_EditConfig");
        }
    }
}

# set flag indicating if standard format (some fields should not be modified)
$H_StdFormat = ($H_FormatName == "oai_dc") ? true : false;
