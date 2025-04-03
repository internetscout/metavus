<?PHP
#
#   FILE:  Entry.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2013-2022 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#

namespace Metavus\Plugins\Blog;

use Metavus\Image;
use Metavus\ImageFactory;
use Metavus\Message;
use Metavus\MetadataField;
use Metavus\MetadataSchema;
use Metavus\Plugins\Blog;
use Metavus\Record;
use Metavus\User;
use Metavus\UserFactory;
use ScoutLib\ApplicationFramework;
use ScoutLib\Database;
use ScoutLib\StdLib;

/**
* Represents a blog entry resource.
*/
class Entry extends Record
{
    const NEWLINE_REGEX = '(\r\n|\r|\n)';

    /**
     * This is a literal pattern, NOT regex, to match '--' as an explicit marker.
     */
    const EXPLICIT_MARKER = '<p>--</p>';

    /**
     * Users are expected to use '--' as an explicit marker for the teaser break in entries.
     * CKEDITOR automatically surrounds this marker with <p> tags, so they need to be
     * captured by regex as well.
     */
    const EXPLICIT_TEASER_BREAK_REGEX =
            self::NEWLINE_REGEX . self::EXPLICIT_MARKER . self::NEWLINE_REGEX;

    /**
     * Get the blog entry comments in ascending order.
     * @return array Returns an array of blog entry comments as Message objects.
     */
    public function comments(): array
    {
        # read in comments if not already loaded
        if (!isset($this->Comments)) {
            $Database = new Database();
            $Database->query("
                SELECT MessageId FROM Messages
                WHERE ParentId = ".$this->id()."
                AND ParentType = 2
                ORDER BY DatePosted ASC");

            while ($MessageId = $Database->fetchField("MessageId")) {
                $this->Comments[] = new Message($MessageId);
            }
        }

        # return array of comments to caller
        return $this->Comments;
    }

    /**
     * Get the URL to the blog entry relative to the CWIS root.
     * @param array $Get Optional GET parameters to add.
     * @param string $Fragment Optional fragment ID to add.
     * @return string Returns the URL to the blog entry relative to the CWIS root.
     */
    public function entryUrl(array $Get = [], $Fragment = null)
    {
        $BlogPlugin = Blog::getInstance();
        $UrlPrefix = $BlogPlugin->blogSetting($this->getBlogId(), "CleanUrlPrefix");

        # if clean URLs are available
        $AF = ApplicationFramework::getInstance();
        if ($AF->cleanUrlSupportAvailable() && strlen($UrlPrefix) > 0) {
            # base part of the URL
            $Url = $UrlPrefix . "/" . urlencode((string) $this->id()) . "/";

            # add the title
            $Url .= urlencode($this->titleForUrl());
        # clean URLs aren't available
        } else {
            # base part of the URL
            $Url = "index.php";

            # add the page to the GET parameters
            $Get["P"] = "P_Blog_Entry";
            $Get["ID"] = $this->id();
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
     * Get the title field value for the blog entry.
     * @return string Returns the blog entry title.
     */
    public function title()
    {
        return $this->get(Blog::TITLE_FIELD_NAME);
    }

    /**
     * Get the body field value for the blog entry but truncated. This tries to
     * use paragraph tags first, then multiple newlines second, and
     * NeatlyTruncateString() last.
     * @param int $MaxLength The maximum length of the teaser.
     * @return string Returns the truncated blog entry body.
     */
    public function teaser($MaxLength = 1200)
    {
        $Body = $this->get(Blog::BODY_FIELD_NAME);

        if (is_null($Body)) {
            return "";
        }

        # filter inserted images out
        $Body = preg_replace(
            '%<div class="mv-form-image-(right|left)">\s*'
            .'<img [^>]*/>\s*'
            .'(<div [^>]*>.*?</div>\s*)?' # remove image caption, if present
            .'</div>%',
            '',
            $Body
        );

        # also filter any other images present
        $Body = preg_replace('%<img [^>]*>%', '', $Body);

        # try to find the '--' marker first
        $Position = strpos($Body, self::EXPLICIT_MARKER);

        # if a good position could be found
        if ($Position !== false && $Position <= $MaxLength) {
            return substr($Body, 0, $Position);
        }

        # a good position wasn't found, truncate body and return with '--' removed
        $TruncatedBody = StdLib::neatlyTruncateString(trim($Body), $MaxLength);
        return str_replace(self::EXPLICIT_MARKER, "", $TruncatedBody);
    }

    /**
     * Get the author field value for the blog entry.
     * @return Metavus\User Returns the blog entry author as a User object.
     */
    public function author()
    {
        return $this->get(Blog::AUTHOR_FIELD_NAME, true);
    }

    /**
     * Get the editor field value for the blog entry.
     * @return Metavus\User Returns the last editor of the blog entry as a User object.
     */
    public function editor()
    {
        return $this->get(Blog::EDITOR_FIELD_NAME, true);
    }

    /**
     * Get the creation date field value for the blog entry.
     * @return string Returns the blog entry creation date.
     */
    public function creationDate()
    {
        return $this->get(Blog::CREATION_DATE_FIELD_NAME);
    }

    /**
     * Get the modification date field value for the blog entry.
     * @return string Returns the blog entry modification date.
     */
    public function modificationDate()
    {
        return $this->get(Blog::MODIFICATION_DATE_FIELD_NAME);
    }

    /**
     * Get the publication date field value for the blog entry.
     * @return string Returns the blog entry publication date.
     */
    public function publicationDate()
    {
        return $this->get(Blog::PUBLICATION_DATE_FIELD_NAME);
    }

    /**
     * Get the categories field value for the blog entry.
     * @return array Returns the blog entry categories as an array of ControlledName
     *     objects.
     */
    public function categories()
    {
        $Categories = $this->get(Blog::CATEGORIES_FIELD_NAME, true);
        return is_null($Categories) ? [] : $Categories;
    }

    /**
     * Get the the first image field value for the blog entry.
     * @return Image|null Returns the blog entry image as an Image object.
     */
    public function image()
    {
        $Images = $this->images();

        # nothing to return if this entry has no associated images
        if (count($Images) == 0) {
            return null;
        }

        # get raw body without image keyword replacements
        $Body = parent::get(Blog::BODY_FIELD_NAME);

        # look for the first image
        $Result = preg_match(
            '%<img[^>]*src="[^"]*{{IMAGEURL\|Id:([0-9]+)\|Size:mv-image-[a-z]+}}"%',
            $Body,
            $Matches
        );

        # if an image was found and it is associated with this entry, return it
        if ($Result && isset($Images[$Matches[1]])) {
            return $Images[$Matches[1]];
        }

        # otherwise use the first available image
        return array_shift($Images);
    }

    /**
     * Determine if a caption should appear in the teaser based on the presence/absence
     * of a caption for the first image included in the body of the blog post.
     * @return bool TRUE when captions should be displayed, FALSE otherwise.
     */
    public function shouldDisplayCaptionInTeaser() : bool
    {
        # get raw body without image keyword replacements
        $Body = parent::get(Blog::BODY_FIELD_NAME);

        # look for the first image
        $Result = preg_match(
            '%<img[^>]*src="[^"]*{{IMAGEURL\|Id:([0-9]+)\|Size:mv-image-[a-z]+}}"%',
            $Body,
            $Matches
        );

        # if no image, then no captions
        if ($Result !== 1) {
            return false;
        }

        # otherwise, show captions if the first image has them
        $ImageId = $Matches[1];
        $Result = preg_match(
            '%<div[^>]*class="mv-form-image-[a-z]+"[^>]*>\s*'
            .'<img[^>]*src="[^"]*{{IMAGEURL\|Id:'.$ImageId.'\|Size:mv-image-[a-z]+}}"[^>]*>\s*'
            .'<div class="mv-form-image-caption" %',
            $Body
        );

        return (bool)$Result;
    }

    /**
     * Get the image field value for the blog entry.
     * @return array Returns the blog entry images as an array of Image object.
     */
    public function images()
    {
        return $this->get(Blog::IMAGE_FIELD_NAME, true);
    }

    /**
     * Get the Blog name that this entry is from
     * @return string Returns the blog name as string
     */
    public function blogName()
    {
        return current($this->get(Blog::BLOG_NAME_FIELD_NAME));
    }

    /**
     * Get the title field value for displaying to users.
     * @return string Returns the title field value for display to users.
     */
    public function titleForDisplay()
    {
        $SafeTitle = StripTagsAttributes(
            $this->title(),
            [
                "StripTags" => true, "StripAttributes" => true,
                "Tags" => "b i u s em del sub sup br"
            ]
        );
        return $SafeTitle;
    }

    /**
     * Get the author field value for displaying to users.
     * @return string Returns the author field value for display to users.
     */
    public function authorForDisplay()
    {
        return $this->formatUserNameForDisplay($this->author());
    }

    /**
     * Get the editor field value for displaying to users.
     * @return string Returns the editor field value for display to users.
     */
    public function editorForDisplay()
    {
        return $this->formatUserNameForDisplay($this->editor());
    }

    /**
     * Get the creation date field value for displaying to users.
     * @return string Returns the creation date field value for display to users.
     */
    public function creationDateForDisplay()
    {
        return $this->formatTimestampForDisplay($this->creationDate());
    }

    /**
     * Get the modification date field value for displaying to users.
     * @return string Returns the modification date field value for display to users.
     */
    public function modificationDateForDisplay()
    {
        return $this->formatTimestampForDisplay($this->modificationDate());
    }

    /**
     * Get the publication date field value for displaying to users.
     * @return string Returns the publication date field value for display to users.
     */
    public function publicationDateForDisplay()
    {
        return $this->formatTimestampForDisplay($this->publicationDate());
    }

    /**
     * Get the categories field value for displaying to users.
     * @return array Returns the categories field value for display to users.
     */
    public function categoriesForDisplay()
    {
        $Categories = [];

        foreach ($this->categories() as $Id => $Category) {
            $Categories[$Id] = $Category->name();
        }

        return $Categories;
    }

    /**
     * Get the first image field value for displaying to users.
     * @return string Returns the image field value for display to users.
     */
    public function imageForDisplay()
    {
        return $this->image()->url("mv-image-preview");
    }

    /**
     * Get the image field value for displaying to users.
     * @return array Returns the image field value for display to users.
     */
    public function imagesForDisplay()
    {
        $Images = [];

        foreach ($this->images() as $Image) {
            $Images[] = $Image->url("mv-image-preview");
        }

        return $Images;
    }

    /**
     * Get the image field value as a thumbnail for displaying to users.
     * @return string Returns the image field value as a thumbnail for display to users.
     */
    public function thumbnailForDisplay()
    {
        return $this->image()->url("mv-image-thumbnail");
    }

    /**
     * Get the image field alt value for displaying to users.
     * @return string Returns the image field alt value for display to users.
     */
    public function imageAltForDisplay()
    {
        return $this->image()->altText();
    }

    /**
     * Get the creation date field value for machine parsing.
     * @return string Returns the creation date field value for machine parsing.
     */
    public function creationDateForParsing()
    {
        return $this->formatTimestampForParsing($this->creationDate());
    }

    /**
     * Get the modification date field value for machine parsing.
     * @return string Returns the modification date field value for machine parsing.
     */
    public function modificationDateForParsing()
    {
        return $this->formatTimestampForParsing($this->modificationDate());
    }

    /**
     * Get the publication date field value for machine parsing.
     * @return string Returns the publication date field value for machine parsing.
     */
    public function publicationDateForParsing()
    {
        return $this->formatTimestampForParsing($this->publicationDate());
    }

    /**
     * Get the title field value for inserting into a URL.
     * @return string Returns the title field value for inserting into a URL.
     */
    public function titleForUrl()
    {
        $SafeTitle = strip_tags($this->title());
        $SafeTitle = str_replace(" ", "-", $SafeTitle);
        $SafeTitle = preg_replace('/[^a-zA-Z0-9-]/', "", $SafeTitle);
        $SafeTitle = strtolower(trim($SafeTitle));

        return $SafeTitle;
    }

    /**
     * Get the date prefix for the creation date field value for displaying to
     * users.
     * @return string Returns the date prefix for the creation date field value for
     *      displaying to users.
     */
    public function creationDateDisplayPrefix()
    {
        return $this->getTimestampPrefix($this->creationDate());
    }

    /**
     * Get the date prefix for the modification date field value for displaying
     * to users.
     * @return string Returns the date prefix for the modification date field value for
     *      displaying to users.
     */
    public function modificationDateDisplayPrefix()
    {
        return $this->getTimestampPrefix($this->modificationDate());
    }

    /**
     * Get the date prefix for the publication date field value for displaying to
     * users.
     * @return string Returns the date prefix for the publication date field value for
     *      displaying to users.
     */
    public function publicationDateDisplayPrefix()
    {
        return $this->getTimestampPrefix($this->publicationDate());
    }

    /**
     * Get BlogId
     * @return int the BlogId associated with this entry
     */
    public function getBlogId() : int
    {
        return current(array_keys($this->get(Blog::BLOG_NAME_FIELD_NAME)));
    }

    /**
     * Get a value from a Blog Entry. Expands image keywords in the Paragraph fields to
     *   URLs.
     * @param int|string|MetadataField $Field Field ID or full name of field
     *      or MetadataField object.
     * @param bool $ReturnObject For field types that can return multiple values, if
     *      TRUE, returns array of objects, else returns array of values.
     *      Defaults to FALSE.
     * @param bool $IncludeVariants If TRUE, includes variants in return value.
     *      Only applicable for ControlledName fields.
     * @return mixed Requested object(s) or value(s).  Returns empty array
     *      (for field types that allow multiple values) or NULL (for field
     *      types that do not allow multiple values) if no values found.  Returns
     *      NULL if field does not exist or was otherwise invalid.
     * @see Record::get()
     */
    public function get($Field, bool $ReturnObject = false, bool $IncludeVariants = false)
    {
        $Field = $this->normalizeFieldArgument($Field);
        $Value = parent::get($Field, $ReturnObject, $IncludeVariants);

        if ($Field->type() == MetadataSchema::MDFTYPE_PARAGRAPH && !is_null($Value)) {
            $Value = (new ImageFactory())->convertKeywordsToUrls((string)$Value);
        }

        if ($Field->name() == Blog::BODY_FIELD_NAME) {
            $AF = ApplicationFramework::getInstance();

            # filter the explicit 'end of teaser' marker when displaying a
            # blog entry (i.e. when we're on P_Blog_Entry and running in the
            # foreground) or when called by Mailer to generate email content
            $FilterMarker = false;
            if ($AF->getPageName() == "P_Blog_Entry" &&
                !$AF->isRunningInBackground()) {
                $FilterMarker = true;
            } else {
                $FilterMarker = StdLib::checkMyCaller(
                    'Metavus\Plugins\Mailer'
                );
            }

            # filter images and their subtitles when called by Mailer to
            # generate email content
            $FilterImages = StdLib::checkMyCaller(
                'Metavus\Plugins\Mailer'
            );

            # perform marker filtering
            if ($FilterMarker) {
                $Value = $this->filterOutEndOfTeaserMarker($Value);
            }

            # perform image filtering
            if ($FilterImages) {
                $Value = $this->filterOutImages($Value);
            }
        }

        return $Value;
    }

    /**
     * Set value using field name, ID, or field object. This method converts
     * image Urls to keywords before calling Record::set().
     * @param int|string|MetadataField $Field Field ID or full name of field
     *      or MetadataField object.
     * @param mixed $NewValue New value for field.
     * @param bool $Reset When TRUE Controlled Names, Classifications,
     *       and Options will be set to contain *ONLY* the contents of
     *       NewValue, rather than appending $NewValue to the current value.
     * @throws \Exception When attempting to set a value for a field that is
     *       part of a different schema than the resource.
     * @throws \InvalidArgumentException When attempting to set a controlled
     *       name with an invalid ID.
     * @see Record::set().
     */
    public function set($Field, $NewValue, bool $Reset = false)
    {
        $Field = $this->normalizeFieldArgument($Field);

        # return if we don't have a valid field
        if (!($Field instanceof MetadataField)) {
            return;
        }

        # handle image keyword replacement
        if ($Field->type() == MetadataSchema::MDFTYPE_PARAGRAPH) {
            $NewValue = ImageFactory::convertUrlsToKeywords($NewValue);
        }

        parent::set($Field, $NewValue, $Reset);
    }

    /**
     * Format a user's name for display, using the real name if available and the
     * user name otherwise.
     * @param Metavus\User|array $Users Users to format names for (from $this->Get()).
     * @return string Returns the user's name for display.
     */
    protected function formatUserNameForDisplay($Users)
    {
        # blog schema does not allow multiple users, so just grab
        #  the first (and only) entry in the array
        $User = array_shift($Users);

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
     * Format a timestamp for displaying to users.
     * @param string $Timestamp Timestamp to format.
     * @return string Returns a formatted timestamp.
     */
    protected function formatTimestampForDisplay($Timestamp)
    {
        return StdLib::getPrettyTimestamp($Timestamp, true);
    }

    /**
     * Format a timestamp for machine parsing.
     * @param string $Timestamp Timestamp to format.
     * @return string Returns a formatted timestamp.
     */
    protected function formatTimestampForParsing($Timestamp)
    {
        $Timestamp = strtotime($Timestamp);

        # invalid timestamp
        if ($Timestamp === false) {
            return "-";
        }

        return date("c", $Timestamp);
    }

    /**
     * Get the date prefix for a timestamp for displaying to users, e.g., "on",
     * "at", etc.
     * @param string $Timestamp Timestamp for which to get a date prefix
     * @return string Returns the date prefix for a timestamp.
     */
    protected function getTimestampPrefix($Timestamp)
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
        if (date("n/j/Y", $Timestamp) == date("n/j/Y", strtotime("-1 day"))) {
            return "";
        }

        # before yesterday
        return "on";
    }

    /**
     * Filter the 'End of Teaser' marker from provided HTML.
     * @string $Html HTML to filter.
     * @return string HTML with the marker removed.
     */
    private function filterOutEndOfTeaserMarker(string $Html): string
    {
        return str_replace(self::EXPLICIT_MARKER, "", $Html);
    }

    /**
     * Filter images from provided HTML.
     * @string $Html HTML to filter.
     * @return string HTML with images removed.
     */
    private function filterOutImages(string $Html): string
    {
        # delete the captions shown below images
        $Html = preg_replace(
            '%<div [^>]*class="mv-form-image-caption"[^>]*>[^<]*</div>%',
            "",
            $Html
        );

        # delete images and their containing divs
        $Html = preg_replace(
            '%<div class="mv-form-image-(right|left)"[^>]*>'
                .'\s*<img [^>]+>\s*'
                .'</div>%',
            "",
            $Html
        );

        return $Html;
    }

    /**
     * Cached blog entry comments as Message objects.
     */
    protected $Comments;
}
