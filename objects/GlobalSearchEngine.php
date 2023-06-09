<?PHP
#
#   FILE:  GlobalSearchEngine.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2005-2021 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

/*
OUTSTANDING ISSUES:
- search string(s) must be escaped (~XX)
- search scores must be normalized
*/


use ScoutLib\Database;
use ScoutLib\OAIClient;
use ScoutLib\StdLib;

class GlobalSearchEngine
{

    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
    * Constructs a GlobalSearchEngine object.
    */
    public function __construct()
    {
    }

    /**
    * Performs a keyword search using a specified search string and returns the
    * results, starting with the one numbered with the starting result
    * (default 0) and continuing until reaching the desired number of results
    * (default 10).
    * @param string $SearchString The string to search for.
    * @param int $StartingResult The number of the starting result.  (OPTIONAL)
    * @param int $NumberOfResults The number of results to return.  (OPTIONAL)
    * @return array The results of the specified search.
    */
    public function search($SearchString, $StartingResult = 0, $NumberOfResults = 10): array
    {
        # save start time to use in calculating search time
        $StartTime = $this->GetMicrotime();

        # create OAI-SQ set specification from search string
        $SetSpec = "OAI-SQ!".$SearchString;

        # perform global search
        $SearchResults = $this->PerformSearch(
            $SetSpec,
            $StartingResult,
            $NumberOfResults
        );

        # record search time
        $this->LastSearchTime = $this->GetMicrotime() - $StartTime;

        # return results to caller
        return $SearchResults;
    }

    /**
    * Gets the number of results returned in the last search.
    * @return int The number of results returned in the the last search.
    */
    public function numberOfResults(): int
    {
        return $this->NumberOfResultsAvailable;
    }

    /**
    * Gets the time taken to perform the previous search, in microseconds.
    * @return float The time taken to perform the previous search.
    */
    public function searchTime(): float
    {
        return $this->LastSearchTime;
    }


    # ---- PRIVATE INTERFACE -------------------------------------------------

    private $NumberOfResultsAvailable;
    private $LastSearchTime;

    /**
    * Performs an OAI-SQ search.
    * @param string $SetSpec The specification of a set of OAI resources
    * @param int $StartingResult The number of the starting result.
    * @param int $NumberOfResults The number of results to retun.
    * @return array The results of the specified search.
    */
    private function performSearch($SetSpec, $StartingResult, $NumberOfResults): array
    {
        # for each global search site
        $DB = new Database();
        $DB->Query("SELECT * FROM GlobalSearchSites");
        $SearchResults = [];
        while ($SiteInfo = $DB->FetchRow()) {
            # retrieve results from site
            $SiteSearchResults = $this->SearchSite($SiteInfo, $SetSpec);

            # add results to result list
            $SearchResults = array_merge($SearchResults, $SiteSearchResults);
        }

        usort($SearchResults, function ($A, $B) {
            return StdLib::SortCompare($A["Search Score"], $B["Search Score"]);
        });

        # save number of results found
        $this->NumberOfResultsAvailable = count($SearchResults);

        # trim result list to match range requested by caller
        $SearchResults = array_slice($SearchResults, $StartingResult, $NumberOfResults);

        # return search results to caller
        return $SearchResults;
    }

    /**
    * Searches one site with a specification of subset of records to be retrieved.
    * @param array $SiteInfo An array containing an OAI repository URL
    * @param string $SetSpec The specification of a set of OAI resources
    * @return array The results of the search.
    */
    private function searchSite($SiteInfo, $SetSpec): array
    {
        # create OAI client and perform query
        $Client = new OAIClient($SiteInfo["OaiUrl"]);
        $Client->SetSpec($SetSpec);
        $QueryResults = $Client->GetRecords();
        $SearchResults = [];

        # for each result returned from query
        foreach ($QueryResults as $Result) {
            # extract and save result data where available
            unset($ResultData);
            $ResultData["Title"] =
                    isset($Result["metadata"]["DC:TITLE"][0])
                    ? $Result["metadata"]["DC:TITLE"][0] : null;
            $ResultData["Description"] =
                    isset($Result["metadata"]["DC:DESCRIPTION"][0])
                    ? $Result["metadata"]["DC:DESCRIPTION"][0] : null;
            $ResultData["Url"] =
                    isset($Result["metadata"]["DC:IDENTIFIER"][0])
                    ? $Result["metadata"]["DC:IDENTIFIER"][0] : null;
            $ResultData["Full Record Link"] =
                    isset($Result["about"]["SEARCHINFO"]["FULLRECORDLINK"][0])
                    ? $Result["about"]["SEARCHINFO"]["FULLRECORDLINK"][0] : null;
            $ResultData["Search Score"] =
                    isset($Result["about"]["SEARCHINFO"]["SEARCHSCORE"][0])
                    ? $Result["about"]["SEARCHINFO"]["SEARCHSCORE"][0] : null;
            $ResultData["Cumulative Rating"] =
                    isset($Result["about"]["SEARCHINFO"]["CUMULATIVERATING"][0])
                    ? $Result["about"]["SEARCHINFO"]["CUMULATIVERATING"][0] : null;
            $ResultData["Cumulative Rating Scale"] =
                    isset($Result["about"]["SEARCHINFO"]["CUMULATIVERATINGSCALE"][0])
                    ? $Result["about"]["SEARCHINFO"]["CUMULATIVERATINGSCALE"][0] : null;

            # save site info for result
            $ResultData["Site ID"] = $SiteInfo["SiteId"];
            $ResultData["Site Name"] = $SiteInfo["SiteName"];
            $ResultData["Site URL"] = $SiteInfo["SiteUrl"];

            # add result data to search results
            $SearchResults[] = $ResultData;
        }

        # return search results to caller
        return $SearchResults;
    }

    /**
    * Convenience function for getting the time in microseconds.
    * @return float The time in microseconds.
    */
    private function getMicrotime(): float
    {
        list($usec, $sec) = explode(" ", microtime());
        return ((float)$usec + (float)$sec);
    }
}
