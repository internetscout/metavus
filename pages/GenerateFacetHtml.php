<?PHP
#
#   FILE:  GenerateFacetHtml.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2026 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan
#
# Generate HTML for search facets to display at right in SearchResults.
# The search parameters in use should be included in the _GET parameters.
# A SchemaId may be provided in an SC= parameter. If omitted, facets
# will be generated for the Resource schema.

namespace Metavus;
use InvalidArgumentException;
use ScoutLib\ApplicationFramework;
use ScoutLib\DataCache;
use ScoutLib\PluginManager;

# ----- SETUP ----------------------------------------------------------------

$AF = ApplicationFramework::getInstance();
$User = User::getCurrentUser();
$PluginMgr = PluginManager::getInstance();

$AF->suppressHtmlOutput();

# request that this page not be indexed by search engines
$AF->addMetaTag(["robots" => "noindex"]);

# do not generate facets for bots
if ($PluginMgr->pluginReady("BotDetector") &&
    $PluginMgr->getPlugin("BotDetector")->checkForBot()) {
    $AF->doNotCacheCurrentPage();
    return;
}

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

$SchemaId = $_GET["SC"] ?? MetadataSchema::SCHEMAID_DEFAULT;
$BaseLink = "index.php?P=SearchResults";

# check that Schema Id from the URL is valid
if (!is_numeric($SchemaId) || !MetadataSchema::schemaExistsWithId((int)$SchemaId)) {
    $AF->doNotCacheCurrentPage();
    header($_SERVER["SERVER_PROTOCOL"]." 400 Bad Request");
    print "ERROR: Invalid SchemaId provided in URL: "
        .htmlspecialchars($SchemaId);
    return;
}

# mark page so cached versions is cleared when search results may change for this schema
$AF->addPageCacheTag("SearchResults".$SchemaId);

# ----- MAIN -----------------------------------------------------------------

# retrieve current search parameters
$SearchParams = new SearchParameterSet();
try {
    $SearchParams->urlParameters($_GET);
} catch (InvalidArgumentException $Ex) {
    $AF->doNotCacheCurrentPage();
    header($_SERVER["SERVER_PROTOCOL"]." 400 Bad Request");
    print "ERROR: Invalid search parameters provided in URL: "
        .htmlspecialchars($Ex->getMessage());
    return;
}

# check if we've got a cached copy of these results
# (key must match the one used in SearchResults.php)
$Cache = new DataCache();
$CacheKey = "SearchResults_".md5(
    ($User->id() ?? "ANON")."/".$SearchParams->urlParameterString()
);
$AllSearchResults = $Cache->get($CacheKey);

# if not, run the search
if ($AllSearchResults === null) {
    $SearchParams->itemTypes($SchemaId);

    $SEngine = new SearchEngine();
    $AllSearchResults = $SEngine->searchAll($SearchParams);
    $SearchResults = $AllSearchResults[$SchemaId] ?? [];

    $RFactory = new RecordFactory((int)$SchemaId);
    $ViewableResourceIds = $RFactory->filterOutUnviewableRecords(
        array_keys($SearchResults),
        User::getCurrentUser()
    );

    # filter out records the current user cannot view
    $SearchResults = array_intersect_key(
        $SearchResults,
        array_flip($ViewableResourceIds)
    );
} else {
    $SearchResults = $AllSearchResults[$SchemaId] ?? [];
}

$FacetUI = new SearchFacetUI(
    $SearchParams,
    $SearchResults
);
$FacetUI->setBaseLink($BaseLink);

print $FacetUI->getHtml();
