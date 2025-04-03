<?PHP
#
#   FILE:  SocialMedia.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2002-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins;
use InvalidArgumentException;
use Metavus\Image;
use Metavus\InterfaceConfiguration;
use Metavus\MetadataField;
use Metavus\MetadataSchema;
use Metavus\Plugins\BotDetector;
use Metavus\Plugins\MetricsRecorder;
use Metavus\Record;
use Metavus\User;
use ScoutLib\ApplicationFramework;
use ScoutLib\Plugin;
use ScoutLib\PluginManager;
use ScoutLib\StdLib;

/**
 * Plugin that offers additional integration with social media sites, like
 * Facebook and Twitter.Adds HTML markup to a resource's view page so that
 * social media websites can extract relevant metadata more easily when somebody
 * shares a resource.
 */
class SocialMedia extends Plugin
{
    /**
     * Base URL used for sharing a URL to Facebook.
     */
    protected const BASE_FACEBOOK_SHARE_URL = "http://www.facebook.com/sharer.php";

    /**
     * Base URL used for sharing a URL to Twitter.
     */
    protected const BASE_TWITTER_SHARE_URL = "https://twitter.com/intent/tweet";

    /**
     * Base URL used for sharing a URL to LinkedIn.
     */
    protected const BASE_LINKEDIN_SHARE_URL = "http://www.linkedin.com/shareArticle";

    /**
     * Value used to signify that e-mail should be used.
     */
    public const SITE_EMAIL = "em";

    /**
     * Value used to signify that Facebook should be used.
     */
    public const SITE_FACEBOOK = "fb";

    /**
     * Value used to signify that Twitter should be used.
     */
    public const SITE_TWITTER = "tw";

    /**
     * Value used to signify that LinkedIn should be used.
     */
    public const SITE_LINKEDIN = "li";

    /**
     * Register information about this plugin.
     * @return void
     */
    public function register(): void
    {
        $this->Name = "Social Media";
        $this->Version = "1.1.5";
        $this->Description = "Plugin that offers additional integration with
            social media websites, like Facebook and Twitter, adding HTML markup
            to a resource's view page so that relevant metadata can be extracted
            more easily when somebody shares it.";
        $this->Author = "Internet Scout Research Group";
        $this->Url = "http://metavus.net";
        $this->Email = "support@metavus.net";
        $this->Requires = [
            "MetavusCore" => "1.2.0",
            "MetricsRecorder" => "1.2.4"
        ];
        $this->EnabledByDefault = true;

        $this->Instructions =
            "<p><strong>Note</strong>: The user interface in use must signal the "
            ."<code>EVENT_IN_HTML_HEADER</code> event in its template file for "
            ."this plugin to add additional metadata to a resource's view page. "
            ."The user interfaces that come with Metavus do so.</p> "
            ."<p>You must <a href=\"https://dev.twitter.com/docs/cards\">request "
            ."approval</a> from Twitter for your site&#39;s information to display "
            ."in tweets.</p>";
    }

    /*
     * Set up plugin configuration options.
     * @return null|string if configuration setup succeeded, otherwise a string with
     *       an error message indicating why config setup failed.
     */
    public function setUpConfigOptions(): ?string
    {
        $this->CfgSetup["GeneralSection"] = [
            "Type" => "Heading",
            "Label" => "General"
        ];

        $this->CfgSetup["SiteName"] = [
            "Type" => "Text",
            "Label" => "Site Name",
            "Default" => InterfaceConfiguration::getInstance()->getString("PortalName"),
            "Help" => "The name of this website. Used in the additional metadata "
                ." added to a resource's view page."
        ];

        $this->CfgSetup["MaxDescriptionLength"] = [
            "Type" => "Number",
            "Label" => "Maximum Description Length",
            "Default" => 1200,
            "Help" => "The maximum length of the description in number of
                characters. Used in the additional metadata added to a
                resource's view page.",
            "Size" => 6
        ];

        $this->CfgSetup["AvailableShareButtons"] = [
            "Type" => "Option",
            "Label" => "Available Share Buttons",
            "Help" => "Social media channels for which a 'Share' button will be displayed.",
            "AllowMultiple" => true,
            "Rows" => 4,
            "Options" => SocialMedia::$SiteNameHumanEnums,
            "Default" => array_keys(SocialMedia::$SiteNameHumanEnums)
        ];

        $this->CfgSetup["TwitterSection"] = [
            "Type" => "Heading",
            "Label" => "Twitter"
        ];

        $this->CfgSetup["TwitterUsername"] = [
            "Type" => "Text",
            "Label" => "Twitter User Name",
            "Help" => "The Twitter user name associated with this website, e.g.,
                <i>@example</i>.Used in the additional metadata added to a
                resource's view page."
        ];

        # add options for each schema
        foreach (MetadataSchema::getAllSchemaNames() as $Id => $Name) {
            # skip the user schema
            if ($Id == MetadataSchema::SCHEMAID_USER) {
                continue;
            }

            $this->CfgSetup["Schema/".$Id] = [
                "Type" => "Heading",
                "Label" => $Name." Resources"
            ];

            $this->CfgSetup["Enabled/".$Id] = [
                "Type" => "Flag",
                "Label" => "Enabled",
                "Default" => false,
                "Help" => "Whether or not to add social media metadata for
                    resources using this metadata schema.",
                "OnLabel" => "Yes",
                "OffLabel" => "No"
            ];

            $this->CfgSetup["TitleField/".$Id] = [
                "Type" => "MetadataField",
                "Label" => "Resource Title Field",
                "Help" => "The field to use as the title in the additional metadata
                    added to a resource's view page.",
                "FieldTypes" => MetadataSchema::MDFTYPE_TEXT,
                "SchemaId" => $Id
            ];

            $this->CfgSetup["DescriptionField/".$Id] = [
                "Type" => "MetadataField",
                "Label" => "Resource Description Field",
                "Help" => "The field to use as the description in the additional
                    metadata added to a resource's view page.",
                "FieldTypes" => MetadataSchema::MDFTYPE_PARAGRAPH,
                "SchemaId" => $Id
            ];

            $this->CfgSetup["ScreenshotField/".$Id] = [
                "Type" => "MetadataField",
                "Label" => "Resource Screenshot/Poster Field",
                "Help" => "The field to use as the screenshot/poster image in
                    the additional metadata added to a resource's view page.",
                "FieldTypes" => MetadataSchema::MDFTYPE_IMAGE,
                "SchemaId" => $Id
            ];
        }

        return null;
    }

    /**
     * Startup initialization for plugin.
     * @return string|null NULL if initialization was successful, otherwise a string
     *       containing an error message indicating why initialization failed.
     */
    public function initialize(): ?string
    {
        $AF = ApplicationFramework::getInstance();

        # sharing URL format friendly to robots.txt

        # clean URL for the share redirect with the user ID
        $AF->addCleanUrl(
            "%^sh/([0-9]+)/(fb|tw|li)/([0-9]+)$%",
            "P_SocialMedia_ShareResource",
            [
                "ResourceId" => "$1",
                "Site" => "$2",
                "UserId" => "$3"
            ],
            "sh/\$ResourceId/\$Site"
        );

        # clean URL for the share redirect without the user ID.this is less
        # specific so it should come after the one above
        $AF->addCleanUrl(
            "%^sh/([0-9]+)/(fb|tw|li)$%",
            "P_SocialMedia_ShareResource",
            [
                "ResourceId" => "$1",
                "Site" => "$2"
            ],
            "sh/\$ResourceId/\$Site"
        );

        # backward compatibility with older sharing URL format

        # clean URL for the share redirect with the user ID
        $AF->addCleanUrl(
            "%^sh([0-9]+)/(fb|tw|li)/([0-9]+)$%",
            "P_SocialMedia_ShareResource",
            [
                "ResourceId" => "$1",
                "Site" => "$2",
                "UserId" => "$3"
            ],
            "sh\$ResourceId/\$Site"
        );

        # clean URL for the share redirect without the user ID.this is less
        # specific so it should come after the one above
        $AF->addCleanUrl(
            "%^sh([0-9]+)/(fb|tw|li)$%",
            "P_SocialMedia_ShareResource",
            [
                "ResourceId" => "$1",
                "Site" => "$2"
            ],
            "sh\$ResourceId/\$Site"
        );

        # register our events with metrics recorder
        MetricsRecorder::getInstance()->registerEventType(
            "SocialMedia",
            "ShareResource"
        );

        # report success
        return null;
    }

    /**
     * Upgrade from a previous version.
     * @param string $PreviousVersion Previous version of the plugin.
     * @return string|null Returns NULL on success and an error message otherwise.
     */
    public function upgrade(string $PreviousVersion): ?string
    {
        # upgrade from versions < 1.1.0 to 1.1.0
        if (version_compare($PreviousVersion, "1.1.0", "<")) {
            $SchemaId = MetadataSchema::SCHEMAID_DEFAULT;

            # the default schema was always enabled in prior versions
            $this->setConfigSetting("Enabled/".$SchemaId, true);

            # migrate old field settings
            $this->setConfigSetting(
                "TitleField/".$SchemaId,
                $this->getConfigSetting("TitleField")
            );
            $this->setConfigSetting(
                "DescriptionField/".$SchemaId,
                $this->getConfigSetting("DescriptionField")
            );
            $this->setConfigSetting(
                "ScreenshotField/".$SchemaId,
                $this->getConfigSetting("ScreenshotField")
            );
        }

        # upgrade from versions < 1.1.1 to 1.1.1
        if (version_compare($PreviousVersion, "1.1.1", "<")) {
            # set the maximum description length to 1200 by default
            $this->setConfigSetting("MaxDescriptionLength", 1200);
        }

        return null;
    }

    /**
     * Declare the events this plugin provides to the application framework.
     * @return array An array of the events this plugin provides.
     */
    public function declareEvents(): array
    {
        return ["SocialMedia_MODIFY_IMAGES" => ApplicationFramework::EVENTTYPE_CHAIN];
    }

    /**
     * Hook event callbacks into the application framework.
     * @return array Returns an array of events to be hooked into the application
     *      framework.
     */
    public function hookEvents(): array
    {
        return ["EVENT_IN_HTML_HEADER" => "PrintMetaTags"];
    }

    /**
     * Print the meta tags in the header. Additional information can be found at
     * the following URLs:
     * @li http://developers.facebook.com/docs/opengraph/property-types/
     * @li http://developers.facebook.com/docs/opengraph/creating-object-types/
     * @li https://dev.twitter.com/docs/cards
     * @return void
     */
    public function printMetaTags(): void
    {
        # variables used to determine if the current page is a view page
        $Path = ApplicationFramework::getInstance()->getUncleanRelativeUrlWithParams();
        $ResourceId = null;

        # check if on a view page for one of the schemas
        foreach (MetadataSchema::getAllSchemas() as $Schema) {
            # skip the user metadata schema
            if ($Schema->id() == MetadataSchema::SCHEMAID_USER) {
                continue;
            }

            # if on the view page for the schema
            if ($Schema->PathMatchesViewPage($Path)) {
                $IdParameter = $Schema->getViewPageIdParameter();
                $ResourceId = StdLib::getFormValue($IdParameter);
                break;
            }
        }

        # if not on a view page (i.e.resource ID is not valid)
        if (($ResourceId === null) || !Record::itemExists($ResourceId)) {
            return;
        }

        $Resource = new Record($ResourceId);
        $Schema = $Resource->getSchema();

        # only add metadata for enabled schemas
        if (!$this->isEnabledForSchema($Schema)) {
            return;
        }

        # extract the metadata from the resource
        $SiteName = $this->getConfigSetting("SiteName");
        $Url = $this->getViewPageUrl($Resource);
        $Title = $this->getSimpleFieldValue($Resource, "TitleField") ?? "";
        $Description = $this->getSimpleFieldValue($Resource, "DescriptionField") ?? "";
        $Images = $this->getImagesForResource($Resource);
        $TwitterUsername = $this->formatTwitterUsername(
            $this->getConfigSetting("TwitterUsername")
        );

        # limit the description length
        $MaxDescriptionLength = $this->getConfigSetting("MaxDescriptionLength");
        $Description = StdLib::neatlyTruncateString($Description, $MaxDescriptionLength);

        # add marker indicating the beginning of our additions
        print "\n<!-- Social Media (start) -->\n";

        # print meta tags for Open Graph (Facebook, but other sites also use it)
        $this->printOpenGraphTag("og:site_name", $SiteName);
        $this->printOpenGraphTag("og:url", $Url);
        $this->printOpenGraphTag("og:title", $Title);
        $this->printOpenGraphTag("og:description", $Description);
        $this->printOpenGraphImages($Images);

        # Twitter needs shorter text
        $MaxTwitterDescLength = min(200, $MaxDescriptionLength);
        $TwitterTitle = StdLib::neatlyTruncateString($Title, 70);
        $TwitterDescription = StdLib::neatlyTruncateString(
            $Description,
            $MaxTwitterDescLength
        );

        # print meta tags for Twitter
        $this->printTwitterTag("twitter:card", "summary");
        $this->printTwitterTag("twitter:site:id", $TwitterUsername);
        $this->printTwitterTag("twitter:title", $TwitterTitle);
        $this->printTwitterTag("twitter:description", $TwitterDescription);
        $this->printTwitterImages($Images);

        # add marker indicating the beginning of our additions
        print "<!-- Social Media (end) -->\n";
    }

    /**
     * Share a resource to a social media website.This will cause a redirect.
     * @param Record $Resource Resource to construct a sharing URL for.
     * @param string $Site Website to share to.
     * @param int $UserId Optional user ID to associate with the share action.
     * @return void
     * @see SITE_EMAIL
     * @see SITE_FACEBOOK
     * @see SITE_TWITTER
     * @see SITE_LINKEDIN
     */
    public function shareResource($Resource, $Site, $UserId): void
    {
        $AF = ApplicationFramework::getInstance();
        $PluginMgr = PluginManager::getInstance();

        # go to the home page if the user can't view this resource
        if (!$Resource->userCanView(User::getCurrentUser())) {
            $AF->setJumpToPage("Home");
            return;
        }

        # if BotDetector is available, use it to bounce robots back to the home page
        if ($PluginMgr->pluginEnabled("BotDetector") &&
            BotDetector::getInstance()->checkForSpamBot()) {
            $AF->setJumpToPage("Home");
            return;
        }

        # e-mail sharing should be handled separately
        if ($Site == self::SITE_EMAIL) {
            # record an event
            MetricsRecorder::getInstance()->recordEvent(
                "SocialMedia",
                "ShareResource",
                $Resource->id(),
                $Site,
                $UserId
            );

            # suppress HTML output because an AJAX request would have been used
            $AF->suppressHTMLOutput();

            return;
        }

        $SharingUrl = $this->getSharingUrl($Resource, $Site);

        # redirect to the sharing URL if it could be retrieved
        if (!is_null($SharingUrl)) {
            # record an event
            MetricsRecorder::getInstance()->recordEvent(
                "SocialMedia",
                "ShareResource",
                $Resource->id(),
                $Site,
                $UserId
            );

            $AF->setJumpToPage($SharingUrl);
        # go to the home page otherwise
        } else {
            $AF->setJumpToPage("Home");
        }
    }

    /**
     * Get the share URL for the given resource to the given site, where site is
     * one of "Facebook", "Twitter", "LinkedIn", or "Email".
     * @param Record $Resource The resource for which to get the share URL.
     * @param string $Site The site for which to get the share URL.This can
     *      either be a social media name (e.g."Facebook") or one of the
     *      social media class constants (e.g.SocialMedia::SITE_FACEBOOK).
     * @return string Returns the share URL for the resource and site.
     */
    public function getShareUrl(Record $Resource, $Site): string
    {
        # map share sites to the URL tokens
        $SiteTokens = [
            "EMAIL" => SocialMedia::SITE_EMAIL,
            "FACEBOOK" => SocialMedia::SITE_FACEBOOK,
            "TWITTER" => SocialMedia::SITE_TWITTER,
            "LINKEDIN" => SocialMedia::SITE_LINKEDIN
        ];

        # get the base URL for sharing links
        $BaseShareUrl = "index.php?P=P_SocialMedia_ShareResource";
        $BaseShareUrl .= "&ResourceId=".urlencode((string)$Resource->id());

        # get the share URL for the resource
        $ShareToken = StdLib::getArrayValue($SiteTokens, strtoupper($Site), $Site);
        $ShareUrl = $BaseShareUrl."&Site=".$ShareToken;

        # try to make the URL clean
        $AF = ApplicationFramework::getInstance();
        $ShareUrl = $AF->getCleanRelativeUrlForPath($ShareUrl);

        # make the URL absolute
        $ShareUrl = ApplicationFramework::baseUrl().$ShareUrl;

        return $ShareUrl;
    }

    /**
     * Print regular size social media buttons to share a given resource.
     * @param Record $Resource The resource to share.
     * @return void
     */
    public function displayShareButtons(Record $Resource): void
    {
        $this->displayShareButtonsWithCustomSize($Resource, SocialMedia::$LargeIconSize);
    }


    /**
     * Print small social media buttons for a given resource
     * @param Record $Resource The resource to share.
     * @return void
     */
    public function displaySmallShareButtons(Record $Resource): void
    {
        $this->displayShareButtonsWithCustomSize($Resource, SocialMedia::$SmallIconSize);
    }


    /**
     * Determine if meta tag printing is enabled for a metadata schema.
     * @param MetadataSchema $Schema Schema to test with.
     * @return bool Returns TRUE if meta tag printing is enabled for the schema.
     */
    protected function isEnabledForSchema(MetadataSchema $Schema): bool
    {
        return $this->getConfigSetting("Enabled/".$Schema->id()) ?? false;
    }

    /**
     * Get the URL to the resource's view page.This will use the clean URL if
     *.htaccess support is available.
     * @param Record $Resource Resource for which to get the view page URL.
     * @return string Returns the view page URL for the resource.
     */
    protected function getViewPageUrl(Record $Resource): string
    {
        $AF = ApplicationFramework::getInstance();
        $Schema = new MetadataSchema($Resource->getSchemaId());
        $SafeResourceId = urlencode((string)$Resource->id());

        # get the view page
        $ViewPageUrl = $Schema->getViewPage();

        # replace the ID parameter with the actual resource ID
        $ViewPageUrl = preg_replace("%\\\$ID%", $SafeResourceId, $ViewPageUrl);

        # make the URL clean, if possible
        if ($AF->cleanUrlSupportAvailable()) {
            $ViewPageUrl = $AF->getCleanRelativeUrlForPath($ViewPageUrl);
        }

        # tack on the rest of the URL to make it absolute
        $ViewPageUrl = $AF->baseUrl().$ViewPageUrl;

        # and, finally, return the URL
        return $ViewPageUrl;
    }

    /**
     * Get the value of a field that has one value that is text.
     * @param Record $Resource Resource from which to get the image.
     * @param string $Setting Plugin setting from which to get the field.
     * @return string|null Returns the field value or NULL if there isn't one.
     * @see GetImageFieldValue()
     */
    protected function getSimpleFieldValue(Record $Resource, string $Setting): ?string
    {
        # load the resource's metadata schema
        $Schema = new MetadataSchema($Resource->getSchemaId());

        # get the field for this setting, if one exists
        $Field = $this->getFieldForSetting($Schema, $Setting);
        if (is_null($Field)) {
            return null;
        }

        # get the value, possibly having it filtered by plugins
        return trim(strip_tags($Resource->getForDisplay($Field)));
    }

    /**
     * Get the URL, width, height, and MIME type of an image field.
     * @param Record $Resource Resource from which to get the image.
     * @param string $Setting Plugin setting from which to get the field.
     * @return array Returns an array with the following structure:
     *      [screenshot URL, width, height, MIME type]
     * @see GetSimpleFieldValue()
     */
    protected function getImageFieldValue(Record $Resource, string $Setting): array
    {
        # screenshot URL
        $Values = [ null ];

        # get configured field
        $Field = $this->getFieldForSetting($Resource->getSchema(), $Setting);

        # if none available, no images to display
        if (is_null($Field)) {
            return $Values;
        }

        # get the images in our field
        $Images = $Resource->getForDisplay($Field);

        # return empty values if there is no image or image is invalid
        if (!count($Images)) {
            return $Values;
        }
        $Image = array_shift($Images);
        if (!($Image instanceof Image)) {
            return $Values;
        }

        # make sure image URL is absolute
        $ImageUrl = $Image->url("mv-image-large");
        if ((stripos($ImageUrl, "http://") !== 0) && (stripos($ImageUrl, "https://") !== 0)) {
            $ImageUrl = ApplicationFramework::baseUrl().$ImageUrl;
        }

        # screenshot URL
        $Values[0] = $ImageUrl;

        return $Values;
    }

    /**
     * Get all images to associate with a resource in the additional metadata
     * added to a resource's view page.
     * @param Record $Resource Resource to get images for.
     * @return array Final image data, with a URL, width, height, and
     *      MIME type for each image.The order of the images is the order of
     *      importance, i.e., the first image is the most important, etc.
     */
    protected function getImagesForResource(Record $Resource): array
    {
        $Images = [];

        # first, get the initial image from the field, which might be nothing
        list($ScreenshotUrl) = $this->getImageFieldValue(
            $Resource,
            "ScreenshotField"
        );

        # add the initial image if it's set
        if (!is_null($ScreenshotUrl)) {
            $Images[] = ["Url" => $ScreenshotUrl];
        }

        # signal the event to modify the images
        $ReturnValue = ApplicationFramework::getInstance()->signalEvent(
            "SocialMedia_MODIFY_IMAGES",
            [
                "Resource" => $Resource,
                "Images" => $Images
            ]
        );

        # extract the final list of images from the return value
        $FinalImages = $ReturnValue["Images"];

        # validate the images
        foreach ($FinalImages as $Key => $Image) {
            # remove the image if it doesn't at least have a URL
            if (!isset($Image["Url"]) || strlen(trim($Image["Url"])) < 1) {
                unset($FinalImages[$Key]);
                continue;
            }

            # make sure all the necessary fields exist
            $FinalImages[$Key] = $FinalImages[$Key] + [
                "Url" => null,
                "Width" => null,
                "Height" => null,
                "Mimetype" => null
            ];
        }

        return $FinalImages;
    }

    /**
     * Get the metadata field associated with the given plugin setting.
     * @param MetadataSchema $Schema Metadata schema to use for fetching the
     *     field.
     * @param string $Setting Plugin setting from which to get the field.
     * @return MetadataField|null Returns the field for the setting or NULL
     *      if it isn't set.
     */
    protected function getFieldForSetting(MetadataSchema $Schema, $Setting): ?MetadataField
    {
        $FieldId = $this->getConfigSetting($Setting."/".$Schema->id());

        # return NULL if the field ID is not set
        if (is_null($FieldId) || !strlen($FieldId)) {
            return null;
        }

        try {
            $Field = $Schema->getField($FieldId);
        # return NULL if the field does not exist
        } catch (InvalidArgumentException $Exception) {
            return null;
        }

        # return NULL if the field isn't valid
        if ($Field->status() !== MetadataSchema::MDFSTAT_OK) {
            return null;
        }

        return $Field;
    }

    /**
     * Determine if a property for a meta tag is set, i.e., not blank.
     * @param string $Property Property value to check.
     * @return bool Returns TRUE if the property is set and FALSE otherwise.
     */
    protected function isPropertySet($Property): bool
    {
        return strlen(trim((string)$Property)) > 0;
    }

    /**
     * Format a Twitter user name for use in a Twitter meta tag.
     * @param string $Username Twitter user name to format.
     * @return string|null Returns the formatted Twitter user name or NULL
     *      if no name available.
     */
    protected function formatTwitterUsername(string $Username): ?string
    {
        # don't format a blank user name
        if (!$this->isPropertySet($Username)) {
            return null;
        }

        # add the @ to the user name, if necessary
        if ($Username[0] != "@") {
            $Username = "@".$Username;
        }

        return $Username;
    }

    /**
     * Print a meta tag tailored to the Open Graph specification.See:
     * @li http://developers.facebook.com/docs/opengraph/property-types/
     * @li http://developers.facebook.com/docs/opengraph/creating-object-types/
     * @param string $Property The value identifier.
     * @param string $Content The metadata content.
     * @return void
     * @see PrintTwitterTag()
     */
    protected function printOpenGraphTag($Property, $Content): void
    {
        # ignore blank properties
        if (!$this->isPropertySet($Content)) {
            return;
        }

        print $this->getMetaTag("property", $Property, "content", $Content)."\n";
    }

    /**
     * Print a meta tag tailored to Twitter's specifications.See:
     * https://dev.twitter.com/docs/cards
     * @param string $Name The value identifier.
     * @param string $Content The metadata content.
     * @return void
     * @see PrintOpenGraphTag()
     */
    protected function printTwitterTag($Name, $Content): void
    {
        # ignore blank properties
        if (!$this->isPropertySet($Content)) {
            return;
        }

        print $this->getMetaTag("name", $Name, "content", $Content)."\n";
    }

    /**
     * Print the Open Graph meta tags for the given image data.
     * @param array $Images Image data to put in Open Graph meta tags.
     * @return void
     */
    protected function printOpenGraphImages(array $Images): void
    {
        # print the tags for each image
        foreach ($Images as $Image) {
            $this->printOpenGraphTag("og:image", $Image["Url"]);
        }
    }

    /**
     * Print the Twitter meta tags for the given image data.
     * @param array $Images Image data to put in Twitter meta tags.
     * @return void
     */
    protected function printTwitterImages(array $Images): void
    {
        # print the tags for each image
        foreach ($Images as $Image) {
            $this->printOpenGraphTag("twitter:image", $Image["Url"]);

            # Twitter currently only supports one image in summary cards
            break;
        }
    }

    /**
     * Generate a meta tag given a parameter list of attribute names and values.
     * Odd parameters should be attribute names and even parameters should be
     * values.
     * @return string Returns the generated meta tag.
     */
    protected function getMetaTag(): string
    {
        $Arguments = func_get_args();
        $Tag = "<meta";

        # loop through each argument, ensuring that they come in pairs
        $N_Args = count($Arguments);
        for ($i = 0; $N_Args - $i > 1; $i += 2) {
            $Attribute = $Arguments[$i];
            $Value = $Arguments[$i + 1];
            $SafeValue = defaulthtmlentities(strip_tags(trim($Value)));

            # add the attribute name/value pair to the tag
            $Tag .= " ".$Attribute.'="'.$SafeValue.'"';
        }

        $Tag .= " />";

        return $Tag;
    }

    /**
     * Get the sharing URL for a resource and a social media website.
     * @param Record $Resource Resource to construct a sharing URL for.
     * @param string $Site Website to share to.
     * @return string|null Returns the sharing URL or NULL if there's an error.
     * @see SITE_FACEBOOK
     * @see SITE_TWITTER
     * @see SITE_LINKEDIN
     */
    public function getSharingUrl(Record $Resource, $Site): ?string
    {
        switch ($Site) {
            case self::SITE_FACEBOOK:
                return $this->getSharingUrlForFacebook($Resource);
            case self::SITE_TWITTER:
                return $this->getSharingUrlForTwitter($Resource);
            case self::SITE_LINKEDIN:
                return $this->getSharingUrlForLinkedIn($Resource);
            default:
                return null;
        }
    }

    /**
     * Construct a sharing URL for Facebook for a resource.
     * @param Record $Resource Resource to construct a sharing URL for.
     * @return string Returns a sharing URL for Facebook for the resource.
     */
    protected function getSharingUrlForFacebook(Record $Resource): string
    {
        # add the basic parameters and get the title, which may not be available
        $Parameters = ["u" => $this->getViewPageUrl($Resource)];
        $Title = $this->getSimpleFieldValue($Resource, "TitleField");

        # add the title, if available
        if ($this->isPropertySet($Title)) {
            $Parameters["t"] = $Title;
        }

        # encode them for a URL query
        $QueryParameters = http_build_query($Parameters);

        # construct the URL
        $Url = self::BASE_FACEBOOK_SHARE_URL."?".$QueryParameters;

        return $Url;
    }

    /**
     * Construct a sharing URL for Twitter for a resource.
     * @param Record $Resource Resource to construct a sharing URL for.
     * @return string|null Returns a sharing URL for Twitter for the resource
     *   or NULL if no twitter username was configured.
     */
    protected function getSharingUrlForTwitter(Record $Resource): ?string
    {
        # add the basic parameters and get the twitter user name, which may not
        # be available
        $RawTwitterUsername = $this->getConfigSetting("TwitterUsername");
        if (($RawTwitterUsername === null) || !strlen($RawTwitterUsername)) {
            return null;
        }
        $Parameters = ["url" => $this->getViewPageUrl($Resource)];
        $TwitterUsername = $this->formatTwitterUsername(
            $this->getConfigSetting("TwitterUsername")
        );

        # add the twitter user name, if available
        if ($this->isPropertySet($TwitterUsername)) {
            # don't include the @
            $Parameters["via"] = substr($TwitterUsername, 1);
        }

        # encode them for a URL query
        $QueryParameters = http_build_query($Parameters);

        # construct the URL
        $Url = self::BASE_TWITTER_SHARE_URL."?".$QueryParameters;

        return $Url;
    }

    /**
     * Construct a sharing URL for LinkedIn for a resource.
     * @param Record $Resource Resource to construct a sharing URL for.
     * @return string Returns a sharing URL for LinkedIn for the resource.
     */
    protected function getSharingUrlForLinkedIn(Record $Resource): string
    {
        # construct the query parameters.LinkedIn supports some other useful
        # parameters, but they sometimes cause issues.since LinkedIn also
        # supports the Open Graph protocol, which is embedded in a resource's
        # view page already, ignore the extra parameters here
        $QueryParameters = http_build_query([
            "mini" => "true",
            "url" => $this->getViewPageUrl($Resource)
        ]);

        # construct the URL
        $Url = self::BASE_LINKEDIN_SHARE_URL."?".$QueryParameters;

        return $Url;
    }

    /**
     * Print social media buttons with custom button size to
     * share a given resource.
     * @param Record $Resource The resource to share.
     * @param integer $Size Size of button icons.
     * @return void
     */
    protected function displayShareButtonsWithCustomSize(Record $Resource, int $Size): void
    {
        $AF = ApplicationFramework::getInstance();

        # get list of requested share buttons
        $ShareButtons = $this->getConfigSetting("AvailableShareButtons") ?? [];

        # if twitter was requested but no username configured, then we cannot
        # use it, so disable it
        $TwitterIndex = array_search(SocialMedia::SITE_TWITTER, $ShareButtons);
        if ($TwitterIndex !== false) {
            $TwitterUsername = $this->getConfigSetting("TwitterUsername");
            if (is_null($TwitterUsername) || strlen($TwitterUsername) == 0) {
                unset($ShareButtons[$TwitterIndex]);
            }
        }

        $TitleBase = "Share this blog entry via ";

        # maps social media constants to their icon url
        $SiteIconUrl = array_map(function ($Site) use ($Size) {
            return strtolower($Site)."_".$Size.".png";
        }, SocialMedia::$SiteNameHumanEnums);

        # generate email button's mailto if share via email is enabled
        $EmailMailTo = "";
        if (in_array(SocialMedia::SITE_EMAIL, $ShareButtons)) {
            $ResourceTitle = defaulthtmlentities(rawurlencode($Resource->get("Title")));
            $ResourceUrl = defaulthtmlentities(
                rawurlencode($this->getViewPageUrl($Resource))
            );
            $EmailBody = strlen($ResourceTitle) ?
                $ResourceTitle.":%0D%0A".$ResourceUrl : $ResourceUrl;
            $EmailMailTo = "mailto:?to=&amp;subject="
                . $ResourceTitle."&amp;body=".$EmailBody;
        }

        # add button HTML
        ?><ul class="mv-list mv-list-noindent mv-list-nobullets mv-list-horizontal list-inline
        social-media-share">
        <?PHP  foreach ($ShareButtons as $Site) {
            if ($Site === SocialMedia::SITE_EMAIL) { ?>
                <li class="list-inline-item"><a title="<?= $TitleBase
                .SocialMedia::$SiteNameHumanEnums[$Site] ?>"
                    onclick="setTimeout(function(){jQuery.get('<?=
                    $this->getShareUrl($Resource, $Site) ?>');},250);"
                    href="<?= $EmailMailTo ?>">
                    <img src="<?PHP $AF->pUIFile($SiteIconUrl[$Site]); ?>"
                        alt="<?= SocialMedia::$SiteNameHumanEnums[$Site] ?>" />
                </a></li>
            <?PHP } else { ?>
                <li class="list-inline-item"><a title="<?= $TitleBase
                .SocialMedia::$SiteNameHumanEnums[$Site] ?>"
                    href="<?= $this->getShareUrl($Resource, $Site) ?>">
                    <img src="<?PHP $AF->pUIFile($SiteIconUrl[$Site]); ?>"
                        alt="<?= SocialMedia::$SiteNameHumanEnums[$Site] ?>" />
                </a></li>
                <?PHP
            }
        }
        ?></ul><?PHP
    }

    /**
     * Maps social media constants to their human-readable names.
     * @var array $SiteNameHumanEnums
     */
    public static $SiteNameHumanEnums = [
        SocialMedia::SITE_EMAIL => "Email",
        SocialMedia::SITE_FACEBOOK => "Facebook",
        SocialMedia::SITE_TWITTER => "Twitter",
        SocialMedia::SITE_LINKEDIN => "LinkedIn",
    ];

    /**
     * Social media sharing buttons' icons' size.
     * @var integer $LargeIconSize
     * @var integer $SmallIconSize
     */
    private static $LargeIconSize = 24;
    private static $SmallIconSize = 16;
}
