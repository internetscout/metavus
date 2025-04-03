<?PHP
#
#   FILE:  ConfirmDeleteMetadataField.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2012-2023 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus;

use ScoutLib\ApplicationFramework;
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

PageTitle("Confirm Metadata Field Deletion");

$AF = ApplicationFramework::getInstance();

# make sure the user can access this page
if (!CheckAuthorization(PRIV_SYSADMIN, PRIV_COLLECTIONADMIN)) {
    return;
}

$Schema = new MetadataSchema();
$H_Field = MetadataField::getField(StdLib::getArrayValue($_GET, "Id"));
$H_IsFinalTreeField = IsFinalTreeField($H_Field);

# invalid field, go to the main page
if ($H_Field->Status() != MetadataSchema::MDFSTAT_OK) {
    $AF->SetJumpToPage("DBEditor");
    return;
}
