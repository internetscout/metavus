<?PHP
#
#   FILE:  ExportUsersExecute.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2011-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
#   @scout:phpstan

use Metavus\MetadataSchema;
use Metavus\PrivilegeFactory;
use Metavus\User;
use ScoutLib\ApplicationFramework;
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
    while ($Handle && $File = readdir($Handle)) {
        if ($File == '.' || $File == '..') {
            continue;
        }
        if (is_dir($DirPath.$File)) {
            continue;
        } elseif (preg_match($Pattern, $File)) {
            $ResultArray[] = $DirPath.$File;
        }
    }
    if ($Handle) {
        closedir($Handle);
    }
    return $ResultArray;
}

# ----- MAIN -----------------------------------------------------------------

$AF = ApplicationFramework::getInstance();

# check if current user is authorized
if (!User::requirePrivilege(PRIV_SYSADMIN, PRIV_USERADMIN)) {
    return;
}

$DB = new Database();

if (isset($_POST["Submit"]) && $_POST["Submit"] == "Cancel") {
    $AF->setJumpToPage("SysAdmin");
    return;
}

# initialize variables here
$MaxUserToExport = 50;
$H_UserCount = $_SESSION["UserCount"] ?? 0;
$UserFactory = new UserFactory();

if (!isset($_POST["F_UserPrivs"])) {
    $UserInfo = $UserFactory->getMatchingUsers(
        ".*.",
        null,
        "UserName",
        $H_UserCount,
        $MaxUserToExport
    );
} else {
    $UsersWithPrivs = $UserFactory->getUsersWithPrivileges($_POST["F_UserPrivs"]);
    $AllUserInfo = $UserFactory->getMatchingUsers(".*.", null, "UserName");
    $UserInfo = array_intersect_key($AllUserInfo, $UsersWithPrivs);

    $UserInfo = array_slice(
        $UserInfo,
        $H_UserCount,
        $MaxUserToExport,
        true
    );
}

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

    $AF->setJumpToPage("DisplayError");
    return;
}

# begin export

# $Schema is a resource schema that will be used to retrieve and export the
#       browsing field (whose FieldId is the value of "BrowsingFieldId",
#       which is a property of one user account) for individual user
$Schema = new MetadataSchema(MetadataSchema::SCHEMAID_DEFAULT);
$PrivDescriptions = (new PrivilegeFactory())->getPrivileges(true, false);

foreach ($UserInfo as $Entry) {
    if ($Entry["BrowsingFieldId"] > 0) {
        $Field = $Schema->getField($Entry["BrowsingFieldId"]);
        $BrowsingField = $Field->name();
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
    $H_UserCount++;

    # now export privileges for this user
    $NewUser = new User($Entry["UserId"]);
    $PrivList = $NewUser->getPrivList();

    foreach ($PrivList as $Privilege) {
        if (is_numeric($Privilege) && in_array($Privilege, $PrivDescriptions)) {
            $Privilege = $PrivDescriptions[$Privilege];
            $Output = $Entry["UserName"]."\t\t\t\t\t\t\t\t\t\t\t\t\t".
                        $Privilege."\n";
            fwrite($FP, $Output);
        }
    }
}

# update user count for refresh as well as page variables
$_SESSION["UserCount"] = $H_UserCount;

$H_ExportComplete = count($UserInfo) < $MaxUserToExport;
$H_FileName = "tmp/".$_SESSION["FileName"];

#  Time to auto-refresh?
if ($H_ExportComplete === false) {
    $AF->setJumpToPage("index.php?P=ExportUsersExecute", 1);
}

$AF->setPageTitle("Export Users");

# register post-processing function with the application framework
$AF->addPostProcessingCall("PostProcessingFn", $FP, $H_ExportComplete);

/**
* Post-processing call, to close file pointer and clean export status
* session variables after export is complete.
* @param mixed $FP File pointer.
* @param bool $ExportComplete If 1, export is complete.
*/
function PostProcessingFn($FP, $ExportComplete): void
{
    if ($ExportComplete) {
        fclose($FP);
        unset($_SESSION["ExportComplete"]);
        unset($_SESSION["UserCount"]);
        unset($_SESSION["FileName"]);
        unset($_SESSION["ExportPath"]);
    }
}
