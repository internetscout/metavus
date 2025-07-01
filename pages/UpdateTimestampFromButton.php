<?PHP
#
#   FILE:  UpdateTimestampFromButton.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2012-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

use Metavus\MetadataField;
use Metavus\MetadataSchema;
use Metavus\Record;
use Metavus\User;
use ScoutLib\ApplicationFramework;

# retrieve user currently logged in
$User = User::getCurrentUser();

# check that required params were provided
if (!isset($_GET["ID"]) || !isset($_GET["FI"])) {
    User::handleUnauthorizedAccess();
    return;
}

# extract parameters
$ResourceId = $_GET["ID"];
$FieldId = $_GET["FI"];

# bail if the resource is invalid
if (!Record::itemExists($ResourceId)) {
    User::handleUnauthorizedAccess();
    return;
}

# get the resource
$Resource = new Record($ResourceId);

# bail if user can't edit this resource, given field is invalid, or user can't
# edit given field
if (!$Resource->userCanModify($User) ||
    !$Resource->getSchema()->fieldExists($FieldId) ||
    !$Resource->userCanModifyField($User, $FieldId)) {
    User::handleUnauthorizedAccess();
    return;
}

# get the field
$Field = $Resource->getSchema()->getField($FieldId);

# bail if field doesn't have an update button
if ($Field->updateMethod() != MetadataField::UPDATEMETHOD_BUTTON) {
    User::handleUnauthorizedAccess();
    return;
}

# do the update
switch ($Field->type()) {
    case MetadataSchema::MDFTYPE_TIMESTAMP:
        $Resource->set($Field, "now");
        break;
    case MetadataSchema::MDFTYPE_USER:
        $Resource->set($Field, $User);
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
ApplicationFramework::getInstance()->setJumpToPage($Resource->getViewPageUrl());
