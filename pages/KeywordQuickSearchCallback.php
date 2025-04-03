<?PHP
#
#   FILE:  KeywordQuickSearchCallback.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2013-2021 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

use Metavus\RecordFactory;
use Metavus\ResourceSummary;
use Metavus\SearchEngine;
use Metavus\User;
use ScoutLib\ApplicationFramework;
use ScoutLib\SearchParameterSet;
use ScoutLib\StdLib;

# ----- MAIN -----------------------------------------------------------------

ApplicationFramework::getInstance()->beginAjaxResponse();

# number of results to return
$DesiredNumberOfResults = 5;

$SearchString = StdLib::getArrayValue($_GET, "SS");

# construct search groups based on the keyword
$SearchParams = new SearchParameterSet();
$SearchParams->addParameter($SearchString);

# perform search
$SearchEngine = new SearchEngine();
$SearchResults = $SearchEngine->search($SearchParams);

# filter out non-viewable records
$SearchResults = array_intersect_key(
    $SearchResults,
    array_flip(
        RecordFactory::multiSchemaFilterNonViewableRecords(
            array_keys($SearchResults),
            User::getCurrentUser()
        )
    )
);

$TotalResults = count($SearchResults);

# and cut the results down to the desired amount
$SearchResults = array_slice($SearchResults, 0, $DesiredNumberOfResults, true);

$ResourceData = array();
foreach ($SearchResults as $ResourceId => $Score) {
    $Summary = ResourceSummary::create($ResourceId);
    $Summary->termsToHighlight($SearchString);

    ob_start();
    $Summary->displayCompact();
    $ResourceData["X".$ResourceId] = ob_get_contents();
    ob_end_clean();
}

# determine how many more results there are
$NumAdditionalResults = $TotalResults - count($ResourceData);

# convert into the format wanted by jquery-ui
$ResponseData = array();
foreach ($ResourceData as $Id => $Datum) {
    $ResponseData[] = [
        "label" => $Datum,
        "value" => $Datum,
        "ItemId" => $Id
    ];
}

if ($NumAdditionalResults > 0) {
    $ResponseData[] = [
        "label" => "There ".($NumAdditionalResults > 1 ? "are" : "is")
           ." <b>".$NumAdditionalResults." additional result"
            .($NumAdditionalResults > 1 ? "s" : "")."</b> "
            ."<a class=\"btn btn-primary\" "
            ."href=\"".ApplicationFramework::baseUrl()."index.php?P=SearchResults&amp;"
            .$SearchParams->UrlParameterString()."\">"
            ."View all</a>",
        "value" => "",
        "ItemId" => ""
    ];
}

print json_encode($ResponseData);
