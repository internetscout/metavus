<?PHP
#
#   FILE:  iCalendarFeed_Test.php
#
#   Part of the ScoutLib application support library
#   Copyright 2023 Edward Almasy and Internet Scout Research Group
#   http://scout.wisc.edu
#
# @scout:phpstan
// phpcs:disable PSR1.Classes.ClassDeclaration.MultipleClasses

namespace ScoutLib;

class iCalendarFeed_Test extends \PHPUnit\Framework\TestCase
{

   /**
    * Test __construct().
    */
    public function testConstructor()
    {
       # construct the iCalendar document
        $ICalendarFeed = new iCalendarFeed();
        $this->assertTrue($ICalendarFeed instanceof iCalendarFeed);
    }


   /*
   * Test that an iCalendar document with multiple events is produced.
   */
    public function testGetAsDocument()
    {
        $ICalendarEvents = [];
        $ICalendarEvents[] = $this->setupEvent($this->testEventInfo()[0]);
        $ICalendarEvents[] = $this->setupEvent($this->testEventInfo()[1]);
        $Feed = new iCalendarFeed();
        $Feed->addEvents($ICalendarEvents);
        $Feed->addTitle("Events of Interest");
        $FeedText = $Feed->getAsDocument();
        # Avoids using a UID that is constructed with a hard-coded hostname.
        $Hostname = gethostname();
        $Expected = <<<CALENDARCHUNK
BEGIN:VCALENDAR\r
CALSCALE:GREGORIAN\r
PRODID:-//Internet Scout//Metavus//EN\r
VERSION:2.0\r
X-WR-CALNAME:Events of Interest\r
BEGIN:VEVENT\r
UID:20221201T060000Z-58@{$Hostname}\r
DTSTART;VALUE=DATE:20221201\r
DTEND;VALUE=DATE:20221203\r
CREATED:20221122T234144Z\r
DTSTAMP:20221130T124501\r
SUMMARY:Bear Dancing\r
DESCRIPTION:Dancing with bears.\r
URL:https://fakeurl/events/58/bear-dancing\r
CATEGORIES:Conference\r
END:VEVENT\r
BEGIN:VEVENT\r
UID:20221221T060000Z-57@{$Hostname}\r
DTSTART;VALUE=DATE:20221221\r
DTEND;VALUE=DATE:20221223\r
CREATED:20221122T233839Z\r
DTSTAMP:20221128T153137\r
SUMMARY:Wisconsin Intoxication Forum\r
DESCRIPTION:Party conference.\r
URL:https://fakeurl/events/57/wisconsin-intoxication-forum\r
CATEGORIES:Conference\r
END:VEVENT\r
END:VCALENDAR\r\n
CALENDARCHUNK;
        $this->assertEquals($Expected, $FeedText);

        $Feed = new iCalendarFeed();
        $this->assertNull(
            $Feed->getAsDocument(),
            "A feed must contain at least one event."
        );
    }

   /**
    *  Returns hardcoded values to create 2 iCalenadr events.
    *  @return array of attributes to feed to iCalendar constructor.
    */
    private function testEventInfo()
    {

        $EventInfo = [];
        $EventInfo[] = array(
            "EventId" => 58,
            "StartDate" => "2022-12-01 00:00:00",
            "EndDate" => "2022-12-02 00:00:00",
            "AllDay" => true,
            "Created" => "2022-11-22 17:41:44",
            "Modified" => "2022-11-30 12:45:01",
            "Summary" => "Bear Dancing",
            "Description" => "Dancing with bears.",
            "URL" => "https://fakeurl/events/58/bear-dancing",
            "Location" => "",
            "Categories" => array ( 130 => "Conference", ),
        );

        $EventInfo[] = array(
            "EventId" => 57,
            "StartDate" => "2022-12-21 00:00:00",
            "EndDate" => "2022-12-22 00:00:00",
            "AllDay" => true,
            "Created" => "2022-11-22 17:38:39",
            "Modified" => "2022-11-28 15:31:37",
            "Summary" => "Wisconsin Intoxication Forum",
            "Description" => "Party conference.",
            "URL" => "https://fakeurl/events/57/wisconsin-intoxication-forum",
            "Location" => "",
            "Categories" => array ( 130 => "Conference", ),
        );
        return $EventInfo;
    }


    /**
     * Sets up one iCalendar object for one event based on one set of attributes.
     * (Attributes are assigned based on how CalendarFeed.php does it.)
     * @param   Attributes for iCalendar object for an event.
     * @return  An iCalendarEvent object for the event specified by $EventInfo.
     */
    private function setUpEvent(array $EventInfo)
    {
        $ICalendarEvent = new iCalendarEvent(
            $EventInfo["EventId"],
            $EventInfo["StartDate"],
            $EventInfo["EndDate"],
            $EventInfo["AllDay"]
        );

        $ICalendarEvent->addCreated($EventInfo["Created"]);
        $ICalendarEvent->addDateTimeStamp($EventInfo["Modified"]);
        $ICalendarEvent->addSummary($EventInfo["Summary"]);
        $ICalendarEvent->addDescription($EventInfo["Description"]);
        $ICalendarEvent->addURL($EventInfo["URL"]);
        $ICalendarEvent->addLocation($EventInfo["Location"]);
        $ICalendarEvent->addCategories($EventInfo["Categories"]);

        return $ICalendarEvent;
    }
}
