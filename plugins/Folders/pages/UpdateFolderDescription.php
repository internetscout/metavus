<?PHP
#
#   FILE:  UpdateFolderDescription.php (Folders plugin)
#
#   Part of the Metavus digital collections platform
#   Copyright 2012-2025 Edward Almasy and Internet Scout Research Group
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

# canceled editing
if (StdLib::getArrayValue($_GET, "Submit") === "Cancel") {
    return;
}

$FolderId = StdLib::getArrayValue($_GET, "FolderId");
$FolderDescription = StdLib::getArrayValue($_GET, "FolderDescription");

# can't do anything if there isn't a folder ID to work with
if (!strlen($FolderId)) {
    return;
}


$FolderFactory = new FolderFactory(User::getCurrentUser()->Id());
$ResourceFolder = $FolderFactory->GetResourceFolder();
$Folder = new Folder($FolderId);

# withdraw only if the resource folder contains this folder, which implies
# that the user owns the folder and it's a valid folder of resources
if ($ResourceFolder->ContainsItem($Folder->Id())) {
    $Folder->Note($FolderDescription);
}
