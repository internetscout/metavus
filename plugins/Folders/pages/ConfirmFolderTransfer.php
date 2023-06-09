<?PHP
#
#   FILE:  ConfirmFolderTransfer.php (Folders plugin)
#
#   Part of the Metavus digital collections platform
#   Copyright 2015-2020 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#

# ----- MAIN -----------------------------------------------------------------

use Metavus\Plugins\Folders\Folder;
use ScoutLib\StdLib;

$H_ErrorMessage = "";
$H_SuccessMessage = "";
$H_FolderID = StdLib::getArrayValue($_GET, "FID");
$H_Folder = new Folder($H_FolderID);
$ErrorMessages = [
    1 => "Folder and new user information not received.",
    2 => "User not found.",
    3 => "You don't have permission to transfer folders.",
    4 => "You are not the owner of the folder.",
    5 => "You cannot transfer this folder to yourself."
];

# check if there is any error returned
if (array_key_exists("ER", $_GET)) {
    $ErrorCode = $_GET["ER"];
    $H_ErrorMessage = $ErrorMessages[$ErrorCode];
} elseif (array_key_exists("SC", $_GET)) {
    $H_SuccessMessage = "Folder successfully transferred.";
    # also jump back to manage folder after some delay upon successful
    header("Refresh: 2; url=index.php?P=P_Folders_ManageFolders");
}
