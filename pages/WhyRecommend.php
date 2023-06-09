<?PHP
#
#   FILE:  WhyRecommend.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2011-2022 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#

use Metavus\Record;
use Metavus\Recommender;
use Metavus\User;

# ----- EXPORTED FUNCTIONS ---------------------------------------------------

/**
* Print recommended resource.
*/
function PrintRecommendedResource()
{
    global $RecommendedResourceId;

    $Resource = new Record($RecommendedResourceId);
    PrintRecommendation(
        $Resource,
        $Resource->getViewPageUrl(),
        User::getCurrentUser()->HasPriv(PRIV_RESOURCEADMIN),
        $Resource->getEditPageUrl(),
        $Resource->ScaledCumulativeRating()
    );
}

/**
* Print recommendation sources.
*/
function PrintRecommendationSources()
{
    global $RecommendedResourceId;
    global $ResultsPerPage;

    # retrieve user currently logged in
    $User = User::getCurrentUser();

    $ResourceCount = 0;

    # create recommender
    $Recommender = new Recommender();

    # get list of recommendation source resources
    $RecSources = $Recommender->GetSourceList(
        $User->Get("UserId"),
        $RecommendedResourceId
    );

    # for each source resource
    foreach ($RecSources as $SourceId => $CorrelationScore) {
        # if we have printed the max number of sources
        if ($ResourceCount > $ResultsPerPage) {
            # bail out
            continue;
        }

        $ResourceCount++;

        # print resource record
        $Resource = new Record($SourceId);
        PrintRecommendation(
            $Resource,
            $Resource->getViewPageUrl(),
            $Resource->UserCanEdit($User),
            $Resource->getEditPageUrl(),
            $Resource->ScaledCumulativeRating()
        );
    }
}

# ----- LOCAL FUNCTIONS ------------------------------------------------------

/**
* Set up RecommendedResourceId and ResultsPerPage.
*/
function ParseArguments()
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

PageTitle("Recommendation Sources");
ParseArguments();

# if the "rr" key isn't set, go to the resource recommendations page
if (!isset($_GET["rr"])) {
    $AF->SetJumpToPage("RecommendResources");
}
