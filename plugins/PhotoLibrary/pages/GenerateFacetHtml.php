<?PHP
#
#   FILE:  GenerateFacetHtml.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan
#
# Generate HTML for search facets to display at right in DisplayGallery.
# The search parameters in use should be included in the _GET parameters.

namespace Metavus;

use Exception;
use Metavus\Plugins\PhotoLibrary;
use ScoutLib\ApplicationFramework;
use ScoutLib\PluginManager;

# ----- SETUP ----------------------------------------------------------------

$AF = ApplicationFramework::getInstance();
$User = User::getCurrentUser();
$PluginMgr = PluginManager::getInstance();

$AF->suppressHtmlOutput();

# request that this page not be indexed by search engines
$AF->addMetaTag(["robots" => "noindex"]);

# do not generate facets for anon uses when load is high
if (!$User->isLoggedIn()) {
    # check system load
    $LoadAverage = sys_getloadavg();
    $SysCfg = SystemConfiguration::getInstance();
    $LoadCutoff = $SysCfg->getInt("AnonSearchCpuLoadCutoff");

    # if system load is high, bail and return a 429 response
    # (anything processing apache logs can look for 429s to identify
    # aggressive crawlers that are hammering this page)
    if (is_array($LoadAverage) && ($LoadAverage[0] > $LoadCutoff)) {
        $AF->doNotCacheCurrentPage();
        header($_SERVER["SERVER_PROTOCOL"]." 429 Too Many Requests");
        return;
    }
}

# do not generate facets for bots
if ($PluginMgr->pluginReady("BotDetector") &&
    $PluginMgr->getPlugin("BotDetector")->checkForBot()) {
    $AF->doNotCacheCurrentPage();
    return;
}

$PhotoLibraryPlugin = PhotoLibrary::getInstance();
$SchemaId = $PhotoLibraryPlugin->getConfigSetting("MetadataSchemaId");
$RFactory = new RecordFactory($SchemaId);
$BaseLink = "index.php?P=P_PhotoLibrary_DisplayGallery";

# mark page so cached versions is cleared when search results may change for this schema
$AF->addPageCacheTag("SearchResults".$SchemaId);

# ----- MAIN -----------------------------------------------------------------

# retrieve current search parameters
$SearchParams = new SearchParameterSet();
try {
    $SearchParams->urlParameters($_GET);
} catch (Exception $Ex) {
    print "ERROR: Invalid search parameters provided in URL: "
        .$Ex->getMessage();
    return;
}
$SearchParams->itemTypes($SchemaId);

# if we have search parameters
if ($SearchParams->parameterCount() !== 0) {
    # retrieve images based on search parameters
    $SEngine = new SearchEngine();
    $SearchScores = $SEngine->search($SearchParams);
    $ItemIds = array_keys($SearchScores);
} else {
    # retrieve all images
    $ItemIds = $RFactory->getItemIds();
}

# filter out those not viewable by current user (if any)
$ItemIds = $RFactory->filterOutUnviewableRecords($ItemIds, $User);

# if nothing was visible, no facets to display
if (count($ItemIds) == 0) {
    return;
}

$FacetUI = new SearchFacetUI(
    $SearchParams,
    array_fill_keys($ItemIds, 1)
);
$FacetUI->setBaseLink($BaseLink);
$FacetUI->setAllFacetsOpenByDefault();
print $FacetUI->getHtml();
