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
use ScoutLib\ApplicationFramework;
use ScoutLib\StdLib;

# ----- MAIN -----------------------------------------------------------------

PageTitle("Rename Metadata Field Group");
CheckAuthorization(PRIV_SYSADMIN, PRIV_COLLECTIONADMIN);

$H_SchemaId = StdLib::getArrayValue($_GET, "SchemaId", MetadataSchema::SCHEMAID_DEFAULT);
$GroupId = StdLib::getArrayValue($_GET, "GroupId");

if (MetadataFieldGroup::itemExists($GroupId)) {
    $H_Group = new MetadataFieldGroup($GroupId);
} else {
    ApplicationFramework::getInstance()->setJumpToPage("MetadataFieldOrdering");
}
