<?PHP
#
#   FILE:  RenameMetadataFieldGroup.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2012-2020 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#

use Metavus\MetadataFieldGroup;
use Metavus\MetadataSchema;
use ScoutLib\StdLib;

# ----- EXPORTED FUNCTIONS ---------------------------------------------------

/**
 * Print the fields in the given metadata field group.
 * @param MetadataFieldGroup $Group metadata field group
 * @return void
 */
function PrintGroupItems(MetadataFieldGroup $Group)
{
    foreach ($Group->GetFields() as $Field) {
        PrintFieldInGroup($Field);
    }
}

# ----- MAIN -----------------------------------------------------------------

global $SchemaId;
global $Group;

PageTitle("Rename Metadata Field Group");
CheckAuthorization(PRIV_SYSADMIN, PRIV_COLLECTIONADMIN);

$SchemaId = StdLib::getArrayValue($_GET, "SchemaId", MetadataSchema::SCHEMAID_DEFAULT);
$GroupId = StdLib::getArrayValue($_GET, "GroupId");

try {
    $Group = new MetadataFieldGroup($GroupId);
} catch (Exception $Exception) {
    $GLOBALS["AF"]->SetJumpToPage("MetadataFieldOrdering");
}
