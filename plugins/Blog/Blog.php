<?PHP
#
#   FILE:  Blog.php
#
#   A plugin for the Metavus digital collections platform
#   Copyright 2013-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins;
use Exception;
use Metavus\ControlledName;
use Metavus\ControlledNameFactory;
use Metavus\FormUI;
use Metavus\Message;
use Metavus\MetadataField;
use Metavus\MetadataSchema;
use Metavus\Plugin;
use Metavus\Plugins\Blog\BlogEntryUI;
use Metavus\Plugins\Blog\Entry;
use Metavus\Plugins\Blog\EntryFactory;
use Metavus\Plugins\CleanURLs;
use Metavus\Plugins\Mailer;
use Metavus\Plugins\MetricsReporter;
use Metavus\Plugins\MetricsRecorder;
use Metavus\Plugins\SecondaryNavigation;
use Metavus\PrivilegeSet;
use Metavus\Record;
use Metavus\RecordFactory;
use Metavus\SearchEngine;
use Metavus\SystemConfiguration;
use Metavus\InterfaceConfiguration;
use Metavus\User;
use Metavus\UserFactory;
use ScoutLib\ApplicationFramework;
use ScoutLib\Database;
use ScoutLib\Email;
use ScoutLib\PluginManager;

/**
 * Plugin that provides support for blog entries.
 */
class Blog extends Plugin
{
    /**
     * CSV file with the default categories.
     */
    const DEFAULT_CATEGORIES_CSV = "DefaultCategories.csv";

    /**
     * The path to the directory holding the XML representations of the blog
     * fields relative to this file.
     */
    const FIELD_XML_PATH = "install/";

    /**
     * The name of the blog entry title field.
     */
    const TITLE_FIELD_NAME = "Title";

    /**
     * The name of the blog entry body field.
     */
    const BODY_FIELD_NAME = "Body";

    /**
     * The name of the blog entry creation date field.
     */
    const CREATION_DATE_FIELD_NAME = "Date of Creation";

    /**
     * The name of the blog entry modification date field.
     */
    const MODIFICATION_DATE_FIELD_NAME = "Date of Modification";

    /**
     * The name of the blog entry publication date field.
     */
    const PUBLICATION_DATE_FIELD_NAME = "Date of Publication";

    /**
     * The name of the blog entry author field.
     */
    const AUTHOR_FIELD_NAME = "Author";

    /**
     * The name of the blog entry editor field.
     */
    const EDITOR_FIELD_NAME = "Editor";

    /**
     * The name of the blog entry categories field.
     */
    const CATEGORIES_FIELD_NAME = "Categories";

    /**
     * The name of the blog entry image field.
     */
    const IMAGE_FIELD_NAME = "Image";

    /**
     * The name of the blog entry notifications field.
     */
    const NOTIFICATIONS_FIELD_NAME = "Notifications Sent";

    /**
     * The name of the blog entry type field.
     */
    const BLOG_NAME_FIELD_NAME = "Blog Name";

    /**
     * The name of the blog subscription flag field.
     */
    const SUBSCRIPTION_FIELD_NAME = "Subscribe to Blog Entries";

    const NEWLINE_REGEX = '(\r\n|\r|\n)';

     /**
     * Regular expression that will match a sequence of HTML that is rendered as a blank line.
     */
    const HTML_BLANK_REGEX = '(<br ?\/?>|<p>(&nbsp;|\s|\xC2\xA0)*<\/p>)'.self::NEWLINE_REGEX;

    /**
     * This is intended to match sequences like "\n<br/>\n<br/>\n", which is meant to be
     * a break between two paragraphs. It's used for legacy (existing) entries to locate
     * where to insert images into entry bodies via Blog::upgrade(). New entries won't use
     * this since images will be handled by CKEDITOR.
     * The leading NEWLINE_REGEX prevents matching an HTML_BLANK_REGEX at the end of a
     * nonempty line, e.g. "This is a test<br/>". All input is expected to be HTML.
     */
    const DOUBLE_BLANK_REGEX = self::NEWLINE_REGEX.self::HTML_BLANK_REGEX
            .self::HTML_BLANK_REGEX;

    /**
     * Register information about this plugin.
     */
    public function register()
    {
        $this->Name = "Blog";
        $this->Version = "1.0.26";
        $this->Description = "Adds blog functionality.";
        $this->Author = "Internet Scout Research Group";
        $this->Url = "http://metavus.net";
        $this->Email = "support@metavus.net";
        $this->Requires = [
            "MetavusCore" => "1.2.0",
            "MetricsRecorder" => "1.2.4",
            "MetricsReporter" => "0.9.2",
            "SocialMedia" => "1.1.0",
            "Mailer" => "1.0.6",
        ];
        $this->InitializeAfter = ["SecondaryNavigation"];
        $this->EnabledByDefault = true;
        $this->Instructions = '
            <p>
              <b>Note:</b> The blog entry metadata fields can be configured on
              the <a href="index.php?P=DBEditor">Metadata Field Editor</i></a>
              page once the plugin is installed.
            </p>
            <p>
              Users may subscribe to blog entry notifications and notifications
              may be sent once a notification e-mail template has been chosen
              below.E-mail templates are created on the
              <a href="index.php?P=P_Mailer_EditMessageTemplates"><i>Email
              Message Templates</i></a> page.A few blog-specific keywords can
              be used in the template in addition to the default mail keywords
              available.They are:
            </p>
            <dl class="cw-content-specialstrings">
              <dt>X-BLOG:UNSUBSCRIBE-X</dt>
              <dd>URL to the page to unsubscribe from blog entry notifications</dd>
              <dt>X-BLOG:BLOGNAME-X</dt>
              <dd>name of the blog</dd>
              <dt>X-BLOG:BLOGDESCRIPTION-X</dt>
              <dd>description of the blog</dd>
              <dt>X-BLOG:BLOGURL-X</dt>
              <dd>URL to the blog landing page</dd>
              <dt>X-BLOG:ENTRYURL-X</dt>
              <dd>URL to the blog entry</dd>
              <dt>X-BLOG:ENTRYAUTHOR-X</dt>
              <dd>the author\'s real name if available and username otherwise</dd>
              <dt>X-BLOG:TEASER-X</dt>
              <dd>blog entry teaser, possibly containing HTML</dd>
              <dt>X-BLOG:TEASERTEXT-X</dt>
              <dd>blog entry teaser in plain text</dd>
            </dl>';
    }

    /**
     * Set up plugin configuration options.
     * @return NULL if configuration setup succeeded, otherwise a string with
     *       an error message indicating why config setup failed.
     */
    public function setUpConfigOptions(): ?string
    {
        $this->CfgSetup["BlogManagerPrivs"] = [
            "Type" => FormUI::FTYPE_PRIVILEGES,
            "Label" => "Privileges Required to View Metrics and Add/Remove Subscribers",
            "Help" => "The user privileges required to view blog entry metrics and "
                ."add/remove subscribers.",
            "AllowMultiple" => true
        ];

        $this->CfgSetup["SummaryLength"] = [
            "Type" => "Number",
            "Label" => "Summary Length",
            "Help" => "Target length (in characters) for blog summaries,"
                ." displayed in search results.",
            "Default" => 400,
            "MinVal" => 40,
            "MaxVal" => 2000,
        ];

        $this->CfgSetup["EmailNotificationBlog"] = [
            "Type" => "Option",
            "Label" => "Blog to Send Notification Emails",
            "Help" => "The blog which subscribed users should get notification emails from.",
            "Default" => -1,
            "OptionsFunction" => [$this, "getNotificationBlogOptions"],
            "AllowMultiple" => false
        ];

        $this->CfgSetup["NotificationTemplate"] = [
            "Type" => "Option",
            "Label" => "Notification E-mail Template",
            "Help" => "The Mailer template to use for notifications of new posts.",
            "Default" => -1,
            "OptionsFunction" => [$this, "getMailerTemplateOptions"],
        ];

        $this->CfgSetup["SubscriptionConfirmationTemplate"] = [
            "Type" => "Option",
            "Label" => "Subscription E-mail Template",
            "Help" => "The Mailer template for subscription notification emails.",
            "Default" => -1,
            "OptionsFunction" => [$this, "getMailerTemplateOptions"],
        ];

        $this->CfgSetup["UnsubscriptionConfirmationTemplate"] = [
            "Type" => "Option",
            "Label" => "Unsubscription E-mail Template",
            "Help" => "The Mailer template for unsubscription notification emails.",
            "Default" => -1,
            "OptionsFunction" => [$this, "getMailerTemplateOptions"],
        ];

        $this->addAdminMenuEntry(
            "ListBlogs",
            "Add/Edit Blogs",
            [ PRIV_SYSADMIN ]
        );
        $this->addAdminMenuEntry(
            "SubscriberStatistics",
            "Blog Subscription Metrics",
            [ PRIV_USERADMIN ]
        );
        if (PluginManager::getInstance()->pluginEnabled("MetricsRecorder")) {
            $this->addAdminMenuEntry(
                "BlogReports",
                "Blog Usage Metrics",
                [ PRIV_COLLECTIONADMIN ]
            );
        }

        return null;
    }

    /**
     * Startup initialization for plugin.
     * @return NULL if initialization was successful, otherwise a string
     *       containing an error message indicating why initialization failed.
     */
    public function initialize(): ?string
    {
        $AF = ApplicationFramework::getInstance();
        $PluginMgr = PluginManager::getInstance();

        foreach ($this->getConfigSetting("BlogSettings") as $BlogId => $BlogSettings) {
            if (isset($BlogSettings["CleanUrlPrefix"]) &&
                strlen($BlogSettings["CleanUrlPrefix"]) > 0) {
                $RegexCleanUrlPrefix = preg_quote($BlogSettings["CleanUrlPrefix"]);

                # clean URL for viewing all blog entries
                $AF->addCleanUrl(
                    "%^".$RegexCleanUrlPrefix."/?$%",
                    "P_Blog_Entries",
                    ["BlogId" => $BlogId],
                    $BlogSettings["CleanUrlPrefix"]."/"
                );

                $AF->addCleanUrl(
                    "%^".$RegexCleanUrlPrefix."/?([0-9]+)%",
                    "P_Blog_Entry",
                    ["ID" => "\$1"],
                    $BlogSettings["CleanUrlPrefix"]."/\$ID"
                );
            }
        }

        # register our events with metrics recorder
        MetricsRecorder::getInstance()->registerEventType(
            "Blog",
            "ViewEntry"
        );

        # add extra function dirs that we need
        # (these are listed in reverse order because each will be added to the
        #       beginning of the search list)
        $BaseName = $this->getBaseName();
        $AF->addFunctionDirectories([
            "plugins/".$BaseName."/interface/default/include/",
            "plugins/".$BaseName."/interface/%ACTIVEUI%/include/",
            "local/plugins/".$BaseName."/interface/default/include/",
            "local/plugins/".$BaseName."/interface/%ACTIVEUI%/include/",
        ], true);

        # add extra image dirs that we need
        # (must be added explicitly because our code gets loaded
        #       on pages other than ours)
        $AF->addImageDirectories([
            "plugins/".$BaseName."/interface/default/images/",
            "plugins/".$BaseName."/interface/%ACTIVEUI%/images/",
            "local/plugins/".$BaseName."/interface/default/images/",
            "local/plugins/".$BaseName."/interface/%ACTIVEUI%/images/",
        ], true);

        # if MetricsRecorder is enabled, register our subscriber count event
        if ($PluginMgr->pluginReady("MetricsRecorder")) {
            MetricsRecorder::getInstance()->registerEventType(
                $this->Name,
                "NumberOfSubscribers"
            );
        }

        if ($PluginMgr->pluginEnabled("SecondaryNavigation") &&
            User::getCurrentUser()->isLoggedIn()) {
            $EditingPrivs = (new MetadataSchema($this->getSchemaId()))
                ->editingPrivileges();

            $SecondaryNav = SecondaryNavigation::getInstance();
            $SecondaryNav->offerNavItem(
                "Blog Entries",
                "index.php?P=P_Blog_ListEntries",
                $EditingPrivs,
                "View a list of all user created blogs."
            );
        }

        Record::registerObserver(
            Record::EVENT_ADD | Record::EVENT_SET,
            [$this, "resourceEdited"]
        );

        # report success
        return null;
    }

    /**
     * Install this plugin.
     * @return string|null Returns NULL if everything went OK or an error message otherwise.
     */
    public function install(): ?string
    {
        $IntConfig = InterfaceConfiguration::getInstance();

        # setup the default privileges for authoring and editing
        $DefaultPrivs = new PrivilegeSet();
        $DefaultPrivs->addPrivilege(PRIV_NEWSADMIN);
        $DefaultPrivs->addPrivilege(PRIV_SYSADMIN);

        # use the default privs as the default privs to view metrics
        $this->setConfigSetting("BlogManagerPrivs", $DefaultPrivs);

        # create a new metadata schema and save its ID
        $Schema = MetadataSchema::create(
            "Blog",
            $DefaultPrivs,
            $DefaultPrivs,
            $DefaultPrivs,
            "index.php?P=P_Blog_Entry&ID=\$ID",
            # (this name must match the ResourceSummary class for the Blog plugin)
            "Blog Entry"
        );
        $Schema->setItemClassName("Metavus\\Plugins\\Blog\\Entry");
        $Schema->setEditPage("index.php?P=EditResource&ID=\$ID");
        $this->setConfigSetting("MetadataSchemaId", $Schema->id());

        # populate our new schema with fields from XML file
        $BaseName = $this->getBaseName();
        if ($Schema->addFieldsFromXmlFile(
            "plugins/".$BaseName."/install/MetadataSchema--"
            .$BaseName.".xml"
        ) === false) {
            return "Error loading Blog metadata fields from XML: ".implode(
                " ",
                $Schema->errorMessages("AddFieldsFromXmlFile")
            );
        }

        # populate user schema with new fields from XML file (if any)
        $UserSchema = new MetadataSchema(MetadataSchema::SCHEMAID_USER);
        $UserFieldsFile = "plugins/".$BaseName
                ."/install/MetadataSchema--User.xml";
        if ($UserSchema->addFieldsFromXmlFile($UserFieldsFile) === false) {
            return "Error loading User metadata fields from XML: ".implode(
                " ",
                $UserSchema->errorMessages("AddFieldsFromXmlFile")
            );
        }

        # disable the subscribe field until an notification e-mail template is
        # selected
        $SubscribeField = $UserSchema->getField(self::SUBSCRIPTION_FIELD_NAME);
        $SubscribeField->enabled(false);

        # change the subscribe field label to match the blog name
        $SubscribeField->label("Subscribe to ".$this->getConfigSetting("BlogName"));

        # get the file that holds the default categories
        $DefaultCategoriesFile = @fopen($this->getDefaultCategoriesFile(), "r");
        if ($DefaultCategoriesFile === false) {
            return "Could not prepopulate the category metadata field.";
        }

        # get the categories
        $Categories = @fgetcsv($DefaultCategoriesFile, null, ",", "\"", "\\");
        if ($Categories === false) {
            return "Could not parse the default categories";
        }
        $CategoriesField = $Schema->getField(self::CATEGORIES_FIELD_NAME);

        # add each category
        foreach ($Categories as $Category) {
            ControlledName::create($Category, $CategoriesField->id());
        }

        # close the default category file
        @fclose($DefaultCategoriesFile);

        # create ConfigSetting "BlogSettings"
        $this->setConfigSetting("BlogSettings", []);

        # create the default blog
        $DefaultBlog = $this->createBlog($IntConfig->getString("PortalName")." Blog");
        $this->blogSettings($DefaultBlog, $this->getBlogConfigTemplate());
        $this->blogSetting(
            $DefaultBlog,
            "BlogName",
            $IntConfig->getString("PortalName")." Blog"
        );
        $this->blogSetting(
            $DefaultBlog,
            "CleanUrlPrefix",
            "blog"
        );

        # create a default News blog as well
        $NewsBlog = $this->createBlog("News");
        $this->blogSettings($NewsBlog, $this->getBlogConfigTemplate());
        $this->blogSetting($NewsBlog, "BlogName", "News");

        # update the editing privileges now that the fields have been created
        $EditingPrivs = $DefaultPrivs;
        $EditingPrivs->addCondition($Schema->getField(self::AUTHOR_FIELD_NAME));
        $Schema->editingPrivileges($EditingPrivs);

        $ViewingPrivs = $EditingPrivs;
        $ViewingPrivs->addCondition(
            $Schema->getField(self::PUBLICATION_DATE_FIELD_NAME),
            "now",
            "<"
        );
        $Schema->viewingPrivileges($ViewingPrivs);

        return null;
    }

    /**
     * Uninstall this plugin.
     * @return string|null Returns NULL if everything went OK or an error message otherwise.
     */
    public function uninstall(): ?string
    {
        $Schema = new MetadataSchema($this->getSchemaId());
        $ResourceFactory = new RecordFactory($this->getSchemaId());

        # delete each resource, including temp ones
        foreach ($ResourceFactory->getItemIds(null, true) as $ResourceId) {
            $Resource = new Record($ResourceId);
            $Resource->destroy();
        }

        $Schema->delete();

        # remove the subscription field from the user schema
        $UserSchema = new MetadataSchema(MetadataSchema::SCHEMAID_USER);
        $SubscribeField = $UserSchema->getField(self::SUBSCRIPTION_FIELD_NAME);
        $UserSchema->dropField($SubscribeField->id());

        return null;
    }

    /**
     * Hook event callbacks into the application framework.
     * @return array Returns an array of events to be hooked into the application framework.
     */
    public function hookEvents(): array
    {
        $Hooks = [
            "EVENT_IN_HTML_HEADER" => "InHtmlHeader",
            "EVENT_MODIFY_SECONDARY_NAV" => "AddSecondaryNavLinks",
            "EVENT_PLUGIN_CONFIG_CHANGE" => "PluginConfigChange",
            "EVENT_PLUGIN_EXTEND_EDIT_RESOURCE_COMPLETE_ACCESS_LIST"
                => "ExtendEditResourceCompleteAccessList",
            "EVENT_HTML_INSERTION_POINT" => "InsertBlogSummary",
            "EVENT_DAILY" => "RunDaily",
        ];
        if ($this->getConfigSetting("AddNavItem")) {
            $Hooks["EVENT_MODIFY_PRIMARY_NAV"] = "AddPrimaryNavLinks";
        }
        return $Hooks;
    }

    /**
     * Callback for constructing clean URLs to be inserted by the application
     * framework when more than regular expression replacement is required.
     * This method is passed to ApplicationFramework::AddCleanURL().
     * @param array $Matches Array of matches from preg_replace().
     * @param string $Pattern Original pattern for clean URL mapping.
     * @param string $Page Page name for clean URL mapping.
     * @param string $SearchPattern Full pattern passed to preg_replace().
     * @return string Replacement to be inserted in place of match.
     */
    public function cleanUrlTemplate(
        array $Matches,
        string $Pattern,
        string $Page,
        string $SearchPattern
    ): string {
        if ($Page == "P_Blog_Entry") {
            # if no resource/classification ID found
            if (count($Matches) <= 2 || !Record::itemExists($Matches[2]) ||
                Record::getSchemaForRecord($Matches[2]) != $this->getSchemaId()) {
                # return match unchanged
                return $Matches[0];
            }

            # get the blog entry from the matched ID
            $Entry = new Entry($Matches[2]);

            # return the replacement
            return "href=\"".defaulthtmlentities($Entry->entryUrl())."\"";
        }

        # return match unchanged
        return $Matches[0];
    }

    /**
     * Print stylesheet and Javascript elements in the page header.
     */
    public function inHtmlHeader(): void
    {
        # only require our stylesheet for Blog pages
        $AF = ApplicationFramework::getInstance();
        if (preg_match('/^P_Blog_/', $AF->getPageName())) {
            $AF->requireUIFile("P_Blog.css");
        }
    }

    /**
     * Add administration links for the plugin to the primary navigation links.
     * @param array $NavItems Existing nav items.
     * @return array Returns the nav items, possibly edited.
     */
    public function addPrimaryNavLinks(array $NavItems): array
    {
        # add a link to the blog
        $NavItems = $this->insertNavItemBefore(
            $NavItems,
            "Blog",
            "index.php?P=P_Blog_Entries",
            "About"
        );

        return ["NavItems" => $NavItems];
    }

    /**
     * Add administration links for the plugin to the sidebar.
     * @param array $NavItems Existing nav items.
     * @return array Returns the nav items, possibly edited.
     */
    public function addSecondaryNavLinks(array $NavItems): array
    {
        $BlogSchema = new MetadataSchema($this->getSchemaId());

        # add a link to the blog entry list if the user can edit blog entries
        if ($BlogSchema->userCanEdit(User::getCurrentUser())) {
            $NavItems = $this->insertNavItemBefore(
                $NavItems,
                "Blog Entries",
                "index.php?P=P_Blog_ListEntries",
                "Administration"
            );
        }

        return ["NavItems" => $NavItems];
    }

    /**
     * Get list of Mailer templates for plugin configuration.
     * @return array List of templates.
     */
    public function getMailerTemplateOptions()
    {
        static $TemplateOptions = null;

        if (is_null($TemplateOptions)) {
            $MailerPlugin = Mailer::getInstance();
            $MailerTemplates = $MailerPlugin->getTemplateList();
            $TemplateOptions = [-1 => "(do not send email)"] + $MailerPlugin->getTemplateList();
        }

        return $TemplateOptions;
    }

    /**
     * Get list of blogs that can be used for email notifications.
     * @return array List of blogs.
     */
    public function getNotificationBlogOptions()
    {
        $Options = $this->getAvailableBlogs()
            + [-1 => "(do not send email)"];
        return $Options;
    }

    /**
     * Handle plugin configuration changes.
     * @param string $PluginName Name of the plugin that has changed.
     * @param string $ConfigSetting Name of the setting that has change.
     * @param mixed $OldValue The old value of the setting.
     * @param mixed $NewValue The new value of the setting.
     */
    public function pluginConfigChange(
        string $PluginName,
        string $ConfigSetting,
        $OldValue,
        $NewValue
    ): void {
        # only worried about changes to the blog plugin
        if ($PluginName != "Blog") {
            return;
        }

        $UserSchema = new MetadataSchema(MetadataSchema::SCHEMAID_USER);

        if ($ConfigSetting == "NotificationTemplate") {
            # enable/disable the subscribe field if only if a valid mail
            # template has been chosen
            $SubscribeField = $UserSchema->getField(self::SUBSCRIPTION_FIELD_NAME);
            $SubscribeField->enabled(is_numeric($NewValue) && $NewValue != -1);
        }

        if ($ConfigSetting == "BlogName") {
            $SubscribeField = $UserSchema->getField(self::SUBSCRIPTION_FIELD_NAME);

            # if a non-blank name is given
            if (strlen(trim($NewValue))) {
                # change the subscribe field's label to reflect the blog name
                $SubscribeField->label("Subscribe to ".$NewValue);
            # otherwise clear the label
            } else {
                $SubscribeField->label(null);
            }
        }

        if ($ConfigSetting == "SummaryLength") {
            # Update all blog summaries to new length
            $BlogEntries = $this->getBlogEntries();
            foreach ($BlogEntries as $BlogEntry) {
                $BlogEntry->set("Summary", $BlogEntry->Teaser($NewValue));
            }
        }
    }

    /**
     * Callback executed whenever a resource is edited (or added).
     * @param int $Events Record::EVENT_* values OR'd together.
     * @param Record $Resource Just-modified resource.
     */
    public function resourceEdited(int $Events, Record $Resource): void
    {
        # only concerned with blog entry resources
        if (!$this->isBlogEntry($Resource)) {
            return;
        }

        # update the entry summary
        $BlogEntryForResource = new Entry($Resource->id());
        $BlogEntryForResource->set("Summary", $BlogEntryForResource->teaser(
            ($this->getConfigSetting("SummaryLength"))
        ));
    }

    /**
     * Select a blog on which to operate.
     * @param int $BlogId BlogId to operate on.
     */
    public function setCurrentBlog(int $BlogId): void
    {
        if (!array_key_exists($BlogId, $this->getAvailableBlogs())) {
            throw new Exception(
                "Attempt to select a blog that does not exist"
            );
        }

        $this->SelectedBlog = $BlogId;
    }

    /**
     * Get the blog name.
     * @return string Returns the blog name.
     */
    public function blogName(): string
    {
        return $this->getSettingForSelectedBlog("BlogName");
    }

    /**
     * Get the blog description.
     * @return string Returns the blog description.
     */
    public function blogDescription(): string
    {
        return $this->getSettingForSelectedBlog("BlogDescription");
    }

    /**
     * Get whether comments are enabled.
     * @return bool Returns TRUE if comments are enabled and FALSE otherwise.
     */
    public function enableComments(): bool
    {
        return $this->getSettingForSelectedBlog("EnableComments");
    }

    /**
     * Get whether the author should be displayed with blog entries.
     * @return bool Returns TRUE if the author should be displayed with blog entries
     *       and FALSE otherwise.
     */
    public function showAuthor(): bool
    {
        return $this->getSettingForSelectedBlog("ShowAuthor");
    }

    /**
     * Get the maximum length of the teaser in number of characters.
     * @return int Returns the maximum length of the teaser.
     */
    public function maxTeaserLength(): int
    {
        return $this->getSettingForSelectedBlog("MaxTeaserLength");
    }

    /**
     * Get the number of blog entries to display at once in the blog.
     * @return int Returns the number of blog entries to display at once in the blog.
     */
    public function entriesPerPage(): int
    {
        return $this->getSettingForSelectedBlog("EntriesPerPage");
    }

    /**
     * Get the clean URL prefix.
     * @return string Returns the clean URL prefix.
     */
    public function cleanUrlPrefix(): string
    {
        return $this->getSettingForSelectedBlog("CleanUrlPrefix") ?? "";
    }

    /**
     * Get the schema ID associated with the blog entry metadata schema.
     * @return int Returns the schema ID of the blog entry metadata schema.
     */
    public function getSchemaId(): int
    {
        return $this->getConfigSetting("MetadataSchemaId");
    }

    /**
     * Record a blog entry view with the Metrics Recorder plugin.
     * @param Record $Entry Blog entry.
     */
    public function recordBlogEntryView(Record $Entry): void
    {
        # record the event
        MetricsRecorder::getInstance()->recordEvent(
            "Blog",
            "ViewEntry",
            $Entry->id()
        );
    }

    /**
     * Determine if a resource is also a blog entry.
     * @param Record $Resource Resource to check.
     * @return bool Returns TRUE if the resources is also a blog entry.
     */
    public function isBlogEntry(Record $Resource): bool
    {
        return $Resource->getSchemaId() == $this->getSchemaId();
    }

    /**
     * Get all blog entries.
     * @return array Returns an array of all blog entries.
     */
    public function getBlogEntries(): array
    {
        $Factory = new RecordFactory($this->getSchemaId());
        $Entries = [];

        # transform IDs to blog entry objects
        foreach ($Factory->getItemIds() as $ItemId) {
            $Entries[$ItemId] = new Entry($ItemId);
        }

        return $Entries;
    }

    /**
     * Get all published blog entries.
     * @return array Returns an array of all published blog entries.
     */
    public function getPublishedBlogEntries(): array
    {
        $Schema = new MetadataSchema($this->getSchemaId());
        $Factory = new RecordFactory($Schema->id());
        $PublicationField = $Schema->getField(self::PUBLICATION_DATE_FIELD_NAME);
        $DBFieldName = $PublicationField->dBFieldName();
        $Entries = [];
        $Ids = $Factory->getItemIds(
            $DBFieldName." < '".date("Y-m-d H:i:s")."'",
            false,
            $DBFieldName,
            false
        );

        # transform IDs to blog entry objects
        foreach ($Ids as $ItemId) {
            $Entries[$ItemId] = new Entry($ItemId);
        }

        return $Entries;
    }

    /**
     * Get the latest blog entry publication date.
     * @return string|null Returns the latest blog entry publication date or
     *      NULL if there isn't one.
     */
    public function getLatestPublicationDate()
    {
        $Schema = new MetadataSchema($this->getSchemaId());
        $PublicationField = $Schema->getField(self::PUBLICATION_DATE_FIELD_NAME);
        $DBFieldName = $PublicationField->dBFieldName();
        $DB = new Database();

        $Date = $DB->queryValue(
            "SELECT MAX(".$DBFieldName.") AS LastChangeDate FROM Records"
                    ." WHERE SchemaId = ".$this->getSchemaId(),
            "LastChangeDate"
        );

        return $Date ? $Date : null;
    }

    /**
     * Get the URL to the blog relative to the site root.
     * @param array $Get Optional GET parameters to add.
     * @param string $Fragment Optional fragment ID to add.
     * @return string Returns the URL to the blog relative to the site root.
     */
    public function blogUrl(array $Get = [], ?string $Fragment = null): string
    {
        # if clean URLs are available
        if (ApplicationFramework::getInstance()->cleanUrlSupportAvailable() &&
            strlen($this->cleanUrlPrefix())) {
            # base part of the URL
            $Url = $this->cleanUrlPrefix()."/";
        # clean URLs aren't available
        } else {
            # base part of the URL
            $Url = "index.php";

            # add the page to the GET parameters
            $Get["P"] = "P_Blog_Entries";
            $Get["BlogId"] = $this->SelectedBlog;
        }

        # tack on the GET parameters, if necessary
        if (count($Get)) {
            $Url .= "?".http_build_query($Get);
        }

        # tack on the fragment identifier, if necessary
        if (!is_null($Fragment)) {
            $Url .= "#".urlencode($Fragment);
        }

        return $Url;
    }

    /**
     * Get the metrics for a blog entry.
     * @param Entry $Entry Blog entry for which to get metrics.
     * @return array Returns an array of metrics.Keys are "Views", "Shares/Email",
     *     "Shares/Facebook", "Shares/Twitter", and "Shares/LinkedIn"
     */
    public function getBlogEntryMetrics(Entry $Entry): array
    {
        # get the metrics plugins
        $Recorder = MetricsRecorder::getInstance();
        $Reporter = MetricsReporter::getInstance();

        # get the privileges to exclude
        $Exclude = $Reporter->getConfigSetting("PrivsToExcludeFromCounts");

        # get the view metrics
        $Metrics["Views"] = $Recorder->getEventData(
            "Blog",
            "ViewEntry",
            null,
            null,
            null,
            $Entry->id(),
            null,
            $Exclude
        );

        # get metrics for shares via e-mail
        $Metrics["Shares/Email"] = $Recorder->getEventData(
            "SocialMedia",
            "ShareResource",
            null,
            null,
            null,
            $Entry->id(),
            SocialMedia::SITE_EMAIL,
            $Exclude
        );

        # get metrics for shares via Facebook
        $Metrics["Shares/Facebook"] = $Recorder->getEventData(
            "SocialMedia",
            "ShareResource",
            null,
            null,
            null,
            $Entry->id(),
            SocialMedia::SITE_FACEBOOK,
            $Exclude
        );

        # get metrics for shares via Twitter
        $Metrics["Shares/Twitter"] = $Recorder->getEventData(
            "SocialMedia",
            "ShareResource",
            null,
            null,
            null,
            $Entry->id(),
            SocialMedia::SITE_TWITTER,
            $Exclude
        );

        # get metrics for shares via LinkedIn
        $Metrics["Shares/LinkedIn"] = $Recorder->getEventData(
            "SocialMedia",
            "ShareResource",
            null,
            null,
            null,
            $Entry->id(),
            SocialMedia::SITE_LINKEDIN,
            $Exclude
        );

        return $Metrics;
    }

    /**
     * Determine if a user can post comments.
     * @param User $User User to check.
     * @return bool Returns TRUE if the user can post comments.
     */
    public function userCanPostComment(User $User): bool
    {
        return $User->hasPriv(PRIV_SYSADMIN, PRIV_POSTCOMMENTS);
    }

    /**
     * Determine if a user can edit a comment.
     * @param User $User User to check.
     * @param Message $Comment Comment to check.
     * @return bool Returns TRUE if the user can edit the comment.
     */
    public function userCanEditComment(User $User, Message $Comment): bool
    {
        # anonymous users can't edit comments
        if (!$User->isLoggedIn()) {
            return false;
        }

        # users have to be system administrators or have the privilege to add comments
        if (!$User->hasPriv(PRIV_SYSADMIN, PRIV_POSTCOMMENTS)) {
            return false;
        }

        # the user must be the owner or a system administrator
        if ($User->id() != $Comment->posterId() && !$User->hasPriv(PRIV_SYSADMIN)) {
            return false;
        }

        # the user can edit the comment
        return true;
    }

    /**
     * Determine if a user can delete a comment.
     * @param User $User User to check.
     * @param Message $Comment Comment to check.
     * @return bool Returns TRUE if the user can delete the comment.
     */
    public function userCanDeleteComment(User $User, Message $Comment): bool
    {
        # anonymous users can't mark delete comments
        if (!$User->isLoggedIn()) {
            return false;
        }

        # users have to be system administrators or have the privilege to add
        # comments
        if (!$User->hasPriv(PRIV_SYSADMIN, PRIV_POSTCOMMENTS)) {
            return false;
        }

        # users have to own the comment or be system or user administrators to
        # delete comments
        if ($User->id() != $Comment->posterId()
            && !$User->hasPriv(PRIV_SYSADMIN, PRIV_USERADMIN)) {
            return false;
        }

        # the user can edit the comment
        return true;
    }

    /**
     * Determine if a user can mark a comment as spam.
     * @param User $User User to check.
     * @param Message $Comment Comment to check.
     * @return bool Returns TRUE if the user can mark the comment as spam.
     */
    public function userCanMarkCommentAsSpam(User $User, Message $Comment): bool
    {
        return $this->userCanEditUsers($User);
    }

    /**
     * Determine if a user can edit other users.
     * @param User $User User to check.
     * @return bool Returns TRUE if the user can edit other users.
     */
    public function userCanEditUsers(User $User): bool
    {
        # anonymous users can't mark comments as spam
        if (!$User->isLoggedIn()) {
            return false;
        }

        # users have to be system or user administrators
        if (!$User->hasPriv(PRIV_SYSADMIN, PRIV_USERADMIN)) {
            return false;
        }

        # the user can edit other users
        return true;
    }

    /**
     * Determine if a user can view blog entry metrics.
     * @param User $User User to check.
     * @return bool Returns TRUE if the user can view blog entry metrics.
     */
    public function userCanViewMetrics(User $User): bool
    {
        $ViewPrivs = $this->getConfigSetting("BlogManagerPrivs");
        return is_array($ViewPrivs) ? $User->hasPriv($ViewPrivs) :
            $ViewPrivs->meetsRequirements($User);
    }

    /**
     * Check a user's notification subscription status.
     * @param User $User The user for which check the subscription status.
     * @return bool Returns TRUE if the user is subscribed and FALSE otherwise.
     */
    public function userIsSubscribedToNotifications(User $User): bool
    {
        # the user must be logged in and notifications must be able to be sent
        if (!$User->isLoggedIn() || !$this->notificationsCouldBeSent()) {
            return false;
        }

        # get the status of the subscription field for the user
        $Resource = $User->getResource();
        return (bool)$Resource->get(self::SUBSCRIPTION_FIELD_NAME);
    }

    /**
     * Change the notification subscription status for the given user.
     * @param User $User The user for which to change the subscription.
     * @param bool $Status TRUE to subscribe and FALSE to unsubscribe.
     */
    public function changeNotificationSubscription(User $User, bool $Status): void
    {
        # only set the status if notifications could be sent
        if ($User->isLoggedIn() && $this->notificationsCouldBeSent()) {
            $Resource = $User->getResource();
            $Resource->set(self::SUBSCRIPTION_FIELD_NAME, $Status);

            # pull out the configured Mailer template for notifications
            $MailerTemplate = $this->getConfigSetting(
                $Status ? "SubscriptionConfirmationTemplate" :
                "UnsubscriptionConfirmationTemplate"
            );

            # if a template was selected
            if ($MailerTemplate != -1) {
                # send notifications
                $MailerPlugin = Mailer::getInstance();
                $MailerPlugin->sendEmail($MailerTemplate, $User);
            }
        }
    }

    /**
     * Determine if notifications could be sent out.
     * @param Entry $Entry Optional blog entry to use as context.
     * @param User $User Optional user to use as context.
     * @return bool Returns TRUE if notifications could be sent out and FALSE
     *      otherwise.
     */
    public function notificationsCouldBeSent(
        ?Entry $Entry = null,
        ?User $User = null
    ): bool {
        # the template has to be set
        if (!is_numeric($this->getConfigSetting("NotificationTemplate"))
            || $this->getConfigSetting("NotificationTemplate") == -1) {
            return false;
        }

        $UserSchema = new MetadataSchema(MetadataSchema::SCHEMAID_USER);
        $SubscribeField = $UserSchema->getField(self::SUBSCRIPTION_FIELD_NAME);

        # the subscription user field must exist
        if (!($SubscribeField instanceof MetadataField)) {
            return false;
        }

        # the subscription field must be enabled
        if (!$SubscribeField->enabled()) {
            return false;
        }

        # perform blog entry checks if given one
        if ($Entry instanceof Entry) {
            $PublicationDate = $Entry->get(self::PUBLICATION_DATE_FIELD_NAME);

            # the blog has to be published
            if (time() < strtotime($PublicationDate)) {
                return false;
            }

            # don't allow notifications to be sent multiple times
            if ($Entry->get(self::NOTIFICATIONS_FIELD_NAME)) {
                return false;
            }
        }

        # perform user checks if given one
        if ($User instanceof User && $Entry !== null) {
            # the user should only be allowed to send out notifications if
            # they can edit blog entries
            if (!$Entry->userCanEdit($User)) {
                return false;
            }
        }

        # notifications could be sent
        return true;
    }

    /**
     * Send test notification e-mails to current user about the given blog
     * entry.  This will only send out e-mails if it's appropriate to do so,
     * i.e., the user's allowed to, an e-mail template is set, etc.
     * @param Entry $Entry The entry to use.
     */
    public function sendTestNotification(Entry $Entry): void
    {
        $this->sendNotificationEmail(
            $Entry,
            [User::getCurrentUser()->id()]
        );
    }

    /**
     * Send out notification e-mails to subscribers about the given blog entry.
     * This will only send out e-mails if it's appropriate to do so, i.e., the
     * user's allowed to, an e-mail template is set, etc.
     * @param Entry $Entry The entry about which to notify subscribers.
     */
    public function notifySubscribers(Entry $Entry): void
    {
        # get the list of filtered user (subscriber) ids where disabled users are not included
        $UserFactory = new UserFactory();
        $SubscribedUserIds = $this->getSubscribers();
        $AllDisabledUserIds = array_keys($UserFactory->getUsersWithPrivileges(PRIV_USERDISABLED));
        $UserIds = array_diff($SubscribedUserIds, $AllDisabledUserIds);

        $EmailSent = $this->sendNotificationEmail(
            $Entry,
            $UserIds
        );

        if ($EmailSent) {
            # flag that notifications have been sent
            $Entry->set(self::NOTIFICATIONS_FIELD_NAME, true);
        }
    }

    /**
     * Record daily subscriber statistics.
     * @param int $LastRunAt Timestamp of last time this event ran.
     */
    public function runDaily($LastRunAt): void
    {
        $PluginMgr = PluginManager::getInstance();
        if (!$PluginMgr->pluginReady("MetricsRecorder")) {
            return;
        }

        $UserIds = $this->getSubscribers();
        $SubscriberCount = count($UserIds);

        MetricsRecorder::getInstance()->recordEvent(
            $this->Name,
            "NumberOfSubscribers",
            $this->getConfigSetting("EmailNotificationBlog"),
            $SubscriberCount,
            null,
            0,
            false
        );
    }

    /**
     * Get UserIds of blog subscribers.
     * @return array of UserIds.
     */
    public function getSubscribers(): array
    {
        $UserSchema = new MetadataSchema(MetadataSchema::SCHEMAID_USER);
        $SubscribeField = $UserSchema->getField(self::SUBSCRIPTION_FIELD_NAME);
        $UserField = $UserSchema->getField("UserId");

        # query user resources directly since the search engine isn't capable
        # of it yet
        $DB = new Database();
        $DB->query("SELECT UserId AS Id FROM RecordUserInts WHERE "
                ."FieldId = ".$UserField->id()." AND RecordId IN ("
                ."  SELECT RecordId FROM Records WHERE "
                ."  RecordId > 0 AND "
                ."  ".$SubscribeField->dBFieldName()." = 1 "
                ."  AND SchemaId = ".MetadataSchema::SCHEMAID_USER.")");
        $UserIds = $DB->fetchColumn("Id");

        return $UserIds;
    }

    /**
     * Get the XML representation for a field from a file with a given path.
     * @param string $Name Name of the field of which to fetch the XML
     *      representation.
     * @return string|null Returns the XML representation string or NULL if
     *      an error occurs.
     */
    protected function getFieldXml($Name)
    {
        $Path = dirname(__FILE__)."/".self::FIELD_XML_PATH."/".$Name.".xml";
        $Xml = @file_get_contents($Path);

        return $Xml !== false ? $Xml : null;
    }

    /**
     * Get the path to the default tags file.
     * @return string path to the default tags file
     */
    protected function getDefaultCategoriesFile(): string
    {
        $Path = dirname(__FILE__)."/install/".self::DEFAULT_CATEGORIES_CSV;

        return $Path;
    }

    /**
     * Insert a new nav item before another existing nav item.The new nav item
     * will be placed at the end of the list if the nav item it should be placed
     * before doesn't exist.
     * @param array $NavItems Existing nav items.
     * @param string $ItemLabel New nav item label.
     * @param string $ItemPage New nav item page.
     * @param string $Before Label of the existing item in front of which the new
     *      nav item should be placed.
     * @return array the nav items with the new nav item in place.
     */
    protected function insertNavItemBefore(
        array $NavItems,
        string $ItemLabel,
        string $ItemPage,
        string $Before
    ): array {
        $Result = [];

        foreach ($NavItems as $Label => $Page) {
            # if the new item should be inserted before this one
            if ($Label == $Before) {
                break;
            }

            # move the nav item to the results
            $Result[$Label] = $Page;
            unset($NavItems[$Label]);
        }

        # add the new item
        $Result[$ItemLabel] = $ItemPage;

        # add the remaining nav items, if any
        $Result = $Result + $NavItems;

        return $Result;
    }

    /**
     * Add pages that this plugin use to EditResourceComplete's
     * "AllowedAccessList", so EditResourceComplete can be redirected from these
     * pages.
     *
     * @param array $AccessList Previous Access List to add regexes to
     * @return array of regexes to match refers that are allowed
     */
    public function extendEditResourceCompleteAccessList(array $AccessList): array
    {
        array_push($AccessList, "/P=P_Blog_ListEntries/i");
        return ["AllowList" => $AccessList];
    }

    /**
     * Get the list of available blogs.
     * @return array of blog names keyed by BlogId.
     */
    public function getAvailableBlogs(): array
    {
        $Schema = new MetadataSchema($this->getSchemaId());
        $BlogNameField = $Schema->getField(self::BLOG_NAME_FIELD_NAME);

        return $BlogNameField->getPossibleValues();
    }

    /**
     * Create a new blog.
     * @param string $BlogName Name of the blog to create
     * @return int BlogId for the newly created blog
     */
    public function createBlog(string $BlogName): int
    {
        $Schema = new MetadataSchema($this->getSchemaId());
        $TypeField = $Schema->getField(self::BLOG_NAME_FIELD_NAME);

        $NewBlogCName = ControlledName::create($BlogName, $TypeField->id());

        return $NewBlogCName->id();
    }

    /**
     * Find the blog ID corresponding to a given BlogName, if one exists.
     * @param string $BlogName BlogName to search for
     * @return int|false Blog ID for the given name, if one exists.FALSE if it did not.
     */
    public function getBlogIdByName(string $BlogName)
    {
        $Schema = new MetadataSchema($this->getSchemaId());
        $TypeField = $Schema->getField(self::BLOG_NAME_FIELD_NAME);
        $CNFactory = new ControlledNameFactory($TypeField->id());

        return $CNFactory->getItemIdByName($BlogName);
    }

    /**
     * Delete a blog and all its entries.
     * @param int $BlogId BlogId to delete.
     */
    public function deleteBlog(int $BlogId): void
    {
        # do not attempt to delete blogs that do not exist
        if (!array_key_exists($BlogId, $this->getAvailableBlogs())) {
            throw new Exception(
                "Attempt to get/set setting for a blog that does not exist"
            );
        }

        # fetch all the entries for this blog
        $EntryFactory = new EntryFactory($BlogId);

        # delete all of them
        foreach ($EntryFactory->getItemIds() as $EntryId) {
            $TgtEntry = new Entry($EntryId);
            $TgtEntry->destroy();
        }

        # delete the CName we have for this blog
        $BlogCName = new ControlledName($BlogId);
        $BlogCName->destroy();

        # delete our copy of the blog settings
        $AllSettings = $this->getConfigSetting("BlogSettings");
        unset($AllSettings[$BlogId]);
        $this->setConfigSetting("BlogSettings", $AllSettings);
    }

    /**
     * Get/set all the settings for a particular blog.
     * @param int $BlogId BlogId on which to operate.
     * @param array $NewSettings Updated settings for the specified blog (OPTIONAL).
     * @return array All settings for the specified blog
     */
    public function blogSettings(int $BlogId, ?array $NewSettings = null): array
    {
        # do not attempt to manipulate settings for blogs that do not exist
        if (!array_key_exists($BlogId, $this->getAvailableBlogs())) {
            throw new Exception(
                "Attempt to get/set setting for a blog that does not exist"
            );
        }

        # pull out current settings
        $AllSettings = $this->getConfigSetting("BlogSettings");

        # use the defaults if we have no settings for this blog
        if (!array_key_exists($BlogId, $AllSettings)) {
            $AllSettings[$BlogId] = $this->getBlogConfigTemplate();
        }

        # when we're updating settings
        if ($NewSettings !== null) {
            # detect an update to the blog name, update the backing CName
            if ($AllSettings[$BlogId]["BlogName"] != $NewSettings["BlogName"]) {
                $CName = new ControlledName($BlogId);
                $CName->name($NewSettings["BlogName"]);
            }

            # check if clean url prefix is set, create one if not
            if (strlen(trim($NewSettings["CleanUrlPrefix"])) == 0) {
                # check if name is null in case created via blog config template
                if (!is_null($NewSettings["BlogName"])) {
                    $NewSettings["CleanUrlPrefix"] =
                    $this->generateCleanUrlPrefix($NewSettings["BlogName"]);
                }
            }

            # store the new setting
            $AllSettings[$BlogId] = $NewSettings;
            $this->setConfigSetting("BlogSettings", $AllSettings);
        }

        # return settings for this blog
        return $AllSettings[$BlogId];
    }

    /**
     * Get/set an individual setting for a specified blog.
     * @param int $BlogId BlogId to operate on.
     * @param string $SettingName Setting to retrieive/set.
     * @param mixed $NewValue New value (OPTIONAL)
     * @return mixed value of the requested setting
     */
    public function blogSetting($BlogId, $SettingName, $NewValue = null)
    {
        $MySettings = $this->blogSettings($BlogId);

        # look for changes to any of the values associated with this blog
        if ($NewValue !== null) {
            $MySettings[$SettingName] = $NewValue;
            $this->blogSettings($BlogId, $MySettings);
        }

        # return the requested setting
        return $MySettings[$SettingName] ;
    }

    /**
     * Fetch the description (in FormUI-compatible format) of settings each blog can have.
     */
    public function getBlogConfigOptions(): array
    {
        return $this->BlogConfigurationOptions;
    }

    /**
     * Get an array of expected settings for blogs
     * @return array of settings
     */
    public function getBlogConfigTemplate(): array
    {
        static $Result;

        if (!isset($Result)) {
            $Result = [];
            foreach ($this->BlogConfigurationOptions as $Name => $UISettings) {
                $Result[$Name] = isset($UISettings["Default"]) ?
                    $UISettings["Default"] : null ;
            }
        }

        return $Result;
    }

    /**
     * Insert a summary of couple blog entries from a specified blog.
     * @param string $PageName The name of the page that signaled the event.
     * @param string $Location Describes the location on the page where the insertion
     *        insertion point occurs.
     * @param array $Context Specify which blog and how many blogs to print
     */
    public function insertBlogSummary(
        string $PageName,
        string $Location,
        ?array $Context = null
    ): void {
        # if no context provided or no blog name provided, bail
        if (!is_array($Context) || !isset($Context["Blog Name"])) {
            return;
        }

        $DisplayBlog = $this->getBlogIdByName($Context["Blog Name"]);
        $DisplayCount = isset($Context["Max Entries"]) ? $Context["Max Entries"] : 2 ;

        # if the Blog Name is not valid, nothing to print
        if ($DisplayBlog === false) {
            print "<p class='blog-noentries'>No posts to display.</p>";
            return;
        }

        BlogEntryUI::printSummaryBlock($DisplayBlog, $DisplayCount);
    }

    /**
     * Get a configuration parameter for the currently selected blog.
     * @param string $SettingName Name of the setting to get
     * @return mixed setting value
     */
    private function getSettingForSelectedBlog(string $SettingName)
    {
        if (!isset($this->SelectedBlog)) {
            throw new Exception("Attempt to get a blog setting when no blog is selected");
        }

        return $this->blogSetting($this->SelectedBlog, $SettingName);
    }

    /**
     * Get a not-in-use clean URL prefix for a blog given a title
     * @param string $BlogName name of blog to get clean URL prefix for
     * @return string clean URL prefix
     */
    public function generateCleanUrlPrefix(string $BlogName): string
    {
        $CleanUrlPrefix = preg_replace(
            '/[^0-9a-z-]+/',
            "",
            strtolower(str_replace(" ", "-", $BlogName))
        );
        $BasePrefix = $CleanUrlPrefix;
        $Count = 2;

        $AF = ApplicationFramework::getInstance();
        while ($AF->cleanUrlIsMapped($CleanUrlPrefix)) {
            $CleanUrlPrefix = $BasePrefix."-".$Count;
            $Count++;
        }
        return $CleanUrlPrefix;
    }

    /**
     * Send email notifications about a blog entry to a specified list of
     * users.
     * @param Entry $Entry Entry to send notifications about.
     * @param array $UserIds Users to notify.
     * @return bool TRUE if notifications were sent, FALSE otherwise.
     */
    private function sendNotificationEmail(
        Entry $Entry,
        array $UserIds
    ): bool {
        if (!$this->notificationsCouldBeSent($Entry, User::getCurrentUser())) {
            return false;
        }

        $NotificationBlog = $this->getConfigSetting("EmailNotificationBlog");
        if ($Entry->getBlogId() != $NotificationBlog) {
            throw new Exception(
                "Attempt to send email notification for a blog entry "
                    ."that is not part of the Email Notification Blog."
            );
        }

        $this->setCurrentBlog($NotificationBlog);

        $MailerPlugin = Mailer::getInstance();

        # get additional data for emails
        $ExtraKeywords = $this->getExtraMailerKeywords($Entry);

        # send the notification e-mails using tasks
        $MailerPlugin->sendEmailUsingTasks(
            $this->getConfigSetting("NotificationTemplate"),
            $UserIds,
            $Entry,
            $ExtraKeywords
        );

        return true;
    }

    /**
     * Get additional tokens used by Mailer when sending notifications about
     * blog entries.
     * @param Entry $Entry Entry in use.
     * @return array Email keyword replacements, with keywords (without X-/-X) for
     *     the index, and replacement strings for the values.
     */
    private function getExtraMailerKeywords(Entry $Entry): array
    {
        $Schema = new MetadataSchema($this->getSchemaId());
        $BodyField = $Schema->getField(self::BODY_FIELD_NAME);

        # remove HTML from the teaser text if HTML is allowed in the body field
        if ($BodyField->allowHtml()) {
            $TeaserText = Email::convertHtmlToPlainText($Entry->teaser());
            $TeaserText = wordwrap($TeaserText, 78);
        # HTML isn't allowed so just use the teaser verbatim
        } else {
            $TeaserText = $Entry->teaser();
        }

        # the extra replacement tokens
        $ExtraKeywords = [
            "BLOG:UNSUBSCRIBE" => ApplicationFramework::baseUrl()
                    ."index.php?P=Preferences",
            "BLOG:BLOGNAME" => $this->blogSetting($Entry->getBlogId(), "BlogName"),
            "BLOG:BLOGDESCRIPTION" => $this->blogSetting(
                $Entry->getBlogId(),
                "BlogDescription"
            ),
            "BLOG:BLOGURL" => ApplicationFramework::baseUrl().$this->blogUrl(),
            "BLOG:ENTRYURL" => ApplicationFramework::baseUrl().$Entry->entryUrl(),
            "BLOG:ENTRYAUTHOR" => $Entry->authorForDisplay(),
            "BLOG:TEASER" => $Entry->teaser(),
            "BLOG:TEASERTEXT" => $TeaserText
        ];

        return $ExtraKeywords;
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

        # find the next double (or more) blank line
        preg_match(
            '/'.self::DOUBLE_BLANK_REGEX.'/',
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

        # now remove items that act as whitespace in HTML
        $Html = preg_replace('/^'.self::HTML_BLANK_REGEX.'+/', "", $Html);

        # do one last left trim
        $Html = ltrim($Html);

        # return the new HTML
        return $Html;
    }

    /**
     * Static validate function for CleanUrlPrefix
     * @param string $FieldName name of field
     * @param string $CleanPrefix clean prefix to validate
     * @param array $AllFieldValues all field values
     * @param int|string|null $BlogId ID of blog to validate for
     * @return string|null string on invalid clean url prefix, null otherwise
     */
    public static function validateCleanUrlPrefix(
        string $FieldName,
        string $CleanPrefix,
        array $AllFieldValues,
        $BlogId
    ) {
        # remove redundant whitespaces at the start and end of the clean url
        $CleanPrefix = trim($CleanPrefix);

        if ($CleanPrefix == "") {
            return null;
        }

        # check that the clean url doesn't contain white spaces
        if (preg_match("/\s/", $CleanPrefix)) {
            return "The Clean URL cannot contain whitespaces.";
        }

        if (!ApplicationFramework::getInstance()->cleanUrlIsMapped($CleanPrefix)) {
            return null;
        }

        if (Blog::getInstance()->blogSetting((int) $BlogId, "CleanUrlPrefix") == $CleanPrefix) {
            return null;
        }

        return "Clean URL <b>".$CleanPrefix."</b> is already in use.";
    }

    # parameters that we expect each blog to have
    private $BlogConfigurationOptions = [
        "BlogName" => [
            "Type" => "Text",
            "Label" => "Blog Name",
            "Help" => "The name of the blog.",
            "Required" => true
        ],
        "BlogDescription" => [
            "Type" => "Paragraph",
            "Label" => "Blog Description",
            "Help" => "A description of the blog."
        ],
        "EnableComments" => [
            "Type" => "Flag",
            "Label" => "Enable Comments",
            "Help" => "Enable user comments for blog entries.",
            "Default" => false
        ],
        "ShowAuthor" => [
            "Type" => "Flag",
            "Label" => "Show Author",
            "Help" => "Show the author of blog entries when displaying them.",
            "Default" => true
        ],
        "MaxTeaserLength" => [
            "Type" => "Number",
            "Label" => "Maximum Teaser Length",
            "Help" => "The maximum length of the teaser in number of characters.",
            "Default" => 1200
        ],
        "EntriesPerPage" => [
            "Type" => "Option",
            "Label" => "Entries Per Page",
            "Help" => "The number of blog entries to display in the blog at once.",
            "Options" => [
                5 => 5,
                10 => 10,
                25 => 25
            ],
            "Default" => 10
        ],
        "CleanUrlPrefix" => [
            "Type" => "Text",
            "Label" => "Clean URL Prefix",
            "Help" => "The prefix for the clean URLs for the blog.",
            "ValidateFunction" => ["Metavus\\Plugins\\Blog", "validateCleanUrlPrefix"]
        ],
        "NotificationLoginPrompt" => [
            "Type" => "Text",
            "Label" => "Notification Login Prompt",
            "Help" => "Text to display when asking users to log in so they can "
                ."subscribe to notifications.",
            "Default" => "Please log in to subscribe to notifications of new blog posts."
        ],
    ];

    private $SelectedBlog;
}
