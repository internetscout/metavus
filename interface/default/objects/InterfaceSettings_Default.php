<?PHP
#
#   FILE:  InterfaceSettings_Default.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2023-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus;
use ScoutLib\PluginManager;

/**
 * Interface setting definitions for Metavus (default) interface.
 */
class InterfaceSettings_Default extends InterfaceSettings
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Class constructor.
     */
    public function __construct()
    {
        $this->SettingDefinitions = [
            # -------------------------------------------------
            "HEADING-Site" => [
                "Type" => FormUI::FTYPE_HEADING,
                "Label" => "Site",
            ],
            "PortalName" => [
                "Type" => FormUI::FTYPE_TEXT,
                "Label" => "Portal Name",
                "MaxLength" => 30,
                "Default" => "Digital Collection Portal",
                "Help" => "The name of the site as displayed in the"
                        ." title bar of the browser window and the page"
                        ." header above the navigation bar.",
            ],
            "SiteKeywords" => [
                "Type" => FormUI::FTYPE_PARAGRAPH,
                "Label" => "Site Keywords",
                "Default" => "",
                "Help" => "Used by search engines to find your site."
                        ." Separate words and phrases by commas.",
            ],
            "AdminEmail" => [
                "Type" => FormUI::FTYPE_TEXT,
                "Label" => "Administrator Email",
                "Required" => true,
                "ValidateFunction" => ["Metavus\\FormUI", "validateEmail"],
                "Default" => "",
                "Help" => "The email address of the individual responsible"
                        ." for overall site management. Feedback and other"
                        ." administrative mail is directed to this address.",
            ],
            "MainNavItems" => [
                "Type" => FormUI::FTYPE_PARAGRAPH,
                "Label" => "Main Navigation",
                "Required" => true,
                "Width" => 60,
                "Default" => "Home | index.php?P=Home\n"
                        ."Browse | index.php?P=BrowseResources\n"
                        ."About | about",
                "ValidateFunction" => [
                    "Metavus\\InterfaceConfiguration",
                    "validateMenuItems"
                ],
                "Help" => "Entries for the main navigation menu.  Entries"
                        ." should be one per line, and contain the menu item"
                        ." text, the relative link, and (optionally) any"
                        ." privilege flags required to see the item.  Each"
                        ." element should be separated by a vertical line"
                        ." character (\"|\"), and privilege flags should be"
                        ." separated by commas.  For entries that do not have"
                        ." any other privilege requires other than that the"
                        ." users be logged in, use the <i>PRIV_ISLOGGEDIN</i>"
                        ." privilege. Example:<br/><i>Administration"
                        ." | index.php?P=SysAdmin "
                        ." | PRIV_SYSADMIN,PRIV_COLLECTIONADMIN</i>",
            ],
            "LogoImage" => [
                "Type" => FormUI::FTYPE_IMAGE,
                "Label" => "Logo Image",
                "Default" => [],
                "Help" => "Logo image to display in upper left corner of site."
                        ." (SVG image format recommended.)",
            ],
            # -------------------------------------------------
            "HEADING-Home" => [
                "Type" => FormUI::FTYPE_HEADING,
                "Label" => "Home",
            ],
            "FeaturedItemsSectionTitle" => [
                "Type" => FormUI::FTYPE_TEXT,
                "Label" => "Featured Items Title",
                "Default" => "Featured",
                "Help" => "The title for the <i>Featured</i> section.",
            ],
            "NumResourcesOnHomePage" => [
                "Type" => FormUI::FTYPE_NUMBER,
                "Label" => "Number of Featured Items",
                "MinVal" => 0,
                "Default" => 5,
                "Help" => "The maximum number of items that"
                        ." will be displayed in the <i>Featured</i>"
                        ." section on the home page.",
            ],
            "ShowNumResourcesEnabled" => [
                "Type" => FormUI::FTYPE_FLAG,
                "Label" => "Display Item Count",
                "Default" => true,
                "Help" => "Determines whether the total number"
                        ." of publicly-viewable items is"
                        ." displayed on the home page."
            ],
            "DisplayCollectionsOnHomePage" => [
                "Type" => FormUI::FTYPE_FLAG,
                "Label" => "Display Collections",
                "Default" => true,
                "Help" => "Whether the <i>Collections</i> section on"
                        ." the home page is displayed."
            ],
            "CollectionsSectionTitle" => [
                "Type" => FormUI::FTYPE_TEXT,
                "Label" => "Collections Title",
                "Default" => "Collections",
                "Help" => "The title for the <i>Collections</i> section.",
            ],
            "NumCollectionsOnHomePage" => [
                "Type" => FormUI::FTYPE_NUMBER,
                "Label" => "Number of Collections",
                "MinVal" => 1,
                "MaxVal" => 30,
                "Default" => 5,
                "Help" => "The maximum number of collections to"
                        ." display in the <i>Collections</i>"
                        ." section on the home page."
            ],
            "HideCollectionMonograms" => [
                "Type" => FormUI::FTYPE_FLAG,
                "Label" => "Hide Collection Monograms",
                "Default" => false,
                "Help" => "Whether to hide (i.e. not display) the collection"
                        ." monograms in the <i>Collections</i> section on"
                        ." the home page."
            ],
            "AnnouncementsEnabled" => [
                "Type" => FormUI::FTYPE_FLAG,
                "Label" => "Display News",
                "Default" => true,
                "ReadOnlyFunction" => [ $this, "isBlogPluginNotReady" ],
                "Help" => "Whether the <i>News</i> section"
                        ." is displayed on the home page."
                        ." (Requires Blog plugin be enabled.)",
            ],
            "NumAnnounceOnHomePage" => [
                "Type" => FormUI::FTYPE_NUMBER,
                "Label" => "Number of News Items",
                "MinVal" => 1,
                "Default" => 3,
                "Help" => "The maximum number of news items"
                        ." that will be displayed on the home page."
                        ." (Requires that Blog plugin be enabled.)",
            ],
            "NewsBlogId" => [
                "Type" => FormUI::FTYPE_OPTION,
                "StorageType" => InterfaceConfiguration::TYPE_INT,
                "Label" => "News Blog",
                "OptionsFunction" => [ $this, "getBlogList" ],
                "DefaultFunction" => [ $this, "getIdOfDefaultBlog" ],
                "ReadOnlyFunction" => [ $this, "isBlogPluginNotReady" ],
                "Help" => "Blog to display on News section of home page."
                        ." (Requires Blog plugin be enabled.)",
            ],
            "EventsEnabled" => [
                "Type" => FormUI::FTYPE_FLAG,
                "Label" => "Display Events",
                "Default" => true,
                "ReadOnlyFunction" => [ $this, "isCalendarEventsPluginNotReady" ],
                "Help" => "Whether the <i>Events</i> section on the home page"
                        ." is displayed."
                        ." (Requires that CalendarEvents plugin be enabled.)",
            ],
            "NumEventsOnHomePage" => [
                "Type" => FormUI::FTYPE_NUMBER,
                "Label" => "Number of Events",
                "MinVal" => 0,
                "Default" => 10,
                "Help" => "The maximum number of events to"
                        ." display in the <i>Events</i>"
                        ." section on the home page."
                        ." (Requires that CalendarEvents plugin be enabled.)",
            ],
            "HeroImage" => [
                "Type" => FormUI::FTYPE_IMAGE,
                "Label" => "Hero Image",
                "Default" => [],
                "Help" => "Hero image to display when no image collage"
                        ." is available."
            ],
            # -------------------------------------------------
            "HEADING-Collections" => [
                "Type" => FormUI::FTYPE_HEADING,
                "Label" => "Collections",
            ],
            "NumCollectionItems" => [
                "Type" => FormUI::FTYPE_NUMBER,
                "Label" => "Collection Items to Display",
                "Default" => 12,
                "Help" => "The maximum number of items to display at a time"
                        ." on the Display Collection page.",
            ],
            # -------------------------------------------------
            "HEADING-Browsing" => [
                "Type" => FormUI::FTYPE_HEADING,
                "Label" => "Browsing",
            ],
            "BrowsingPageTitle" => [
                "Type" => FormUI::FTYPE_TEXT,
                "Label" => "Browsing Page Title",
                "Default" => "Browse Resources",
                "Required" => true,
                "Help" => "Title and heading for resource browsing page"
                    ." (BrowseResources), when not in classification editing mode.",
            ],
            "NumClassesPerBrowsePage" => [
                "Type" => FormUI::FTYPE_NUMBER,
                "Label" => "Classifications Per Page",
                "MinVal" => 2,
                "Default" => 50,
                "Help" => "The default number of classifications"
                        ." to display on the Browse Resources page"
                        ." before they are split up. System"
                        ." administrators should consider the size"
                        ." of the collection as well as the current"
                        ." state of browser technology as longer"
                        ." numbers of resource entries per page"
                        ." may require lengthy browser load times."
            ],
            "NumColumnsPerBrowsePage" => [
                "Type" => FormUI::FTYPE_NUMBER,
                "Label" => "Columns Per Page",
                "MinVal" => 1,
                "MaxVal" => 4,
                "RecVal" => 2,
                "Default" => 2,
                "Help" => "The number of columns in which to"
                        ." display the classifications on the"
                        ." Browse Resources page. (Minimum:"
                        ." <i>1</i>, Maximum: <i>4</i>,"
                        ." Recommended: <i>2</i>)"
            ],
            "BrowsingFieldId" => [
                "Type" => FormUI::FTYPE_METADATAFIELD,
                "Label" => "Default Browsing Field",
                "FieldTypes" => MetadataSchema::MDFTYPE_TREE,
                "SchemaId" => MetadataSchema::SCHEMAID_DEFAULT,
                "DefaultFunction" => function () {
                    $Schema = new MetadataSchema(
                        MetadataSchema::SCHEMAID_DEFAULT
                    );
                    $TreeFields = $Schema->getFields(
                        MetadataSchema::MDFTYPE_TREE
                    );
                    if (count($TreeFields)) {
                        return key($TreeFields);
                    }
                    return null;
                },
                "ReadOnlyFunction" => function (string $FieldName): bool {
                    $Schema = new MetadataSchema(MetadataSchema::SCHEMAID_DEFAULT);
                    $TreeFields = $Schema->getFields(MetadataSchema::MDFTYPE_TREE);
                    return (count($TreeFields) == 0) ? true : false;
                },
                "Help" => "The default field displayed and used"
                        ." as the default browsing option on the"
                        ." Browse Resources page. This may be set"
                        ." to any tree field present in the"
                        ." metadata schema. While the field"
                        ." specified will be the default browsing"
                        ." option, users may choose to browse by"
                        ." any tree field they have"
                        ." permission to browse."
            ],
            "BrowsingSortingFieldId" => [
                "Type" => FormUI::FTYPE_OPTION,
                "StorageType" => InterfaceConfiguration::TYPE_INT,
                "Label" => "Browsing Sorting Field",
                "OptionsFunction" => [$this, "getResourceSortingFields"],
                "DefaultFunction" => function () {
                    $Schema = new MetadataSchema(MetadataSchema::SCHEMAID_DEFAULT);
                    return $Schema->getFieldIdByName("Title");
                },
                "Required" => true,
                "AllowMultiple" => false,
                "Help" => "The default field to use for sorting the"
                        ." resources when browsing."
            ],
            "BrowsingSortingDirection" => [
                "Type" => FormUI::FTYPE_OPTION,
                "StorageType" => InterfaceConfiguration::TYPE_STRING,
                "Label" => "Browsing Sorting Direction",
                "Options" => ["ASC" => "Ascending", "DESC" => "Descending"],
                "Default" => "ASC",
                "Required" => true,
                "AllowMultiple" => false,
                "Help" => "The direction to sort the resources in when"
                        ." browsing."
            ],
            # -------------------------------------------------
            "HEADING-Searching" => [
                "Type" => FormUI::FTYPE_HEADING,
                "Label" => "Searching",
            ],
            "ItemTypesToDisplayInSearchResults" => [
                "Type" => FormUI::FTYPE_OPTION,
                "Label" => "Search Result Types",
                "AllowMultiple" => true,
                "OptionType" => FormUI::OTYPE_INPUTSET,
                "Required" => true,
                "DefaultFunction" => function () {
                    $SchemaNames = MetadataSchema::getAllSchemaNames();
                    unset($SchemaNames[MetadataSchema::SCHEMAID_USER]);
                    return array_keys($SchemaNames);
                },
                "OptionsFunction" => function () {
                    $SchemaNames = MetadataSchema::getAllSchemaNames();
                    unset($SchemaNames[MetadataSchema::SCHEMAID_USER]);
                    return $SchemaNames;
                },
                "Help" => "Which types of items to potentially include"
                        ." in displayed search results.  (Items will still"
                        ." not appear in search results, even if checked"
                        ." here, if none of the fields in the item's"
                        ." schema are enabled for searching.)"
            ],
            "DefaultRecordsPerPage" => [
                "Type" => FormUI::FTYPE_NUMBER,
                "Label" => "Default Results Per Page",
                "Required" => true,
                "MinVal" => 5,
                "RecVal" => 10,
                "Default" => 10,
                "Help" => "Determines the default number"
                        ." of search results displayed per"
                        ." page. Users can override this"
                        ." setting from the User"
                        ." Preferences page."
            ],
            "CollapseMetadataFieldGroups" => [
                "Type" => FormUI::FTYPE_FLAG,
                "Label" => "Collapse Metadata Field Groups",
                "Default" => false,
                "Help" => "Determines whether metadata field groups"
                        ." created on the Metadata Field Ordering page"
                        ." should be collapsed by default when"
                        ." editing a resource."
            ],
            "ShowGroupNamesEnabled" => [
                "Type" => FormUI::FTYPE_FLAG,
                "Label" => "Show Group Names",
                "Default" => true,
                "Help" => "Whether group names are shown in full record page."
            ],
            "IncrementalKeywordSearchEnabled" => [
                "Type" => FormUI::FTYPE_FLAG,
                "Label" => "Incremental Keyword Search",
                "Default" => true,
                "Help" => "Whether users see an incremental "
                        ." keyword search (AKA search-as-you-type)"
                        ." interface to interactively show a subset"
                        ." of search results for the current search"
                        ." string when performing a keyword search"
                        ." from the keyword search box."
            ],
            "DisplayLimitsByDefault" => [
                "Type" => FormUI::FTYPE_FLAG,
                "Label" => "Display Search Limits",
                "Default" => true,
                "Help" => "Determines whether the search"
                        ." limits on the Advanced Search"
                        ." page are displayed or hidden"
                        ." by default."
            ],
            # -------------------------------------------------
            "HEADING-Emails" => [
                "Type" => FormUI::FTYPE_HEADING,
                "Label" => "Emails",
            ],
            "PasswordChangeTemplateId" => [
                "Type" => FormUI::FTYPE_OPTION,
                "StorageType" => InterfaceConfiguration::TYPE_INT,
                "Label" => "Password Change Template",
                "OptionsFunction" => [ $this, "getMailingTemplateList" ],
                "DefaultFunction" => function () {
                    $Templates = $this->getMailingTemplateList("PasswordChangeTemplateId");
                    return array_search("Password Change Default", $Templates);
                }
            ],
            "EmailChangeTemplateId" => [
                "Type" => FormUI::FTYPE_OPTION,
                "StorageType" => InterfaceConfiguration::TYPE_INT,
                "Label" => "Email Change Template",
                "OptionsFunction" => [ $this, "getMailingTemplateList" ],
                "DefaultFunction" => function () {
                    $Templates = $this->getMailingTemplateList("PasswordChangeTemplateId");
                    return array_search("Email Change Default", $Templates);
                }
            ],
            "ActivateAccountTemplateId" => [
                "Type" => FormUI::FTYPE_OPTION,
                "StorageType" => InterfaceConfiguration::TYPE_INT,
                "Label" => "Activate Account Template",
                "OptionsFunction" => [ $this, "getMailingTemplateList" ],
                "DefaultFunction" => function () {
                    $Templates = $this->getMailingTemplateList("PasswordChangeTemplateId");
                    return array_search("Account Activation Default", $Templates);
                }
            ],
            # -------------------------------------------------
            "HEADING-Other" => [
                "Type" => FormUI::FTYPE_HEADING,
                "Label" => "Other",
            ],
            "ResourceLaunchesNewWindowEnabled" => [
                "Type" => FormUI::FTYPE_FLAG,
                "Label" => "Resource Launches New Window",
                "Default" => false,
                "Help" => "Determines if links to the Resource's URL"
                        ." (e.g., from the Full Record page or Search"
                        ." Results) will open in a new tab/window."
            ],
            "DefaultCharacterSet" => [
                "Type" => FormUI::FTYPE_OPTION,
                "StorageType" => InterfaceConfiguration::TYPE_STRING,
                "Label" => "Default Character Encoding",
                "Options" => [
                    "ISO-8859-1" => "ISO-8859-1",
                    "UTF-8" => "UTF-8"
                ],
                "Required" => true,
                "Default" => "UTF-8",
            ],
            "ResourceRatingsEnabled" => [
                "Type" => FormUI::FTYPE_FLAG,
                "Label" => "Resource Ratings/Recommendations",
                "Default" => true,
                "Help" => "Whether <i>resource ratings</i> are"
                        ." enabled and displayed on the site."
            ],
            "PreferredLinkValue" => [
                "Type" => FormUI::FTYPE_OPTION,
                "StorageType" => InterfaceConfiguration::TYPE_STRING,
                "Label" => "Preferred Link Value",
                "Options" => ["URL" => "URL", "FILE" => "File"],
                "Default" => "FILE",
                "Help" => "Used when both <i>Resource URL Field</i> and"
                        ." <i>Resource File Field</i> are set, for records"
                        ." where both fields have values.",
            ],
            "TitlesLinkTo" => [
                "Type" => FormUI::FTYPE_OPTION,
                "StorageType" => InterfaceConfiguration::TYPE_STRING,
                "Label" => "Titles Link to",
                "Options" => ["URL" => "URL", "RECORD" => "Full Record"],
                "Default" => "RECORD",
                "Help" => "Determines whether to use the resource's full"
                        ." record page or its URL when displaying links"
                        ." containing its title."
            ],
            "RequireEmailWithFeedback" => [
                "Type" => FormUI::FTYPE_FLAG,
                "Label" => "Require Email Address with Feedback",
                "Default" => false,
                "Help" => "Determines whether users who are not"
                        ." logged in are required to include an"
                        ." e-mail address when submitting feedback."
            ],
            "AddAWStatsScript" => [
                "Type" => FormUI::FTYPE_FLAG,
                "Label" => "AWStats Logging",
                "Default" => false,
                "Help" => "Whether AWStats logging is performed."
            ],
            "LegalNotice" => [
                "Type" => FormUI::FTYPE_TEXT,
                "Label" => "Legal Notice",
                "Default" => "",
                "Help" => "Legal notice that may be displayed on some"
                        ." interfaces.",
            ],
        ];
    }

    /**
     * Get list of available mailing templates.  (Intended to be used as a
     * callback for "OptionsFunction" parameters, and should not be called
     * otherwise.  Method is only "public" because that is required for the
     * callback use.)
     * @param string $SettingName Setting name that this is being called for.
     * @return array List of mailing templates, with template IDs for the index
     *      and template names for the values.
     */
    public function getMailingTemplateList(string $SettingName): array
    {
        $PluginMgr = PluginManager::getInstance();
        if ($PluginMgr->pluginReady("Mailer")) {
            return $PluginMgr->getPlugin("Mailer")->getTemplateList();
        } else {
            return [];
        }
    }

    /**
     * Get a list of all the possible sorting fields for the resources schema.
     * (Intended to be used as a callback for "OptionsFunction" parameters, and
     * should not be called otherwise.  Method is only "public" because that is
     * required for the callback use.)
     * @param string $SettingName Setting name that this is being called for.
     * @return array List of all the possible sorting fields for the resources schema.
     */
    public function getResourceSortingFields(string $SettingName): array
    {
        return (new MetadataSchema(MetadataSchema::SCHEMAID_RESOURCES))->getSortFields();
    }

    /**
     * Get list of available blogs.  (Intended to be used as a callback
     * for "OptionsFunction" parameters, and should not be called
     * otherwise.  Method is only "public" because that is required for the
     * callback use.)
     * @param string $SettingName Setting name that this is being called for.
     * @return array List of blogs, with blog IDs for the index and blog
     *      names for the values.
     */
    public function getBlogList(string $SettingName): array
    {
        $PluginMgr = PluginManager::getInstance();
        if ($PluginMgr->pluginReady("Blog")) {
            return $PluginMgr->getPlugin("Blog")->getAvailableBlogs();
        } else {
            return [];
        }
    }

    /**
     * Get ID of default blog.  (Intended to be used as a callback
     * for "DefaultFunction" parameters, and should not be called
     * otherwise.  Method is only "public" because that is required for the
     * callback use.)
     * @param string $SettingName Setting name that this is being called for.
     * @return int|null ID of default blog, or NULL if no default blog available.
     */
    public function getIdOfDefaultBlog(string $SettingName)
    {
        $PluginMgr = PluginManager::getInstance();
        if ($PluginMgr->pluginReady("Blog")) {
            $BlogPlugin = $PluginMgr->getPlugin("Blog");
            $BlogId = $BlogPlugin->getBlogIdByName("News");
            if ($BlogId !== false) {
                return $BlogId;
            } else {
                # return ID of first blog if available
                $AllBlogs = $BlogPlugin->getAvailableBlogs();
                foreach ($AllBlogs as $BlogId => $BlogName) {
                    return $BlogId;
                }
                return null;
            }
        } else {
            return null;
        }
    }

    /**
     * Report whether Blog plugin is ready for use.  (This is a convenience
     * function, to be used to disable configuration settings that require
     * the Blog plugin.)
     * @param string $FieldName Name of configuration setting.
     * @return bool FALSE if blog plugin is ready, otherwise TRUE.
     */
    public function isBlogPluginNotReady(string $FieldName): bool
    {
        return !(PluginManager::getInstance())->pluginReady("Blog");
    }

    /**
     * Report whether CalendarEvents plugin is ready for use.  (This is a convenience
     * function, to be used to disable configuration settings that require
     * the CalendarEvents plugin.)
     * @param string $FieldName Name of configuration setting.
     * @return bool FALSE if blog plugin is ready, otherwise TRUE.
     */
    public function isCalendarEventsPluginNotReady(string $FieldName): bool
    {
        return !(PluginManager::getInstance())->pluginReady("CalendarEvents");
    }
}
