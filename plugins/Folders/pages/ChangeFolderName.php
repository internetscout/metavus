<?PHP
#
#   FILE:  ChangeFolderName.php (Folders plugin)
#
#   Part of the Metavus digital collections platform
#   Copyright 2012-2020 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#

use Metavus\Plugins\Folders\Folder;
use Metavus\Plugins\Folders\FolderFactory;
use Metavus\User;
use ScoutLib\StdLib;

# ----- MAIN -----------------------------------------------------------------

global $Folder;
global $Id;
global $Name;
global $ReturnTo;

PageTitle("Change Folder Name");

# make sure the user is logged in
if (!CheckAuthorization()) {
    return;
}

# redirect if no folder ID is given
if (!isset($_GET["FolderId"])) {
    $AF->SetJumpToPage("P_Folders_ManageFolders");
    return;
}

$FolderFactory = new FolderFactory(User::getCurrentUser()->Id());
$ResourceFolder = $FolderFactory->GetResourceFolder();
$Folder = new Folder(StdLib::getArrayValue($_GET, "FolderId"));

# redirect if the user should not see the folder
if (!$ResourceFolder->ContainsItem($Folder->Id())) {
    $AF->SetJumpToPage("P_Folders_ManageFolders");
    return;
}

$Id = $Folder->Id();
$Name = $Folder->Name();
$ReturnTo = defaulthtmlentities(StdLib::getArrayValue($_GET, "ReturnTo"));
