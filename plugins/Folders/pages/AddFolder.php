<?PHP
#
#   FILE:  AddFolder.php (Folders plugin)
#
#   Part of the Metavus digital collections platform
#   Copyright 2012-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus;

use Metavus\Plugins\Folders\Folder;
use Metavus\Plugins\Folders\FolderFactory;
use ScoutLib\ApplicationFramework;

# ----- MAIN -----------------------------------------------------------------

# make sure a user is logged in
if (!CheckAuthorization()) {
    return;
}

# go back to ManageFolders when we're done
ApplicationFramework::getInstance()
    ->setJumpToPage("P_Folders_ManageFolders");

# nothing to do when no folder name provided
$FolderName = $_GET["FolderName"] ?? "";
if (!strlen($FolderName)) {
    return;
}

$User = User::getCurrentUser();
$FolderFactory = new FolderFactory($User->id());
$ResourceFolder = $FolderFactory->getResourceFolder();

$NewFolder = $FolderFactory->createFolder("Resource", $FolderName);
$ResourceFolder->prependItem($NewFolder);
