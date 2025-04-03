<?PHP
#
#   FILE:  Feed.php (CalendarFeed plugin)
#
#   Part of the Metavus digital collections platform
#   Copyright 2022 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#

use Metavus\SearchParameterSet;
use Metavus\Plugins\CalendarFeed;
use ScoutLib\ApplicationFramework;
use ScoutLib\PluginManager;
use ScoutLib\StdLib;

# ----- MAIN -----------------------------------------------------------------

$AF = ApplicationFramework::getInstance();
$PluginMgr = PluginManager::getInstance();

$AF->suppressHTMLOutput();

# Fail if the CalendarEvents plugin is not ready.
if (!$PluginMgr->pluginReady("CalendarEvents")) {
    http_response_code(500);
    return;
}

try {
    $SearchParams = new SearchParameterSet();
    $SearchParams->urlParameters($_GET);
} catch (InvalidArgumentException $e) {
    http_response_code(500);
    echo 'Invalid search parameters.';
    return;
}

if ($SearchParams->parameterCount() == 0) {
    http_response_code(500);
    echo 'Invalid search parameters.';
    return;
}

$FeedTitle = null;
if (isset($_GET["Title"])) {
    $FeedTitle = $_GET["Title"];
}

$CalendarFeedPlugin = CalendarFeed::getInstance();
// @phpstan-ignore-next-line
$FeedText = $CalendarFeedPlugin->generateFeedForParameters(
    $SearchParams,
    $FeedTitle
);

# Search parameters are valid, but no events are found.
if ($FeedText === null) {
    http_response_code(204);
    return;
}


# Set up the headers for printing the iCalendar document.
header("Content-Type: text/calendar; charset=".$AF->htmlCharset(), true);
header("Content-Disposition: inline; filename=\"events.ics\"");

# Output the iCalendar document.
print $FeedText;
