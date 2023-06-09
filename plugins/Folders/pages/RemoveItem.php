<?PHP
#
#   FILE:  RemoveItem.php (Folders plugin)
#
#   Part of the Metavus digital collections platform
#   Copyright 2012-2022 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#

use Metavus\Plugins\Folders\Common;
use Metavus\Plugins\Folders\Folder;
use Metavus\Plugins\Folders\FolderFactory;
use Metavus\User;
use Metavus\Record;
use ScoutLib\ApplicationFramework;
use ScoutLib\PluginManager;
use ScoutLib\StdLib;

# ----- SETUP ----------------------------------------------------------------

# check authorization
if (!Common::ApiPageCompletion("P_Folders_ManageFolders")) {
    return;
}

# get the folders plugin
$FoldersPlugin = PluginManager::getInstance()->getPluginForCurrentPage();

# set up variables
$ItemId = StdLib::getArrayValue($_GET, "ItemId");
$ReturnTo = [];
$Errors = [];

$FolderFactory = new FolderFactory(User::getCurrentUser()->Id());
$FolderId = StdLib::getArrayValue($_GET, "FolderId");

# get the currently selected folder if no folder ID is given
if ($FolderId === null) {
    $FolderId = $FolderFactory->GetSelectedFolder()->Id();
}

$ResourceFolder = $FolderFactory->GetResourceFolder();
$Folder = new Folder($FolderId);

PageTitle("Folders - Remove Item");

# ----- MAIN -----------------------------------------------------------------

# if specified resource exists
if (Record::ItemExists($ItemId)) {
    # remove item only if the resource folder contains this folder, which implies
    # that the user owns the folder and it's a valid folder of resources
    if ($ResourceFolder->ContainsItem($Folder->Id())) {
        # last operation was successful?
        if ($ResourceFolder->ContainsItem($Folder->Id())) {
            $Folder->RemoveItem($ItemId);
        }
    } else {
        # report user doesn't own the folder
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

/** @phpstan-ignore-next-line */
$FoldersPlugin->ProcessPageResponse($Errors);
