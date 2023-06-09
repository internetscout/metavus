<?PHP
#
#   FILE:  SavedSearchFactory.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2009-2020 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus;

use ScoutLib\ItemFactory;

/**
 * Factory for manipulating SavedSearch objects.
 */
class SavedSearchFactory extends ItemFactory
{

    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Object constructor.
     */
    public function __construct()
    {
        # set up item factory base class
        parent::__construct("Metavus\\SavedSearch", "SavedSearches", "SearchId", "SearchName");
    }

    /**
    * Get all saved searches for a specified user sorted by ID in ascending order.
    * @param int $UserId ID of user.
    * @return array Array of SavedSearch objects, with saved search IDs
    *       for index.
    */
    public function getSearchesForUser(int $UserId): array
    {
        # get SavedSearch IDs in ascending order using UserId
        $SavedSearchIds = $this->getItemIds("UserId = ".$UserId, false, "SearchId", true);
        # construct list of SavedSearches using IDs
        $SavedSearches = [];
        foreach ($SavedSearchIds as $Id) {
            $SavedSearches[$Id] = new SavedSearch($Id);
        }
        # return list of searches to caller
        return $SavedSearches;
    }

    /**
     * Get all searches that should be run according to frequency and last run time.
     * @return array Array of SavedSearch objects, with saved search IDs
     *       for index.
     */
    public function getSearchesDueToRun(): array
    {
        # retrieve searches with frequency/time values that indicate need to be run
        return $this->getItems(
            "((Frequency = ".SavedSearch::SEARCHFREQ_HOURLY.")"
                        ." AND (DateLastRun < '"
                        .date("Y-m-d H:i:s", (strtotime("1 hour ago") + 15))."'))"
                ." OR ((Frequency = ".SavedSearch::SEARCHFREQ_DAILY.")"
                        ." AND (DateLastRun < '"
                        .date("Y-m-d H:i:s", (strtotime("1 day ago") + 15))."'))"
                ." OR ((Frequency = ".SavedSearch::SEARCHFREQ_WEEKLY.")"
                        ." AND (DateLastRun < '"
                        .date("Y-m-d H:i:s", (strtotime("1 week ago") + 15))."'))"
                ." OR ((Frequency = ".SavedSearch::SEARCHFREQ_BIWEEKLY.")"
                        ." AND (DateLastRun < '"
                        .date("Y-m-d H:i:s", (strtotime("2 weeks ago") + 15))."'))"
                ." OR ((Frequency = ".SavedSearch::SEARCHFREQ_MONTHLY.")"
                        ." AND (DateLastRun < '"
                        .date("Y-m-d H:i:s", (strtotime("1 month ago") + 15))."'))"
                ." OR ((Frequency = ".SavedSearch::SEARCHFREQ_QUARTERLY.")"
                        ." AND (DateLastRun < '"
                        .date("Y-m-d H:i:s", (strtotime("3 months ago") + 15))."'))"
                ." OR ((Frequency = ".SavedSearch::SEARCHFREQ_YEARLY.")"
                        ." AND (DateLastRun < '"
            .date("Y-m-d H:i:s", (strtotime("1 year ago") + 15))."'))"
        );
    }

    /**
     * Get number of users with saved searches.
     * @return int User count.
     */
    public function getSearchUserCount(): int
    {
        return $this->DB->Query(
            "SELECT COUNT(DISTINCT UserId) AS UserCount FROM SavedSearches",
            "UserCount"
        );
    }


    # ---- PRIVATE INTERFACE -------------------------------------------------
}
