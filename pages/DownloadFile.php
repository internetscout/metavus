<?PHP
#
#   FILE:  DownloadFile.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2012-2020 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#

use Metavus\File;
use Metavus\MetadataField;
use Metavus\Record;
use Metavus\User;
use ScoutLib\Item;
use ScoutLib\StdLib;

# ----- MAIN -----------------------------------------------------------------

# retrieve user currently logged in
$User = User::getCurrentUser();

$FileId = StdLib::getFormValue("ID", StdLib::getFormValue("Id"));

# if file ID was supplied and is valid
if (!is_null($FileId) && File::ItemExists($FileId)) {
    # load file
    $File = new File($FileId);

    # check whether user can view file
    if ($File->ResourceId() != Record::NO_ITEM) {
        $Resource = new Record($File->ResourceId());
        if ($File->FieldId() != Item::NO_ITEM) {
            $Field = new MetadataField($File->FieldId());
            $CanView = $Resource->UserCanViewField($User, $Field);
        } else {
            $CanView = $Resource->UserCanView($User);
        }
    } else {
        $CanView = true;
    }

    # if user can view file
    if ($CanView) {
        # download file
        $GLOBALS["AF"]->DownloadFile(
            $File->GetNameOfStoredFile(),
            $File->Name(),
            $File->GetMimeType()
        );
    } else {
        # print message about unauthorized access
        CheckAuthorization(-1);
    }
}

# if a file was not found, the HTML template will be loaded instead
