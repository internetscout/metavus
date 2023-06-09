<?PHP
#
#   FILE:  UrlChecker.php
#
#   A plugin for the Metavus digital collections platform
#   Copyright 2011-2023 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins;

use Exception;
use Metavus\FormUI;
use Metavus\MetadataField;
use Metavus\MetadataSchema;
use Metavus\Plugin;
use Metavus\Plugins\UrlChecker\Constraint;
use Metavus\Plugins\UrlChecker\ConstraintList;
use Metavus\Plugins\UrlChecker\HttpInfo;
use Metavus\Plugins\UrlChecker\InvalidUrl;
use Metavus\Plugins\UrlChecker\Record;
use Metavus\Plugins\UrlChecker\StatusLine;
use Metavus\User;
use ScoutLib\ApplicationFramework;
use ScoutLib\Database;
use ScoutLib\PluginCaller;
use ScoutLib\StdLib;

/**
 * Plugin to validate URL field values.
 */
class UrlChecker extends Plugin
{
    /**
     * @const FLAG_OFF_VALUE value used by the Resource class when a flag is off
     */
    protected const FLAG_OFF_VALUE = 0;

    /**
     * @const FLAG_ON_VALUE value used by the Resource class when a flag is on
     */
    protected const FLAG_ON_VALUE = 1;

    /**
     * The timeout value in seconds for URL checking connections.
     */
    private const CONNECTION_TIMEOUT = 5.0;

    /**
     * How long to wait between checking for URLs when there was nothing to
     * check (minutes).
     */
    private const RETRY_TIME_NOTHING_TO_CHECK = 60;

    /**
     * How long to wait for currently queued checks to finish before checking
     * for new URLs (minutes).
     */
    private const RETRY_TIME_CHECKING = 5;

    # ---- STANDARD PLUGIN INTERFACE -----------------------------------------

    /**
     * Register information about this plugin.
     */
    public function register()
    {
        $this->Name = "URL Checker";
        $this->Version = "2.1.24";
        $this->Description =
            "Periodically validates URL field values."
            ."<i>System Administrator</i> or <i>Collection Administrator</i> privilege "
            ."is required to view the results.";
        $this->Author = "Internet Scout";
        $this->Url = "http://scout.wisc.edu/cwis/";
        $this->Email = "scout@scout.wisc.edu";
        $this->Requires = ["MetavusCore" => "1.0.0"];
        $this->EnabledByDefault = false;

        $this->CfgSetup["TaskPriority"] = [
            "Type" => "Option",
            "Label" => "Task Priority",
            "Help" => "Priority of the URL checking tasks in the task queue.",
            "AllowMultiple" => false,
            "Options" => [
                ApplicationFramework::PRIORITY_BACKGROUND => "Background",
                ApplicationFramework::PRIORITY_LOW => "Low",
                ApplicationFramework::PRIORITY_MEDIUM => "Medium",
                ApplicationFramework::PRIORITY_HIGH => "High"
            ],
            "Default" => ApplicationFramework::PRIORITY_BACKGROUND,
        ];

        $this->CfgSetup["FieldsToCheck"] = [
            "Type" => "Option",
            "Label" => "Checked Fields",
            "Help" => "Check links in the selected URL fields.",
            "AllowMultiple" => true,
            "OptionsFunction" => [$this, "getFieldsToCheckOptionValues"],
        ];

        $this->CfgSetup["DontCheck"] = [
            "Type" => "Option",
            "Label" => "Exclusion Conditions",
            "Help" => "Don't check the URLs of resources matching any of "
                    ."these conditions.",
            "AllowMultiple" => true,
            "OptionsFunction" => [$this, "getRuleOptionValues"],
        ];

        $this->CfgSetup["VerifySSLCerts"] = [
            "Type" => "Flag",
            "Label" => "Verify SSL Certificates",
            "Help" => "Perform SSL certificate verification when connecting to "
                ."https sites.If outbound SSL connections are not working "
                ."correctly on your server for some reason (e.g.list of root "
                ."CAs is not current), then disabling may avoid spurious "
                ."'Could Not Connect' errors for https sites.",
            "Default" => true,
        ];

        $this->CfgSetup["EnableDeveloper"] = [
            "Type" => "Flag",
            "Label" => "Enable Developer Interface",
            "Help" => "Enable an additional developer interface "
                ."to aid in debugging the plugin.",
            "Default" => false,
        ];

        $this->CfgSetup["EnableChecking"] = [
            "Type" => FormUI::FTYPE_FLAG,
            "Label" => "Enable URL checks",
            "Help" => "Enable automatic URL checking.",
            "Default" => 1,
        ];

        $this->CfgSetup["NumToCheck"] = [
            "Type" => "Number",
            "Label" => "Resources to Check",
            "Help" => "The number of resources to include in a batch of checks",
            "Default" => 250,
        ];

        $this->CfgSetup["CheckDelay"] = [
            "Type" => "Number",
            "Label" => "Check Delay",
            "Help" => "The number of minutes between tasks that start batches"
                ." of URL checks.If the previous batch has not finished, nothing"
                ." new will be queued.If no URLs need to be rechecked, nothing"
                ." new will be queued.",
            "Default" => 15,
        ];

        $this->CfgSetup["InvalidationThreshold"] = [
            "Type" => "Number",
            "Label" => "Invalidation Threshold",
            "Help" => "The number of times a URL check must fail before "
                ."the link is considered invalid.",
            "MinVal" => 1,
            "Default" => 4,
        ];

        $this->CfgSetup["ResourceRecheckTime"] = [
            "Type" => "Number",
            "Label" => "Resource Recheck Time",
            "Help" => "How often to check resources for new URLs.",
            "MinVal" => 1,
            "Default" => 1,
            "Units" => "days",
        ];

        $this->CfgSetup["ValidUrlRecheckTime"] = [
            "Type" => "Number",
            "Label" => "Valid URL Recheck Time",
            "Help" => "How long to wait between checks of a URL that "
                ."is considered valid",
            "MinVal" => 1,
            "Default" => 1,
            "Units" => "days",
        ];

        $this->CfgSetup["InvalidUrlRecheckTime"] = [
            "Type" => "Number",
            "Label" => "Invalid URL Recheck Time",
            "Help" => "How long to wait between checks of URL that "
                ."is considered invalid",
            "MinVal" => 1,
            "Default" => 7,
            "Units" => "days",
        ];
    }

    /**
     * Create the database tables necessary to use this plugin.
     * @return string|null NULL if everything went OK or an error message otherwise
     */
    public function install()
    {
        $Result = $this->createTables($this->SqlTables);
        if (!is_null($Result)) {
            return $Result;
        }

        # set default settings
        $this->configSetting("NextNormalUrlCheck", 0);
        $this->configSetting("NextInvalidUrlCheck", 0);

        # default to checking all URL fields
        $FieldsToCheck = [];
        foreach (MetadataSchema::getAllSchemas() as $Schema) {
            $UrlFields = $Schema->getFields(MetadataSchema::MDFTYPE_URL);
            foreach ($UrlFields as $Field) {
                $FieldsToCheck[] = $Field->id();
            }
        }
        $this->configSetting("FieldsToCheck", $FieldsToCheck);

        # set up default release/withhold/autofix actions
        $this->configureDefaultActions();

        return null;
    }

    /**
     * Uninstall the plugin.
     * @return NULL|string NULL if successful or an error message otherwise
     */
    public function uninstall()
    {
        return $this->dropTables($this->SqlTables);
    }

    /**
     * Upgrade from a previous version.
     * @param string $PreviousVersion Previous version number.
     */
    public function upgrade(string $PreviousVersion)
    {
        # upgrade from versions < 2.0.0 to 2.0.0
        if (version_compare($PreviousVersion, "2.0.0", "<")) {
            $DB = new Database();

            // make the upgrade process fault tolerant
            $DB->setQueryErrorsToIgnore([
                '/ALTER\s+TABLE\s+[^\s]+\s+CHANGE\s+.+/i'
                  => '/(Unknown\s+column\s+[^\s]+\s+in\s+[^\s]+|'
                     .'Table\s+[^\s]+\s+doesn\'t\s+exist)/i',
                '/ALTER\s+TABLE\s+[^\s]+\s+ADD\s+.+/i'
                  => '/(Duplicate\s+column\s+name\s+[^\s]+|'
                     .'/Table\s+[^\s]+\s+doesn\'t\s+exist)/i',
                '/RENAME\s+TABLE\s+[^\s]+\s+TO\s+[^\s]+/i'
                  => '/Table\s+[^\s]+\s+already\s+exists/i',
                '/CREATE\s+TABLE\s+[^\s]+\s+\([^)]+\)/i'
                  => '/Table\s+[^\s]+\s+already\s+exists/i'
            ]);

            # rename columns
            if (false === $DB->query("ALTER TABLE UrlChecker_Failures
                CHANGE DateChecked CheckDate TIMESTAMP")) {
                return "Could not update the URL history CheckDate column";
            }
            if (false === $DB->query("ALTER TABLE UrlChecker_Failures
                CHANGE TimesFailed TimesInvalid INT")) {
                return "Could not update the TimesInvalid column";
            }
            if (false === $DB->query("ALTER TABLE UrlChecker_Failures
                CHANGE StatusNo StatusCode INT")) {
                return "Could not update the StatusCode column";
            }
            if (false === $DB->query("ALTER TABLE UrlChecker_Failures
                CHANGE StatusText ReasonPhrase TEXT")) {
                return "Could not update the ReasonPhrase column";
            }
            if (false === $DB->query("ALTER TABLE UrlChecker_Failures
                CHANGE DataOne FinalStatusCode INT DEFAULT -1")) {
                return "Could not update the FinalStatusCode column";
            }
            if (false === $DB->query("ALTER TABLE UrlChecker_Failures
                CHANGE DataTwo FinalUrl TEXT")) {
                return "Could not update the FinalUrl column";
            }
            if (false === $DB->query("ALTER TABLE UrlChecker_History
                CHANGE DateChecked CheckDate TIMESTAMP")) {
                return "Could not update the resource history CheckDate column";
            }

            # add columns
            if (false === $DB->query("ALTER TABLE UrlChecker_Failures
                ADD Hidden INT DEFAULT 0 AFTER FieldId")) {
                return "Could not add the Hidden column";
            }
            if (false === $DB->query("ALTER TABLE UrlChecker_Failures
                ADD IsFinalUrlInvalid INT DEFAULT 0 AFTER ReasonPhrase")) {
                return "Could not add the IsFinalUrlInvalid column";
            }
            if (false === $DB->query("ALTER TABLE UrlChecker_Failures
                ADD FinalReasonPhrase TEXT")) {
                return "Could not add the FinalReasonPhrase column";
            }

            # rename history tables
            if (false === $DB->query("RENAME TABLE UrlChecker_Failures
                TO UrlChecker_UrlHistory")) {
                return "Could not rename the URL history table";
            }
            if (false === $DB->query("RENAME TABLE UrlChecker_History
                TO UrlChecker_ResourceHistory")) {
                return "Could not rename the resource history table";
            }

            # remove any garbage data
            if (false === $DB->query("DELETE FROM UrlChecker_UrlHistory WHERE ResourceId < 0")) {
                return "Could not remove stale data from the URL history";
            }
            if (false === $DB->query("DELETE FROM UrlChecker_ResourceHistory
                WHERE ResourceId < 0")) {
                return "Could not remove stale data from the resource history";
            }

            # add settings table
            if (false === $DB->query("
                CREATE TABLE UrlChecker_Settings (
                    NextNormalUrlCheck     INT,
                    NextInvalidUrlCheck    INT
                );")) {
                return "Could not create the settings table";
            }

            # repair and optimize tables after the changes.if this isn't done,
            # weird ordering issues might pop up
            if (false === $DB->query("REPAIR TABLE UrlChecker_UrlHistory")) {
                return "Could not repair the URL history table";
            }
            if (false === $DB->query("REPAIR TABLE UrlChecker_ResourceHistory")) {
                return "Could not repair the resource history table";
            }
            if (false === $DB->query("OPTIMIZE TABLE UrlChecker_UrlHistory")) {
                return "Could not optimize the URL history table";
            }
            if (false === $DB->query("OPTIMIZE TABLE UrlChecker_ResourceHistory")) {
                return "Could not optimize the resource history table";
            }
        }

        # upgrade from version 2.0.0 to 2.1.0
        if (version_compare($PreviousVersion, "2.1.0", "<")) {
            $DB = new Database();

            // make the upgrade process fault tolerant
            // @codingStandardsIgnoreStart
            $DB->setQueryErrorsToIgnore([
                '/ALTER\s+TABLE\s+[^\s]+\s+ADD\s+.+/i'
                  => '/Duplicate\s+column\s+name\s+[^\s]+/i',
                '/ALTER\s+TABLE\s+[^\s]+\s+DROP\s+.+/i'
                  => '/Can\'t\s+DROP\s+[^\s;]+;\s+check\s+that\s+column\/key\s+exists/i'
            ]);
            // @codingStandardsIgnoreEnd

            # get old settings data
            if (false === $DB->query("SELECT * FROM UrlChecker_Settings LIMIT 1")) {
                return "Could not get settings data";
            }

            $Row = $DB->fetchRow();
            if (is_array($Row)) {
                $NextNormalUrlCheck = $Row["NextNormalUrlCheck"];
                $NextInvalidUrlCheck = $Row["NextInvalidUrlCheck"];
            } else {
                $NextNormalUrlCheck = 0;
                $NextInvalidUrlCheck = 0;
            }

            # add column
            if (false === $DB->query("ALTER TABLE UrlChecker_Settings ADD Name Text")) {
                return "Could not add the Name column";
            }
            if (false === $DB->query("ALTER TABLE UrlChecker_Settings ADD Value Text")) {
                return "Could not add the Value column";
            }

            # remove old columns
            if (false === $DB->query("ALTER TABLE UrlChecker_Settings DROP NextNormalUrlCheck")) {
                return "Could not remove the NextNormalUrlCheck Column";
            }
            if (false === $DB->query("ALTER TABLE UrlChecker_Settings DROP NextInvalidUrlCheck")) {
                return "Could not remove the NextInvalidUrlCheck Column";
            }

            # remove any garbage data from the tables
            if (false === $DB->query("DELETE FROM UrlChecker_UrlHistory WHERE ResourceId < 0")) {
                return "Could not remove stale data from the URL history";
            }
            if (false === $DB->query("DELETE FROM UrlChecker_ResourceHistory
                WHERE ResourceId < 0")) {
                return "Could not remove stale data from the resource history";
            }

            # this makes sure that no garbage rows exist
            if (false === $DB->query("DELETE FROM UrlChecker_Settings")) {
                return "Could not remove stale data from the settings table";
            }

            # add settings back into the table
            if (false === $DB->query("
                INSERT INTO UrlChecker_Settings (Name, Value)
                VALUES
                ('NextNormalUrlCheck', '".addslashes($NextNormalUrlCheck)."'),
                ('NextInvalidUrlCheck', '".addslashes($NextInvalidUrlCheck)."'),
                ('EnableDeveloper', '0')")) {
                return "Could not initialize the updated settings";
            }

            # repair and optimize the settings table after the changes
            if (false === $DB->query("REPAIR TABLE UrlChecker_Settings")) {
                return "Could not repair the settings table";
            }
            if (false === $DB->query("OPTIMIZE TABLE UrlChecker_Settings")) {
                return "Could not optimize the settings table";
            }
        }

        # upgrade from version 2.1.0 to 2.1.1
        if (version_compare($PreviousVersion, "2.1.1", "<")) {
            $DB = new Database();

            # remove old garbage data
            if (false === $DB->query("
                DELETE FROM UrlChecker_UrlHistory
                WHERE Url NOT REGEXP '^https?:\/\/'")) {
                return "Could not remove stale data from the URL history";
            }
        }

        # upgrade to version 2.1.4
        if (version_compare($PreviousVersion, "2.1.4", "<")) {
            $this->configSetting(
                "TaskPriority",
                ApplicationFramework::PRIORITY_BACKGROUND
            );
        }

        # upgrade to version 2.1.10
        if (version_compare($PreviousVersion, "2.1.10", "<")) {
            $DB = new Database();

            # make the upgrade process fault tolerant
            $DB->setQueryErrorsToIgnore([
                '/DROP\s+.+/i'
                  => '/Unknown\s+table/i',
                '/SELECT\s+.+/i'
                  => '/doesn\'t\s+exist/i'
            ]);

            # get old settings data if possible
            $Result = $DB->query("SELECT * FROM UrlChecker_Settings");

            $OldSettings = [];

            # if the query succeeded
            if ($Result) {
                # add the old settings to the array
                while (false !== ($Row = $DB->fetchRow())) {
                    $OldSettings[$Row["Name"]] = intval($Row["Value"]);
                }
            }

            # migrate the data to the settings for the plugin
            $this->configSetting(
                "EnableDeveloper",
                (bool)StdLib::getArrayValue($OldSettings, "EnableDeveloper", false)
            );
            $this->configSetting(
                "NextNormalUrlCheck",
                StdLib::getArrayValue($OldSettings, "NextNormalUrlCheck", 0)
            );
            $this->configSetting(
                "NextInvalidUrlCheck",
                StdLib::getArrayValue($OldSettings, "NextInvalidUrlCheck", 0)
            );

            # remove the old settings table if possible
            $DB->query("DROP TABLE UrlChecker_Settings;");
        }

        # upgrade to version 2.1.11
        if (version_compare($PreviousVersion, "2.1.11", "<")) {
            $DB = new Database();

            # make the upgrade process fault tolerant
            $DB->setQueryErrorsToIgnore([
                '/ALTER\s+.+/i'
                  => '/Duplicate\s+column\s+name/i'
            ]);

            # add the Time column if possible
            $DB->query("
                ALTER TABLE UrlChecker_ResourceHistory
                ADD Time INT DEFAULT ".intval(self::CONNECTION_TIMEOUT));

            # reset the check times (invalid less than normal to make sure an
            # invalid check is performed first)
            $this->configSetting("NextNormalUrlCheck", 1);
            $this->configSetting("NextInvalidUrlCheck", 0);
        }

        if (version_compare($PreviousVersion, "2.1.12", "<")) {
            $this->configSetting("NumToCheck", 500);
        }

        if (version_compare($PreviousVersion, "2.1.13", "<")) {
            # If people have left the default in place,
            # change it to the new default.
            if ($this->configSetting("NumToCheck") == 500) {
                $this->configSetting("NumToCheck", 250);
            }

            # Default to checking all URL fields:
            $FieldsToCheck = [];
            $AllSchemas = MetadataSchema::getAllSchemas();
            foreach ($AllSchemas as $Schema) {
                $UrlFields = $Schema->getFields(MetadataSchema::MDFTYPE_URL);
                foreach ($UrlFields as $Field) {
                    $FieldsToCheck[] = $Field->id();
                }
            }
            $this->configSetting("FieldsToCheck", $FieldsToCheck);
        }

        if (version_compare($PreviousVersion, "2.1.14", "<")) {
            $DB = new Database();

            $DB->setQueryErrorsToIgnore([
                '/ALTER\s+.+/i'
                  => '/check\sthat\scolumn\/key\sexists/i'
            ]);

            $DB->query(
                "ALTER TABLE UrlChecker_ResourceHistory"
                ." DROP COLUMN Time"
            );

             $this->configSetting("CheckDelay", 15);
        }

        if (version_compare($PreviousVersion, "2.1.16", "<")) {
            # translate Release and Withhold configurations to the new format that
            # supports multiple schemas
            $Actions = ["Withhold", "Release"];
            foreach ($Actions as $Action) {
                $NewSetting = [];
                $Setting = $this->configSetting($Action."Configuration");
                if ($Setting !== null) {
                    $NewSetting = [MetadataSchema::SCHEMAID_DEFAULT => $Setting];
                }
                $this->configSetting($Action."Configuration", $NewSetting);
            }
            # default to doing nothing when withholding resources
            $this->configSetting("WithholdConfiguration", []);
        }

        if (version_compare($PreviousVersion, "2.1.17", "<")) {
            $DB = new Database();

            if (false === $DB->query("
                ALTER TABLE UrlChecker_UrlHistory
                ADD CheckDuration INT DEFAULT NULL
                AFTER CheckDate")) {
                return "Could not add the CheckDuration column";
            }

            # set a default duration of 25 seconds
            $DB->query(
                "UPDATE UrlChecker_UrlHistory SET CheckDuration=25"
            );
        }

        if (version_compare($PreviousVersion, "2.1.20", "<")) {
            $DB = new Database();

            $Result = $DB->query(
                "ALTER TABLE UrlChecker_ResourceHistory"
                ." RENAME UrlChecker_RecordHistory"
            );
            if ($Result === false) {
                return "Failed to rename UrlChecker_ResourceHistory";
            }

            $Tables = ["RecordHistory", "UrlHistory"];
            foreach ($Tables as $Table) {
                $Result = $DB->query(
                    "ALTER TABLE UrlChecker_".$Table
                    ." CHANGE COLUMN ResourceId RecordId INT"
                );
                if ($Result === false) {
                    return "Failed to rename ResourceId column in UrlChecker_".$Table;
                }
            }
        }

        if (version_compare($PreviousVersion, "2.1.21", "<")) {
            $DB = new Database();

            # get a list of the indexes on the UrlHistory table
            $Indexes = [];
            $DB->query("SHOW KEYS FROM UrlChecker_UrlHistory");
            $Rows = $DB->fetchRows();
            foreach ($Rows as $Row) {
                $Indexes[$Row["Key_name"]][] = $Row["Column_name"];
            }

            # if it has a PRIMARY KEY, we'll need to drop it
            if (isset($Indexes["PRIMARY"])) {
                $Result = $DB->query(
                    "ALTER TABLE UrlChecker_UrlHistory DROP PRIMARY KEY"
                );
                if ($Result === false) {
                    return "Failed to remove primary key from UrlChecker_UrlHistory";
                }
            }

            # prior to r10147, UrlChecker was creating an INDEX (ResourceId, FieldId)
            # that was automatically named ResourceId.Our col renaming will have changed
            # such indexes to cover (RecordId, FieldId).If such an index exists, then we
            # don't need to create anything
            $HaveIndexAlready = isset($Indexes["ResourceId"]) &&
                $Indexes["ResourceId"][0] == "RecordId" &&
                $Indexes["ResourceId"][1] == "FieldId" ? true : false;

            # if we don't already have such an index, create one
            if (!$HaveIndexAlready) {
                $Result = $DB->query(
                    "CREATE INDEX Index_RF ON UrlChecker_UrlHistory (RecordId, FieldId)"
                );
                if ($Result === false) {
                    return "Failed to create Index_RF for UrlChecker_UrlHistory";
                }
            }
        }

        if (version_compare($PreviousVersion, "2.1.23", "<")) {
            # some previous versions of UrlChecker created an INDEX (RecordId)
            # on the RecordHistory table rather than a PRIMARY KEY; the former
            # allows duplicate RecordIds whereas the latter does not

            $DB = new Database();

            # build a list of indexes and what columns they cover on the
            # RecordHistory table
            $Indexes = [];
            $DB->query("SHOW KEYS FROM UrlChecker_RecordHistory");
            foreach ($DB->fetchRows() as $Row) {
                $Indexes[$Row["Key_name"]][] = $Row["Column_name"];
            }

            # if we don't have a primary key, or we have one that covers too many columns,
            # or we have one that covers the wrong column(s), then we've got
            # some cleanup to do
            if (!isset($Indexes["PRIMARY"]) || count($Indexes["PRIMARY"]) > 1 ||
                $Indexes["PRIMARY"][0] != "RecordId") {
                # create a new version of the table with the correct indexes
                $DB->query(
                    str_replace(
                        "UrlChecker_RecordHistory",
                        "UrlChecker_RecordHistory_New",
                        $this->SqlTables["RecordHistory"]
                    )
                );

                # wrap an explicit lock around the data migration so that
                # tasks running in other threads (including those *not* inside
                # an upgrade() run) cannot insert new data
                $DB->query(
                    "LOCK TABLES UrlChecker_RecordHistory WRITE, "
                    ."UrlChecker_RecordHistory_New WRITE"
                );

                # put de-duped data into the new table
                $DB->query(
                    "INSERT INTO UrlChecker_RecordHistory_New (RecordId, CheckDate) "
                    ."SELECT RecordId, MAX(CheckDate) FROM UrlChecker_RecordHistory "
                    ."GROUP BY RecordId"
                );

                # Per MySQL's "RENAME TABLE Statement" at
                #   https://dev.mysql.com/doc/refman/5.6/en/rename-table.html
                # "To execute RENAME TABLE, there must be no active
                #  transactions or tables locked with LOCK TABLES.With the
                #  transaction table locking conditions satisfied, the rename
                #  operation is done atomically; no other session can access any
                #  of the tables while the rename is in progress."
                # So we must unlock before we can rename.
                $DB->query("UNLOCK TABLES");

                # (brief race condition here where new data could be inserted
                #  into the old table before the 'RENAME TABLE' starts; any
                #  such data will be lost, but this will just mean that the
                #  UrlChecker re-checks those records sooner than it needs to)
                $DB->query(
                    "RENAME TABLE "
                    ."UrlChecker_RecordHistory TO UrlChecker_RecordHistory_Old, "
                    ."UrlChecker_RecordHistory_New TO UrlChecker_RecordHistory"
                );
                $DB->query(
                    "DROP TABLE UrlChecker_RecordHistory_Old"
                );
            }
        }

        if (version_compare($PreviousVersion, "2.1.24", "<")) {
            # Remove URLs that contain an IMAGEURL keyword pulled in from a
            # paragraph field
            $DB = new Database();
            $DB->query(
                "DELETE FROM UrlChecker_UrlHistory "
                ."WHERE Url LIKE '%{{IMAGEURL|Id:%|Size:%}}'"
            );
        }

        return null;
    }

    /**
     * Handle plugin initialization.
     * @return null|string NULL on success, error string on failure.
     */
    public function initialize()
    {
        $this->DB = new Database();
        $this->addAdminMenuEntry(
            "Results",
            "URL Checker Results",
            [ PRIV_COLLECTIONADMIN ]
        );
        $this->addAdminMenuEntry(
            "ConfigureActions",
            "Release/Withhold Configuration",
            [ PRIV_COLLECTIONADMIN ]
        );
        if ($this->configSetting("EnableDeveloper")) {
            $this->addAdminMenuEntry(
                "HiddenUrls",
                "Hidden URLs",
                [ PRIV_COLLECTIONADMIN ]
            );
            $this->addAdminMenuEntry(
                "Developer",
                "Developer Support",
                [ PRIV_COLLECTIONADMIN ]
            );
        }
        return null;
    }

    /**
     * Hook the events into the application framework.
     * @return array an array of events to be hooked into the application framework
     */
    public function hookEvents(): array
    {
        $Events = [
            "EVENT_RESOURCE_MODIFY" => "resourceModify",
            "EVENT_RESOURCE_DELETE" => "resourceDelete",
            "EVENT_FIELD_ADDED" => "addField",
            "EVENT_PRE_FIELD_DELETE" => "removeField",
            "EVENT_PLUGIN_CONFIG_CHANGE" => "handleConfigChange",
        ];

        if ($this->configSetting("EnableChecking")) {
            $Events["EVENT_PERIODIC"] = "queueResourceCheckTasks";
        }

        return $Events;
    }


    # ---- HOOKED METHODS ----------------------------------------------------

    /**
     * Queue tasks to check resource URLs for resources that need to be checked.
     * @return int Returns the amount of time before this should be called again, in
     *      minutes.
     */
    public function queueResourceCheckTasks()
    {
        if (!$this->configSetting("EnableChecking")) {
            return self::RETRY_TIME_NOTHING_TO_CHECK;
        }

        # don't waste time and resources if there aren't any URL fields
        if (count($this->getFieldsToCheck()) == 0) {
            return self::RETRY_TIME_NOTHING_TO_CHECK;
        }

        # come back later if there are URLs still being checked
        if ($this->getQueuedTaskCount("checkResourceUrls")) {
            return self::RETRY_TIME_CHECKING;
        }

        # dump the db cache to lower the chances of popping the memory cap
        Database::clearCaches();

        # Get the list of failing URLs that need to be checked, and the list of
        # resources that are due for a check.This will give us somewhere between
        #  0 and 2 * $NumToCheck elements.
        $Urls = $this->getNextUrlsToBeChecked();
        $Resources = $this->getNextResourcesToBeChecked();

        # If we have anything to do:
        if (count($Urls) > 0 || count($Resources) > 0) {
            # Divide our checks among Urls and Resources, with weighting
            # determined by the number of each check type.If we've got
            # equal numbers of both, then the split will be 50/50.If we've
            # got N Url checks and 2N Resource checks, then 1/3 of the
            # checks will go to URLs and 2/3 to Resources.

            $NumToCheck = $this->configSetting("NumToCheck");
            $PctUrls = count($Urls) / (count($Urls) + count($Resources) );

            $Urls = array_slice(
                $Urls,
                0,
                (int)round($PctUrls * $NumToCheck),
                true
            );
            $Resources = array_slice(
                $Resources,
                0,
                (int)round((1 - $PctUrls) * $NumToCheck),
                true
            );

            # Note: In the code below, we do not check our exclusion rules
            # and queue a check for all resources / urls.This is
            # because the CheckResourceUrls tasks queued by
            # QueueResourceCheckTask() still need to run to do some
            # bookkeeping in the database.
            foreach ($Urls as $Url) {
                $Resource = new Record($Url->RecordId);
                $this->queueResourceCheckTask($Resource);
            }

            foreach ($Resources as $ResourceId => $CheckDate) {
                $Resource = new Record($ResourceId, $CheckDate);
                $this->queueResourceCheckTask($Resource);
            }
        }

        return $this->configSetting("CheckDelay");
    }

    /**
     * Get information/stats of the various data saved.
     * @return array of various information
     */
    public function getInformation()
    {
        $this->removeStaleData();

        $Info = [];

        # database settings
        $Info["EnableDeveloper"] = intval($this->configSetting("EnableDeveloper"));
        $Info["NumToCheck"] = $this->configSetting("NumToCheck");

        # hard-coded settings
        $Info["Timeout"] = self::CONNECTION_TIMEOUT;
        $Info["Threshold"] = $this->configSetting("InvalidationThreshold");

        # the number of resources checked so far
        $this->DB->query("SELECT COUNT(*) as NumChecked FROM UrlChecker_RecordHistory");
        $Info["NumResourcesChecked"] = intval($this->DB->fetchField("NumChecked"));

        # the number of resources that haven't been checked so far (don't count
        # resources with IDs < 0 since they're probably bad)
        $this->DB->query("
            SELECT COUNT(*) as NumResources
            FROM Records
            WHERE RecordId >= 0");
        $Info["NumResourcesUnchecked"] = intval($this->DB->fetchField("NumResources"))
            - $Info["NumResourcesChecked"];

        # the number of the invalid URLs past the threshold and "not hidden"
        $this->DB->query("
            SELECT COUNT(*) as NumInvalid
            FROM UrlChecker_UrlHistory
            WHERE Hidden = 0
            AND TimesInvalid >= ".$this->configSetting("InvalidationThreshold"));
        $Info["NumInvalid"] = intval($this->DB->fetchField("NumInvalid"));

        # the number of the invalid URLs past the threshold and hidden
        $this->DB->query("
            SELECT COUNT(*) as NumInvalid
            FROM UrlChecker_UrlHistory
            WHERE Hidden = 1
            AND TimesInvalid >= ".$this->configSetting("InvalidationThreshold"));
        $Info["NumInvalidAndHidden"] = intval($this->DB->fetchField("NumInvalid"));

        # the number of possibly invalid urls
        $this->DB->query("
            SELECT COUNT(*) as NumInvalid
            FROM UrlChecker_UrlHistory
            WHERE TimesInvalid < ".$this->configSetting("InvalidationThreshold"));
        $Info["NumPossiblyInvalid"] = intval($this->DB->fetchField("NumInvalid"));

        # the number of "not hidden" invalid URLs for each status code
        $Info["InvalidUrlsForStatusCodes"] = [];
        $this->DB->query("
            SELECT StatusCode, COUNT(*) as NumInvalid
            FROM UrlChecker_UrlHistory
            WHERE Hidden = 0
            AND TimesInvalid >= ".$this->configSetting("InvalidationThreshold")."
            GROUP BY StatusCode");
        while (false !== ($Row = $this->DB->fetchRow())) {
            $Info["InvalidUrlsForStatusCodes"][intval($Row["StatusCode"])]
                = intval($Row["NumInvalid"]);
        }

        # the number of "hidden" invalid URLs for each status code
        $Info["HiddenInvalidUrlsForStatusCodes"] = [];
        $this->DB->query("
            SELECT StatusCode, COUNT(*) as NumInvalid
            FROM UrlChecker_UrlHistory
            WHERE Hidden = 1
            AND TimesInvalid >= ".$this->configSetting("InvalidationThreshold")."
            GROUP BY StatusCode");
        while (false !== ($Row = $this->DB->fetchRow())) {
            $Info["HiddenInvalidUrlsForStatusCodes"][intval($Row["StatusCode"])]
                = intval($Row["NumInvalid"]);
        }

        # the last time a check was done
        $this->DB->query("
            SELECT *
            FROM UrlChecker_RecordHistory
            ORDER BY CheckDate DESC LIMIT 1");
        $Info["DateLastResourceChecked"] = $this->DB->fetchField("CheckDate");

        # the next time a check will be performed
        $Info["DateOfNextCheck"] = $this->getDateOfNextCheck();

        # version information
        $Info["Version"] = $this->Version;
        $Info["MetavusVersion"] = METAVUS_VERSION;
        $Info["PhpVersion"] = PHP_VERSION;

        return $Info;
    }

    /**
     * Check all of the URL metadata field values for the given resource.
     * @param int|Record $ResourceId ID of Resource to check.
     * @param string $CheckDate Date resource was last checked.
     */
    public function checkResourceUrls($ResourceId, $CheckDate)
    {
        if (!$this->configSetting("EnableChecking")) {
            return;
        }

        # bail if the specified resource no longer exists
        if (!Record::itemExists($ResourceId)) {
            return;
        }

        # instantiate resource
        $Resource = is_object($ResourceId) ? $ResourceId
                : new Record($ResourceId, $CheckDate);

        # the URLs for the resource should not be checked
        if ($this->shouldNotCheckUrls($Resource)) {
            # record that the resource was checked
            $this->updateResourceHistory($Resource);

            # clear out the URL history
            $this->DB->query(
                "DELETE FROM UrlChecker_UrlHistory"
                ." WHERE RecordId = ".$Resource->id()
            );

            # don't check any URLs
            return;
        }

        # get the list of fields that we will check for this resource
        $FieldsToCheck = $this->getFieldsToCheck($Resource->getSchemaId());

        # if we have no fields to check, record that this resource was
        # checked and bail
        if (count($FieldsToCheck) == 0) {
            # record that the resource was checked
            $this->updateResourceHistory($Resource);
            return;
        }

        # otherwise, make sure there's enough time to check all the fields
        $TimeRequired = $this->estimateCheckTime($Resource);

        # Note: If TimeRequired is more than our MaxExecTime, then we'll
        # never think we have enough time and will get stuck in a
        # loop of constantly requeueing ourself.To avoid that, cap
        # our TimeRequired at 90% of max execution time.If this
        # really and truly isn't enough, we'll end up orphaned (not
        # great, but better than choking the Task Queue).
        $AF = ApplicationFramework::getInstance();
        $TimeRequired = min(
            $TimeRequired,
            0.9 * $AF->maxExecutionTime()
        );

        # if we're running in the background and are low on time,
        # mark ourselves to be re-queued and stop processing
        if ($AF->isRunningInBackground() &&
            $AF->getSecondsBeforeTimeout() < $TimeRequired) {
            $AF->requeueCurrentTask(true);
            return;
        }

        foreach ($FieldsToCheck as $Field) {
            $Urls = $this->getUrlsFromField($Resource, $Field);

            # if this field has no urls, move along to the next one
            if (count($Urls) == 0) {
                continue;
            }

            # clean out history entries for URLs that are no longer associated
            # with this field and record
            $EscapedUrls = array_map(
                function ($x) {
                    return "'".addslashes($x)."'";
                },
                $Urls
            );
            $this->DB->query(
                " DELETE FROM UrlChecker_UrlHistory"
                ." WHERE RecordId = '".intval($Resource->id())."'"
                ." AND FieldId = '".intval($Field->id())."'"
                ." AND Url NOT IN (".implode(",", $EscapedUrls).")"
            );

            # check each url from this field
            foreach ($Urls as $Url) {
                $this->checkUrl($Resource->id(), $Field->id(), $Url);
            }
        }

        # record that the resource was checked
        $this->updateResourceHistory($Resource);
    }

    /**
     * Get the number of invalid URLs that match the given constraints
     * @param array $Constraints Array of constraints.
     * @return int The number of invalid URLs that match the constraints
     */
    public function getInvalidCount($Constraints = [])
    {
        $this->removeStaleData();

        $ValidRelations = ["=", "!=", "<", ">", "<=", ">="];

        # construct the where constraint
        $Where = " WHERE URH.TimesInvalid >= "
                   .$this->configSetting("InvalidationThreshold")." ";
        $OuterGroup = "";
        foreach ($Constraints as $ConstraintList) {
            # skip invalid constraints
            if (!($ConstraintList instanceof ConstraintList)) {
                continue;
            }

            $InnerGroup = "";
            foreach ($ConstraintList as $Constraint) {
                $Key = $Constraint->Key;
                $Value = $Constraint->Value;
                $Relation = $Constraint->Relation;

                # skip if the relation is invalid
                if (!in_array($Relation, $ValidRelations)) {
                    continue;
                }

                # Resource table constraint
                if ($Key instanceof MetadataField &&
                    $Key->status() == MetadataSchema::MDFSTAT_OK) {
                    $LogicOperator = (strlen($InnerGroup)) ? "AND" : "";
                    $InnerGroup .= " ".$LogicOperator." R.".$Key->dBFieldName();
                    $InnerGroup .= " ".$Relation." '".addslashes($Value)."'";
                } elseif (is_string($Key)) {
                    # UrlChecker_History table constraint
                    $LogicOperator = (strlen($InnerGroup)) ? "AND" : "";
                    $InnerGroup .= " ".$LogicOperator." URH.".$Key;
                    $InnerGroup .= " ".$Relation." '".addslashes($Value)."'";
                }

                # otherwise ignore the invalid key value
            }

            if (strlen($InnerGroup)) {
                $OuterGroup .= (strlen($OuterGroup)) ? " OR " : "";
                $OuterGroup .= " ( ".$InnerGroup." ) ";
            }
        }

        if (strlen($OuterGroup)) {
            $Where .= " AND ".$OuterGroup;
        }

        # get the url data
        $this->DB->query("
            SELECT COUNT(*) AS NumInvalid
            FROM UrlChecker_UrlHistory URH
            LEFT JOIN Records R
            ON URH.RecordId = R.RecordId
            ".$Where);

        return intval($this->DB->fetchField("NumInvalid"));
    }

    /**
     * Get the invalid URLs that match the given constraints.
     * @param array $Constraints Array of constraints
     * @param string|MetadataField $OrderBy Field by which the URLs should be sorted
     * @param string $OrderDirection Direction in which the URLs should be sorted
     * @param int $Limit How many URLs should be returned
     * @param int $Offset Where the result set should begin
     * @return array An array of InvalidUrl objects
     */
    public function getInvalidUrls(
        $Constraints = [],
        $OrderBy = "StatusCode",
        $OrderDirection = "DESC",
        $Limit = 15,
        $Offset = 0
    ) {
        $this->removeStaleData();

        $ValidGetConstraints = [
            "RecordId", "FieldId", "TimesInvalid", "Url", "CheckDate",
            "StatusCode", "ReasonPhrase", "FinalUrl", "FinalStatusCode",
            "FinalReasonPhrase", "Hidden"
        ];
        $ValidRelations = ["=", "!=", "<", ">", "<=", ">="];

        # construct the where constraint
        $Where = " WHERE URH.TimesInvalid >= "
                   .$this->configSetting("InvalidationThreshold")." ";
        $OuterGroup = "";
        $InnerGroup = "";
        foreach ($Constraints as $ConstraintList) {
            # skip invalid constraints
            if (!($ConstraintList instanceof ConstraintList)) {
                continue;
            }

            $InnerGroup = "";
            foreach ($ConstraintList as $Constraint) {
                $Key = $Constraint->Key;
                $Value = $Constraint->Value;
                $Relation = $Constraint->Relation;

                # skip if the relation is invalid
                if (!in_array($Relation, $ValidRelations)) {
                    continue;
                }

                # Resource table constraint
                if ($Key instanceof MetadataField &&
                    $Key->status() == MetadataSchema::MDFSTAT_OK) {
                    $LogicOperator = (strlen($InnerGroup)) ? "AND" : "";
                    $InnerGroup .= " ".$LogicOperator." R.".$Key->dBFieldName();
                    $InnerGroup .= " ".$Relation." '".addslashes($Value)."'";
                } elseif (is_string($Key)) {
                    # UrlChecker_History table constraint
                    $LogicOperator = (strlen($InnerGroup)) ? "AND" : "";
                    $InnerGroup .= " ".$LogicOperator." URH.".$Key;
                    $InnerGroup .= " ".$Relation." '".addslashes($Value)."'";
                }

                # otherwise ignore the invalid key value
            }

            if (strlen($InnerGroup)) {
                $OuterGroup .= (strlen($OuterGroup)) ? " OR " : "";
                $OuterGroup .= " ( ".$InnerGroup." ) ";
            }
        }

        # if there is at least one inner group, add an outer parentheses to
        # group them together
        if (strlen($InnerGroup)) {
            $OuterGroup = " (".$OuterGroup.") ";
        }

        if (strlen($OuterGroup)) {
            $Where .= " AND ".$OuterGroup;
        }

        # valid UrlChecker_History table order
        if (is_string($OrderBy) && in_array($OrderBy, $ValidGetConstraints)) {
            $OrderBy = "URH.".$OrderBy;
        } elseif ($OrderBy instanceof MetadataField
                && $OrderBy->status() == MetadataSchema::MDFSTAT_OK) {
            # valid Resource table order
            $OrderBy = "R.".$OrderBy->dBFieldName();
        } else {
            # otherwise default the StatusCode field of the UrlChecker_History tale
            $OrderBy = "URH.StatusCode";
        }

        # make sure order direction is valid
        if ($OrderDirection != "ASC" && $OrderDirection != "DESC") {
            $OrderDirection = "DESC";
        }
        # get the url data
        $this->DB->query(
            "SELECT URH.* FROM UrlChecker_UrlHistory URH"
            ." LEFT JOIN Records R"
            ." ON URH.RecordId = R.RecordId"
            .$Where
            ."ORDER BY ".$OrderBy." ".$OrderDirection
            ." LIMIT ".intval($Limit)
            ." OFFSET ".intval($Offset)
        );

        # create url objects
        $Urls = [];
        foreach ($this->DB->fetchRows() as $Row) {
            $Urls[] = new InvalidUrl($Row);
        }

        return $Urls;
    }

    /**
     * Encode an identifier for a specified Url to use in links.
     * @param int $RecordId Record Id.
     * @param int $FieldId Field Id.
     * @param string $Url Subject Url.
     * @return string Opaque identifier
     */
    public function encodeUrlIdentifier(
        int $RecordId,
        int $FieldId,
        string $Url
    ): string {
        return $RecordId.":".$FieldId.":".md5($Url);
    }

    /**
     * Decode an identifier created by encodeUrlIdentifier().
     * @param string $Identifier Opaque string.
     * @return array Array having keys RecordId, FieldId, and UrlHash.
     * @see encodeUrlIdentifier()
     */
    public function decodeUrlIdentifier(
        string $Identifier
    ): array {
        list($RecordId, $FieldId, $UrlHash) = explode(":", $Identifier);
        $Data = [
            "RecordId" => $RecordId,
            "FieldId" => $FieldId,
            "UrlHash" => $UrlHash,
        ];

        return $Data;
    }

    /**
     * Get the invalid URL that is associated with the given resource and
     * metadata field, or NULL if one doesn't exist.
     * @param int $RecordId Record Id.
     * @param int $FieldId Metadata field Id.
     * @param string $UrlHash Hash of the target URL.
     * @return InvalidUrl|null an InvalidUrl object or NULL
     */
    public function getInvalidUrl(
        int $RecordId,
        int $FieldId,
        string $UrlHash
    ) {
        $this->DB->query(
            "SELECT *"
            ." FROM UrlChecker_UrlHistory"
            ." WHERE RecordId = ".intval($RecordId)
            ." AND FieldId = ".intval($FieldId)
        );

        $Rows = $this->DB->fetchRows();
        if (count($Rows) == 0) {
            return null;
        }

        foreach ($Rows as $Row) {
            if (md5($Row["Url"]) == $UrlHash) {
                return new InvalidUrl($Row);
            }
        }

        return null;
    }

    /**
     * Determine whether or not the resource is "released".
     * @param Record $Resource Resource.
     * @return bool TRUE if the resource is released, FALSE otherwise
     */
    public function isResourceReleased(Record $Resource)
    {
        # released resources are those anon users can view
        return $Resource->userCanView(User::getAnonymousUser());
    }

    /**
     * Release a resource using the configured ReleaseAction.
     * @param Record $Resource Resource.
     */
    public function releaseResource(Record $Resource)
    {
        $ReleaseActions = $this->configSetting("ReleaseConfiguration");
        if (isset($ReleaseActions[$Resource->getSchemaId()])) {
            # actions configured via the UI
            $Resource->applyListOfChanges(
                $ReleaseActions[$Resource->getSchemaId()],
                User::getCurrentUser()
            );
        }
    }

    /**
     * Withhold the given resource using the configured WithholdAction.
     * @param Record $Resource Resource.
     */
    public function withholdResource(Record $Resource)
    {
        $WithholdActions = $this->configSetting("WithholdConfiguration");
        if (isset($WithholdActions[$Resource->getSchemaId()])) {
            # actions configured via the UI
            $Resource->applyListOfChanges(
                $WithholdActions[$Resource->getSchemaId()],
                User::getCurrentUser()
            );
        }
    }

    /**
     * Apply automatic fix for a given Url.
     * @param string $Identifier Opaque Url Identifier.
     */
    public function autofixUrl($Identifier)
    {
        $UrlInfo = $this->decodeUrlIdentifier($Identifier);

        $Resource = new Record($UrlInfo["RecordId"]);

        $AutofixActions = $this->configSetting("AutofixConfiguration");
        $Changes = isset($AutofixActions[$Resource->getSchemaId()]) ?
            $AutofixActions[$Resource->getSchemaId()] : [];

        $FieldId = $UrlInfo["FieldId"];
        $Field = new MetadataField($FieldId);

        $Url = $this->getInvalidUrl(
            $Resource->id(),
            $FieldId,
            $UrlInfo["UrlHash"]
        );

        if (!is_null($Url) && strlen($Url->FinalUrl)) {
            switch ($Field->type()) {
                case MetadataSchema::MDFTYPE_URL:
                    $Changes[] = [
                        "FieldId" => $FieldId,
                        "Op" => Record::CHANGE_SET,
                        "Val" => $Url->FinalUrl
                    ];
                    break;

                case MetadataSchema::MDFTYPE_PARAGRAPH:
                    $Changes[] = [
                        "FieldId" => $FieldId,
                        "Op" => Record::CHANGE_FIND_REPLACE,
                        "Val" => $Url->Url,
                        "Val2" => $Url->FinalUrl,
                    ];
                    break;

                default:
                    throw new Exception(
                        "Unsupported field type: ".$Field->typeAsName()."."
                    );
            }

            # make the change
            $Resource->applyListOfChanges(
                $Changes,
                User::getCurrentUser()
            );

            # and clean out our failure information
            $this->DB->query(
                "DELETE FROM UrlChecker_UrlHistory"
                ." WHERE RecordId = '".$Url->RecordId."'"
                ." AND FieldId = '".$Url->FieldId."'"
                ." AND Url = '".addslashes($Url->Url)."'"
            );
        }
    }

    /**
     * Hide the URL associated with the given resource and metadata field so
     * that it doesn't show up on the results page.
     * @param string $Identifier Url Identifier
     */
    public function hideUrl(string $Identifier)
    {
        $UrlInfo = $this->decodeUrlIdentifier($Identifier);

        $Url = $this->getInvalidUrl(
            $UrlInfo["RecordId"],
            $UrlInfo["FieldId"],
            $UrlInfo["UrlHash"]
        );

        # bail if no url found
        if (is_null($Url)) {
            return;
        }

        $this->DB->query(
            "UPDATE UrlChecker_UrlHistory"
            ." SET Hidden = 1"
            ." WHERE RecordId = '".$Url->RecordId."'"
            ." AND FieldId = '".$Url->FieldId."'"
            ." AND Url = '".addslashes($Url->Url)."'"
        );
    }

    /**
     * "Unhide" the URL associated with the given resource and metadata field so
     * that it shows up on the results page.
     * @param string $Identifier Url Identifier
     */
    public function unhideUrl(string $Identifier)
    {
        $UrlInfo = $this->decodeUrlIdentifier($Identifier);

        $Url = $this->getInvalidUrl(
            $UrlInfo["RecordId"],
            $UrlInfo["FieldId"],
            $UrlInfo["UrlHash"]
        );

        # bail if no url found
        if (is_null($Url)) {
            return;
        }

        $this->DB->query(
            "UPDATE UrlChecker_UrlHistory"
            ." SET Hidden = 0"
            ." WHERE RecordId = '".$Url->RecordId."'"
            ." AND FieldId = '".$Url->FieldId."'"
            ." AND Url = '".addslashes($Url->Url)."'"
        );
    }

    /**
     * "Unhide" all the URLs associated with the given record so that they
     *   will show up on the results page.
     * @param \Metavus\Record $Record Subject record.
     */
    public function unhideUrlsForRecord(\Metavus\Record $Record)
    {
        $FieldsToCheck = $this->getFieldsToCheck($Record->getSchemaId());

        foreach ($FieldsToCheck as $Field) {
            $Urls = $this->getUrlsFromField($Record, $Field);

            # if this field has no urls, move along to the next one
            if (count($Urls) == 0) {
                continue;
            }

            foreach ($Urls as $Url) {
                $Identifier = $this->encodeUrlIdentifier(
                    $Record->id(),
                    $Field->id(),
                    $Url
                );

                $this->unhideUrl($Identifier);
            }
        }
    }

    /**
     * Get the list of records that are due to be checked or re-checked.The
     *   length of the list will be limited by the "Resources to Check" config
     *   setting.The plugin tracks the domains of URLs queued for a check
     *   within a given page load and will only queue one check per
     *   domain.This tracking includes URLs returned by this function as well
     *   as those from getNextUrlsToBeChecked().
     * @see getNextUrlsToBeChecked()
     * @return array Resources to check [ResourceId => CheckDate]
     */
    public function getNextResourcesToBeChecked()
    {
        $this->removeStaleData();

        $FieldsChecked = $this->getFieldsToCheck();

        # if we aren't checking any fields, bail
        if (count($FieldsChecked) == 0) {
            return [];
        }

        # assemble a list of schemas that we are checking
        $SchemasChecked = [];
        foreach ($FieldsChecked as $Field) {
            if (!in_array($Field->schemaId(), $SchemasChecked)) {
                $SchemasChecked[] = $Field->schemaId();
            }
        }

        # pull out the number of checks we want to do
        $NumToCheck = $this->configSetting("NumToCheck");

        # start building the list of resources to check
        $Resources = [];

        # get the list of RecordIds from schemas containing a field that we
        # check where we've never checked a Url from the given record, limiting
        # to 4 x NumToCheck rows to avoid hitting PHP's memory limit when the
        # DB cache is large and there are a lot of resources to check

        $this->DB->query(
            "SELECT R.RecordId as RecordId"
            ." FROM Records R"
            ." LEFT JOIN UrlChecker_RecordHistory URH"
            ." ON R.RecordId = URH.RecordId"
            ." WHERE URH.RecordId IS NULL"
            ." AND R.RecordId >= 0"
            ." AND R.SchemaId IN (".implode(",", $SchemasChecked).")"
            ." LIMIT ".intval(4 * $NumToCheck)
        );
        $RecordIds = $this->DB->fetchColumn("RecordId");

        foreach ($RecordIds as $RecordId) {
            $Record = new Record($RecordId);

            if (!$this->shouldCheckDomainsFromRecordUrls($Record)) {
                continue;
            }

            $Resources[$RecordId] = "N/A";

            if (count($Resources) >= $NumToCheck) {
                return $Resources;
            }
        }

        # resources that need to be rechecked
        $CheckDate = date(
            StdLib::SQL_DATE_FORMAT,
            (int)strtotime(
                "-".$this->configSetting("ResourceRecheckTime")." days"
            )
        );
        $this->DB->query(
            "SELECT * FROM UrlChecker_RecordHistory"
            ." WHERE CheckDate <= '".strval($CheckDate)."'"
            ." ORDER BY CheckDate ASC"
            ." LIMIT ".intval(4 * $NumToCheck)
        );
        $Rows = $this->DB->fetchRows();

        foreach ($Rows as $Row) {
            $Record = new Record($Row["RecordId"]);

            if (!$this->shouldCheckDomainsFromRecordUrls($Record)) {
                continue;
            }

            $Resources[$Row["RecordId"]] = $Row["CheckDate"] ;

            if (count($Resources) >= $NumToCheck) {
                break;
            }
        }

        return $Resources;
    }

    /**
     * Get the list of URLs that have at least one failure recorded and should
     *   be re-checked.This will include potentially invalid URLs (i.e.those
     *   that have failed fewer times than our configured Invalidation
     *   Threshold) that were last checked longer than our configured Valid
     *   URL Recheck Time ago.It will also include invalid URLs (i.e.those
     *   that have failed more times than our configured Invalidation
     *   Threshold) that were last checked longer than our configured Invalid
     *   URL Recheck Time ago.Result will be limited by the Resources to
     *   Check setting.The plugin tracks the domains of URLs queued for a
     *   check within a given page load and will only queue one check per
     *   domain.This tracking includes URLs returned by this function as well
     *   as those from getNextResourcesToBeChecked().
     * @see getNextResourcessToBeChecked()
     * @return array InvalidUrl objects to be checked.
     */
    public function getNextUrlsToBeChecked()
    {
        $this->removeStaleData();

        $Urls = [];

        $ValidCheckTime = date(
            StdLib::SQL_DATE_FORMAT,
            (int)strtotime(
                "-".$this->configSetting("ValidUrlRecheckTime")." days"
            )
        );
        $InvalidCheckTime = date(
            StdLib::SQL_DATE_FORMAT,
            (int)strtotime(
                "-".$this->configSetting("InvalidUrlRecheckTime")." days"
            )
        );

        $NumToCheck = $this->configSetting("NumToCheck");

        # get list of records due to check, limiting to 4 x NumToCheck rows to
        # avoid hitting PHP's memory limit when the DB cache is large and
        # there are a lot of URLs to check
        $this->DB->query(
            "SELECT * FROM UrlChecker_UrlHistory"
            ." WHERE ("
            ."   TimesInvalid < ".intval($this->configSetting("InvalidationThreshold"))
            ."   AND CheckDate <= '".strval($ValidCheckTime)."'"
            ." ) OR ( "
            ."   TimesInvalid >= ".intval($this->configSetting("InvalidationThreshold"))
            ."   AND CheckDate <= '".strval($InvalidCheckTime)."'"
            ." )"
            ." ORDER BY CheckDate ASC"
            ." LIMIT ".intval(4 * $NumToCheck)
        );

        foreach ($this->DB->fetchRows() as $Row) {
            $Url = new InvalidUrl($Row);
            $Host = parse_url($Url->Url, PHP_URL_HOST);

            # if we haven't queued any checks against this host while queuing
            # our current batch of checks, then this one can be queued
            if (!isset($this->DomainsQueuedForChecking[$Host])) {
                $Urls[] = $Url;
                $this->DomainsQueuedForChecking[$Host] = true;
            }

            if (count($Urls) >= $NumToCheck) {
                break;
            }
        }

        return $Urls;
    }

    /**
     * Handle resource modification.
     * @param \Metavus\Record $Resource Resource that was modified.
     *   (needs to be \Metavus\Record here to distinguish from
     *   \Metavus\Plugins\UrlChecker\Record and because AF passes in a
     *   \Metavus\Record when signaling the events)
     */
    public function resourceModify(\Metavus\Record $Resource)
    {
        # get the list of fields that we will check for this resource
        $FieldsToCheck = $this->getFieldsToCheck($Resource->getSchemaId());

        foreach ($FieldsToCheck as $Field) {
            $Urls = $this->getUrlsFromField($Resource, $Field);

            # if this field has no urls, move along to the next one
            if (count($Urls) == 0) {
                continue;
            }

            # clean out history entries for URLs that are no longer associated
            # with this field and record
            $EscapedUrls = array_map(
                function ($x) {
                    return "'".addslashes($x)."'";
                },
                $Urls
            );
            $this->DB->query(
                " DELETE FROM UrlChecker_UrlHistory"
                ." WHERE RecordId = '".intval($Resource->id())."'"
                ." AND FieldId = '".intval($Field->id())."'"
                ." AND Url NOT IN (".implode(",", $EscapedUrls).")"
            );
        }
    }

    /**
     * Handle resource deletion.
     * @param \Metavus\Record $Resource Resource that is about to be deleted.
     *   (needs to be \Metavus\Record here to distinguish from
     *   \Metavus\Plugins\UrlChecker\Record and because AF passes in a
     *   \Metavus\Record when signaling the events)
     */
    public function resourceDelete(\Metavus\Record $Resource)
    {
        $this->DB->query(
            "DELETE FROM UrlChecker_UrlHistory"
            ." WHERE RecordId = '".intval($Resource->id())."'"
        );
        $this->DB->query(
            "DELETE FROM UrlChecker_RecordHistory"
            ." WHERE RecordId = '".intval($Resource->id())."'"
        );
    }

    /**
     * Handle the addition of a new URL field, setting it to check by default.
     * @param int $FieldId ID of field.
     */
    public function addField($FieldId)
    {
        $FieldsToCheck = $this->configSetting("FieldsToCheck") ?? [];

        $Field = new MetadataField($FieldId);
        if ($Field->type() == MetadataSchema::MDFTYPE_URL) {
            $FieldsToCheck[] = $FieldId;
            $this->configSetting("FieldsToCheck", $FieldsToCheck);
        }
    }

    /**
     * Handle the deletion of a metadata field, removing it from the list of
     *   fields to check.
     * @param int $FieldId ID of field.
     */
    public function removeField($FieldId)
    {
        $FieldsToCheck = $this->configSetting("FieldsToCheck");

        # if we're not checking any fields, bail because there's nothing to do
        if (!$FieldsToCheck) {
            return;
        }

        # if we were checking this field, stop doing so
        $Key = array_search($FieldId, $FieldsToCheck);
        if ($Key !== false) {
            unset($FieldsToCheck[$Key]);
            $this->configSetting("FieldsToCheck", $FieldsToCheck);

            # and clean out any URL history for this field
            $this->DB->query(
                "DELETE FROM UrlChecker_UrlHistory"
                ." WHERE FieldId = '".intval($FieldId)."'"
            );
        }
    }

    /**
     * Handle changes to plugin configuration.
     * @param string $PluginName Name of plugin
     * @param string $ConfigSetting Setting to change.
     * @param mixed $OldValue Old value of setting.
     * @param mixed $NewValue New value of setting.
     */
    public function handleConfigChange(
        $PluginName,
        $ConfigSetting,
        $OldValue,
        $NewValue
    ) {
        if ($PluginName == $this->Name && $ConfigSetting == "DontCheck") {
            $this->queueUniqueTask(
                "processChangedExclusionRules",
                [],
                ApplicationFramework::PRIORITY_LOW,
                "Remove URL checker data for resources excluded "
                ."by URLChecker rules change"
            );
        }
    }

    /**
     * Process a change in exclusion rules
     */
    public function processChangedExclusionRules()
    {
        # Clean out invalid URLs from resources that would now be skipped
        #  by our exclusion rules.This is done to prevent them from
        #  continuing to appear in the Results page after being excluded.
        $DB = new Database();
        $DB->query(
            "SELECT DISTINCT RecordId AS RecordId "
            ."FROM UrlChecker_UrlHistory WHERE StatusCode >= 300"
        );
        $ResourceIds = $DB->fetchRows();

        $SkippedResourceIds = [];

        foreach ($ResourceIds as $Row) {
            $Resource = new Record($Row["RecordId"]);
            if ($this->shouldNotCheckUrls($Resource)) {
                $SkippedResourceIds[] = $Row["RecordId"];
            }
        }

        if (count($SkippedResourceIds) > 0) {
            foreach (array_chunk($SkippedResourceIds, 100) as $Chunk) {
                $DB->query(
                    "DELETE FROM UrlChecker_UrlHistory "
                    ."WHERE RecordId IN (".implode(",", $Chunk).")"
                );
            }
        }
    }

    /**
     * Get list of options to display for the exclusion rule config setting.
     * @return array List of options.
     */
    public function getRuleOptionValues() : array
    {
        $this->loadConfigOptionValues();
        return $this->RuleOptions;
    }

    /**
     * Get list of options to display for the fields to check config setting.
     * @return array List of options.
     */
    public function getFieldsToCheckOptionValues() : array
    {
        $this->loadConfigOptionValues();
        return $this->FieldsToCheckOptions;
    }

    # ---- PRIVATE INTERFACE -------------------------------------------------

    /**
     * Load option values to be used on the config screen.
     */
    private function loadConfigOptionValues()
    {
        if ($this->OptionValuesLoaded) {
            return;
        }

        foreach (MetadataSchema::getAllSchemas() as $Schema) {
            $SchemaFields = $Schema->getFields(
                MetadataSchema::MDFTYPE_FLAG |
                    MetadataSchema::MDFTYPE_TIMESTAMP |
                    MetadataSchema::MDFTYPE_URL |
                    MetadataSchema::MDFTYPE_PARAGRAPH
            );

            foreach ($SchemaFields as $Field) {
                $QualifiedFieldName = $Schema->name()." : ".$Field->name();

                switch ($Field->type()) {
                    case MetadataSchema::MDFTYPE_FLAG:
                        $this->RuleOptions[$Field->id().":".self::FLAG_OFF_VALUE] =
                            $QualifiedFieldName
                            ." is set to \"".$Field->flagOffLabel()."\"";
                        $this->RuleOptions[$Field->id().":".self::FLAG_ON_VALUE] =
                            $QualifiedFieldName
                            ." is set to \"".$Field->flagOnLabel()."\"";

                        break;

                    case MetadataSchema::MDFTYPE_TIMESTAMP:
                        $this->RuleOptions[$Field->id().":PAST"] =
                            $QualifiedFieldName." is in the past";
                        break;

                    case MetadataSchema::MDFTYPE_PARAGRAPH:
                        if (!$Field->allowHTML()) {
                            break;
                        }
                        /* fall through */

                    case MetadataSchema::MDFTYPE_URL:
                        $this->FieldsToCheckOptions[$Field->id()] = $QualifiedFieldName;
                        break;
                }
            }
        }

        $this->OptionValuesLoaded = true;
    }

    /**
     * Set up default release/withhold/autofix actions on plugin installation.
     */
    private function configureDefaultActions()
    {
        # actions we need to configure
        $Actions = ["Withhold", "Release", "Autofix"];

        $NewSettings = [];

        # configure default actions for each schema
        foreach (MetadataSchema::getAllSchemas() as $Schema) {
            # assume we won't find any fields to set
            $ToSet = null;
            $PublishedVal = null;
            $UnpublishedVal = null;

            # if this schema has a Record Status field containing "Published" and
            # "Extinct" values, configure our actions to toggle those
            # Meant to handle default schemas with no actions configured,
            # not meant for every possible schema
            if ($Schema->getItemIdByName("Record Status") !== false) {
                $Field = $Schema->getItemByName("Record Status");
                $Factory = $Field->getFactory();

                $PublishedId = $Factory->getItemIdByName("Published");
                $DeaccId = $Factory->getItemIdByName("Extinct");

                if ($PublishedId !== false && $DeaccId !== false) {
                    $ToSet = $Field->id();
                    $PublishedVal = $PublishedId;
                    $UnpublishedVal = $DeaccId;
                }
            } elseif ($Schema->getItemIdByName("Release Flag") !== false) {
                # otherwise, if this schema has a Release Flag field, configure our actions
                # to toggle that
                $ToSet = $Schema->getItemIdByName("Release Flag");

                $PublishedVal = "1";
                $UnpublishedVal = "0";
            }

            # for each action, determine the changes we want to make
            foreach ($Actions as $Action) {
                $Changes = [];
                if ($ToSet !== null) {
                    $Changes[] = [
                        "FieldId" => $ToSet,
                        "Op" => Record::CHANGE_SET,
                        "Val" => ($Action == "Withhold") ?
                                $UnpublishedVal : $PublishedVal,
                    ];
                }

                $NewSettings[$Action][$Schema->id()] = $Changes;
            }
        }

        # save the list of changes we've computed
        foreach ($Actions as $Action) {
            $this->configSetting($Action."Configuration", $NewSettings[$Action]);
        }
    }

    /**
     * Estimate how long it will take to check all the URLs for a resource.
     * @param Record $Resource Resource for the estimate.
     * @return int Expected number of seconds
     */
    private function estimateCheckTime($Resource)
    {
        $Fields = $this->getFieldsToCheck($Resource->getSchemaId());

        # pull out the time taken for the checks when they last ran
        $this->DB->query(
            "SELECT Url, CheckDuration FROM UrlChecker_UrlHistory "
            ."WHERE RecordId=".intval($Resource->id())
        );
        $LastFetchDuration = $this->DB->fetchColumn("CheckDuration", "Url");

        # sum up these times, adding on a margin of 5s per url or 30s
        # for urls where we have no timing information
        $Estimate = 0;
        foreach ($Fields as $Field) {
            $Url = $Resource->get($Field);
            $Estimate += (isset($LastFetchDuration[$Url]) ?
                          $LastFetchDuration[$Url] + 5 : 30);
        }

        return $Estimate;
    }

    /**
     * Determine whether or not the URLs for the given resource should be
     * checked.
     * @param Record $Resource Resource.
     * @return bool TRUE if the URLs should not be checked and FALSE otherwise
    */
    private function shouldNotCheckUrls($Resource)
    {
        $Rules = $this->configSetting("DontCheck");

        # if there are no exclusions, then nothing should be excluded
        if (!$Rules) {
            return false;
        }

        # check if the resource matches any of the rules
        foreach ($Rules as $Rule) {
            # parse out the field ID and flag value
            list($FieldId, $Flag) = explode(":", $Rule);

            try {
                $Field = new MetadataField(intval($FieldId));
            } catch (Exception $e) {
                # If the ID was invalid, causing an exception to be thrown,
                # move along to the next rule.
                continue;
            }

            # If this rule applies to a field that we couldn't retrieve,
            #  skip it.
            if ($Field->status() != MetadataSchema::MDFSTAT_OK) {
                continue;
            }

            # If this rule applies to a different schema, skip it.
            if ($Field->schemaId() != $Resource->getSchemaId()) {
                continue;
            }

            $Value = $Resource->get($Field);
            if (empty($Value)) {
                $Value = self::FLAG_OFF_VALUE;
            }

            switch ($Field->type()) {
                case MetadataSchema::MDFTYPE_FLAG:
                    # the rule matches if the field value equals the flag value
                    # specified in the rule.the checks with empty() are used in case
                    # NULLs are in the database, which are assumed to be "off"
                    if ($Value == $Flag) {
                        return true;
                    }
                    break;
                case MetadataSchema::MDFTYPE_TIMESTAMP:
                    if ($Flag == "PAST" && strtotime($Value) < time()) {
                        return true;
                    }
                    break;
                default:
                    break;
            }
        }

        return false;
    }

    /**
     * Determine if the URLs in a record should be checked based on which
     * domains have already been queued for a check in the current batch.
     * @param Record $Record Record to check URLs for.
     * @return bool TRUE when the given record should be checked, FALSE otherwise.
     *   when TRUE is returned, domains from this record's URLs will be added to
     *   $this->DomainsQueuedForChecking.
     */
    private function shouldCheckDomainsFromRecordUrls(Record $Record): bool
    {
        # get the list of fields that we will check for this resource
        $FieldsToCheck = $this->getFieldsToCheck($Record->getSchemaId());

        # if we have no fields to check, then we can't have already checked those domains
        if (count($FieldsToCheck) == 0) {
            return false;
        }

        # list of hosts referenced by this record
        $Domains = [];

        # iterate over all our checkable fields
        foreach ($FieldsToCheck as $Field) {
            $Urls = $this->getUrlsFromField($Record, $Field);

            # nothing to do if field has no URLs
            if (count($Urls) == 0) {
                continue;
            }

            # iterate over URLs in field
            foreach ($Urls as $Url) {
                $Domain = parse_url($Url, PHP_URL_HOST);

                # if we've already queued a URL for checking that has the
                # same domain, then this record should not be queued for checking
                if (isset($this->DomainsQueuedForChecking[$Domain])) {
                    return false;
                }

                # otherwise add this domain to our list
                $Domains[] = $Domain;
            }
        }

        # add the domains from this record to our list of domains that are
        # queued for checking
        foreach ($Domains as $Domain) {
            $this->DomainsQueuedForChecking[$Domain] = true;
        }

        # report that this record should be checked
        return true;
    }

    /**
     * Extract all the Urls from a given field for a provided record.
     * @param \Metavus\Record $Record Subject record.
     * @param MetadataField $Field Field to search.
     * @return array Extracted Urls
     */
    private function getUrlsFromField(
        \Metavus\Record $Record,
        MetadataField $Field
    ): array {
        $Urls = [];

        switch ($Field->type()) {
            case MetadataSchema::MDFTYPE_URL:
                $FieldData = $Record->get($Field);
                if (!is_null($FieldData) && strlen(trim($FieldData)) > 0) {
                    $Urls[] = $FieldData;
                }
                break;

            case MetadataSchema::MDFTYPE_PARAGRAPH:
                if ($Field->allowHTML()) {
                    $Text = $Record->get($Field);

                    $Patterns = [
                        '%<a\b[^>]*\bhref="([^"]+)"%i',
                        '%<a\b[^>]*\bhref=\'([^\'])+\'"%i',
                        '%<img\b[^>]*\bsrc="([^"]+)"%i',
                        '%<img\b[^>]*\bsrc=\'([^\']+)\'%i',
                    ];

                    foreach ($Patterns as $Pattern) {
                        preg_match_all($Pattern, $Text, $Matches);
                        if (count($Matches[1])) {
                            $Urls = array_merge(
                                $Urls,
                                $Matches[1]
                            );
                        }
                    }

                    # filter out URLs that contain an insertion keyword
                    # (e.g., for scaled images)
                    $Urls = array_filter(
                        $Urls,
                        function ($Url) {
                            if (preg_match("%{{[A-Za-z0-9]+(\|[^}]+)?}}%", $Url)) {
                                return false;
                            }
                            return true;
                        }
                    );
                }
                break;
        }

        return $Urls;
    }

    /**
     * Update the resource history for the given resource.
     * @param Record $Resource The resource for which to update the history.
     */
    private function updateResourceHistory($Resource)
    {
        $this->DB->query(
            "INSERT INTO UrlChecker_RecordHistory"
            ." (RecordId) VALUES (".$Resource->id().")"
            ." ON DUPLICATE KEY UPDATE CheckDate = CURRENT_TIMESTAMP"
        );
    }

    /**
     * Check a given Url, updating failure information in the database based
     *   on the result.
     * @param int $RecordId Resource that owns this Url.
     * @param int $FieldId Field that owns this Url.
     * @param string $Url Url to check.
     */
    private function checkUrl(
        int $RecordId,
        int $FieldId,
        string $Url
    ) {
        # get the url's http status
        $Start = time();
        $Info = $this->getHttpInformation($Url);
        $CheckDuration = ceil(time() - $Start);

        # SQL to clear failures history for our Url
        $DeleteHistoryQuery = "DELETE FROM UrlChecker_UrlHistory"
            ." WHERE RecordId = '".$RecordId."'"
            ." AND FieldId = '".$FieldId."'"
            ." AND Url = '".addslashes($Url)."'";

        # remove old failure data, if any, if the url is ok
        if ($Info["StatusCode"] == -1 || ($Info["StatusCode"] == 200
            && $this->hasValidContent($Url))) {
            $this->DB->query($DeleteHistoryQuery);
            return;
        }

        # if this was a 3xx redirect to a page that is okay
        if ($Info["StatusCode"] >= 300 && $Info["StatusCode"] < 400 &&
            $Info["FinalStatusCode"] == 200) {
            # see if we're just switching http/https or adding/removing www.
            $PrefixToStrip = "%^https?://(www\.)?%";
            $Src = preg_replace($PrefixToStrip, "", $Info["Url"]);
            $Dst = preg_replace($PrefixToStrip, "", $Info["FinalUrl"]);
            if ($Src == $Dst) {
                $this->DB->query($DeleteHistoryQuery);
                return;
            }
        }

        # look up existing failure information
        $this->DB->query(
            "SELECT * FROM UrlChecker_UrlHistory"
            ." WHERE RecordId = '".intval($RecordId)."'"
            ." AND FieldId = '".intval($FieldId)."'"
            ." AND Url = '".addslashes($Url)."'"
        );

        # try to use an existing TimesInvalid value if possible and the
        # HTTP info is not too different
        $TimesInvalid = 1;
        $Hidden = 0;
        if (false !== ($Row = $this->DB->fetchRow())
            && $Row["StatusCode"] == strval($Info["StatusCode"])
            && $Row["FinalStatusCode"] == strval($Info["FinalStatusCode"])) {
            # the URL hasn't changed at all
            if ($Row["FinalUrl"] == $Info["FinalUrl"]) {
                $TimesInvalid = intval($Row["TimesInvalid"]) + 1;
                $Hidden = intval($Row["Hidden"]);
            } elseif ($Row["StatusCode"][0] == "3" && $Info["UsesCookies"]) {
                # if the server uses cookies, and there is a redirect, the
                # URL is likely to change every time a check takes place.
                # thus, only check the host portions if those conditions are
                # true
                $DbUrl = @parse_url($Row["FinalUrl"]);
                $NewUrl = @parse_url($Info["FinalUrl"]);

                if ($DbUrl && $NewUrl && isset($DbUrl["host"]) && isset($NewUrl["host"])
                    && $DbUrl["host"] == $NewUrl["host"]) {
                    $TimesInvalid = intval($Row["TimesInvalid"]) + 1;
                    $Hidden = intval($Row["Hidden"]);
                }
            }
        }

        if ($Info["FinalStatusCode"] == 200 && !$this->hasValidContent($Info["FinalUrl"])) {
            $IsFinalUrlInvalid = 1;
        } else {
            $IsFinalUrlInvalid = 0;
        }

        # delete any existing row and create a new one with updated information
        $this->DB->query("LOCK TABLES UrlChecker_UrlHistory WRITE");
        $this->DB->query($DeleteHistoryQuery);
        $this->DB->query(
            "INSERT INTO UrlChecker_UrlHistory SET"
            ." RecordId = '".intval($RecordId)."',"
            ." FieldId = '".intval($FieldId)."',"
            ." CheckDuration = '".intval($CheckDuration)."',"
            ." Hidden = '".$Hidden."',"
            ." TimesInvalid = ".intval($TimesInvalid).","
            ." Url = '".addslashes($Url)."',"
            ." StatusCode = '".intval($Info["StatusCode"])."',"
            ." ReasonPhrase = '".addslashes($Info["ReasonPhrase"])."',"
            ." IsFinalUrlInvalid = '".$IsFinalUrlInvalid."',"
            ." FinalUrl = '".addslashes($Info["FinalUrl"])."',"
            ." FinalStatusCode = '".intval($Info["FinalStatusCode"])."',"
            ." FinalReasonPhrase = '".addslashes($Info["FinalReasonPhrase"])."'"
        );
        $this->DB->query("UNLOCK TABLES");
    }

    /**
     * Get an URL's status info.If there is no redirection, this will be the
     * status line for the URL.If there are redirects, this will be the status
     * line for the URL and the status line for the last URL after redirection.
     * @param string $Url URL
     * @return array an array with the same fields as an HttpInfo object
     */
    private function getHttpInformation($Url)
    {
        # information for the URL
        list($Info, $Redirect) = $this->getHttpInformationAux($Url);

        # information for redirects, if any
        if (!is_null($Redirect)) {
            $FinalUrl = "";
            $FinalInfo = [];

            $MaxIterations = 5;
            while (isset($Redirect) && --$MaxIterations >= 0) {
                $FinalUrl = $Redirect;
                list($FinalInfo, $Redirect) =
                    $this->getHttpInformationAux($Redirect);

                $Info["UsesCookies"] = $Info["UsesCookies"] || $FinalInfo["UsesCookies"];

                if (is_null($Redirect)) {
                    unset($Redirect);
                }
            }

            $Info["FinalUrl"] = $FinalUrl;
            $Info["FinalStatusCode"] = $FinalInfo["StatusCode"];
            $Info["FinalReasonPhrase"] = $FinalInfo["ReasonPhrase"];
        }

        return $Info;
    }

    /**
     * Auxiliary function for self::GetHttpInformation().Gets the HTTP
     * information on one URL.Note that this only supports HTTP and HTTPS.
     * @param string $Url URL
     * @return array an array with the same fields as an HttpInfo object
     */
    private function getHttpInformationAux($Url)
    {
        # this should be an HttpInfo object but some versions of PHP
        # segfault when using them, for an unknown reason
        $Info = ["Url" => "", "StatusCode" => -1, "ReasonPhrase" => "",
            "FinalUrl" => "", "FinalStatusCode" => -1, "FinalReasonPhrase" => "",
            "UsesCookies" => false
        ];

        # blank url (code defaults to -1, i.e., not checked)
        if (!strlen(trim($Url))) {
            return [$Info, null];
        }

        # default to HTTP if not protocol is specified
        if (!@preg_match('/^[a-z]+:/', $Url)) {
            $Url = "http://".$Url;
        }

        # only check HTTP/HTTPS URLs
        if (!@preg_match('/^https?:\/\//', $Url)) {
            return [$Info, null];
        }

        # assume that we can't connect to the URL
        $Info["Url"] = $Url;
        $Info["StatusCode"] = 0;

        # make sure there are no spaces in the url and parse it
        $ParsedUrl = @parse_url(str_replace(" ", "%20", $Url));

        if (!$ParsedUrl || !isset($ParsedUrl["host"])) {
            return [$Info, null];
        }

        $HostName = $ParsedUrl["host"];

        # username and password specified in the URL, add to the hostname
        if (isset($ParsedUrl["user"]) && strlen($ParsedUrl["user"]) > 0 &&
            isset($ParsedUrl["pass"]) && strlen($ParsedUrl["pass"]) > 0) {
            $HostName = $ParsedUrl["user"].":".$ParsedUrl["pass"]."@".$HostName;
        }

        # port specified in the URL, so get it out
        if (isset($ParsedUrl["port"])) {
            $Port = intval($ParsedUrl["port"]);
        }

        # HTTPS needs to use the ssl:// protocol with fsockopen
        if (isset($ParsedUrl["scheme"]) && $ParsedUrl["scheme"] == "https") {
            $HostName = "ssl://".$HostName;

            # default to port 443 if no port is specified
            if (!isset($Port)) {
                $Port = 443;
            }
        }

        # default to port 80 if no port specified
        if (!isset($Port)) {
            $Port = 80;
        }

        $Context = stream_context_create([
            "ssl" => [
                "verify_peer" => $this->configSetting("VerifySSLCerts"),
            ],
        ]);

        $Stream = stream_socket_client(
            $HostName.":".$Port,
            $ErrNo,
            $ErrStr,
            self::CONNECTION_TIMEOUT,
            STREAM_CLIENT_CONNECT,
            $Context
        );

        if ($Stream === false) {
            return [$Info, null];
        }

        # construct the path that's going to be GET'ed
        if (isset($ParsedUrl["path"])) {
            $Path = $ParsedUrl["path"];

            if (isset($ParsedUrl["query"])) {
                $Path .= "?".$ParsedUrl["query"];
            }
        } else {
            $Path = "/";
        }

        # basic headers required for HTTP version 1.1
        $RequestHeaders = "GET ".$Path." HTTP/1.1\r\n";
        $RequestHeaders .= "Host: ".$ParsedUrl["host"]."\r\n";

        # set the User-Agent header since some servers erroneously require it
        $RequestHeaders .= "User-Agent: URL-Checker/".$this->Version." "
           ."METAVUS/".METAVUS_VERSION." PHP/".PHP_VERSION."\r\n";

        # some servers erroneously require the Accept header too
        $RequestHeaders .= "Accept: text/html,application/xhtml+xml,"
            ."application/xml;q=0.9,*/*;q=0.8\r\n";

        # final newline to signal that we're done sending headers
        $RequestHeaders .= "\r\n";

        if (false === fwrite($Stream, $RequestHeaders)) {
            # couldn't send anything
            fclose($Stream);
            return [$Info, null];
        }

        # HTTP status line
        if (!feof($Stream) && false !== ($Line = fgets($Stream))) {
            # remove trailing newline from the HTTP status line
            $Line = trim($Line);

            $StatusLine = new StatusLine($Line);
            $Info["StatusCode"] = $StatusLine->getStatusCode();
            $Info["ReasonPhrase"] = $StatusLine->getReasonPhrase();
        } else {
            # the server responded with nothing so mark the URL as an internal
            # server error (500)
            fclose($Stream);
            $Info["StatusCode"] = 500;
            $Info["ReasonPhrase"] = "Internal Server Error";
            return [$Info, null];
        }

        # this might cause hangs for line > 8KB. trim() removes trailing newline
        /* @phpstan-ignore-next-line */
        while (!feof($Stream) && (($Line = fgets($Stream)) !== false)) {
            $Line = trim($Line);

            # stop before reading any content
            if ($Line == "") {
                break;
            }

            # a Location header
            if (substr($Line, 0, 9) == "Location:") {
                list(, $Location) = explode(":", $Line, 2);
                $Location = ltrim($Location);
            }

            # a Set-Cookie header
            if (substr($Line, 0, 11) == "Set-Cookie:") {
                $Info["UsesCookies"] = true;
            }
        }

        # given a Location value; need to make sure it's absolute
        if (isset($Location) && strlen($Location) && substr($Location, 0, 4) != "http") {
            # relative path, relative URI, so add in the path info
            if ($Location[0] != "/") {
                $BasePath = isset($ParsedUrl["path"]) ?
                    dirname($ParsedUrl["path"]) : "";
                $Location = $BasePath."/".$Location;
            }

            if (substr($HostName, 0, 6) == "ssl://") {
                $Location = "https://".substr($HostName, 5).$Location;
            } else {
                $Location = "http://".$HostName.$Location;
            }
        }

        return [$Info, isset($Location) ? $Location : null];
    }

    /**
     * Determine if a given URL has valid content, that is, if it doesn't match
     * some rudimentary regular expressions.Checks for "Page Not Found"-type
     * strings.
     * @param string $Url URL
     * @return bool TRUE if the content for the given URL is valid, FALSE otherwise
     */
    private function hasValidContent($Url)
    {
        # set up stream options
        $Options = [
            "http" => [
                # set the default protocol version to 1.1, this may cause issues with
                # PHP < 5.3 if the request isn't HTTP 1.1 compliant
                "protocol_version" => 1.1,

                # timeout
                "timeout" => self::CONNECTION_TIMEOUT,

                # set the User-Agent HTTP header since some servers
                # erroneously require it
                "user_agent" => "URL-Checker/".$this->Version." "
                    ."Metavus/".METAVUS_VERSION." PHP/".PHP_VERSION,

                # some servers erroneously require the Accept header too
                "header" => "Accept: text/html,application/xhtml+xml,"
                    ."application/xml;q=0.9,*/*;q=0.8"

                    # try to prevent hangs in feof by telling the server to close the
                    # connection after retrieving all of the content
                    ."\r\nConnection: close",

                # fetch content even when the HTTP status code is not 200
                "ignore_errors" => true,
            ]
        ];

        $Stream = stream_context_create($Options);

        # escape spaces so that we don't mess up the http method header line
        $Url = str_replace(" ", "%20", $Url);

        $Handle = @fopen($Url, "r", false, $Stream);
        if ($Handle === false) {
            return true;
        }

        # sleep for 0.15s to allow some of the content to buffer to avoid having
        # the opening HTML tag not show up in the first fread
        usleep(150000);

        # get the first 8KB and do a basic check to see if the file is HTML.
        # since fread might stop before getting 8KB, e.g., if a packet is
        # received or the server is slow, there is a chance that the file is
        # HTML, but it's opening tag won't have arrived in the first fread, and
        # therefore won't be checked.this should be OK since it probably means
        # the server is really slow and it shouldn't be checked anyway
        $Html = @fread($Handle, 8192);
        if ($Html === false || strpos($Html, "<html") === false) {
            return true;
        }

        # this will be used to prevent hangs in feof in case the server doesn't
        # support the Connection header
        $Time = microtime(true);

        # read until the end of the file, the timeout is reached, or if at least
        # 500 KB have been read
        $Failsafe = 1000;
        while (!feof($Handle) && (microtime(true) - $Time) < self::CONNECTION_TIMEOUT
               && strlen($Html) < 512000 && $Failsafe--) {
            $Chunk = @fread($Handle, 8192);
            if ($Chunk === false) {
                return true;
            }
            $Html .= $Chunk;
        }

        fclose($Handle);

        # parse out the title and the body to search within
        $Title = (preg_match('/<title[^>]*>(.*?)<\/title>/is', $Html, $Matches))
            ? trim($Matches[1]) : "" ;
        $Body = (preg_match('/<body[^>]*>(.*?)<\/body>/is', $Html, $Matches))
            ? trim($Matches[1]) : "";
        $Html = $Title." ".$Body;

        # strip out tags that contain data that is probably not HTML
        $Html = preg_replace(
            '/<(script|noscript|style)[^>]*>.*?<\/\1>/is',
            '',
            $Html
        );

        # remove HTML tags so we only have text to search
        $Html = strip_tags($Html);

        if (preg_match('/(file|url|page|document)\s+([^\s]+\s+)?(couldn\'t\s+be|'
            .'could\s+not\s+be|cannot\s+be|can\'t\s+be|was\s+not)\s+found/i', $Html)
        ) {
            return false;
        } elseif (preg_match('/(file|url|page|404|document)\s+not\s+found|'
            .'(http|error)\s+404/i', $Html)
        ) {
            return false;
        } elseif (preg_match('/(couldn\'t|could\s+not|cannot|can\'t)\s+find\s+'
            .'(the|that)\s+(file|url|page|document)/i', $Html)
        ) {
            return false;
        }

        return true;
    }

    /**
     * Queue tasks that check individual resources.
     * @param Record $Resource Resource to be checked.
     */
    private function queueResourceCheckTask(Record $Resource)
    {
        $TaskDescription =
           "Validate URLs associated with <a href=\"r".$Resource->id()."\"><i>"
           .$Resource->getMapped("Title")."</i></a>";

        $this->queueUniqueTask(
            "checkResourceUrls",
            [$Resource->id(), $Resource->getCheckDate()],
            $this->configSetting("TaskPriority"),
            $TaskDescription
        );
    }

    /**
     * Remove any stale data from deleted resources or changed URLs.
     */
    private function removeStaleData()
    {
        static $RemovedStaleData = false;

        # so that the following queries are executed only once per load
        if ($RemovedStaleData) {
            return;
        }

        # clean URL history table of data for fields that aren't URL or Paragraph fields
        # (from when field types are changed)
        $this->DB->query(
            "DELETE FROM UrlChecker_UrlHistory WHERE "
            ."FieldId NOT IN (SELECT FieldId FROM MetadataFields "
            ."WHERE FieldType IN ('Url', 'Paragraph')) "
        );

        # remove entries for records that no longer exist
        $this->DB->query(
            "DELETE FROM UrlChecker_UrlHistory WHERE "
            ."RecordId NOT IN (SELECT RecordId FROM Records)"
        );
        $this->DB->query(
            "DELETE FROM UrlChecker_RecordHistory WHERE "
            ."RecordId NOT IN (SELECT RecordId FROM Records)"
        );

        $RemovedStaleData = true;
    }

    /**
     * Get metadata fields that should be checked for broken links, optionally
     *   restricted to a specific schema.
     * @param int $SchemaId Schema restriction (OPTIONAL, default none)
     * @return array of all the metadata fields in the given schema
     */
    private function getFieldsToCheck($SchemaId = null)
    {
        static $Fields;

        if (!isset($Fields)) {
            $FieldsToCheck = $this->configSetting("FieldsToCheck");

            $Fields = [];
            foreach ($FieldsToCheck as $FieldId) {
                if (MetadataSchema::fieldExistsInAnySchema($FieldId)) {
                    $Fields[] = new MetadataField($FieldId);
                }
            }
        }

        if ($SchemaId === null) {
            return $Fields;
        } else {
            $Result = [];
            foreach ($Fields as $Field) {
                if ($Field->SchemaId() == $SchemaId) {
                    $Result[] = $Field;
                }
            }

            return $Result;
        }
    }

    /**
     * Get the date/time that the URL checking method will run.
     * @return string|null Returns the date/time that the URL checking method will run.
     */
    private function getDateOfNextCheck()
    {
        $AF = ApplicationFramework::getInstance();

        # find the URL checking method
        foreach ($AF->getKnownPeriodicEvents() as $PeriodicEvent) {
            $Callback = $PeriodicEvent["Callback"];

            # if its the URL checking method
            if (is_array($Callback)
                && $Callback[0] instanceof PluginCaller
                && $Callback[0]->getCallbackAsText()
                        == "UrlChecker::QueueResourceCheckTasks"
            ) {
                # return the next run date
                return date("Y-m-d H:i:s", $PeriodicEvent["NextRun"]);
            }
        }

        # no next run date
        return null;
    }

    private $DB;

    # domains queued for a check in the current batch of checks
    # (used to prevent a single domain from being repeatedly checked in the
    # same batch)
    private $DomainsQueuedForChecking = [];

    # values for options shown on config screen
    private $OptionValuesLoaded = false;
    private $RuleOptions = [];
    private $FieldsToCheckOptions = [];

    private $SqlTables = [
        "RecordHistory" => "CREATE TABLE IF NOT EXISTS UrlChecker_RecordHistory (
                RecordId       INT,
                CheckDate      TIMESTAMP,
                PRIMARY KEY    (RecordId)
            )",
        "UrlHistory" => "CREATE TABLE IF NOT EXISTS UrlChecker_UrlHistory (
                RecordId            INT,
                FieldId             INT,
                Hidden              INT,
                CheckDate           TIMESTAMP,
                CheckDuration       INT DEFAULT NULL,
                TimesInvalid        INT,
                Url                 TEXT,
                StatusCode          SMALLINT,
                ReasonPhrase        TEXT,
                IsFinalUrlInvalid   INT,
                FinalUrl            TEXT,
                FinalStatusCode     SMALLINT,
                FinalReasonPhrase   TEXT,
                INDEX               Index_RF (RecordId, FieldId)
            )",
    ];
}
