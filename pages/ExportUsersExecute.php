<?PHP
#
#   FILE:  ExportUsersExecute.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2011-2020 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#

use Metavus\MetadataSchema;
use Metavus\PrivilegeFactory;
use Metavus\User;
use ScoutLib\Database;
use ScoutLib\UserFactory;

# ----- LOCAL FUNCTIONS ------------------------------------------------------

/**
* Get list of matching files.
* @param string $DirPath Directory path.
* @param string $Pattern Regular expression to match.
* @return array Matching file names.
*/
function ListDir($DirPath, $Pattern)
{
    static $ResultArray = array();

    $Handle = opendir($DirPath);
    while ($File = readdir($Handle)) {
        if ($File == '.' || $File == '..') {
            continue;
        }
        if (is_dir($DirPath.$File)) {
            continue;
        } elseif (preg_match($Pattern, $File)) {
            $ResultArray[] = $DirPath.$File;
        }
    }
    closedir($Handle);
    return $ResultArray;
}

# ----- MAIN -----------------------------------------------------------------

# check if current user is authorized
if (!CheckAuthorization(PRIV_SYSADMIN, PRIV_USERADMIN)) {
    return;
}

$DB = new Database();

if (isset($_POST["Submit"]) && $_POST["Submit"] == "Cancel") {
    $AF->SetJumpToPage("SysAdmin");
    return;
}

# initialize variables here
$MaxUserToExport = 50;
$UserCount = isset($_SESSION["UserCount"]) ? $_SESSION["UserCount"] : 0;
$UserFactory = new UserFactory();

if (!isset($_POST["F_UserPrivs"])) {
    $UserInfo = $UserFactory->GetMatchingUsers(
        ".*.",
        null,
        "UserName",
        $UserCount,
        $MaxUserToExport
    );
} else {
    $UsersWithPrivs = $UserFactory->GetUsersWithPrivileges($_POST["F_UserPrivs"]);
    $AllUserInfo = $UserFactory->GetMatchingUsers(".*.", null, "UserName");
    $UserInfo = array_intersect_key($AllUserInfo, $UsersWithPrivs);

    $UserInfo = array_slice(
        $UserInfo,
        $UserCount,
        $MaxUserToExport,
        true
    );
}
$ExportComplete = count($UserInfo) < $MaxUserToExport
        ? true : false;

# open export path
if (!isset($_SESSION["ExportPath"])) {
    $TmpDir = realpath(__DIR__."/../tmp/")."/";
    $FileName = "Users_".date("YmdHis").".txt";
    $ExportPath = $TmpDir.$FileName;

    # remove any old exported files
    $OldExportFiles = ListDir($TmpDir, "/^Users_.*\.txt$/");

    if (is_array($OldExportFiles)) {
        foreach ($OldExportFiles as $OldFile) {
            unlink($OldFile);
        }
    }
    $_SESSION["FileName"] = $FileName;
    $_SESSION["ExportPath"] = $ExportPath;
} else {
    $ExportPath = $_SESSION["ExportPath"];
}

$FP = fopen($ExportPath, "a");
if ($FP == false) {
    $ErrorMessage = "Cannot open Export Filename: $ExportPath<br>";
    $_SESSION["ErrorMessage"] = $ErrorMessage;
    $AF->SetJumpToPage("DisplayError");
    return;
}

# begin export

# $Schema is a resource schema that will be used to retrieve and export the
#       browsing field (whose FieldId is the value of "BrowsingFieldId",
#       which is a property of one user account) for individual user
$Schema = new MetadataSchema(MetadataSchema::SCHEMAID_DEFAULT);
$PrivDescriptions = (new PrivilegeFactory())->GetPrivileges(true, false);

foreach ($UserInfo as $Entry) {
    if ($Entry["BrowsingFieldId"] > 0) {
        $Field = $Schema->GetField($Entry["BrowsingFieldId"]);
        if (is_object($Field)) {
            $BrowsingField = $Field->Name();
        } else {
            $BrowsingField = null;
        }
    } else {
        $BrowsingField = null;
    }

    $Output = $Entry["UserName"]."\t".
              $Entry["UserPassword"]."\t".
              $Entry["EMail"]."\t".
              $Entry["WebSite"]."\t".
              $Entry["RealName"]."\t".
              $Entry["AddressLineOne"]."\t".
              $Entry["AddressLineTwo"]."\t".
              $Entry["City"]."\t".
              $Entry["State"]."\t".
              $Entry["Country"]."\t".
              $Entry["ZipCode"]."\t".
              $Entry["ActiveUI"]."\t".
              $BrowsingField."\t\n";

    fwrite($FP, $Output);
    $UserCount++;

    # now export privileges for this user
    $NewUser = new User($Entry["UserId"]);
    $PrivList = $NewUser->GetPrivList();

    foreach ($PrivList as $Privilege) {
        if (is_numeric($Privilege)) {
            $Privilege = $PrivDescriptions[$Privilege];
            $Output = $Entry["UserName"]."\t\t\t\t\t\t\t\t\t\t\t\t\t".
                        $Privilege."\n";
            fwrite($FP, $Output);
        }
    }
}

# update usercount for refresh as well as page variables
$_SESSION["UserCount"] = $UserCount;

$H_ExportComplete = $ExportComplete;
$H_UserCount = $UserCount;
$H_FileName = "tmp/".$_SESSION["FileName"];

#  Time to auto-refresh?
if ($ExportComplete == false) {
    $AF->SetJumpToPage("index.php?P=ExportUsersExecute", 1);
}

PageTitle("Export Users");

# register post-processing function with the application framework
$AF->AddPostProcessingCall("PostProcessingFn", $FP, $ExportComplete);

/**
* Post-processing call, to close file pointer and clean export status
* session variables after export is complete.
* @param mixed $FP File pointer.
* @param book $ExportComplete If 1, export is complete.
*/
function PostProcessingFn($FP, $ExportComplete)
{
    if ($ExportComplete == true) {
        fclose($FP);
        unset($_SESSION["ExportComplete"]);
        unset($_SESSION["UserCount"]);
        unset($_SESSION["FileName"]);
        unset($_SESSION["ExportPath"]);
    }
}
