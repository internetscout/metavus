<?PHP
#
#   FILE:  installmv.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2009-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus;
use Exception;
use ScoutLib\ApplicationFramework;
use ScoutLib\Database;
use ScoutLib\PluginManager;
use ScoutLib\StdLib;
use ZipArchive;

$Inst = new Installer();
$Inst->install();

class Installer
{
    # ----- CONFIGURATION --------------------------------------------------------

    const MINIMUM_MYSQL_VERSION = "5.0";
    const MINIMUM_PHP_VERSION = "7.2.0";
    const OLDEST_UPGRADABLE_VERSION = "3.2.0";

    const MINIMUM_PASSWORD_LENGTH = 6;

    const SAMPLE_RECORD_FILE = "install/SampleRecords-220620a.zip";

    # PHP extensions required by the software
    public $RequiredExtensions = [
        "curl",
        "gd",
        "json",
        "mysqli",
        "openssl",
        "session",
        "simplexml",
        "xml",
        "xmlreader",
        "zip",
    ];

    # directories that must be writable before install can begin
    # (an attempt will be made to create these if they don't exist)
    public $DirsThatMustBeWritable = [
        "/",
        "/include",
        "/tmp",
        "/local",
        "/local/logs",
        "/local/data",
        "/local/data/caches",
        "/local/data/files",
        "/local/data/images",
    ];

    # files containing SQL commands to set up initial database
    # (index = file name, value = progress message)
    public $DatabaseSetupFiles = [
        "install/TestDBPerms.sql" => "Testing database permissions...",
        "lib/ScoutLib/User--CreateTables.sql" => "Setting up user tables...",
        "lib/ScoutLib/ApplicationFramework--CreateTables.sql"
                    => "Setting up application framework tables...",
        "lib/ScoutLib/SearchEngine--CreateTables.sql"
                    => "Setting up search engine tables...",
        "lib/ScoutLib/PluginManager--CreateTables.sql"
                    => "Setting up plugin manager tables...",
        "lib/ScoutLib/RSSClient--CreateTables.sql"
                    => "Setting up RSS client tables...",
        "install/CreateTables.sql" => "Setting up Metavus tables...",
    ];

    # obsolete files that must be moved aside (renamed with ".OLD" at the end)
    public $ObsoleteFiles = [
        "objects/ItemFactory.php",
        "plugins/MyForumPosts.php",
        "plugins/phpBBSync/phpBBSync.php",
        "interface/default/include/AjaxDropdown.css",
        "interface/default/include/CW-Generic.css",
        "interface/default/include/CWIS.css",
        "interface/default/include/CW-Legacy.css",
        "interface/default/include/CW-Theme-CKEditor.css",
        "interface/default/include/CW-Theme.css",
        "interface/default/include/jquery-ui.css",
        "interface/default/include/SPT--AJAXDropdown.css",
        "interface/default/include/SPT--DragAndDrop.css",
        "interface/default/include/SPT--EditInPlace.css",
        "interface/default/include/SPT--MDTAndAdmin.css",
        "interface/default/include/SPT--Stylesheet.css",
    ];

    # SQL errors we can ignore (index = SQL command, value = error message)
    # (IMPORTANT:  this list MUST match the list in the Developer plugin)
    public $SqlErrorsWeCanIgnore = [
        "/ALTER TABLE /i" => "/Table '[a-z0-9_.]+' already exists/i",
        "/ALTER TABLE [a-z0-9_]+ (CHANGE|MODIFY) COLUMN/i" => "/Unknown column/i",
        "/ALTER TABLE [a-z0-9_]+ ADD /i" => "/Duplicate column name/i",
        "/ALTER TABLE [a-z0-9_]+ ADD INDEX/i" => "/Duplicate key name/i",
        "/ALTER TABLE [a-z0-9_]+ ADD PRIMARY KEY/i" => "/Multiple primary key/i",
        "/ALTER TABLE [a-z0-9_]+ DROP COLUMN/i" => "/Check that column/i",
        "/ALTER TABLE [a-z0-9_]+ RENAME/i" => "/Table '[a-z0-9_.]+' doesn't exist/i",
        "/CREATE (UNIQUE )?INDEX [a-z0-9_]+ ON [a-z0-9_]+/i" => "/Duplicate key name/i",
        "/CREATE TABLE /i" => "/Table '[a-z0-9_.]+' already exists/i",
        "/DROP INDEX /i" => "/check that column\/key exists/i",
        "/DROP TABLE /i" => "/Unknown table '[a-z0-9_.]+'/i",
        # (situation-specific patterns, that should eventually be removed)
        "/ALTER TABLE RecordImageInts/i" => "/Table '[a-z0-9_.]+' doesn't exist/i",
        "/CREATE TABLE [a-z0-9_]+_old AS SELECT/i"
                    => "/Table '[a-z0-9_.]+' doesn't exist/i",
        "/INSERT INTO [a-z]+ SELECT \* FROM [a-z0-9_]+_old/i"
                    => "/Table '[a-z0-9_.]+' doesn't exist/i",
    ];

    # ----- PUBLIC INTERFACE -----------------------------------------------------

    /**
     * Class constructor, that sets up class for use before the installation begins.
     */
    public function __construct()
    {
        # have all output write out immediately
        ob_implicit_flush(true);

        # initialize starting values
        $this->FVars = $_POST;

        # set debug output level
        if (isset($_POST["F_Debug"])) {
            $this->VerbosityLevel = 2;
        }
        if (isset($_POST["F_MoreDebug"])) {
            $this->VerbosityLevel = 3;
        }
        if (isset($_POST["F_EvenMoreDebug"])) {
            $this->VerbosityLevel = 4;
        }
        if (isset($_GET["VB"])) {
            $this->VerbosityLevel = $_GET["VB"];
        }

        # grab our version number
        if (file_exists("NEWVERSION")) {
            $NewVersion = file_get_contents("NEWVERSION");
            if ($NewVersion !== false) {
                $this->NewVersion = trim($NewVersion);
            }
        }
    }

    /**
     * Install or upgrade software.
     */
    public function install(): void
    {
        $this->beginHtmlPage();

        # if NEWVERSION exists, it indicates that an install/upgrade hasn't yet
        # been run and that we can proceed
        if (file_exists("NEWVERSION")) {
            # check environment to make sure we can run
            $this->checkEnvironment();

            # check distribution files
            if (!array_key_exists("NOCHKSUMS", $_GET)
                    && !array_key_exists("NOCHKSUMS", $_POST)) {
                $this->checkDistributionFiles($this->NewVersion);
            }
        } else {
            $this->ErrMsgs[] = "It appears that the installation or upgrade "
                ."has already been completed.";
        }

        # if problems were found with environment or the install/upgrade was
        # already complete
        if (count($this->ErrMsgs)) {
            # display error messages
            $this->printErrorMessages($this->ErrMsgs);
        } else {
            # if we are upgrading
            $OldVersion = $this->checkForUpgrade();
            $IsUpgrade = $OldVersion ? true : false;
            if ($IsUpgrade) {
                # if existing version is too old to upgrade
                $VersionIsTooOld = self::legacyVersionCompare(
                    $OldVersion,
                    self::OLDEST_UPGRADABLE_VERSION,
                    "<"
                );
                if ($VersionIsTooOld) {
                    $this->ErrMsgs[] = "Your currently installed version (<i>".$OldVersion
                            ."</i>) is too old to be upgraded with this package. "
                            ."You must first upgrade to version "
                            .self::OLDEST_UPGRADABLE_VERSION
                            ." and then use this package.";
                } else {
                    # load install information from existing files
                    $this->loadOldInstallInfo();
                }
            }

            # if we have install information
            if (isset($this->FVars["F_Submit"]) && !count($this->ErrMsgs)) {
                # check installation information
                $this->checkInstallInfo();
            }

            # if install information has not been collected or we encountered errors
            if (!isset($this->FVars["F_Submit"]) || count($this->ErrMsgs)) {
                # display any error messages
                $this->printErrorMessages($this->ErrMsgs);

                # if this is an upgrade
                if ($IsUpgrade) {
                    # display pointer to helpful info
                    $this->printHelpPointers();
                } else {
                    # display install information form
                    $this->printInstallInfoForm();
                }
            } else {
                # print install parameter summary
                $this->printInstallInfoSummary($IsUpgrade);

                # set up files
                $this->msg(1, "<b>Beginning "
                        .($IsUpgrade ? "Upgrade" : "Installation")." Process...</b>");
                $ErrMsgs = $this->installFiles($IsUpgrade);

                # tell Bootloader to use ScoutLib\User instead of Metavus\User
                #       (because Metavus\User relies on having the User metadata
                #       schema already set up)
                $GLOBALS["StartUpOpt_USE_AXIS_USER"] = true;

                # if we are upgrading
                if ($IsUpgrade) {
                    # upgrade existing database
                    if (!count($this->ErrMsgs)) {
                        $this->ErrMsgs = $this->upgradeExistingDatabase(
                            $this->ErrMsgs,
                            $OldVersion
                        );
                    }

                    # initialize application environment
                    if (!count($this->ErrMsgs)) {
                        $this->initializeAF($this->NewVersion);
                    }

                    # upgrade site
                    if (!count($this->ErrMsgs)) {
                        $this->ErrMsgs = $this->upgradeSite($OldVersion);
                    }

                    # clear any existing compiled CSS files and minimized
                    #       JavaScript files so that any updated files will
                    #       instead be used
                    if (!count($this->ErrMsgs)) {
                        $AF = ApplicationFramework::getInstance();
                        if ($AF->clearCompiledCssFiles() === false) {
                            $this->ErrMsgs[] = "Clearing compiled CSS files failed.";
                        }
                        if ($AF->clearMinimizedJavascriptFiles() === false) {
                            $this->ErrMsgs[] = "Clearing minimized JS files failed.";
                        }
                    }
                } else {
                    # set up database
                    if (!count($this->ErrMsgs)) {
                        $this->ErrMsgs = $this->setUpNewDatabase($this->ErrMsgs);
                    }

                    # initialize application environment (without plugins)
                    $GLOBALS["StartUpOpt_DO_NOT_LOAD_PLUGINS"] = true;
                    if (!count($this->ErrMsgs)) {
                        $this->initializeAF($this->NewVersion);
                    }

                    # load default system configuration
                    if (!count($this->ErrMsgs)) {
                        $this->ErrMsgs = $this->loadDefaultConfiguration($this->ErrMsgs);
                    }

                    # set up site
                    if (!count($this->ErrMsgs)) {
                        $this->ErrMsgs = $this->setUpNewSite($this->ErrMsgs);
                    }

                    # load plugins
                    if (!count($this->ErrMsgs)) {
                        $this->msg(1, "Loading plugins...");
                        $PluginMgr = PluginManager::getInstance();
                        $PluginMgr->loadPlugins();
                    }
                }

                # if errors encountered
                if (count($this->ErrMsgs)) {
                    # display any error messages
                    $this->printErrorMessages($this->ErrMsgs);

                    # display pointer to helpful info
                    $this->printHelpPointers();
                } else {
                    # queue new install or upgrade follow-up work as appropriate
                    $this->msg(1, "Queuing follow-up tasks...");
                    $this->queueFollowUpWork($IsUpgrade, $OldVersion);

                    # declare victory
                    $this->msg(1, "<b>".($IsUpgrade ? "Upgrade" : "Installation")
                    ." Process Completed</b>");

                    # print install complete message
                    $this->printInstallCompleteInfo($IsUpgrade);

                    # set version
                    if ($IsUpgrade) {
                        if (file_exists("OLDVERSION")) {
                            unlink("OLDVERSION");
                        }
                        rename("VERSION", "OLDVERSION");
                    }
                    rename("NEWVERSION", "VERSION");
                }
            }
        }

        $this->endHtmlPage();
    }

    /**
     * Message display and logging function for use during site upgrades.
     * @param int $VerbLvl Minimum verbosity level required to display message.
     * @param string $Message Message string.
     */
    public function msg(int $VerbLvl, string $Message): void
    {
        if ($VerbLvl <= $this->VerbosityLevel) {
            for ($Index = $VerbLvl; $Index > 1; $Index--) {
                print("&nbsp;&nbsp;");
            }
            print($Message."<br />\n");
            $this->logMsg($Message);
        }
    }

    # ----- PRIVATE INTERFACE ----------------------------------------------------

    private $ErrMsg;
    private $ErrMsgs = [];
    private $FVars;
    private $NewVersion = "";
    private $VerbosityLevel = 1;

    /**
     * Output the beginning HTML for all of our pages.
     */
    private function beginHtmlPage(): void
    {
        ?>
        <!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN"
                "http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd">
        <html xmlns="http://www.w3.org/1999/xhtml" xml:lang="en" lang="en">
        <head>
            <title>Metavus <?=  $this->NewVersion  ?> Installation</title>
            <style type="text/css">
                body {
                    background-color: #B3B3B3;
                }
                .MainTable {
                    margin: 5% 10% 10% 10%;
                    padding: 2em;
                    background-color: #FFFFFF;
                    border: 2px solid #999999;
                }
                .InfoTable {
                    width: 100%;
                }
                .InfoTable tr {
                    vertical-align: top;
                }
                .InfoTable th {
                    text-align: right;
                    white-space: nowrap;
                }
                .InstallInfoSummaryTable {
                    border: 1px solid #DDDDDD;
                    background-color: #EEEEEE;
                    padding: 15px;
                }
                .InstallInfoSummaryTable th {
                    text-align: right;
                    white-space: nowrap;
                    padding-right: 10px;
                }
                .TitleLine {
                    font-size: 1.5em;
                    font-weight: bold;
                    font-family: sans-serif;
                }
                .LogoMeta
                {
                    color: #111111;
                    font-weight: bold;
                    font-family: verdana, arial, helvetica, sans-serif;
                }
                .LogoVus
                {
                    color: #555555;
                    font-weight: bold;
                    font-family: verdana, arial, helvetica, sans-serif;
                }
                .ErrorList
                {
                    color: red;
                }
            </style>
        </head>
        <body>
        <table class="MainTable">
            <tr><td colspan="2"><span class="TitleLine">Metavus <?=
                    $this->NewVersion  ?> Installation</span></td><tr>
            <tr><td colspan="2">
            </td></tr>
            <tr><td>
        <?PHP
    }

    /**
     * Output the ending HTML for all pages.
     */
    private function endHtmlPage(): void
    {
        ?>
            </td></tr>
        </table>
        </body>
        </html>
        <?PHP
    }

    # ----- FORMS AND MESSAGES ---------------------------------------------------

    /**
     * Print form to gather installation info.
     */
    private function printInstallInfoForm(): void
    {
        # set up default values
        $Protocol = isset($_SERVER["HTTPS"]) ? "https://" : "http://";
        $SiteUrl = $this->FVars["F_SiteUrl"]
                ?? $Protocol.$_SERVER["HTTP_HOST"].dirname($_SERVER["SCRIPT_NAME"])."/";
        $DBHost = $this->FVars["F_DBHost"] ?? "localhost";
        $DBLogin = $this->FVars["F_DBLogin"] ?? "";
        $DBPassword = $this->FVars["F_DBPassword"] ?? "";
        $DBName = $this->FVars["F_DBName"] ?? "";
        $AdminLogin = $this->FVars["F_AdminLogin"] ?? "";
        $AdminPassword = $this->FVars["F_AdminPassword"] ?? "";
        $AdminEmail = $this->FVars["F_AdminEmail"] ?? "";
        $AdminEmailAgain = $this->FVars["F_AdminEmailAgain"] ?? "";

        # display form
        ?>
        <form method="POST" action="installmv.php">
        <?PHP
        if (array_key_exists("NOCHKSUMS", $_GET)
                || array_key_exists("NOCHKSUMS", $_POST)) {
            ?><input type="hidden" name="NOCHKSUMS" value="1"><?PHP
        }
        ?>
        <table class="InfoTable">

            <tr><td colspan="2">
            Before beginning installation, we need to gather a few bits of
            information to set up the software and allow it to access the SQL
            database where the resource data will be stored.  It is important
            that this information be entered correctly, so please take the time
            to read the notes accompanying each field to make sure you enter the
            right values, and consult your system administrator if you're not sure.
            </td></tr>

            <tr><td>&nbsp;</td></tr>
            <tr>
                <th>Site URL:</th>
                <td><input type="text" size="40" maxlength="120"
                        name="F_SiteUrl" value="<?PHP  print($SiteUrl);  ?>" /></td>
            </tr>
            <tr><td></td><td>
                This is the URL (web address) where the software will be accessed.
            </td></tr>
            <tr><td>&nbsp;</td></tr>

            <tr>
                <th>Database Host:</th>
                <td><input type="text" size="30" maxlength="60"
                        name="F_DBHost" value="<?PHP  print($DBHost);  ?>" /></td>
            </tr>
            <tr><td></td><td>
                This is the name of the computer on which your SQL database server
                is running.  If your web server and your database server are running
                on the same computer, then you should enter <i>localhost</i> here.
            </td></tr>
            <tr><td>&nbsp;</td></tr>

            <tr>
                <th>Database Login:</th>
                <td><input type="text" size="15" maxlength="40"
                        name="F_DBLogin" value="<?PHP  print($DBLogin);  ?>" /></td>
            </tr>
            <tr>
                <th>Database Password:</th>
                <td><input type="text" size="15" maxlength="40"
                        name="F_DBPassword" value="<?PHP  print($DBPassword);  ?>" /></td>
            </tr>
            <tr><td></td><td>
                This is the login name and password that you need to
                connect to your database server.  Please note that this is a
                <b>database server</b> user name and password, which must have
                already been set up by your database administrator, <b>not</b>
                your Linux or OS X login name and password.<br />
                <br />
                Please Note:  If the database named below does not already exist,
                this database user account must have <i>CREATE</i> privileges.
                If the database <b>does</b> exist, it must not already
                contain tables or data.
            </td></tr>
            <tr><td>&nbsp;</td></tr>

            <tr>
                <th>Database Name:</th>
                <td><input type="text" size="30" maxlength="60"
                        name="F_DBName" value="<?PHP  print($DBName);  ?>" /></td>
            </tr>
            <tr><td></td><td>
                This is the name of the SQL database (the internal database name
                that you yourself choose, like <i>PortalDB</i> or <i>OurDB</i>, not
                the name of the database software package) that we will use to
                store portal information.<br />
                <br />
                Please Note: If this database already exists, it must <b>not</b>
                already contain tables or data.
            </td></tr>
            <tr><td>&nbsp;</td></tr>

            <tr>
                <th>Admin Login:</th>
                <td><input type="text" size="15" maxlength="30"
                        name="F_AdminLogin" value="<?PHP  print($AdminLogin);  ?>" /></td>
            </tr>
            <tr>
                <th>Admin Password:</th>
                <td><input type="text" size="15" maxlength="30"
                        name="F_AdminPassword" value="<?PHP  print($AdminPassword);  ?>" /></td>
            </tr>
            <tr>
                <th>Admin E-Mail:</th>
                <td><input type="text" size="40" maxlength="120"
                        name="F_AdminEmail" value="<?PHP  print($AdminEmail);  ?>" /></td>
            </tr>
            <tr>
                <th>Admin E-Mail:</th>
                <td><input type="text" size="40" maxlength="120"
                        name="F_AdminEmailAgain" value="<?PHP  print($AdminEmailAgain);
                        ?>" /> <b>(again to confirm)</b></td>
            </tr>
            <tr><td></td><td>
                This is the user name and password that you will initially use
                to log into and configure your portal, and the e-mail address
                where any administrative e-mail will be sent.  The password must
                be at least six characters long.
            </tr>
            <tr><td>&nbsp;</td></tr>

            <tr><td></td><td>
                <input type="submit" name="F_Submit" value="Begin Installation">
                <span style="float: right; color: grey; font-size: 12px;">
                        Verbosity: <input type="checkbox" name="F_Debug" <?PHP
                        if (isset($this->FVars["F_Debug"])) {
                            print("checked");
                        }
                        ?>><input type="checkbox" name="F_MoreDebug" <?PHP
if (isset($this->FVars["F_MoreDebug"])) {
    print("checked");
}
?>><input type="checkbox" name="F_EvenMoreDebug" <?PHP
if (isset($this->FVars["F_EvenMoreDebug"])) {
    print("checked");
}
?>></span>
            </tr>

        </table>
        </form>
        <?PHP
    }

    /**
     * Print list of error messages.
     * @param array $ErrMsgs Error messages to print.
     */
    private function printErrorMessages(array $ErrMsgs): void
    {
        if (count($ErrMsgs)) {
            ?><b>Errors Encountered:</b>
            <ul class="ErrorList"><?PHP
            foreach ($ErrMsgs as $Msg) {
                ?><li><?= $Msg ?></li><?PHP
                $this->logMsg("ERROR: ".$Msg);
            }
            ?></ul><?PHP
        }
    }

    /**
     * Print summary of installation info.
     * @param bool $IsUpgrade TRUE if upgrade, or FALSE if new install.
     */
    private function printInstallInfoSummary(bool $IsUpgrade): void
    {
        ?>
        <table class="InstallInfoSummaryTable" width="100%">
            <tr><th>Site Base URL:</th>
                    <td><i><?PHP  print($this->FVars["F_SiteUrl"]);  ?></i></td></tr>
            <tr><th>Database Host:</th>
                    <td><i><?PHP  print($this->FVars["F_DBHost"]);  ?></i></td></tr>
            <tr><th>Database Login:</th>
                    <td><i><?PHP  print($this->FVars["F_DBLogin"]);  ?></i></td></tr>
            <tr><th>Database Name:</th>
                    <td><i><?PHP  print($this->FVars["F_DBName"]);  ?></i></td></tr>
            <?PHP
            if (!$IsUpgrade) {
                ?>
            <tr><th>Admin Login:</th>
                    <td><i><?PHP  print($this->FVars["F_AdminLogin"]);  ?></i></td></tr>
            <tr><th>Admin E-Mail:</th>
                    <td><i><?PHP  print($this->FVars["F_AdminEmail"]);  ?></i></td></tr>
                <?PHP
            }
            ?>
        </table>
        <br />
        <?PHP
    }

    /**
     * Print info about installation being completed.
     * @param bool $IsUpgrade TRUE if upgrade, or FALSE if new install.
     */
    private function printInstallCompleteInfo(bool $IsUpgrade): void
    {
        ?>
        <br />
        <?PHP
        if ($IsUpgrade) {
            ?>
            You may now proceed to your <a href="<?PHP  print($this->FVars["F_SiteUrl"]);
            ?>index.php" target="_blank">upgraded Metavus site</a>.<br />
            <?PHP
            if ($this->FVars["F_DefaultUI"] == "SPTUI--Default") {
                ?>
                <br />
                <b>PLEASE NOTE:</b>  It appears that you have upgraded from SPT to Metavus.
                If some pages on your site appear to load incorrectly, you may
                need to edit the file <code>local/config.php</code> and change the
                value of <code>$GLOBALS["G_Config"]["UserInterface"]["DefaultUI"]</code>
                to <code>"default"</code>.<br />
                <?PHP
            }
        } else {
            ?>
            You may now proceed to your <a href="<?PHP  print($this->FVars["F_SiteUrl"]);
            ?>index.php">new Metavus site</a> and log in with the user name <i><?PHP
                print($this->FVars["F_AdminLogin"]);  ?></i> and the password
                you supplied.<br />
            <?PHP
        }
        ?>
        <br />
        Thank you for using <span class="LogoMeta">Meta</span><span class="LogoVus">vus</span>!
        <?PHP
    }

    /**
     * Print helpful info about what to do about problems encountered.
     */
    private function printHelpPointers(): void
    {
        ?>
        <br />
        Please correct these problems and re-run the installation.<br />
        <?PHP
    }


    # ----- VALIDATION CHECKS ----------------------------------------------------

    /**
     * Check to make sure our environment will support the software.  Any issues
     * discovered are recorded via messages added to $this->ErrMsgs.
     */
    private function checkEnvironment(): void
    {
        # check PHP version
        if (version_compare(PHP_VERSION, Installer::MINIMUM_PHP_VERSION) == -1) {
            $this->ErrMsgs[] = "Required PHP version not found."
                    ." Metavus ".$this->NewVersion." requires PHP version "
                            .Installer::MINIMUM_PHP_VERSION." or later."
                    ."<br />PHP version <i>".PHP_VERSION
                            ."</i> was detected.";
        }

        # check for required extensions
        foreach ($this->RequiredExtensions as $Ext) {
            if (!extension_loaded($Ext)) {
                $this->ErrMsgs[] =
                     "The '".$Ext."' extension is not loaded or is not enabled. "
                     ."Some Linux distributions split this extension off into a "
                     ."separate package, often with a name like 'php81-".$Ext."'.";
            }
        }

        # check to make sure directories are writable
        $Cwd = getcwd();
        $DirMode = 0755;
        foreach ($this->DirsThatMustBeWritable as $Dir) {
            # the directory must have a forward slash in the beginning since the
            # directory from getcwd() will not have a trailing slash
            $Dir = ($Dir[0] != "/") ? "/".$Dir : $Dir;

            $Dir = $Cwd.$Dir;
            if (!is_dir($Dir)) {
                @mkdir($Dir, $DirMode);
                if (!is_dir($Dir)) {
                    $this->ErrMsgs[] = "Directory <i>".$Dir."</i> could not be created.";
                }
            }

            if (is_dir($Dir) && !is_writable($Dir)) {
                @chmod($Dir, $DirMode);
                if (is_writable($Dir) !== true) {
                    $this->ErrMsgs[] = "Directory <i>".$Dir."</i> is not writable.";
                }
            }
        }
    }

    /**
     * Check supplied installation info for validity.  Any issues
     * discovered are recorded via messages added to $this->ErrMsgs.
     */
    private function checkInstallInfo(): void
    {
        $this->checkDBInfo();

        if ($this->FVars["F_Submit"] != "Upgrade Installation") {
            $this->checkAdminInfo();
        }
    }

    /**
     * Check supplied database info for validity.  Any issues
     * discovered are recorded via messages added to $this->ErrMsgs.
     */
    private function checkDBInfo(): void
    {
        # check MySQL availability and version and that we can create tables in DB
        if (!strlen(trim($this->FVars["F_DBHost"]))
                || !strlen(trim($this->FVars["F_DBLogin"]))
                || !strlen(trim($this->FVars["F_DBPassword"]))) {
            if (!strlen(trim($this->FVars["F_DBHost"]))) {
                $this->ErrMsgs["F_DBHost"] = "No database host was supplied.";
            }
            if (!strlen(trim($this->FVars["F_DBLogin"]))) {
                $this->ErrMsgs["F_DBLogin"] = "No database login was supplied.";
            }
            if (!strlen(trim($this->FVars["F_DBPassword"]))) {
                $this->ErrMsgs["F_DBPassword"] = "No database password was supplied.";
            }
            return;
        }

        require_once("lib/ScoutLib/StdLib.php");
        require_once("lib/ScoutLib/Database.php");

        $Result = Database::connectionInfoIsValid(
            $this->FVars["F_DBHost"],
            $this->FVars["F_DBLogin"],
            $this->FVars["F_DBPassword"]
        );
        if (!$Result) {
            $this->ErrMsgs[] = "Could not connect to database on "
                .$this->FVars["F_DBHost"].".";
            return;
        }

        Database::setGlobalServerInfo(
            $this->FVars["F_DBLogin"],
            $this->FVars["F_DBPassword"],
            $this->FVars["F_DBHost"]
        );

        $MysqlVersion = Database::getServerVersion();

        if (version_compare($MysqlVersion, Installer::MINIMUM_MYSQL_VERSION) == -1) {
            $this->ErrMsgs[] = "Required MySQL version not found."
                ." Metavus ".$this->NewVersion." requires MySQL version "
                .Installer::MINIMUM_MYSQL_VERSION." or later."
                ."<br />MySQL version <i>".$MysqlVersion."</i> was detected.";
            return;
        }

        if (!strlen(trim($this->FVars["F_DBName"]))) {
            $this->ErrMsgs["F_DBName"] = "No database name was supplied.";
            return;
        }


        if (!Database::databaseExists($this->FVars["F_DBName"])) {
            if (!Database::createDatabase($this->FVars["F_DBName"])) {
                $this->ErrMsgs["F_DBName"] = "Could not create database.";
                return;
            }
            Database::dropDatabase($this->FVars["F_DBName"]);
        }
    }

   /**
    * Check supplied administrativer user info for validity.  Any issues
    * discovered are recorded via messages added to $this->ErrMsgs.
    */
    private function checkAdminInfo(): void
    {
        if (!strlen(trim($this->FVars["F_AdminLogin"]))) {
            $this->ErrMsgs["F_AdminLogin"] = "No administrative account login was supplied.";
        }
        if (!strlen(trim($this->FVars["F_AdminPassword"]))) {
            $this->ErrMsgs["F_AdminPassword"] =
                    "No administrative account password was supplied.";
        }
        if (strlen(trim($this->FVars["F_AdminPassword"]))
                < self::MINIMUM_PASSWORD_LENGTH) {
            $this->ErrMsgs["F_AdminPassword"] =
                    "Administrative password supplied was too short."
                    ." Password must be at least ".self::MINIMUM_PASSWORD_LENGTH
                    ." characters long.";
        }
        $AdminEmail = trim($this->FVars["F_AdminEmail"]);
        if (!strlen($AdminEmail)) {
            $this->ErrMsgs["F_AdminEmail"] =
                    "No administrative account e-mail address was supplied.";
        } elseif (filter_var($AdminEmail, FILTER_VALIDATE_EMAIL) === false) {
            $this->ErrMsgs["F_AdminEmail"] =
                    "An invalid administrative account e-mail address was supplied.";
        } elseif (trim($this->FVars["F_AdminEmailAgain"]) != $AdminEmail) {
            $this->ErrMsgs["F_AdminEmailAgain"] =
                    "The two administrative account e-mail addresses did not match.";
        }
    }

    /**
     * Check integrity of distribution files using checksums.  Any errors
     * encountered are recorded in $this->ErrMsgs.
     * @param string $NewVersion Version we are installing or upgrading to.
     */
    private function checkDistributionFiles(string $NewVersion): void
    {
        # error out if checksum file not found
        if (!file_exists("install/CHECKSUMS")) {
            $this->ErrMsgs[] = "Checksum file <i>install/CHECKSUMS</i> not found.";
            return;
        }

        # load in MD5 checksums
        $ErrorCount = 0;
        $Lines = file("install/CHECKSUMS", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($Lines === false) {
            $this->ErrMsgs[] = "Checksum file <i>install/CHECKSUMS</i> not readable.";
            return;
        }
        $Checksums = array();
        foreach ($Lines as $Line) {
            $Pieces = preg_split("/\s+/", $Line, 2);
            if ($Pieces === false) {
                $this->ErrMsgs[] = "Unable to parse CHECKSUMS file line \"".$Line."\".";
                $ErrorCount++;
                continue;
            }
            $Checksums[$Pieces[1]] = $Pieces[0];
        }

        # for each checksum
        foreach ($Checksums as $FileName => $Chksum) {
            # if file exists
            if (file_exists($FileName)) {
                # generate checksum for file
                $CalcChksum = md5_file($FileName);

                # if distribution file is missing
                if ($CalcChksum === false) {
                    # record error message about checksum error
                    $this->ErrMsgs[] = "Could not read Metavus ".$NewVersion
                            ." distribution file <i>".$FileName."</i>.";
                    $ErrorCount++;
                # else if checksums do not match
                } elseif (strtolower($CalcChksum) != strtolower($Chksum)) {
                    # record error message about checksum error
                    $this->ErrMsgs[] = "Checksum does not match for Metavus ".$NewVersion
                            ." distribution file <i>".$FileName."</i>.";
                    $ErrorCount++;
                }
            } else {
                # record error message about missing file
                $this->ErrMsgs[] = "Distribution file <i>".$FileName."</i> not found.";
                $ErrorCount++;
            }

            # quit if too many errors encountered
            if ($ErrorCount > 20) {
                $this->ErrMsgs[] = "More than 20 errors encountered when checking"
                        ." distribution file integrity.  If files were uploaded"
                        ." to the web server via FTP, please make sure they"
                        ." were transferred using \"Binary\" mode, rather than"
                        ." \"ASCII\" or \"Automatic\" mode.";
                break;
            }
        }
    }


    # ----- INSTALLATION ---------------------------------------------------------

    /**
     * Install config, htaccess, and robots.txt files, and move aside any
     * obsolete files that are found.
     * @param bool $IsUpgrade TRUE if upgrade, or FALSE if new install.
     * @return array Any error messages.
     */
    private function installFiles(bool $IsUpgrade): array
    {
        # set up config file with DB info if not present
        $ErrMsgs = [];
        if (!file_exists("local/config.php") && !file_exists("config.php")) {
            $this->msg(1, "Creating configuration file...");
            $ConfigReplacements = array(
                "X-DBUSER-X" => addslashes($this->FVars["F_DBLogin"]),
                "X-DBPASSWORD-X" => addslashes($this->FVars["F_DBPassword"]),
                "X-DBHOST-X" => addslashes($this->FVars["F_DBHost"]),
                "X-DBNAME-X" => addslashes($this->FVars["F_DBName"]),
            );
            $ErrMsg = $this->copyFile(
                "install/config.php.DIST",
                "local/config.php",
                $ConfigReplacements
            );
            if ($ErrMsg) {
                $ErrMsgs[] = $ErrMsg;
            }
        }

        # if there is no top-level .htaccess file
        if (!file_exists(".htaccess")) {
            # set up .htaccess file
            $this->msg(1, "Creating .htaccess file...");
            $ErrMsg = $this->installHtaccess(".htaccess");
            if ($ErrMsg) {
                $ErrMsgs[] = $ErrMsg;
            }
        } else {
            # if .htaccess file appears to be from a previous Metavus release
            $OldChecksums = $this->getFileChecksums("install/htaccess.CHECKSUMS");

            $Lines = file(".htaccess");
            if ($Lines === false) {
                throw new Exception("Unable to read htaccess file.");
            }
            $Content = "";
            foreach ($Lines as $Line) {
                if (!preg_match("/^RewriteBase/", $Line)) {
                    $Content .= $Line;
                }
            }
            $Checksum = md5($Content);
            $this->msg(3, "Checksum for current .htaccess file:  ".$Checksum);
            if (in_array($Checksum, $OldChecksums)) {
                # replace .htaccess file
                $this->msg(1, "Replacing .htaccess file...");
                $ErrMsg = $this->installHtaccess(".htaccess");
                if ($ErrMsg) {
                    $ErrMsgs [] = $ErrMsg;
                }
            } else {
                # create .htaccess template file
                $this->msg(1, "Creating .htaccess template file...");
                $ErrMsg = $this->installHtaccess(".htaccess.Metavus");
                if ($ErrMsg) {
                    $ErrMsgs [] = $ErrMsg;
                }
            }
        }

        # add .htaccess that blocks php execution in directories apache can write to
        $Dirs = ["include", "tmp", "local"];
        foreach ($Dirs as $Dir) {
            # set htaccess writable to update it
            if (file_exists($Dir."/.htaccess")) {
                chmod($Dir."/.htaccess", 0664);
            }
            $this->copyFile("install/htaccess.BLOCK-PHP", $Dir."/.htaccess");
            # set htaccess read only to make it harder to overwrite
            chmod($Dir."/.htaccess", 0444);
        }

        # check if robots.txt exists
        if (!file_exists("robots.txt")) {
            # if not, create it
            $this->msg(1, "Creating robots.txt file...");
            $ErrMsg = $this->copyFile("install/robots.txt.DIST", "robots.txt");
            if ($ErrMsg) {
                $ErrMsgs [] = $ErrMsg;
            }
        } else {
            # if so, see if it came from an older Metavus version
            $OldChecksums = $this->getFileChecksums("install/robots.txt.CHECKSUMS");
            $RobotsContent = file_get_contents("robots.txt");
            $Checksum = ($RobotsContent === false) ? "--" : md5($RobotsContent);

            $this->msg(3, "Checksum for current robots.txt file:  ".$Checksum);
            if (in_array($Checksum, $OldChecksums)) {
                # it was from an older version, replace it
                $this->msg(1, "Replacing robots.txt file...");
                $ErrMsg = $this->copyFile("install/robots.txt.DIST", "robots.txt");
                if ($ErrMsg) {
                    $ErrMsgs [] = $ErrMsg;
                }
            } else {
                # otherwise create robots.txt template file
                $this->msg(1, "Creating .htaccess template file...");
                $ErrMsg = $this->copyFile("install/robots.txt.DIST", "robots.txt.Metavus");
                if ($ErrMsg) {
                    $ErrMsgs [] = $ErrMsg;
                }
            }
        }

        # move aside any obsolete files if necessary
        if ($IsUpgrade) {
            $this->msg(1, "Checking for obsolete files...");
            foreach ($this->ObsoleteFiles as $File) {
                if (file_exists($File)) {
                    $Result = @rename($File, $File.".OLD");
                    if ($Result === false) {
                        $ErrMsgs[] = "Unable to move aside obsolete file <code>"
                                .$File."</code>.  Please delete or rename this file"
                                ." and restart the upgrade.";
                    }
                }
            }
        }

        # return any error messages to caller
        return $ErrMsgs;
    }

    /**
     * Install new .htaccess file.
     * @param string $FileName Name (with path) of new .htaccess file.
     * @return string|null Error message or NULL if no error.
     */
    private function installHtaccess(string $FileName)
    {
        # set up .htaccess file with correct rewrite base
        $BasePath = parse_url($this->FVars["F_SiteUrl"], PHP_URL_PATH);
        if (($BasePath === null) || ($BasePath === false)) {
            throw new Exception("No path found in site URL (\""
                    .$this->FVars["F_SiteUrl"]."\").");
        }
        $BasePath = trim($BasePath);
        $BasePath = preg_match("%/$%", $BasePath) ? $BasePath : dirname($BasePath);
        if ($BasePath == "//") {
            $BasePath = "/";
        }
        $ConfigReplacements = array(
            "X-REWRITEBASE-X" => $BasePath,
        );

        if (file_exists($FileName)) {
            # set htaccess writable to update it
            chmod($FileName, 0664);
        }
        $ErrMsg = $this->copyFile(
            "install/htaccess.DIST",
            $FileName,
            $ConfigReplacements
        );
        # set htaccess read only to make it harder to overwrite
        chmod($FileName, 0444);

        # return any error messages to caller
        return $ErrMsg;
    }

    /**
     * Set up database and database tables for new installation.
     * @param array $ErrMsgs Current list of error messages.
     * @return array Possibly-expanded list of error messages.
     */
    private function setUpNewDatabase(array $ErrMsgs): array
    {
        Database::setGlobalServerInfo(
            $this->FVars["F_DBLogin"],
            $this->FVars["F_DBPassword"],
            $this->FVars["F_DBHost"]
        );

        if (!Database::databaseExists($this->FVars["F_DBName"])) {
            $Result = Database::createDatabase($this->FVars["F_DBName"]);
            if ($Result === false) {
                $ErrMsgs[] = "Could not create database <i>".$this->FVars["F_DBName"]."</i>.";
                return $ErrMsgs;
            }
        }

        Database::setGlobalDatabaseName($this->FVars["F_DBName"]);

        $DB = new Database();

        # set default storage engine (need MyISAM for full-text indexing)
        if (version_compare($DB->getServerVersion(), "5.5", "<")) {
            $DB->query("SET storage_engine=MYISAM");
        } else {
            $DB->query("SET default_storage_engine=MYISAM");
        }

        $DB->setQueryErrorsToIgnore($this->SqlErrorsWeCanIgnore);

        # set up database tables
        foreach ($this->DatabaseSetupFiles as $SqlFile => $Msg) {
            $this->msg(1, $Msg);
            $Result = $DB->executeQueriesFromFile($SqlFile);
            if ($Result === null) {
                $ErrMsgs[] = $DB->queryErrMsg();
                return $ErrMsgs;
            }
        }

        # return (possibly updated) error message list to caller
        return $ErrMsgs;
    }

    /**
     * Load default values for system configuration.
     * @param array $ErrMsgs Current list of error messages.
     * @return array Possibly-expanded list of error messages.
     */
    private function loadDefaultConfiguration(array $ErrMsgs): array
    {
        # load default configuration
        $this->msg(1, "Loading default configuration...");

        # load the default configuration
        $SysConfig = SystemConfiguration::getInstance();
        $SysConfig->setArray(
            "DefaultUserPrivs",
            [PRIV_POSTCOMMENTS]
        );
        $IntConfig = InterfaceConfiguration::getInstance();
        $IntConfig->setString("PortalName", "");
        $IntConfig->setInt("BrowsingFieldId", 6);
        $IntConfig->setString(
            "LegalNotice",
            "Sample Content Copyright 2022 Internet Scout Research Group"
        );

        # reload the system configuration and set the admin e-mail
        $IntConfig->setString("AdminEmail", $this->FVars["F_AdminEmail"]);

        # return (possibly updated) error message list to caller
        return $ErrMsgs;
    }

    /**
     * Initialize application framework.
     * @param string $NewVersion Version we are installing or upgrading to.
     */
    private function initializeAF(string $NewVersion): void
    {
        # set software version for startup
        define("METAVUS_VERSION", $NewVersion);

        # set CWIS_VERSION to METAVUS_VERSION + 4
        $SplitMVVersion = explode(".", METAVUS_VERSION);
        $NewCWISVersion = (string)(((int) $SplitMVVersion[0]) + 4)
            .".".$SplitMVVersion[1]
            .".".$SplitMVVersion[2];
        define("CWIS_VERSION", $NewCWISVersion);

        # initialize application environment
        $this->msg(1, "Initializing application framework...");
        require_once("lib/ScoutLib/AFUrlManagerTrait.php");
        require_once("lib/ScoutLib/AFTaskManagerTrait.php");
        require_once("lib/ScoutLib/ApplicationFramework.php");
        ApplicationFramework::suppressSessionInitialization(true);
        $GLOBALS["StartUpOpt_CLEAR_AF_CACHES"] = true;
        require_once("objects/Bootloader.php");
        (\Metavus\Bootloader::getInstance())->boot();
    }

    /**
     * Set up metadata schemas, load sample records, and create administrative
     * account for new site.
     * @param array $ErrMsgs Current list of error messages.
     * @return array Possibly-expanded list of error messages.
     */
    private function setUpNewSite(array $ErrMsgs): array
    {
        # create schemas
        if (MetadataSchema::schemaExistsWithId(MetadataSchema::SCHEMAID_DEFAULT)) {
            $ResourceSchema = new MetadataSchema(MetadataSchema::SCHEMAID_DEFAULT);
        } else {
            $ResourceSchema = MetadataSchema::create("Resources");
        }
        $ResourceSchema->setViewPage("index.php?P=FullRecord&ID=\$ID");
        $ResourceSchema->setEditPage("index.php?P=EditResource&ID=\$ID");

        if (MetadataSchema::schemaExistsWithId(MetadataSchema::SCHEMAID_USER)) {
            $UserSchema = new MetadataSchema(MetadataSchema::SCHEMAID_USER);
        } else {
            $UserSchema = MetadataSchema::create("Users");
        }
        $UserSchema->setViewPage("index.php?P=UserList");
        $UserSchema->setEditPage("index.php?P=EditUser&ID=\$ID");

        $CollectionSchema = MetadataSchema::create("Collections");
        $CollectionSchema->setViewPage("index.php?P=DisplayCollection&ID=\$ID");
        $CollectionSchema->setItemClassName("Metavus\\Collection");
        $CollectionSchema->setEditPage("index.php?P=EditResource&ID=\$ID");

        # load qualifiers
        $this->msg(1, "Loading qualifiers...");
        $ErrMsg = $this->importQualifiersFromXml("install/Qualifiers.xml");
        if ($ErrMsg !== null) {
            $ErrMsgs[] = $ErrMsg;
            return $ErrMsgs;
        }

        # load metadata fields
        $SchemaFiles = array(
            "DCMI" => "Dublin Core",
            "Administrative" => "administrative",
        );
        foreach ($SchemaFiles as $SchemaSuffix => $SchemaDescription) {
            $this->msg(1, "Loading ".$SchemaDescription." fields for default resource schema...");
            $Result = $ResourceSchema->addFieldsFromXmlFile(
                "install/MetadataSchema--".$SchemaSuffix.".xml"
            );
            if ($Result === false) {
                $SchemaErrors = $ResourceSchema->errorMessages();
                foreach ($SchemaErrors as $Errors) {
                    $ErrMsgs = array_merge($ErrMsgs, $Errors);
                }
                return $ErrMsgs;
            }
        }

        # load user metadata fields
        $this->msg(1, "Loading fields for user resource schema...");
        $Result = $UserSchema->addFieldsFromXmlFile(
            "install/MetadataSchema--User.xml"
        );
        if ($Result === false) {
            $SchemaErrors = $UserSchema->errorMessages();
            foreach ($SchemaErrors as $Errors) {
                $ErrMsgs = array_merge($ErrMsgs, $Errors);
            }
            return $ErrMsgs;
        }

        # load collection metadata fields
        $this->msg(1, "Loading fields for collection schema...");
        $Result = $CollectionSchema->addFieldsFromXmlFile(
            "install/MetadataSchema--Collection.xml"
        );
        if ($Result === false) {
            $SchemaErrors = $CollectionSchema->errorMessages();
            foreach ($SchemaErrors as $Errors) {
                $ErrMsgs = array_merge($ErrMsgs, $Errors);
            }
            return $ErrMsgs;
        }

        # create administrative account
        $UFactory = new UserFactory();
        $AdminUserName = $this->FVars["F_AdminLogin"];
        if ($UFactory->userNameExists($AdminUserName)) {
            $Admin = new User($AdminUserName);
        } else {
            $this->msg(1, "Adding administrator account...");
            # (nullify password requirements for creation of admin account)
            User::setPasswordRules(0);
            User::setPasswordMinLength(0);
            User::setPasswordMinUniqueChars(0);
            $Admin = $UFactory->createNewUser(
                $AdminUserName,
                $this->FVars["F_AdminPassword"],
                $this->FVars["F_AdminPassword"],
                $this->FVars["F_AdminEmail"],
                $this->FVars["F_AdminEmail"]
            );
            if (is_object($Admin)) {
                $Admin->isActivated(true);
                $Admin->grantPriv(PRIV_SYSADMIN);
                $Admin->grantPriv(PRIV_NEWSADMIN);
                $Admin->grantPriv(PRIV_RESOURCEADMIN);
                $Admin->grantPriv(PRIV_CLASSADMIN);
                $Admin->grantPriv(PRIV_NAMEADMIN);
                $Admin->grantPriv(PRIV_RELEASEADMIN);
                $Admin->grantPriv(PRIV_USERADMIN);
                $Admin->grantPriv(PRIV_POSTCOMMENTS);
                $Admin->grantPriv(PRIV_COLLECTIONADMIN);
            } else {
                foreach ($Admin as $ErrCode) {
                    $ErrMsgs[] = "Error creating administrator account ("
                            .$ErrCode.").";
                }
                return $ErrMsgs;
            }
        }

        # set admin as logged-in user so that sample records are created by admin
        User::getCurrentUser()->login($Admin->get("UserName"), "", true);

        # load sample records
        $this->msg(1, "Loading sample records...");
        try {
            $this->loadSampleRecords();
        } catch (Exception $Ex) {
            $ErrMsgs[] = $Ex->getMessage();
            return $ErrMsgs;
        }

        # load sample collections
        $this->msg(1, "Loading sample collections...");
        try {
            $this->loadSampleCollections($CollectionSchema->id());
        } catch (Exception $Ex) {
            $ErrMsgs[] = $Ex->getMessage();
            return $ErrMsgs;
        }

        # clean up our temporary global settings
        User::getCurrentUser()->logout();

        # return unchanged error message list to caller
        return $ErrMsgs;
    }

    /**
     * Load sample records.
     */
    private function loadSampleRecords(): void
    {
        # set up temporary location
        $TmpDir = sys_get_temp_dir()."/MetavusSampleRecords-".date("ymdHis");
        if (mkdir($TmpDir) != true) {
            throw new Exception("Unable to create temporary directory ".$TmpDir);
        }

        # unpack sample records zip file to temporary location
        $ZipFile = new ZipArchive();
        $SampleRecordFile = self::SAMPLE_RECORD_FILE;
        if ($ZipFile->open($SampleRecordFile, ZipArchive::RDONLY) != true) {
            throw new Exception("Unable to open sample record file ".$SampleRecordFile);
        }
        if ($ZipFile->extractTo($TmpDir) != true) {
            throw new Exception("Unable to unpack sample record file "
                    .$SampleRecordFile." to temporary directory ".$TmpDir);
        }

        # load sample records from temporary location
        $XmlFile = $TmpDir."/".pathinfo($SampleRecordFile, PATHINFO_FILENAME)
                ."/SampleRecords.xml";
        $RFactory = new RecordFactory(MetadataSchema::SCHEMAID_DEFAULT);
        $RecordIds = $RFactory->importRecordsFromXmlFile($XmlFile);
        $ImportErrors = $RFactory->errorMessages("importRecordsFromXmlFile");
        if (count($ImportErrors)) {
            throw new Exception("XML Import Errors: ".implode(" - ", $ImportErrors));
        }

        # clean up temporary location
        StdLib::deleteDirectoryTree($TmpDir);

        # for each new sample record
        $SEngine = new SearchEngine();
        $REngine = new Recommender();
        foreach ($RecordIds as $RecordId) {
            # make sure record is publicly-viewable
            $Record = new Record($RecordId);
            $Record->set("Record Status", "Published");
            $Record->set("Date Of Record Release", "NOW");

            # queue search and recommender database rebuild for record
            $SEngine->queueUpdateForItem($Record);
            $REngine->queueUpdateForItem($Record);
        }
    }

    /**
     * Load sample collections.
     * @param int $SchemaId ID for collection schema.
     */
    private function loadSampleCollections(int $SchemaId): void
    {
        $RFactory = new RecordFactory($SchemaId);
        $RecordIds = $RFactory->importRecordsFromXmlFile("install/SampleCollections.xml");
    }

    /**
     * Upgrade existing database to make it ready for use with new version.
     * @param array $ErrMsgs Current list of error messages.
     * @param string $OldVersion Version number from which we are upgrading.
     * @return array Possibly-expanded list of error messages.
     */
    private function upgradeExistingDatabase(array $ErrMsgs, string $OldVersion): array
    {
        # if legacy configuration file was used
        if (!file_exists("local/config.php")) {
            # write out configuration file in new location
            $this->msg(1, "Migrating configuration file...");
            $ConfigReplacements = array(
                "X-DBUSER-X" => addslashes($this->FVars["F_DBLogin"]),
                "X-DBPASSWORD-X" => addslashes($this->FVars["F_DBPassword"]),
                "X-DBHOST-X" => addslashes($this->FVars["F_DBHost"]),
                "X-DBNAME-X" => addslashes($this->FVars["F_DBName"]),
            );
            $ErrMsg = $this->copyFile(
                "install/config.php.DIST",
                "local/config.php",
                $ConfigReplacements
            );
            if ($ErrMsg) {
                $ErrMsgs[] = $ErrMsg;
            }

            # rename legacy configuration file so it won't be loaded
            rename("config.php", "OLD.config.php");
        }

        require_once("lib/ScoutLib/StdLib.php");
        require_once("lib/ScoutLib/Database.php");
        Database::setGlobalServerInfo(
            $this->FVars["F_DBLogin"],
            $this->FVars["F_DBPassword"],
            $this->FVars["F_DBHost"]
        );

        Database::setGlobalDatabaseName(
            $this->FVars["F_DBName"]
        );

        try {
            $DB = new Database();
        } catch (\Exception $e) {
            $ErrMsgs[] = "Could not connect to database <i>"
                .$this->FVars["F_DBName"]
                ."</i> to upgrade.";
            return $ErrMsgs;
        }

        $DB->setQueryErrorsToIgnore($this->SqlErrorsWeCanIgnore);

        # for each available database upgrade file
        $SqlFileNames = $this->readDirectory(
            "install/DBUpgrades/.",
            "/DBUpgrade--.*\.sql/"
        );
        $PhpFileNames = $this->readDirectory(
            "install/DBUpgrades/.",
            "/DBUpgrade--.*\.php/"
        );
        $FileNames = array_merge($SqlFileNames, $PhpFileNames);
        $FileNames = self::sortUpgradeFileList($FileNames);

        foreach ($FileNames as $FileName) {
            # parse out version number of upgrade file
            $UpgradeVersion = (string)str_replace(
                ["DBUpgrade--", ".sql", ".php"],
                "",
                $FileName
            );

            # if upgrade file version is greater than or equal to old software version
            if (self::legacyVersionCompare($UpgradeVersion, $OldVersion, ">=")) {
                # add file to list of those to be run
                $FilesToRun["install/DBUpgrades/".$FileName] =
                        "Upgrading database to version ".$UpgradeVersion
                        .(preg_match("/.php/", $FileName) ? " (PHP)" : "")
                        ."...";
            }
        }

        # if there were upgrades to be done
        if (isset($FilesToRun)) {
            # add entry to test database permissions at start of upgrade
            $Test = array("install/TestDBPerms.sql" => "Testing database permissions...");
            $FilesToRun = $Test + $FilesToRun;

            # for each file
            foreach ($FilesToRun as $FileName => $Msg) {
                # if file was PHP upgrade file
                $this->msg(1, $Msg);
                if (preg_match("/.php/", $FileName)) {
                    # run PHP for upgrade
                    include($FileName);
                    $ErrMsg = isset($this->ErrMsg) ? $this->ErrMsg : null;
                    unset($this->ErrMsg);
                # else file was SQL upgrade file
                } else {
                    # run SQL for upgrade
                    $Result = $DB->executeQueriesFromFile($FileName);
                    $ErrMsg = ($Result === null) ?  $DB->queryErrMsg() : null;
                }

                # if errors were encountered
                if ($ErrMsg) {
                    # add error messages to list
                    $ErrMsgs[] = $ErrMsg;

                    # stop running upgrades
                    break;
                }
            }
        }

        # return any error messages to caller
        return $ErrMsgs;
    }

    /**
    * Perform version-specific site upgrades (after application framework has
    * been intialized), from PHP files stored in install/SiteUpgrades.  Any code
    * run must be idempotent, and any error messages should be returned by
    * putting them in an array in $this->ErrMsgs.  Upgrade files should
    * be named "SiteUpgrade--VERSION.php", where VERSION is the version being
    * upgraded to.
    * @param string $OldVersion Version we are upgrading from.
    * @return array Error messages (or empty array if no errors).
    */
    private function upgradeSite(string $OldVersion): array
    {
        # set up log message function access
        $GLOBALS["G_MsgFunc"] = [$this, "msg"];

        # for each available site upgrade file
        $FileNames = $this->readDirectory(
            "install/SiteUpgrades/.",
            "/SiteUpgrade--.*\.php/"
        );
        $FileNames = self::sortUpgradeFileList($FileNames);

        foreach ($FileNames as $FileName) {
            # parse out version number of upgrade file
            $UpgradeVersion = (string)str_replace("SiteUpgrade--", "", $FileName);
            $UpgradeVersion = str_replace(".php", "", $UpgradeVersion);

            # if upgrade file version is greater than or equal to old software version
            if (self::legacyVersionCompare($UpgradeVersion, $OldVersion, ">=")) {
                # add file to list of those to be run
                $FilesToRun["install/SiteUpgrades/".$FileName] =
                        "Upgrading site to version ".$UpgradeVersion."...";
            }
        }

        # if there were upgrades to be done
        if (isset($FilesToRun)) {
            # for each file
            foreach ($FilesToRun as $FileName => $Msg) {
                # run PHP for upgrade
                $this->msg(1, $Msg);
                include($FileName);

                # if error was encountered, stop upgrades
                $ErrMsgs = $GLOBALS["G_ErrMsgs"];
                if (!is_null($ErrMsgs)) {
                    break;
                }
            }
        }

        # return error messages (if any) to caller
        return $ErrMsgs ?? [];
    }

    /**
     * Check whether this is a new installation or an upgrade, and get
     * the old version if it is an upgrade.
     * @return string|null Old version, that we are upgrading from, or NULL
     *      if this is a new installation.
     */
    private function checkForUpgrade()
    {
        # if both old and new version files are present
        $OldVersion = null;
        if (file_exists("VERSION") && file_exists("NEWVERSION")) {
            # read in old version
            $InputFile = fopen("VERSION", "r");
            if ($InputFile === false) {
                throw new Exception("Unable to open VERSION file.");
            }
            $OldVersion = fgets($InputFile, 256);
            if ($OldVersion === false) {
                throw new Exception("Unable to read old version from VERSION file.");
            }
            $OldVersion = trim($OldVersion);
            fclose($InputFile);

            # read in new version
            $InputFile = fopen("NEWVERSION", "r");
            if ($InputFile === false) {
                throw new Exception("Unable to open NEWVERSION file.");
            }
            $NewVersion = fgets($InputFile, 256);
            if ($NewVersion === false) {
                throw new Exception("Unable to read old version from VERSION file.");
            }
            $NewVersion = trim($NewVersion);
            fclose($InputFile);

            # if new version is older than old version
            if (self::legacyVersionCompare($NewVersion, $OldVersion, "<")) {
                throw new Exception("New software version (".$NewVersion.") is older"
                        ." than existing installed version (".$OldVersion.").");
            }
        }

        # return old version number to caller
        return $OldVersion;
    }

    /**
     * Load existing installation settings.
     */
    private function loadOldInstallInfo(): void
    {
        # load values from existing configuration file
        if (file_exists("local/config.php")) {
            include("local/config.php");
        } elseif (file_exists("config.php")) {
            include("config.php");
        } elseif (file_exists("include/SPT--Config.php")) {
            include("include/SPT--Config.php");
        }

        $Protocol = isset($_SERVER["HTTPS"]) ? "https://" : "http://";

        if (array_key_exists("G_Config", $GLOBALS) && is_array($GLOBALS["G_Config"])) {
            $this->FVars["F_DBHost"] = $GLOBALS["G_Config"]["Database"]["Host"];
            $this->FVars["F_DBLogin"] = $GLOBALS["G_Config"]["Database"]["UserName"];
            $this->FVars["F_DBPassword"] = $GLOBALS["G_Config"]["Database"]["Password"];
            $this->FVars["F_DBName"] = $GLOBALS["G_Config"]["Database"]["DatabaseName"];
            $this->FVars["F_DefaultUI"] = $GLOBALS["G_Config"]["UserInterface"]["DefaultUI"];
        } else {
            $this->FVars["F_DBHost"] = $GLOBALS["SPT_DBHost"];
            $this->FVars["F_DBLogin"] = $GLOBALS["SPT_DBUserName"];
            $this->FVars["F_DBPassword"] = $GLOBALS["SPT_DBPassword"];
            $this->FVars["F_DBName"] = $GLOBALS["SPT_DBName"];
            $this->FVars["F_DefaultUI"] = $GLOBALS["SPT_DefaultUI"];
        }
        $this->FVars["F_Submit"] = "Upgrade Installation";
        $this->FVars["F_SiteUrl"] = $Protocol.$_SERVER["HTTP_HOST"]
                .dirname($_SERVER["SCRIPT_NAME"]);
        if (substr($this->FVars["F_SiteUrl"], -1) != "/") {
            $this->FVars["F_SiteUrl"] .= "/";
        }
    }

    /**
     * Queue follow-up tasks to be executed once site is up.
     * @param bool $IsUpgrade TRUE if an upgrade.
     * @param string|null $OldVersion Version being upgraded from, or NULL if
     *      new installation.
     */
    private function queueFollowUpWork(bool $IsUpgrade, $OldVersion): void
    {
        $Tasks[] = ["Callback" => [ "\\Metavus\\SearchEngine", "queueDBRebuildForAllSchemas" ],
            "Parameters" => null,
            "Description" => "Rebuild Search Database",
            "Priority" => ApplicationFramework::PRIORITY_MEDIUM
        ];

        if ($IsUpgrade) {
            $Tasks[] = [
                "Callback" => ["\\Metavus\\FollowupTasks", "performUpgradeFollowUp"],
                "Parameters" => [$OldVersion],
                "Description" => "Upgrade Follow-Up",
                "Priority" => ApplicationFramework::PRIORITY_HIGH
            ];
        } else {
            $Tasks[] = [
                "Callback" => ["\\Metavus\\FollowupTasks", "performNewInstallFollowUp"],
                "Parameters" => null,
                "Description" => "New Installation Follow-Up",
                "Priority" => ApplicationFramework::PRIORITY_HIGH
            ];
        }

        $AF = ApplicationFramework::getInstance();
        foreach ($Tasks as $Task) {
            if (!is_callable($Task["Callback"])) {
                throw new Exception("Invalid callback.");
            }
            $AF->queueUniqueTask(
                $Task["Callback"],
                $Task["Parameters"],
                $Task["Priority"],
                $Task["Description"]
            );
        }
    }


    # ----- UTILITY FUNCTIONS ----------------------------------------------------

    /**
    * Copy a file, optionally doing keyword replacement on the contents.
    * @param string $SrcFile Name of original file.
    * @param string $DstFile Name of new file.
    * @param array|NULL $Replacements with keys giving keywords to search
    *     for and values giving resplacements.
    * @return null|string NULL if successful or error message if failed.
    */
    private function copyFile(string $SrcFile, string $DstFile, $Replacements = null)
    {
        # read source file contents
        $Text = @file_get_contents($SrcFile);
        if ($Text === false) {
            return "Unable to open file <i>".$SrcFile."</i>.";
        }

        if ($Replacements !== null) {
            # make substitutions
            $Text = str_replace(array_keys($Replacements), $Replacements, $Text);
        }

        # write out destination file
        $Result = @file_put_contents($DstFile, $Text);
        if ($Result === false) {
            return "Unable to write file <i>".$DstFile."</i>.";
        }

        # return NULL to caller to indicate success
        return null;
    }

    /**
     * Read list of files from specified directory, with names matching
     * specified regular expression.
     * @param string $Path Directory to read.
     * @param string $PerlExp Regular expression.
     * @return array Sorted array of file names.  If the directory cannot be
     *      read, an empty array is returned.
     */
    private function readDirectory(string $Path, string $PerlExp): array
    {
        # while file names left to read from directory
        $FileNames = [];
        $Dir = @opendir($Path);
        if ($Dir === false) {
            return [];
        }
        while ($FileName = readdir($Dir)) {
            # if name matches mask
            if (preg_match($PerlExp, $FileName)) {
                # store file name in array
                $FileNames[] = $FileName;
            }
        }
        closedir($Dir);

        # return sorted array of file names to caller
        sort($FileNames);
        return $FileNames;
    }

    /**
     * Read in checksums for older versions of distribution files.
     * @param string $SrcFile containing VERSION\tMD5 for old versions.  Lines
     *      starting with a hashmark are ignored.
     * @return array(VERSION => MD5)
     * @throws Exception If unable to read checksum file.
     */
    private function getFileChecksums(string $SrcFile): array
    {
        $OldChecksums = [];

        $Lines = file($SrcFile);
        if ($Lines === false) {
            throw new Exception("Unable to read checksum file \"".$SrcFile."\".");
        }
        foreach ($Lines as $Line) {
            if (!preg_match("/^#/", $Line)) {
                list($Version, $Sum) = explode(" ", $Line);
                $OldChecksums[$Version] = trim($Sum);
            }
        }

        return $OldChecksums;
    }

    /**
     * Write message to installation log file.
     * @param string $Message Message to write out.
     */
    private function logMsg(string $Message): void
    {
        static $FHandle = false;
        if ($FHandle == false) {
            $InstallLogFile = "local/logs/install.log";
            if (!is_dir(dirname($InstallLogFile))
                    && is_writable(dirname(dirname($InstallLogFile)))) {
                mkdir(dirname(dirname($InstallLogFile)));
            }
            if ((file_exists($InstallLogFile) && is_writable($InstallLogFile))
                    || (!file_exists($InstallLogFile)
                            && is_writable(dirname($InstallLogFile)))) {
                $FHandle = fopen($InstallLogFile, "a");
            }
        }
        if ($FHandle) {
            $LogMsg = date("Y-m-d H:i:s")."  ".strip_tags($Message)."\n";
            fwrite($FHandle, $LogMsg);
            fflush($FHandle);
        }
    }

    /**
    * Import qualifiers from XML file.
    * @param string $FileName Name of XML file.
    * @return string|null Error message or NULL if execution was successful.
    */
    private function importQualifiersFromXml(
        string $FileName,
        int $SchemaId = MetadataSchema::SCHEMAID_DEFAULT
    ) {
        $QFactory = new QualifierFactory();
        try {
            $QFactory->importQualifiersFromXmlFile($FileName);
            $ErrMsg = null;
        } catch (Exception $Ex) {
            $ErrMsg = $Ex->getMessage();
        }
        return $ErrMsg;
    }

    /**
     * Sort list of upgrade files so that they appear in the order we would
     * like them to be run (CWIS upgrades before Metavus, database upgrades
     * before PHP upgrades).
     * @param array $FileNames List of upgrade files.
     * @return array Sorted list.
     * @see Developer::sortUpgradeFiles()  (identical method)
     */
    private static function sortUpgradeFileList(array $FileNames): array
    {
        $VerExtractFunc = function (string $FileName) {
            $FileName = pathinfo($FileName, PATHINFO_FILENAME);
            $Version = preg_replace("/[A-Z]+--/i", "", $FileName);
            return $Version;
        };
        $SortFunc = function ($AFileName, $BFileName) use ($VerExtractFunc) {
            if ($AFileName == $BFileName) {
                return 0;
            }
            $AVersion = $VerExtractFunc($AFileName);
            $BVersion = $VerExtractFunc($BFileName);
            # if versions are equal compare file names so that SQL ugprades run first
            if ($AVersion == $BVersion) {
                return $AFileName <=> $BFileName;
            }
            return (int)self::legacyVersionCompare($AVersion, $BVersion);
        };
        usort($FileNames, $SortFunc);
        return $FileNames;
    }

    /**
     * Compare version numbers, with adjustments to understand CWIS vs Metavus.
     * (Version number is assumed to be CWIS if it's equal to or above the oldest
     * upgradable version number.)
     * @param string $VersionOne First version number.
     * @param string $VersionTwo Second version number.
     * @param string $Operator Comparison operator (same as that supported
     *      for version_compare()).
     * @return bool|int If operator supplied, then TRUE if
     *      (VersionOne Operator VersionTwo) is true, otherwise FALSE.  If no
     *      operator supplied, then 1/0/-1 like the spaceship operator.
     *      (Similar to version_compare().)  (OPTIONAL)
     * @see Developer::legacyVersionCompare()  (identical method)
     */
    private static function legacyVersionCompare(
        string $VersionOne,
        string $VersionTwo,
        ?string $Operator = null
    ) {
        $AdjustFunc = function (string $Version): string {
            if (version_compare($Version, self::OLDEST_UPGRADABLE_VERSION, "<")) {
                $Pieces = explode(".", $Version, 2);
                $Version = ((string)((int)$Pieces[0] + 4)).".".$Pieces[1];
            }
            return $Version;
        };
        $VersionOne = $AdjustFunc($VersionOne);
        $VersionTwo = $AdjustFunc($VersionTwo);
        if ($Operator === null) {
            return version_compare($VersionOne, $VersionTwo);
        } else {
            return version_compare($VersionOne, $VersionTwo, $Operator);
        }
    }
}
