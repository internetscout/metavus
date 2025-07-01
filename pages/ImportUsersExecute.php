<?PHP
#
#   FILE:  ImportUsersExecute.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2004-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
#   @scout:phpstan

namespace Metavus;
use Exception;
use ScoutLib\ApplicationFramework;
use ScoutLib\Database;
use ScoutLib\StdLib;

# ----- MAIN -----------------------------------------------------------------

# check if current user is authorized
if (!User::requirePrivilege(PRIV_SYSADMIN, PRIV_USERADMIN)) {
    return;
}

$AF = ApplicationFramework::getInstance();

# initialize variables
$FSeek = StdLib::getArrayValue($_SESSION, "FSeek", 0);
$TempFile = StdLib::getFormValue("TF", StdLib::getArrayValue($_SESSION, "TempFile", null));
$H_UserCount = StdLib::getArrayValue($_SESSION, "UserCount", 0);
$H_ErrorMessages = StdLib::getArrayValue($_SESSION, "ErrorMessages", array());
$H_DuplicateEntries = StdLib::getArrayValue($_SESSION, "DuplicateEntries", array());
$H_PrivNotFound = StdLib::getArrayValue($_SESSION, "PrivNotFound", array());
$UsersProcessed = StdLib::getArrayValue($_SESSION, "UsersProcessed", array());
$LineCount = StdLib::getArrayValue($_SESSION, "LineCount", 0);
$LastUserName = StdLib::getArrayValue($_SESSION, "LastUserName", null);
$H_ImportComplete = false;
$Encrypt = StdLib::getFormValue("EN", StdLib::getArrayValue($_SESSION, "Encrypt", null));

$fp = fopen($TempFile, 'r');
if ($fp === false) {
    return;
}

# seek to the next line
if ($FSeek > 0) {
    fseek($fp, $FSeek);
}

# begin import
$Schema = new MetadataSchema();
$PrivDescriptions = (new PrivilegeFactory())->getPrivileges(true, false);
$LocalLineCount = 0;


while (!feof($fp) && $LocalLineCount < 500) {
    # read in line from import file
    $fline = fgets($fp, 4096);
    if ($fline === false || strlen($fline) === 0) {
        continue;
    }

    # update variables
    $LocalLineCount++;
    $LineCount++;
    $_SESSION["LineCount"] = $LineCount;
    $FSeek += strlen($fline);
    $_SESSION["FSeek"] = $FSeek;

    $Value = null;
    # parse line from import file
    $Vars = explode("\t", $fline);

    $NumberOfVars = count($Vars);
    if (feof($fp)) {
        $H_ImportComplete = true;
        break;
    }

    # should be 14 variables per line
    if (count($Vars) != 14) {
        $ErrorMessage = "Error: Wrong number of fields on Line ".$LineCount;
        $_SESSION["ErrorMessage"] = $ErrorMessage;

        # clean up file pointer, temp file
        fclose($fp);
        unlink($TempFile);

        # jump back and display error message
        $AF->setJumpToPage("ImportUsers");
        return;
    }

    # initial the vars
    $UserName = trim(addslashes($Vars[0]));
    $UserPassword = $Vars[1];
    $Email = addslashes($Vars[2]);
    $WebSite = addslashes($Vars[3]);
    $RealName = addslashes($Vars[4]);
    $AddressLineOne = addslashes($Vars[5]);
    $AddressLineTwo = addslashes($Vars[6]);
    $City = addslashes($Vars[7]);
    $State = addslashes($Vars[8]);
    $Country = addslashes($Vars[9]);
    $ZipCode = $Vars[10];
    $ActiveUI = $Vars[11];
    $BrowsingField = trim($Vars[12]);
    $PrivDescription = trim($Vars[13]);

    try {
        $Field = $Schema->getField($BrowsingField);
    } catch (Exception $e) {
        $Field = null;
    }

    # force FieldId to reasonable value if not set
    if (is_object($Field)) {
        $FieldId = $Field->id();
    } else {
        $FieldId = "NULL";
    }

    # default UserPassword to UserName if blank
    if (empty($UserPassword)) {
        $UserPassword = $UserName;
    }

    # encrypt the password
    if ($Encrypt) {
        $UserPassword = password_hash($UserPassword, PASSWORD_BCRYPT);
    }

    # check if this is a privilege line
    if ($LastUserName == $UserName && !empty($PrivDescription)) {
        # if this privilege line belongs to a duplicate entry,
        #       save this line as a duplicate entry as well
        if (isset($UsersProcessed[$UserName]) && $UsersProcessed[$UserName] === 1) {
            $H_DuplicateEntries[$LineCount] = $fline;
        } elseif (!array_key_exists($UserName, $H_ErrorMessages)) {
            # if this user was not created, its privilege lines should also be skipped
            $Privilege = array_search($PrivDescription, $PrivDescriptions);

            # if a privilege is not found in the privilege factoroy,
            #       notify admin about this failure
            if ($Privilege === false) {
                $H_PrivNotFound[$LineCount] = $fline;
            } else {
                $ThisUser = new User($UserName);
                $ThisUser->grantPriv((int) $Privilege);
            }
        }
        continue;
    }

    # cache username for privileges
    $LastUserName = $UserName;

    # only process the 1st entry if there are duplicate entries (same username)
    if (array_key_exists($UserName, $UsersProcessed)) {
        $H_DuplicateEntries[$LineCount] = $fline;
        $UsersProcessed[$UserName] = 1;
        continue;
    } else {
        $UsersProcessed[$UserName] = 0;
    }

    # attemp to create user
    $UserFactory = new UserFactory();
    $User = $UserFactory->createNewUser(
        $UserName,
        $UserPassword,
        $UserPassword,
        $Email,
        $Email
    );

    # check if user creation succeeded, if failed, save error messages to print
    if (!($User instanceof User)) {
        $H_ErrorMessages[$UserName]["LineNumber"] = $LineCount;
        foreach ($User as $ErrorCode) {
            $H_ErrorMessages[$UserName]["Messages"][] =
                    User::getStatusMessageForCode($ErrorCode);
        }
        continue;
    }

    $User->set("WebSite", isset($Website) ? $Website : "");
    $User->set("AddressLineOne", $AddressLineOne);
    $User->set("AddressLineTwo", $AddressLineTwo);
    $User->set("City", $City);
    $User->set("State", $State);
    $User->set("ZipCode", $ZipCode);
    $User->set("Country", $Country);
    $User->set("RealName", $RealName);
    $User->set("ActiveUI", $ActiveUI);

    $User->isActivated(true);

    if (!isset($_POST["F_PasswordFlag"])) {
        $User->setEncryptedPassword($UserPassword);
    } elseif ($_POST["F_PasswordFlag"] != 1) {
        $User->setEncryptedPassword($UserPassword);
    }

    # add in privilege if set
    if (!empty($PrivDescription)) {
        $Privilege = array_search($PrivDescription, $PrivDescriptions);
        # if a privilege is not found in the privilege factoroy,
        #       notify admin about this failure
        if ($Privilege === false) {
            $H_PrivNotFound[$LineCount] = $fline;
        }

        $ThisUser = new User($UserName);
        $ThisUser->grantPriv((int) $Privilege);
    }

    # keep track of number of users added
    $H_UserCount++;
    $_SESSION["UserCount"] = $H_UserCount;
}

# end of file reached?
if (feof($fp)) {
    $H_ImportComplete = true;

    # annihilate uploaded file
    $ToDelete = new File($_SESSION["FileId"]);
    $ToDelete->destroy();
    unset($_SESSION["FileId"]);
}
$_SESSION["UserCount"] = $H_UserCount;
$_SESSION["FSeek"] = $FSeek;
$_SESSION["TempFile"] = $TempFile;
$_SESSION["ErrorMessages"] = $H_ErrorMessages;
$_SESSION["UsersProcessed"] = $UsersProcessed;
$_SESSION["DuplicateEntries"] = $H_DuplicateEntries;
$_SESSION["PrivNotFound"] = $H_PrivNotFound;
$_SESSION["LastUserName"] = $LastUserName;
$_SESSION["Encrypt"] = $Encrypt;
#  Time to auto-refresh?
if ($H_ImportComplete == false) {
    $AF->setJumpToPage("index.php?P=ImportUsersExecute", 1);
}

$AF->setPageTitle("Import Users");

# register post-processing function with the application framework
$AF->addPostProcessingCall(
    __NAMESPACE__."\\PostProcessingFn",
    $TempFile,
    $fp,
    $H_ImportComplete
);

# post-processing call
/**
* Handle post-processing after a pageload.
* Post-processing task: clear import file and session variables.
* @param string $TempFile Temporary file name.
* @param resource $fp Active file descriptor.
* @param bool $ImportComplete True when import is complete.
*/
function PostProcessingFn($TempFile, $fp, $ImportComplete): void
{
    if ($ImportComplete == true) {
        fclose($fp);
        # remove temporary uploaded file

        $Variables = array("UserCount", "FSeek", "TempFile",
            "LineCount", "ErrorMessages", "LastUserName",
            "DuplicateEntries", "UsersProcessed", "PrivNotFound", "Encrypt"
        );
        foreach ($Variables as $Var) {
            unset($_SESSION[$Var]);
        }
    }
}
