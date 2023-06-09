<?PHP
#
#   FILE:  RecommendResources.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2002-2022 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#

use Metavus\Record;
use Metavus\Recommender;
use Metavus\User;

ParseArguments();
PageTitle("Recommend Resources");

# ----- EXPORTED FUNCTIONS ---------------------------------------------------

/**
* Output resource recommendations.
*/
function PrintRecommendations()
{
    global $Recommendations;
    global $CurrentResourceId;

    # make sure recommendations have been generated
    GetRecommendations();

    # for each recommended result
    foreach ($Recommendations as $ResourceId => $Score) {
        # export resource ID for use by other functions
        $CurrentResourceId = $ResourceId;

        # print entry
        $Resource = new Record($ResourceId);
        PrintRecommendation(
            $Resource,
            $Resource->getViewPageUrl(),
            $Resource->UserCanEdit(User::getCurrentUser()),
            $Resource->getEditPageUrl(),
            $Resource->ScaledCumulativeRating()
        );
    }
}

/**
* Get link to the 'why recommend' page.
*/
function GetWhyRecommendLink()
{
    global $CurrentResourceId;
    return "index.php?P=WhyRecommend&amp;rr=".$CurrentResourceId;
}

/**
* Output total number of resource recommendations.
*/
function PrintTotalNumberOfResults()
{
    global $Recommender;

    # make sure recommendations have been generated
    GetRecommendations();

    print($Recommender->NumberOfResults());
}

/**
* Out number of first result shown on this page.
*/
function PrintStartingResultNumber()
{
    global $StartingResult;
    global $Recommender;

    # make sure recommendations have been generated
    GetRecommendations();

    if ($Recommender->NumberOfResults() == 0) {
        print("0");
    } else {
        print($StartingResult + 1);
    }
}

/**
* Output number of the last result shown on this page.
*/
function PrintEndingResultNumber()
{
    global $StartingResult;
    global $ResultsPerPage;
    global $Recommender;

    # make sure recommendations have been generated
    GetRecommendations();

    print(min(($StartingResult + $ResultsPerPage), $Recommender->NumberOfResults()));
}

/**
* Determine if previous results are available.
* @return bool TRUE when there are previous results.
*/
function PreviousResultsAvailable()
{
    global $StartingResult;

    return ($StartingResult > 0) ? true : false;
}

/**
* Determine if additional results are available.
* @return bool TRUE when additional results exists.
*/
function NextResultsAvailable()
{
    global $StartingResult;
    global $ResultsPerPage;
    global $Recommender;

    # make sure recommendations have been generated
    GetRecommendations();

    return (($StartingResult + $ResultsPerPage) <
           $Recommender->NumberOfResults()) ? true : false;
}

/**
* Print link to previous page of results.
*/
function PrintPreviousResultsLink()
{
    global $StartingResult;
    global $ResultsPerPage;
    global $SearchString;

    $NewStartingResult = max(($StartingResult - $ResultsPerPage), 0);
    print("index.php?P=RecommendResources&amp;sr=".$NewStartingResult);
}

/**
* Print link to next page of results.
*/
function PrintNextResultsLink()
{
    global $StartingResult;
    global $ResultsPerPage;
    global $SearchString;

    $NewStartingResult = $StartingResult + $ResultsPerPage;
    print("index.php?P=RecommendResources&amp;sr=".$NewStartingResult);
}

/**
* Determine if there are no results.
* @return TRUE when no results exist.
*/
function NoResultsFound()
{
    global $Recommender;

    # make sure recommendations have been generated
    GetRecommendations();

    return ($Recommender->NumberOfResults() == 0) ? true : false;
}

/**
* Output time taken by recommendation search.
*/
function PrintSearchTime()
{
    global $Recommender;

    # make sure recommendations have been generated
    GetRecommendations();

    printf("%.3f", $Recommender->SearchTime());
}

/**
* Output number of previous results.
*/
function PrintNumberOfPreviousResults()
{
    global $ResultsPerPage;

    print($ResultsPerPage);
}

/**
* Output number of next results.
*/
function PrintNumberOfNextResults()
{
    global $ResultsPerPage;
    global $StartingResult;
    global $Recommender;

    # make sure recommendations have been generated
    GetRecommendations();

    print(min($ResultsPerPage, ($Recommender->NumberOfResults() -
            ($StartingResult + $ResultsPerPage))));
}


# ----- LOCAL FUNCTIONS ------------------------------------------------------

/**
* Parse page arguments.
*/
function ParseArguments()
{
    global $_GET;
    global $StartingResult;
    global $ResultsPerPage;

    # grab starting result number if passed in
    if (isset($_GET["sr"])) {
        $StartingResult = $_GET["sr"];
    } else {
        $StartingResult = 0;
    }

    # set results per page to a default for now
    $ResultsPerPage = 10;
}

/**
* Get resource recommendations.
*/
function GetRecommendations()
{
    global $Recommender;
    global $Recommendations;
    global $StartingResult;
    global $ResultsPerPage;

    # bail out if we've already gotten recommendations
    static $AlreadyGotRecommendations;
    if ($AlreadyGotRecommendations) {
        return;
    }
    $AlreadyGotRecommendations = true;

    # create recommender
    $Recommender = new Recommender();

    # add filter function to return only released records
    $Recommender->AddResultFilterFunction("FilterRecommendationsForReleasedRecords");

    # get recommendations
    $Recommendations = $Recommender->Recommend(
        User::getCurrentUser()->Get("UserId"),
        $StartingResult,
        $ResultsPerPage
    );
}

/**
* Filter function to show only released recommendations.
* @param int $ResourceId Resource to test.
* @return TRUE for resources that should be shown.
*/
function FilterRecommendationsForReleasedRecords($ResourceId)
{
    $Resource = new Record($ResourceId);
    return ($Resource->UserCanView(User::getCurrentUser()));
}


# ----- MAIN -----------------------------------------------------------------

# non-standard global variables
global $CurrentResourceId;
global $Recommendations;
global $Recommender;
global $ResultsPerPage;
global $SearchString;
global $StartingResult;
