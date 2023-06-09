<?PHP
#
#   FILE:  SiteUpgrade--4.1.2.php
#
#   Part of the Collection Workflow Integration System (CWIS)
#   Copyright 2019-2022 Edward Almasy and Internet Scout
#   http://scout.wisc.edu/cwis
#
# @scout:phpstan

namespace Metavus;

use Exception;
use Metavus\Plugins\Pages\Page;
use Metavus\Plugins\Pages\PageFactory;
use ScoutLib\ApplicationFramework;
use ScoutLib\Database;
use ScoutLib\ImageFile;

$GLOBALS["G_ErrMsgs"] = SiteUpgrade412_PerformUpgrade();

/**
 * Perform all site upgrades for 4.1.2
 * @return null|array Returns NULL on success or a list of error messages
 *      if an error occurs.
 */
function SiteUpgrade412_PerformUpgrade()
{
    try {
        SiteUpgrade412_CreateMailerTemplates();
        SiteUpgrade412_FixDateFields();
        SiteUpgrade412_PopulateFileChecksums();
        SiteUpgrade412_PopulateImageChecksums();
        SiteUpgrade412_FixUserFieldPerms();
        SiteUpgrade412_AddAboutPage();
        SiteUpgrade412_FixParagraphStorage();
        SiteUpgrade412_FixRecordsTable();
        SiteUpgrade412_FixPluginCfg();
        SiteUpgrade412_FixSPSFields();
        SiteUpgrade412_MigrateImages();
        SiteUpgrade412_UpdateCollectionsSchema();
        SiteUpgrade412_PopulateImageLengths();
        SiteUpgrade412_CleanPluginInfo();
        SiteUpgrade412_FixUserRecordCreationDates();
        SiteUpgrade412_FixSearchWordCounts();
    } catch (Exception $Exception) {
        return array(
            $Exception->getMessage(),
            "Exception Trace:<br/><pre>"
            .$Exception->getTraceAsString()."</pre>"
        );
    }
    return null;
}

/**
 * Create new mailer templates for password changes, email changes, and account activation
 */
function SiteUpgrade412_CreateMailerTemplates(): void
{
    $DB = new Database();

    # determine if this operation has been done by checking if one of the old
    # templates has been dropped from the table already
    if (!$DB->fieldExists("SystemConfiguration", "MailChangeMailBody")) {
        return;
    }

    $Mailer = $GLOBALS["G_PluginManager"]->getPlugin("Mailer");
    $MailerTemplates = $Mailer->getTemplateList();

    # set up missing templates
    $TemplatesNeeded = [
        "Email Change Default" => [
            "Column" => "MailChangeMail",
            "SettingName" => "EmailChangeTemplateId",
            "Msg" => "Adding email template for changing account emails."
        ],
        "Account Activation Default" => [
            "Column" => "PasswordMail",
            "SettingName" => "ActivateAccountTemplateId",
            "Msg" => "Adding email template for activating accounts."
        ],
        "Password Change Default" => [
            "Column" => "PasswordResetMail",
            "SettingName" => "PasswordChangeTemplateId",
            "Msg" => "Adding email template for changing passwords."
        ]
    ];

    $IntConfig = InterfaceConfiguration::getInstance();
    foreach ($TemplatesNeeded as $Name => $Template) {
        if (!in_array($Name, $MailerTemplates)) {
            $GLOBALS["G_MsgFunc"](1, $Template["Msg"]);
            $Subject = $DB->queryValue(
                "SELECT ".$Template["Column"]."Subject FROM SystemConfiguration",
                $Template["Column"]."Subject"
            );
            $Body = $DB->queryValue(
                "SELECT ".$Template["Column"]."Body FROM SystemConfiguration",
                $Template["Column"]."Body"
            );
            $BodyWithHTMLBreaks = nl2br($Body);
            $SettingName = $Template["SettingName"];
            $TemplateId = $Mailer->addTemplate(
                $Name,
                "X-PORTALNAME-X <X-ADMINEMAIL-X>",
                $Subject,
                $BodyWithHTMLBreaks,
                "",
                $Body,
                ""
            );
            $IntConfig->setInt($SettingName, $TemplateId);
        }
    }

    # drop unnecessary columns for holding old templates
    $Columns = [
        "MailChangeMailBody",
        "MailChangeMailSubject",
        "PasswordMailBody",
        "PasswordMailSubject",
        "PasswordResetMailBody",
        "PasswordResetMailSubject",
    ];
    foreach ($Columns as $Column) {
        $DB->query("ALTER TABLE SystemConfiguration DROP COLUMN ".$Column);
    }
}

/**
 * Fix date columns that contain "0000-00-00" instead of NULL.
 */
function SiteUpgrade412_FixDateFields(): void
{
    $DB = new Database();

    # foreach column in Records
    $Columns = $DB->getColumns("Records");
    foreach ($Columns as $Column) {
        # if this is a date column
        $Type = $DB->getFieldType("Records", $Column);
        if ($Type == "date") {
            # update any stored "0000-00-00" to NULL
            $DB->query(
                "UPDATE Records SET `".$Column."` = NULL WHERE `".$Column."` = '0000-00-00'"
            );

            # and if necessary, fix the default
            $Default = $DB->query("SHOW COLUMNS FROM Records WHERE Field='".$Column."'", "Default");
            if ($Default == "0000-00-00") {
                $DB->query(
                    "ALTER TABLE Records CHANGE COLUMN `".$Column
                    ."` `".$Column."` DATE DEFAULT NULL"
                );
            }
        }
    }
}

/**
 * Ensure files have a checksum.
 */
function SiteUpgrade412_PopulateFileChecksums(): void
{
    $DB = new Database();
    $DB->query("SELECT FileId FROM Files WHERE FileChecksum IS NULL");
    $FileIds = $DB->fetchColumn("FileId");

    foreach ($FileIds as $FileId) {
        $GLOBALS["AF"]->queueUniqueTask(
            "\\Metavus\\File::callMethod",
            [$FileId, "populateChecksum"],
            \ScoutLib\ApplicationFramework::PRIORITY_LOW,
            "Populate checksum for File Id ".$FileId
        );
    }
}

/**
 * Ensure images have a checksum.
 */
function SiteUpgrade412_PopulateImageChecksums(): void
{
    $DB = new Database();
    $DB->query("SELECT ImageId FROM Images WHERE FileChecksum IS NULL");
    $ImageIds = $DB->fetchColumn("ImageId");

    foreach ($ImageIds as $ImageId) {
        $GLOBALS["AF"]->queueUniqueTask(
            "\\Metavus\\Image::callMethod",
            [$ImageId, "populateChecksum"],
            \ScoutLib\ApplicationFramework::PRIORITY_LOW,
            "Populate checksum for Image Id ".$ImageId
        );
    }
}

/**
 * Ensure images have a length.
 */
function SiteUpgrade412_PopulateImageLengths(): void
{
    $DB = new Database();
    $DB->query("SELECT ImageId FROM Images WHERE FileLength = 0");
    $ImageIds = $DB->fetchColumn("ImageId");

    foreach ($ImageIds as $ImageId) {
        $GLOBALS["AF"]->queueUniqueTask(
            "\\Metavus\\Image::callMethod",
            [$ImageId, "populateLength"],
            \ScoutLib\ApplicationFramework::PRIORITY_LOW,
            "Populate length for Image Id ".$ImageId
        );
    }
}

/**
 * Fix permissions in user schema to ensure that administrative fields 1) have
 * Editable = false, and 2) require PRIV_SYSADMIN or PRIV_USERADMIN to edit if
 * Editable is ever toggled to true.
 */
function SiteUpgrade412_FixUserFieldPerms(): void
{
    $Schema = new \Metavus\MetadataSchema(
        \Metavus\MetadataSchema::SCHEMAID_USER
    );

    $FieldNames = [
        "CreationDate",
        "Has No Password",
    ];

    foreach ($FieldNames as $FieldName) {
        $Field = $Schema->getField($FieldName);
        $Field->editable(false);

        if ($Field->editingPrivileges()->comparisonCount() == 0) {
            $Privs = new PrivilegeSet();
            $Privs->usesAndLogic(false);
            $Privs->addPrivilege(PRIV_SYSADMIN);
            $Privs->addPrivilege(PRIV_USERADMIN);

            $Field->editingPrivileges($Privs);
        }
    }
}

/**
 * Move old about page information into a Pages/Page
 */
function SiteUpgrade412_AddAboutPage(): void
{
    $DB = new Database();

    # if migration was already performed, nothing to do
    if (!$DB->fieldExists("SystemConfiguration", "AboutText")) {
        return;
    }

    # get current about text
    $DB = new Database();
    $AboutText = $DB->queryValue("SELECT AboutText FROM SystemConfiguration", "AboutText");

    # trim whitespace
    if (is_string($AboutText)) {
        $AboutText = trim($AboutText);
    }

    # if we have any about text, migrate it
    if (!empty($AboutText)) {
        $PagesEnabled = $GLOBALS["G_PluginManager"]->pluginEnabled("Pages");

        # if pages wasn't enabled, force reloading of all plugins so that we will
        # be able to access it
        if (!$PagesEnabled) {
            $GLOBALS["G_PluginManager"]->loadPlugins(true);
        }

        # get the Pages plugin
        $Plugin = $GLOBALS["G_PluginManager"]->getPlugin(
            "Pages",
            true
        );

        # stash our about text in a plugin config setting that the plugin can
        # then look for
        $Plugin->configSetting(
            "MigratedAboutContent",
            "<h1>About ".InterfaceConfiguration::getInstance()->getString("PortalName")
                    ."</h1>\n".$AboutText
        );

        # log a warning if the site admin will need to do something
        if (!$PagesEnabled) {
            $GLOBALS["AF"]->logError(
                ApplicationFramework::LOGLVL_WARNING,
                "Your about page has been moved to the Pages plugin. "
                ."Enable the Pages plugin in order to access it."
            );
        }
    }

    # remove the about text column from SystemConfiguration
    $DB->query("ALTER TABLE SystemConfiguration DROP COLUMN AboutText");
}

/**
 * Expand any paragraph fields using TEXT columns to MEDIUMTEXT
 */
function SiteUpgrade412_FixParagraphStorage(): void
{
    $ParagraphCols = [];

    $AllSchemas = \Metavus\MetadataSchema::getAllSchemas();
    foreach ($AllSchemas as $Schema) {
        $SchemaFields = $Schema->getFields(
            \Metavus\MetadataSchema::MDFTYPE_PARAGRAPH
        );
        foreach ($SchemaFields as $FieldId => $Field) {
            $ParagraphCols[$FieldId] = $Field->dBFieldName();
        }
    }

    $DB = new \ScoutLib\Database();
    foreach ($ParagraphCols as $Col) {
        $ColType = $DB->getFieldType("Records", $Col);
        if ($ColType == "text") {
            $DB->query(
                "ALTER TABLE Records CHANGE `".$Col."` "
                ."`".$Col."` MEDIUMTEXT DEFAULT NULL"
            );
        }
    }
}

/**
 * Fix columns and values in Records table as follows:
 * - Allow NULL values on all columns so that MySQL 8 will not scream at us
 *      when we do an INSERT that does not specify values for everything
 *      (e.g., in  Record::create()).
 * - Fix any illegal ("0000-00-00 00:00:00) values in DATETIME columns,
 *      that may prevent modification of Records table in MySQL 5.7+.
 *      (e.g. by MetadataField::addDatabaseFields()).
 */
function SiteUpgrade412_FixRecordsTable(): void
{
    $DB = new \ScoutLib\Database();

    # adjust SQL mode to avoid complaints about invalid data
    # in existing columns preventing us from changing anything
    $DB->query("SET sql_mode=''");

    # iterate over all the cols in the records table
    $DB->query("SHOW COLUMNS FROM Records");
    $Rows = $DB->fetchRows();
    foreach ($Rows as $Row) {
        $FieldName = $Row["Field"];
        $FieldType = $Row["Type"];

        # adjust column to allow and default to null
        # (We can't just check the 'Null' column of the description
        #  because that will be 'Yes' for cols that allow null but have
        #  no default value. The 'Default' column does not help us here
        #  because it will say 'NULL' both for cols that default to null and
        #  for those that lack a default.
        #  See https://dev.mysql.com/doc/refman/8.0/en/show-columns.html for details)
        $DB->query("ALTER TABLE Records CHANGE COLUMN ".$FieldName
                   ." ".$FieldName." ".$FieldType." DEFAULT NULL");

        # clean up bad values if column is DATETIME
        if ($FieldType == "datetime") {
            $DB->query("UPDATE Records SET ".$FieldName." = NULL"
                    ." WHERE ".$FieldName." < '0000-01-01 00:00:00'");
        }

        # clean up bad values if column is DATE
        if ($FieldType == "date") {
            # (for dates with month and day set to zero)
            $DB->query("UPDATE Records"
                    ." SET ".$FieldName." = STR_TO_DATE("
                            ."CONCAT("
                                    ."YEAR(".$FieldName."),"
                                    ." '-1-1'),"
                            ." '%Y-%m-%d')"
                    ." WHERE MONTH(".$FieldName.") = '0'"
                            ." AND DAY(".$FieldName.") = '0'");
            # (for dates with just day set to zero)
            $DB->query("UPDATE Records"
                    ." SET ".$FieldName." = STR_TO_DATE("
                            ."CONCAT("
                                    ."YEAR(".$FieldName."),"
                                    ." '-',"
                                    ." MONTH(".$FieldName."),"
                                    ." '-1'),"
                            ." '%Y-%m-%d')"
                    ." WHERE DAY(".$FieldName.") = '0'");
        }
    }
}

/**
 * Convert the Cfg column in the PluginInfo table to BLOB while preserving the
 * data it contains. If the serialized data contains multibyte UTF-8
 * characters then converting it to BLOB will start to return the underlying
 * bytes (e.g., the left-hand curly quote U+201F will go from being 0x201F to
 * 0xE2 0x80 0x9F, which then breaks unserialize() because the number of
 * characters is no longer correct).
 */
function SiteUpgrade412_FixPluginCfg(): void
{
    $DB = new \ScoutLib\Database();
    $AF = ApplicationFramework::getInstance();
    $ColType = $DB->getFieldType("PluginInfo", "Cfg");

    # bail of the conversion was already done
    if ($ColType == "mediumblob") {
        return;
    }

    # otherwise, grab both AF and MySQL locks
    # (with the AF lock a defensive measure in case PluginInfo is using
    #  InnoDB. cf. https://dev.mysql.com/doc/refman/8.0/en/alter-table-problems.html )
    $AF->getLock(__FUNCTION__);
    $DB->query("LOCK TABLES PluginInfo");

    $DB->query(
        "SELECT PluginId, Cfg FROM PluginInfo"
    );
    $CfgData = $DB->fetchColumn("Cfg", "PluginId");

    $DB->query(
        "ALTER TABLE PluginInfo CHANGE COLUMN Cfg Cfg MEDIUMBLOB"
    );

    foreach ($CfgData as $PluginId => $Cfg) {
        $DB->query(
            "UPDATE PluginInfo SET Cfg = '".addslashes($Cfg)."' "
            ."WHERE PluginId = ".$PluginId
        );
    }
    $DB->query("UNLOCK TABLES");
    $AF->releaseLock(__FUNCTION__);
}

/**
 * Convert DB columns for SearchParameterSet fields to BLOB storage
 */
function SiteUpgrade412_FixSPSFields(): void
{
    $Cols = [];

    $AllSchemas = \Metavus\MetadataSchema::getAllSchemas();
    foreach ($AllSchemas as $Schema) {
        $SchemaFields = $Schema->getFields(
            \Metavus\MetadataSchema::MDFTYPE_SEARCHPARAMETERSET
        );
        foreach ($SchemaFields as $FieldId => $Field) {
            $Cols[$FieldId] = $Field->dBFieldName();
        }
    }

    $DB = new \ScoutLib\Database();
    foreach ($Cols as $Col) {
        $ColType = $DB->getFieldType("Records", $Col);
        if ($ColType == "blob") {
            continue;
        }

        $DB->query(
            "ALTER TABLE Records CHANGE `".$Col."` "
            ."`".$Col."` BLOB DEFAULT NULL"
        );
    }
}

/**
 * Migration of images from the RecordImageInts table into the Images table.
 */
function SiteUpgrade412_MigrateImages() : void
{
    $DB = new Database();

    # nothing to do if migration has already been performed
    if (!$DB->tableExists("RecordImageInts")) {
        return;
    }

    # fetch information for all images
    $Images = [];
    $DB->query("SELECT * FROM RecordImageInts");
    foreach ($DB->fetchRows() as $Row) {
        # skip invalid images
        if (!Image::itemExists($Row["ImageId"])) {
            continue;
        }

        $Images[$Row["ImageId"]][] = [
            "ItemId" => $Row["RecordId"],
            "FieldId" => $Row["FieldId"],
        ];
    }

    # iterate over all the images
    foreach ($Images as $ImageId => $Associations) {
        # copy the first association into the Images table
        $Association = array_shift($Associations);
        $DB->query(
            "UPDATE Images"
            ." SET ItemId = ".$Association["ItemId"]
            ." , FieldId = ".$Association["FieldId"]
            ." WHERE ImageId = ".$ImageId
        );

        # if there are no more associations, nothing else to do
        if (count($Associations) == 0) {
            continue;
        }

        # for any additional associations, create new images
        # by copying the original image
        $SrcImage = new Image($ImageId);
        while (count($Associations) > 0) {
            $Association = array_shift($Associations);

            if ($SrcImage->fileExists()) {
                $DstImage = $SrcImage->duplicate();
            } else {
                $DB->query(
                    "INSERT INTO Images (Format) VALUES ('".$SrcImage->format()."')"
                );
                $ImageId = $DB->getLastInsertId();
                $DstImage = new Image($ImageId);
            }

            $DB->query(
                "UPDATE Images"
                ." SET ItemId = ".$Association["ItemId"]
                ." , FieldId = ".$Association["FieldId"]
                ." WHERE ImageId = ".$DstImage->id()
            );
        }
    }

    # remove the now unnecessary intersection table
    $DB->query("DROP TABLE RecordImageInts");

    $AF = ApplicationFramework::getInstance();

    # delete all the image symlinks; they are now invalid
    $AF->queueUniqueTask(
        "\\Metavus\\ImageFactory::deleteAllImageSymlinks",
        [],
        \ScoutLib\ApplicationFramework::PRIORITY_LOW,
        "Delete all image symlinks from Record::IMAGE_CACHE_PATH"
    );

    # clean up the tho old scaled images
    foreach (array_keys($Images) as $ImageId) {
        $AF->queueUniqueTask(
            "\\Metavus\\Image::callMethod",
            [$ImageId, "deleteLegacyScaledImages"],
            \ScoutLib\ApplicationFramework::PRIORITY_LOW,
            "Delete legacy scaled images for Image Id ".$ImageId
        );
    }
}

/**
 * Make sure Collections schema contains all expected fields.
 */
function SiteUpgrade412_UpdateCollectionsSchema(): void
{
    $SchemaId = MetadataSchema::getSchemaIdForName("Collections");
    if ($SchemaId === null) {
        $CollectionSchema = MetadataSchema::create("Collections");
    } else {
        $CollectionSchema = new MetadataSchema($SchemaId);
    }
    $Result = $CollectionSchema->addFieldsFromXmlFile(
        "install/MetadataSchema--Collection.xml"
    );
    if ($Result === false) {
        $SchemaErrors = $CollectionSchema->errorMessages("addFieldsFromXmlFile");
        foreach ($SchemaErrors as $ErrMsg) {
            $GLOBALS["G_MsgFunc"](1, "Collections schema update error: ".$ErrMsg);
        }
    }
}

/**
 * Remove redundant rows from the PluginInfo table.
 */
function SiteUpgrade412_CleanPluginInfo(): void
{
    $DB = new Database();

    $DB->query("LOCK TABLES PluginInfo");

    $DB->query(
        "SELECT GROUP_CONCAT(PluginId) AS Ids"
        ." FROM PluginInfo GROUP BY BaseName"
        ." HAVING COUNT(BaseName) > 1"
    );
    foreach ($DB->fetchColumn("Ids") as $IdList) {
        $Ids = explode(",", $IdList);

        # put the Ids in ascending order, drop the first one so that we just
        # have the redundant entries
        sort($Ids, SORT_NUMERIC);
        array_shift($Ids);

        $DB->query(
            "DELETE FROM PluginInfo WHERE "
            ."PluginId IN (".implode(",", $Ids).")"
        );
    }

    $DB->query("UNLOCK TABLES");
}

/**
 * Update the user record creation date field (where empty)
 * to reflect the correct user creation date from APUsers table.
 */
function SiteUpgrade412_FixUserRecordCreationDates(): void
{
    # get list of all user ids and creation dates
    $DB = new Database();
    $DB->query("SELECT `UserId`, `CreationDate` FROM `APUsers`");
    $CreationDates = $DB->fetchColumn("CreationDate", "UserId");

    # set the CreationDate Field for each user record only if it's empty
    foreach ($CreationDates as $UserId => $CreationDate) {
        $User = new User($UserId);
        # we want to skip all the user records where the creation date is already set
        if ($User->getResource()->fieldIsSet("CreationDate")) {
            continue;
        }
        $User->set("CreationDate", $CreationDate);
    }
}

/**
 * Add unique index to SearchWordCounts table if necessary and de-duplicate
 * existing data.
 */
function SiteUpgrade412_FixSearchWordCounts(): void
{
    $DB = new Database();

    # nothing to do if the index we want already exists
    $DB->query("SHOW INDEX FROM SearchWordCounts WHERE Key_name = 'UIndex_WFI'");
    if ($DB->numRowsSelected() > 0) {
        return;
    }

    # snag a lock
    $AF = ApplicationFramework::getInstance();
    $AF->getLock(__FUNCTION__);

    # see if another thread has already created a _New version of the SWC table.
    # if so, that thread is handling the update
    if ($DB->tableExists("SearchWordCounts_New")) {
        $AF->releaseLock(__FUNCTION__);
        return;
    }

    # if the table didn't exist, create it and then release our lock
    $DB->query(
        "CREATE TABLE IF NOT EXISTS SearchWordCounts_New ("
        ."  WordId          INT NOT NULL,"
        ."  ItemId          INT NOT NULL,"
        ."  FieldId         SMALLINT NOT NULL,"
        ."  Count           SMALLINT,"
        ."  INDEX           Index_I (ItemId),"
        ."  UNIQUE          UIndex_WFI (WordId, FieldId, ItemId)"
        .")"
    );
    $AF->releaseLock(__FUNCTION__);

    # extend max execution time
    set_time_limit(3600);

    # migrate data from the old table to the new one, ignoring duplicates
    $DB->query("INSERT IGNORE INTO SearchWordCounts_New SELECT * FROM SearchWordCounts");

    # move the new table into place
    $DB->query(
        "RENAME TABLE SearchWordCounts TO SearchWordCounts_Old, "
        ."  SearchWordCounts_New TO SearchWordCounts"
    );

    # and drop the old one
    $DB->query("DROP TABLE SearchWordCounts_Old");
}
