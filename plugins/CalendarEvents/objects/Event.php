<?PHP
#
#   FILE:  Event.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2013-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins\CalendarEvents;
use DateTime;
use Metavus\MetadataSchema;
use Metavus\Plugins\CalendarEvents;
use Metavus\Record;
use Metavus\User;
use Metavus\UserFactory;
use ScoutLib\ApplicationFramework;
use ScoutLib\PluginManager;
use ScoutLib\StdLib;

/**
* Represents an event resource.
*/
class Event extends Record
{
    /**
     * The format for a date for display.
     */
    const DATE_FOR_DISPLAY = 'F jS, Y';

    /**
     * The format for a date/time for display.
     */
    const DATETIME_FOR_DISPLAY = 'F jS, Y \a\t g:i A';

    /**
     * The format for a time for display.
     */
    const TIME_FOR_DISPLAY = 'g:i A';

    /**
     * The format for a date for machine parsing.
     */
    const DATE_FOR_PARSING = 'Y-m-d';

    /**
     * The format for a date/time for machine parsing.
     */
    const DATETIME_FOR_PARSING = 'c';

    /**
     * Get the URL to the event relative to the CWIS root.
     * @param array $Get Optional GET parameters to add.
     * @param string $Fragment Optional fragment ID to add.
     * @return string URL to the event relative to the CWIS root.
     */
    public function eventUrl(array $Get = [], $Fragment = null): string
    {
        # if clean URLs are available
        if (ApplicationFramework::getInstance()->cleanUrlSupportAvailable()) {
            $Plugin = CalendarEvents::getInstance();

            # base part of the URL
            $Url = $Plugin->cleanUrlPrefix() . "/" . urlencode((string)$this->id()) . "/";

            # add the title
            $Url .= urlencode($this->titleForUrl());
        # clean URLs aren't available
        } else {
            # base part of the URL
            $Url = "index.php";

            # add the page to the GET parameters
            $Get["P"] = "P_CalendarEvents_Event";
            $Get["EntryId"] = $this->id();
        }

        # tack on the GET parameters, if necessary
        if (count($Get)) {
            $Url .= "?" . http_build_query($Get);
        }

        # tack on the fragment identifier, if necessary
        if (!is_null($Fragment)) {
            $Url .= "#" . urlencode($Fragment);
        }

        return $Url;
    }

    /**
     * Get the URL to the event in iCalendar format relative to the CWIS root.
     * @param array $Get Optional GET parameters to add.
     * @param string $Fragment Optional fragment ID to add.
     * @return string the URL to the event in iCalendar format relative to the
     *      CWIS root.
     */
    public function iCalUrl(array $Get = [], $Fragment = null): string
    {
        # if clean URLs are available
        if (ApplicationFramework::getInstance()->cleanUrlSupportAvailable()) {
            $Plugin = CalendarEvents::getInstance();

            # base part of the URL
            $Url = $Plugin->cleanUrlPrefix() . "/ical/" . urlencode((string)$this->id()) . "/";

            # add the title
            $Url .= urlencode($this->titleForUrl());
        # clean URLs aren't available
        } else {
            # base part of the URL
            $Url = "index.php";

            # add the page to the GET parameters
            $Get["P"] = "P_CalendarEvents_iCal";
            $Get["EntryId"] = $this->id();
        }

        # tack on the GET parameters, if necessary
        if (count($Get)) {
            $Url .= "?" . http_build_query($Get);
        }

        # tack on the fragment identifier, if necessary
        if (!is_null($Fragment)) {
            $Url .= "#" . urlencode($Fragment);
        }

        return $Url;
    }

    /**
     * Get the best URL to use for the event, meaning the Url field when set and
     * the URL to the event record otherwise.
     * @return string the best URL to use for the event.
     */
    public function getBestUrl(): string
    {
        $Url = $this->get("Url");

        if ($Url) {
            return $Url;
        }

        return ApplicationFramework::baseUrl() . $this->eventUrl();
    }

    /**
     * Determine if the event occurs in the future.
     * @return bool TRUE if the event occurs in the future.
     */
    public function isInFuture(): bool
    {
        # make the date precise only to the day if the event occurs all day
        if ($this->get("All Day")) {
            $Date = date("Y-m-d", strtotime($this->get("Start Date")));
            return strtotime($Date) > time();
        }

        return $this->startDateAsObject()->getTimestamp() > time();
    }

    /**
     * Determine if the event is currently occuring.
     * @return bool TRUE if the event is currently occuring.
     */
    public function isOccurring(): bool
    {
        return !($this->isInFuture() || $this->isInPast());
    }

    /**
     * Determine if the event occurs in the past.
     * @return bool TRUE if the event occurs in the past.
     */
    public function isInPast(): bool
    {
        # make the date precise only to the day if the event occurs all day
        if ($this->get("All Day")) {
            $Date = date("Y-m-d", strtotime($this->get("End Date")));
            return strtotime($Date) < time();
        }

        return $this->endDateAsObject()->getTimestamp() < time();
    }

    /**
     * Determine if the event starts at some point today.
     * @return bool TRUE if the event starts at some point today.
     */
    public function startsToday(): bool
    {
        return date("Y-m-d") == date("Y-m-d", strtotime($this->get("Start Date")));
    }

    /**
     * Determine if the event ends at some point today.
     * @return bool TRUE if the event ends at some point today.
     */
    public function endsToday(): bool
    {
        return date("Y-m-d") == date("Y-m-d", strtotime($this->get("End Date")));
    }

    /**
     * Get the start date as a DateTime object.
     * @return \DateTime the start date as a DateTime object.
     */
    public function startDateAsObject(): \DateTime
    {
        if (!is_null($this->get("Start Date"))) {
            return $this->convertDateStringToObject($this->get("Start Date"));
        }
        return new DateTime();
    }

    /**
     * Get the end date as a DateTime object.
     * @return \DateTime the end date as a DateTime object.
     */
    public function endDateAsObject(): \DateTime
    {
        if (!is_null($this->get("End Date"))) {
            return $this->convertDateStringToObject($this->get("End Date"));
        }
        return new DateTime();
    }

    /**
     * Get the creation date as a DateTime object.
     * @return \DateTime the creation date as a DateTime object.
     */
    public function creationDateAsObject(): \DateTime
    {
        return $this->convertDateStringToObject($this->get("Date Of Record Creation"));
    }

    /**
     * Get the modification date as a DateTime object.
     * @return \DateTime the modification date as a DateTime object.
     */
    public function modificationDateAsObject(): \DateTime
    {
        return $this->convertDateStringToObject($this->get("Date Last Modified"));
    }

    /**
     * Get the release date as a DateTime object.
     * @return \DateTime the release date as a DateTime object.
     */
    public function releaseDateAsObject(): \DateTime
    {
        return $this->convertDateStringToObject($this->get("Release Date"));
    }

    /**
     * Get the categories field value for displaying to users.
     * @return array Returns the categories field value for display to users.
     */
    public function categoriesForDisplay(): array
    {
        $ControlledNames = $this->get("Categories", true);

        # there are no categories assigned to the event
        if (is_null($ControlledNames)) {
            return [];
        }

        $Categories = [];

        foreach ($ControlledNames as $Id => $Category) {
            $Categories[$Id] = $Category->name();
        }

        return $Categories;
    }

    /**
     * Get the attachments field value for displaying to users.
     * @return array Returns the attachments field value for display to users.
     */
    public function attachmentsForDisplay(): array
    {
        $Attachments = [];

        foreach ($this->get("Attachments", true) as $Id => $Attachment) {
            $Attachments[$Id] = [$Attachment->name(), $Attachment->getLink()];
        }

        return $Attachments;
    }

    /**
     * Get the release flage field value for displaying to users.
     * @return string Returns the release flag field value for display to users.
     */
    public function releaseFlagForDisplay(): string
    {
        $Schema = new MetadataSchema($this->getSchemaId());
        $Field = $Schema->getField("Release Flag");

        if ($this->get("Release Flag")) {
            return $Field->flagOnLabel();
        }

        return $Field->flagOffLabel();
    }

    /**
     * Get the start date field value for displaying to users.
     * @return string Returns the start date field value for display to users.
     */
    public function startDateForDisplay(): string
    {
        # do not show the the year on the start date unless the event only
        # spans one day *and* the start date is not in the current year
        $StartDateYear = $this->startDateAsObject()->format("Y");

        if ((($StartDateYear == $this->endDateAsObject()->format("Y"))
                && ($this->spanInDays() > 1))
                || $StartDateYear == date("Y") ) {
                $OmitYearFromStartDate = true;
        } else {
                $OmitYearFromStartDate = false;
        }

        # getPrettyDate returns a date with the name of the month spelled out,
        # also puts the "th" on August 5th
        # passing true to getPrettyTimestamp sets the verbose option to use the
        # longer form expression of the date
        $FormattedStartDate =
                StdLib::getPrettyDate($this->get("Start Date"), true);

        # if date range was invalid display --
        if ($FormattedStartDate == "--") {
            return "--";
        }

        # strings used to determine when a date is too pretty
        $TooPretty = [
            "Today", "Yesterday", "Tomorrow", "Sunday", "Monday", "Tuesday",
            "Wednesday", "Thursday", "Friday", "Saturday"
        ];

        # if the date is "too pretty"
        if (in_array($FormattedStartDate, $TooPretty)) {
            $DateFormat = $OmitYearFromStartDate ?
                    str_replace(", Y", "", Event::DATE_FOR_DISPLAY) :
                    Event::DATE_FOR_DISPLAY;
            return $this->startDateAsObject()->format($DateFormat);
        }

        if ($OmitYearFromStartDate) {
            $FormattedStartDate =
                    str_replace(", ".$StartDateYear, "", $FormattedStartDate);
        }
        return $FormattedStartDate;
    }

    /**
     * Get the end date field value for displaying to users.
     * @return string Returns the end date field value for display to users.
     */
    public function endDateForDisplay(): string
    {
        # passing true to getPrettyTimestamp sets the verbose option to use the
        # longer form expression of the date
        $FormattedEndDate = StdLib::getPrettyDate($this->get("End Date"), true);

        # if date range was invalid display --
        if ($FormattedEndDate == "--") {
            return "--";
        }

        # strings used to determine when a date is too pretty
        $TooPretty = [
            "Today", "Yesterday", "Tomorrow", "Sunday", "Monday", "Tuesday",
            "Wednesday", "Thursday", "Friday", "Saturday"
        ];

        # drop the year off of the date format if the year is the current year
        if (in_array($FormattedEndDate, $TooPretty)) {
            if (date("Y") == date("Y", strtotime($this->get("End Date")))) {
                # if the end date is in the current year, remove the year
                # from the date format
                $DateFormat =  str_replace(", Y", "", Event::DATE_FOR_DISPLAY);
            } else {
                $DateFormat = Event::DATE_FOR_DISPLAY;
            }
            return $this->endDateAsObject()->format($DateFormat);
        }
        return $FormattedEndDate;
    }

    /**
     * Get the start date time for displaying to users.
     * @return string Returns the start date time for display to users.
     */
    public function startDateTimeForDisplay(): string
    {
        $StartDate = $this->startDateForDisplay();
        # startDateForDisplay calls getPrettyTimestamp and avoids using
        # named days of the week or yesterday/today/tomorrow

        $StartTime = date("g:ia", strtotime($this->get("Start Date")));
        $DateWithTime = $StartDate." at ".$StartTime;

        # passing true to getPrettyTimestamp sets that function's
        # "verbose" option, which indicates the long form string
        # expression of the date should be returned

        # if both the start or end date is not midnight, print the start time
        # even if it *is* midnight
        if ($this->checkIfStartAndEndTimesAreBothMidnight()) {
            return str_replace(
                [" at 12:00am"],
                "",
                $DateWithTime
            );
        }
        # if the start and end time are not both midnight, print the start time
        # even if it *is* midnight
        return $DateWithTime;
    }

    /**
     * Get the end date time for displaying to users.
     * @return string Returns the end date time for display to users.
     */
    public function endDateTimeForDisplay(): string
    {
        $PartsOfTimestampToRemove = [];

        if ($this->checkIfStartAndEndTimesAreBothMidnight()) {
            # if both start and end time are midnight, exclude the end time
            # from the printed date and time
            # EndDateAsObject() returns the time as "12:00am", not "at 12:00am"
            $PartsOfTimestampToRemove = ["at 12:00am", "12:00am"];
        }

        # if the event spans one day, print only the time and not the end date
        if ($this->spanInDays() == 1) {
            $DatePart =
                    StdLib::getPrettyDate($this->get("End Date"), true)." at ";
            $PartsOfTimestampToRemove [] = $DatePart;
        }

        # passing true to getPrettyTimestamp sets the verbose option to use the
        # longer form expression of the date
        $EndDate = str_replace(
            $PartsOfTimestampToRemove,
            "",
            StdLib::getPrettyTimestamp($this->get("End Date"), true)
        );

        return $EndDate;
    }

    /**
     * Get the author field value for displaying to users.
     * @return string the author field value for display to users.
     */
    public function authorForDisplay(): string
    {
        return $this->formatUserNameForDisplay($this->get("Added By Id", true));
    }

    /**
     * Get the editor field value for displaying to users.
     * @return string the editor field value for display to users.
     */
    public function editorForDisplay(): string
    {
        return $this->formatUserNameForDisplay($this->get("Last Modified By Id", true));
    }

    /**
     * Get the creation date field value for displaying to users.
     * @return string the creation date field value for display to users.
     */
    public function creationDateForDisplay(): string
    {
        return StdLib::getPrettyTimestamp($this->get("Date Of Record Creation"));
    }

    /**
     * Get the modification date field value for displaying to users.
     * @return string the modification date field value for display to users.
     */
    public function modificationDateForDisplay(): string
    {
        return StdLib::getPrettyTimestamp($this->get("Date Last Modified"));
    }

    /**
     * Get the release date field value for displaying to users.
     * @return string the release date field value for display to users.
     */
    public function releaseDateForDisplay(): string
    {
        return StdLib::getPrettyTimestamp($this->get("Release Date"));
    }

    /**
     * Get the start date field value for machine parsing.
     * @return string the start date field value for machine parsing.
     */
    public function startDateForParsing(): string
    {
        $Format = $this->get("All Day")
            ? self::DATE_FOR_PARSING : self::DATETIME_FOR_PARSING;
        return $this->startDateAsObject()->format($Format);
    }

    /**
     * Get the end date field value for machine parsing.
     * @return string the end date field value for machine parsing.
     */
    public function endDateForParsing(): string
    {
        $Format = $this->get("All Day")
            ? self::DATE_FOR_PARSING : self::DATETIME_FOR_PARSING;
        return $this->endDateAsObject()->format($Format);
    }

    /**
     * Get the entire location as a space-separated string.
     * @param bool $IncludeVenue TRUE to include venue information
     *   (OPTIONAL, default TRUE).
     * @return string the entire location as a space-separated string
     */
    public function locationString($IncludeVenue = true): string
    {
        # start out with an empty location string
        $Location = "";

        # add the venue name if given
        if ($this->get("Venue") && $IncludeVenue) {
            $Location .= $this->get("Venue") . " ";
        }

        # add the street address if given
        if ($this->get("Street Address")) {
            $Location .= $this->get("Street Address") . " ";
        }

        # add the locality if given
        if ($this->get("Locality")) {
            $Location .= $this->get("Locality") . " ";
        }

        # add the region if given
        if ($this->get("Region")) {
            $Location .= $this->get("Region") . " ";
        }

        # add the postal code if given
        if ($this->get("Postal Code")) {
            $Location .= $this->get("Postal Code") . " ";
        }

        # add the country if given
        if ($this->get("Country")) {
            $Location .= $this->get("Country");
        }

        # remove trailing whitespace
        $Location = trim($Location);

        # return the location
        return $Location;
    }

    /**
     * Get the summary of the event location in the order of Locality, Reigion
     * -> Country.
     *
     * @return string Summary of the event location, or empty string if none provided.
     */
    public function locationSummaryString(): string
    {
        if ($this->get("Region") && $this->get("Locality")) {
            return $this->get("Locality") . ", " . $this->get("Region");
        } elseif ($this->get("Country")) {
            return $this->get("Country");
        } else {
            return "";
        }
    }

    /**
     * Get the location of the event as one line of plain text with most tokens
     * separated by commas as in normal US format.
     * @return string location of the event as one line of plain text.
     */
    public function oneLineLocation(): string
    {
        # start out with an empty location string
        $Location = "";

        # add the venue name if given
        if ($this->get("Venue")) {
            $Location .= $this->get("Venue") . ", ";
        }

        # don't add the street address if given
        if ($this->get("Street Address")) {
            $Location .= $this->get("Street Address") . ", ";
        }

        # add the locality if given
        if ($this->get("Locality")) {
            $Location .= $this->get("Locality") . ", ";
        }

        # add the region if given
        if ($this->get("Region")) {
            $Suffix = $this->get("Postal Code") ? " " : ", ";
            $Location .= $this->get("Region") . $Suffix;
        }

        # add the postal code if given
        if ($this->get("Postal Code")) {
            $Location .= $this->get("Postal Code") . ", ";
        }

        # add the country if given
        if ($this->get("Country")) {
            $Location .= $this->get("Country");
        }

        # remove trailing whitespace and commas
        $Location = trim($Location, " \t\n\r\0\x0B,");

        # return the location
        return $Location;
    }

    /**
     * Get the entire location as HTML.
     * @return string the entire location as HTML.
     */
    public function locationForHtml(): string
    {
        # start out with an empty location string
        $Location = "";

        # add the venue name if given
        if ($this->get("Venue")) {
            $Location .=
                '<span class="calendar_events-venue" itemprop="name">'
                .defaulthtmlentities($this->get("Venue"))
                .'</span>';
        }

        # add the street address if given
        if ($this->get("Street Address")) {
            $Location .=
                '<span class="calendar_events-street_address" itemprop="streetAddress">'
                .defaulthtmlentities($this->get("Street Address"))
                .'</span>';
        }

        # add the locality if given
        if ($this->get("Locality")) {
            $Location .=
                '<span class="calendar_events-locality" itemprop="addressLocality">'
                .defaulthtmlentities($this->get("Locality"))
                .'</span>';
        }

        # add the region if given
        if ($this->get("Region")) {
            $Location .=
                '<span class="calendar_events-region" itemprop="addressRegion">'
                .defaulthtmlentities($this->get("Region"))
                .'</span>';
        }

        # add the postal code if given, but only if there's a locality or region
        if ($this->get("Postal Code") && ($this->get("Locality") || $this->get("Region"))) {
            $Location .=
                '<span class="calendar_events-postal_code" itemprop="postalCode">'
                .defaulthtmlentities($this->get("Postal Code"))
                .'</span>';
        }

        # add the country if given
        if ($this->get("Country")) {
            $Location .=
                '<span class="calendar_events-country" itemprop="addressCountry">'
                .defaulthtmlentities($this->get("Country"))
                .'</span>';
        }

        # wrap the address if anything has been added so far
        if (strlen($Location) > 0) {
            $Location = '<span class="calendar_events-location" itemprop="location"'
                            .' itemscope="itemscope" itemtype="http://schema.org/Place">'
                          .$Location
                       .'</span>';
        }

        # return the location
        return $Location;
    }

    /**
     * Get the span of the event in days.
     * @return int the span of the event in days.
     */
    public function spanInDays(): int
    {
        $StartDate = $this->startDateAsObject();
        $EndDate = $this->endDateAsObject();

        # get the difference between the dates
        $Difference = intval($EndDate->diff($StartDate, true)->format('%a'));

        # set the value to two days if the difference is less than a day but the
        # interval crosses a day boundary
        if ($Difference === 0 && $StartDate->format("d") != $EndDate->format("d")) {
            return 2;
        }

        # otherwise the difference is plus one day because the interval doesn't
        # account for the event happening throughout the day
        return $Difference + 1;
    }

    /**
     * Get the title field value for inserting into a URL.
     * @return string the title field value for inserting into a URL.
     */
    public function titleForUrl(): string
    {
        $SafeTitle = str_replace(" ", "-", $this->get("Title"));
        $SafeTitle = preg_replace('/[^a-zA-Z0-9-]/', "", (string)$SafeTitle);
        $SafeTitle = strtolower(trim($SafeTitle));

        return $SafeTitle;
    }

    /**
     * Get the date prefix for the start date field value for displaying to
     * users.
     * @return string the date prefix for the start date field value for
     *      displaying to users.
     */
    public function startDateDisplayPrefix(): string
    {
        # use the date prefix if it's a full-day event
        if ($this->get("All Day")) {
            return $this->getDatePrefix($this->get("Start Date"));
        }

        # otherwise use the timestamp prefix
        return $this->getTimestampPrefix($this->get("Start Date"));
    }

    /**
     * Get the date prefix for the end date field value for displaying to
     * users.
     * @return string the date prefix for the end date field value for
     *      displaying to users.
     */
    public function endDateDisplayPrefix(): string
    {
        # use the date prefix if it's a full-day event
        if ($this->get("All Day")) {
            return $this->getDatePrefix($this->get("End Date"));
        }

        # otherwise use the timestamp prefix
        return $this->getTimestampPrefix($this->get("End Date"));
    }

   /**
    * Check if both the start and end time of an event are midnight.
    * @return bool True if the start and end time of an event are both
    *         midnight, false otherwise.
    */
    public function checkIfStartAndEndTimesAreBothMidnight(): bool
    {
        $StartTime = date("g:ia", strtotime($this->get("Start Date")));
        $EndTime = date("g:ia", strtotime($this->get("End Date")));
        if ($StartTime == "12:00am" && $EndTime == "12:00am") {
            return true;
        }
        return false;
    }

    /**
     * Format a user's name for display, using the real name if available and the
     * user name otherwise.
     * @param array $User containing user of which to get the name.
     * @return string the user's name for display.
     */
    protected function formatUserNameForDisplay(array $User): string
    {
        $User = array_shift($User);

        # the user isn't set
        if (!($User instanceof User)) {
            return "-";
        }

        # the user is invalid
        $UserFactory = new UserFactory();
        if (!$UserFactory->userNameExists($User->name())) {
            return "-";
        }

        # get the real name or user name if it isn't available
        $BestName = $User->getBestName();

        # blank best name
        if (!strlen($BestName)) {
            return "-";
        }

        return $BestName;
    }

    /**
     * Get the date prefix for a timestamp for displaying to users, e.g., "on",
     * "at", etc.
     * @param string $Timestamp Timestamp for which to get a date prefix
     * @return string the date prefix for a timestamp.
     */
    protected function getDatePrefix(string $Timestamp): string
    {
        # convert timestamp to seconds
        $Timestamp = strtotime($Timestamp);

        # invalid timestamp
        if ($Timestamp === false) {
            return "";
        }

        # up until yesterday
        if ($Timestamp < strtotime("yesterday")) {
            return "";
        }

        # before yesterday
        return "on";
    }

    /**
     * Get the date prefix for a timestamp for displaying to users, e.g., "on",
     * "at", etc.
     * @param string $Timestamp Timestamp for which to get a date prefix
     * @return string the date prefix for a timestamp.
     */
    protected function getTimestampPrefix(string $Timestamp): string
    {
        # convert timestamp to seconds
        $Timestamp = strtotime($Timestamp);

        # invalid timestamp
        if ($Timestamp === false) {
            return "";
        }

        # today
        if (date("z Y", $Timestamp) == date("z Y")) {
            return "at";
        }

        # yesterday
        if (date("n/j/Y", ($Timestamp - (24 * 60 * 60))) == date("n/j/Y")) {
            return "";
        }

        # before yesterday
        return "on";
    }

    /**
     * Convert a date or date/time string to a DateTime object.
     * @param string $DateOrDateTime A date or date/time string.
     * @param string $TZID An optional time zone ID.
     * @return DateTime object for given string.
     */
    protected function convertDateStringToObject(string $DateOrDateTime, $TZID = null): \DateTime
    {
        # if a time zone ID isn't available
        if (is_null($TZID)) {
            return new DateTime($DateOrDateTime);
        # a time zone ID is available, so use it
        } else {
            try {
                return new DateTime($DateOrDateTime, new \DateTimeZone($TZID));
            } catch (\Exception $Exception) {
                return new DateTime($DateOrDateTime);
            }
        }
    }

    /**
     * Set database access values; overridden here because Events are
     * stored in the Resources table.
     *@param string $ClassName Class name to set values for.
     */
    protected static function setDatabaseAccessValues(string $ClassName): void
    {
        if (!isset(self::$ItemIdColumnNames[$ClassName])) {
            self::$ItemIdColumnNames[$ClassName] = "RecordId";
            self::$ItemNameColumnNames[$ClassName] = "ResourceName";
            self::$ItemTableNames[$ClassName] = "Records";
        }
    }
}
