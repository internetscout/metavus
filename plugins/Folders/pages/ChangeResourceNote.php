<?PHP
#
#   FILE:  ChangeResourceNote.php (Folders plugin)
#
#   Part of the Metavus digital collections platform
#   Copyright 2012-2026 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus;

use Metavus\Plugins\Folders\Folder;
use Metavus\Plugins\Folders\FolderFactory;
use ScoutLib\ApplicationFramework;
use ScoutLib\StdLib;

# ----- MAIN -----------------------------------------------------------------

$AF = ApplicationFramework::getInstance();
$AF->setPageTitle("Change Resource Note");

# make sure the user is logged in
if (!User::requireBeingLoggedIn()) {
    return;
}

# get parameters
$H_FolderId = StdLib::getFormValue("FolderId");
$H_ItemId = StdLib::getFormValue("ItemId");

# nothing to do for invalid folders
if ($H_FolderId === null || !Folder::itemExists($H_FolderId)) {
    $AF->setJumpToPage("P_Folders_ManageFolders");
    return;
}

# nothing to do for invalid records
if ($H_ItemId === null || !Record::itemExists($H_ItemId)) {
    $AF->setJumpToPage("P_Folders_ViewFolder&FolderId=".$H_FolderId);
    return;
}

$Folder = new Folder($H_FolderId);
$FolderFactory = new FolderFactory(User::getCurrentUser()->id());
$ResourceFolder = $FolderFactory->getResourceFolder();

# if resource folder does not contain the target FolderId, that implies that a
# different user owns the folder
if (!$ResourceFolder->containsItem($Folder->id())) {
    $AF->setJumpToPage("P_Folders_ViewFolder&FolderId=".$H_FolderId);
    return;
}

# nothing to do when resource isn't in this folder
if (!$Folder->containsItem($H_ItemId)) {
    $AF->setJumpToPage("P_Folders_ViewFolder&FolderId=".$H_FolderId);
    return;
}

$H_Note = $Folder->noteForItem($H_ItemId) ?? "";

# build the form
$FormFields = [
    "ResourceNote" => [
        "Label" => "Note",
        "Type" => FormUI::FTYPE_PARAGRAPH,
        "Value" => $H_Note,
    ]
];
$H_Form = new FormUI($FormFields);
$H_Form->addHiddenField("FolderId", $H_FolderId);
$H_Form->addHiddenField("ItemId", $H_ItemId);

# ----- PROCESSING -----------------------------------------------------------

# if we're saving a note, save it and go back to the folder
switch ($H_Form->getSubmitButtonValue()) {
    case "Change Resource Note":
        $ResourceNote = $H_Form->getNewValuesFromForm()["ResourceNote"];
        $Folder->noteForItem($H_ItemId, $ResourceNote);
        # intentional fall-through
    case "Cancel":
        $AF->setJumpToPage("P_Folders_ViewFolder&FolderId=".$H_FolderId);
        return;
}
