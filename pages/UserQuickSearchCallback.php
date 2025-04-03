<?PHP
#
#   FILE:  UserQuickSearchCallback.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2018-2020 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
#   @scout:phpstan

use Metavus\QuickSearchHelper;
use ScoutLib\ApplicationFramework;

# ----- LOCAL FUNCTIONS ------------------------------------------------------

# ----- MAIN -----------------------------------------------------------------
#
ApplicationFramework::getInstance()->BeginAjaxResponse();

if (!isset($_GET["SS"])) {
    print json_encode([
        "success" => false,
        "general_message" => "You must provide a search string.",
    ]);
    return;
}

$Search = $_GET["SS"];

# grab all the matches, sort them, and pull out the chunk we want
list($NumResults, $NumAdditionalResults, $ANames) =
    QuickSearchHelper::SearchForUsers($Search);

# convert results into a format that jquery-ui can grok
$AvailableNames = array();
foreach ($ANames as $Id => $Name) {
    $AvailableNames[] = [
        "label" => QuickSearchHelper::HighlightSearchString(
            $Search,
            $Name
        ),
        "value" => $Name,
        "ItemId" => $Id,
    ];
}

if ($NumAdditionalResults > 0) {
    $AvailableNames[] = [
        "label" =>
            "<div class=\"cw-quicksearch-moreresults\">"
            ."There ".($NumAdditionalResults > 1 ? "are" : "is")
            ." <b>".$NumAdditionalResults." additional result"
            .($NumAdditionalResults > 1 ? "s" : "")."</b> "
            ."that are not displayed. Add additional search terms "
            ."if you do not see what you are trying to find."
            ."</div>",
        "value" => "",
        "ItemId" => "",
    ];
}

print json_encode($AvailableNames);
