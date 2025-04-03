<?PHP
#
#   FILE:  SystemConfiguration.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2020-2023 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus;
use Exception;
use ScoutLib\ApplicationFramework;
use ScoutLib\Email;
use ScoutLib\StdLib;

/**
 * System configuration setting storage, retrieval, and editing definitions class.
 */
class SystemConfiguration extends Configuration
{
    # ---- LEGACY SUPPORT ----------------------------------------------------
    # (to be removed once all settings slated to be migrated to
    #       InterfaceSettings_Default have been moved)
    # (other code labeled with "LEGACY SUPPORT" should also be removed then)

    /**
     * Get array field value.
     * @param string $FieldName Name of field.
     * @return array Current value for field.
     */
    public function getArray(string $FieldName): array
    {
        if ($this->fieldExists($FieldName)) {
            return parent::getArray($FieldName);
        }
        if (!isset($this->IntCfg)) {
            $this->IntCfg = InterfaceConfiguration::getInstance();
        }
        return $this->IntCfg->getArray($FieldName);
    }

    /**
     * Get boolean field value.
     * @param string $FieldName Name of field.
     * @return bool Current value for field.
     */
    public function getBool(string $FieldName): bool
    {
        if ($this->fieldExists($FieldName)) {
            return parent::getBool($FieldName);
        }
        if (!isset($this->IntCfg)) {
            $this->IntCfg = InterfaceConfiguration::getInstance();
        }
        return $this->IntCfg->getBool($FieldName);
    }


    /**
     * Get date/time field value.
     * @param string $FieldName Name of field.
     * @return int Current value for field, as a Unix timestamp.
     */
    public function getDatetime(string $FieldName): int
    {
        if ($this->fieldExists($FieldName)) {
            return parent::getDatetime($FieldName);
        }
        if (!isset($this->IntCfg)) {
            $this->IntCfg = InterfaceConfiguration::getInstance();
        }
        return $this->IntCfg->getDatetime($FieldName);
    }

    /**
     * Get float field value.
     * @param string $FieldName Name of field.
     * @return float Current value for field.
     */
    public function getFloat(string $FieldName): float
    {
        if ($this->fieldExists($FieldName)) {
            return parent::getFloat($FieldName);
        }
        if (!isset($this->IntCfg)) {
            $this->IntCfg = InterfaceConfiguration::getInstance();
        }
        return $this->IntCfg->getFloat($FieldName);
    }

    /**
     * Get integer field value.
     * @param string $FieldName Name of field.
     * @return int Current value for field.
     */
    public function getInt(string $FieldName): int
    {
        if ($this->fieldExists($FieldName)) {
            return parent::getInt($FieldName);
        }
        if (!isset($this->IntCfg)) {
            $this->IntCfg = InterfaceConfiguration::getInstance();
        }
        return $this->IntCfg->getInt($FieldName);
    }

    /**
     * Get string-value (string, email, IP address, or URL) field value.
     * @param string $FieldName Name of field.
     * @return string Current value for field.
     */
    public function getString(string $FieldName): string
    {
        if ($this->fieldExists($FieldName)) {
            return parent::getString($FieldName);
        }
        if (!isset($this->IntCfg)) {
            $this->IntCfg = InterfaceConfiguration::getInstance();
        }
        return $this->IntCfg->getString($FieldName);
    }


    # ---- PRIVATE INTERFACE -------------------------------------------------

    protected static $Instance;

    # (LEGACY SUPPORT)
    protected $IntCfg;

    /**
     * Object constructor.
     */
    protected function __construct()
    {
        $this->DatabaseTableName = "SystemConfiguration";
        $this->SettingDefinitions = [
            # -------------------------------------------------
            "HEADING-Interface" => [
                "Type" => FormUI::FTYPE_HEADING,
                "Label" => "Interface",
            ],
            "DefaultActiveUI" => [
                "Type" => FormUI::FTYPE_OPTION,
                "StorageType" => self::TYPE_STRING,
                "Label" => "Default User Interface",
                "Required" => true,
                # (use regular expression to exclude plugin interfaces from those offered)
                "Options" => (ApplicationFramework::getInstance())->getUserInterfaces(
                    "/^(?![a-zA-Z0-9\/]*plugins\/)[a-zA-Z0-9\/]*interface\/[a-zA-Z0-9%\/]+/"
                ),
                "Default" => "default",
                "Help" => "Determines the user interface new "
                        ."members and logged-out users will view. "
                        ."Individual users may control this option "
                        ."for themselves through their preferences "
                        ."options if multiple interfaces are allowed "
                        ."by the site administrator. Selecting the "
                        ."Set All Users to This Interface checkbox "
                        ."will set each user's interface setting to "
                        ."the user interface selected."
            ],
            "ForceDefaultActiveUI" => [
                "Type" => FormUI::FTYPE_FLAG,
                "Label" => "Set All Users to Default Interface",
                "GetFunction" => function (string $SettingName) {
                    return false;
                },
                "SetFunction" => [ $this, "setAllUserInterfacesToDefault" ],
                "Help" => "When checked, this option will set all user"
                        ." accounts to the above Default User Interface.",
                "Value" => false,
            ],
            "AllowMultipleUIsEnabled" => [
                "Type" => FormUI::FTYPE_FLAG,
                "Label" => "Allow Multiple User Interfaces",
                "Default" => false,
                "Help" => "Determines whether users may use a different "
                        ."user interface when logged in by selecting one "
                        ."in their preferences options. System "
                        ."Administrators may use different interfaces "
                        ."even if this option is disabled."
            ],
            "CommentsAllowHTML" => [
                "Type" => FormUI::FTYPE_FLAG,
                "Label" => "Resource Comments Allow HTML",
                "Default" => false,
                "Help" => "Whether HTML is allowed in resource comments.",
            ],
            # -------------------------------------------------
            "HEADING-Users" => [
                "Type" => FormUI::FTYPE_HEADING,
                "Label" => "Users",
            ],
            "DefaultUserPrivs" => [
                "Type" => FormUI::FTYPE_OPTION,
                "Label" => "Default New User Privileges",
                "AllowMultiple" => true,
                "Rows" => 15,
                "Options" => (new PrivilegeFactory())->getPrivilegeOptions(),
                "Default" => [ 0 ],
                "Help" => "Determines the privilege flags that are "
                        ."given to users after they have "
                        ."registered for an account."
            ],
            # -------------------------------------------------
            "HEADING-Passwords" => [
                "Type" => FormUI::FTYPE_HEADING,
                "Label" => "Passwords",
            ],
            "PasswordMinLength" => [
                "Type" => FormUI::FTYPE_NUMBER,
                "Label" => "Minimum Password Length",
                "Units" => "characters",
                "MinVal" => 6,
                "Default" => 6,
                "Help" => "Passwords must contain at least this many characters."
                                ." (Minimum: <i>6</i>)",
            ],
            "PasswordUniqueChars" => [
                "Type" => FormUI::FTYPE_NUMBER,
                "Label" => "Minimum Unique Characters",
                "Units" => "characters",
                "MinVal" => 4,
                "Default" => 4,
                "Help" => "Passwords must contain at least this many"
                                ." different characters. (Minimum: <i>4</i>)",
            ],
            "PasswordRequiresPunctuation" => [
                "Type" => FormUI::FTYPE_FLAG,
                "Label" => "Require Punctuation Character",
                "Default" => true,
                "Help" => "When set, passwords must contain at least one"
                            ." punctuation character.",
            ],
            "PasswordRequiresMixedCase" => [
                "Type" => FormUI::FTYPE_FLAG,
                "Label" => "Require Mixed-Case Passwords",
                "Default" => true,
                "Help" => "When set, passwords must contain both lower and"
                            ." upper case letters.",
            ],
            "PasswordRequiresDigits" => [
                "Type" => FormUI::FTYPE_FLAG,
                "Label" => "Require Digits",
                "Default" => true,
                "Help" => "When set, passwords must contain at least one number.",
            ],
            # -------------------------------------------------
            "HEADING-Search" => [
                "Type" => FormUI::FTYPE_HEADING,
                "Label" => "Search",
            ],
            "SearchDBEnabled" => [
                "Type" => FormUI::FTYPE_FLAG,
                "Label" => "Search Engine Automatic Updates",
                "Default" => true,
            ],
            "SearchEngineUpdatePriority" => [
                "Type" => FormUI::FTYPE_OPTION,
                "StorageType" => self::TYPE_INT,
                "Label" => "Search Engine Update Task Priority",
                "Options" => [
                    ApplicationFramework::PRIORITY_BACKGROUND => "Background",
                    ApplicationFramework::PRIORITY_LOW => "Low",
                    ApplicationFramework::PRIORITY_MEDIUM => "Medium",
                    ApplicationFramework::PRIORITY_HIGH => "High"
                ],
                "Required" => true,
                "Default" => ApplicationFramework::PRIORITY_LOW,
                "Help" => "The priority given to the tasks that "
                        ."run search engine updates for resources."
            ],
            "SearchTermsRequired" => [
                "Type" => FormUI::FTYPE_OPTION,
                "StorageType" => self::TYPE_BOOL,
                "Label" => "Default Search Term Handling",
                "Options" => [1 => "AND", 0 => "OR"],
                "Default" => true,
                "Help" => "Determines whether AND or OR logic "
                        ."is used when more than one search "
                        ."term is specified. Resource records "
                        ."that contain all specified search "
                        ."terms will be retrieved when AND is "
                        ."selected. Resource records that have "
                        ."any of the search terms specified "
                        ."will be retrieved when OR is selected, "
                        ."but those that have two or more "
                        ."will be ranked higher."
            ],
            "MaxFacetsPerField" => [
                "Type" => FormUI::FTYPE_NUMBER,
                "Label" => "Max Facets Per Field",
                "Help" => "The maximum number of facets to display "
                        ."per field in faceted search.",
                "Required" => true,
                "MinVal" => 2,
                "MaxVal" => 100,
                "RecVal" => 50,
                "Default" => 50,
            ],
            "NumResourcesForSearchFacets" => [
                "Type" => FormUI::FTYPE_NUMBER,
                "Label" => "Resources for Facet Generation",
                "Units" => "resources",
                "MinVal" => 1000,
                "Default" => 5000,
                "Help" => "The maximum number of resources to use when"
                        ." generating search facets.",
            ],
            "AnonSearchCpuLoadCutoff" => [
                "Type" => FormUI::FTYPE_NUMBER,
                "Label" => "System Load Cutoff for Anonymous Searches",
                "MinVal" => 1,
                "DefaultFunction" => function () {
                    $CoreCount = StdLib::getNumberOfCpuCores();
                    return ($CoreCount > 0) ? (int)($CoreCount * 1.2) : 8;
                },
                "Help" => "When the system laod is above this level, searches"
                        ." by anonymous users will not be allowed.",
            ],
            # -------------------------------------------------
            "HEADING-Recommender" => [
                "Type" => FormUI::FTYPE_HEADING,
                "Label" => "Recommender",
            ],
            "RecommenderDBEnabled" => [
                "Type" => FormUI::FTYPE_FLAG,
                "Label" => "Recommender Engine Automatic Updates",
                "Default" => false,
            ],
            "RecommenderEngineUpdatePriority" => [
                "Type" => FormUI::FTYPE_OPTION,
                "StorageType" => self::TYPE_INT,
                "Label" => "Recommender Engine Update Task Priority",
                "Options" => [
                    ApplicationFramework::PRIORITY_BACKGROUND => "Background",
                    ApplicationFramework::PRIORITY_LOW => "Low",
                    ApplicationFramework::PRIORITY_MEDIUM => "Medium",
                    ApplicationFramework::PRIORITY_HIGH => "High"
                ],
                "Required" => true,
                "Default" => ApplicationFramework::PRIORITY_BACKGROUND,
                "Help" => "The priority given to the tasks that "
                        ."run recommender engine updates for resources."
            ],
            # -------------------------------------------------
            "HEADING-Mailing" => [
                "Type" => FormUI::FTYPE_HEADING,
                "Label" => "Mailing",
            ],
            "MailingMethod" => [
                "Type" => FormUI::FTYPE_OPTION,
                "Label" => "Mailing Method",
                "Options" => [
                    Email::METHOD_PHPMAIL => "PHP mail()",
                    Email::METHOD_SMTP => "SMTP",
                ],
                "Help" => "Which mechanism to use when sending email.",
                "GetFunction" => [ $this, "getEmailDeliverySetting" ],
                "SetFunction" => [ $this, "setEmailDeliverySetting" ],
                "ValidateFunction" => [$this, "validateEmailDeliverySettings"],
            ],
            "SmtpServer" => [
                "Type" => FormUI::FTYPE_TEXT,
                "Label" => "SMTP Server",
                "GetFunction" => [ $this, "getEmailDeliverySetting" ],
                "SetFunction" => [ $this, "setEmailDeliverySetting" ],
                "ValidateFunction" => [$this, "validateEmailDeliverySettings"],
                "Help" => "Example: <i>mail.myhost.com</i>",
                "DisplayIf" => [ "MailingMethod" => Email::METHOD_SMTP ],
            ],
            "SmtpPort" => [
                "Type" => FormUI::FTYPE_NUMBER,
                "Label" => "SMTP Port",
                "MinVal" => 1,
                "MaxVal" => 65535,
                "RecVal" => 25,
                "GetFunction" => [ $this, "getEmailDeliverySetting" ],
                "SetFunction" => [ $this, "setEmailDeliverySetting" ],
                "ValidateFunction" => [$this, "validateEmailDeliverySettings"],
                "Help" => "Default: <i>25</i>",
                "DisplayIf" => [ "MailingMethod" => Email::METHOD_SMTP ],
            ],
            "UseAuthenticationForSmtp" => [
                "Type" => FormUI::FTYPE_FLAG,
                "Label" => "Use Authentication for SMTP",
                "GetFunction" => [ $this, "getEmailDeliverySetting" ],
                "SetFunction" => [ $this, "setEmailDeliverySetting" ],
                "ValidateFunction" => [$this, "validateEmailDeliverySettings"],
                "DisplayIf" => [ "MailingMethod" => Email::METHOD_SMTP ],
            ],
            "SmtpUserName" => [
                "Type" => FormUI::FTYPE_TEXT,
                "Label" => "SMTP User Name",
                "GetFunction" => [ $this, "getEmailDeliverySetting" ],
                "SetFunction" => [ $this, "setEmailDeliverySetting" ],
                "ValidateFunction" => [$this, "validateEmailDeliverySettings"],
                "Help" => "Only needed if using authentication for SMTP.",
                "DisplayIf" => [
                    "MailingMethod" => Email::METHOD_SMTP,
                    "UseAuthenticationForSmtp" => true
                ],
            ],
            "SmtpPassword" => [
                "Type" => FormUI::FTYPE_TEXT,
                "Label" => "SMTP Password",
                "GetFunction" => [$this, "getEmailDeliverySetting"],
                "SetFunction" => [$this, "setEmailDeliverySetting"],
                "ValidateFunction" => [$this, "validateEmailDeliverySettings"],
                "Help" => "Only needed if using authentication for SMTP.",
                "DisplayIf" => [
                    "MailingMethod" => Email::METHOD_SMTP,
                    "UseAuthenticationForSmtp" => true
                ],
            ],
            "EmailDeliverySettings" => [
                "Label" => "Opaque Data for Email Delivery Setting Storage",
                "Type" => FormUI::FTYPE_TEXT,
                "Hidden" => true,
                "Default" => null,
            ],
            "EmailLineEnding" => [
                "Type" => FormUI::FTYPE_OPTION,
                "StorageType" => self::TYPE_STRING,
                "Label" => "Email Line Ending",
                "Help" => "<i>CRLF</i> should be used whenever possible.",
                "Options" => [
                    "CRLF" => "CRLF",
                    "CR" => "CR",
                    "LF" => "LF",
                ],
                "Default" => "CRLF",
            ],
            # -------------------------------------------------
            "HEADING-Caching" => [
                "Type" => FormUI::FTYPE_HEADING,
                "Label" => "Caching",
            ],
            "PageCacheEnabled" => [
                "Type" => FormUI::FTYPE_FLAG,
                "Label" => "Enable Page Caching",
                "GetFunction" => [$this, "getAFSetting"],
                "SetFunction" => [$this, "setAFSetting"],
                "Help" => "Pages will be cached for anonymous users only.",
            ],
            "PageCacheExpirationPeriod" => [
                "Type" => FormUI::FTYPE_NUMBER,
                "Label" => "Page Cache Expiration Period",
                "Units" => "minutes",
                "MinVal" => 1,
                "GetFunction" => [$this, "getAFSetting"],
                "SetFunction" => [$this, "setAFSetting"],
            ],
            "TemplateLocationCacheExpirationInterval" => [
                "Type" => FormUI::FTYPE_NUMBER,
                "Label" => "Template Location Cache Expiration",
                "Units" => "minutes",
                "MinVal" => 0,
                "GetFunction" => [$this, "getAFSetting"],
                "SetFunction" => [$this, "setAFSetting"],
                "Help" => "Set to <i>0</i> to disable caching.",
            ],
            "ObjectLocationCacheExpirationInterval" => [
                "Type" => FormUI::FTYPE_NUMBER,
                "Label" => "Object Location Cache Expiration",
                "Units" => "minutes",
                "MinVal" => 0,
                "GetFunction" => [$this, "getAFSetting"],
                "SetFunction" => [$this, "setAFSetting"],
                "Help" => "Set to <i>0</i> to disable caching.",
            ],
            # -------------------------------------------------
            "HEADING-Logging" => [
                "Type" => FormUI::FTYPE_HEADING,
                "Label" => "Logging",
            ],
            "LoggingLevel" => [
                "Type" => FormUI::FTYPE_OPTION,
                "StorageType" => self::TYPE_INT,
                "Label" => "Logging Level",
                "Options" => [
                    ApplicationFramework::LOGLVL_FATAL => "1 - Fatal",
                    ApplicationFramework::LOGLVL_ERROR => "2 - Error",
                    ApplicationFramework::LOGLVL_WARNING => "3 - Warning",
                    ApplicationFramework::LOGLVL_INFO => "4 - Info",
                    ApplicationFramework::LOGLVL_DEBUG => "5 - Debug",
                    ApplicationFramework::LOGLVL_TRACE => "6 - Trace"
                ],
                "OptionThreshold" => 1,
                "GetFunction" => [$this, "getAFSetting"],
                "SetFunction" => [$this, "setAFSetting"],
                "Help" => "Maximum level of messages recorded to "
                        .(ApplicationFramework::getInstance())->logFile(),
            ],
            "LogSlowPageLoads" => [
                "Type" => FormUI::FTYPE_FLAG,
                "Label" => "Log Slow Page Loads",
                "GetFunction" => [$this, "getAFSetting"],
                "SetFunction" => [$this, "setAFSetting"],
            ],
            "SlowPageLoadThreshold" => [
                "Type" => FormUI::FTYPE_NUMBER,
                "Label" => "Slow Page Threshold",
                "Units" => "seconds",
                "GetFunction" => [$this, "getAFSetting"],
                "SetFunction" => [$this, "setAFSetting"],
                "Help" => "Pages that take longer than this will be logged"
                                ." if <i>Log Slow Page Loads</i> is enabled.",
                "MinVal" => 2,
            ],
            "LogHighMemoryUsage" => [
                "Type" => FormUI::FTYPE_FLAG,
                "Label" => "Log High Memory Usage",
                "GetFunction" => [$this, "getAFSetting"],
                "SetFunction" => [$this, "setAFSetting"],
            ],
            "HighMemoryUsageThreshold" => [
                "Type" => FormUI::FTYPE_NUMBER,
                "Label" => "High Memory Usage Threshold",
                "Units" => "%",
                "MinVal" => 10,
                "MaxVal" => 99,
                "RecVal" => 90,
                "GetFunction" => [$this, "getAFSetting"],
                "SetFunction" => [$this, "setAFSetting"],
                "Help" => "Pages that use more than this percentage of"
                                ." the PHP memory limit will be logged, if"
                                ." <i>Log High Memory Usage</i> is enabled.",
            ],
            "LogPhpNotices" => [
                "Type" => FormUI::FTYPE_FLAG,
                "Label" => "Log PHP Notices",
                "GetFunction" => [$this, "getAFSetting"],
                "SetFunction" => [$this, "setAFSetting"],
                "Help" => "When enabled and the current logging level is"
                                ." set to <i>Warning</i> or above, PHP \"Notice\""
                                ." messages will be logged.",
            ],
            "LogDBCachePruning" => [
                "Type" => FormUI::FTYPE_FLAG,
                "Label" => "Log Database Cache Pruning",
                "GetFunction" => [$this, "getAFSetting"],
                "SetFunction" => [$this, "setAFSetting"],
                "Help" => "When enabled and the current logging level is"
                                ." set to <i>Info</i> or above, details about"
                                ." database cache pruning activity will"
                                ." be logged.",
            ],
            "DatabaseSlowQueryThresholdForForeground" => [
                "Type" => FormUI::FTYPE_NUMBER,
                "Label" => "Database Slow Query Threshold (Foreground)",
                "Units" => "seconds",
                "MinVal" => ApplicationFramework::MIN_DB_SLOW_QUERY_THRESHOLD,
                "GetFunction" => [$this, "getAFSetting"],
                "SetFunction" => [$this, "setAFSetting"],
                "Help" => "Database queries that take longer than this will"
                        ." be considered 'slow' by the database server, when"
                        ." running in the foreground.  (Minimum: <i>"
                        .ApplicationFramework::MIN_DB_SLOW_QUERY_THRESHOLD."</i>)",
            ],
            "DatabaseSlowQueryThresholdForBackground" => [
                "Type" => FormUI::FTYPE_NUMBER,
                "Label" => "Database Slow Query Threshold (Background)",
                "Units" => "seconds",
                "MinVal" => ApplicationFramework::MIN_DB_SLOW_QUERY_THRESHOLD,
                "GetFunction" => [$this, "getAFSetting"],
                "SetFunction" => [$this, "setAFSetting"],
                "Help" => "Database queries that take longer than this will"
                        ." be considered 'slow' by the database server, when"
                        ." running in the background (i.e. in a queued task)."
                        ." (Minimum: <i>"
                        .ApplicationFramework::MIN_DB_SLOW_QUERY_THRESHOLD."</i>)",
            ],
            # -------------------------------------------------
            "HEADING-System" => [
                "Type" => FormUI::FTYPE_HEADING,
                "Label" => "System",
            ],
            "SessionLifetime" => [
                "Type" => FormUI::FTYPE_NUMBER,
                "Label" => "Session Lifetime",
                "Units" => "minutes",
                "MinVal" => 5,
                "RecVal" => 30,
                "GetFunction" => function (string $SettingName) {
                    return (ApplicationFramework::getInstance())->sessionLifetime() / 60;
                },
                "SetFunction" => function (string $SettingName, $Value) {
                    (ApplicationFramework::getInstance())->sessionLifetime($Value * 60);
                },
                "Help" => "Length of time the site will remember a given user's "
                              ."login session. Users that are inactive longer than "
                              ."this will be logged out.",
            ],
            "MaxSimultaneousTasks" => [
                "Type" => FormUI::FTYPE_NUMBER,
                "Label" => "Maximum Simultaneous Background Tasks",
                "MinVal" => 1,
                "MaxVal" => 32,
                "GetFunction" => function (string $SettingName) {
                    return (ApplicationFramework::getInstance())->maxTasks();
                },
                "SetFunction" => function (string $SettingName, $Value) {
                    (ApplicationFramework::getInstance())->maxTasks($Value);
                },
                "Help" => "The maximum number of tasks to run in "
                        ."the background per execution cycle."
            ],
            "MaxExecutionTime" => [
                "Type" => FormUI::FTYPE_NUMBER,
                "Label" => "Maximum Execution Time",
                "Units" => "minutes",
                "MinVal" => 1,
                "RecVal" => 5,
                "GetFunction" => function (string $SettingName) {
                    return (ApplicationFramework::getInstance())->maxExecutionTime() / 60;
                },
                "SetFunction" => function (string $SettingName, $Value) {
                    (ApplicationFramework::getInstance())->maxExecutionTime($Value * 60);
                },
            ],
            "UseFilepond" => [
                "Type" => FormUI::FTYPE_FLAG,
                "Label" => "Use Filepond for Uploads",
                "Help" => "When enabled, uploads on most forms are handled with the"
                              ." FilePond library, which uses front-end Javascript"
                              ." and AJAX to provide progress meters and chunked"
                              ." uploads of large files.",
                "Default" => true
            ],
            "UploadChunkSize" => [
                "Type" => FormUI::FTYPE_NUMBER,
                "Label" => "Upload Chunk Size",
                "Units" => "megabytes",
                "MinVal" => 1,
                "Default" => 2,
                "Help" => "Size of chunks used when uploading files via FilePond. To avoid errors,"
                                ." it must not exceed the PHP upload_max_filesize setting or the"
                                ." limits of any HTTP proxies that sit between users and the server"
                                ." (ex: 100MB for Cloudflare).",
                "DisplayIf" => ["UseFilepond" => true],
            ],
            "UseMinimizedJavascript" => [
                "Type" => FormUI::FTYPE_FLAG,
                "Label" => "Use Minimized JavaScript",
                "GetFunction" => [$this, "getAFSetting"],
                "SetFunction" => [$this, "setAFSetting"],
                "Help" => "When enabled, minimized JavaScript files (file"
                                ." name ending in <i>.min.js</i>) will be searched"
                                ." for and used if found.",
            ],
            "JavascriptMinimizationEnabled" => [
                "Type" => FormUI::FTYPE_FLAG,
                "Label" => "Generate Minimized JavaScript",
                "GetFunction" => [$this, "getAFSetting"],
                "SetFunction" => [$this, "setAFSetting"],
                "Help" => "When enabled, the application framework will"
                                ." attempt to generate minimized JavaScript files.",
            ],
            "UrlFingerprintingEnabled" => [
                "Type" => FormUI::FTYPE_FLAG,
                "Label" => "Add URL Fingerprints",
                "GetFunction" => [$this, "getAFSetting"],
                "SetFunction" => [$this, "setAFSetting"],
                "Help" => "When enabled, the appplication framework will"
                                ." attempt to insert timestamp-based fingerprints"
                                ." into the names of CSS, JavaScript, and image"
                                ." files, to help with browser caching.",
            ],
            "ScssSupportEnabled" => [
                "Type" => FormUI::FTYPE_FLAG,
                "Label" => "SCSS Support Enabled",
                "GetFunction" => [$this, "getAFSetting"],
                "SetFunction" => [$this, "setAFSetting"],
                "Help" => "When enabled, the application framework will"
                                ." look for SCSS versions of included CSS files,"
                                ." compile them into CSS, and use the resulting"
                                ." CSS files.",
            ],
            "GenerateCompactCss" => [
                "Type" => FormUI::FTYPE_FLAG,
                "Label" => "Generate Compact CSS",
                "GetFunction" => [$this, "getAFSetting"],
                "SetFunction" => [$this, "setAFSetting"],
                "Help" => "When enabled, any CSS generated from SCSS files"
                                ." will be compacted, to improve page access speeds.",
            ],
            "PreferHttpHost" => [
                "Type" => FormUI::FTYPE_FLAG,
                "Label" => "Prefer HTTP_HOST",
                "Default" => false,
                "Help" => "If available, prefer <i>\$_SERVER[\"HTTP_HOST\"]</i>"
                                ." over <i>\$_SERVER[\"SERVER_NAME\"]</i> when"
                                ." determining the current URL.",
            ],
            "RootUrlOverride" => [
                "Type" => FormUI::FTYPE_TEXT,
                "Label" => "Root URL Override",
                "ValidateFunction" => ["Metavus\\FormUI", "validateUrl"],
                "Default" => "",
                "Help" => "The \"root URL\" is the portion of the URL"
                                ." through the host name.  This setting primarily"
                                ." affects the values returned by the URL"
                                ." retrieval methods and the attempted insertion"
                                ." of clean URLs in outgoing HTML.",
            ],
        ];
        parent::__construct();
    }

    # mapping of email-related settings to Email class method used to get/set them
    protected static $EmailSettingToMethodMap = [
        "MailingMethod" => "defaultDeliveryMethod",
        "SmtpPassword" => "defaultPassword",
        "SmtpPort" => "defaultPort",
        "SmtpServer" => "defaultServer",
        "SmtpUserName" => "defaultUserName",
        "UseAuthenticationForSmtp" => "defaultUseAuthentication",
    ];

    /**
     * Set the interface for each user to default interface
     * @param bool $Value Whether to set the interface
     */
    protected function setAllUserInterfacesToDefault(string $SettingName, $Value): void
    {
        if ($Value == false) {
            return;
        }
        $DefaultInterface = $this->getString("DefaultActiveUI");
        $UFactory = new \Metavus\UserFactory();
        $UserIds = $UFactory->getUserIds();
        foreach ($UserIds as $UserId) {
            $User = new User($UserId);
            $User->set("ActiveUI", $DefaultInterface);
        }
    }

    /**
     * Get specific delivery setting value from Email class.
     * @param string $SettingName Name of setting.
     * @return mixed Value to set.
     */
    protected function getEmailDeliverySetting(string $SettingName)
    {
        $Method = ["ScoutLib\\Email", static::$EmailSettingToMethodMap[$SettingName]];
        if (!is_callable($Method)) {
            throw new Exception("Uncallable Email method for setting \""
                    .$SettingName."\".");
        }
        return ($Method)();
    }

    /**
     * Set specific delivery setting value in Email class.
     * @param string $SettingName Name of setting.
     * @param mixed $Value Value to set.
     * @return void
     */
    protected function setEmailDeliverySetting(string $SettingName, $Value): void
    {
        $Method = ["ScoutLib\\Email", static::$EmailSettingToMethodMap[$SettingName]];
        if (!is_callable($Method)) {
            throw new Exception("Uncallable Email method for setting \""
                    .$SettingName."\".");
        }
        ($Method)($Value);
        $this->setString("EmailDeliverySettings", Email::defaultDeliverySettings());
    }

    /**
     * Get specific delivery setting value from ApplicationFramework class.
     * @param string $SettingName Name of setting.
     * @return mixed Value to set.
     */
    protected function getAFSetting(string $SettingName)
    {
        $AF = ApplicationFramework::getInstance();
        $Method = [ $AF, "get".$SettingName ];
        if (!is_callable($Method)) {
            $Method = [ $AF, lcfirst($SettingName) ];
        }
        if (!is_callable($Method)) {
            throw new Exception("No callable AF method available for getting \""
                    .$SettingName."\".");
        }
        return ($Method)();
    }

    /**
     * Set specific delivery setting value in ApplicationFramework class.
     * @param string $SettingName Name of setting.
     * @param mixed $Value Value to set.
     * @return void
     */
    protected function setAFSetting(string $SettingName, $Value): void
    {
        $AF = ApplicationFramework::getInstance();
        $Method = [ $AF, "set".$SettingName ];
        if (!is_callable($Method)) {
            $Method = [ $AF, lcfirst($SettingName) ];
        }
        if (!is_callable($Method)) {
            throw new Exception("No callable AF method available for setting \""
                    .$SettingName."\".");
        }
        ($Method)($Value, true);
    }

    /**
     * Validate email delivery settings from form.  (Intended to be used as a
     * callback for "ValidateFunction" parameters, and should not be called otherwise.
     * Method is only "public" because that is required for the callback use.)
     * @param string $SettingName Name of setting to validate.
     * @param string|array $SettingValue Value for specified setting.
     * @param array $AllValues All values submitted for form.
     * @return string|null Error message or NULL if no problems found.
     */
    public function validateEmailDeliverySettings(
        string $SettingName,
        $SettingValue,
        array $AllValues
    ): ?string {
        # return previously-determined result if available
        static $ErrMsgs;
        if (isset($ErrMsgs)) {
            return $ErrMsgs[$SettingName] ?? null;
        }

        $ErrMsgs = [];

        # save current settings
        $SavedSettings = Email::defaultDeliverySettings();

        # set settings to values passed in
        Email::defaultDeliveryMethod($AllValues["MailingMethod"]);
        Email::defaultPassword($AllValues["SmtpPassword"] ?? null);
        Email::defaultPort($AllValues["SmtpPort"] ?? null);
        Email::defaultServer($AllValues["SmtpServer"] ?? null);
        Email::defaultUserName($AllValues["SmtpUserName"] ?? null);
        Email::defaultUseAuthentication($AllValues["UseAuthenticationForSmtp"] ?? null);

        # test new settings
        $TestEmail = new Email();
        $TestResult = $TestEmail->deliverySettingsOkay();

        # if problems were found with new settings
        if ($TestResult == false) {
            # translate problems to error messages
            $TestResultMsgs = Email::deliverySettingErrors();
            if (in_array("UseAuthentication", $TestResultMsgs)
                    || in_array("UserName", $TestResultMsgs)
                    || in_array("Password", $TestResultMsgs)) {
                $Msg = "Unable to connect with the specified <b>SMTP User Name</b>"
                        . " and <b>SMTP Password</b>. Please check that these"
                        . " values are correct to connect to <i>"
                        . $AllValues["SmtpServer"] . "</i>.";
                $ErrMsgs["SmtpUserName"] = $Msg;
                $ErrMsgs["SmtpPassword"] = $Msg;
            } elseif (in_array("Server", $TestResultMsgs)
                    || in_array("Port", $TestResultMsgs)) {
                $Msg = "An error was found with the <b>SMTP Server</b> or"
                        ." <b>SMTP Port</b> number.  Please check that these values"
                        ." are correct.";
                $ErrMsgs["SmtpServer"] = $Msg;
                $ErrMsgs["SmtpPort"] = $Msg;
            } elseif (in_array("TLS", $TestResultMsgs)) {
                $Msg = "An error was encountered trying to make a TLS connection to"
                        ." the specified server for SMTP.  Please check the server"
                        ." and port values to make sure they are correct.";
                $ErrMsgs["SmtpServer"] = $Msg;
            } else {
                $Msg = "An unknown error was encountered while trying to verify the"
                        ." <b>Mailing</b> settings.";
                FormUI::logError($Msg);
            }
        }

        # restore saved settings
        Email::defaultDeliverySettings($SavedSettings);

        # return error message (if any) for current setting to caller
        return $ErrMsgs[$SettingName] ?? null;
    }
}
