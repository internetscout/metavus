<?PHP
#
#   FILE:  CalendarEvents.php
#
#   A plugin for the Metavus digital collections platform
#   Copyright 2013-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins;
use Exception;
use Metavus\ControlledName;
use Metavus\FormUI;
use Metavus\MetadataField;
use Metavus\MetadataSchema;
use Metavus\Plugins\CalendarEvents\CalendarEventUI;
use Metavus\Plugins\CalendarEvents\Event;
use Metavus\Plugins\GoogleMaps;
use Metavus\Plugins\MetricsRecorder;
use Metavus\Plugins\MetricsReporter;
use Metavus\Plugins\SecondaryNavigation;
use Metavus\PrivilegeSet;
use Metavus\Record;
use Metavus\RecordFactory;
use Metavus\User;
use ScoutLib\ApplicationFramework;
use ScoutLib\Database;
use ScoutLib\Plugin;
use ScoutLib\PluginManager;

/**
 * Plugin that provides support for calendar events.
 */
class CalendarEvents extends Plugin
{
    /**
     * Register information about this plugin.
     * @return void
     */
    public function register(): void
    {
        $this->Name = "Calendar Events";
        $this->Version = "1.0.17";
        $this->Description = "Adds events calendar functionality.";
        $this->Author = "Internet Scout Research Group";
        $this->Url = "http://metavus.net";
        $this->Email = "support@metavus.net";
        $this->Requires = [
            "MetavusCore" => "1.2.0",
            "GoogleMaps" => "1.0.5",
            "MetricsRecorder" => "1.2.4",
            "MetricsReporter" => "0.9.2",
            "SocialMedia" => "1.1.0"
        ];
        $this->EnabledByDefault = false;
        $this->InitializeAfter = ["SecondaryNavigation"];
        $this->Instructions = '
            <p>
              <b>Note:</b> The calendar event metadata fields can be configured
              on the <a href="index.php?P=DBEditor">Metadata Field Editor</i></a>
              page once the plugin has been installed.
            </p>';

        $this->CfgSetup["TodayIncludesOngoingEvents"] = [
            "Type" => FormUI::FTYPE_FLAG,
            "Label" => "Use Ongoing Events for <i>Today</i> Button",
            "Help" => "Consider ongoing events, i.e., those that started before "
                ."today's date, when determing where to jump to when clicking "
                ."the <i>Today</i> button.",
            "OnLabel" => "Yes",
            "OffLabel" => "No",
            "Default" => false,
        ];

        $this->CfgSetup["CleanUrlPrefix"] = [
            "Type" => FormUI::FTYPE_TEXT,
            "Label" => "Clean URL Prefix",
            "Help" => "The prefix for the clean URLs for the events.",
            "Default" => "events",
        ];

        $this->CfgSetup["ViewMetricsPrivs"] = [
            "Type" => FormUI::FTYPE_PRIVILEGES,
            "Label" => "Privileges Required to View Metrics",
            "Help" => "The user privileges required to view event metrics.",
            "Default" => [PRIV_RESOURCEADMIN, PRIV_SYSADMIN],
            "AllowMultiple" => true,
        ];

        $this->CfgSetup["StaticMapWidth"] = [
            "Type" => FormUI::FTYPE_NUMBER,
            "Label" => "Map Width",
            "Help" => "Width of embedded maps shown on Event pages.",
            "Units" => "pixels",
            "MinVal" => 50,
            "MaxVal" => 1000,
            "Default" => 500,
        ];

        $this->CfgSetup["StaticMapHeight"] = [
            "Type" => FormUI::FTYPE_NUMBER,
            "Label" => "Map Height",
            "Help" => "Height of embedded maps shown on Event pages.",
            "Units" => "pixels",
            "MinVal" => 50,
            "MaxVal" => 1000,
            "Default" => 300,
        ];

        $this->CfgSetup["StaticMapZoom"] = [
            "Type" => FormUI::FTYPE_NUMBER,
            "Label" => "Map Zoom",
            "Help" => "<a href='https://developers.google.com/maps/documentation/"
                ."maps-static/dev-guide#Zoomlevels'>GoogleMaps zoom level </a> for "
                ."maps shown on event pages.Larger values are more detailed."
                ."Level 1 shows the world, 5 shows continents, 10 cities, 15 streets, "
                ." and 20 shows individual buildings.",
            "MinVal" => 1,
            "Default" => 14,
        ];

        $this->CfgSetup["EventsPerPage"] = [
            "Type" => FormUI::FTYPE_NUMBER,
            "Label" => "Events Per Page",
            "Help" => "The minimum number of events to display in the Past or "
                ."Upcoming events sections, if available.",
            "MinVal" => 1,
            "Default" => 10,
        ];
    }

    /**
     * Startup initialization for plugin.
     * @return null|string NULL if initialization was successful, otherwise
     *      a string containing an error message indicating why initialization
     *      failed.
     */
    public function initialize(): ?string
    {
        $CleanUrlPrefix = $this->cleanUrlPrefix();
        $RegexCleanUrlPrefix = preg_quote($CleanUrlPrefix);

        # add clean URL for viewing a single event
        $AF = ApplicationFramework::getInstance();
        $AF->addCleanUrlWithCallback(
            "%^".$RegexCleanUrlPrefix."/([0-9]+)(/[^/]*)?$%",
            "P_CalendarEvents_Event",
            ["EventId" => "\$1"],
            [$this, "CleanUrlTemplate"]
        );
        # add clean URL for viewing a single event in iCalendar format
        $AF->addCleanUrlWithCallback(
            "%^".$RegexCleanUrlPrefix."/ical/([0-9]+)(/[^/]*)$%",
            "P_CalendarEvents_iCal",
            ["EventId" => "\$1"],
            [$this, "CleanUrlTemplate"]
        );
        # add clean URL for viewing all events
        $AF->addCleanUrl(
            "%^".$RegexCleanUrlPrefix."/?$%",
            "P_CalendarEvents_Events",
            [],
            $CleanUrlPrefix
        );
        # add clean URL for viewing all a specific month of events
        $MonthRegEx = "jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec"
                ."|january|february|march|april|may|june|july"
                ."|august|september|october|november|december"
                ."|0?[1-9]|1[12]";
        $AF->addCleanUrl(
            "%^".$RegexCleanUrlPrefix."/month/([0-9]{4})/(".$MonthRegEx.")/?$%i",
            "P_CalendarEvents_Events",
            [
                "Year" => "\$1",
                "Month" => "\$2 \$1"
            ],
            $CleanUrlPrefix."/month/\$Year/\$Month"
        );

        # register our events with metrics recorder
        $PluginMgr = PluginManager::getInstance();
        $MRPlugin = MetricsRecorder::getInstance();
        $MRPlugin->registerEventType("CalendarEvents", "ViewEvent");
        $MRPlugin->registerEventType("CalendarEvents", "iCalDownload");

        # add our menu options to those offered for secondary navigation
        if ($PluginMgr->pluginReady("SecondaryNavigation") &&
            User::getCurrentUser()->isLoggedIn()) {
            $SecondaryNav = SecondaryNavigation::getInstance();
            $Schema = new MetadataSchema($this->getSchemaId());
            $SecondaryNav->offerNavItem(
                "Event List",
                "index.php?P=P_CalendarEvents_ListEvents",
                $Schema->editingPrivileges(),
                "View the list of event records."
            );
            $SecondaryNav->offerNavItem(
                "Add Event",
                str_replace('$ID', "NEW&SC=".$Schema->id(), $Schema->getEditPage()),
                $Schema->authoringPrivileges(),
                "Create a new event record."
            );
        }

        Record::registerObserver(
            Record::EVENT_ADD | Record::EVENT_SET,
            [$this, "resourceUpdated"]
        );

        # report success
        return null;
    }

    /**
     * Install this plugin.
     * @return null|string Returns NULL if everything went OK or an error
     *      message otherwise.
     */
    public function install(): ?string
    {
        # setup the default privileges for authoring and editing
        $DefaultPrivs = new PrivilegeSet();
        $DefaultPrivs->addPrivilege(PRIV_NEWSADMIN);
        $DefaultPrivs->addPrivilege(PRIV_SYSADMIN);

        # create a new metadata schema and save its ID
        $Schema = MetadataSchema::create(
            "Events",
            $DefaultPrivs,
            $DefaultPrivs,
            $DefaultPrivs,
            "index.php?P=P_CalendarEvents_Event&EventId=\$ID"
        );
        $Schema->setItemClassName("Metavus\\Plugins\\CalendarEvents\\Event");
        $Schema->setEditPage("index.php?P=EditResource&ID=\$ID");
        $this->setConfigSetting("MetadataSchemaId", $Schema->id());

        # create schema fields
        if ($Schema->addFieldsFromXmlFile("plugins/".$this->getBaseName()
            ."/install/MetadataSchema--".$this->getBaseName().".xml") === false) {
            return "Error loading ".$this->getBaseName()." metadata fields from XML: "
            .implode(" ", $Schema->errorMessages("AddFieldsFromXmlFile"));
        }

        # get the file that holds the default categories
        $DefaultCategoriesFile = @fopen($this->getDefaultCategoriesFile(), "r");

        if ($DefaultCategoriesFile === false) {
            return "Could not prepopulate the category metadata field.";
        }

        # get the categories
        $Categories = @fgetcsv($DefaultCategoriesFile, null, ",", "\"", "\\");

        if ($Categories === false) {
            return "Could not parse the default categories";
        }

        $CategoriesField = $Schema->getField("Categories");

        # add each category
        foreach ($Categories as $Category) {
            $ControlledName = ControlledName::create($Category, $CategoriesField->id());
        }

        # close the default category file
        @fclose($DefaultCategoriesFile);

        # get the ordering objects for the schema
        $DisplayOrder = $Schema->getDisplayOrder();
        $EditOrder = $Schema->getEditOrder();

        # the names of the groups to create and which fields they should hold
        # and in which order
        $GroupsToCreate = [
            "Date and Time" => [
                "Start Date",
                "End Date",
                "All Day"
            ],
            "Contact" => [
                "Contact Email",
                "Contact URL"
            ],
            "Location" => [
                "Venue",
                "Street Address",
                "Locality",
                "Region",
                "Postal Code",
                "Country",
                "Coordinates"
            ],
            "Administration" => [
                "Added By Id",
                "Last Modified By Id",
                "Date Of Record Creation",
                "Date Last Modified",
                "Date Of Record Release",
                "Owner",
            ]
        ];

        # create the groups
        foreach ($GroupsToCreate as $GroupToCreate => $FieldsToContain) {
            # create the group in each ordering
            $DisplayGroup = $DisplayOrder->createGroup($GroupToCreate);
            $EditGroup = $EditOrder->createGroup($GroupToCreate);

            # reverse the fields to contain because all of the fields are added
            # in reverse order
            $FieldsToContain = array_reverse($FieldsToContain);

            # add each field to the new groups
            foreach ($FieldsToContain as $FieldToContain) {
                # get the field object
                $Field = $Schema->getField($FieldToContain);

                try {
                    # add the field to each group for each ordering
                    $DisplayOrder->moveFieldToTopOfGroup($DisplayGroup, $Field);
                    $EditOrder->moveFieldToTopOfGroup($EditGroup, $Field);
                } catch (Exception $Exception) {
                    return "Could not move the ".$FieldToContain." field to the "
                           .$GroupToCreate." group.";
                }
            }
        }

        # update the editing and viewing privileges now that the fields have
        # been created
        $EditingPrivs = clone $DefaultPrivs;
        $EditingPrivs->addCondition($Schema->getField("Added By Id"));
        $Schema->editingPrivileges($EditingPrivs);
        $ViewingPrivs = clone $DefaultPrivs;
        $ViewingPrivs->addCondition($Schema->getField("Release Flag"), 1);
        $ViewingPrivs->addCondition($Schema->getField("Added By Id"));
        $Schema->viewingPrivileges($ViewingPrivs);

        # create index so that searching by date can be fast
        $DB = new Database();
        $SCID = $this->getSchemaId();
        $DB->query(
            "CREATE INDEX CalendarEvents_StartDateAllDay "
            ."ON Records (AllDay".$SCID.", StartDate".$SCID.");"
        );

        return null;
    }

    /**
     * Uninstall this plugin.
     * @return NULL if everything went OK or an error message otherwise.
     */
    public function uninstall(): ?string
    {
        $SCID = $this->getSchemaId();
        $Schema = new MetadataSchema($SCID);

        # delete each resource, including temp ones
        $ResourceFactory = new RecordFactory($SCID);
        foreach ($ResourceFactory->getItemIds(null, true) as $ResourceId) {
            $Resource = new Record($ResourceId);
            $Resource->destroy();
        }

        # remove index
        $DB = new Database();
        $DB->query(
            "DROP INDEX CalendarEvents_StartDateAllDay ON Records"
        );

        # delete schema
        $Schema->delete();

        return null;
    }

    /**
     * Hook event callbacks into the application framework.
     * @return array Events to be hooked into the application framework.
     */
    public function hookEvents(): array
    {
        $Events = [
            "EVENT_DAILY" => "fillInMissingCoordinates",
            "EVENT_IN_HTML_HEADER" => "inHtmlHeader",
            "EVENT_PLUGIN_EXTEND_EDIT_RESOURCE_COMPLETE_ACCESS_LIST"
                => "extendEditResourceCompleteAccessList",
        ];

        return $Events;
    }

    /**
     * Callback for constructing clean URLs to be inserted by the application
     * framework when more than regular expression replacement is required.
     * This method is passed to ApplicationFramework::AddCleanURL().
     * @param array $Matches Array of matches from preg_replace().
     * @param string $Pattern Original pattern for clean URL mapping.
     * @param string $Page Page name for clean URL mapping.
     * @param string $SearchPattern Full pattern passed to preg_replace().
     * @return string Replacement to be inserted in place of match.
     */
    public function cleanUrlTemplate($Matches, $Pattern, $Page, $SearchPattern): string
    {
        if ($Page == "P_CalendarEvents_Event") {
            # if no resource ID found
            if (count($Matches) <= 2) {
                # return match unchanged
                return $Matches[0];
            }

            # get the event from the matched ID
            $Event = new Event($Matches[2]);

            # return the replacement
            return "href=\"".defaulthtmlentities($Event->eventUrl())."\"";
        }

        # return match unchanged
        return $Matches[0];
    }

    /**
     * Print style sheet and JavaScript elements in the page header when necessary.
     * @return void
     */
    public function inHtmlHeader(): void
    {
        $AF = ApplicationFramework::getInstance();
        $PageName = $AF->getPageName();

        # only add the stylesheet for CalendarEvents pages
        if (!preg_match('/^P_CalendarEvents_/', $PageName)) {
            return;
        }

        ?>
        <link rel="stylesheet" type="text/css" href="<?= $AF->gUIFile("style.css") ?>" />
        <script type="text/javascript" src="<?= $AF->gUIFile("style.js") ?>"></script>
        <?PHP
    }

    /**
     * Callback executed whenever a resource is updated, i.e., added or modified.
     * @param int $Events Record::EVENT_* values OR'd together.
     * @param Record $Resource Just-updated resource.
     * @return void
     */
    public function resourceUpdated(int $Events, Record $Resource): void
    {
        # only handle resources that use the events metadata schema
        if (!$this->isEvent($Resource)) {
            return;
        }

        # if end date is before start date, swap them
        $StartDateTimestamp = (int)strtotime((string)$Resource->get("Start Date"));
        $EndDateTimestamp = (int)strtotime((string)$Resource->get("End Date"));
        if ($EndDateTimestamp < $StartDateTimestamp) {
            $Resource->set(
                "End Date",
                date("Y-m-d H:i:s", $StartDateTimestamp)
            );
            $Resource->set(
                "Start Date",
                date("Y-m-d H:i:s", $EndDateTimestamp)
            );
        }

        # ensure coordinates are updated
        $this->setCoordinates($Resource->id());
    }

    /**
     * Get the clean URL prefix.
     * @return string Returns the clean URL prefix.
     */
    public function cleanUrlPrefix(): string
    {
        return $this->getConfigSetting("CleanUrlPrefix");
    }

    /**
     * Get the flag that determines whether clicking the "Today" button should
     * consider ongoing events.
     * @return bool Returns the flag value.
     */
    public function todayIncludesOngoingEvents(): bool
    {
        return $this->getConfigSetting("TodayIncludesOngoingEvents");
    }

    /**
     * Get the schema ID associated with the events metadata schema.
     * @return int Returns the schema ID of the events metadata schema.
     */
    public function getSchemaId(): int
    {
        return $this->getConfigSetting("MetadataSchemaId");
    }

    /**
     * Record an event view with the Metrics Recorder plugin.
     * @param Event $Event Event.
     * @return void
     */
    public function recordEventView(Event $Event): void
    {
        # record the view event
        $PluginMgr = PluginManager::getInstance();
        MetricsRecorder::getInstance()->recordEvent(
            "CalendarEvents",
            "ViewEvent",
            $Event->id()
        );
    }

    /**
     * Record an event iCalendar file download with the Metrics Recorder plugin.
     * @param Event $Event Event.
     * @return void
     */
    public function recordEventiCalDownload(Event $Event): void
    {
        # record the view event
        $PluginMgr = PluginManager::getInstance();
        MetricsRecorder::getInstance()->recordEvent(
            "CalendarEvents",
            "iCalDownload",
            $Event->id()
        );
    }

    /**
     * Determine if a resource is also an event.
     * @param Record $Resource Resource to check.
     * @return bool Returns TRUE if the resource is also an event.
     */
    public function isEvent(Record $Resource): bool
    {
        return $Resource->getSchemaId() == $this->getSchemaId();
    }

    /**
     * Get the URL to the events list relative to the CWIS root.
     * @param array $Get Optional GET parameters to add.
     * @param string $Fragment Optional fragment ID to add.
     * @return string Returns the URL to the events list relative to the CWIS root.
     */
    public function eventsListUrl(array $Get = [], $Fragment = null): string
    {
        # if clean URLs are available
        if ((ApplicationFramework::getInstance())->cleanUrlSupportAvailable()) {
            # base part of the URL
            $Url = $this->cleanUrlPrefix()."/";
        # clean URLs aren't available
        } else {
            # base part of the URL
            $Url = "index.php";

            # add the page to the GET parameters
            $Get["P"] = "P_CalendarEvents_Events";
        }

        # tack on the GET parameters, if necessary
        if (count($Get)) {
            $Url .= "?".http_build_query($Get);
        }

        # tack on the fragment identifier, if necessary
        if (!is_null($Fragment)) {
            $Url .= "#".urlencode($Fragment);
        }

        return $Url;
    }

    /**
     * Get the metrics for an event.
     * @param Event $Event Event for which to get metrics.
     * @return array Returns an array of metrics.Keys are "Views",
     *      "iCalDownloads", "Shares/Email", "Shares/Facebook",
     *      "Shares/Twitter", and "Shares/LinkedIn".
     */
    public function getEventMetrics(Event $Event): array
    {
        # get the metrics plugins
        $PluginMgr = PluginManager::getInstance();
        $Recorder = MetricsRecorder::getInstance();
        $Reporter = MetricsReporter::getInstance();

        # get the privileges to exclude
        $Exclude = $Reporter->getConfigSetting("PrivsToExcludeFromCounts");
        if ($Exclude instanceof PrivilegeSet) {
            $Exclude = $Exclude->getPrivileges();
        }

        # get the view metrics
        $Metrics["Views"] = $Recorder->getEventData(
            "CalendarEvents",
            "ViewEvent",
            null,
            null,
            null,
            $Event->id(),
            null,
            $Exclude
        );

        # get the iCal download metrics
        $Metrics["iCalDownloads"] = $Recorder->getEventData(
            "CalendarEvents",
            "iCalDownload",
            null,
            null,
            null,
            $Event->id(),
            null,
            $Exclude
        );

        # get metrics for shares via e-mail
        $Metrics["Shares/Email"] = $Recorder->getEventData(
            "SocialMedia",
            "ShareResource",
            null,
            null,
            null,
            $Event->id(),
            SocialMedia::SITE_EMAIL,
            $Exclude
        );

        # get metrics for shares via Facebook
        $Metrics["Shares/Facebook"] = $Recorder->getEventData(
            "SocialMedia",
            "ShareResource",
            null,
            null,
            null,
            $Event->id(),
            SocialMedia::SITE_FACEBOOK,
            $Exclude
        );

        # get metrics for shares via Twitter
        $Metrics["Shares/Twitter"] = $Recorder->getEventData(
            "SocialMedia",
            "ShareResource",
            null,
            null,
            null,
            $Event->id(),
            SocialMedia::SITE_TWITTER,
            $Exclude
        );

        # get metrics for shares via LinkedIn
        $Metrics["Shares/LinkedIn"] = $Recorder->getEventData(
            "SocialMedia",
            "ShareResource",
            null,
            null,
            null,
            $Event->id(),
            SocialMedia::SITE_LINKEDIN,
            $Exclude
        );

        return $Metrics;
    }

    /**
     * Set the coordinates for the event based on its address.
     * @param int $EventId Event ID.
     * @return void
     */
    public function setCoordinates($EventId): void
    {
        # bail if the event no longer exists
        if (!Event::itemExists($EventId)) {
            return;
        }

        $Event = new Event($EventId);

        # don't deal with temp events
        if ($Event->id() < 0) {
            return;
        }

        # get the address as a one-line string
        $Address = $Event->locationString(false);

        # don't deal with events without addresses
        if (!strlen($Address)) {
            return;
        }

        # try to get the location from Google
        $Location = (ApplicationFramework::getInstance())->signalEvent(
            "GoogleMaps_EVENT_GEOCODE",
            [$Address, true]
        );

        # if the geocode info was available, set the location
        if (!is_null($Location)) {
            $Location[GoogleMaps::POINT_LATITUDE] = $Location["Lat"];
            $Location[GoogleMaps::POINT_LONGITUDE] = $Location["Lng"];
            $Event->set("Coordinates", $Location);
        }
    }

    /**
     * Periodic job to look for events that are missing coordinates and
     * fill them in.
     * @return void
     */
    public function fillInMissingCoordinates() : void
    {
        $RFactory = new RecordFactory($this->getSchemaId());

        $EventIds = $RFactory->getIdsOfMatchingRecords(["Events: Coordinates" => "NULL"]);

        foreach ($EventIds as $EventId) {
            (ApplicationFramework::getInstance())->queueUniqueTask(
                [$this, "SetCoordinates"],
                [$EventId],
                ApplicationFramework::PRIORITY_MEDIUM,
                "Set the location coordinates for a calendar event "
                ."when they become available"
            );
        }
    }

    /**
     * Print an event summary.
     * @param Event $Event Event to print.
     * @return void
     */
    public function printEventSummary(Event $Event): void
    {
        CalendarEventUI::printEventSummary($Event);
    }

    /**
     * Print an event.
     * @param Event $Event Event to print.
     * @return void
     */
    public function printEvent(Event $Event): void
    {
        CalendarEventUI::printEvent($Event);
    }

    /**
     * Print the metrics for an event.
     * @param Event $Event Event for which to print metrics.
     * @return void
     */
    public function printEventMetrics(Event $Event): void
    {
        CalendarEventUI::printEventMetrics($Event);
    }

    /**
     * Print share buttons for an event.
     * @param Event $Event Event.
     * @param int $Size The size of the share buttons.
     * @param string $Color The color of the share buttons.(NULL, "grey", or
     *      "maroon").
     * @return void
     */
    public function printShareButtonsForEvent(Event $Event, $Size = 24, $Color = null): void
    {
        CalendarEventUI::printShareButtonsForEvent($Event, $Size, $Color);
    }

    /**
     * Print extra buttons for an event.
     * @param Event $Event Event.
     * @param string $Color The color of the buttons.(NULL or "grey")
     * @return void
     */
    public function printExtraButtonsForEvent(Event $Event, $Color = null): void
    {
        CalendarEventUI::printExtraButtonsForEvent($Event, $Color);
    }

    /**
     * Print the transport controls from browsing the events calendar.
     * @param array $EventCounts An array of months mapped to the count of events for
     *      that month.
     * @param string $FirstMonth The first month that contains an event.
     * @param string $CurrentMonth The current month being displayed.
     * @param string $LastMonth The last month that contains an event.
     * @param int $PreviousMonthTimestamp The timestamp for the previous month.
     * @param int $NextMonthTimestamp The timestamp for the next month.
     * @return void
     */
    public function printTransportControls(
        array $EventCounts,
        $FirstMonth,
        $CurrentMonth,
        $LastMonth,
        $PreviousMonthTimestamp,
        $NextMonthTimestamp
    ): void {
        CalendarEventUI::printTransportControls(
            $EventCounts,
            $FirstMonth,
            $CurrentMonth,
            $LastMonth,
            $PreviousMonthTimestamp,
            $NextMonthTimestamp
        );
    }

    /**
     * Print the month selector.
     * @param array $EventCounts An array of months mapped to the count of events
     *      for that month.
     * @param string $FirstMonth The first month that contains an event.
     * @param string $LastMonth The last month that contains an event.
     * @param string $SelectedMonth The month that should be selected.
     * @return void
     */
    public function printMonthSelector(
        array $EventCounts,
        $FirstMonth,
        $LastMonth,
        $SelectedMonth = null
    ): void {
        CalendarEventUI::printMonthSelector(
            $EventCounts,
            $FirstMonth,
            $LastMonth,
            $SelectedMonth
        );
    }

    /**
     * Get the URL for the month that contains the given timestamp.
     * @param int $Timestamp Timestamp.
     * @return string Returns the URL for the month that contains the timestamp.
     */
    public function getUrl($Timestamp): string
    {
        $SafeMonth = urlencode(strtolower(date("M", $Timestamp)));
        $SafeYear = urlencode(date("Y", $Timestamp));
        return "index.php?P=P_CalendarEvents_Events&Year=".$SafeYear."&Month=".$SafeMonth;
    }

    /**
     * Determine whether a URL for the month that contains the given timestamp should
     * be displayed, based on whether the month has any events in it.
     * @param array $EventCounts An array of months mapped to the count of events for
     *      that month.
     * @param int $Timestamp Timestamp.
     * @return bool Returns TRUE if the URL should be displayed.
     */
    public function showUrl(array $EventCounts, $Timestamp): bool
    {
        $Key = date("M", $Timestamp).date("Y", $Timestamp);
        return array_key_exists($Key, $EventCounts) && $EventCounts[$Key] > 0;
    }

    /**
     * Determine if a user can author events.
     * @param User $User User to check.
     * @return bool Returns TRUE if the user can author events.
     */
    public function userCanAuthorEvents(User $User): bool
    {
        $Schema = new MetadataSchema($this->getSchemaId());
        return $User->privileges()->isGreaterThan($Schema->authoringPrivileges());
    }

    /**
     * Determine if a user can edit events.
     * @param User $User User to check.
     * @param Event $Event Optional event to check for authorship.
     * @return bool Returns TRUE if the user can edit events.
     */
    public function userCanEditEvents(User $User, ?Event $Event = null): bool
    {
        if ($Event == null) {
            $Event = PrivilegeSet::NO_RESOURCE;
        }
        $Schema = new MetadataSchema($this->getSchemaId());
        return $User->privileges()->isGreaterThan(
            $Schema->editingPrivileges(),
            $Event
        );
    }

    /**
     * Determine if a user can view event metrics.
     * @param User $User User to check.
     * @return bool Returns TRUE if the user can view event metrics.
     */
    public function userCanViewMetrics(User $User): bool
    {
        return ($this->getConfigSetting("ViewMetricsPrivs"))->meetsRequirements($User);
    }

    /**
     * Get the path to the default categories file.
     * @return string Returns the path to the default categories file.
     */
    protected function getDefaultCategoriesFile(): string
    {
        return dirname(__FILE__)."/install/DefaultCategories.csv";
    }

    /**
     * Add pages that this plugin use to EditResourceComplete's
     * "AllowedAccessList", so EditResourceComplete can be redirected from
     * these pages.
     * @param array $AccessList Previous Access List to add regexes to.
     * @return array Returns an array of regexes to match refers that are allowed.
     */
    public function extendEditResourceCompleteAccessList(array $AccessList): array
    {
        array_push($AccessList, "/P=P_CalendarEvents_ListEvents/i");
        return ["AllowList" => $AccessList];
    }
}
