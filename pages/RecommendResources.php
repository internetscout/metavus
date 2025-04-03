<?PHP
#
#   FILE:  RecommendResources.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2002-2022 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
#   @scout:phpstan

ParseArguments();
PageTitle("Recommend Resources");

# ----- EXPORTED FUNCTIONS ---------------------------------------------------

# ----- LOCAL FUNCTIONS ------------------------------------------------------

/**
* Parse page arguments.
*/
function ParseArguments(): void
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

# ----- MAIN -----------------------------------------------------------------

# non-standard global variables
global $CurrentResourceId;
global $Recommendations;
global $Recommender;
global $ResultsPerPage;
global $SearchString;
global $StartingResult;
