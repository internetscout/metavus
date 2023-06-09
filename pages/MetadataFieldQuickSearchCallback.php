<?PHP
#
#   FILE:  MetadataFieldQuickSearchResponse.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2011-2020 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#

use Metavus\MetadataField;
use Metavus\QuickSearchHelper;

# ----- LOCAL FUNCTIONS ------------------------------------------------------

# ----- MAIN -----------------------------------------------------------------
#
# set headers to control caching
$GLOBALS["AF"]->BeginAjaxResponse();

# retrieve field to search
if (isset($_GET["MF"])) {
    $FieldId = intval($_GET["MF"]);
    if (isset($_GET["SS"])) {
        $Search = $_GET["SS"];
    } else {
        # return nothing if there is no search
        $FailureArray = array(
            "success" => false,
            "general_message" =>
                'You must provide a search string to receive results.'
            );
        echo json_encode($FailureArray);
        return;
    }
} else {
    # return if there's no MetadataFieldId
    $FailureArray = array(
        "success" => false,
        "general_message" =>
            'You must search a field to receive results.' );
    echo json_encode($FailureArray);
    return;
}

$Field = new MetadataField($FieldId);

# grab all the matches, sort them, and pull out the chunk we want
list($NumResults, $NumAdditionalResults, $ANames) =
    QuickSearchHelper::SearchField($Field, $Search);

# convert results into a format that jquery-ui can grok
$AvailableNames = array();
foreach ($ANames as $Id => $Name) {
    if (is_array($Name)) {
        $AvailableNames[] = array(
            "label" => QuickSearchHelper::HighlightSearchString(
                $Search,
                $Name["name"]
            ),
            "value" => $Name["title"],
            "ItemId" => $Id);
    } else {
        $AvailableNames[] = array(
            "label" => QuickSearchHelper::HighlightSearchString(
                $Search,
                $Name
            ),
            "value" => $Name,
            "ItemId" => $Id);
    }
}

if ($NumAdditionalResults > 0) {
    $AvailableNames[] = array(
        "label" =>
            "<div class=\"cw-quicksearch-moreresults\">"
            ."There ".($NumAdditionalResults > 1 ? "are" : "is")
            ." <b>".$NumAdditionalResults." additional result"
            .($NumAdditionalResults > 1 ? "s" : "")."</b> "
            ."that are not displayed. Add additional search terms "
            ."if you do not see what you are trying to find."
            ."</div>",
        "value" => "",
        "ItemId" => "");
}

echo json_encode($AvailableNames);
