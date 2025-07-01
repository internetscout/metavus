<?PHP
#
#   FILE:  Event.php (CalendarEvents plugin)
#
#   Part of the Metavus digital collections platform
#   Copyright 2013-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

use Metavus\Plugins\CalendarEvents;
use Metavus\Plugins\CalendarEvents\Event;
use Metavus\User;
use ScoutLib\ApplicationFramework;
use ScoutLib\StdLib;

$AF = ApplicationFramework::getInstance();
$H_Plugin = CalendarEvents::getInstance();

# assume that a generic error will occur
$H_State = "Error";

# get object parameters
$EventId = StdLib::getArrayValue($_GET, "EventId");

# if the event ID looks invalid
if (!is_numeric($EventId)) {
    $AF->doNotCacheCurrentPage();
    $H_State = "Invalid ID";
    return;
}

# if the event ID actually is invalid
if (!Event::itemExists((int)$EventId)) {
    $AF->doNotCacheCurrentPage();
    $H_State = "Invalid ID";
    return;
}

$H_Event = new Event((int)$EventId);

# if the entry is some other type of resource
if (!$H_Plugin->isEvent($H_Event)) {
    $H_State = "Not Event";
    return;
}

# if the user can't view the event
if (!$H_Event->userCanView(User::getCurrentUser())) {
    $H_State = "Viewing not permitted";
    return;
}

# get the events's metrics
$H_Metrics = $H_Plugin->getEventMetrics($H_Event);

# record an event
$H_Plugin->recordEventView($H_Event);

# everything is fine
$H_State = "OK";

# signal view of full event info
ApplicationFramework::getInstance()->signalEvent(
    "EVENT_FULL_RECORD_VIEW",
    ["ResourceId" => $EventId]
);
