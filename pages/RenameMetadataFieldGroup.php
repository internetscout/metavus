<?PHP
#
#   FILE:  RenameMetadataFieldGroup.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2012-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
#   @scout:phpstan

use Metavus\MetadataFieldGroup;
use Metavus\MetadataSchema;
use Metavus\User;
use ScoutLib\ApplicationFramework;
use ScoutLib\StdLib;

# ----- MAIN -----------------------------------------------------------------
$AF = ApplicationFramework::getInstance();
$AF->setPageTitle("Rename Metadata Field Group");
if (!User::requirePrivilege(PRIV_SYSADMIN, PRIV_COLLECTIONADMIN)) {
    return;
}

$H_SchemaId = StdLib::getArrayValue($_GET, "SchemaId", MetadataSchema::SCHEMAID_DEFAULT);
$GroupId = StdLib::getArrayValue($_GET, "GroupId");

if (MetadataFieldGroup::itemExists($GroupId)) {
    $H_Group = new MetadataFieldGroup($GroupId);
} else {
    $AF->setJumpToPage("MetadataFieldOrdering");
}
