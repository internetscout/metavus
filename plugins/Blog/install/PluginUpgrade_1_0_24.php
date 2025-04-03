<?PHP
#
#   FILE:  PluginUpgrade_1_0_24.php (Blog plugin)
#
#   A plugin upgrade file for the Metavus digital collections platform
#   Copyright 2024 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins\Blog;

use Metavus\MetadataSchema;
use Metavus\Plugins\Blog;
use ScoutLib\PluginUpgrade;

/**
 * Class for upgrading the Blog plugin to version 1.0.24.
 */
class PluginUpgrade_1_0_24 extends PluginUpgrade
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Perform actions necessary to upgrade plugin to version 1.0.24.
     * @return null|string Return NULL if upgrade succeeeded, or string
     *      containing error message if upgrade failed.
     */
    public function performUpgrade()
    {
        $Plugin = Blog::getInstance(true);
        # modify existing entries to insert images into body
        foreach ($Plugin->getBlogEntries() as $Entry) {
            $this->insertImagesIntoBody($Entry);
        }

        # update image field to allow multiple images
        $Schema = new MetadataSchema($Plugin->getSchemaId());
        $Schema->getField(Blog::IMAGE_FIELD_NAME)->allowMultiple(true);
        return null;
    }

    # ---- PRIVATE INTERFACE -------------------------------------------------
    /**
     * Inserts Image HTML into a given Blog Entry's body. Only for use with
     * upgrade().
     * @param Entry $Entry The Blog Entry to insert images into.
     * @note This method should only be used for upgrade().
     */
    private function insertImagesIntoBody(Entry $Entry): void
    {
        # get the unadultered body of the entry and its images
        $Body = $Entry->get("Body");
        $Images = $Entry->images();

        # return the body as-is if there are no images associated with the blog
        # entry
        if (count($Images) < 1) {
            return;
        }

        # get all of the image insertion points
        $ImageInsertionPoints = $this->getImageInsertionPoints($Body);

        # display all of the images at the top if there are no insertion points
        if (count($ImageInsertionPoints) < 1) {
            $ImageInsertionPoints = [0];
        }

        # variables used to determine when and where to insert images
        $ImagesPerPoint = ceil(count($Images) / count($ImageInsertionPoints));
        $ImagesInserted = 0;
        $InsertionOffset = 0;

        foreach ($Images as $Image) {
            $ImageInsert = "<img src=\"".$Image->url("mv-image-preview")."\" alt=\""
                    .htmlspecialchars($Image->altText())
                    ."\" class=\"mv-form-image-right\" />";
            # determine at which insertion point to insert this images
            $InsertionPointIndex = floor($ImagesInserted / $ImagesPerPoint);
            $ImageInsertionPoint = $ImageInsertionPoints[$InsertionPointIndex];

            # insert the image into the body, offsetting by earlier insertions
            $Body = substr_replace(
                $Body,
                $ImageInsert,
                $ImageInsertionPoint + $InsertionOffset,
                0
            );

            # increment the variables used to determine where to insert the next
            # image
            $InsertionOffset += strlen($ImageInsert);
            $ImagesInserted += 1;
        }

        $Entry->set("Body", $Body);
    }

    /**
     * Get the best image insertion points for the Blog Entry.
     * @param string $Body The Blog Entry body to insert into.
     * @return array Returns the best image insertion points of the Blog Entry.
     * @note This method should only be used for upgrade().
     */
    private function getImageInsertionPoints(string $Body): array
    {
        $Offset = 0;
        $Positions = [];

        # put a hard limit on the number of loops
        for ($i = 0; $i < 20; $i++) {
            # search for an image marker
            $MarkerData = $this->getEndOfFirstParagraphPositionWithLines($Body, $Offset);

            if ($MarkerData === false) {
                break;
            }

            list($Position, $Length) = $MarkerData;

            # didn't find a marker so stop
            if ($Position === false) {
                break;
            }

            # save the position and update the offset
            $Positions[] = $Position;
            $Offset = $Position + $Length;
        }

        return $Positions;
    }

    /**
     * Try to find the end of the first paragraph in some HTML using blank lines.
     * @param string $Html HTML in which to search.
     * @param int $Offset Position in the string to begin searching (OPTIONAL, default 0).
     * @return array|false Returns the position and length if found or FALSE otherwise.
     */
    private function getEndOfFirstParagraphPositionWithLines($Html, $Offset = 0)
    {
        # save the initial length so that the offset of the HTML in the original
        # HTML can be found after trimming
        $InitialLength = strlen($Html);

        # strip beginning whitespace and what is rendered as whitespace in HTML
        $Html = $this->leftTrimHtml($Html);

        $Plugin = Blog::getInstance(true);
        # find the next double (or more) blank line
        preg_match(
            '/'.$Plugin::DOUBLE_BLANK_REGEX.'/',
            $Html,
            $Matches,
            PREG_OFFSET_CAPTURE,
            $Offset
        );

        # a double (or more) blank line wasn't found
        if (!count($Matches)) {
            return false;
        }

        # return the position before the blank lines and their length
        return [
            $Matches[0][1] + ($InitialLength - strlen($Html)),
            strlen($Matches[0][0])
        ];
    }

    /**
     * Removes whitespace and most HTML that is rendered as whitespace from the
     * beginning of some HTML.
     * @param string $Html HTML to trim.
     * @return string Returns the trimmed HTML.
     */
    private function leftTrimHtml($Html)
    {
        # remove whitespace from the beginning
        $Html = ltrim($Html);

        $Plugin = Blog::getInstance(true);
        # now remove items that act as whitespace in HTML
        $Html = preg_replace('/^'.$Plugin::HTML_BLANK_REGEX.'+/', "", $Html);

        # do one last left trim
        $Html = ltrim($Html);

        # return the new HTML
        return $Html;
    }
}
