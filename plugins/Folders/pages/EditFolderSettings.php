<?PHP
#
#   FILE:  EditFolderSettings.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2024-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# VALUES PROVIDED to INTERFACE (OPTIONAL):
#   $H_Error - Error message if something went wrong. If not set, all
#       the remaining optional parameters will be provided.
#   $H_FolderId - ID of current folder
#   $H_FormUI - Editing form for folder
#
# @scout:phpstan

namespace Metavus;

use Metavus\Plugins\Folders;
use Metavus\Plugins\Folders\Folder;
use ScoutLib\ApplicationFramework;
use ScoutLib\StdLib;

# ----- LOCAL FUNCTIONS ------------------------------------------------------

# ----- MAIN -----------------------------------------------------------------

$AF = ApplicationFramework::getInstance();

$User = User::getCurrentUser();

# must be logged in to edit a folder
if (!User::requireBeingLoggedIn()) {
    return;
}

$FoldersPlugin = Folders::getInstance();

$AllowCoverImages = $FoldersPlugin->getConfigSetting("PrivsToAddCoverImage")
    ->meetsRequirements($User);

# ID must be provided and valid
$H_FolderId = $_GET["ID"] ?? null;
if (!is_numeric($H_FolderId) || !Folder::itemExists((int)$H_FolderId)) {
    $H_Error = "Invalid Folder Id.";
    return;
}

$Folder = new Folder((int)$H_FolderId);

if ($User->id() != $Folder->ownerId()) {
    $H_Error = "You cannot edit folders you do not own.";
    return;
}

$CoverImageId = $Folder->getCoverImageId();
$CoverImageList = ($CoverImageId !== false) ? [$CoverImageId] : [];

$FormFields = [
    "FolderName" => [
        "Label" => "Folder Name",
        "Type" => FormUI::FTYPE_TEXT,
        "Required" => true,
        "Value" => $Folder->name(),
    ],
    "FolderNote" => [
        "Label" => "Folder Description",
        "Type" => FormUI::FTYPE_PARAGRAPH,
        "Value" => $Folder->note(),
    ],
    "CoverImage" => [
        "Label" => "Cover Image",
        "Type" => FormUI::FTYPE_IMAGE,
        "AllowMultiple" => false,
        "Value" => $CoverImageList,
    ]
];

if (!$AllowCoverImages) {
    unset($FormFields["CoverImage"]);
}

$H_FormUI = new FormUI($FormFields);

switch ($H_FormUI->getSubmitButtonValue()) {
    case "Upload":
        $H_FormUI->handleUploads();
        break;

    case "Delete":
        $H_FormUI->handleDeletes();
        break;

    case "Save":
        # if the input provided was valid
        if ($H_FormUI->validateFieldInput() == 0) {
            $NewValues = $H_FormUI->getNewValuesFromForm();

            $Folder->name($NewValues["FolderName"]);
            $Folder->note($NewValues["FolderNote"]);

            if ($AllowCoverImages) {
                $NewCoverImageList = $NewValues["CoverImage"];
                if ($NewCoverImageList != $CoverImageList) {
                    if (count($NewCoverImageList) == 0) {
                        $Folder->setCoverImageId(false);
                    } else {
                        $Folder->setCoverImageId(reset($NewCoverImageList));
                    }

                    # delete any images that are no longer in our cover image list
                    $ImagesToDelete = array_diff($CoverImageList, $NewCoverImageList);
                    foreach ($ImagesToDelete as $ImageId) {
                        $Image = new Image($ImageId);
                        $Image->destroy();
                    }
                }
            }

            $AF->setJumpToPage(
                "index.php?P=P_Folders_ViewFolder&FolderId=".$H_FolderId
            );
        }
        break;

    case "Cancel":
        $H_FormUI->deleteUploads();
        $AF->setJumpToPage(
            "index.php?P=P_Folders_ViewFolder&FolderId=".$H_FolderId
        );
        break;

    default:
        break;
}
