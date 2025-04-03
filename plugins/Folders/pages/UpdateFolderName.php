<?PHP
#
#   FILE:  UpdateFolderName.php (Folders plugin)
#
#   Part of the Metavus digital collections platform
#   Copyright 2012-2023 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

# ----- MAIN -----------------------------------------------------------------

use Metavus\Plugins\Folders;
use Metavus\Plugins\Folders\Common;
use Metavus\Plugins\Folders\Folder;
use Metavus\Plugins\Folders\FolderFactory;
use Metavus\User;
use ScoutLib\ApplicationFramework;
use ScoutLib\StdLib;

# check authorization and setup HTML suppression and page redirection
if (!Common::ApiPageCompletion("P_Folders_ManageFolders")) {
    return;
}

# canceled editing
if (StdLib::getArrayValue($_GET, "Cancel")) {
    return;
}
# get the folders plugin
$FoldersPlugin = Folders::getInstance();

if (!($FoldersPlugin instanceof \Metavus\Plugins\Folders)) {
    throw new Exception("Retrieved plugin is not Folders (should be impossible).");
}

# set up variables
$Errors = [];

$FolderId = StdLib::getArrayValue($_GET, "FolderId");
$FolderName = StdLib::getArrayValue($_GET, "FolderName");
$FolderFactory = new FolderFactory(User::getCurrentUser()->Id());

# We need to pass in a folder id, not simply change the default, so we break here
if ($FolderId === null) {
    array_push($Errors, 'E_FOLDERS_NOSUCHFOLDER');
} else {
    $Folder = new Folder($FolderId);
    $ResourceFolder = $FolderFactory->GetResourceFolder();
}

PageTitle("Folders - Change Folder Name");

# ----- MAIN -----------------------------------------------------------------

# only move on if we have a valid folder
if (isset($Folder) && isset($ResourceFolder)) {
    # continue only if the resource folder contains this folder, which implies
    # that the user owns the folder and it's a valid folder of resources
    if ($ResourceFolder->ContainsItem($Folder->Id())) {
        # change the name
        $Folder->Name($FolderName);
    } else {
        # user doesn't own the folder
        array_push($Errors, 'E_FOLDERS_NOTFOLDEROWNER');
    }
}

# ----- PAGE ROUTING  -----------------------------------------------------------------
# handle page routing based on the success/failure above.

# This page does not output any HTML
ApplicationFramework::getInstance()->suppressHTMLOutput();

$FoldersPlugin->ProcessPageResponse($Errors);
