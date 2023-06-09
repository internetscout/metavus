<?PHP
#
#   FILE:  ConfirmDeleteMetadataField.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2012-2021 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#

namespace Metavus;

use ScoutLib\StdLib;

# ----- LOCAL FUNCTIONS ------------------------------------------------------

/**
 * Determine whether the given metadata field is the last enabled tree field.
 * @param MetadataField $Field Tree metadata field
 * @return Bool TRUE if the metadata field is the last tree field, else FALSE
 */
function IsFinalTreeField(MetadataField $Field)
{
    # re-assign browsing field ID if that is what is being deleted
    if (InterfaceConfiguration::getInstance()->getInt("BrowsingFieldId") == $Field->Id()) {
        $Schema = new MetadataSchema();
        $TreeFields = $Schema->GetFields(MetadataSchema::MDFTYPE_TREE);

        # remove the field to be deleted from the list
        unset($TreeFields[$Field->Id()]);

        return count($TreeFields) < 1;
    }

    return false;
}

# ----- MAIN -----------------------------------------------------------------

global $Field;
global $IsFinalTreeField;

PageTitle("Confirm Metadata Field Deletion");

# make sure the user can access this page
if (!CheckAuthorization(PRIV_SYSADMIN, PRIV_COLLECTIONADMIN)) {
    return;
}

$Schema = new MetadataSchema();
$Field = new MetadataField(StdLib::getArrayValue($_GET, "Id"));
$IsFinalTreeField = IsFinalTreeField($Field);

# invalid field, go to the main page
if ($Field->Status() != MetadataSchema::MDFSTAT_OK) {
    $AF->SetJumpToPage("DBEditor");
    return;
}
