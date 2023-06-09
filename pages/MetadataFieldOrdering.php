<?PHP
#
#   FILE:  MetadataFieldOrdering.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2012-2020 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#

use Metavus\MetadataFieldGroup;
use Metavus\MetadataFieldOrder;
use Metavus\MetadataSchema;
use ScoutLib\ApplicationFramework;
use ScoutLib\StdLib;

# ----- EXPORTED FUNCTIONS ---------------------------------------------------

const TYPE_GROUP = 0;
const TYPE_FIELD = 1;

/**
 * Get Tree with structure to display fields/groups
 * @param MetadataFieldOrder $Order containing fields and groups to display
 * @return array representing structure in which to display the fields/groups
 */
function getTree(MetadataFieldOrder $Order)
{
    $Items = $Order->getItems();
    $Tree = [];
    foreach ($Items as $Index => $Item) {
        if ($Item instanceof Metavus\MetadataField) {
            $Tree[$Index]["Id"] = $Item->id();
            $Tree[$Index]["Label"] = $Item->getDisplayName();
            $Tree[$Index]["Children"] = [];
            $Tree[$Index]["Type"] = TYPE_FIELD;
        } elseif ($Item instanceof MetadataFieldGroup) {
            $Tree[$Index]["Id"] = $Item->Id();
            $Tree[$Index]["Label"] = $Item->name();
            $Tree[$Index]["Children"] = [];
            foreach ($Item->GetFields() as $Field) {
                $Tree[$Index]["Children"][] = [
                    "Label" => $Field->getDisplayName(),
                    "Type" => TYPE_FIELD,
                    "Id" => $Field->id()
                ];
            }
            $Tree[$Index]["Type"] = TYPE_GROUP;
        }
    }
    return $Tree;
}

# ----- LOCAL FUNCTIONS ------------------------------------------------------

# ----- MAIN -----------------------------------------------------------------

PageTitle("Metadata Field Ordering");
CheckAuthorization(PRIV_SYSADMIN, PRIV_COLLECTIONADMIN);

# get the schema ID or use the default one if not specified
$SchemaId = StdLib::getArrayValue(
    $_GET,
    "SC",
    StdLib::getFormValue("F_SchemaId", MetadataSchema::SCHEMAID_DEFAULT)
);

# construct the metadata schema
$H_Schema = new MetadataSchema($SchemaId);

$H_GroupIdOffset = $H_Schema->getHighestFieldId() + 100;

# construct the order objects
$H_DisplayOrder = $H_Schema->GetDisplayOrder();
$H_EditOrder = $H_Schema->GetEditOrder();

# in case a bug results in any issues, try to fix them
$H_DisplayOrder->MendIssues();
$H_EditOrder->MendIssues();

$H_ButtonPushed = StdLib::getFormValue("Submit");

if ($H_ButtonPushed == "AddGroup") {
    # get values from the user
    $GroupName = StdLib::getFormValue("F_GroupName");
    $Ordering = StdLib::getFormValue("F_Ordering");

    if ($GroupName && ($Ordering == "Display" || $Ordering == "Edit")) {
        $Order = $Ordering == "Display" ? $H_DisplayOrder : $H_EditOrder;
        $Order->CreateGroup($GroupName);
    }
    # change pushed button to "Save" so we save changes
    $H_ButtonPushed = "Save";
} elseif (preg_match("[^DeleteGroup]", (string)$H_ButtonPushed)) {
    # get values from the user, index 0 contains "DeleteGroup"
    # used in the preg_match above to activate this code
    $Ids = explode(",", $H_ButtonPushed);
    $GroupId = $Ids[1];
    $OrderId = $Ids[2];

    if ($OrderId && $GroupId) {
        $Order = new MetadataFieldOrder($OrderId);
        $Group = new MetadataFieldGroup($GroupId);

        $Order->DeleteGroup($Group);
    }
} elseif ($H_ButtonPushed == "EditGroupName") {
    # get values from the user
    $GroupId = StdLib::getFormValue("F_GroupId");
    $GroupName = StdLib::getFormValue("F_GroupName");

    if ($GroupId && $GroupName) {
        $Group = new MetadataFieldGroup($GroupId);
        $Group->name($GroupName);
    }
}
# outside of elseif chain so you can save after adding without abstraction
if ($H_ButtonPushed == "Save") {
    # get orders from hidden input, generated by nestedSortable::serialize()
    parse_str(StdLib::getFormValue("editOrder"), $EditOrder);
    parse_str(StdLib::getFormValue("displayOrder"), $DisplayOrder);
    # save new order
    $H_EditOrder->reorder($EditOrder["edit-item"], $H_GroupIdOffset);
    $H_DisplayOrder->reorder($DisplayOrder["display-item"], $H_GroupIdOffset);

    # clear page cache so changed ordering will take effect
    ApplicationFramework::getInstance()->clearPageCache();
}