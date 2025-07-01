<?PHP
#
#   FILE:  WhyRecommend.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2011-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
#   @scout:phpstan

use ScoutLib\ApplicationFramework;

# ----- LOCAL FUNCTIONS ------------------------------------------------------

/**
* Set up RecommendedResourceId and ResultsPerPage.
*/
function ParseArguments(): void
{
    global $ResultsPerPage;
    global $RecommendedResourceId;

    # grab ID of recommended resource
    $RecommendedResourceId = (isset($_GET["rr"])) ? $_GET["rr"] : null;

    # set results per page to a default for now
    $ResultsPerPage = 10;
}

# ----- MAIN -----------------------------------------------------------------

# non-standard global variables
global $RecommendedResourceId;
global $ResultsPerPage;

$AF = ApplicationFramework::getInstance();
$AF->setPageTitle("Recommendation Sources");
ParseArguments();

# if the "rr" key isn't set, go to the resource recommendations page
if (!isset($_GET["rr"])) {
    $AF->setJumpToPage("RecommendResources");
}
