<?PHP
#
#   FILE:  ResourceSummary_Default.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2018-2024 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus;

use ScoutLib\ApplicationFramework;
use ScoutLib\Image;

/**
* Class for default resource summary display.
*/
class ResourceSummary_Default extends ResourceSummary
{
    # ---- CONFIGURATION -----------------------------------------------------

    const MAX_DESCRIPTION_LENGTH = 300;
    const MAX_RESOURCE_LINK_LENGTH = 60;
    const MAX_URL_LENGTH = 60;

    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Return output HTML for resource summary.
     * @return string|false The HTML string for this ResourceSummary.
     */
    public function getHtml()
    {
        ob_start();
        $this->display();
        return ob_get_clean();
    }

    /**
     * Display (output HTML) for resource summary.
     * @return void
     */
    public function display(): void
    {
        $AF = ApplicationFramework::getInstance();
        $Resource = $this->Resource;

        # retrieve user currently logged in
        $User = User::getCurrentUser();

        # retrieve and format title
        $Schema = new MetadataSchema($Resource->getSchemaId());
        $TitleField = $Schema->getFieldByMappedName("Title");
        $Title = $Resource->userCanViewField($User, $TitleField) ?
            StripXSSThreats($this->getFieldValue($TitleField)) :
            "";

        $UrlLooksValid = true;

        # get URL link (if any)
        $UrlField = $Schema->getFieldByMappedName("Url");
        $RealUrlLink = "";
        if (($UrlField !== null) && $Resource->userCanViewField($User, $UrlField)) {
            $Url = $this->getFieldValue($UrlField);

            if (is_string($Url) && strlen($Url)) {
                $UrlLink = ApplicationFramework::baseUrl()
                    ."index.php?P=GoTo&amp;ID=".$Resource->id()
                    ."&amp;MF=".$UrlField->id();
                $RealUrlLink = $this->getFieldValue($UrlField);

                if (!filter_var($RealUrlLink, FILTER_VALIDATE_URL)) {
                    $UrlLooksValid = false;
                }
            }
        }

        # get file link (if any)
        $FileField = $Schema->getFieldByMappedName("File");
        if ($FileField !== null) {
            if ($Resource->userCanViewField($User, $FileField)) {
                $FileList = $this->getFieldValue($FileField);
                if (is_array($FileList) && count($FileList)) {
                    $File = array_shift($FileList);
                    $FileLink = ApplicationFramework::baseUrl().$File->getLink();
                }
            }
        }

        # get link to resource and displayable link to resource
        $IntConfig = InterfaceConfiguration::getInstance();
        if (isset($UrlLink) && isset($FileLink)) {
            $ResourceLink = ($IntConfig->getString("PreferredLinkValue") == "FILE")
                    ? $FileLink
                    : $UrlLink;
        } elseif (isset($UrlLink)) {
            $ResourceLink = $UrlLink;
        } elseif (isset($FileLink)) {
            $ResourceLink = $FileLink;
        }

        $UrlLooksValid = isset($ResourceLink) && $UrlLooksValid;

        if (isset($ResourceLink)) {
            $ResourceLinkTag = "<a href=\"".$ResourceLink."\" title=\"Go to "
                    .(isset($Title) ? htmlspecialchars(strip_tags($Title))
                            : "Resource")."\""
                    .($IntConfig->getBool("ResourceLaunchesNewWindowEnabled")
                            ? " target=\"_blank\"" : "").">";
        }
        if (isset($UrlLink) && isset($ResourceLink) && ($ResourceLink == $UrlLink)) {
            if ((strlen($RealUrlLink) > static::MAX_RESOURCE_LINK_LENGTH) &&
                (strlen(strip_tags($RealUrlLink)) == strlen($RealUrlLink))) {
                $DisplayableResourceLink = substr(
                    $RealUrlLink,
                    0,
                    static::MAX_RESOURCE_LINK_LENGTH
                )."...";
            } else {
                $DisplayableResourceLink = $RealUrlLink;
            }
        }

        # get link to full record page
        $Target = "";
        if ($IntConfig->getBool("ResourceLaunchesNewWindowEnabled")) {
            $Target = ' target="_blank"';
        }
        $FullRecordLink = htmlspecialchars(
            preg_replace('%\$ID%', $Resource->Id(), $Schema->getViewPage())
        );
        $FullRecordAltText = "View More Info for "
            .(isset($Title) ? htmlspecialchars(strip_tags($Title)) : "Resource");
        $FullRecordLinkTag = '<a href="'.$FullRecordLink.'"'
            .' title="'.$FullRecordAltText.'"'.$Target.">";

        # retrieve and format description
        $Description = $this->getFormattedFieldValue(
            "Description",
            static::MAX_DESCRIPTION_LENGTH
        );

        # retrieve and format resource rating
        $RatingsEnabled = false;
        if ($Schema->fieldExists("Cumulative Rating")) {
            $RatingField = $Schema->getField("Cumulative Rating");
            $RatingsEnabled = $IntConfig->getBool("ResourceRatingsEnabled");
            $RatingsEnabled = $RatingsEnabled
                    && $Resource->userCanViewField($User, $RatingField);
            if ($RatingsEnabled) {
                $ResourceRating = $Resource->get($RatingField);

                # signal event to allow other code to change rating value
                $ResourceRating = $AF->signalEvent(
                    "EVENT_FIELD_DISPLAY_FILTER",
                    [
                        "Field" => $RatingField,
                        "Resource" => $Resource,
                        "Value" => $ResourceRating
                    ]
                )["Value"];

                # display star rating graphic for numeric rating, text for string rating
                if (is_numeric($ResourceRating)) {
                    $ScaledRating = $Resource->scaledCumulativeRating();
                    if (isset($ScaledRating) && $ScaledRating > 0) {
                        $StarCount = max(1, ($ScaledRating / 2));
                        $RatingGraphic = sprintf(
                            "StarRating--%d_%d.gif",
                            $StarCount,
                            (((int)$StarCount % 1) * 10)
                        );
                        $RatingAltText = sprintf(
                            "This resource has a %.1f-star rating.",
                            $StarCount
                        );
                        $RatingImg = ApplicationFramework::baseUrl()
                            .$AF->gUIFile($RatingGraphic);
                    }
                } else {
                    $RatingString = $ResourceRating;
                }
            }
        }

        # TODO $UserRating is not currently used, but it should be; restore it
        if ($User->isLoggedIn()) {
            $UserRating = $Resource->rating();
            if ($UserRating == null) {
                $UserRating = 0;
            }
        }

        # get the schema name associated with this resource
        $SchemaCSSName = "mv-resourcesummary-resourcetype-tag-".
                str_replace([" ", "/"], '', strtolower($Schema->name()));
        $SchemaItemName = $Schema->resourceName();
        $TitlesLinkTo = $IntConfig->getString("TitlesLinkTo");


        $GoButtonOpenTag = ($TitlesLinkTo == "RECORD") ? $FullRecordLinkTag
                            : ((isset($ResourceLinkTag) && $UrlLooksValid)
                                    ? $ResourceLinkTag
                                    : "");

        $GoButtonCloseTag = ($TitlesLinkTo == "RECORD") ? "</a>"
                            : ((isset($ResourceLinkTag) && $UrlLooksValid)
                                    ? "</a>"
                                    : "");

        // @codingStandardsIgnoreStart
        $HasRating = isset($RatingImg) && isset($RatingAltText);
        $CanRate = isset($RatingString) && $RatingsEnabled;
        $CanEdit = $this->Editable;
?>
<div class="container-fluid mv-resourcesummary">
  <div class="row">
    <div class="col-auto d-none <?= $this->ShowScreenshot ? "d-sm-flex" : "";?>">
      <?PHP $this->displayScreenshot($Title, $GoButtonOpenTag, $GoButtonCloseTag); ?>
    </div>
    <div class="col">
      <div class="mv-content-resourcesummary-data container-fluid">
        <div class="row align-items-end">
          <div class="col mr-auto">
              <?PHP if (isset($Title)) { ?>
                  <h3 class="mv-resource-title" dir="auto"
                    ><?= $GoButtonOpenTag ?><?= $Title ?><?= $GoButtonCloseTag ?></h3>
              <?PHP if ($this->IncludeResourceType) { ?>
                <span class="<?= $SchemaCSSName ?> mv-resourcesummary-resourcetype-tag"
                      ><?= $SchemaItemName ?></span>
              <?PHP } ?>
            <?PHP } ?>
          </div>
          <?PHP if ($HasRating || $CanRate || $CanEdit) { ?>
          <div class="col-auto">
            <ul class="list-group list-group-flush list-group-horizontal">
              <?PHP if ($HasRating) { ?>
                <li class="list-group-item"
                  ><img src="<?= $RatingImg ?>" title="<?= $RatingAltText ?>" alt="<?= $RatingAltText ?>"
                        class="mv-rating-graphic" /></li>
              <?PHP } else if ($CanRate) { ?>
                <li class="list-group-item"><?= $RatingString ?></li>
              <?PHP } ?>
              <?PHP if ($CanEdit) { ?>
                <li class="list-group-item">
                  <a class="btn btn-primary btn-sm mv-button-iconed"
                     href="<?= $Resource->getEditPageUrl(); ?>"><img class="mv-button-icon"
                         src="<?= $AF->gUIFile("Pencil.svg") ?>"
                         alt="" /> Edit</a>
                </li>
              <?PHP } ?>
              <?PHP  $this->signalInsertionPoint("Resource Summary Buttons"); ?>
            </ul>
          </div>
         <?PHP } ?>
        </div>

        <div class="row">
          <div class="col">
            <div class="mv-description" dir="auto"><?= $Description ?></div>
            <?PHP  if ($TitlesLinkTo == "URL") { ?>
            <div class="mv-moreinfo"><a href="<?= $FullRecordLink ?>"
                      title="<?= $FullRecordAltText ?>">(More Info)</a></div>
            <?PHP } ?>

            <?PHP  $this->signalInsertionPoint("After Resource Description");  ?>

            <?PHP if (isset($DisplayableResourceLink)) { ?>
              <div class="mv-content-fullurl-container"></div>
            <?PHP } ?>
            <div class="mv-content-categories">
              <?PHP $this->displayCategories(); ?>
            </div>
           </div>
         </div>
      </div>
    </div>
  </div>
</div>
        <?PHP
        // @codingStandardsIgnoreEnd
    }

    /**
     * Display (output HTML) for compact resource summary.
     * @return void
     */
    public function displayCompact(): void
    {
        $Resource = $this->Resource;

        # retrieve user currently logged in
        $User = User::getCurrentUser();

        # retrieve and format title
        $Schema = new MetadataSchema($Resource->getSchemaId());
        $TitleField = $Schema->getFieldByMappedName("Title");
        if ($Resource->userCanViewField($User, $TitleField)) {
            $Title = StripXSSThreats($this->getFieldValue($TitleField));
        }

        $UrlLooksValid = true;

        # get URL link (if any)
        $UrlField = $Schema->getFieldByMappedName("Url");
        $RealUrlLink = "";
        if (($UrlField !== null) && $Resource->userCanViewField($User, $UrlField)) {
            $Url = $this->getFieldValue($UrlField);

            if (strlen($Url)) {
                $UrlLink = ApplicationFramework::baseUrl()
                    ."index.php?P=GoTo&amp;ID=".$Resource->id()
                    ."&amp;MF=".$UrlField->id();
                $RealUrlLink = $this->getFieldValue($UrlField);

                if (!filter_var($RealUrlLink, FILTER_VALIDATE_URL)) {
                    $UrlLooksValid = false;
                }
            }
        }

        # get file link (if any)
        $FileField = $Schema->getFieldByMappedName("File");
        if ($FileField !== null) {
            if ($Resource->userCanViewField($User, $FileField)) {
                $FileList = $this->getFieldValue($FileField);
                if (is_array($FileList) && count($FileList)) {
                    $File = array_shift($FileList);
                    $FileLink = ApplicationFramework::baseUrl().$File->getLink();
                }
            }
        }

        # get link to resource and displayable link to resource
        $IntConfig = InterfaceConfiguration::getInstance();
        if (isset($UrlLink) && isset($FileLink)) {
            $ResourceLink = ($IntConfig->getString("PreferredLinkValue") == "FILE")
                    ? $FileLink
                    : $UrlLink;
        } elseif (isset($UrlLink)) {
            $ResourceLink = $UrlLink;
        } elseif (isset($FileLink)) {
            $ResourceLink = $FileLink;
        }

        $UrlLooksValid = isset($ResourceLink) && $UrlLooksValid;

        if (isset($ResourceLink)) {
            $ResourceLinkTag = "<a href=\"".$ResourceLink."\" title=\"Go to "
                    .(isset($Title) ? htmlspecialchars(strip_tags($Title))
                            : "Resource")."\""
                    .($IntConfig->getBool("ResourceLaunchesNewWindowEnabled")
                            ? " target=\"_blank\"" : "").">";
        }
        # TODO $DisplayableResourceLink is not currently used
        if (isset($UrlLink) && isset($ResourceLink) && ($ResourceLink == $UrlLink)) {
            if ((strlen($RealUrlLink) > static::MAX_RESOURCE_LINK_LENGTH) &&
                (strlen(strip_tags($RealUrlLink)) == strlen($RealUrlLink))) {
                $DisplayableResourceLink = substr(
                    $RealUrlLink,
                    0,
                    static::MAX_RESOURCE_LINK_LENGTH
                )."...";
            } else {
                $DisplayableResourceLink = $RealUrlLink;
            }
        }

        # get link to full record page
        $FullRecordLink = htmlspecialchars(
            preg_replace('%\$ID%', $Resource->Id(), $Schema->getViewPage())
        );
        $FullRecordLinkTag = "<a href=\"".$FullRecordLink."\""
                ." title=\"View More Info for ".(isset($Title)
                        ? htmlspecialchars(strip_tags($Title)) : "Resource")."\">";

        # retrieve and format description
        $Description = $this->getFormattedFieldValue(
            "Description",
            static::MAX_DESCRIPTION_LENGTH
        );

        # get the schema name associated with this resource
        $SchemaCSSName = "mv-resourcesummary-resourcetype-tag-".
                str_replace([" ", "/"], '', strtolower($Schema->name()));
        $SchemaItemName = $Schema->resourceName();

        $GoButtonOpenTag = ($IntConfig->getString("TitlesLinkTo") == "RECORD")
                            ? $FullRecordLinkTag
                            : ((isset($ResourceLinkTag) && $UrlLooksValid)
                                    ? $ResourceLinkTag
                                    : "");

        $GoButtonCloseTag = ($IntConfig->getString("TitlesLinkTo") == "RECORD")
                            ? "</a>"
                            : ((isset($ResourceLinkTag) && $UrlLooksValid)
                                    ? "</a>"
                                    : "");

        // @codingStandardsIgnoreStart
        ?>
        <div class="container bg-light border rounded table-responsive">
        <table class="table-sm mv-content-resourcesummary">
            <tbody>
                <tr>
                    <td>
                      <?PHP if (isset($Title) || $Description) { ?>
                            <?PHP if (isset($Title)) { ?>
                                <?= $GoButtonOpenTag ?>
                                <strong><?= $Title; ?></strong>
                                <?= $GoButtonCloseTag ?>
                                <?PHP if ($this->IncludeResourceType) { ?>
                                    <span class="<?= $SchemaCSSName
                                            ?> mv-resourcesummary-resourcetype-tag"><?=
                                            $SchemaItemName ?></span>
                                <?PHP } ?>
                            <?PHP } ?>

                            <?PHP if ($Description) { ?>
                            <p><?= $Description ?></p>
                            <?PHP } ?>
                            <?PHP  $this->signalInsertionPoint(
                                    "After Resource Description");  ?>
                        <?PHP } ?>
                    </td>
            </tbody>
        </table>
        </div>
        <?PHP
        // @codingStandardsIgnoreEnd
    }


    # ---- PRIVATE INTERFACE -------------------------------------------------

    /**
     * Display screenshot associated with resource.
     * @param string $Title Title of resource.
     * @param string $GoButtonOpenTag HTML to open the 'Go' button tag.
     * @param string $GoButtonCloseTag HTML to close the 'Go' button tag.
     * @return void
     */
    protected function displayScreenshot(
        string $Title,
        string $GoButtonOpenTag,
        string $GoButtonCloseTag
    ): void {
        # TODO $GoButtonOpenTag and $GoButtonCloseTag are unused
        $GoButtonOpenTagNoFocus = str_replace(
            "href=",
            "tabindex=\"-1\" href=",
            $GoButtonOpenTag
        );

        # retrieve and format screenshot thumbnail
        $ScreenshotField = $this->Resource->getSchema()->getFieldByMappedName("Screenshot");
        $ScreenshotAltText = "";
        $ScreenshotThumbnailUrl = "";
        $FullImageUrl = "";
        if ($ScreenshotField && $ScreenshotField->status() == MetadataSchema::MDFSTAT_OK &&
            $this->Resource->userCanViewField(User::getCurrentUser(), $ScreenshotField)) {
            $Screenshot = $this->getFieldValue($ScreenshotField);

            # if $Screenshot is an array of images, use the first one as thumbnail
            if (is_array($Screenshot)) {
                if (count($Screenshot)) {
                    $Screenshot = array_shift($Screenshot);
                    if (!($Screenshot instanceof \Metavus\Image)) {
                        unset($Screenshot);
                    } else {
                        $FullImageUrl = ApplicationFramework::baseUrl()
                            ."index.php?P=FullImage"
                            ."&amp;RI=".$this->Resource->id()
                            ."&amp;FI=".$ScreenshotField->id()
                            ."&amp;ID=".$Screenshot->id();

                        $ScreenshotAltText = $Screenshot->altText();
                        if (!strlen($ScreenshotAltText)) {
                            $ScreenshotAltText = "Screenshot for ".$Title;
                        }
                    }
                } else {
                    unset($Screenshot);
                }
            }
        }
        ?>
      <div class="mv-content-resourcesummary-screenshot">
            <?PHP if (isset($Screenshot)) {  ?>
                <?PHP if ($Screenshot instanceof \Metavus\Image) { ?>
                <a href="<?= $FullImageUrl ?>"
                   title="View screenshot for <?= $Title ?>"
                   tabindex="-1"><?= $Screenshot->getHtml("mv-image-screenshot") ?></a>
                <?PHP } else { ?>
                    <?= $Screenshot; ?>
                <?PHP } ?>
            <?PHP } else {?>
                <div class="mv-image-screenshot-container bg-transparent"></div>
            <?PHP } ?>
       </div>
        <?PHP
    }

    /**
     * Display hierarchical (Tree) values associated with resource.
     * @return void
     */
    protected function displayCategories(): void
    {
        $TreeFields = $this->Resource->getSchema()->getFields(
            MetadataSchema::MDFTYPE_TREE
        );

        if (count($TreeFields) == 0) {
            return;
        }

        $Field = reset($TreeFields);
        $Terms = $this->Resource->get($Field);

        $SearchUrlBase = ApplicationFramework::baseUrl()
            ."index.php?P=SearchResults";

        $TermLinks = [];
        foreach ($Terms as $Term) {
            $SearchParams = new SearchParameterSet();
            $SearchParams->addParameter(
                "=".$Term,
                $Field
            );
            $TermLinks[] = '<a href="'.$SearchUrlBase
                .'&amp;'.$SearchParams->urlParameterString()
                .'">'.htmlspecialchars($Term).'</a>';
        }

        print (implode(" &bull; ", $TermLinks));
    }

    /**
     * Print a UI element that allows users to quickly rate a resource.
     * @param int $ResourceId ID of the resource to apply the rating to.
     * @param int $UserRating User's current rating for the resource, if applicable.
     * @return void
     */
    protected static function displayFastRating(int $ResourceId, $UserRating = 0): void
    {
        $AF = ApplicationFramework::getInstance();
        static $SupportJsDisplayed = false;

        if (!$SupportJsDisplayed) {
            // @codingStandardsIgnoreStart
?><script type="text/javascript">
    var RateResource = new Image();
    var SavedRatings = new Array();

    function SwapStars( ResourceId, NumStars, Out, ImageURLBase ) {
        var ImageHolder;

        // If this was a onmouseout event we need to check which
        //  star image will be loaded
        if (Out && SavedRatings[ ResourceId ]) {
            NumStars = SavedRatings[ ResourceId ];
        }

        // Grab the image and change it
        ImageHolder = document.images[ "Stars" + ResourceId ];
        ImageHolder.src = ImageURLBase + "BigStars--" + NumStars + "_0.gif";
    }

    function Rate( ResourceId, NumStars ) {
        var Holder;
        var BaseUrl = cw ? cw.getBaseUrl() : "";

        // Rate the resource
        RateResource.src = BaseUrl + "index.php?P=RateResource"
            + "&F_ResourceId=" + ResourceId
            + "&F_Rating=" + (NumStars*20);

        // Save the rating for onmouseout events
        SavedRatings[ResourceId] = NumStars;

        // Change the background image to indicate that the rating has been saved
        Holder = document.getElementById( "RatingLabelDiv" + ResourceId );
        Holder.style.color = "";
    }
</script>
<?PHP
            // @codingStandardsIgnoreEnd
            $SupportJsDisplayed = true;
        }

        $Stars = intval(($UserRating + 5) / 20);
        $UserRatingGraphic = "BigStars--".$Stars."_0.gif";

        # determine rating graphic alt tag
        if (!$UserRating) {
            $RatingGraphicAlt = "You have not yet rated this resource.";
        } else {
            $RatingGraphicAlt = (1 <= $Stars && $Stars <= 5) ?
                "You have given this resource a ".$Stars." star rating." :
                "The rating for this resource is unavailable.";
        }

        $RatingUrlHead = ApplicationFramework::baseUrl()."index.php?P=RateResource"
            ."&amp;F_ResourceId=".$ResourceId."&amp;F_Rating=";
        $ImageURLBase = ApplicationFramework::baseUrl()
            .$AF->gUIFile($UserRatingGraphic);
        $ImageURLBase = preg_replace('/BigStars.*/', '', $ImageURLBase);
        $OnMouseOut = "SwapStars( '".$ResourceId."', ".$Stars.", true, '"
            .$ImageURLBase."' );";

        $MapArray = [
            [
                "StarNumber" => 1,
                "TitleText" => "One Star",
                "Coordinates" => "0,0,14,16",
                "Position" => "20"
            ],
            [
                "StarNumber" => 2,
                "TitleText" => "Two Stars",
                "Coordinates" => "15,0,29,16",
                "Position" => "40"
            ],
            [
                "StarNumber" => 3,
                "TitleText" => "Three Stars",
                "Coordinates" => "30,0,44,16",
                "Position" => "60"
            ],
            [
                "StarNumber" => 4,
                "TitleText" => "Four Stars",
                "Coordinates" => "45,0,59,16",
                "Position" => "80"
            ],
            [
                "StarNumber" => 5,
                "TitleText" => "Five Stars",
                "Coordinates" => "60,0,75,16",
                "Position" => "100"
            ],
        ];
        // @codingStandardsIgnoreStart
?>
    <div class="RatingDiv mv-content-rating" id="RatingDiv<?= $ResourceId ?>">
        <img src="<?= ApplicationFramework::baseUrl().$AF->gUIFile($UserRatingGraphic); ?>"
          width="75" height="16" border="0" usemap="#StarMap_<?= $ResourceId; ?>"
          id="Stars<?= $ResourceId; ?>" alt="<?= $RatingGraphicAlt; ?>" class="inline">
    </div>
    <div
     class="RatingDiv mv-content-rating <?= ($UserRating == 0) ? "mv-content-rating-user" :"" ?>"
 id="RatingLabelDiv<?= $ResourceId ?>" title="Why is it important that I rate resources?
When you rate resources, you give the portal information that helps
provide you with better recommendations and portal users with your opinion of
the value of a resource.The more users provide ratings, the more valuable
the portal becomes to the user community.

Can other users see what I rated a resource?
No.Only the cumulative rating and number of responses are shown to other
users.However, when you are logged in, you will be able to see how you
rated a selected resource from that resource's full or brief records.

Can I rate something more than once?
No, you cannot provide more than one rating for a selected resource.
However, you can change your rating.">
        Your&nbsp;Rating:&nbsp;
    </div>
    <map name="StarMap_<?= $ResourceId; ?>">
    <?PHP foreach ($MapArray as $Map) {?>
      <area shape="rect" title="<?= $Map["TitleText"] ?>" coords="<?= $Map["Coordinates"] ?>"
            href="<?= $RatingUrlHead.$Map["Position"] ?>"
            onmouseover="SwapStars('<?= $ResourceId; ?>', <?= $Map["StarNumber"];?>, false, '<?= $ImageURLBase ?>');"
            onfocus="SwapStars('<?= $ResourceId ?>', <?= $Map["StarNumber"] ?>, false, '<?= $ImageURLBase ?>' );"
            onclick="Rate('<?= $ResourceId; ?>', <?= $Map["StarNumber"]; ?>); return false;"
            onmouseout="<?= $OnMouseOut; ?>" onblur="<?= $OnMouseOut; ?>" >
    <?PHP } ?>
    </map>
<?PHP
    // @codingStandardsIgnoreEnd
    }
}
