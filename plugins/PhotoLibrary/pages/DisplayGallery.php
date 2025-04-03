<?PHP
#
#   FILE:  DisplayGallery.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2023 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# VALUES PROVIDED to INTERFACE (REQUIRED):
#   $H_ItemIds - IDs of items to be displayed on current page.
#   $H_SearchParams - Search parameters used to filter items currently to be
#       displayed on page.
# VALUES PROVIDED to INTERFACE (OPTIONAL):
#   $H_TransportUI - Transport control UI to use for paging through items
#       currently selected.  Only set if there are more items than will fit
#       on a single page.
#
# @scout:phpstan

namespace Metavus;
use Exception;
use Metavus\Plugins\PhotoLibrary;
use ScoutLib\ApplicationFramework;

# ----- MAIN -----------------------------------------------------------------

$AF = ApplicationFramework::getInstance();
$PhotoLibraryPlugin = PhotoLibrary::getInstance();
$SchemaId = $PhotoLibraryPlugin->getConfigSetting("MetadataSchemaId");
$RFactory = new RecordFactory($SchemaId);
$User = User::getCurrentUser();
$H_BaseLink = "index.php?P=P_PhotoLibrary_DisplayGallery";

$MaxItemsPerPage = 24;

# retrieve current search parameters
$H_SearchParams = new SearchParameterSet();
try {
    $H_SearchParams->urlParameters($_GET);
} catch (Exception $Ex) {
    $H_Error = "Invalid search parameters provided in URL: "
        .$Ex->getMessage();
    return;
}
$H_SearchParams->itemTypes($SchemaId);

# if we have search parameters
if ($H_SearchParams->parameterCount()) {
    # retrieve images based on search parameters
    $SEngine = new SearchEngine();
    $SearchScores = $SEngine->search($H_SearchParams);
    $H_ItemIds = array_keys($SearchScores);
} else {
    # retrieve all images
    $H_ItemIds = $RFactory->getItemIds();
}

# filter out those not viewable by current user (if any)
$H_ItemIds = $RFactory->filterOutUnviewableRecords($H_ItemIds, $User);

# if we have more than one page of items
if (count($H_ItemIds) > $MaxItemsPerPage) {
    # add checksum to base link to so that paging can be reset if results change
    $Checksum = md5(serialize($H_ItemIds));
    $H_BaseLink .= "&amp;CK=".$Checksum;

    # set up transport controls for pagination
    $H_TransportUI = new TransportControlsUI();
    $H_TransportUI->baseLink($H_BaseLink."&amp;".$H_SearchParams->urlParameterString());

    # reset paging if results have changed
    if (($_GET["CK"] ?? "") != $Checksum) {
        $H_TransportUI->startingIndex(0);
    }

    # pare down items to just those for this page
    $H_ItemIds = $H_TransportUI->filterItemIdsForCurrentPage(
        $H_ItemIds,
        $MaxItemsPerPage
    );
}

# mark page so cached versions is cleared when search results may change for this schema
$AF->addPageCacheTag("SearchResults".$SchemaId);
