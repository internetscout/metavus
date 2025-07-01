<?PHP
#
#   FILE:  UpdateQualifier.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2012-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
#   @scout:phpstan

use Metavus\MetadataSchema;
use Metavus\Qualifier;
use Metavus\QualifierFactory;
use Metavus\RecordFactory;
use Metavus\User;
use ScoutLib\ApplicationFramework;

# ----- LOCAL FUNCTIONS ------------------------------------------------------

/**
* Get list of qualifiers to remove.
* @return array remove list
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
function RemoveListValue(): void
{
    $Schema = new MetadataSchema();
    $RFactory = new RecordFactory();

    $Qualifiers = $_SESSION["Qualifiers"];
    unset($_SESSION["Qualifiers"]);

    foreach ($Qualifiers as $QualifierId) {
        $Qualifier = new Qualifier($QualifierId);
        $Schema->removeQualifierAssociations($Qualifier);
        $RFactory->clearQualifier($Qualifier);
        $Qualifier->destroy();
    }
}

/**
* Add list value.
* @param string $NewName Name of new qualifier.
* @param string $NewNamespace Namespace of new qualifier.
* @param string $NewUrl Url of new qualifer.
* @return string|null Error message is there is any, or NULL if none.
*/
function AddListValue($NewName, $NewNamespace, $NewUrl)
{
    $QualifierFactory = new QualifierFactory();

    # escape quotes
    $NewName = addslashes($NewName);
    $NewNamespace = addslashes($NewNamespace);
    $NewUrl = addslashes($NewUrl);

    # first check to see if a qualifier with the new name already exists
    # (pass true to nameIsInUse so a case-insensitive string
    # comparison is used to check if the new qualifier's name
    # is already in use)
    if (!empty($NewName)) {
        if ($QualifierFactory->nameIsInUse($NewName, true)) {
            return "<b>Error: </b>".$NewName." already exists";
        }

        # create new qualifier
        $Qualifier = Qualifier::create($NewName);
        $Qualifier->nSpace($NewNamespace);
        $Qualifier->url($NewUrl);
    } else {
        return "<b>Error: </b>No Value Entered";
    }
    return null;
}

/**
* Update list value.
*/
function UpdateListValue(): void
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
            if (!empty($QualifierName) && isset($QualifierId) && isset($QualifierNamespace)) {
                # create new qualifier
                $Qualifier = new Qualifier($QualifierId);
                $Qualifier->name($QualifierName);
                $Qualifier->nSpace($QualifierNamespace);
                $Qualifier->url($QualifierUrl);
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

if (!User::requirePrivilege(PRIV_SYSADMIN, PRIV_COLLECTIONADMIN)) {
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

$AF = ApplicationFramework::getInstance();

# check for Cancel button from previous screen
if ($Submit == "Cancel") {
    # cancel from confirm delete
    if ($OkayToDelete) {
        $AF->setJumpToPage("AddQualifier");
    } else {
        $AF->setJumpToPage("SysAdmin");
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
            $AF->setJumpToPage("ConfirmDeleteQualifier");
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
ApplicationFramework::getInstance()->setJumpToPage("AddQualifier");
