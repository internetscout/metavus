<?PHP
#
#   FILE:  TransferFolders.php (Folders plugin)
#
#   Part of the Metavus digital collections platform
#   Copyright 2015-2023 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

# ----- MAIN -----------------------------------------------------------------
use Metavus\Plugins\Folders;
use Metavus\Plugins\Folders\Folder;
use Metavus\Plugins\Folders\FolderFactory;
use Metavus\User;
use ScoutLib\ApplicationFramework;
use ScoutLib\StdLib;
use ScoutLib\UserFactory;

/**
 * Print JSON using given information in JsonHelper format, replaces use of JsonHelper
 * @param string $State to display, "OK" for success, "ERROR" for error
 * @param string $Message (optional) to display
 * @return void
 */
function printJson(string $State, string $Message = "")
{
    $JsonArray = [
        "data" => [],
        "status" => [
            "state" => $State,
            "message" => $Message,
            "numWarnings" => 0,
            "warnings" => []
        ]
    ];
    print(json_encode($JsonArray));
}

$AF = ApplicationFramework::getInstance();

# retrieve user currently logged in
$User = User::getCurrentUser();

$FolderID = StdLib::getArrayValue($_GET, "FID");
$NewUserName = StdLib::getArrayValue($_POST, "username");
$NewUserID = null;
$Plugin = Folders::getInstance();
$UserHasPriv = $User->HasPriv($Plugin->
        ConfigSetting("PrivsToTransferFolders"));
$IsAjax = ApplicationFramework::ReachedViaAjax();

# suppress HTML if we are using AJAX
if ($IsAjax) {
    $AF->BeginAjaxResponse();
}

# try to retrieve User ID
if ($NewUserName != null) {
    $UserFactory = new UserFactory();
    $NewUserID = key($UserFactory->FindUserNames($NewUserName));
}

# return error messages
if ($FolderID == null || !$UserHasPriv || $NewUserName == null || $NewUserID == null) {
    if ($IsAjax) {
        if ($FolderID == null || $NewUserName == null) {
            printJson("ERROR", "Folder or new user information not received.");
        } elseif (Folder::ItemExists($FolderID)) {
            printJson("ERROR", " is an invalid folder ID.");
        } elseif ($NewUserID == null) {
            printJson("ERROR", "User '".$NewUserName."' not found.");
        } else {
            printJson("ERROR", "You don't have permission to transfer folders.");
        }
    } else {
        if ($FolderID == null || $NewUserName == null) {
            # error: not sufficient information received
            $ErrorID = 1;
        } elseif ($NewUserID == null) {
            # error: new user not found
            $ErrorID = 2;
        } else {
            # error: user don't have permission to transfer folder
            $ErrorID = 3;
        }

        $AF->SetJumpToPage("index.php?P=P_Folders_ConfirmFolderTransfer&FID="
                . $FolderID."&ER=".$ErrorID);
    }
    return;
}

$Folder = new Folder($FolderID);

if ($Folder->OwnerID() != $User->Id()) {
    if ($IsAjax) {
        printJson("ERROR", "You are not the owner of the folder.");
    } else {
        # error: user is not the owner of the folder
        $AF->SetJumpToPage("index.php?P=P_Folders_ConfirmFolderTransfer&FID="
                . $FolderID."&ER=4");
    }
    return;
}

# make sure that the new user id is of type int
# (i.e., if the new user id was a string, then cast it to an int)
$NewUserID = intval($NewUserID);

if ($Folder->OwnerID() == $NewUserID) {
    if ($IsAjax) {
        printJson("ERROR", "You cannot transfer this folder to yourself.");
    } else {
        # error: user is trying to transfer the folder to himself
        $AF->SetJumpToPage("index.php?P=P_Folders_ConfirmFolderTransfer&FID="
                . $FolderID."&ER=5");
    }
    return;
}

# transfer the folder
$FolderFactory = new FolderFactory($User->Id());
$OriginalResourceFolder = $FolderFactory->GetResourceFolder($User->Id());
$NewResourceFolder = $FolderFactory->GetResourceFolder($NewUserID);
$OriginalResourceFolder->RemoveItem($FolderID);
$NewResourceFolder->AppendItem($FolderID);

$Folder->OwnerId($NewUserID);

# create new default folder if none left
if ($OriginalResourceFolder->GetItemCount() == 0) {
    $FolderFactory->CreateDefaultFolder();
}

# select new folder if original was transferred
$SelectedFolder = $FolderFactory->GetSelectedFolder();
$FolderIds = $OriginalResourceFolder->GetItemIds();
if ($SelectedFolder->Id() == $FolderID) {
    $FolderFactory->SelectFolder(new Folder(current($FolderIds)));
}

# success
if ($IsAjax) {
    printJson("OK", "Folder successfully transferred to ".$NewUserName.".");
} else {
    $AF->SetJumpToPage("index.php?P=P_Folders_ConfirmFolderTransfer&FID="
            . $FolderID."&SC=TRUE");
}
