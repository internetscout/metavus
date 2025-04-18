<?PHP
#
#   FILE:  Home.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2021-2023 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# VALUES PROVIDED to INTERFACE (REQUIRED):
#   $H_Collections - Current collections of items (Collection instances),
#       with collection IDs for the index. If collection display on the home page is disabled,
#       then it will be set to false.
# VALUES PROVIDED to INTERFACE (OPTIONAL):
#   $H_NewsItems - News items (Plugin\Blog\Entry instances), with news item
#       IDs for the index.  Only set if news is enabled, Blog plugin is ready,
#       and blog exists with the configured name.
#   $H_Events - Events (Plugin\CalendarEvents\Event instances) with event IDs
#       for the index. Only set if CalendarEvents plugin is ready, and the
#       interface setting NumEventsOnHomePage > 0.
#
# @scout:phpstan

namespace Metavus;

use Metavus\Plugins\Blog\Entry;
use Metavus\Plugins\Blog\EntryFactory;
use Metavus\Plugins\CalendarEvents\Event;
use Metavus\Plugins\CalendarEvents\EventFactory;
use ScoutLib\PluginManager;

# ----- LOCAL FUNCTIONS ------------------------------------------------------

/**
 * Load collections for display.
 * @return array|false Collections (Collection instances), with collection IDs for
 *      the index. If collection display on the home page is disabled, then returns false.
 */
function loadCollections()
{
    $IntConfig = InterfaceConfiguration::getInstance();

    if (!$IntConfig->getBool("DisplayCollectionsOnHomePage")) {
        return false;
    }

    $User = User::getCurrentUser();
    $CFactory = new CollectionFactory();
    $MaxCollections = $IntConfig->getInt("NumCollectionsOnHomePage");

    $AllCollectionIds = $CFactory->getItemIds();

    $ViewableCollectionIds = $CFactory->getFirstNViewableRecords(
        $AllCollectionIds,
        $User,
        $MaxCollections
    );

    $ViewableCollections = [];
    foreach ($ViewableCollectionIds as $CollectionId) {
        $Collection = new Collection($CollectionId);
        $ViewableCollections[$CollectionId] = $Collection;
    }
    return $ViewableCollections;
}

/**
 * Load news items for display.  Assumes that Blog plugin is ready.
 * @return array News items (Entry instances).
 */
function loadNews(): array
{
    $PluginMgr = PluginManager::getInstance();
    $BlogPlugin = $PluginMgr->getPlugin("Blog");
    $IntConfig = InterfaceConfiguration::getInstance();

    $NewsBlogId = $IntConfig->getInt("NewsBlogId");
    $MaxNewsItems = $IntConfig->getInt("NumAnnounceOnHomePage");

    $AllBlogs = $BlogPlugin->getAvailableBlogs();
    if (!isset($AllBlogs[$NewsBlogId])) {
        # (fall back to blog named "News" if available to provide legacy behavior)
        $NewsBlogId = $BlogPlugin->getBlogIdByName("News");
        if ($NewsBlogId === false) {
            return [];
        }
    }

    $EFactory = new EntryFactory($NewsBlogId);
    $NewsItemIds = $EFactory->getRecordIdsSortedBy(
        "Date of Publication",
        false,
        $MaxNewsItems
    );

    $User = User::getCurrentUser();
    $NewsItems = [];
    foreach ($NewsItemIds as $NewsItemId) {
        $NewsItem = new Entry($NewsItemId);
        if ($NewsItem->userCanView($User)) {
            $NewsItems[$NewsItemId] = $NewsItem;
        }
    }

    return $NewsItems;
}

/**
 * Load events for display.
 * @return array Event instances.
 */
function loadEvents(): array
{
    $IntConfig = InterfaceConfiguration::getInstance();
    $MaxEvents = $IntConfig->getInt("NumEventsOnHomePage");
    $EFactory = new EventFactory();
    $EventIds = $EFactory->getIdsOfUpcomingEvents(true, $MaxEvents);
    $Events = [];
    foreach ($EventIds as $EventId) {
        $Events[$EventId] = new Event($EventId);
    }
    return $Events;
}

# ----- MAIN -----------------------------------------------------------------

# load news if enabled
$SysConfig = SystemConfiguration::getInstance();
$IntConfig = InterfaceConfiguration::getInstance();
$PluginMgr = PluginManager::getInstance();
if ($IntConfig->getBool("AnnouncementsEnabled")
        && $PluginMgr->pluginReady("Blog")) {
    $H_NewsItems = loadNews();
}

# load collection info
$H_Collections = loadCollections();

# load events if enabled and NumEventsOnHomePage > 0

if ($IntConfig->getBool("EventsEnabled")
        && $PluginMgr->pluginReady("CalendarEvents")
        && $IntConfig->getInt("NumEventsOnHomePage") > 0) {
    $H_Events = loadEvents();
}
