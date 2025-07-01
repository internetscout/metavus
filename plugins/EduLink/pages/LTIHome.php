<?PHP
#
#   FILE:  LTIHome.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2024-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan
#
# Select resources to include in an LTI Deep Linking responses. See docstring
# for EduLink plugin for a description of the request flow.
#
#  VALUES PROVIDED to INTERFACE (REQUIRED):
#   $H_LaunchId - Launch Id for the current request
#   $H_SelectedRecordIds - IDs of currently selected records
#   $H_SearchResults - Records that match the current search
#   $H_SearchParams - Current set of search parameters
#   $H_FacetUI - SearchFacetUI to display facets
#   $H_TransportUI - TransportControlsUI for pagination
#   $H_BaseLink - Base link for the current page

namespace Metavus;
use Exception;
use Metavus\Plugins\EduLink;
use Metavus\Plugins\EduLink\ResourceSelectionUI;
use Metavus\Plugins\MetricsRecorder;
use Metavus\Plugins\UrlChecker;
use ScoutLib\ApplicationFramework;
use ScoutLib\Database;
use ScoutLib\HtmlOptionList;
use ScoutLib\StdLib;

/**
 * Get search results, using plugin cache and filtering for records that can
 *     be displayed in an LMS.
 * @param SearchParameterSet $SearchParams Search Parameters.
 * @return array IDs of records that match SearchParams, restricted to those
 *     records that are visible to the public and have a URL or associated file
 *     suitable for embedding in an LMS
 */
function getSearchResults(SearchParameterSet $SearchParams): array
{
    $Plugin = EduLink::getInstance();

    $RecordIds = $Plugin->getCachedSearchResults($SearchParams);
    if (!is_null($RecordIds)) {
        return $RecordIds;
    }

    # perform search
    $Engine = new SearchEngine();
    $SearchResults = $Engine->search($SearchParams);
    $RecordIds = array_keys($SearchResults);

    # filter results for visibility
    $RFactory = new RecordFactory(MetadataSchema::SCHEMAID_DEFAULT);
    $RecordIds = $RFactory->filterOutUnviewableRecords(
        $RecordIds,
        User::getAnonymousUser()
    );

    # filter out temp records (ID < 0)
    $RecordIds = array_filter(
        $RecordIds,
        function ($Id) {
            return $Id >= 0;
        }
    );

    # ensure that records we offer for embedding in an iframe have good URLs
    if (count($RecordIds) > 0) {
        $RecordIds = $Plugin->filterOutRecordsWithUnusableUrls($RecordIds);
    }

    $Plugin->cacheSearchResults(
        $SearchParams,
        $RecordIds
    );

    return $RecordIds;
}


# ----- MAIN -----------------------------------------------------------------

$AF = ApplicationFramework::getInstance();
$Schema = new MetadataSchema(MetadataSchema::SCHEMAID_DEFAULT);

$Plugin = EduLink::getInstance();

$H_LaunchId = $_GET["L"];

# get any current selections
$H_SelectedRecordIds = ResourceSelectionUI::getCurrentSelections();

# if a selection was made, handle the selection
$ButtonPushed = StdLib::getFormValue("Submit");
if ($ButtonPushed == "Select") {
    $Launch = $Plugin->getCachedLaunch($H_LaunchId);
    $DeepLink = $Launch->get_deep_link();

    if (count($H_SelectedRecordIds) == 1) {
        $RecordId = (int)reset($H_SelectedRecordIds);

        $LinkUrl = $AF->baseUrl()."lti/dl_r/v1/".$RecordId;

        $Title = (new Record($RecordId))->getMapped("Title") ;

        $Reply = \IMSGlobal\LTI\LTI_Deep_Link_Resource::new()
            ->set_title($Title)
            ->set_url($LinkUrl);
    } else {
        $Data = $Plugin->encodeRecordList(
            $H_SelectedRecordIds
        );
        $LinkUrl = $AF->baseUrl()."lti/dl_l/".$Data;

        $Reply = \IMSGlobal\LTI\LTI_Deep_Link_Resource::new()
            ->set_title("Resource List")
            ->set_url($LinkUrl);
    }

    $Plugin->recordRecordSelection($H_LaunchId, $H_SelectedRecordIds);

    $AF->suppressHtmlOutput();

    // at-prefix to hide a warning from w/in the LTI libraries
    @$DeepLink->output_response_form([$Reply], $Plugin->getKeyId());
    return;
}

$AF->suppressStandardPageStartAndEnd();

# get search params from url
$H_SearchParams = new SearchParameterSet();
$H_SearchParams->itemTypes(MetadataSchema::SCHEMAID_DEFAULT);
$H_SearchParams->urlParameters($_GET);

# if new keywords provided in POST, use those
if (isset($_POST["F_Keywords"])) {
    $H_SearchParams->removeParameter(null);
    if (strlen($_POST["F_Keywords"]) > 0) {
        $H_SearchParams->addParameter($_POST["F_Keywords"]);
    }
}

# set up the default search parameters
$PluginParams = $Plugin->getConfigSetting("ResourceCriteria");

$Launch = $Plugin->getCachedLaunch($H_LaunchId);
$Registration = $Plugin->getLmsRegistration($Launch);
$RegistrationParams = $Registration->getSearchParameters();

if ($RegistrationParams->parameterCount() > 0) {
    $DefaultSearchParams = new SearchParameterSet();
    $DefaultSearchParams->logic("AND");
    $DefaultSearchParams->addSet($PluginParams);
    $DefaultSearchParams->addSet($RegistrationParams);
} else {
    $DefaultSearchParams = $PluginParams;
}
$DefaultSearchParams->itemTypes(MetadataSchema::SCHEMAID_DEFAULT);

if ($H_SearchParams->parameterCount() == 0) {
    # if no params provided, start with the configured defaults
    $H_SearchParamsProvided = false;
    $H_SearchParams = $DefaultSearchParams;
} else {
    # if params were provided, see if they differ from the defaults
    $DefaultUrlParams = $DefaultSearchParams->urlParameterString();
    $ProvidedUrlParams = $H_SearchParams->urlParameterString();
    $H_SearchParamsProvided = ($ProvidedUrlParams != $DefaultUrlParams) ? true : false;
}

$SortField = $Plugin->getConfigSetting("SortField");
if ($SortField !== null) {
    $H_SearchParams->sortBy($SortField);
}
$H_SearchParams->sortDescending($Plugin->getConfigSetting("SortDescending"));

$H_AllRecords = getSearchResults($H_SearchParams);

if (!$H_SearchParamsProvided) {
    $NewRecords = getSearchResults(
        $Plugin->getConfigSetting("NewResourceCriteria")
    );

    $H_Records = array_intersect(
        $H_AllRecords,
        $NewRecords
    );
} else {
    $H_Records = $H_AllRecords;
}
$H_FacetUI = new SearchFacetUI(
    $H_SearchParams,
    array_fill_keys($H_Records, "1") # expects [RecordId => SearchScore, ...] form
);
$H_FacetUI->setAllFacetsOpenByDefault();

$FacetFieldIds = $Plugin->getConfigSetting("FacetFields");
if (is_array($FacetFieldIds) && count($FacetFieldIds) > 0) {
    $H_FacetUI->setFacetFieldIds($FacetFieldIds);
}

$H_TransportUI = new TransportControlsUI();
$H_TransportUI->itemsPerPage(10);
$H_TransportUI->itemCount(count($H_Records));

$H_Records = array_slice(
    $H_Records,
    $H_TransportUI->startingIndex(),
    $H_TransportUI->itemsPerPage(),
    true
);

$H_BaseLink = $AF->baseUrl()."index.php"
    ."?P=P_EduLink_LTIHome"
    ."&L=".$H_LaunchId;

$H_FacetUI->setBaseLink($H_BaseLink);

$FacetParams = clone $H_SearchParams;
$FacetParams->removeParameter(null);
$FacetUrlParams = $FacetParams->urlParameterString();
if (strlen($FacetUrlParams) > 0) {
    $H_BaseLink .= "&".$FacetUrlParams;
}

$H_TransportUI->baseLink($H_BaseLink);

if ($H_SearchParamsProvided) {
    $PageNo = (int)floor(
        $H_TransportUI->startingIndex() /
            $H_TransportUI->itemsPerPage()
    );
    $Plugin->recordSearch(
        $H_LaunchId,
        $H_SearchParams,
        $PageNo,
        $H_TransportUI->itemCount()
    );
}
