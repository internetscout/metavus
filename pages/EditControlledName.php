<?PHP
#
#   FILE:  EditControlledName.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2011-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

use Metavus\ControlledName;
use Metavus\ControlledNameFactory;
use Metavus\MetadataField;
use Metavus\MetadataSchema;
use Metavus\Record;
use Metavus\User;
use ScoutLib\StdLib;
use ScoutLib\ApplicationFramework;


# ----- MAIN -----------------------------------------------------------------

$AF = ApplicationFramework::getInstance();
$AF->setPageTitle("Edit Controlled Names");

if (!User::requirePrivilege(PRIV_NAMEADMIN)) {
    return;
}

if (isset($_POST["Submit"]) && $_POST["Submit"] == "Cancel" && !isset($_POST["F_ReturnToECN"])) {
    $AF->setJumpToPage("MDHome");
    return;
}

# get the schema ID or use the default one if not specified
$SchemaId = StdLib::getFormValue("SC", MetadataSchema::SCHEMAID_DEFAULT);
$H_ControlledName = StdLib::getFormValue("F_ControlledName", "");

$Submit = StdLib::getFormValue("Submit", "");

$H_RecordsPerPage = StdLib::getFormValue("F_RecordsPerPage", 10);

$H_Schema = new MetadataSchema($SchemaId);

$H_SavedChanges = false;

# if here by updating records per page, set page to zero
if (isset($_POST["F_UpdatePerPage"])) {
    $Submit = "Search ".$_POST["F_FieldName"];
    $H_StartRec = 0;
}

switch ($Submit) {
    case ">":
        $Submit = "Search ".$_POST["F_FieldName"];
        $H_StartRec = $_POST["F_StartRec"] + $H_RecordsPerPage;
        break;

    case "<":
        $Submit = "Search ".$_POST["F_FieldName"];
        $H_StartRec = max(0, $_POST["F_StartRec"] - $H_RecordsPerPage);
        break;

    case "Save Changes":
        $Submit = "Search ".$_POST["F_FieldName"];
        $H_StartRec = $_POST["F_StartRec"];

        $H_ModifiedCNames = [];
        $H_DeletedCNames = [];
        $H_ModifiedResources = [];

        $AffectedResourceIds = [];

        # iterate over the controlled names in our form
        for ($i = 0; array_key_exists('F_ControlledNameId_'.$i, $_POST); $i++) {
            $ControlledNameId = StdLib::getArrayValue($_POST, 'F_ControlledNameId_'.$i);
            $ControlledName = trim(StdLib::getArrayValue($_POST, 'F_ControlledName_'.$i));
            $QualifierId = StdLib::getArrayValue($_POST, 'F_QualifierName_'.$i);
            $VariantName = StdLib::getArrayValue($_POST, 'F_VariantName_'.$i);
            $Remap = array_filter(StdLib::getArrayValue($_POST, 'D_Remap_'.$i, []));
            $Delete = StdLib::getArrayValue($_POST, 'F_Delete_'.$i, false);

            # update the values for each controlled name
            if (!empty($ControlledName)) {
                # pull out specified CName
                $CN = new ControlledName($ControlledNameId);

                if ($Delete) {
                    # handle CName deletion
                    $H_DeletedCNames[$CN->name()] = $CN->variantName();

                    $AffectedResourceIds = array_merge(
                        $AffectedResourceIds,
                        $CN->getAssociatedResources()
                    );

                    $CN->destroy(true);
                } else {
                    # if the user requested a remap
                    if (count($Remap) > 0) {
                        # pull out the Id of the target cname
                        $OtherId = reset($Remap);

                        # if the id is valid, perform a remapping
                        $CNFact = new ControlledNameFactory($CN->fieldId());
                        if ($CNFact->itemExists($OtherId)) {
                            # save resources as affected before remapping,
                            # because after remapping, resources are moved
                            # and GetAssociatedResources returns empty
                            $AffectedResourceIds = array_merge(
                                $AffectedResourceIds,
                                $CN->getAssociatedResources()
                            );

                            # perform the remapping
                            $CN->remapTo($OtherId);
                        }
                    } else {
                        # assume changeless until proven guilty
                        $Modified = false;

                        # handle name changes
                        if ($CN->name() != $ControlledName) {
                            $Modified = true;
                            $CN->name($ControlledName);
                        }

                        # handle qualifier changes
                        if (!empty($QualifierId)) {
                            if ($CN->qualifierId() != $QualifierId) {
                                $Modified = true;
                                $CN->qualifierId($QualifierId);
                            }
                        }

                        # handle variant changes
                        if ($CN->variantName() != $VariantName) {
                            $Modified = true;
                            # if user submitted empty variant name,
                            # clear variant name by passing false
                            $CN->variantName(
                                strlen($VariantName) > 0 ? $VariantName : false
                            );
                        }

                        # if this CName was modified, add it to our list of changed names
                        # and gather its list of ResourceIds.
                        if ($Modified) {
                            $H_ModifiedCNames[$CN->name()] = $CN->variantName();
                            $AffectedResourceIds = array_merge(
                                $AffectedResourceIds,
                                $CN->getAssociatedResources()
                            );
                        }
                    }
                }
            }
        }

        # iterate over all the affected resources, handle their
        # autoupdated fields, and queue search engine updates
        $AffectedResourceIds = array_unique($AffectedResourceIds);
        foreach ($AffectedResourceIds as $ResourceId) {
            $Resource = new Record($ResourceId);
            $Resource->updateAutoupdateFields(
                MetadataField::UPDATEMETHOD_ONRECORDCHANGE,
                User::getCurrentUser()
            );

            # update search and recommender DBs if configured to do so
            $Resource->queueSearchAndRecommenderUpdate();

            # signal the modified event
            $AF->signalEvent(
                "EVENT_RESOURCE_MODIFY",
                array("Resource" => $Resource)
            );

            $H_ModifiedResources[] =
                "<a href=\"index.php?P=FullRecord&amp;ID=".
                $Resource->id()."\" target=\"_blank\">".
                $Resource->getMapped("Title")."</a><br>";
        }
        $H_SavedChanges = true;
        break;

    case "Cancel":
        $AF->setJumpToPage("index.php?P=EditControlledName&SC=".$SchemaId);
        return;

    default:
        $H_StartRec = 0;
        break;
}

if (preg_match("/^Search (.*)/", $Submit, $Matches)) {
    $H_Field = $H_Schema->getField($Matches[1]);
    $CNFact = new ControlledNameFactory($H_Field->id());

    # if the F_ControlledName is empty (because the user just pushed
    # "search"), then return all CNames in the given field
    $SearchString = strlen($H_ControlledName) ? $H_ControlledName : "*";
    $H_MatchingControlledNames = $CNFact->controlledNameSearch($SearchString);
    $H_NumResults = count($H_MatchingControlledNames);
    $H_SearchEntered = true;
} else {
    $H_MatchingControlledNames = [];
    $H_NumResults = 0;
    $H_SearchEntered = false;
}
