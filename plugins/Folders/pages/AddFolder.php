<?PHP
#
#   FILE:  AddFolder.php (Folders plugin)
#
#   Part of the Metavus digital collections platform
#   Copyright 2012-2020 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#

# ----- MAIN -----------------------------------------------------------------

# check authorization and setup HTML suppression and page redirection
use Metavus\Plugins\Folders\Common;
use Metavus\Plugins\Folders\Folder;
use Metavus\Plugins\Folders\FolderFactory;
use Metavus\User;
use ScoutLib\StdLib;

if (!Common::ApiPageCompletion("P_Folders_ManageFolders")) {
    return;
}

$FolderName = StdLib::getArrayValue($_GET, "FolderName");

# can't do anything if there isn't a folder name to work with
if (!strlen($FolderName)) {
    return;
}

$FolderFactory = new FolderFactory(User::getCurrentUser()->Id());
$ResourceFolder = $FolderFactory->GetResourceFolder();

$NewFolder = $FolderFactory->CreateFolder("Resource", $FolderName);
$ResourceFolder->PrependItem($NewFolder);
