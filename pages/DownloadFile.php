<?PHP
#
#   FILE:  DownloadFile.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2012-2023 Edward Almasy and Internet Scout Research Group
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
if ($File->ResourceId() != Record::NO_ITEM) {
    $Resource = new Record($File->ResourceId());
    if ($File->FieldId() != Item::NO_ITEM) {
        $Field = MetadataField::getField($File->FieldId());
        $CanView = $Resource->UserCanViewField($User, $Field);
    } else {
        $CanView = $Resource->UserCanView($User);
    }
} else {
    $CanView = true;
}

# if user cannot view file, tell AF not to cache this page
if (!$CanView) {
    $AF->doNotCacheCurrentPage();
    CheckAuthorization(-1);
    return;
}

# download file
$AF->downloadFile(
    $File->GetNameOfStoredFile(),
    $File->Name(),
    $File->GetMimeType()
);
