<?PHP
#
#   FILE:  FullImage.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2003-2024 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# VALUES PROVIDED to INTERFACE (OPTIONAL):
#   $H_Image - Image to be displayed.  (May not be set if error was
#       encountered.)
#   $H_Resource - Record associated with image.  (May not be set if error
#       was encountered.)
#   $H_Field - Metadata field associated with image.  (May not be set if
#       error was encountered.)
#   $H_Error - Error message to display.  (Only set if error was encountered.)
#
# @scout:phpstan

namespace Metavus;
use ScoutLib\ApplicationFramework;
use ScoutLib\StdLib;

# ----- MAIN -----------------------------------------------------------------

$AF = ApplicationFramework::getInstance();

$ResourceId = StdLib::getFormValue("RI");
if (is_null($ResourceId)) {
    $ResourceId = StdLib::getFormValue("ResourceId");

    if (!is_null($ResourceId)
            && array_key_exists("HTTP_REFERER", $_SERVER)
            && strlen($_SERVER["HTTP_REFERER"])) {
        $AF->logMessage(
            ApplicationFramework::LOGLVL_WARNING,
            "FullImage page loaded with deprecated ResourceId= parameter"
                    ." (should be RI=). Referer was ".$_SERVER["HTTP_REFERER"]
        );
    }
}

$FieldId = StdLib::getFormValue("FI");
$ImageId = StdLib::getFormValue("ID");

# check that required parameters were provided
if (is_null($ResourceId) || is_null($FieldId) || is_null($ImageId)) {
    $AF->doNotCacheCurrentPage();
    $H_Error = "Required parameters not provided.";
    return;
}

# check that image exists
if (!is_numeric($ImageId) || !Image::itemExists((int)$ImageId)) {
    $AF->doNotCacheCurrentPage();
    $H_Error = "Invalid ImageId provided.";
    return;
}

$H_Image = new Image($ImageId);

# check that the resource exists
if (!Record::itemExists($ResourceId)) {
    $AF->doNotCacheCurrentPage();
    $H_Error = "Invalid ResourceId provided.";
    return;
}

# check that the field exists
if (!MetadataSchema::fieldExistsInAnySchema($FieldId)) {
    $AF->doNotCacheCurrentPage();
    $H_Error =  "Invalid FieldId provided.";
    return;
}

# get the requested record and field
$H_Resource = new Record($ResourceId);
$H_Field = MetadataField::getField($FieldId);

# check that the requested field is actually an image field
if ($H_Field->type() != MetadataSchema::MDFTYPE_IMAGE) {
    $H_Error = "Specified field is not an image field.";
    return;
}

# check that the requested field is a valid field for this resource
if ($H_Field->schemaId() != $H_Resource->getSchemaId()) {
    $H_Error = "Field belongs to a different schema that the specified resource.";
    return;
}

# check that this image is associated with the requested record/field
$AssociatedImageIds = $H_Resource->get($H_Field);
if (!in_array($H_Image->id(), $AssociatedImageIds)) {
    $H_Error = "Image not associated with the specified resource and field.";
    return;
}

# check that user can view image
if (!$H_Resource->userCanViewField(User::getCurrentUser(), $H_Field)) {
    $AF->setJumpToPage("UnauthorizedAccess");
    return;
}

# if image download was requested
if (isset($_GET["DL"])) {
    # download image and suppress page display
    $AF->downloadFile($H_Image->getFullPathForOriginalImage(), null, null, true);
}
