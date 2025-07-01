<?PHP
#
#   FILE:  DownloadFile.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2012-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus;
use ScoutLib\ApplicationFramework;
use ScoutLib\Item;
use ScoutLib\StdLib;

# ----- MAIN -----------------------------------------------------------------

$AF = ApplicationFramework::getInstance();

# retrieve user currently logged in
$User = User::getCurrentUser();

$FileId = StdLib::getFormValue("ID", StdLib::getFormValue("Id"));

# if file was not valid, tell AF not to cache this page
if ($FileId === null || !File::itemExists($FileId)) {
    $AF->doNotCacheCurrentPage();
    return;
}


# load file
$File = new File($FileId);

# check whether user can view file
if ($File->resourceId() != Record::NO_ITEM) {
    $Resource = new Record($File->resourceId());
    if ($File->fieldId() != Item::NO_ITEM) {
        $Field = MetadataField::getField($File->fieldId());
        $CanView = $Resource->userCanViewField($User, $Field);
    } else {
        $CanView = $Resource->userCanView($User);
    }
} else {
    $CanView = true;
}

# if user cannot view file, tell AF not to cache this page
if (!$CanView) {
    $AF->doNotCacheCurrentPage();
    User::handleUnauthorizedAccess();
    return;
}

# download file
$AF->downloadFile(
    $File->getNameOfStoredFile(),
    $File->name(),
    $File->getMimeType()
);
