<?PHP
#
#   FILE:  DBExportField.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2012-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus;
use Exception;
use ScoutLib\ApplicationFramework;
use ScoutLib\Database;
use ScoutLib\StdLib;

# ----- LOCAL FUNCTIONS ------------------------------------------------------

/**
* Initialize variables (first time through they will be null).
*/
function InitExportVars(): void
{
    global $FSeek, $FieldCount;

    if (is_null($FSeek)) {
        $FSeek = 0;
    }

    if (is_null($FieldCount)) {
        $FieldCount = 0;
    }
}

/**
* Perform action. This is the main event.
*/
function DoWhileLoop(): void
{
    global $fpo, $FieldCount;
    global $Field, $FSeek;

    # write out the header line only on first time
    if (!empty($fpo) && $FSeek == 0) {
        # replace trailing tab with newline
        $Header = $Field->name()."\n";
        fwrite($fpo, $Header);
    }

    if ($Field->type() == MetadataSchema::MDFTYPE_TREE) {
        $Factory = new ClassificationFactory();
    } elseif ($Field->type() == MetadataSchema::MDFTYPE_CONTROLLEDNAME) {
        $Factory = new ControlledNameFactory();
    } elseif ($Field->type() == MetadataSchema::MDFTYPE_OPTION) {
        $Factory = new ControlledNameFactory();
    } else {
        throw new Exception("Unexpected field type (".$Field->type().").");
    }

    $ItemIds = $Factory->getItemIds("FieldId = ".$Field->id());

    foreach ($ItemIds as $Id) {
        # can only export trees
        if ($Field->type() == MetadataSchema::MDFTYPE_TREE) {
            $Class = new Classification($Id);
            $Value = $Class->fullName();
        # or controllednames or options
        } else {
            $CN = new ControlledName($Id);
            $Value = $CN->name();
        }

        # write out the value
        $Record = $Value."\n";
        if (!empty($fpo)) {
            fwrite($fpo, $Record);
        }
        $FieldCount++;
    }
}

# ----- MAIN -----------------------------------------------------------------

$AF = ApplicationFramework::getInstance();

# non-standard global variables
global $FSeek;
global $Field;
global $FieldCount;
global $fpo;

# check if current user is authorized
if (!User::requirePrivilege(PRIV_SYSADMIN, PRIV_COLLECTIONADMIN)) {
    return;
}

$ClassDB = new Database();

$Schema = new MetadataSchema();
$FieldId = StdLib::getArrayValue($_GET, "Id");
$Field = MetadataField::getField($FieldId);

$TempDir = realpath(__DIR__."/../tmp/")."/";

# make sure destination dir exists
if (!file_exists($TempDir)) {
    $ErrorMessage = "Error: Destination directory ".$TempDir.
                    " doesn't exist.";
    $AF->setJumpToPage("EditMetadataField&Id=".$FieldId
                       ."&ERR=".urlencode($ErrorMessage));
    return;
}

# make sure destination dir is writable
if (!is_writable($TempDir)) {
    $ErrorMessage = "Error: Destination directory ".$TempDir.
                    " is not writable.";
    $AF->setJumpToPage("EditMetadataField&Id=".$FieldId
                       ."&ERR=".urlencode($ErrorMessage));
    return;
}

if (isset($_POST["Submit"])) {
    $Submit = $_POST["Submit"];
} else {
    $Submit = null;
}

if ($Submit == "Cancel") {
    $AF->setJumpToPage("DBEditor");
    return;
}

InitExportVars();

# open export path
if (!isset($_SESSION["ExportPath"])) {
    $TmpDir = realpath(__DIR__."/../tmp/")."/";
    $FileNamePrefix = preg_replace("/[^a-z0-9]/i", "", $Field->name());
    $BaseName = $FileNamePrefix."_".date("YmdHis").".txt";
    $ExportPath = $TmpDir.$BaseName;
} else {
    $BaseName = "";
    $ExportPath = $_SESSION["ExportPath"];
}

$fpo = fopen($ExportPath, "a");
if ($fpo == false) {
    $ErrorMessage = "Cannot open Export Filename: ".$ExportPath;
    $AF->setJumpToPage("EditMetadataField&Id=".$FieldId
                       ."&ERR=".urlencode($ErrorMessage));
    return;
}

# suppress any HTML output
$AF->suppressHtmlOutput();

# the main work happenes here
DoWhileLoop();

fclose($fpo);

header("Content-Type: application/x-octet-stream");
header("Content-Disposition: attachment; filename=\"".addslashes($BaseName)."\"");
readfile("tmp/".$BaseName);
