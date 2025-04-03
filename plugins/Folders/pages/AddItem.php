<?PHP
#
#   FILE:  AddItem.php (Folders plugin)
#
#   Part of the Metavus digital collections platform
#   Copyright 2012-2022 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

use Metavus\Plugins\Folders;
use Metavus\Plugins\Folders\Common;
use Metavus\Plugins\Folders\Folder;
use Metavus\Plugins\Folders\FolderFactory;
use Metavus\Record;
use Metavus\User;
use ScoutLib\ApplicationFramework;
use ScoutLib\StdLib;

# ----- SETUP ----------------------------------------------------------------

# check authorization
if (!Common::ApiPageCompletion("P_Folders_ManageFolders")) {
    return;
}

# get the folders plugin
$FoldersPlugin = Folders::getInstance();

# set up variables
$ItemId = StdLib::getArrayValue($_GET, "ItemId");
$Errors = [];

$FolderId = StdLib::getArrayValue($_GET, "FolderId");
$FolderFactory = new FolderFactory(User::getCurrentUser()->Id());

# get the currently selected folder if no folder ID is given
if ($FolderId === null) {
    $FolderId = $FolderFactory->GetSelectedFolder()->Id();
}

$ResourceFolder = $FolderFactory->GetResourceFolder();
$Folder = new Folder($FolderId);

PageTitle("Folders - Add Item");

# ----- MAIN -----------------------------------------------------------------

# if resource exists
if (Record::ItemExists($ItemId)) {
    # add item only if the resource folder contains this folder, which implies
    # that the user owns the folder and it's a valid folder of resources
    if ($ResourceFolder->ContainsItem($Folder->Id())) {
        # last operation was successful?
        $Folder->AppendItem($ItemId);
    } else {
        # report that user doesn't own the folder
        array_push($Errors, 'E_FOLDERS_NOTFOLDEROWNER');
    }
} else {
    # report invalid item Id
    array_push($Errors, 'E_FOLDERS_NOSUCHITEM');
}

# ----- PAGE ROUTING  -----------------------------------------------------------------
# handle page routing based on the success/failure above.

# This page does not output any HTML
ApplicationFramework::getInstance()->suppressHTMLOutput();

$FoldersPlugin->ProcessPageResponse($Errors);
