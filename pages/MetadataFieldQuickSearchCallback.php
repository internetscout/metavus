<?PHP
#
#   FILE:  MetadataFieldQuickSearchResponse.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2011-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus;
use ScoutLib\ApplicationFramework;

# ----- MAIN -----------------------------------------------------------------

ApplicationFramework::getInstance()->beginAjaxResponse();

# retrieve field to search
if (isset($_GET["MF"])) {
    $FieldId = intval($_GET["MF"]);
    if (isset($_GET["SS"])) {
        $Search = $_GET["SS"];
    } else {
        # return nothing if there is no search
        $FailureArray = [
            "success" => false,
            "general_message" =>
                'You must provide a search string to receive results.'
        ];
        echo json_encode($FailureArray);
        return;
    }
} else {
    # return if there's no MetadataFieldId
    $FailureArray = [
        "success" => false,
        "general_message" => 'You must search a field to receive results.'
    ];
    echo json_encode($FailureArray);
    return;
}

$Field = MetadataField::getField($FieldId);

# grab all the matches, sort them, and pull out the chunk we want
list($NumResults, $NumAdditionalResults, $ANames) =
    QuickSearchHelper::searchField($Field, $Search);

# convert results into a format that jquery-ui can grok
$AvailableNames = array();
foreach ($ANames as $Id => $Name) {
    if (is_array($Name)) {
        $AvailableNames[] = [
            "label" => QuickSearchHelper::highlightSearchString(
                $Search,
                $Name["name"]
            ),
            "value" => $Name["title"],
            "ItemId" => $Id
        ];
    } else {
        $AvailableNames[] = [
            "label" => QuickSearchHelper::highlightSearchString(
                $Search,
                $Name
            ),
            "value" => $Name,
            "ItemId" => $Id
        ];
    }
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
        "ItemId" => ""
    ];
}

echo json_encode($AvailableNames);
