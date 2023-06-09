<?PHP
#
#   FILE:  UpdateTimestampFromButton.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2012-2020 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#

use Metavus\MetadataField;
use Metavus\MetadataSchema;
use Metavus\Record;
use Metavus\User;

# retrieve user currently logged in
$User = User::getCurrentUser();

# check that required params were provided
if (!isset($_GET["ID"]) || !isset($_GET["FI"])) {
        checkAuthorization(-1);
    return;
}

# extract parameters
$ResourceId = $_GET["ID"];
$FieldId = $_GET["FI"];

# bail if the resource is invalid
if (!Record::itemExists($ResourceId)) {
    checkAuthorization(-1);
    return;
}

# get the resource
$Resource = new Record($ResourceId);

# bail if user can't edit this resource, given field is invalid, or user can't
# edit given field
if (!$Resource->userCanModify($User) ||
    !$Resource->getSchema()->fieldExists($FieldId) ||
    !$Resource->userCanModifyField($User, $FieldId)) {
    CheckAuthorization(-1);
    return;
}

# get the field
$Field = $Resource->getSchema()->getField($FieldId);

# bail if field doesn't have an update button
if ($Field->updateMethod() != MetadataField::UPDATEMETHOD_BUTTON) {
    CheckAuthorization(-1);
    return;
}

# do the update
switch ($Field->Type()) {
    case MetadataSchema::MDFTYPE_TIMESTAMP:
        $Resource->Set($Field, "now");
        break;
    case MetadataSchema::MDFTYPE_USER:
        $Resource->Set($Field, $User);
        break;
    default:
        throw new Exception("Unsupported field type for update button");
}

# and process autoupdate fields
$Resource->updateAutoupdateFields(
    MetadataField::UPDATEMETHOD_ONRECORDCHANGE,
    $User
);

# route user back to view page
$GLOBALS["AF"]->setJumpToPage($Resource->getViewPageUrl());
