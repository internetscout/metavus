<?PHP
#
#   FILE:  DuplicateFolders.php (Folders plugin)
#
#   Part of the Metavus digital collections platform
#   Copyright 2018-2022 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#

# ----- MAIN -----------------------------------------------------------------

namespace Metavus;

use Metavus\Plugins\Folders\Folder;
use Metavus\Plugins\Folders\FolderFactory;
use ScoutLib\ApplicationFramework;
use ScoutLib\StdLib;

$FolderId = StdLib::getFormValue("FID");

# check argument validity
if ($FolderId === null) {
    $H_ErrorMsg = "Folder ID is not received.";
    return;
}

if (!Folder::itemExists($FolderId)) {
    $H_ErrorMsg = "Folder ID is not valid.";
    return;
}

# only allow current user to edit their own folders
$Folder = new Folder($FolderId);
$OwnerId = $Folder->ownerId();
if ($OwnerId != User::getCurrentUser()->id()) {
    $H_ErrorMsg = "You are not the owner of this folder.";
    return;
}

# create a duplicate
$NewFolder = $Folder->duplicate();
$NewFolder->name($NewFolder->name()." (DUPLICATE)");

$FolderFactory = new FolderFactory($OwnerId);
$ResourceFolder = $FolderFactory->getResourceFolder($OwnerId);
$ResourceFolder->appendItem($NewFolder->id());

# jump to ManageFolders page
ApplicationFramework::getInstance()->setJumpToPage("index.php?P=P_Folders_ManageFolders");
