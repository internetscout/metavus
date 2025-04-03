<?PHP
#
#   FILE:  EditClassifications.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2013-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus;
use Exception;
use ScoutLib\StdLib;

# ----- LOCAL FUNCTIONS ------------------------------------------------------

/**
* Get the metadata schema to use.
* @param Classification $Parent Optional parent to use as context.
* @return MetadataSchema Returns a metadata schema object.
*/
function GetSchema(?Classification $Parent = null) : MetadataSchema
{
    # give priority to the parent ID
    if (!is_null($Parent)) {
        $Field = MetadataField::getField($Parent->FieldId());

        if ($Field->Status() === MetadataSchema::MDFSTAT_OK) {
            return new MetadataSchema($Field->SchemaId());
        }
    }

    # use the schema ID from the URL or the default schema if not given
    $SchemaId = StdLib::getArrayValue($_GET, "SC", MetadataSchema::SCHEMAID_DEFAULT);
    return new MetadataSchema($SchemaId);
}

/**
* Get the metadata field to use.
* @param Classification $Parent Optional parent to use as context.
* @param MetadataSchema $Schema Optional schema to use for context.
* @return ?MetadataField Returns a metadata field or NULL if one couldn't be retrieved.
*/
function GetField(
    ?Classification $Parent = null,
    ?MetadataSchema $Schema = null
) : ?MetadataField {
    # give priority to the parent ID
    if (!is_null($Parent)) {
        $Field = MetadataField::getField($Parent->FieldId());

        if ($Field->Status() === MetadataSchema::MDFSTAT_OK) {
            return $Field;
        }
    }

    # try to get the field ID from the URL
    $FieldId = StdLib::getArrayValue($_GET, "FieldId");

    # a field ID was given in the URL
    if (isset($FieldId)) {
        $Field = MetadataField::getField($FieldId);

        if ($Field->Status() === MetadataSchema::MDFSTAT_OK) {
            return $Field;
        }
    }

    # try to use the system configuration if using the default metadata schema
    $IntConfig = InterfaceConfiguration::getInstance();
    if ($Schema->Id() === MetadataSchema::SCHEMAID_DEFAULT) {
        $Field = $Schema->GetField($IntConfig->getInt("BrowsingFieldId"));

        # return the field ID if the field exists and is okay
        if ($Field instanceof MetadataField
            && $Field->Status() === MetadataSchema::MDFSTAT_OK) {
            return $Field;
        }
    }

    # get the tree fields in alphabetical order
    $TreeFields = $Schema->GetFields(
        MetadataSchema::MDFTYPE_TREE,
        MetadataSchema::MDFORDER_ALPHABETICAL
    );

    # return the first tree field alphabetically if there are any tree fields or
    # return NULL
    return count($TreeFields) ? array_shift($TreeFields) : null;
}

/**
* Get all of the classifications for the given parent classification or the
* given metadata field to use for fetching the top-level classifications failing
* that.
* @param Classification $Parent The parent classification.
* @param MetadataField $Field The field to use if the parent isn't given.
* @param string $SearchParams Search parameter string.
* @return array Returns an array of classifications when there are less than
* NumClassesPerBrowsePage of them, and an array of arrays of
* classifications (one per alphabet letter) otherwise.
*/
function GetClassifications(
    ?Classification $Parent = null,
    ?MetadataField $Field = null,
    $SearchParams = null
) : array {
    $CFactory = new ClassificationFactory(
        ($Field !== null) ? $Field->Id() : null
    );

    # if a search was performed
    if ($SearchParams !== null) {
        $MatchingClassifications = $CFactory->GetItemNames(
            "ClassificationName LIKE '%".addslashes($SearchParams) ."%'"
        );

        # if we specified a parent, subset our matches to just cover the children
        #   of that parent
        # NB: we cannot use ParentId for this because it won't catch
        #   ((great )*grand-)?children
        if ($Parent !== null) {
            $MatchingClassifications = array_intersect_key(
                $MatchingClassifications,
                array_flip($CFactory->GetChildIds($Parent->Id()))
            );
        }
    } else {
        # if there was no search and we have no parent, get everything at depth zero
        # otherwise, get all the direct descendents of our parent
        $MatchingClassifications = $CFactory->GetItemNames(
            ($Parent === null) ? "Depth = 0" : "ParentId = ".$Parent->Id()
        );
    }

    # determine if we have enough classifications that we should partition them
    if (count($MatchingClassifications) >
        InterfaceConfiguration::getInstance()->getInt("NumClassesPerBrowsePage")) {
        # if we need a partition, divide into buckets by starting letter
        $Classifications = array();
        foreach ($MatchingClassifications as $ClassId => $ClassName) {
            $Index = strtolower(substr($ClassName, 0, 1));
            if (!preg_match("/[a-z]/", $Index)) {
                $Index = "XX";
            }
            $Classifications[$Index][] = $ClassId;
        }

        $Classifications["XX-IsPartitioned-XX"] = true;
    } else {
        $Classifications = array_keys($MatchingClassifications);
    }

    return $Classifications;
}

# ----- MAIN -----------------------------------------------------------------

global $H_Schema;
global $H_Field;
global $H_Parent;
global $H_Classifications;
global $H_ClassificationsAll;
global $H_ClassificationCount;
global $H_StartLetter;

global $H_Errors;

PageTitle("Add/Edit Classifications");

# check if current user is authorized to edit classifications
if (!CheckAuthorization(PRIV_SYSADMIN, PRIV_CLASSADMIN)) {
    return;
}

$H_Errors = array();

# extract needed variables from the GET parameters
$ParentId = StdLib::getFormValue("ParentId", Classification::NOPARENT);
$H_Parent = ($ParentId == Classification::NOPARENT) ? null
        : new Classification($ParentId);
$H_SearchQuery = (isset($_GET["SQ"]) && strlen(trim($_GET["SQ"]))) ?
    trim($_GET["SQ"]) : null;
$H_StartLetter = isset($_GET["SL"]) ? strtolower($_GET["SL"]) : null ;
try {
    $H_Schema = GetSchema($H_Parent);
    $H_Field = GetField($H_Parent, $H_Schema);
} catch (Exception $e) {
    $H_Errors[] = "Invalid parameters specified";
}

# if there were errors in the parameters, stop processing
if (count($H_Errors)) {
    return;
}

# extract classifications for the specified parent, field, and search terms
$H_ClassificationsAll = GetClassifications($H_Parent, $H_Field, $H_SearchQuery);

$H_Classifications = array();
if (isset($H_ClassificationsAll["XX-IsPartitioned-XX"])) {
    # unset the Partitioned flag; we don't want to treat it as a classification
    unset($H_ClassificationsAll["XX-IsPartitioned-XX"]);

    # count the total number of classifications
    $H_ClassificationCount = 0;
    foreach ($H_ClassificationsAll as $ClassBin) {
        $H_ClassificationCount += count($ClassBin);
    }

    # determine which to show
    # if we were provided a StartLetter and have any classifications in that bucket,
    # use it otherwise, use the first non-empty bucket
    $Index = ($H_StartLetter !== null && isset($H_ClassificationsAll[$H_StartLetter])) ?
        $H_StartLetter : current(array_keys($H_ClassificationsAll));

    # extract the classifications for the selected bucket
    foreach ($H_ClassificationsAll[$Index] as $ClassificationId) {
        $H_Classifications[] = new Classification($ClassificationId);
    }
} else {
    $H_ClassificationCount = count($H_ClassificationsAll);
    foreach ($H_ClassificationsAll as $ClassificationId) {
        $H_Classifications[] = new Classification($ClassificationId);
    }
}
