<?PHP
#
#   File:  EventFactory.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2016-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins\CalendarEvents;
use Metavus\Plugins\CalendarEvents;
use Metavus\RecordFactory;
use Metavus\SearchEngine;
use Metavus\SearchParameterSet;
use ScoutLib\PluginManager;
use ScoutLib\StdLib;

class EventFactory extends RecordFactory
{
    /**
     * Class constructor.
     */
    public function __construct()
    {
        $CalPlugin = CalendarEvents::getInstance();
        parent::__construct($CalPlugin->getSchemaId());
    }

    /**
     * Get the first month that contains an event.
     * @param bool $ReleasedOnly Set to FALSE to consider all events.
     * @return string|null Returns the month and year of the first month that
     *      contains an event, or NULL if there is no first month set.
     */
    public function getFirstMonth(bool $ReleasedOnly = true)
    {
        $StartDateName = $this->Schema->getField("Start Date")->dBFieldName();
        $ReleaseFlagName = $this->Schema->getField("Release Flag")->dBFieldName();

        # construct the base query for the first month that contains an event
        $Query = "SELECT MIN(".$StartDateName.") AS FirstMonth "
            ."FROM Records "
            ."WHERE RecordId >= 0 "
            ."AND SchemaId = ".$this->SchemaId." "
            ."AND ".$StartDateName." != 0";

        # add the release flag constraint if necessary
        if ($ReleasedOnly) {
            $Query .= " AND `".$ReleaseFlagName."` = '1' ";
        }

        $Query .= $this->getSqlConditionForOwner();

        # execute the query
        $FirstMonth = $this->DB->query($Query, "FirstMonth");

        # convert the first month to its timestamp
        $Timestamp = strtotime((string)$FirstMonth);

        # return NULL if there is no first month
        if ($Timestamp === false) {
            return null;
        }

        # normalize the month and return
        return date("F Y", $Timestamp);
    }

    /**
     * Get the last month that contains an event.
     * @param bool $ReleasedOnly Set to FALSE to consider all events.
     * @return string|null Returns the month and year of the last month that
     *      contains an event, or NULL if there is no last month set.
     */
    public function getLastMonth(bool $ReleasedOnly = true)
    {
        $EndDateName = $this->Schema->getField("End Date")->dBFieldName();
        $ReleaseFlagName = $this->Schema->getField("Release Flag")->dBFieldName();

        # constrcut the base query for the last month that contains an event
        $Query = "SELECT MAX(".$EndDateName.") AS LastMonth "
            ."FROM Records "
            ."WHERE RecordId >= 0 "
            ."AND SchemaId = ".$this->SchemaId;

        # add the release flag constraint if necessary
        if ($ReleasedOnly) {
            $Query .= " AND `".$ReleaseFlagName."` = '1' ";
        }

        $Query .= $this->getSqlConditionForOwner();

        # execute the query
        $LastMonth = $this->DB->query($Query, "LastMonth");

        # convert the last month to its timestamp
        $Timestamp = strtotime((string)$LastMonth);

        # return NULL if there is no last month
        if ($Timestamp === false) {
            return null;
        }

        # normalize the month and return
        return date("F Y", $Timestamp);
    }

    /**
     * Return array of item IDs.
     * @param string $Condition For compatibility with
     *   ItemFactory::getItemIds. Should always be null.
     * @param bool $IncludeTempItems Whether to include temporary items
     *   in returned set.(OPTIONAL, defaults to FALSE)
     * @param string $SortField For compatibility with
     *   ItemFactory::getItemIds. Should always be null.
     * @param bool $SortAscending If TRUE, sort items in ascending order,
     *   otherwise sort items in descending order. (OPTIONAL, and
     *   only meaningful if a sort field is specified.)
     * @return array Item IDs.
     */
    public function getItemIds(
        ?string $Condition = null,
        bool $IncludeTempItems = false,
        ?string $SortField = null,
        bool $SortAscending = true
    ): array {
        $AllItemIds = parent::getItemIds(
            $Condition,
            $IncludeTempItems,
            $SortField,
            $SortAscending
        );

        if ($this->OwnerId === false) {
            return $AllItemIds;
        }

        return $this->filterEventsByOwner($AllItemIds, $this->OwnerId);
    }

    /**
     * Get the IDs for all events in the given month.
     * @param string $Month Month and year of the events to get.
     * @param bool $ReleasedOnly Set to TRUE to only get released events. This
     *      parameter is optional and defaults to TRUE.
     * @return array Returns an array of event IDs.
     */
    public function getEventIdsForMonth(string $Month, bool $ReleasedOnly = true): array
    {
        # get the database field names for the date fields
        $StartDateName = $this->Schema->getField("Start Date")->dBFieldName();
        $EndDateName = $this->Schema->getField("End Date")->dBFieldName();

        # get the month range
        $Date = (int)strtotime($Month);
        $SafeMonthStart = date("Y-m-01 00:00:00", $Date);
        $SafeMonthEnd = date("Y-m-t 23:59:59", $Date);

        # leaving this here until query testing with more data can be done
        $Condition = " ((".$StartDateName." >= '".$SafeMonthStart."' ";
        $Condition .= " AND ".$StartDateName." <= '".$SafeMonthEnd."') ";
        $Condition .= " OR (".$EndDateName." >= '".$SafeMonthStart."' ";
        $Condition .= " AND ".$EndDateName." <= '".$SafeMonthEnd."') ";
        $Condition .= " OR (".$StartDateName." < '".$SafeMonthStart."' ";
        $Condition .= " AND ".$EndDateName." >= '".$SafeMonthStart."')) ";

        # retrieve event IDs and return
        return $this->getEventIds($Condition, $ReleasedOnly);
    }

    /**
     * Get all past event IDs for a given month.
     * @param string $Month The month to search for, in a format that is
     *      parseable by strtotime().
     * @param bool $ReleasedOnly Set to FALSE to consider all events.
     * @return array Array of all matching event IDs in descending order.
     */
    public function getIdsOfPastEventsForMonth(
        string $Month,
        bool $ReleasedOnly = true
    ): array {
        $EventIdsForMonth = $this->getEventIdsForMonth($Month, $ReleasedOnly);
        $PastEventIds = $this->getIdsOfPastEvents($ReleasedOnly);
        return array_intersect($PastEventIds, $EventIdsForMonth);
    }

    /**
     * Get all ongoing event IDs.
     * @param string $Month The month to search for, in a format that is
     *      parseable by strtotime().
     * @param bool $ReleasedOnly Set to FALSE to consider all events.
     * @return array Array of all matching event IDs.
     */
    public function getIdsOfOngoingEvents(
        string $Month,
        bool $ReleasedOnly = true
    ): array {
        $EventIdsForMonth = $this->getEventIdsForMonth($Month, $ReleasedOnly);
        $PastEventIdsForMonth = $this->getIdsOfPastEventsForMonth(
            $Month,
            $ReleasedOnly
        );
        $UpcomingEventIdsForMonth = $this->getIdsOfUpcomingEventsForMonth(
            $Month,
            $ReleasedOnly
        );

        $Results = array_diff($EventIdsForMonth, $PastEventIdsForMonth);
        $Results = array_diff($Results, $UpcomingEventIdsForMonth);
        return $Results;
    }

    /**
     * Get all upcoming event IDs for a given month.
     * @param string $Month The month to search for, in a format that is
     *      parseable by strtotime().
     * @param bool $ReleasedOnly Set to FALSE to consider all events.
     * @return array Array of all matching event IDs in descending order.
     */
    public function getIdsOfUpcomingEventsForMonth(
        string $Month,
        bool $ReleasedOnly = true
    ): array {
        $UpcomingEventIds = $this->getIdsOfUpcomingEvents(
            $ReleasedOnly,
            null
        );
        $EventIdsForMonth = $this->getEventIdsForMonth($Month, $ReleasedOnly);
        return array_intersect($UpcomingEventIds, $EventIdsForMonth);
    }

    /**
     * Get the event IDs for past events.
     * @param bool $ReleasedOnly Set to TRUE to get only events with the
     *      "Release Flag" field set to TRUE. (OPTIONAL, defaults to TRUE)
     * @param ?int $Limit Number of events to return. (OPTIONAL, defaults
     *      to all events)
     * @param ?string $EndMonth The last date of the specified month,
     *      used as the end point for retrieving events. (OPTIONAL, defaults
     *      to using the current date and timestamp)
     * @return array Event IDs, sorted by descending order of start date,
     *      end date, and then title.
     */
    public function getIdsOfPastEvents(
        bool $ReleasedOnly = true,
        ?int $Limit = null,
        ?string $EndMonth = null
    ): array {
        # get the database field names
        $EndDateFieldName = $this->Schema->getField("End Date")->dBFieldName();

        if (is_null($EndMonth)) {
            # get current timestamp
            $SafeTodayWithTime = date("Y-m-d H:i:s");

            # get events that end before right now
            $Condition = " (".$EndDateFieldName." < '".$SafeTodayWithTime."')";
        } else {
            # get events that end on the selected month
            $Condition = " (".$EndDateFieldName." <= '".$EndMonth."')";
        }

        return $this->getEventIds($Condition, $ReleasedOnly, $Limit, false);
    }

    /**
     * Get the IDs for upcoming events.
     * @param bool $ReleasedOnly Set to TRUE to get only events with the
     *      "Release Flag" field set to TRUE.  (OPTIONAL, defaults to TRUE)
     * @param int $Limit Number of events to return.  (OPTIONAL, defaults
     *      to all events)
     * @param string $StartDate The first date for consideration for
     *      retrieving events, formatted as "Y-M-D H:M:S". (OPTIONAL, defaults
     *      to using the current date and timestamp)
     * @return array Event IDs, sorted by descending order of start date,
     *      end date, and then title.
     */
    public function getIdsOfUpcomingEvents(
        bool $ReleasedOnly = true,
        ?int $Limit = null,
        ?string $StartDate = null
    ): array {
        # get the database field names for the date fields
        $StartDateFieldName = $this->Schema->getField("Start Date")->dBFieldName();

        if (is_null($StartDate)) {
            # get today's timestamps
            $SafeTodayWithTime = date("Y-m-d H:i:s");

            # get events that begin after right now
            $Condition = " (".$StartDateFieldName." > '".$SafeTodayWithTime."')";
        } else {
            # get events that begin on the selected month
            $Condition = " (".$StartDateFieldName." >= '".$StartDate."')";
        }

        return $this->getEventIds($Condition, $ReleasedOnly, $Limit, true);
    }

    /**
     * Get the IDs for past events, defined as those with an end date between
     * the present to 12 months in the past.
     * @param bool $ReleasedOnly Set to TRUE to get only events with the
     *      "Release Flag" field set to TRUE. (OPTIONAL, defaults to TRUE)
     * @param int $Limit Number of events to return. (OPTIONAL, defaults
     *      to all events)
     * @return array Event IDs, sorted by descending order of start date,
     *      end date, and then title.
     */
    public function getIdsOfPastEventsForYear(
        bool $ReleasedOnly = true,
        ?int $Limit = null
    ): array {

        # get the database field names
        $EndDateFieldName = $this->Schema->getField("End Date")->dBFieldName();

        # get events that ended before now, up to one year in the past
        $SafeTodayWithTime = date(StdLib::SQL_DATE_FORMAT);

        $LastYear = strtotime("now - 1 year");
        $EndMonth = date(StdLib::SQL_DATE_FORMAT, $LastYear);

        $Condition = " (".$EndDateFieldName." < '".$SafeTodayWithTime."')";
        $Condition .= " AND (".$EndDateFieldName." >= '".$EndMonth."')";

        return $this->getEventIds($Condition, $ReleasedOnly, $Limit, false);
    }

    /**
     * Get the IDs for upcoming events, defined as those with a start date
     * between the present, up to 12 months in the future.
     * @param bool $ReleasedOnly Set to TRUE to get only events with the
     *      "Release Flag" field set to TRUE.  (OPTIONAL, defaults to TRUE)
     * @param int $Limit Number of events to return.  (OPTIONAL, defaults
     *      to all events)
     * @return array Event IDs, sorted by descending order of start date,
     *      end date, and then title.
     */
    public function getIdsOfUpcomingEventsForYear(
        bool $ReleasedOnly = true,
        ?int $Limit = null
    ): array {
        # get the database field names for the date fields
        $StartDateFieldName = $this->Schema->getField("Start Date")->dBFieldName();

        $NextYear = strtotime("now + 1 year");
        $StartDate = date(StdLib::SQL_DATE_FORMAT, $NextYear);

        $SafeTodayWithTime = date(StdLib::SQL_DATE_FORMAT);
        $Condition =
                " (".$StartDateFieldName." > '".$SafeTodayWithTime."')";
        $Condition .= " AND (".$StartDateFieldName." <= '".$StartDate."')";

        return $this->getEventIds($Condition, $ReleasedOnly, $Limit, true);
    }

    /**
     * Get upcoming events.
     * @param bool $ReleasedOnly Set to TRUE to get only events with the
     *      "Release Flag" field set to TRUE.  (OPTIONAL, defaults to TRUE)
     * @param int $Limit Number of events to return.  (OPTIONAL, defaults
     *      to all events)
     * @return array Event, sorted by increasing order of start date, end
     *      date, and then title, and indexed by event ID.
     */
    public function getUpcomingEvents(
        bool $ReleasedOnly = true,
        ?int $Limit = null
    ): array {
        $Ids = $this->getIdsOfUpcomingEvents($ReleasedOnly, $Limit);
        $Events = [];
        foreach ($Ids as $Id) {
            $Events[$Id] = new Event($Id);
        }
        return $Events;
    }

    /**
     * Get counts for events for the future, occurring, and past events.
     * @param bool $ReleasedOnly Set to FALSE to get the counts for all events.
     * @return array Returns the counts for future, occurring, and past events.
     */
    public function getEventCountsByTense(bool $ReleasedOnly = true): array
    {
        # get the database field names for the date fields
        $StartDateName = $this->Schema->getField("Start Date")->dBFieldName();
        $EndDateName = $this->Schema->getField("End Date")->dBFieldName();
        $ReleaseFlagName = $this->Schema->getField("Release Flag")->dBFieldName();

        # get the month range
        $SafeTodayWithTime = date("Y-m-d H:i:s");

        # get an SQL condition, if one is required
        $Condition = $this->getSqlConditionForOwner();

        # rather than doing complex SQL query logic, just get the count of all
        # of the events and subtract the others below
        $AllEventsCount = strlen($Condition) ?
            $this->getItemCount(preg_replace('/^ AND /', '', $Condition)) :
            $this->getItemCount();

        $PastEventsCount = $this->getItemCount(
            "(`".$EndDateName."` < '".$SafeTodayWithTime."')".$Condition
        );
        $FutureEventsCount = $this->getItemCount(
            "(`".$StartDateName."` > '".$SafeTodayWithTime."')".$Condition
        );

        # return the counts
        return [
            "Past" => $PastEventsCount,
            "Occurring" => $AllEventsCount - $PastEventsCount - $FutureEventsCount,
            "Future" => $FutureEventsCount
        ];
    }

    /**
     * Get counts for events for each month.
     * @param bool $ReleasedOnly Set to FALSE to get the counts for all events.
     * @return array Returns the event counts for each month.
     */
    public function getEventCounts(bool $ReleasedOnly = true): array
    {
        # get the bounds of the months
        $FirstMonth = $this->getFirstMonth();
        $LastMonth = $this->getLastMonth();

        # convert the month strings to timestamps
        $FirstMonthTimestamp = strtotime((string)$FirstMonth);
        $LastMonthTimestamp = strtotime((string)$LastMonth);

        # return an empty array if the timestamps aren't valid or there are no
        # events
        if ($FirstMonthTimestamp === false || $LastMonthTimestamp === false) {
            return [];
        }

        # get the boundaries as numbers
        $FirstYearNumber = intval(date("Y", $FirstMonthTimestamp));
        $FirstMonthNumber = intval(date("m", $FirstMonthTimestamp));
        $LastYearNumber = intval(date("Y", $LastMonthTimestamp));
        $LastMonthNumber = intval(date("m", $LastMonthTimestamp));

        # start off the query
        $Query = "SELECT ";

        # get the database field names for the date fields
        $StartDateName = $this->Schema->getField("Start Date")->dBFieldName();
        $EndDateName = $this->Schema->getField("End Date")->dBFieldName();
        $ReleaseFlagName = $this->Schema->getField("Release Flag")->dBFieldName();

        # loop through the years
        for ($i = $FirstYearNumber; $i <= $LastYearNumber; $i++) {
            # loop through the months
            for ($j = ($i == $FirstYearNumber ? $FirstMonthNumber : 1); #
                 ($i == $LastYearNumber ? $j <= $LastMonthNumber : $j < 13); #
                 $j++) {
                $ColumnName = date("MY", (int)mktime(0, 0, 0, $j, 1, $i));
                $LastDay = date("t", (int)mktime(0, 0, 0, $j, 1, $i));

                $Start = $i."-".$j."-01 00:00:00";
                $End = $i."-".$j."-".$LastDay." 23:59:59";

                $Query .= " sum((".$StartDateName." >= '".$Start."' ";
                $Query .= " AND ".$StartDateName." <= '".$End."') ";
                $Query .= " OR (".$EndDateName." >= '".$Start."' ";
                $Query .= " AND ".$EndDateName." <= '".$End."') ";
                $Query .= " OR (".$StartDateName." < '".$Start."' ";
                $Query .= " AND ".$EndDateName." >= '".$Start."')) AS ".$ColumnName.", ";
            }
        }

        # remove the trailing comma
        $Query = rtrim($Query, ", ") . " ";

        # add the table name
        $Query .= "FROM Records WHERE RecordId >= 0"
               ." AND SchemaId = ".$this->SchemaId;

        # add the release flag constraint if necessary
        if ($ReleasedOnly) {
            $Query .= " AND `".$ReleaseFlagName."` = '1' ";
        }

        $Query .= $this->getSqlConditionForOwner();

        # this may be a very long query and could have very long results
        # avoid caching it
        $PreviousSetting = $this->DB->caching();
        $this->DB->caching(false);
        $this->DB->query($Query);
        $Result = $this->DB->fetchRow();
        $this->DB->caching($PreviousSetting);

        return $Result;
    }

    /**
     * Limit the events retrieved by the getXX methods based on the event
     * owner.
     * @param int|null|false $OwnerId Owner restriction - an int giving an
     *   OwnerId for events with that owner, NULL for events with no owner,
     *   or FALSE to disable filtering and retrieve all events regardless of
     *   ownership. By default only unowned events are returned.
     */
    public function limitRetrievedEventsToOwnerId($OwnerId) : void
    {
        $this->OwnerId = $OwnerId;
    }

    /**
     * Filter a list of events to either return those with a specified
     * owner or those that have no owner.
     * @param array $EventIds List of event IDs to filter.
     * @param int|null $OwnerId Desired owner ID or NULL for events with no owner.
     * @return array Filtered list of event IDs.
     */
    public function filterEventsByOwner(array $EventIds, $OwnerId): array
    {
        $OwnerFieldId = $this->Schema->getField("Owner")->id();

        if ($OwnerId === null) {
            # get the list of all events that have an owner
            $this->DB->query(
                "SELECT RecordId FROM RecordUserInts"
                ." WHERE FieldId = ".$OwnerFieldId
            );
            $OwnedEventIds = $this->DB->fetchColumn("RecordId");

            # and remove those events from the list we were given
            $Result = array_diff($EventIds, $OwnedEventIds);
        } else {
            $OwnedEventIds = $this->getIdsOfMatchingRecords(
                [$OwnerFieldId => $OwnerId]
            );

            # and filter out any events that weren't on that list
            $Result = array_intersect($EventIds, $OwnedEventIds);
        }

        return $Result;
    }

    /**
     * Filter a list of events based on search parameters.
     * @param array $EventIds List of event IDs to filter.
     * @param SearchParameterSet $SearchParams Search parameters to use when filtering.
     * @return array Filtered list of event IDs.
     */
    public function filterEventsBySearchParameters(
        array $EventIds,
        SearchParameterSet $SearchParams
    ): array {
        $SEngine = new SearchEngine();
        $SearchParams->itemTypes($this->SchemaId);
        $SearchResults = $SEngine->search($SearchParams);
        $SearchResultEventIds = array_keys($SearchResults);
        return array_intersect($EventIds, $SearchResultEventIds);
    }

    /**
     * Fetch event IDs using supplied SQL condition.
     * @param string $Condition Additional SQL condition string.
     * @param bool $ReleasedOnly Set to TRUE to get only events with the
     *      "Release Flag" field set to TRUE.  (OPTIONAL, defaults to TRUE)
     * @param int $Limit Number of events to return.  (OPTIONAL, defaults
     *      to all events)
     * @param bool $SortAscending Whether to sort the event IDs by start date,
     *      end date, and then title in ascending or desending order.
     * @return array Matched and sorted event IDs.
     */
    protected function getEventIds(
        string $Condition,
        bool $ReleasedOnly = true,
        ?int $Limit = null,
        bool $SortAscending = true
    ): array {
        $TitleName = $this->Schema->getField("Title")->dBFieldName();
        $StartDateName = $this->Schema->getField("Start Date")->dBFieldName();
        $EndDateName = $this->Schema->getField("End Date")->dBFieldName();

        # construct the first part of the query
        $Query = "SELECT RecordId FROM Records "
                ."WHERE RecordId >= 0 AND SchemaId = ".$this->SchemaId;

        # if only released events should be returned
        if ($ReleasedOnly) {
            $ReleaseFlagName = $this->Schema->getField(
                "Release Flag"
            )->dBFieldName();
            $Query .= " AND ".$ReleaseFlagName." = '1' ";
        }

        # add the condition string
        $Query .= " AND " . $Condition;

        $Query .= $this->getSqlConditionForOwner();

        # add sorting parameters
        $SortingKeyword = $SortAscending ? "ASC" : "DESC";
        $Query .= " ORDER BY ".$StartDateName." ".$SortingKeyword.", ";
        $Query .= $EndDateName.", ";
        $Query .= $TitleName;

        # add the limit if given
        if (!is_null($Limit)) {
            $Query .= " LIMIT " . intval($Limit);
        }

        # execute the query
        $this->DB->query($Query);

        # return the IDs
        return $this->DB->fetchColumn("RecordId");
    }


    # ---- PRIVATE INTERFACE -------------------------------------------------
    private $OwnerId = null; # by default, get events with no owner

    /**
     * Get SQL condition to apply an owner limitation if one is set.
     * @return string SQL condition. If non-empty, it will include the leading AND.
     */
    private function getSqlConditionForOwner() : string
    {
        if ($this->OwnerId === false) {
            return "";
        }

        $OwnerFieldId = $this->Schema->getField("Owner")->id();

        if ($this->OwnerId === null) {
            return " AND RecordId NOT IN ("
                ."SELECT RecordId FROM RecordUserInts"
                ." WHERE FieldId = ".$OwnerFieldId.")";
        }

        return " AND RecordId IN ("
            ."SELECT RecordId FROM RecordUserInts"
            ." WHERE FieldId = ".$OwnerFieldId
            ." AND UserId=".intval($this->OwnerId).")";
    }
}
