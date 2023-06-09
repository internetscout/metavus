<?PHP
#
#   FILE:  UpdateQualifier.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2012-2020 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#

use Metavus\MetadataSchema;
use Metavus\Qualifier;
use Metavus\QualifierFactory;
use Metavus\RecordFactory;

# ----- LOCAL FUNCTIONS ------------------------------------------------------

/**
* Get list of qualifiers to remove.
*/
function GetRemoveList()
{
    $Schema = new MetadataSchema();

    $Qualifiers = array();
    foreach ($_POST as $Var => $Value) {
        if (preg_match("/qid_[0-9]+/", $Var)) {
            if ($Value != "") {
                $Qualifiers[] = $Value;
            }
        }
    }
    return $Qualifiers;
}

/**
* Remove list value.
*/
function RemoveListValue()
{
    $Schema = new MetadataSchema();
    $RFactory = new RecordFactory();

    $Qualifiers = $_SESSION["Qualifiers"];
    unset($_SESSION["Qualifiers"]);

    foreach ($Qualifiers as $QualifierId) {
        $Qualifier = new Qualifier($QualifierId);
        $Schema->RemoveQualifierAssociations($Qualifier);
        $RFactory->ClearQualifier($Qualifier);
        $Qualifier->destroy();
    }
}

/**
* Add list value.
* @param str $NewName Name of new qualifier.
* @param str $NewNamespace Namespace of new qualifier.
* @param str $NewUrl Url of new qualifer.
* @return str Error message is there is any, or NULL if none.
*/
function AddListValue($NewName, $NewNamespace, $NewUrl)
{
    $QualifierFactory = new QualifierFactory();

    # escape quotes
    $NewName = addslashes($NewName);
    $NewNamespace = addslashes($NewNamespace);
    $NewUrl = addslashes($NewUrl);

    # first check to see if it already exists
    if (!empty($NewName)) {
        if ($QualifierFactory->NameIsInUse($NewName)) {
            return "<b>Error: </b>".$NewName." already exists";
        }

        # create new qualifier
        $Qualifier = Qualifier::Create($NewName);
        $Qualifier->NSpace($NewNamespace);
        $Qualifier->Url($NewUrl);
    } else {
        return "<b>Error: </b>No Value Entered";
    }
    return null;
}

/**
* Update list value.
*/
function UpdateListValue()
{
    foreach ($_POST as $Var => $Value) {
        if (preg_match("/qid_[0-9]+/", $Var)) {
            if (isset($Value)) {
                $QualifierId = $Value;
            } else {
                continue;
            }
        }
        if (preg_match("/qn_[0-9]+/", $Var)) {
            if (isset($Value)) {
                $QualifierName = addslashes($Value);
            } else {
                continue;
            }
            # add check here to see if it already exists
        }
        if (preg_match("/qs_[0-9]+/", $Var)) {
            if (isset($Value)) {
                $QualifierNamespace = addslashes($Value);
            } else {
                continue;
            }
            # add check here to see if it already exists
        }
        if (preg_match("/qu_[0-9]+/", $Var)) {
            $Value = trim($Value);
            $QualifierUrl = addslashes($Value);
            if (!empty($QualifierName) && isset($QualifierId)) {
                # create new qualifier
                $Qualifier = new Qualifier($QualifierId);
                $Qualifier->Name($QualifierName);
                $Qualifier->NSpace($QualifierNamespace);
                $Qualifier->Url($QualifierUrl);
                $QualifierId = null;
            }
        }
    }
}

# ----- MAIN -----------------------------------------------------------------

# non-standard global variables
global $ErrorMessages;
global $F_NewName;
global $F_NewNamespace;
global $F_NewUrl;

if (!CheckAuthorization(PRIV_SYSADMIN, PRIV_COLLECTIONADMIN)) {
    return;
}

if (isset($_POST["F_NewName"])) {
    $F_NewName = $_POST["F_NewName"];
}
if (isset($_POST["F_NewName"])) {
    $F_NewNamespace = $_POST["F_NewNamespace"];
}
if (isset($_POST["F_NewUrl"])) {
    $F_NewUrl = $_POST["F_NewUrl"];
}
if (isset($_POST["OkayToDelete"])) {
    $OkayToDelete = $_POST["OkayToDelete"];
} else {
    $OkayToDelete = null;
}
if (isset($_POST["Submit"])) {
    $Submit = $_POST["Submit"];
} else {
    $Submit = null;
}

$ErrorMessages = array();

# check for Cancel button from previous screen
if ($Submit == "Cancel") {
    # cancel from confirm delete
    if ($OkayToDelete) {
        $AF->SetJumpToPage("AddQualifier");
    } else {
        $AF->SetJumpToPage("SysAdmin");
    }
    return;
} elseif (substr($Submit, 0, 6) == "Delete") {
    if ($OkayToDelete) {
        RemoveListValue();
    } else {
        # give user a second chance
        $Qualifiers = GetRemoveList();
        if (count($Qualifiers) > 0) {
            $_SESSION["Qualifiers"] = $Qualifiers;
            $AF->SetJumpToPage("ConfirmDeleteQualifier");
            return;
        } else {
            $ErrorMessages[] = "<b>Error: </b>No Qualifiers selected";
        }
    }
} elseif (substr($Submit, 0, 15) == "Update Selected") {
    UpdateListValue();
} elseif (substr($Submit, 0, 3) == "Add" ||
    (empty($Submit) && isset($F_NewName))) {
    $Result = AddListValue($F_NewName, $F_NewNamespace, $F_NewUrl);

    if ($Result !== null) {
        $ErrorMessages[] = $Result;
    }
}

$_SESSION["ErrorMessages"] = $ErrorMessages;
$GLOBALS["AF"]->SetJumpToPage("AddQualifier");
