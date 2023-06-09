<?PHP
#
#   FILE:  RecordImageCollage.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2021-2023 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins\Collage;

use Metavus\Image;
use Metavus\Record;
use ScoutLib\ApplicationFramework;
use ScoutLib\PluginManager;
use ScoutLib\StdLib;

/**
 * Generate HTML for an image collage
 */
class RecordImageCollage
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Get HTML for an image collage.
     * @param array $RecordIds IDs of records to display in collage
     * @return string HTML for collage, or an empty string if no record
     *      IDs were supplied.
     */
    public static function getHtml(array $RecordIds)
    {
        # if we weren't given records, don't try to get a collage
        if (count($RecordIds) == 0) {
            return "";
        }

        # if record list is too short for a full collage, extend by merging with self
        $Plugin = (PluginManager::getInstance())->getPlugin("Collage");
        $NumberOfImages = $Plugin->getNumberOfImages();
        $RecordIds = self::extendArray($RecordIds, $NumberOfImages);

        # randomize collage order
        shuffle($RecordIds);

        # prune out any adjacent repeated values from collage
        $RecordIds = self::pruneRepeatedValues($RecordIds);

        # calculate height for collage assuming square tiles
        $TileWidth = $Plugin->getConfigSetting("TileWidth");
        $CollageHeight = $Plugin->getConfigSetting("NumRows") * $TileWidth;

        # generate/return actual html
        $CollageHtml = "<div class=\"col mv-p-collage-wrapper\" style=\"height:"
            .$CollageHeight."px;\"><div class=\"mv-p-collage\">";
        foreach ($RecordIds as $Id) {
            $Record = new Record($Id);
            $CollageHtml .= self::getHtmlForRecord($Record);
        }
        $CollageHtml .= "</div></div>";

        return $CollageHtml;
    }

    /**
     * Get supporting HTML for image collages, that should be included once
     * (and only once) on any page that contains a collage.
     * @return string Supporting HTML.
     */
    public static function getSupportingHtml()
    {
        (ApplicationFramework::getInstance())->requireUIFile("RecordImageCollage.js");

        $Plugin = (PluginManager::getInstance())->getPlugin("Collage");
        $TileWidth = $Plugin->getConfigSetting("TileWidth");
        $DialogWidth = $Plugin->getConfigSetting("DialogWidth");
        $MaxViewportWidth = $Plugin->getConfigSetting("MaxExpectedViewportWidth");
        $NumRows = $Plugin->getConfigSetting("NumRows");

        $SupportingHtml = "<div id=\"mv-rollover-dialog\" style=\"display: none;\" "
                ."data-tile-width=\"".$TileWidth."\" "
                ."data-dialog-width=\"".$DialogWidth."\" "
                ."data-expected-vp-width=\"".$MaxViewportWidth."\" "
                ."data-num-rows=\"".$NumRows."\">"
                ."<p><a class=\"mv-url\"></a><br>"
                ."<a class=\"mv-fullrecord\">(More Info)</a></p>"
                ."<p class=\"mv-description\"></p></div>";
        return $SupportingHtml;
    }


    # ---- PRIVATE INTERFACE -------------------------------------------------

    /**
     * Get html for one record (image and dialogue data)
     * @param Record $Record record to get html for
     * @return string html div with image and data for popup
     */
    private static function getHtmlForRecord(Record $Record)
    {
        $Screenshot = $Record->getMapped("Screenshot", true);
        if (is_null($Screenshot) || count($Screenshot) == 0) {
            return "";
        }
        $Screenshot = reset($Screenshot);
        $Plugin = (PluginManager::getInstance())->getPlugin("Collage");
        $TileWidth = $Plugin->getConfigSetting("TileWidth");
        $ImageSize = Image::getNextLargestSize($TileWidth, $TileWidth);
        $ImageUrl = $Screenshot->url($ImageSize);

        # entity-encoded text in data attributes is correctly handled by jquery-ui
        $Title = (string)$Record->getMapped("Title");
        $Description = (string)$Record->getMapped("Description");
        $Url = (string)$Record->getMapped("Url");
        $FullRecordUrl = $Record->getViewPageUrl();
        $GoToUrl = ApplicationFramework::baseUrl().
            ApplicationFramework::getInstance()
            ->getCleanRelativeUrlForPath(
                "index.php?P=GoTo"
                ."&ID=".$Record->id()
                ."&MF=".$Record->getSchema()->stdNameToFieldMapping("Url")
            );

        return "<div class=\"mv-p-collage-tile\"".
        " title=\"".htmlspecialchars($Title)." [click for detail]\"".
        " data-title=\"".htmlspecialchars($Title)."\"".
        " data-description=\"".htmlspecialchars($Description)."\"".
        " data-url=\"".htmlspecialchars($Url)."\"".
        " data-fullrecord=\"".htmlspecialchars($FullRecordUrl)."\"".
        " data-goto=\"".htmlspecialchars($GoToUrl)."\"".
        "><img src=\"".$ImageUrl."\" alt=\"\" ".
        "style=\"width:".$TileWidth."px; ".
        "height:".$TileWidth."px;\" /></div>";
    }

    /**
     * Prune any repeated values out of array, extending the array if necessary
     * by adding copies of the array to the end.
     * @param array $Values Array to prune.
     * @return array Pruned array.
     */
    private static function pruneRepeatedValues(array $Values): array
    {
        $OriginalLength = count($Values);

        $NewValues = [];
        $LastValue = null;
        foreach ($Values as $Value) {
            if ($Value != $LastValue) {
                $NewValues[] = $Value;
                $LastValue = $Value;
            }
        }

        $NewValues = self::extendArray($NewValues, $OriginalLength);
        return $NewValues;
    }

    /**
     * Extend supplied array to specified length, by adding copies of array
     * on to end if necessary.  Numerical keys are assumed and used in the
     * returned array.
     * @param array $Values Array to extend.
     * @param int $Length Target array length.
     * @return array Array of specified length.
     */
    private static function extendArray(array $Values, int $Length): array
    {
        $NewValues = $Values;
        while (count($NewValues) < $Length) {
            $NewValues = array_merge(
                $NewValues,
                $Values
            );
        }
        return array_slice($NewValues, 0, $Length);
    }
}
