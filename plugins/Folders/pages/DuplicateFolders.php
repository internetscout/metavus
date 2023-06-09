<?PHP
#
#   FILE:  DuplicateFolders.php (Folders plugin)
#
#   Part of the Metavus digital collections platform
#   Copyright 2018-2022 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#

# ----- MAIN -----------------------------------------------------------------

use Metavus\Plugins\Folders\Folder;
use Metavus\Plugins\Folders\FolderFactory;
use Metavus\User;
use ScoutLib\ApplicationFramework;
use ScoutLib\StdLib;

$FolderId = StdLib::getFormValue("FID");

# check argument validity
if ($FolderId == null) {
    $H_ErrorMsg = "Folder ID is not received.";
    return;
} elseif (!Folder::ItemExists($FolderId)) {
    $H_ErrorMsg = "Folder ID is not valid.";
    return;
}

# only allow current user to edit their own folders
$Folder = new Folder($FolderId);
$OwnerId = $Folder->OwnerId();
if ($OwnerId != User::getCurrentUser()->Id()) {
    $H_ErrorMsg = "You are not the owner of this folder.";
    return;
}

# create a duplicate
$NewFolder = $Folder->duplicate();
$NewFolder->Name($NewFolder->Name()." (DUPLICATE)");

$FolderFactory = new FolderFactory($OwnerId);
$ResourceFolder = $FolderFactory->GetResourceFolder($OwnerId);
$ResourceFolder->AppendItem($NewFolder->Id());

# jump to ManageFolders page
ApplicationFramework::getInstance()->setJumpToPage("index.php?P=P_Folders_ManageFolders");
