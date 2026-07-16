<?PHP
#
#   FILE:  DisplayPhoto.php (PhotoLibrary plugin)
#
#   Part of the Metavus digital collections platform
#   Copyright 2024-2026 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# VALUES PROVIDED to INTERFACE (OPTIONAL):
#   $H_ErrMsg - Error message to display (if any).
#   $H_Photo - Photo (Image instance) to display.  (Only set if $H_ErrMsg
#       is not set.)
#   $H_Record - Record (Record instance) for photo.  (Only set if $H_ErrMsg
#       is not set.)
#
# @scout:phpstan

namespace Metavus;
use Metavus\Plugins\PhotoLibrary;
use ScoutLib\ApplicationFramework;
use ScoutLib\StdLib;

# ----- MAIN -----------------------------------------------------------------

# set up environment
$AF = ApplicationFramework::getInstance();
$User = User::getCurrentUser();
$Plugin = PhotoLibrary::getInstance();
$SchemaId = $Plugin->getConfigSetting("MetadataSchemaId");
$RFactory = new RecordFactory($SchemaId);

# retrieve photo ID
$RecordId = StdLib::getFormValue("ID");

# check that photo ID is valid
if (filter_var($RecordId, FILTER_VALIDATE_INT) == false ||
    !$RFactory->itemExists($RecordId)) {
    $H_ErrMsg = "Supplied photo record ID is invalid.";
    return;
}

# check that user has permission to view photo
$H_Record = new Record($RecordId);
if (!$H_Record->userCanView($User)) {
    $H_ErrMsg = "You do not have permission to view this photo.";
    return;
}

# check that photo is available
$Photos = $H_Record->getMapped("Screenshot", true);
if (($Photos === null) || (count($Photos) == 0)) {
    $H_ErrMsg = "Photo is unavailable.";
    return;
}

# retrieve photo
$H_Photo = $Plugin->getOriginalDownloadImageForRecord($H_Record);
if ($H_Photo === null) {
    $H_ErrMsg = "Photo is unavailable.";
    return;
}

# if download requested
if ($_GET["DL"] ?? 0) {
    # trigger download of original image file
    if (!$Plugin->downloadOriginalImage($H_Record, $H_Photo)) {
        $H_ErrMsg = "Unable to download original image.";
    }
    return;
} else {
    # signal full record page view
    $AF->signalEvent("EVENT_FULL_RECORD_VIEW", ["ResourceId" => $H_Record->id()]);
}
