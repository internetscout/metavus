<?PHP
#
#   FILE:  iCal.php (CalendarEvents plugin)
#
#   Part of the Metavus digital collections platform
#   Copyright 2013-2022 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#

use Metavus\Plugins\CalendarEvents;
use Metavus\Plugins\CalendarEvents\Event;
use Metavus\User;
use ScoutLib\ApplicationFramework;
use ScoutLib\iCalendarEvent;
use ScoutLib\iCalendarFeed;
use ScoutLib\StdLib;

# ----- MAIN -----------------------------------------------------------------

# assume that a generic error will occur
$H_State = "Error";

# get object parameters
$EventId = StdLib::getArrayValue($_GET, "EventId");

# if the event ID looks invalid
if (!is_numeric($EventId)) {
    $H_State = "Invalid ID";
    return;
}

# if the event ID actually is invalid
if (!Event::itemExists($EventId)) {
    $H_State = "Invalid ID";
    return;
}

$Event = new Event($EventId);

$H_Plugin = CalendarEvents::getInstance();

# if the entry is some other type of resource
if (!$H_Plugin->isEvent($Event)) {
    $H_State = "Not Event";
    return;
}

# if the user can't view the event
if (!$Event->userCanView(User::getCurrentUser())) {
    $H_State = "Viewing not permitted";
    return;
}

# everything is fine
$H_State = "OK";

# get start and end date to make sure we don't pass anything bad into iCalendar's constructor
$StartDate = $Event->get("Start Date");
$EndDate = is_null($Event->get("End Date")) ? $StartDate : $Event->get("End Date");

if (is_null($StartDate) && is_null($EndDate)) {
    $H_State = "Invalid Date";
    return;
} elseif (is_null($StartDate)) {
    $StartDate = $EndDate;
}

# record an iCalendar file download
$H_Plugin->recordEventiCalDownload($Event);

# construct the iCalendar document
$ICalendar = new iCalendarEvent(
    $Event->id(),
    $StartDate,
    $EndDate,
    $Event->get("All Day")
);

# add the fields for the event
$ICalendar->addCreated($Event->get("Date Of Record Creation"));
$ICalendar->addSummary(
    iCalendarEvent::transformHTMLToPlainText($Event->get("Title"))
);
$ICalendar->addDateTimeStamp($Event->get("Date Last Modified"));
$ICalendar->addDescription(iCalendarEvent::transformHTMLToPlainText(
    $Event->get("Description")
));
$ICalendar->addURL($Event->getBestUrl());
$ICalendar->addLocation($Event->oneLineLocation());
$ICalendar->addCategories($Event->categoriesForDisplay());

# set up the headers for printing the iCalendar document
$AF = ApplicationFramework::getInstance();
$AF->suppressHTMLOutput();
header("Content-Type: text/calendar; charset=".$AF->htmlCharset(), true);
header("Content-Disposition: inline; filename=\"".$ICalendar->generateFileName()."\"");

# output the iCalendar document
$Feed = new ICalendarFeed();
$Feed->addEvent($ICalendar);
print $Feed->getAsDocument();
