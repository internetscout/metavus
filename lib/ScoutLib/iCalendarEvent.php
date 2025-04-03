<?PHP
#
#   FILE:  iCalendarEvent.php
#
#   Part of the ScoutLib application support library
#   Copyright 2013-2025 Edward Almasy and Internet Scout Research Group
#   http://scout.wisc.edu
#
# @scout:phpstan

namespace ScoutLib;

/**
 * Class to generate a simple iCalendar document.
 */
class iCalendarEvent
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Construct a basic iCalendar document.
     * @param string $ID Event ID used when generating the UID.
     * @param string $StartDate Event start date parsable by strtotime().
     * @param string $EndDate Event end date parsable by strtotime().
     * @param bool $AllDay Flag to specify if the event takes place throughout
     *      the day instead of during specific times.
     * @param string $TimeZoneID Optional time zone ID, e.g., "America/New_York".
     */
    public function __construct(
        string $ID,
        string $StartDate,
        string $EndDate,
        bool $AllDay,
        ?string $TimeZoneID = null
    ) {
        # generate the UID and add it to the document
        $this->addProperty("VEVENT", "UID", $this->generateUID($ID, $StartDate));

        # need to use the time zone parameter if a time zone ID is given
        $DateParameters = is_null($TimeZoneID) ? array() : array("TZID" => $TimeZoneID);

        if ($AllDay) {
            # need to offset the end date by one day so that the range spans the
            # entire 24 hours of the last day
            $EndDate = date("Y-m-d", strtotime($EndDate) + 86400);

            $this->addDateProperty("VEVENT", "DTSTART", $StartDate, $DateParameters);
            $this->addDateProperty("VEVENT", "DTEND", $EndDate, $DateParameters);
        } else {
            $this->addDateTimeProperty("VEVENT", "DTSTART", $StartDate, $DateParameters);
            $this->addDateTimeProperty("VEVENT", "DTEND", $EndDate, $DateParameters);
        }
    }

    /**
     * Add the created property to the iCalendar document. An existing created
     * property will be overwritten.
     * @param string $Value The date and time the event was created.
     */
    public function addCreated(string $Value): void
    {
        $this->addTextProperty(
            "VEVENT",
            "CREATED",
            iCalendarEvent::generateUTCDateTimeString($Value)
        );
    }

    /**
     * Add the summary property to the iCalendar document. An existing summary
     * property will be overwritten.
     * @param string $Value The body of the summary.
     */
    public function addSummary(string $Value): void
    {
        # add the property
        $this->addTextProperty("VEVENT", "SUMMARY", $Value);

        # save the summary for use in generating the file name
        $this->Summary = $Value;
    }

    /**
     * Add the date time stamp (DTSTAMP) property to the iCalendar document.
     * Any existing DTSTAMP property will be overwritten.
     * @param string $Value The body of the description.
     */
    public function addDateTimeStamp(string $Value): void
    {
        $this->addDateTimeProperty(
            "VEVENT",
            "DTSTAMP",
            iCalendarEvent::generateUTCDateTimeString($Value)
        );
    }

    /**
     * Add the description property to the iCalendar document. An existing
     * description property will be overwritten.
     * @param string $Value The body of the description.
     */
    public function addDescription(string $Value): void
    {
        $this->addTextProperty("VEVENT", "DESCRIPTION", $Value);
    }

    /**
     * Add the categories property to the iCalendar document. An existing
     * categories property will be overwritten.
     * @param array $Categories A list of categories.
     */
    public function addCategories(array $Categories): void
    {
        # don't add the property if there are no categories to add
        if (!count($Categories)) {
            return;
        }

        $this->addProperty(
            "VEVENT",
            "CATEGORIES",
            implode(",", array_map(array($this, "EscapeTextValue"), $Categories))
        );
    }

    /**
     * Add the URL property to the iCalendar document. An existing URL property
     * will be overwritten.
     * @param string $Value The URL to add.
     */
    public function addURL(string $Value): void
    {
        # don't add a blank URL
        if (!strlen($Value)) {
            return;
        }

        $this->addProperty("VEVENT", "URL", $Value);
    }

    /**
     * Add the geographic position property to the iCalendar document. An
     * existing geographic position property will be overwritten.
     * @param float $Latitude Latitude value.
     * @param float $Longitude Longitude value.
     */
    public function addGeographicPosition(float $Latitude, float $Longitude): void
    {
        # construct the value for the property
        $Value = floatval($Latitude) . ";" . floatval($Longitude);

        # add the property to the list
        $this->addProperty("VEVENT", "GEO", $Value);
    }

    /**
     * Add the location property to the iCalendar document. An existing location
     * property will be overwritten.
     * @param string $Value The location.
     */
    public function addLocation(string $Value): void
    {
        $this->addTextProperty("VEVENT", "LOCATION", $Value);
    }

    /**
     * Generate a VEVENT component for an iCalendar document based on the list
     * of properties for an Event.
     * @return string Text of the VEVENT component for an iCalendar document.
     */
    public function getAsComponent(): string
    {
        # add each component
        $Document = "";
        foreach ($this->Properties as $Component => $Properties) {
            # don't add empty components
            if (!count($Properties)) {
                continue;
            }

            # begin the component definition
            $Document .= "BEGIN:" . $Component . "\r\n";

            # add each property line
            foreach ($Properties as $Property => $PropertyLine) {
                $Document .= $PropertyLine;
            }

            # end the component definition
            $Document .= "END:" . $Component . "\r\n";
        }

        return $Document;
    }


    /**
     * Generate a file name for the iCalendar document. The file name will be the
     * summary property if set and the current date/time if not. The generated
     * file name is safe to use in the "filename" property of the HTTP
     * "Content-Disposition" header when the value is quoted.
     * @return string the generated file name.
     */
    public function generateFileName(): string
    {
        return self::generateFileNameFromSummary($this->Summary);
    }

    /**
     * Create a file name for an iCalendar document using a given summary. The
     * fiel name will be the current date/time if the summary is not given. The
     * generated file name is safe to use in the "filename" property of the HTTP
     * "Content-Disposition" header when the value is quoted.
     * @param string $Summary Optional summary to use in the name.
     * @return string the generated file name.
     */
    public static function generateFileNameFromSummary(?string $Summary = null): string
    {
        # just use the date/time if the summary isn't given
        if (!$Summary) {
            return date("Ymd-His") . ".ics";
        }

        # remove any HTML from the summary
        $Name = strip_tags($Summary);

        # replace problematic characters for most filesystems
        $Name = str_replace(
            array("/", "?", "<", ">", "\\", ":", "*", "|", '"', "^"),
            "-",
            $Name
        );

        # remove whitespace at the beginning and end
        $Name = trim($Name);

        # make sure the name isn't too long because it can cause problems for
        # some browsers and file systems
        $Name = substr($Name, 0, 75);

        # return the name plus extension
        return $Name . ".ics";
    }


    /**
     * Helper method to transform an HTML string to plain text.
     * @param string $HTML HTML string to transform.
     * @return string the HTML string transformed to plain text.
     */
    public static function transformHTMLToPlainText(string $HTML): string
    {
        # remove HTML tags
        $HTML = strip_tags($HTML);

        # handle a few replacements separately because they aren't handled by
        # html_entity_decode() or are replaced by a character that isn't ideal.
        # string to replace => replacement
        $Replace = array(
            "&nbsp;" => " ",
            "&ndash;" => "-",
            "&mdash;" => "--",
            "&ldquo;" => '"',
            "&rdquo;" => '"',
            "&lsquo;" => "'",
            "&rsquo;" => "'"
        );

        # do the first pass of replacements
        $HTML = str_replace(array_keys($Replace), array_values($Replace), $HTML);

        # do the final pass of replacements and return
        return html_entity_decode($HTML);
    }



    # ---- PRIVATE INTERFACE --------------------------------------------------

    /**
     * Add a generic property, i.e., one whose value is already in the proper
     * form.
     * @param string $Component The iCalendar component the property belongs to.
     * @param string $Property The name of the property.
     * @param string $Value The property value.
     * @param array $Parameters Optional parameters for the property. These
     *      should already be properly escaped.
     * @see addTextProperty()
     * @see addDateProperty()
     * @see addDateTimeProperty()
     */
    protected function addProperty(
        string $Component,
        string $Property,
        string $Value,
        array $Parameters = array()
    ): void {

        # construct the property line
        $Line = $this->generatePropertyString($Property, $Parameters) . $Value;

        # fold the line if necessary and add the line ending sequence
        $Line = $this->foldString($Line) . "\r\n";

        # add the property line to the list of properties
        $this->Properties[$Component][$Property] = $Line;
    }

    /**
     * Add a text property to the list.
     * @param string $Component The iCalendar component the property belongs to.
     * @param string $Property The name of the property.
     * @param string $Value The property value.
     * @param array $Parameters Optional parameters for the property. These
     *      should already be properly escaped.
     * @see addProperty()
     * @see addDateProperty()
     * @see addDateTimeProperty()
     * @see http://tools.ietf.org/html/rfc5545#section-3.3.11
     */
    protected function addTextProperty(
        string $Component,
        string $Property,
        string $Value,
        array $Parameters = array()
    ): void {

        # don't add empty properties
        if (!strlen($Value)) {
            return;
        }

        $this->addProperty(
            $Component,
            $Property,
            $this->escapeTextValue($Value),
            $Parameters
        );
    }

    /**
     * Add a date property to the list.
     * @param string $Component The iCalendar component the property belongs to.
     * @param string $Property The name of the property.
     * @param string $Value The property value.
     * @param array $Parameters Optional parameters for the property. These
     *      should already be properly escaped.
     * @see addProperty()
     * @see addTextProperty()
     * @see addDateTimeProperty()
     * @see http://tools.ietf.org/html/rfc5545#section-3.3.4
     */
    protected function addDateProperty(
        string $Component,
        string $Property,
        string $Value,
        array $Parameters = array()
    ): void {

        $this->addProperty(
            $Component,
            $Property,
            $this->generateDateString($Value),
            array("VALUE" => "DATE") + $Parameters
        );
    }

    /**
     * Add a date/time property to the list.
     * @param string $Component The iCalendar component the property belongs to.
     * @param string $Property The name of the property.
     * @param string $Value The property value.
     * @param array $Parameters Optional parameters for the property. These
     *      should already be properly escaped.
     * @see addProperty()
     * @see addTextProperty()
     * @see addDateProperty()
     * @see http://tools.ietf.org/html/rfc5545#section-3.3.5
     */
    protected function addDateTimeProperty(
        string $Component,
        string $Property,
        string $Value,
        array $Parameters = array()
    ): void {

        $this->addProperty(
            $Component,
            $Property,
            $this->generateDateTimeString($Value),
            $Parameters
        );
    }

    /**
     * Escape a text value for inserting into a property line.
     * @param string $Value The text value to escape.
     * @return string the escaped text value.
     */
    protected function escapeTextValue(string $Value): string
    {
        # escape most characters
        $Value = preg_replace('/([\\;,])/', "\\\\\\1", $Value);

        # escape newlines
        $Value = preg_replace('/\n/', "\\n", $Value);

        return $Value;
    }

    /**
     * Generate a full UID from an event ID and start date.
     * @param string $ID Event ID.
     * @param string $StartDate The date the event starts.
     * @return string a full UID.
     */
    protected function generateUID(string $ID, string $StartDate): string
    {
        # concatenate the date string, ID, and host name as in the spec
        $UID = iCalendarEvent::generateUTCDateTimeString($StartDate);
        $UID .= "-" . $ID;
        $UID .= "@" . gethostname();

        return $UID;
    }

    /**
     * Generate a date string from a date parsable by strtotime().
     * @param string $Date Date from which to generate the date string.
     * @return string a date string.
     */
    protected function generateDateString(string $Date): string
    {
        return date("Ymd", (int)strtotime($Date));
    }

    /**
     * Generate a date/time string from a date parsable by strtotime().
     * @param string $DateTime Date/Time from which to generate the date string.
     * @return string a date/time string.
     */
    protected function generateDateTimeString(string $DateTime): string
    {
        return date("Ymd\THis", (int)strtotime($DateTime));
    }

    /**
     * Generate a UTC date/time string from a date parsable by strtotime().
     * @param string $DateTime Date/Time from which to generate the date string.
     * @return string a UTC date/time string.
     */
    protected static function generateUTCDateTimeString(string $DateTime): string
    {
        return gmdate("Ymd\THis\Z", (int)strtotime($DateTime));
    }


    /**
     * Generate a property string (property + parameters + ":").
     * @param string $Property The property name.
     * @param array $Parameters Optional parameters for the property. These
     *      should already be properly escaped.
     * @return string the generated property string.
     */
    protected function generatePropertyString(string $Property, array $Parameters = array()): string
    {
        # start the property string off with the property name
        $String = $Property;

        # add each property parameter, if any
        foreach ($Parameters as $Parameter => $Value) {
            $String .= ";" . $Parameter . "=" . $Value;
        }

        # add the colon separator and return
        return $String . ":";
    }

    /**
     * Fold a string so that lines are never longer than 75 characters.
     * @param string $String The string to fold.
     * @param string $End Optionally specifieds the line ending sequence.
     * @return string the string, folded where necessary.
     */
    protected function foldString(string $String, string $End = "\r\n "): string
    {
        # split the line into chunks
        $FoldedString = chunk_split($String, 75, $End);

        # chunk_split() unnecessarily adds the line ending sequence to the end
        # of the string, so remove it
        $FoldedString = substr($FoldedString, 0, -strlen($End));

        return $FoldedString;
    }

    /**
     * The list of components and properties. Not all of the components may be
     * used.
     */
    protected $Properties = array(
        "VEVENT" => array(),
        "VTODO" => array(),
        "VJOURNAL" => array(),
        "VFREEBUSY" => array(),
        "VTIMEZONE" => array(),
        "VALARM" => array()
    );

    /**
     * The summary property for the iCalendar document. Used to generate the file
     * name when set.
     */
    protected $Summary;
}
