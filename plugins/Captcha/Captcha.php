<?PHP
#
#   FILE:  Captcha.php
#
#   A plugin for the Metavus digital collections platform
#   Copyright 2002-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins;
use Metavus\Plugin;
use Metavus\User;
use ScoutLib\ApplicationFramework;
use ScoutLib\Database;
use ScoutLib\StdLib;

class Captcha extends Plugin
{
    /**
     * Register the Captcha plugin
     */
    public function register(): void
    {
        $this->Name = "CAPTCHA Anti-Spam";
        $this->Version = "1.1.0";
        $this->Description = "Adds <a href=\"http://captcha.net\" "
            ."target=\"_blank\">CAPTCHA</a> "
            ."support to protect against attacks by spammers using bots. ";
        $this->Author = "Internet Scout Research Group";
        $this->Url = "https://metavus.net";
        $this->Email = "support@metavus.net";
        $this->Requires = [ "MetavusCore" => "1.2.0"];
        $this->EnabledByDefault = true;

        $this->CfgSetup = [
            "Method" =>  [
                "Type" => "Option",
                "Label" => "CAPTCHA Method",
                "Help" =>
                "Select what manner of CAPTCHA you wish to display.",
                "Options" => [
                    "Securimage" => "SecurImage CAPTCHA",
                ],
                "Default" => "Securimage",
            ],
            "Width" => [
                "Type" => "Number",
                "MaxVal" => 1024,
                "Label" => "Width",
                "Help" => "Width of the captcha image",
                "Default" => 115,
            ],
            "Height" => [
                "Type" => "Number",
                "MaxVal" => 1024,
                "Label" => "Height",
                "Help" => "Height of the captcha image",
                "Default" => 40,
            ],
            "DisplayIfLoggedIn" => [
                "Type" => "Flag",
                "Label" => "Display CAPTCHA for logged-in users",
                "Help" => "",
                "OnLabel" => "Yes",
                "OffLabel" => "No",
                "Default" => false,
            ],
        ];

        $this->addAdminMenuEntry(
            "Log",
            "View Captcha Logs",
            [ PRIV_SYSADMIN ]
        );
    }

    /**
     * Initialize the plugin.
     */
    public function initialize(): ?string
    {
        if (isset($_SESSION[self::SESSION_ALREADY_SOLVED]) &&
            $_SESSION[self::SESSION_ALREADY_SOLVED] === true) {
            $this->AlreadySolved = true;
        }

        $this->DB = new Database();
        return null;
    }

    /**
     * Install the Captcha plugin.
     */
    public function install(): ?string
    {
        $Result = $this->checkCacheDirectory();
        if (!is_null($Result)) {
            return $Result;
        }

        return $this->createTables($this->SqlTables);
    }

    /**
     * Uninstall the plugin.
     * @return NULL|string : NULL if successful or an error message otherwise
     */
    public function uninstall(): ?string
    {
        $Path = $this->getCachePath();
        if (file_exists($Path)) {
            # delete .htaccess if present
            # (RFF() does not handle hidden files)
            if (file_exists($Path."/.htaccess")) {
                unlink($Path."/.htaccess");
            }

            if (!StdLib::deleteDirectoryTree($Path)) {
                return "Could not delete the cache directory.";
            }
        }

        return $this->dropTables($this->SqlTables);
    }

    /**
     * Hook CAPTCAH plugin into the event system.
     * @return array Events to hook.
     */
    public function hookEvents(): array
    {
        return [
            "EVENT_USER_LOGIN" => "resetState",
            "EVENT_USER_LOGOUT" => "resetState",
        ];
    }

    /**
     *  Add a View Captcha Logs entry to the system administration menu
     * @return Array of pages to add.
     */
    public function sysAdminMenu(): array
    {
        return [
            "Log" => "View Captcha Logs",
        ];
    }

    /**
     * Get the HTML to display a captcha.
     * @param string $UniqueKey Unique key to distinguish this captcha from
     *   others on the page.
     * @return string Captcha HTML.
     */
    public function getCaptchaHtml(string $UniqueKey = "") : string
    {
        $AF = ApplicationFramework::getInstance();

        # do not cache pages where a Captcha may be displayed
        $AF->doNotCacheCurrentPage();

        $Html = "";

        if (User::getCurrentUser()->isLoggedIn()
            && !$this->getConfigSetting("DisplayIfLoggedIn")) {
            return $Html;
        }

        if ($this->AlreadySolved) {
            return $Html;
        }

        $Method = $this->getConfigSetting("Method");
        switch ($Method) {
            case "Securimage":
                $Html = $this->getSecurimageCaptchaHtml($UniqueKey);
                break;

            default:
                break;
        }

        if (strlen($Html)) {
            $this->updateCaptchaViewLogs();
        }

        return $Html;
    }

    /**
     * Verify a captcha code.
     * @param string $UniqueKey Unique key to distinguish this captcha from
     *   others on the page.
     *
     * @return null|bool NULL: unable to display captcha
     *    TRUE: Captcha displayed and successfully solved
     *    FALSE: Captcha displayed but solved incorrectly
     */
    public function verifyCaptcha(string $UniqueKey = ""): ?bool
    {
        # if the user has already solved a captcha, don't prompt them anymore
        if ($this->AlreadySolved) {
            return true;
        }

        # start off assuming we won't have a captcha to display
        $Result = null;

        # attempt to validate the captcha, using whatever backend
        # is appropriate for our selected method
        $Method = $this->getConfigSetting("Method");
        switch ($Method) {
            case "Securimage":
                $Result = $this->verifySecurimageCaptcha($UniqueKey);
                break;

            default:
                break;
        }

        # if we could not display a captcha, bail
        if (is_null($Result)) {
            return $Result;
        }

        # log success/failure of this attempt
        $this->updateCaptchaAttemptLogs($Result);

        # if the validation succeeded, we want to stash that
        if ($Result === true) {
            $this->AlreadySolved = true;
            $_SESSION[self::SESSION_ALREADY_SOLVED] = true;
        }

        return $Result;
    }

    /**
     * When a user logs out, clear the flag indicating that they've solved a captcha.
     */
    public function resetState(): void
    {
        if (isset($_SESSION[self::SESSION_ALREADY_SOLVED])) {
            unset($_SESSION[self::SESSION_ALREADY_SOLVED]);
        }
        $this->AlreadySolved = false;
    }

    /**
     * Get the per-user Captcha attempt log.
     * @param int $Start Index of the first entry to retrieve.
     * @param int $Count Number of entries to retrieve.
     * @return array Captcha attempts where each row has elements
     *   UserName, LastSeen, Views, Successes, Failuers.
     */
    public function getUserLog(int $Start, int $Count) : array
    {
        $this->DB->query(
            "SELECT * FROM Captcha_UserLog "
            ."ORDER BY LastSeen DESC LIMIT ".$Start.",".$Count
        );

        return $this->DB->fetchRows();
    }

    /**
     * Get the number of user log entries available.
     * @return int Number of log entries.
     */
    public function getUserLogCount() : int
    {
        return $this->DB->query(
            "SELECT COUNT(*) AS N FROM Captcha_UserLog",
            "N"
        );
    }

    /**
     * Get the per-IP Captcha attempt log.
     * @param int $Start Index of the first entry to retrieve.
     * @param int $Count Number of entries to retrieve.
     * @return array Captcha attempts where each row has elements
     *   ClientIp, LastSeen, Views, Successes, Failuers.
     */
    public function getIPLog(int $Start, int $Count) : array
    {
        $this->DB->query(
            "SELECT * FROM Captcha_IpLog "
            ."ORDER BY LastSeen DESC LIMIT ".$Start.",".$Count
        );

        return $this->DB->fetchRows();
    }

    /**
     * Get the number of IP log entries available.
     * @return int Number of log entries.
     */
    public function getIPLogCount() : int
    {
        return $this->DB->query(
            "SELECT COUNT(*) AS N FROM Captcha_IpLog",
            "N"
        );
    }


    # ---- PRIVATE METHODS ---------------------------------------------------

    # database updates

    /**
     * Update captcha view logs after a captcha has been displayed.
     */
    private function updateCaptchaViewLogs(): void
    {
        # retrieve user currently logged in
        $User = User::getCurrentUser();

        $this->DB->query(
            "INSERT IGNORE INTO "
            ."Captcha_IpLog (ClientIp, Views, Successes, Failures) VALUES "
            ."('".$_SERVER["REMOTE_ADDR"]."', 0, 0, 0)"
        );
        $this->DB->query(
            "UPDATE Captcha_IpLog "
            ."SET Views = Views + 1, LastSeen=NOW() "
            ."WHERE ClientIp = '".$_SERVER["REMOTE_ADDR"]."'"
        );

        if ($User->isLoggedIn()) {
            $this->DB->query(
                "INSERT IGNORE INTO "
                ."Captcha_UserLog (UserName, Views, Successes, Failures) VALUES "
                ."('".$User->name()."', 0, 0, 0)"
            );
            $this->DB->query(
                "UPDATE Captcha_UserLog "
                ."SET Views = Views + 1, LastSeen=NOW() "
                ."WHERE UserName='".$User->name()."'"
            );
        }
    }

    /**
     * Update captcha attempt logs after a captcha has been submitted.
     * @param bool $Result TRUE when the captcha was solved correctly, FALSE
     *   when it was not
     */
    private function updateCaptchaAttemptLogs(bool $Result): void
    {
        # retrieve user currently logged in
        $User = User::getCurrentUser();

        # if we were able to run the validation, we then want
        # to update the IP and User logs to indicate success or failure.
        $Column = ($Result) ? "Successes" : "Failures" ;
        $this->DB->query(
            "UPDATE Captcha_IpLog "
            ."SET ".$Column." = ".$Column." + 1"
            ." WHERE ClientIp = '".$_SERVER["REMOTE_ADDR"]."'"
        );

        if ($User->isLoggedIn()) {
            $this->DB->query(
                "UPDATE Captcha_UserLog "
                ."SET ".$Column." = ".$Column." + 1, LastSeen=NOW() "
                ."WHERE UserName = '".$User->name()."'"
            );
        }
    }

    # Securimage backend

    /**
     * Get the HTML to display a Securimage captcha.
     * @param string $UniqueKey Unique key to distinguish this captcha from
     *   others on the page.
     * @return string Captcha HTML.
     */
    private function getSecurimageCaptchaHtml(string $UniqueKey): string
    {
        $AF = ApplicationFramework::getInstance();

        require_once(
            dirname(__FILE__)."/lib/securimage/securimage.php"
        );

        $Options = [
            'input_name' => 'captcha_code_'.$UniqueKey,
            'securimage_path' => $AF->baseUrl()
                ."plugins/Captcha/lib/securimage/",
            'namespace' => $UniqueKey,
            'image_width' => $this->getConfigSetting("Width"),
            'image_height' => $this->getConfigSetting("Height"),
        ];

        $this->checkCacheDirectory();
        $CaptchaHtml = \Securimage::getCaptchaHtml($Options);

        # add honeypot form field
        $Style = "style=\"opacity:0; position:absolute; top:0; left:0;"
            ." height:0; width:0; z-index:-1;\"";
        $CaptchaHtml .= "<label for=\"name\" ".$Style."></label>";
        $CaptchaHtml .= "<input type=\"text\" name=\"name\" id=\"name\""
            ." autocomplete=\"off\" placeholder=\"Your name\" ".$Style."/>";

        return $CaptchaHtml;
    }

    /**
     * Verify a Securimage captcha.
     * @param string $UniqueKey Unique key to use for CAPTCHA.
     * @return null|bool NULL: unable to verify captcha
     *    TRUE: Captcha displayed and successfully solved
     *    FALSE: Captcha displayed but solved incorrectly
     *    or honeypot form field filled out
     */
    private function verifySecurimageCaptcha(string $UniqueKey): ?bool
    {
        require_once(
            dirname(__FILE__)."/lib/securimage/securimage.php"
        );

        if (!isset($_POST['captcha_code_'.$UniqueKey])) {
            return null;
        }

        # honeypot form field check
        if (isset($_POST['name']) && strlen($_POST['name']) > 0) {
            return false;
        }

        $img = new \Securimage();
        $img->setNamespace($UniqueKey);
        return $img->check($_POST['captcha_code_'.$UniqueKey]);
    }

    /**
     * Get path to the directory where securimage stores data.
     * @return string Cache path.
     */
    private function getCachePath() : string
    {
        static $Path = null;

        if (is_null($Path)) {
            $Path = getcwd() . "/local/data/caches/Captcha";
        }

        return $Path;
    }

    /**
     * Ensure cache directory exists, creating it if abselt.
     * @return null|string NULL on success, error string describing the
     *   problem otherwise.
     */
    public function checkCacheDirectory(): ?string
    {
        $Path = $this->getCachePath();

        # ensure cache exists
        if (!file_exists($Path)) {
            $Result = @mkdir($Path, 0777, true);
            if ($Result === false) {
                return "Cache directory ".$Path." could not be created.";
            }
        }

        # exists, but is not a directory
        if (!is_dir($Path)) {
            return "(".$Path.") is not a directory.";
        }

        # exists and is a directory, but is not writeable
        if (!is_writeable($Path)) {
            return "Cache directory ".$Path." is not writeable.";
        }

        # copy upstream .htaccess to ensure this dir is not public
        if (!file_exists($Path."/.htaccess")) {
            copy(
                dirname(__FILE__)."/lib/securimage/database/.htaccess",
                $Path."/.htaccess"
            );
        }

        return null;
    }

    const SESSION_ALREADY_SOLVED = "Captcha_AlreadySolved";

    private $AlreadySolved = false;
    public $DB = null;

    private $SqlTables = [
        "IpLog" => "CREATE TABLE Captcha_IpLog (
                ClientIp VARCHAR(15) UNIQUE,
                Views INT,
                Successes INT,
                Failures INT,
                LastSeen TIMESTAMP
            )",
        "UserLog" => "CREATE TABLE Captcha_UserLog (
                UserName VARCHAR(15) UNIQUE,
                Views INT,
                Successes INT,
                Failures INT,
                LastSeen TIMESTAMP
            )",
    ];
}
