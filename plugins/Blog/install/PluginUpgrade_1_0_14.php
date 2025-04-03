<?PHP
#
#   FILE:  PluginUpgrade_1_0_14.php (Blog plugin)
#
#   A plugin upgrade file for the Metavus digital collections platform
#   Copyright 2024 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins\Blog;

use Metavus\MetadataSchema;
use Metavus\Plugins\Blog;
use Metavus\Record;
use Metavus\RecordFactory;
use ScoutLib\Database;
use ScoutLib\PluginUpgrade;

/**
 * Class for upgrading the Blog plugin to version 1.0.14.
 */
class PluginUpgrade_1_0_14 extends PluginUpgrade
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Perform actions necessary to upgrade plugin to version 1.0.14.
     * @return null|string Return NULL if upgrade succeeeded, or string
     *      containing error message if upgrade failed.
     */
    public function performUpgrade()
    {
        $Plugin = Blog::getInstance(true);
        $Schema = new MetadataSchema($Plugin->getSchemaId());
        # add new blog name field
        if ($Schema->addFieldsFromXmlFile(
            "plugins/".$Plugin->getBaseName()."/install/MetadataSchema--"
            .$Plugin->getBaseName().".xml"
        ) === false) {
            return "Error loading Blog metadata fields from XML: ".implode(
                " ",
                $Schema->errorMessages("AddFieldsFromXmlFile")
            );
        }

        # create ConfigSetting "BlogSettings"
        $Plugin->setConfigSetting("BlogSettings", []);

        # convert current blog to a newly created blog
        $DefaultBlogId = $Plugin->createBlog($Plugin->getConfigSetting("BlogName"));
        $Plugin->blogSettings($DefaultBlogId, $Plugin->getBlogConfigTemplate());

        # copy the plugin ConfigSettings that should apply to the default blog
        # into blog settings instead
        foreach ([
            "BlogName",
            "BlogDescription",
            "EnableComments",
            "ShowAuthor",
            "MaxTeaserLength",
            "EntriesPerPage",
            "CleanUrlPrefix",
            "NotificationLoginPrompt"
        ] as $ToMigrate) {
            $Plugin->blogSetting(
                $DefaultBlogId,
                $ToMigrate,
                $Plugin->getConfigSetting($ToMigrate)
            );
        }

        # upgrade current blog entries to include Blog Name field
        $BlogNameToSet = [];
        $BlogNameToSet[$DefaultBlogId] = 1;

        $Factory = new RecordFactory($Plugin->getSchemaId());
        foreach ($Factory->getItemIds() as $Id) {
            $BlogEntry = new Entry($Id);
            $BlogEntry->set($Plugin::BLOG_NAME_FIELD_NAME, $BlogNameToSet);
        }

        # Create a new Blog for news
        $NewsBlogId = $Plugin->createBlog("News");
        $Plugin->blogSettings($NewsBlogId, $Plugin->getBlogConfigTemplate());
        $Plugin->blogSetting($NewsBlogId, "BlogName", "News");

        # Migrate Announcements into Blog
        $BlogNameToSet = [];
        $BlogNameToSet[$NewsBlogId] = 1;

        $DB = new Database();

        # Find an admin user to be the author/editor of these announcements
        $UserId = $DB->queryValue(
            "SELECT MIN(UserId) AS UserId FROM APUserPrivileges "
            ."WHERE Privilege=".PRIV_SYSADMIN,
            "UserId"
        );

        $DB->query("SELECT * FROM Announcements");
        while (false !== ($Record = $DB->fetchRow())) {
            $ToSet = [
                $Plugin::TITLE_FIELD_NAME => $Record["AnnouncementHeading"],
                $Plugin::BODY_FIELD_NAME => $Record["AnnouncementText"],
                $Plugin::PUBLICATION_DATE_FIELD_NAME => $Record["DatePosted"],
                $Plugin::MODIFICATION_DATE_FIELD_NAME => $Record["DatePosted"],
                $Plugin::CREATION_DATE_FIELD_NAME => $Record["DatePosted"],
                $Plugin::BLOG_NAME_FIELD_NAME => $BlogNameToSet,
                $Plugin::AUTHOR_FIELD_NAME => $UserId
            ];

            $BlogNews = Record::create($Plugin->getSchemaId());
            foreach ($ToSet as $FieldName => $Value) {
                $BlogNews->set($FieldName, $Value);
            }
            $BlogNews->isTempRecord(false);

            # needs to be updated after resource addition so that
            #  event hooks won't modify it
            $BlogNews->set($Plugin::EDITOR_FIELD_NAME, $UserId);
        }

        # drop the Announcements table
        if (false === $DB->query("DROP TABLE IF EXISTS Announcements")) {
            return "Could not drop the old news table.";
        }
        return null;
    }

    # ---- PRIVATE INTERFACE -------------------------------------------------
}
