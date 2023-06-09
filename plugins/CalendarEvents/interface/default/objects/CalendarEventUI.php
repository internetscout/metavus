<?PHP
#
#   FILE:  CalendarEventUI.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2020-2022 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins\CalendarEvents;

use Metavus\MetadataSchema;
use Metavus\Plugins\GoogleMaps;
use Metavus\User;
use ScoutLib\ApplicationFramework;
use ScoutLib\iCalendarEvent;
use ScoutLib\PluginManager;
use ScoutLib\StdLib;

/**
 * User interface class providing methods to display Calendar events.
 */
class CalendarEventUI
{
  /**
   * Print an event.
   * @param Event $Event Event to print.
   */
    public static function printEvent(Event $Event)
    {
        $AF = ApplicationFramework::getInstance();
        $PluginMgr = PluginManager::getInstance();
        $CalendarEventsPlugin = $PluginMgr->getPlugin("CalendarEvents");

        # retrieve user currently logged in
        $User = User::getCurrentUser();

        $Schema = new MetadataSchema($CalendarEventsPlugin->getSchemaId());

        $SafeId = defaulthtmlentities($Event->id());
        $SafeUrl = defaulthtmlentities($Event->get("Url"));
        $SafeUrlDisplay = $Event->get("Url") !== null ?
          defaulthtmlentities(StdLib::neatlyTruncateString(
              $Event->get("Url"),
              100,
              true
          )) : "";
        $SafeBestUrl = defaulthtmlentities($Event->getBestUrl());
        $SafeTitle = defaulthtmlentities($Event->get("Title"));
        $SafeStartDate = defaulthtmlentities($Event->startDateForDisplay());
        $SafeStartDateForParsing = defaulthtmlentities($Event->startDateForParsing());
        $SafeEndDate = defaulthtmlentities($Event->endDateForDisplay());
        $SafeEndDateForParsing = defaulthtmlentities($Event->endDateForParsing());
        $SafeEndDateTimeForDisplay = defaulthtmlentities($Event->endDateTimeForDisplay());
        $SafeContactEmail = defaulthtmlentities($Event->get("Contact Email"));
        $SafeContactUrl = defaulthtmlentities($Event->get("Contact URL"));
        $SafeiCalUrl = defaulthtmlentities($Event->iCalUrl());
        $SafeiCalFileName = defaulthtmlentities(
            iCalendarEvent::generateFileNameFromSummary($Event->get("Title"))
        );
        $Description = $Event->get("Description");
        $Categories = $Event->categoriesForDisplay();
        $Attachments = $Event->attachmentsForDisplay();
        $Coordinates = $Event->get("Coordinates");
        $Location = $Event->locationForHtml();
        $SpanInDays = $Event->spanInDays();
        $SafeSpanInDays = defaulthtmlentities($SpanInDays);
        $IsAllDay = $Event->get("All Day");
        $HasContact = $SafeContactEmail || $SafeContactUrl;
        $HasCategories = count($Categories);
        $HasAttachments = count($Attachments);
        $CanEditEvents = $CalendarEventsPlugin->userCanEditEvents($User, $Event);
        $CanViewMetrics = $CalendarEventsPlugin->userCanViewMetrics($User);
        $SafeCategoriesLabel = $Schema->getField("Categories")->getDisplayName();
        $SafeAttachmentsLabel = $Schema->getField("Attachments")->getDisplayName();

        $HasMap = false;
        if (!(is_null($Coordinates[GoogleMaps::POINT_LATITUDE]) &&
          is_null($Coordinates[GoogleMaps::POINT_LONGITUDE])) &&
          $PluginMgr->pluginEnabled("GoogleMaps")) {
            $GMaps = $PluginMgr->getPlugin("GoogleMaps");
            $SafeMapUrl = $GMaps->GoogleMapsUrl($Location);
            $HasMap = true;
        }

        // @codingStandardsIgnoreStart
    ?>
    <article class="vevent calendar_events-event calendar_events-full"
              itemscope="itemscope"
              itemtype="http://schema.org/Event">
      <link rel="profile" href="http://microformats.org/profile/hcalendar">
      <link itemprop="url" href="<?= $SafeBestUrl; ?>" />
      <header class="calendar_events-header">
        <?PHP if ($CanEditEvents) { ?>
          <div class="container">
            <div class="row">
              <div class="col">
                <h1 class="summary calendar_events-title"
                    itemprop="name"><?= $SafeTitle; ?></h1>
              </div>
              <div class="col">
                <a class="btn btn-primary btn-sm mv-button-iconed"
                    href="<?= str_replace('$ID', $SafeId, $Event->getSchema()->editPage()); ?>">
                    <img class="mv-button-icon" alt=""
                        src="<?= $AF->GUIFile('Pencil.svg') ?>"/> Edit</a>
                <?PHP  $AF->signalEvent("EVENT_HTML_INSERTION_POINT",
                    [
                        $AF->getPageName(),
                        "Resource Display Buttons",
                        ["Resource" => $Event]
                    ]); ?>
              </div>
            </div>
          </div>
        <?PHP } else { ?>
          <h1 class="summary calendar_events-title"
              itemprop="name"><?= $SafeTitle; ?></h1>
        <?PHP } ?>
        <p>
          <time class="dtstart calendar_events-start_date"
                itemprop="startDate"
                datetime="<?= $SafeStartDateForParsing; ?>">
              <?= $SafeStartDate; ?></time>
            <?PHP if ($SpanInDays > 1) { ?>
            <time class="dtend calendar_events-end_date"
                  itemprop="endDate"
                  datetime="<?= $SafeEndDateForParsing; ?>">
              <?= $SafeEndDate; ?></time>
            <?PHP if ($SpanInDays < 11) { ?>
              <span class="calendar_events-span">(<?= $SafeSpanInDays; ?> days)</span>
            <?PHP } ?>
          <?PHP } else if (!$IsAllDay && $SafeStartDate != $SafeEndDateTimeForDisplay) { ?>
            <time class="dtend calendar_events-end_date"
                  itemprop="endDate"
                  datetime="<?= $SafeEndDateForParsing; ?>">
              <?= $SafeEndDateTimeForDisplay; ?></time>
          <?PHP } else { ?>
            <time class="dtend calendar_events-end_date"
                  itemprop="endDate"
                  datetime="<?= $SafeEndDateForParsing; ?>"></time>
          <?PHP } ?>
        </p>
      </header>

      <div class="description"
            itemprop="description"><?= $Description; ?></div>

        <?PHP if ($SafeUrl) { ?>
        <p><a class="url calendar_events-url"
              href="<?= $SafeUrl; ?>"><?= $SafeUrlDisplay; ?></a></p>
      <?PHP } ?>

      <div class="calendar_events-fancy_box">
          <?PHP if ($HasCategories) { ?>
          <section id="categories">
            <header><b><?= $SafeCategoriesLabel; ?>:</b></header>
            <ul class="list-inline calendar_events-categories"
                itemprop="keywords">
              <?PHP foreach ($Categories as $Category) {
                        $SafeCategory = defaulthtmlentities($Category); ?>
                <li class="list-inline-item"><i class="category"><?= $Category; ?></i></li>
              <?PHP } ?>
            </ul>
          </section>
        <?PHP } ?>

          <?PHP if ($HasContact) { ?>
          <section id="contact">
            <header><b>Contact:</b></header>
            <ul class="list-inline calendar_events-contact">
              <?PHP if ($SafeContactEmail) { ?>
                <li class="list-inline-item"><a href="mailto:<?= $SafeContactEmail; ?>"
                        title="E-mail the event organizer"><?= $SafeContactEmail; ?></a></li>
              <?PHP } ?>
              <?PHP if ($SafeContactUrl) { ?>
                <li class="list-inline-item"><a href="<?= $SafeContactUrl; ?>"
                        title="Go to the event organizer's contact page"><?= $SafeContactUrl; ?></a></li>
              <?PHP } ?>
            </ul>
          </section>
        <?PHP } ?>

          <?PHP if ($HasAttachments) { ?>
          <section id="attachments">
            <header><b><?= $SafeAttachmentsLabel; ?>:</b></header>
            <ul class="list-inline calendar_events-attachments">
              <?PHP foreach ($Attachments as $Attachment) {
                        $SafeName = defaulthtmlentities($Attachment[0]);
                        $SafeLink = defaulthtmlentities($Attachment[1]); ?>
                <li class="list-inline-item"><a href="<?= $SafeLink; ?>" title="Download this attachment"><?= $SafeName; ?></a></li>
              <?PHP } ?>
            </ul>
          </section>
        <?PHP } ?>

        <section id="import">
          <header><b>Add to Calendar:</b></header>
          <a href="<?= $SafeiCalUrl; ?>"
              title="Download this event in iCalendar format to import it into your personal calendar"><?= $SafeiCalFileName; ?></a>
        </section>
      </div>
      <br />
      <section id="location" class="calendar_events-location-section">
        <header><h1 class="calendar_events-location-header">Location</h1></header>
      <?PHP if ($HasMap) { ?>
        <a href="<?= $SafeMapUrl; ?>"
            title="View this location in Google Maps"
            target="_blank"
            ><?PHP $GMaps->StaticMap(
                $Coordinates[GoogleMaps::POINT_LATITUDE],
                $Coordinates[GoogleMaps::POINT_LONGITUDE],
                $CalendarEventsPlugin->configSetting("StaticMapWidth"),
                $CalendarEventsPlugin->configSetting("StaticMapHeight"),
                $CalendarEventsPlugin->configSetting("StaticMapZoom")); ?>
        </a>
      <?PHP } else {?>
        <span><?= $Location; ?></span>
      <?PHP } ?>
      </section>
      <section id="share">
          <?PHP self::printShareButtonsForEvent($Event); ?>
      </section>

        <?PHP if ($CanViewMetrics) { ?>
        <section id="metrics">
          <h1 class="calendar_events-metrics-header">Metrics</h1>
          <?PHP self::printEventMetrics($Event); ?>
        </section>
      <?PHP } ?>
    </article>
    <?PHP
        // @codingStandardsIgnoreEnd
    }

  /**
   * Print an event summary.
   * @param Event $Event Event to print.
   */
    public static function printEventSummary(Event $Event)
    {
        $AF = ApplicationFramework::getInstance();
        $CalendarEventsPlugin = PluginManager::getInstance()->getPlugin("CalendarEvents");
        $SafeId = defaulthtmlentities($Event->id());
        $SafeEventUrl = defaulthtmlentities($Event->eventUrl());
        $SafeTitle = defaulthtmlentities($Event->get("Title"));
        $SafeUrl = defaulthtmlentities($Event->get("Url"));
        $SafeUrlDisplay = defaulthtmlentities(StdLib::neatlyTruncateString(
            $Event->get("Url") ?? "",
            90,
            true
        ));
        $SafeStartDate = defaulthtmlentities($Event->startDateForDisplay());
        $SafeStartDateForParsing = defaulthtmlentities($Event->startDateForParsing());
        $SafeEndDate = defaulthtmlentities($Event->endDateForDisplay());
        $SafeEndDateForParsing = defaulthtmlentities($Event->endDateForParsing());
        $SafeEndDateTimeForDisplay = defaulthtmlentities($Event->endDateTimeForDisplay());
        $Description = $Event->get("Description");
        $Location = $Event->locationForHtml();
        $SpanInDays = $Event->spanInDays();
        $SafeSpanInDays = defaulthtmlentities($SpanInDays);
        $IsAllDay = $Event->get("All Day");
        $CanEditEvents = $CalendarEventsPlugin->userCanEditEvents(User::getCurrentUser(), $Event);
        // @codingStandardsIgnoreStart
    ?>
    <article class="vevent calendar_events-event calendar_events-summary"
              itemscope="itemscope"
              itemtype="http://schema.org/Event">
      <link rel="profile" href="http://microformats.org/profile/hcalendar">
      <link itemprop="url" href="<?= $SafeEventUrl; ?>" />
      <header class="calendar_events-header">
        <?PHP if ($CanEditEvents) { ?>
          <div class="container">
            <div class="row">
              <div class="col">
                <h1 class="calendar_events-title">
                  <a href="index.php?P=P_CalendarEvents_Event&amp;EventId=<?= $SafeId; ?>">
                    <span class="summary"
                          itemprop="name"><?= $SafeTitle; ?></span>
                  </a>
                </h1>
              </div>
              <div class="col">
                <a class="btn btn-primary mv-button-iconed"
                    href="<?= str_replace('$ID', $SafeId, $Event->getSchema()->editPage()); ?>">
                    <img class="mv-button-icon" alt=""
                        src="<?= $AF->GUIFile('Pencil.svg') ?>"/> Edit</a>
              </div>
            </div>
          </div>
        <?PHP } else { ?>
          <h1 class="calendar_events-title">
            <a href="index.php?P=P_CalendarEvents_Event&amp;EventId=<?= $SafeId; ?>">
              <span class="summary"
                    itemprop="name"><?= $SafeTitle; ?></span>
            </a>
          </h1>
        <?PHP } ?>
        <p>
          <time class="dtstart calendar_events-start_date"
                itemprop="startDate"
                datetime="<?= $SafeStartDateForParsing; ?>">
            <?= $SafeStartDate; ?></time>
          <?PHP if ($SpanInDays > 1) { ?>
            <time class="dtend calendar_events-end_date"
                  itemprop="endDate"
                  datetime="<?= $SafeEndDateForParsing; ?>">
              <?= $SafeEndDate; ?></time>
            <?PHP if ($SpanInDays < 11) { ?>
              <span class="calendar_events-span">(<?= $SafeSpanInDays; ?> days)</span>
            <?PHP } ?>
          <?PHP } else if (!$IsAllDay) { ?>
            <time class="dtend calendar_events-end_date"
                  itemprop="endDate"
                  datetime="<?= $SafeEndDateForParsing; ?>">
              <?= $SafeEndDateTimeForDisplay; ?></time>
          <?PHP } else { ?>
            <time class="dtend calendar_events-end_date"
                  itemprop="endDate"
                  datetime="<?= $SafeEndDateForParsing; ?>"></time>
          <?PHP } ?>
          <?PHP if ($Location) { ?>
            <span class="location calendar_events-location"><?= $Location; ?></span>
          <?PHP } ?>
        </p>
      </header>

      <div class="description calendar_events-description"
            itemprop="description"><?= $Description; ?></div>

      <?PHP if ($SafeUrl) { ?>
        <p><a class="url calendar_events-url"
              href="<?= $SafeUrl; ?>"><?= $SafeUrlDisplay; ?></a></p>
      <?PHP } ?>

      <div class="container"><div class="row">
        <p class="col calendar_events-more">
          <a href="index.php?P=P_CalendarEvents_Event&amp;EventId=<?= $SafeId; ?>">
            <span class="calendar_events-bullet">&raquo;</span> More Information</a>
        </p>
        <section class="col calendar_events-actions">
          <?PHP self::printExtraButtonsForEvent($Event, "grey"); ?>
          <?PHP self::printShareButtonsForEvent($Event, 16, "grey"); ?>
        </section>
      </div></div>
    </article>
    <?PHP
        // @codingStandardsIgnoreEnd
    }

     /**
     * Print the metrics for an event.
     * @param Event $Event Event for which to print metrics.
     */
    public static function printEventMetrics(Event $Event)
    {
        $CalendarEventsPlugin = PluginManager::getInstance()->getPlugin("CalendarEvents");
        $Metrics = $CalendarEventsPlugin->getEventMetrics($Event);

        $SafeNumViews = defaulthtmlentities(count($Metrics["Views"]));
        $SafeNumiCalDownloads = defaulthtmlentities(count($Metrics["iCalDownloads"]));
        $SafeNumEmail = defaulthtmlentities(count($Metrics["Shares/Email"]));
        $SafeNumFacebook = defaulthtmlentities(count($Metrics["Shares/Facebook"]));
        $SafeNumTwitter = defaulthtmlentities(count($Metrics["Shares/Twitter"]));
        $SafeNumLinkedIn = defaulthtmlentities(count($Metrics["Shares/LinkedIn"]));
        // @codingStandardsIgnoreStart
    ?>
      <table class="table table-striped calendar_events-metrics-table">
        <tbody>
          <tr>
            <th>Views</th>
            <td><?= $SafeNumViews; ?></td>
          </tr>
          <tr>
            <th>iCalendar Downloads</th>
            <td><?= $SafeNumiCalDownloads; ?></td>
          </tr>
          <tr>
            <th>Shared via E-mail</th>
            <td><?= $SafeNumEmail; ?></td>
          </tr>
          <tr>
            <th>Shared to Facebook</th>
            <td><?= $SafeNumFacebook; ?></td>
          </tr>
          <tr>
            <th>Shared to Twitter</th>
            <td><?= $SafeNumTwitter; ?></td>
          </tr>
          <tr>
            <th>Shared to LinkedIn</th>
            <td><?= $SafeNumLinkedIn; ?></td>
          </tr>
        </tbody>
      </table>
    <?PHP
        // @codingStandardsIgnoreEnd
    }

    /**
     * Print share buttons for an event.
     * @param Event $Event Event.
     * @param int $Size The size of the share buttons.
     * @param string $Color The color of the share buttons.(NULL, "grey", or
     *      "maroon").
     */
    public static function printShareButtonsForEvent(
        Event $Event,
        $Size = 24,
        $Color = null
    ) {
        $AF = ApplicationFramework::getInstance();
        $SafeId = defaulthtmlentities(urlencode((string)$Event->id()));
        $SafeTitle = defaulthtmlentities(rawurlencode(strip_tags($Event->get("Title"))));
        $SafeUrl = defaulthtmlentities(rawurlencode(
            $AF->baseUrl().$Event->eventUrl()
        ));
        $FileSuffix = $Size;

      # add the color if given
        if (!is_null($Color)) {
            $FileSuffix .= "-".strtolower($Color);
        }

      # construct the base URL for share URLS
        $SafeBaseUrl = "index.php?P=P_SocialMedia_ShareResource";
        $SafeBaseUrl .= "&amp;ResourceId=".$SafeId;
      // @codingStandardsIgnoreStart
?>
<ul class="list-inline calendar_events-share">
    <li class="list-inline-item">
        <a title="Share this event via e-mail"
           onclick="jQuery.get(cw.getRouterUrl()+'?P=P_SocialMedia_ShareResource&ResourceId=<?= $SafeId; ?>&Site=em');"
           href="mailto:?to=&amp;subject=<?= $SafeTitle; ?>&amp;body=<?= $SafeTitle; ?>:%0D%0A<?= $SafeUrl; ?>">
            <img src="<?PHP $AF->PUIFile("email_".$FileSuffix.".png"); ?>" alt="E-mail" />
        </a>
    </li>
    <li class="list-inline-item">
        <a title="Share this event via Facebook" href="<?= $SafeBaseUrl; ?>&amp;Site=fb">
            <img src="<?PHP $AF->pUIFile("facebook_".$FileSuffix.".png"); ?>" alt="Facebook" />
        </a>
    </li>
    <li class="list-inline-item">
        <a title="Share this event via Twitter" href="<?= $SafeBaseUrl; ?>&amp;Site=tw">
            <img src="<?PHP $AF->pUIFile("twitter_".$FileSuffix.".png"); ?>" alt="Twitter" />
        </a>
    </li>
    <li class="list-inline-item">
        <a title="Share this event via LinkedIn" href="<?= $SafeBaseUrl; ?>&amp;Site=li">
            <img src="<?PHP $AF->pUIFile("linkedin_".$FileSuffix.".png"); ?>" alt="LinkedIn" />
        </a>
    </li>
</ul>
<?PHP
      // @codingStandardsIgnoreEnd
    }

      /**
     * Print extra buttons for an event.
     * @param Event $Event Event.
     * @param string $Color The color of the buttons.(NULL or "grey")
     */
    public static function printExtraButtonsForEvent(Event $Event, $Color = null)
    {
        $AF = ApplicationFramework::getInstance();
        # get the file suffix
        $FileSuffix = is_null($Color) ? "" : "-".strtolower($Color);

        # get the URL to the full event page
        $EventUrl = $Event->eventUrl();

        # assume these won't be set by default
        $ContactUrl = null;
        $MapUrl = null;
        $AttachmentsUrl = null;

        # go to the full event page with both contact options they're both set
        if ($Event->get("Contact Email") && $Event->get("Contact URL")) {
            $ContactUrl = $EventUrl."#contact";
        # use a mailto: if just the contact e-mail field is set
        } elseif ($Event->get("Contact Email")) {
            $ContactUrl = "mailto:".urlencode($Event->get("Contact Email"));
        # use the contact URL if it's set
        } elseif ($Event->get("Contact URL")) {
            $ContactUrl = $Event->get("Contact URL");
        }

        # if any of the location fields are set
        if ($Event->locationString()) {
            $MapUrl = $EventUrl."#location";
        }

        # if there are any attachments
        if (count($Event->get("Attachments"))) {
            $AttachmentsUrl = $EventUrl."#attachments";
        }

        # escape URLs for insertion into HTML
        $SafeiCalUrl = defaulthtmlentities($Event->iCalUrl());
        $SafeContactUrl = defaulthtmlentities($ContactUrl);
        $SafeMapUrl = defaulthtmlentities($MapUrl);
        $SafeAttachmentsUrl = defaulthtmlentities($AttachmentsUrl);
        // @codingStandardsIgnoreStart
?>
  <ul class="list-inline calendar_events-extra">
    <li class="list-inline-item"><a title="Download this event in iCalendar format to import it into your personal calendar" href="<?= $SafeiCalUrl; ?>"><img src="<?PHP $AF->pUIFile("calendar-import_16".$FileSuffix.".png"); ?>" alt="iCalendar" /></a></li>
    <?PHP if ($ContactUrl) { ?>
      <li class="list-inline-item"><a title="Contact the event organizer" href="<?= $SafeContactUrl; ?>"><img src="<?PHP $AF->pUIFile("at-sign_16".$FileSuffix.".png"); ?>" alt="Contact" /></a></li>
    <?PHP } ?>
    <?PHP if ($MapUrl) { ?>
      <li class="list-inline-item"><a title="View this event on a map" href="<?= $SafeMapUrl; ?>"><img src="<?PHP $AF->pUIFile("marker_16".$FileSuffix.".png"); ?>" alt="Map" /></a></li>
    <?PHP } ?>
    <?PHP if ($AttachmentsUrl) { ?>
      <li class="list-inline-item"><a title="View files attached to this event" href="<?= $SafeAttachmentsUrl; ?>"><img src="<?PHP $AF->pUIFile("paper-clip_16".$FileSuffix.".png"); ?>" alt="Attachments" /></a></li>
    <?PHP } ?>
  </ul>
<?PHP
        // @codingStandardsIgnoreEnd
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
     */
    public static function printTransportControls(
        array $EventCounts,
        $FirstMonth,
        $CurrentMonth,
        $LastMonth,
        $PreviousMonthTimestamp,
        $NextMonthTimestamp
    ) {
        $CalendarEventsPlugin = PluginManager::getInstance()->getPlugin("CalendarEvents");
        $SafePreviousMonthName = date("F", $PreviousMonthTimestamp);
        $SafeNextMonthName = date("F", $NextMonthTimestamp);
        $CurrentMonthKey = date("MY");
        $HasEventsForCurrentMonth =
            isset($EventCounts[$CurrentMonthKey])
            && $EventCounts[$CurrentMonthKey] > 0;
        // @codingStandardsIgnoreStart
    ?>
      <div class="container calendar_events-transport_controls">
        <div class="row">
          <div class="col calendar_events-back">
            <?PHP if ($CalendarEventsPlugin->showUrl($EventCounts, $PreviousMonthTimestamp)) { ?>
              <a class="btn btn-primary"
                 href="<?=
                    defaulthtmlentities($CalendarEventsPlugin->getUrl($PreviousMonthTimestamp)); ?>"
                >&larr; <?= $SafePreviousMonthName; ?></a>
            <?PHP } ?>
          </div>
          <div class="col calendar_events-selector">
            <form method="get" action="index.php">
              <input type="hidden" name="P" value="P_CalendarEvents_Events" />
              <?PHP self::printMonthSelector($EventCounts, $FirstMonth, $LastMonth, $CurrentMonth); ?>
              <?PHP if ($HasEventsForCurrentMonth) { ?>
                <a class="btn btn-primary btn-sm calendar_events-go_to_today"
                   href="<?= $CalendarEventsPlugin->eventsListUrl([], "today"); ?>">Today</a>
              <?PHP } ?>
            </form>
          </div>
          <div class="col calendar_events-forward">
            <?PHP if ($CalendarEventsPlugin->showUrl($EventCounts, $NextMonthTimestamp)) { ?>
              <a class="btn btn-primary" href="<?=
                defaulthtmlentities($CalendarEventsPlugin->getUrl($NextMonthTimestamp)); ?>"><?=
                $SafeNextMonthName; ?> &rarr;</a>
            <?PHP } ?>
          </div>
        </div>
      </div>
    <?PHP
        // @codingStandardsIgnoreEnd
    }

    /**
     * Print the month selector.
     * @param array $EventCounts An array of months mapped to the count of events
     *      for that month.
     * @param string $FirstMonth The first month that contains an event.
     * @param string $LastMonth The last month that contains an event.
     * @param string $SelectedMonth The month that should be selected.
     */
    public static function printMonthSelector(
        array $EventCounts,
        $FirstMonth,
        $LastMonth,
        $SelectedMonth = null
    ) {
        # print nothing if there are no events
        if (!count($EventCounts)) {
            return;
        }

        $CalendarEventsPlugin = PluginManager::getInstance()->getPlugin("CalendarEvents");
        $CleanUrlPrefix = $CalendarEventsPlugin->cleanUrlPrefix();

        # convert the month strings to timestamps
        $FirstMonthTimestamp = strtotime($FirstMonth);
        $LastMonthTimestamp = strtotime($LastMonth);
        $SelectedMonthTimestamp = strtotime($SelectedMonth);

        # print nothing if the timestamps are invalid or not set
        if (($FirstMonthTimestamp === false)
                || ($LastMonthTimestamp === false)
                || ($SelectedMonthTimestamp === false)) {
            return;
        }

        $SelectedHasEvents = isset($EventCounts[date("MY", $SelectedMonthTimestamp)]);
        $FirstYearNumber = intval(date("Y", $FirstMonthTimestamp));
        $FirstMonthNumber = intval(date("m", $FirstMonthTimestamp));
        $LastYearNumber = intval(date("Y", $LastMonthTimestamp));
        $LastMonthNumber = intval(date("m", $LastMonthTimestamp));
        $SelectedYearNumber = intval(date("Y", $SelectedMonthTimestamp));
        $SelectedMonthNumber = intval(date("m", $SelectedMonthTimestamp));
        $SelectedMonthAbbr = strtolower(date("M", $SelectedMonthTimestamp));
        // @codingStandardsIgnoreStart
    ?>
      <select class="calendar_events-month_selector" name="Month">
        <?PHP if (!$SelectedHasEvents) { ?>
          <!-- dummy option for the current month -->
          <option value="<?= $SelectedMonthAbbr; ?> <?= $SelectedYearNumber; ?>"><?= $SelectedMonth; ?></option>
        <?PHP } ?>
        <?PHP for ($i = $FirstYearNumber; $i <= $LastYearNumber; $i++) { ?>
          <optgroup label="<?= defaulthtmlentities($i); ?>">
            <?PHP for ($j = ($i == $FirstYearNumber ? $FirstMonthNumber : 1);
                       ($i == $LastYearNumber ? $j <= $LastMonthNumber : $j < 13);
                       $j++)
                  {
                      $Selected = $SelectedYearNumber == $i && $SelectedMonthNumber == $j;
                      $MonthName = date("F", (int)mktime(0, 0, 0, $j));
                      $MonthNameAbbr = date("M", (int)mktime(0, 0, 0, $j));
                      $EventCount = $EventCounts[$MonthNameAbbr.$i];
                                                                              ?>
              <option <?PHP if ($Selected) print ' selected="selected" '; ?>
                      <?PHP if ($EventCount < 1) print ' disabled="disabled" '; ?>
                      value="<?= strtolower($MonthNameAbbr)." ".$i; ?>">
                <?= $MonthName; ?>
              </option>
            <?PHP } ?>
          </optgroup>
        <?PHP } ?>
      </select>
      <script type="text/javascript">
        (function(){
          var selector = jQuery(".calendar_events-month_selector");

          // explicitly set the width so that it doesn't change when the selected
          // option's text does
          selector.css("width", "135px");

          // submit the form when the selector changes
          selector.change(function(){
            <?PHP if ((ApplicationFramework::getInstance())->cleanUrlSupportAvailable()) { ?>
              // use clean URLs whenever possible
              var values = jQuery(this).val().split(" ");
              window.location.href = cw.getBaseUrl() + "<?= $CleanUrlPrefix; ?>/month/" + values[1] + "/" + values[0];
            <?PHP } else { ?>
              this.form.submit();
            <?PHP } ?>
          });

          // remove the year from the selected option when the select box gets
          // activated, but keep the width the same
          selector.mousedown(function(){
            var selector = jQuery(this),
                selected = jQuery("option:selected", this);
            selected.html(jQuery.trim(selected.html()).split(/\s+/)[0]);
          });

          // add the year back in.this isn't perfect, but it's best way (so far) to
          // get this to work cross-browser.the only issue comes up when the user
          // selects an already-selected option, which shouldn't be often.also
          selector.bind("change blur", function(){
            var selected = jQuery("option:selected", this),
                label = jQuery.trim(selected.html());

            if (label.search(/[0-9]{4}$/) === -1) {
              selected.html(label + " " + jQuery.trim(selected.parent().attr("label")));
            }
          });

          // add the month for the initially selected value.don't use "change"
          // because it will submit the form
          selector.blur();
        }());
      </script>
    <?PHP
        // @codingStandardsIgnoreEnd
    }
}
