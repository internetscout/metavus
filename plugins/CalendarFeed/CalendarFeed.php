<?PHP
#
#   FILE:  CalendarFeed.php
#
#   A plugin for the Metavus digital collections platform
#   Copyright 2022-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
#   @scout:phpstan

namespace Metavus\Plugins;
use Metavus\FormUI;
use Metavus\MetadataSchema;
use Metavus\MetadataField;
use Metavus\Plugins\CalendarEvents;
use Metavus\Plugins\CalendarEvents\Event;
use Metavus\Plugins\SecondaryNavigation;
use Metavus\PrivilegeSet;
use Metavus\SearchEngine;
use Metavus\SearchParameterSet;
use ScoutLib\ApplicationFramework;
use ScoutLib\iCalendarEvent;
use ScoutLib\iCalendarFeed;
use ScoutLib\Plugin;
use ScoutLib\PluginManager;

/**
 * Plugin that provides support for an ICalendar event feed.
 * ICalendar format defined in RFC5545: https://www.rfc-editor.org/rfc/rfc5545
 */
class CalendarFeed extends Plugin
{
    # ---- STANDARD PLUGIN INTERFACE -----------------------------------------

    /**
     * Register information about this plugin.
     */
    public function register(): void
    {
        $this->Name = "Calendar Feed";
        $this->Version = "1.0.0";
        $this->Description = "Plugin that adds iCalendar feed support to Metavus.";
        $this->Author = "Internet Scout Research Group";
        $this->Url = "https://metavus.net";
        $this->Email = "support@metavus.net";
        $this->Requires = [
            "MetavusCore" => "1.2.0",
            "CalendarEvents" => "1.0.15"
        ];
        $this->EnabledByDefault = false;
    }

    /**
     * Set up plugin configuration options.
     * This method is called after install() or upgrade() methods are called.
     * @return null|string NULL if configuration setup succeeded, otherwise a
     *      string or array of strings containing error message(s) indicating
     *      why config setup failed.
     */
    public function setUpConfigOptions(): ?string
    {
        $AF = ApplicationFramework::getInstance();
        $CalendarEventsPlugin = CalendarEvents::getInstance();
        $EventsSchemaId = $CalendarEventsPlugin->getSchemaId();

        $this->CfgSetup["FeedNoticeHtml"] = [
            "Type" => FormUI::FTYPE_PARAGRAPH,
            "Label" => "Feed Notice Text",
            "Help" => "HTML included with search results to provide a link"
                    . " to an iCalendar feed of events in search results. "
                    . $AF->escapeInsertionKeywords("{{URL}}")
                    . " must be included in the HTML where the Feed URL "
                    . " should be included.",
            "Default" => (
                   'Get these events as an <a href="{{URL}}">'
                   . ' iCalendar Feed</a>'),
            "Required" => true,
            "ValidateFunction" => [$this, "validateFeedNoticeHtml"]
        ];

        $this->CfgSetup["BuildFeedAdditionalFields"] = [
            "Type" => FormUI::FTYPE_METADATAFIELD,
            "FieldTypes" =>  MetadataSchema::MDFTYPE_TREE    |
                             MetadataSchema::MDFTYPE_OPTION  |
                             MetadataSchema::MDFTYPE_CONTROLLEDNAME,
            "SchemaId" => $EventsSchemaId,
            "Label" => "Additional Search Fields",
            "Help" => "Provide additional filters for specifying"
                    ." events on the build feed page.",
            "Required" => false,
            "AllowMultiple" => true
        ];

        $this->CfgSetup["BuildFeedDescriptiveText"] = [
            "Type" => FormUI::FTYPE_PARAGRAPH,
            "Label" => "Build Feed Introduction",
            "Help" => "This HTML will appear at the top of the Build Feed"
                    ." page.",
            "Default" => (
                    '<p>Calendar feeds can be added to Outlook or the Mac'
                    .' Calendar app, to automatically display upcoming events'
                    .' of interest on your personal calendar.</p><p>They can'
                    .' also be used to add lists of upcoming events to your'
                    .' website. For WordPress-based sites, free plugins like'
                    .' <a href="https://wordpress.org/plugins/ics-calendar/">'
                    .'ICS Calendar</a> will allow you to use a calendar feed to'
                    .' display a list of upcoming events on your WordPress site'
                    .'.</p>'),
            "UseWYSIWYG" => true
        ];

        return null;
    }

    /**
     * Initialize this plugin.
     * @return string|null NULL if everything went OK or an error message otherwise.
     */
    public function initialize(): ?string
    {
        $AF = ApplicationFramework::getInstance();
        $AF->registerInsertionKeywordCallback(
            "P-CALENDARFEED-FEEDNOTICE",
            [$this, "getCalendarFeedNoticeHtml"]
        );

        $PluginMgr = PluginManager::getInstance();
        if ($PluginMgr->pluginEnabled("SecondaryNavigation")) {
            $FeedPrivs = new PrivilegeSet();
            $SecondaryNav = SecondaryNavigation::getInstance();
            $SecondaryNav->offerNavItem(
                "Build Calendar Feed",
                "index.php?P=P_CalendarFeed_BuildFeed",
                $FeedPrivs,
                "Create a feed of calendar events."
            );
        }

        $AF->addSimpleCleanUrl("calendarfeeds", "P_CalendarFeed_BuildFeed");

        # report success
        return null;
    }

    # ---- INSERTION KEYWORD CALLBACKS -----------------------------------------

    /**
     * Returns the HTML for the notice and link to an iCalendar feed for events
     * that are part of the currently shown search results.
     * @return string  HTML containing a link to the iCalendar feed.
     */
    public function getCalendarFeedNoticeHtml(): string
    {
        $SearchParams = new SearchParameterSet();
        $SearchParams->urlParameters($_GET);
        $FeedNoticeHtml = $this->getConfigSetting("FeedNoticeHtml");
        $FeedUrl = self::getFeedUrl($SearchParams);
        $FeedNoticeHtml = str_replace("{{URL}}", $FeedUrl, $FeedNoticeHtml);
        return $FeedNoticeHtml;
    }


    # ---- CALLABLE METHODS --------------------------------------------------

    /**
     * Produce iCalendar feed output based on a set of search parameters.
     * @param SearchParameterSet $SearchParams To search for matching events.
     * @param $Title Title to include with feed. (OPTIONAL)
     * @return string|null Text of iCalendar feed for matching event search
     *                     results. Null if feed would contian no events.
     */
    public function generateFeedForParameters(
        SearchParameterSet $SearchParams,
        ?string $Title = null
    ): ?string {
        $CalendarEventsPlugin = CalendarEvents::getInstance();
        $EventSchemaId = $CalendarEventsPlugin->getSchemaId();

        # Restrict search results to just events.
        $SearchParams->itemTypes($EventSchemaId);


        $SearchEngine = new SearchEngine();
        $SearchResults = $SearchEngine->search($SearchParams);

        $EventIds  = array_keys($SearchResults);
        $Events = [];

        foreach ($EventIds as $EventId) {
            # if the event ID actually is invalid
            if (!Event::itemExists($EventId)) {
                continue;
            }

            $Event = new Event($EventId);

            $StartDate = $Event->get("Start Date");
            $EndDate = $Event->get("End Date");

            # make sure both start and end date are available
            if (is_null($EndDate)) {
                if (is_null($StartDate)) {
                    continue;
                } else {
                    $EndDate = $StartDate;
                }
            } elseif (is_null($StartDate)) {
                   $StartDate = $EndDate;
            }

            # construct the iCalendar document
            $ICalendarEvent = new iCalendarEvent(
                (string) $Event->id(),
                $StartDate,
                $EndDate,
                $Event->get("All Day")
            );

            # add the fields for the event
            $ICalendarEvent->addCreated($Event->get("Date Of Record Creation"));
            $ICalendarEvent->addDateTimeStamp(
                $Event->get("Date Last Modified")
            );
            $ICalendarEvent->addSummary(
                iCalendarEvent::transformHTMLToPlainText(
                    $Event->get("Title")
                )
            );
            $ICalendarEvent->addDescription(
                iCalendarEvent::transformHTMLToPlainText(
                    $Event->get("Description")
                )
            );
            $ICalendarEvent->addURL($Event->getBestUrl());
            $ICalendarEvent->addLocation($Event->oneLineLocation());
            $ICalendarEvent->addCategories($Event->categoriesForDisplay());

            $Events[] = $ICalendarEvent;
        }

        $Feed = new iCalendarFeed();
        $Feed->addEvents($Events);

        if ($Title != null) {
            $Feed->addTitle($Title);
        }

        return $Feed->getAsDocument();
    }

    /**
     * Validate Feed Notice HTML. Must include {{URL}}.
     * @param string $FieldName Name of config setting being validated
     * @param string $NewValue Setting value to validate.
     * @return string|null Error message or NULL if no error found.
     */
    public function validateFeedNoticeHtml($FieldName, $NewValue): ?string
    {
        if (!strpos($NewValue, "{{URL}}")) {
            $AF = ApplicationFramework::getInstance();
            $UrlKeyword = $AF->escapeInsertionKeywords("{{URL}}");
            return "Feed Notice HTML must contain " . $UrlKeyword . ".";
        }

        return null;
    }

    /**
     * Get 3 most recent feeds created by a specified user.
     *   Includes URL parameters and text description.
     * @param int $UserId ID of the user to return past feeds for.
     * @return array Array containing up to 3 entries, the key for each entry
     *   is the link to the feed, the value for each entry is the text
     *   description of the feed's search parameters. Returns an empty array
     *   if no feeds are saved for the * user specified by UserId.
     */
    public function getPastFeedsForUserId(int $UserId): array
    {
        $Feeds = $this->getConfigSetting("PastFeeds") ?? [];
        if (!isset($Feeds[$UserId])) {
            return [];
        }

        $PastFeeds = [];
        foreach ($Feeds[$UserId] as $Feed) {
            $FeedUrl = self::getFeedUrl($Feed["SearchParams"], $Feed["Title"]);
            $PastFeeds[$FeedUrl] = [
                "Description" => $Feed["SearchParams"]->textDescription(),
                "Title" => $Feed["Title"],
            ];
        }
        return $PastFeeds;
    }

    /**
     * Save the search parameter output that specifies a feed from the form.
     * The last three feeds created by a user are to be saved.
     * @param int $UserId   ID of user to return save the feed for.
     * @param SearchParameterSet $FeedToSave  The set of serarch parameters
     *      constructed based on the form input which specifies which events
     *      to include in a feed.
     * @param string $Title  Title for the feed. (OPTIONAL)
     */
    public function saveFeedForUserId(
        int $UserId,
        SearchParameterSet $FeedToSave,
        ?string $Title = null
    ): void {
        $Feeds = $this->getConfigSetting("PastFeeds") ?? [];
        $FeedsForUser = $Feeds[$UserId] ?? [];

        $FeedWithTitle = [
            "SearchParams" => $FeedToSave,
            "Title" => $Title
        ];

        if (in_array($FeedWithTitle, $FeedsForUser)) {
            # an identical feed is already saved, bail out
            return;
        }

        # adds a feed to the front (0th index) of the array
        array_unshift($FeedsForUser, $FeedWithTitle);

        $FeedsForUser = array_slice($FeedsForUser, 0, 3); # keep 3 most recent
        $Feeds[$UserId]  = $FeedsForUser;
        $this->setConfigSetting("PastFeeds", $Feeds);
    }

    /**
    * Get the URL for a feed of iCalendar events specified by the parameter.
    * @param SearchParameterSet $SearchParams  Search parameters that specify
    *      which events to include in the feed.
    * @param string $FeedTitle Title for the feed. (OPTIONAL)
    * @return string  URL for the feed of iCalendar events.
    */
    public static function getFeedUrl(
        SearchParameterSet $SearchParams,
        ?string $FeedTitle = null
    ): string {

        foreach ($SearchParams->getSearchStrings(true) as $FieldId => $Values) {
            $MdField = MetadataField::getField($FieldId);

            # if all possible values are specified, remove the
            # search parameter for the field so the URL is shorter
            if ($MdField->getCountOfPossibleValues() == count($Values)) {
                $SearchParams->removeParameter(null, $MdField);
            }
        }

        $Parameters = $SearchParams->urlParameterString();
        $FeedUrl = ApplicationFramework::baseUrl()
                ."index.php?P=P_CalendarFeed_Feed&"
                .$Parameters;
        if ($FeedTitle != null) {
            $FeedUrl .= "&Title=".urlencode($FeedTitle);
        }
        return $FeedUrl;
    }
}
