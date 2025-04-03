<?PHP
#
#   FILE:  iCalendarFeed.php
#
#   Part of the ScoutLib application support library
#   Copyright 2013-2024 Edward Almasy and Internet Scout Research Group
#   http://scout.wisc.edu
#
# @scout:phpstan

namespace ScoutLib;

/**
 * Class to generate an iCalendar feed which contains multiple events.
 */
class iCalendarFeed
{
    # ---- PUBLIC INTERFACE --------------------------------------------------
    /**
     * Generate an object representing an iCalendar feed that has an
     * empty array of Events.
     */
    public function __construct()
    {
        $this->Events = [];
    }


    /**
     * Add an iCalendarEvent, with information about a single event to the list
     * of events for this feed.
     * @param iCalendarEvent $Event  Event to add to this feed.
     */
    public function addEvent(iCalendarEvent $Event): void
    {
        $this->Events[] = $Event;
    }


    /**
     * Add the events from an array of one or more iCalendarEvents to this feed.
     * @param iCalendarEvent[] $Events  Events to add to this feed.
     */
    public function addEvents(array $Events): void
    {
        foreach ($Events as $Event) {
            $this->addEvent($Event);
        }
    }


    /**
     * Add a title to this feed. The title will be included in the feed
     * in the experimental iCalendar property 'X-WR-CALNAME'.
     * @param string $Title  Title to add to this feed.
     */
    public function addTitle(string $Title): void
    {
        $this->Title = $Title;
    }


    /**
     * Return the text of an iCalendar document containing information for all
     * of the events in this Feed
     * @return string|null  The iCalendar document as a string, or null if
     *                      this Feed has no Events.
     */
    public function getAsDocument(): ?string
    {
        if (sizeof($this->Events) === 0) {
            # An iCalendar document with no calendar components is invalid.
            return null;
        }

        # Generating the timestamp based on the first event in the list.
        $Document = iCalendarFeed::getHeader();

        if (isset($this->Title)) {
            $Document .= "X-WR-CALNAME:" . $this->Title . "\r\n";
        }

        // call them iCalendar Events.
        foreach ($this->Events as $Event) {
            $Document .= $Event->getAsComponent();
        }

        # end the iCalendar definition
        $Document .= iCalendarFeed::getFooter();

        return $Document;
    }


    # ---- PRIVATE INTERFACE --------------------------------------------------

    protected $Events;
    protected $Title;

    /**
     * Produce boilerplate header for iCalendar document.
     */
    protected static function getHeader(): string
    {
        # start the iCalendar definition
        $Document = "BEGIN:VCALENDAR\r\n";

        # add basic headers
        $Document .= "CALSCALE:GREGORIAN\r\n";
        $Document .= "PRODID:-//Internet Scout//Metavus//EN\r\n";
        $Document .= "VERSION:2.0\r\n";
        return $Document;
    }

    /**
     * Produce boilerplate footer for iCalendar document.
     */
    protected static function getFooter(): string
    {
        return "END:VCALENDAR\r\n";
    }
}
