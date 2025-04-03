<?PHP
#
#   FILE:  Events.php (CalendarEvents plugin)
#
#   Part of the Metavus digital collections platform
#   Copyright 2013-2023 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#

# ----- EXPORTED FUNCTIONS ---------------------------------------------------
use Metavus\Plugins\CalendarEvents;
use Metavus\Plugins\CalendarEvents\Event;
use Metavus\Plugins\CalendarEvents\EventFactory;
use ScoutLib\ApplicationFramework;
use ScoutLib\StdLib;

/**
* Print all of the events for the page.
* @param array $Events Events to print.
* @param string $CurrentMonth Optional current month being displayed.
*/
function CalendarEvents_PrintEvents(array $Events, $CurrentMonth = null) : void
{
    $Plugin = CalendarEvents::getInstance();

    # if printing events for the current month
    if (date("F Y") == $CurrentMonth) {
        $TodayIncludesOngoing = $Plugin->TodayIncludesOngoingEvents();
        $NumEvents = count($Events);
        $CurrentNumber = 0;
        $SawNextUpcoming = false;

        foreach ($Events as $Event) {
            $CurrentNumber++;

            # if we haven't already seen the next upcoming event and the next event
            # is either the last event in the list or is the next upcoming event
            if (!$SawNextUpcoming
                && ($NumEvents == $CurrentNumber
                    || ($TodayIncludesOngoing
                        ? $Event->IsInFuture() || $Event->IsOccurring()
                        : $Event->StartsToday() || $Event->IsInFuture()))) {
                CalendarEvents_PrintTodayMarker();
            }

            $Plugin->PrintEventSummary($Event);
        }
    } else {
        # printing events for a past or future month
        foreach ($Events as $Event) {
            $Plugin->PrintEventSummary($Event);
        }
    }
}

/**
* Get the previous month relative to the given month that has at least one
* event.
* @param array $EventCounts An array of months mapped to the count of events for
*      that month.
* @param string $Month Month to use as an anchor.
* @return int The next month relative to the given month.
*/
function CalendarEvents_GetPreviousMonth(array $EventCounts, $Month)
{
    return CalendarEvents_GetSiblingMonth($EventCounts, $Month, "-1");
}

/**
* Get the next month relative to the given month that has at least one event.
* @param array $EventCounts An array of months mapped to the count of events for
*      that month.
* @param string $Month Month to use as an anchor.
* @return int The next month relative to the given month.
*/
function CalendarEvents_GetNextMonth(array $EventCounts, $Month)
{
    return CalendarEvents_GetSiblingMonth($EventCounts, $Month, "+1");
}

/**
* Get a sibling month relative to the given month.
* @param array $EventCounts An array of months mapped to the count of events for
*      that month.
* @param string $Month Month to use as an anchor.
* @param string $Interval Interval relative to the given month.
* @return int The month relative to the given month.
*/
function CalendarEvents_GetSiblingMonth(array $EventCounts, $Month, $Interval)
{
    $Key = date("MY", strtotime($Month." ".$Interval." month"));

    while (array_key_exists($Key, $EventCounts)) {
        if ($EventCounts[$Key] < 1) {
            $Key = date("MY", strtotime($Key." ".$Interval." month"));
            continue;
        }

        return strtotime($Key);
    }

    return strtotime($Month." ".$Interval." month");
}

# ----- MAIN -----------------------------------------------------------------

# get up some basic values
$H_Plugin = CalendarEvents::getInstance();
$H_StartingIndex = StdLib::getFormValue("SI", 0);
$H_Month = StdLib::getFormValue("Month");
$H_SchemaId = $H_Plugin->GetSchemaId();

$EFactory = new EventFactory();

$H_FirstMonth = $EFactory->GetFirstMonth();
$H_LastMonth = $EFactory->GetLastMonth();

$H_Events = [];
$H_EventCounts = $EFactory->GetEventCounts();

# transform "numeric_month year" to something strtotime() understands
if (!is_null($H_Month) && preg_match('/(0?[1-9]|1[12]) ([0-9]{4})/', (string)$H_Month, $Matches)) {
    $H_Month = date("M Y", (int)mktime(0, 0, 0, (int)$Matches[1], 1, (int)$Matches[2]));
}

# convert the month to a timestamp
$MonthTimestamp = !is_null($H_Month) ? strtotime((string)$H_Month) : false;

# strtotime() will return false when the provided month was absent or invalid
# in that case, use the timestamp of the first upcoming event or the current time
# when there are no upcoming events
if ($MonthTimestamp === false) {
    $EventIds = $EFactory->getIdsOfUpcomingEvents(true, 1);
    if (count($EventIds) > 0) {
        $FirstEvent = new Event(reset($EventIds));
        $MonthTimestamp = strtotime($FirstEvent->get("Start Date"));
    } else {
        $MonthTimestamp = time();
    }
}

# normalize the month
$H_Month = date("F Y", $MonthTimestamp);

# set the page title based on the month name
PageTitle($H_Month);

# get the event IDs and count for the count
$EventIds = $EFactory->GetEventIdsForMonth($H_Month);
$EventIds = $EFactory->FilterEventsByOwner($EventIds, null);
$H_EventCount = count($EventIds);

# load event objects from IDs
foreach ($EventIds as $Id) {
    $H_Events[$Id] = new Event($Id);
}

# tag page so it will be cleared when events are edited
$AF = ApplicationFramework::getInstance();
$AF->addPageCacheTag("ResourceList".$H_SchemaId);
