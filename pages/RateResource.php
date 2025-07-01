<?PHP
#
#   FILE:  RateResource.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2011-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

use Metavus\Record;
use ScoutLib\ApplicationFramework;

# ----- MAIN -----------------------------------------------------------------

$AF = ApplicationFramework::getInstance();
$ResourceId = (isset($_GET["F_ResourceId"])) ? intval($_GET["F_ResourceId"]) : null;
$Rating = (isset($_GET["F_Rating"])) ? intval($_GET["F_Rating"]) : null;

# check for the rating in POST variables
if (is_null($Rating) && isset($_POST["F_Rating"])) {
    $Rating = intval($_POST["F_Rating"]);
}

# save the new rating for the user
if (!is_null($ResourceId)) {
    if (!is_null($Rating)) {
        $Resource = new Record($ResourceId);
        $Resource->rating($Rating);
    }

    # go to full record page
    $AF->setJumpToPage("FullRecord&ID=".$ResourceId);
} else {
    # go to the home page
    $AF->setJumpToPage("Home");
}
