<?PHP
#
#   FILE:  Event.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2013-2022 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins\CalendarEvents;

use DateTime;
use Metavus\MetadataSchema;
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
            $Plugin = PluginManager::getInstance()->getPlugin("CalendarEvents");

            # base part of the URL
            $Url = $Plugin->CleanUrlPrefix() . "/" . urlencode((string)$this->Id()) . "/";

            # add the title
            $Url .= urlencode($this->TitleForUrl());
        # clean URLs aren't available
        } else {
            # base part of the URL
            $Url = "index.php";

            # add the page to the GET parameters
            $Get["P"] = "P_CalendarEvents_Event";
            $Get["EntryId"] = $this->Id();
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
            $Plugin = PluginManager::getInstance()->getPlugin("CalendarEvents");

            # base part of the URL
            $Url = $Plugin->CleanUrlPrefix() . "/ical/" . urlencode((string)$this->Id()) . "/";

            # add the title
            $Url .= urlencode($this->TitleForUrl());
        # clean URLs aren't available
        } else {
            # base part of the URL
            $Url = "index.php";

            # add the page to the GET parameters
            $Get["P"] = "P_CalendarEvents_iCal";
            $Get["EntryId"] = $this->Id();
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
        $Url = $this->Get("Url");

        if ($Url) {
            return $Url;
        }

        return ApplicationFramework::BaseUrl() . $this->EventUrl();
    }

    /**
     * Determine if the event occurs in the future.
     * @return bool TRUE if the event occurs in the future.
     */
    public function isInFuture(): bool
    {
        # make the date precise only to the day if the event occurs all day
        if ($this->Get("All Day")) {
            $Date = date("Y-m-d", strtotime($this->Get("Start Date")));
            return strtotime($Date) > time();
        }

        return $this->StartDateAsObject()->getTimestamp() > time();
    }

    /**
     * Determine if the event is currently occuring.
     * @return bool TRUE if the event is currently occuring.
     */
    public function isOccurring(): bool
    {
        return !($this->IsInFuture() || $this->IsInPast());
    }

    /**
     * Determine if the event occurs in the past.
     * @return bool TRUE if the event occurs in the past.
     */
    public function isInPast(): bool
    {
        # make the date precise only to the day if the event occurs all day
        if ($this->Get("All Day")) {
            $Date = date("Y-m-d", strtotime($this->Get("End Date")));
            return strtotime($Date) < time();
        }

        return $this->EndDateAsObject()->getTimestamp() < time();
    }

    /**
     * Determine if the event starts at some point today.
     * @return bool TRUE if the event starts at some point today.
     */
    public function startsToday(): bool
    {
        return date("Y-m-d") == date("Y-m-d", strtotime($this->Get("Start Date")));
    }

    /**
     * Determine if the event ends at some point today.
     * @return bool TRUE if the event ends at some point today.
     */
    public function endsToday(): bool
    {
        return date("Y-m-d") == date("Y-m-d", strtotime($this->Get("End Date")));
    }

    /**
     * Get the start date as a DateTime object.
     * @return \DateTime the start date as a DateTime object.
     */
    public function startDateAsObject(): \DateTime
    {
        if (!is_null($this->Get("Start Date"))) {
            return $this->ConvertDateStringToObject($this->Get("Start Date"));
        }
        return new DateTime();
    }

    /**
     * Get the end date as a DateTime object.
     * @return \DateTime the end date as a DateTime object.
     */
    public function endDateAsObject(): \DateTime
    {
        if (!is_null($this->Get("End Date"))) {
            return $this->ConvertDateStringToObject($this->Get("End Date"));
        }
        return new DateTime();
    }

    /**
     * Get the creation date as a DateTime object.
     * @return \DateTime the creation date as a DateTime object.
     */
    public function creationDateAsObject(): \DateTime
    {
        return $this->ConvertDateStringToObject($this->Get("Date Of Record Creation"));
    }

    /**
     * Get the modification date as a DateTime object.
     * @return \DateTime the modification date as a DateTime object.
     */
    public function modificationDateAsObject(): \DateTime
    {
        return $this->ConvertDateStringToObject($this->Get("Date Last Modified"));
    }

    /**
     * Get the release date as a DateTime object.
     * @return \DateTime the release date as a DateTime object.
     */
    public function releaseDateAsObject(): \DateTime
    {
        return $this->ConvertDateStringToObject($this->Get("Release Date"));
    }

    /**
     * Get the categories field value for displaying to users.
     * @return array Returns the categories field value for display to users.
     */
    public function categoriesForDisplay(): array
    {
        $ControlledNames = $this->Get("Categories", true);

        # there are no categories assigned to the event
        if (is_null($ControlledNames)) {
            return [];
        }

        $Categories = [];

        foreach ($ControlledNames as $Id => $Category) {
            $Categories[$Id] = $Category->Name();
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

        foreach ($this->Get("Attachments", true) as $Id => $Attachment) {
            $Attachments[$Id] = [$Attachment->Name(), $Attachment->GetLink()];
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

        if ($this->Get("Release Flag")) {
            return $Field->FlagOnLabel();
        }

        return $Field->FlagOffLabel();
    }

    /**
     * Get the start date field value for displaying to users.
     * @return string Returns the start date field value for display to users.
     */
    public function startDateForDisplay(): string
    {
        if ($this->Get("All Day")) {
            $DateRange = GetPrettyDateRangeInParts(
                $this->Get("Start Date"),
                $this->Get("End Date")
            );

            # if date range was invalid display --
            if (is_null($DateRange)) {
                return "--";
            }

            return $DateRange["Start"];
        }

        return str_replace(
            '12:00am',
            '',
            StdLib::getPrettyTimestamp($this->Get("Start Date"), false)
        );
    }

    /**
     * Get the end date field value for displaying to users.
     * @return string Returns the end date field value for display to users.
     */
    public function endDateForDisplay(): string
    {
        if ($this->Get("All Day")) {
            $DateRange = GetPrettyDateRangeInParts(
                $this->Get("Start Date"),
                $this->Get("End Date")
            );

            # if date range was invalid, display --
            if (is_null($DateRange)) {
                return "--";
            }

            return $DateRange["End"];
        }

        return str_replace(
            '12:00am',
            '',
            StdLib::getPrettyTimestamp($this->Get("End Date"), false)
        );
    }

    /**
     * Get the start date time for displaying to users.
     * @return string Returns the start date time for display to users.
     */
    public function startDateTimeForDisplay(): string
    {
        return str_replace(
            '12:00am',
            '',
            StdLib::getPrettyTimestamp($this->Get("Start Date"))
        );
    }

    /**
     * Get the end date time for displaying to users.
     * @return string Returns the end date time for display to users.
     */
    public function endDateTimeForDisplay(): string
    {
        return str_replace(
            '12:00am',
            '',
            StdLib::getPrettyTimestamp($this->Get("End Date"))
        ) ;
    }

    /**
     * Get the author field value for displaying to users.
     * @return string the author field value for display to users.
     */
    public function authorForDisplay(): string
    {
        return $this->FormatUserNameForDisplay($this->Get("Added By Id", true));
    }

    /**
     * Get the editor field value for displaying to users.
     * @return string the editor field value for display to users.
     */
    public function editorForDisplay(): string
    {
        return $this->FormatUserNameForDisplay($this->Get("Last Modified By Id", true));
    }

    /**
     * Get the creation date field value for displaying to users.
     * @return string the creation date field value for display to users.
     */
    public function creationDateForDisplay(): string
    {
        return StdLib::getPrettyTimestamp($this->Get("Date Of Record Creation"));
    }

    /**
     * Get the modification date field value for displaying to users.
     * @return string the modification date field value for display to users.
     */
    public function modificationDateForDisplay(): string
    {
        return StdLib::getPrettyTimestamp($this->Get("Date Last Modified"));
    }

    /**
     * Get the release date field value for displaying to users.
     * @return string the release date field value for display to users.
     */
    public function releaseDateForDisplay(): string
    {
        return StdLib::getPrettyTimestamp($this->Get("Release Date"));
    }

    /**
     * Get the start date field value for machine parsing.
     * @return string the start date field value for machine parsing.
     */
    public function startDateForParsing(): string
    {
        $Format = $this->Get("All Day")
            ? self::DATE_FOR_PARSING : self::DATETIME_FOR_PARSING;
        return $this->StartDateAsObject()->format($Format);
    }

    /**
     * Get the end date field value for machine parsing.
     * @return string the end date field value for machine parsing.
     */
    public function endDateForParsing(): string
    {
        $Format = $this->Get("All Day")
            ? self::DATE_FOR_PARSING : self::DATETIME_FOR_PARSING;
        return $this->EndDateAsObject()->format($Format);
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
        if ($this->Get("Venue") && $IncludeVenue) {
            $Location .= $this->Get("Venue") . " ";
        }

        # add the street address if given
        if ($this->Get("Street Address")) {
            $Location .= $this->Get("Street Address") . " ";
        }

        # add the locality if given
        if ($this->Get("Locality")) {
            $Location .= $this->Get("Locality") . " ";
        }

        # add the region if given
        if ($this->Get("Region")) {
            $Location .= $this->Get("Region") . " ";
        }

        # add the postal code if given
        if ($this->Get("Postal Code")) {
            $Location .= $this->Get("Postal Code") . " ";
        }

        # add the country if given
        if ($this->Get("Country")) {
            $Location .= $this->Get("Country");
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
        if ($this->Get("Region") && $this->Get("Locality")) {
            return $this->Get("Locality") . ", " . $this->Get("Region");
        } elseif ($this->Get("Country")) {
            return $this->Get("Country");
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
        if ($this->Get("Venue")) {
            $Location .= $this->Get("Venue") . ", ";
        }

        # don't add the street address if given
        if ($this->Get("Street Address")) {
            $Location .= $this->Get("Street Address") . ", ";
        }

        # add the locality if given
        if ($this->Get("Locality")) {
            $Location .= $this->Get("Locality") . ", ";
        }

        # add the region if given
        if ($this->Get("Region")) {
            $Suffix = $this->Get("Postal Code") ? " " : ", ";
            $Location .= $this->Get("Region") . $Suffix;
        }

        # add the postal code if given
        if ($this->Get("Postal Code")) {
            $Location .= $this->Get("Postal Code") . ", ";
        }

        # add the country if given
        if ($this->Get("Country")) {
            $Location .= $this->Get("Country");
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
        if ($this->Get("Venue")) {
            $Location .=
                '<span class="calendar_events-venue" itemprop="name">'
                .defaulthtmlentities($this->Get("Venue"))
                .'</span>';
        }

        # add the street address if given
        if ($this->Get("Street Address")) {
            $Location .=
                '<span class="calendar_events-street_address" itemprop="streetAddress">'
                .defaulthtmlentities($this->Get("Street Address"))
                .'</span>';
        }

        # add the locality if given
        if ($this->Get("Locality")) {
            $Location .=
                '<span class="calendar_events-locality" itemprop="addressLocality">'
                .defaulthtmlentities($this->Get("Locality"))
                .'</span>';
        }

        # add the region if given
        if ($this->Get("Region")) {
            $Location .=
                '<span class="calendar_events-region" itemprop="addressRegion">'
                .defaulthtmlentities($this->Get("Region"))
                .'</span>';
        }

        # add the postal code if given, but only if there's a locality or region
        if ($this->Get("Postal Code") && ($this->Get("Locality") || $this->Get("Region"))) {
            $Location .=
                '<span class="calendar_events-postal_code" itemprop="postalCode">'
                .defaulthtmlentities($this->Get("Postal Code"))
                .'</span>';
        }

        # add the country if given
        if ($this->Get("Country")) {
            $Location .=
                '<span class="calendar_events-country" itemprop="addressCountry">'
                .defaulthtmlentities($this->Get("Country"))
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
        $StartDate = $this->StartDateAsObject();
        $EndDate = $this->EndDateAsObject();

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
        if ($this->Get("All Day")) {
            return $this->GetDatePrefix($this->Get("Start Date"));
        }

        # otherwise use the timestamp prefix
        return $this->GetTimestampPrefix($this->Get("Start Date"));
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
        if ($this->Get("All Day")) {
            return $this->GetDatePrefix($this->Get("End Date"));
        }

        # otherwise use the timestamp prefix
        return $this->GetTimestampPrefix($this->Get("End Date"));
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
        if (!$UserFactory->userNameExists($User->Name())) {
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
    protected static function setDatabaseAccessValues(string $ClassName)
    {
        if (!isset(self::$ItemIdColumnNames[$ClassName])) {
            self::$ItemIdColumnNames[$ClassName] = "RecordId";
            self::$ItemNameColumnNames[$ClassName] = "ResourceName";
            self::$ItemTableNames[$ClassName] = "Records";
        }
    }
}
