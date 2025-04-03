<?PHP
#
#   FILE:  AdvancedSearch.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2011-2024 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus;

use Exception;
use ScoutLib\ApplicationFramework;
use ScoutLib\DataCache;

# how long to save data in the per-page cache
const CACHE_TTL = 3600;

$AF = ApplicationFramework::getInstance();

# request that this page not be indexed by search engines
$AF->addMetaTag(["robots" => "noindex"]);

# ----- EXPORTED FUNCTIONS ---------------------------------------------------

/**
 * Get a DataCache for this page.
 * @return DataCache Cache
 */
function getCache(): DataCache
{
    static $Cache = false;
    if ($Cache === false) {
        $User = User::getCurrentUser();
        $KeySuffix = "-".($User->id() ?? "NONE")."-";

        $Cache = new DataCache("P-AdvancedSearch".$KeySuffix);
    }

    return $Cache;
}

/**
 * Get the list of possible values for a searching field. For User fields,
 * these will be all values that are assigned to a record. For Tree fields,
 * this will be all values less than the configured maximum depth for the
 * field. For other fields types, these will be all possible values.
 * @param MetadataField $Field Field for which values are desired
 * @return array of possible values (Id => Name)
*/
function determinePossibleValues(MetadataField $Field): array
{
    $Cache = getCache();
    $CacheName = str_replace('\\', '_', __FUNCTION__);
    $CacheData = $Cache->get($CacheName) ?? [];

    $CacheKey = $Field->id();
    if (array_key_exists($CacheKey, $CacheData)) {
        return $CacheData[$CacheKey];
    }

    if ($Field->type() == MetadataSchema::MDFTYPE_USER) {
        $PossibleValues = $Field->getValuesInUse();
    } elseif ($Field->Type() == MetadataSchema::MDFTYPE_TREE) {
        $MaxDepth = $Field->MaxDepthForAdvancedSearch();
        $AllValues = $Field->GetPossibleValues();

        $PossibleValues = array();
        foreach ($AllValues as $ClassId => $ClassName) {
            if (count(explode(" -- ", $ClassName)) <= $MaxDepth) {
                $PossibleValues[$ClassId] = $ClassName;
            }
        }
    } else {
        # otherwise GetPossibleValues is our friend
        $PossibleValues = $Field->GetPossibleValues();
    }

    $CacheData[$CacheKey] = $PossibleValues;
    $Cache->set($CacheName, $CacheData, CACHE_TTL);

    return $PossibleValues;
}

/**
* Determine which values on a list of candidates should be disabled
* @param MetadataField $Field Metadata field to use
* @param array $PossibleValues Values to consider
* @return array with keys giving disabled value ids.
*/
function determineDisabledValues(MetadataField $Field, array $PossibleValues): array
{
    static $Factories;

    # user fields and trees don't support disabled values
    if ($Field->Type() == MetadataSchema::MDFTYPE_USER ||
        $Field->Type() == MetadataSchema::MDFTYPE_TREE) {
        return [];
    }

    $Cache = getCache();
    $CacheName = str_replace('\\', '_', __FUNCTION__);
    $CacheData = $Cache->get($CacheName) ?? [];

    $CacheKey = $Field->id();
    if (array_key_exists($CacheKey, $CacheData)) {
        return $CacheData[$CacheKey];
    }

    # retrieve user currently logged in
    $User = User::getCurrentUser();

    $SchemaId = $Field->SchemaId();
    $Schema = new MetadataSchema($SchemaId);

    if (!isset($Factories[$SchemaId])) {
        $Factories[$SchemaId] = new RecordFactory($SchemaId);
    }

    $DisabledValues = array();

    if ($Field->Type() == MetadataSchema::MDFTYPE_FLAG) {
        foreach ($PossibleValues as $ValueId => $Value) {
            $ResourceIds = $Factories[$SchemaId]->getIdsOfMatchingRecords(
                [$Field->Id() => $ValueId]
            );
            $ResourceIds = $Factories[$SchemaId]->filterOutUnviewableRecords(
                $ResourceIds,
                $User
            );
            if (count($ResourceIds) == 0) {
                $DislabedValues[$ValueId] = 1;
            }
        }
    } else {
        foreach ($PossibleValues as $ValueId => $Value) {
            if ($Factories[$SchemaId]->associatedVisibleRecordCount(
                $ValueId,
                $User
            ) == 0) {
                $DisabledValues[$ValueId] = 1;
            }
        }
    }

    $CacheData[$CacheKey] = $DisabledValues;
    $Cache->set($CacheName, $CacheData, CACHE_TTL);

    return $DisabledValues;
}

/**
* Convert value names to identifiers
* @param MetadataField $Field Field to use
* @param array $Values Values to convert
* @return array of converted values
*/
function convertValueNamesToIds(MetadataField $Field, array $Values): array
{
    switch ($Field->Type()) {
        case MetadataSchema::MDFTYPE_USER:
            $RetVal = array();
            $UserFactory = new UserFactory();

            foreach ($Values as $UserName) {
                # if we have a leading equals sign
                if (strlen($UserName) && $UserName[0] == "=") {
                    # try to look up this user, add them if found
                    $UserName = substr($UserName, 1);
                    if ($UserFactory->userNameExists($UserName)) {
                        $User = new User($UserName);
                        $RetVal[] = $User->Id();
                    }
                }
            }
            break;

        case MetadataSchema::MDFTYPE_CONTROLLEDNAME:
        case MetadataSchema::MDFTYPE_OPTION:
            $RetVal = array();

            $Factory = $Field->GetFactory();
            foreach ($Values as $Value) {
                # if we have a leading equals sign
                if (strlen($Value) && $Value[0] == "=") {
                    # try to look up this value, add it if found
                    $Value = substr($Value, 1);
                    $Id = $Factory->GetItemIdByName($Value);
                    if ($Id !== false) {
                        $RetVal[] = $Id;
                    }
                }
            }
            break;

        case MetadataSchema::MDFTYPE_TREE:
            $RetVal = array();

            $Factory = $Field->GetFactory();
            foreach ($Values as $Value) {
                # if this is an 'is' or an 'is under' query
                if (strlen($Value) && ($Value[0] == "=" || $Value[0] == "^")) {
                    # remove operator from target value
                    $TgtVal = substr($Value, 1);

                    # remove trailing -- from 'is under' queries
                    if ($Value[0] == "^") {
                        $TgtVal = preg_replace('% -- ?$%', '', $TgtVal);
                    }

                    # try to look up this value, add it if found
                    $Id = $Factory->GetItemIdByName($TgtVal);
                    if ($Id !== false) {
                        $RetVal[] = $Id;
                    }
                }
            }
            break;

        case MetadataSchema::MDFTYPE_FLAG:
            $RetVal = array();

            foreach ($Values as $Value) {
                if (strlen($Value) && $Value[0] == "=") {
                    $Value = substr($Value, 1);
                    $RetVal[] = $Value;
                }
            }
            break;

        default:
            $RetVal = $Values;
            break;
    }

    return $RetVal;
}

/**
 * Get the list of fields that use a field selection widget and a text entry.
 * @param array $AllSchemas All metadata schemas.
 * @param int $TextFieldTypes Bitmask of field types to include.
 * @param User $User User accessing the page.
 * @return array Array formatted for HtmlOptionList.
 */
function getTextFieldList(
    array $AllSchemas,
    int $TextFieldTypes,
    User $User
) : array {

    $Cache = getCache();
    $CacheName = str_replace('\\', '_', __FUNCTION__);
    $CacheData = $Cache->get($CacheName) ?? [];

    $CacheKey = $TextFieldTypes;
    if (array_key_exists($CacheKey, $CacheData)) {
        return $CacheData[$CacheKey];
    }

    $TextFieldsBySchema = [];
    $AllTextFields = [];
    foreach ($AllSchemas as $SchemaId => $Schema) {
        $Fields = $Schema->getFields($TextFieldTypes, MetadataSchema::MDFORDER_DISPLAY);
        foreach ($Fields as $FieldId => $Field) {
            if ($Field->Enabled() &&
                $Field->IncludeInAdvancedSearch() &&
                $Field->UserCanView($User)) {
                if ($Field->Type() == MetadataSchema::MDFTYPE_TREE &&
                    $Field->DisplayAsListForAdvancedSearch()) {
                    continue;
                }
                $DisplayName = $Field->getDisplayName();
                $TextFieldsBySchema[$Schema->name()][$FieldId] = $DisplayName;
                $AllTextFields[$DisplayName][] = $FieldId;
            }
        }
    }

    # construct the list of fields that will have text forms, starting with
    # Keyword
    $Result = [
        "KEYWORD" => "Keyword",
    ];

    # then adding all the fields that appear in several schemas
    foreach ($AllTextFields as $DisplayName => $FieldIds) {
        if (count($FieldIds) > 1) {
            $Key = implode("-", $FieldIds);
            $Result[$Key] = $DisplayName;
        }
    }

    # and then adding option groups for the fields that are in only one schema
    foreach ($TextFieldsBySchema as $SchemaName => $Fields) {
        $FilteredFields = array_filter(
            $Fields,
            function ($DisplayName) use ($AllTextFields) {
                return count($AllTextFields[$DisplayName]) < 2;
            }
        );

        if (count($FilteredFields)) {
            $Result[$SchemaName] = $FilteredFields;
        }
    }

    $CacheData[$CacheKey] = $Result;
    $Cache->set($CacheName, $CacheData, CACHE_TTL);

    return $Result;
}

/**
 * Get the list of text search fields that we will display.
 * @param User $User Current user.
 * @param SearchParameterSet $SearchParams Current search.
 * @param array $FieldsWithTextForms List of text field options.
 * @return array Field IDs for selected fields
 */
function getSelectedTextFieldList(
    User $User,
    SearchParameterSet $SearchParams,
    array $FieldsWithTextForms
) : array {
    $Result = [];

    # if a user is logged in, and we're not loading a saved search,
    #  and we're not refining an existing search, then we want to
    #  load the user's saved search selections
    if ($User->IsLoggedIn() &&
        !isset($_GET["ID"]) && !isset($_GET["RF"])) {
        $FieldData = $User->get("SearchSelections");
        if (strlen($FieldData)) {
            $Result = unserialize($FieldData);

            # filter out invalid fields
            $Result = array_filter(
                $Result,
                function ($FieldId) {
                    return MetadataSchema::fieldExistsInAnySchema($FieldId);
                }
            );
        }
    }

    # list of fields not yet selected
    $RemainingFields = [];

    # if a search was specified
    if ($SearchParams->parameterCount()) {
        # get the list of non-keyword fields in this search
        $FieldsInSearch = $SearchParams->getFields();

        # if this includes keywords, then add that as well
        if (count($SearchParams->getKeywordSearchStrings())) {
            $FieldsInSearch[] = "KEYWORD";
        }

        # iterate over the fields that have text forms, adding those
        # for which we have a value to the list of selected fields
        foreach ($FieldsWithTextForms as $FieldIds => $DisplayName) {
            # start off assuming we don't have a value for this field
            $FoundInSearch = false;

            # if this is a field group
            if (is_array($DisplayName)) {
                # check each field in the group
                foreach (array_keys($DisplayName) as $FieldId) {
                    if (in_array($FieldId, $FieldsInSearch)) {
                        $Result[] = $FieldId;
                    } else {
                        $RemainingFields[] = $FieldId;
                    }
                }
            } else {
                # iterate over the fields for this selection, checking if
                # we have a value for any of them
                foreach (explode("-", $FieldIds) as $FieldId) {
                    if (in_array($FieldId, $FieldsInSearch)) {
                        $FoundInSearch = true;
                        break;
                    }
                }

                # if we did find a value, select this field
                # otherwise, add it to the list of empty fields
                if ($FoundInSearch) {
                    $Result[] = $FieldIds;
                } else {
                    $RemainingFields[] = $FieldIds;
                }
            }
        }
    } else {
        # if no search, then all fields are not yet selected
        foreach ($FieldsWithTextForms as $FieldIds => $DisplayName) {
            # if this is a field group, add each field in the group
            if (is_array($DisplayName)) {
                foreach (array_keys($DisplayName) as $FieldId) {
                    $RemainingFields[] = $FieldId;
                }
            } else {
                $RemainingFields[] = $FieldIds;
            }
        }
    }

    # be sure we display at least four fields
    while (count($Result) < 4) {
        $Result[] = array_shift($RemainingFields);
    }

    return $Result;
}

/**
 * Get the list of fields that should be displayed as search limits.
 * @param array $AllSchemas All metadata schemas.
 * @param array $SearchLimitTypes Array of field types to include.
 * @param User $User User accessing the page.
 * @return array Array formatted for HtmlOptionList.
 */
function getSearchLimitList(
    array $AllSchemas,
    array $SearchLimitTypes,
    User $User
) : array {
    $Cache = getCache();
    $CacheName = str_replace('\\', '_', __FUNCTION__);
    $CacheData = $Cache->get($CacheName) ?? [];

    $CacheKey = md5(implode("-", $SearchLimitTypes));
    if (array_key_exists($CacheKey, $CacheData)) {
        return $CacheData[$CacheKey];
    }

    $Result = [];

    foreach ($AllSchemas as $SchemaId => $Schema) {
        # iterate over candidate fields, including those that belong
        foreach ($SearchLimitTypes as $LimitType) {
            $Fields = $Schema->getFields(
                $LimitType,
                MetadataSchema::MDFORDER_DISPLAY
            );

            foreach ($Fields as $FieldId => $Field) {
                if ($Field->enabled() &&
                    $Field->includeInAdvancedSearch() &&
                    $Field->userCanView($User)) {
                    if ($Field->type() == MetadataSchema::MDFTYPE_TREE &&
                        !$Field->displayAsListForAdvancedSearch()) {
                        continue;
                    }

                    $Result[$SchemaId][$FieldId] = $Field->getDisplayName();
                }
            }
        }
    }

    $CacheData[$CacheKey] = $Result;
    $Cache->set($CacheName, $CacheData, CACHE_TTL);

    return $Result;
}


/**
 * Get the list of fields to display as sort options.
 * @param int $SortFieldTypes Bitmask of field types to include.
 * @param User $User User accessing the page.
 * @return array Array formatted for HtmlOptionList.
 */
function getSortOptions(int $SortFieldTypes, User $User): array
{
    $Cache = getCache();
    $CacheName = str_replace('\\', '_', __FUNCTION__);
    $CacheData = $Cache->get($CacheName) ?? [];

    $CacheKey = $SortFieldTypes;
    if (array_key_exists($CacheKey, $CacheData)) {
        return $CacheData[$CacheKey];
    }

    $Schema = new MetadataSchema(MetadataSchema::SCHEMAID_DEFAULT);

    # construct a list of sort fields
    $Result = array("R" => "Relevance");

    # iterate over candidate fields, including those that belong
    $Fields = $Schema->GetFields(
        $SortFieldTypes,
        MetadataSchema::MDFORDER_DISPLAY
    );
    foreach ($Fields as $FieldId => $Field) {
        if ($Field->Enabled() &&
            $Field->IncludeInSortOptions() &&
            $Field->UserCanView($User)) {
            $Result[$FieldId] = $Field->GetDisplayName();
        }
    }

    $CacheData[$CacheKey] = $Result;
    $Cache->set($CacheName, $CacheData, CACHE_TTL);

    return $Result;
}

# ----- MAIN -----------------------------------------------------------------

$IntConfig = InterfaceConfiguration::getInstance();

# retrieve user currently logged in
$User = User::getCurrentUser();

# check if we have a legacy search URL, redirect
#  to SearchResults page if we do
if (SearchParameterSet::IsLegacyUrl($_SERVER["QUERY_STRING"])) {
    # attempt to convert legacy URL to current format
    try {
        $ConvertedUrl = SearchParameterSet::ConvertLegacyUrl(
            $_SERVER["QUERY_STRING"]
        );
    } catch (Exception $e) {
        # if conversion fails, bounce to AdvancedSearch
        $AF->SetJumpToPage(
            "AdvancedSearch"
        );
        return;
    }
    # othrewise, redirect to search results for the converted URL
    $AF->SetJumpToPage(
        "SearchResults&".$ConvertedUrl
    );
    return;
}

# instantiate error to null
$H_Error = null;

# if errors, get errors
if (isset($_GET["Err"])) {
    $Error = $_GET["Err"];
    switch ($Error) {
        case "E_NOPARAMS":
            $H_Error = "No search parameters set.";
            break;
        default:
            $H_Error = "Unrecognized error.";
            break;
    }
}

$TextFieldTypes =
    MetadataSchema::MDFTYPE_TEXT |
    MetadataSchema::MDFTYPE_PARAGRAPH |
    MetadataSchema::MDFTYPE_CONTROLLEDNAME |
    MetadataSchema::MDFTYPE_NUMBER |
    MetadataSchema::MDFTYPE_FILE |
    MetadataSchema::MDFTYPE_TREE |
    MetadataSchema::MDFTYPE_IMAGE |
    MetadataSchema::MDFTYPE_DATE |
    MetadataSchema::MDFTYPE_TIMESTAMP |
    MetadataSchema::MDFTYPE_URL |
    MetadataSchema::MDFTYPE_REFERENCE ;

$SearchLimitTypes = array(
    MetadataSchema::MDFTYPE_OPTION,
    MetadataSchema::MDFTYPE_TREE,
    MetadataSchema::MDFTYPE_FLAG,
    MetadataSchema::MDFTYPE_USER
);

$SortFieldTypes =
    MetadataSchema::MDFTYPE_TEXT |
    MetadataSchema::MDFTYPE_NUMBER |
    MetadataSchema::MDFTYPE_DATE |
    MetadataSchema::MDFTYPE_TIMESTAMP |
    MetadataSchema::MDFTYPE_URL ;

# construct a list of fields needing a search limit
$H_SearchLimits = array();

$AllSchemas = MetadataSchema::GetAllSchemas();

# extract the names of all schemas
$H_SchemaNames = array();
foreach ($AllSchemas as $SchemaId => $Schema) {
    $H_SchemaNames[$SchemaId] =
            ($SchemaId == MetadataSchema::SCHEMAID_DEFAULT) ?
            "Resource" : $Schema->Name();
}

# generate the list of fields that have text searches
$H_FieldsHavingTextForms = GetTextFieldList(
    $AllSchemas,
    $TextFieldTypes,
    $User
);

# now, on to the search limits
$H_SearchLimits = GetSearchLimitList(
    $AllSchemas,
    $SearchLimitTypes,
    $User
);

$H_SortFields = GetSortOptions($SortFieldTypes, $User);

# use sort field from URL parameters if available and visible,
#   otherwise default to relevance
$DefaultSortField = $AllSchemas[MetadataSchema::SCHEMAID_DEFAULT]->defaultSortField();
$H_SelectedSortField =
    (isset($_GET["SF"]) && array_key_exists($_GET["SF"], $H_SortFields)) ?
    $_GET["SF"] : ($DefaultSortField === false ? "R" : $DefaultSortField);

# extract records per page setting
$H_RecordsPerPage = isset($_GET["RP"]) ? intval($_GET["RP"]) : null;

# determine saved searches to list and RecordsPerPage preference (if any)
if ($User->IsLoggedIn()) {
    $SSFactory = new SavedSearchFactory();
    $H_SavedSearches = $SSFactory->GetSearchesForUser(
        $User->Id()
    );
    if (is_null($H_RecordsPerPage)) {
        $H_RecordsPerPage = $User->Get("RecordsPerPage");
    }
} else {
    $H_SavedSearches = array();
}

if (is_null($H_RecordsPerPage)) {
    $H_RecordsPerPage = $IntConfig->getInt("DefaultRecordsPerPage");
}

# if there was a saved search specified, we'll want to snag that and
#  extract search information from it
if (isset($_GET["ID"])) {
    $H_SavedSearch = new SavedSearch(intval($_GET["ID"]));
    $H_SearchParameters = $H_SavedSearch->SearchParameters();
} else {
    # otherwise, pull the search information out of the URL
    $H_SearchParameters = new SearchParameterSet();
    try {
        $H_SearchParameters->UrlParameters($_GET);
    } catch (Exception $Ex) {
        # if search params were invalid, dump them and reload page w/o params
        $AF->setJumpToPage("AdvancedSearch");
        return;
    }
}

# if we're refining a search, do not save to the static page cache
if ($H_SearchParameters->parameterCount() > 0) {
    $AF->doNotCacheCurrentPage();
}

# determine which search limits should be open
$OpenLimitSchemas = array();
foreach ($H_SearchParameters->GetFields() as $FieldId) {
    # check if this field even exists
    if (MetadataSchema::FieldExistsInAnySchema($FieldId)) {
        # and if so mark it as open
        $Field = MetadataField::getField($FieldId);
        if (isset($H_SearchLimits[$Field->SchemaId()][$FieldId])) {
            $OpenLimitSchemas[$Field->SchemaId()] = 1;
        }
    }
}
$H_OpenSearchLimits = array_keys($OpenLimitSchemas);

$H_OpenByDefault = $IntConfig->getBool("DisplayLimitsByDefault");

# determine which text fields should be selected
$H_SelectedFields = GetSelectedTextFieldList(
    $User,
    $H_SearchParameters,
    $H_FieldsHavingTextForms
);
