<?PHP
#
#   FILE:  Captcha.php
#
#   A plugin for the Metavus digital collections platform
#   Copyright 2002-2026 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins;
use Exception;
use Metavus\FormUI;
use Metavus\Plugin;
use Metavus\Plugins\Captcha\TurnstileCaptcha;
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
        $this->Version = "2.0.0";
        $this->Description = "Adds <a href=\"http://captcha.net\" "
            ."target=\"_blank\">CAPTCHA</a> "
            ."support to protect against attacks by spammers using bots. ";
        $this->Author = "Internet Scout Research Group";
        $this->Url = "https://metavus.net";
        $this->Email = "support@metavus.net";
        $this->Requires = [ "MetavusCore" => "1.2.0"];
        $this->EnabledByDefault = false;
    }

    /**
     * Set up configuration options.
     * (cannot be done in register() because the plugin's object directories
     * haven't yet been added and we use the TurnstileCaptcha object)
     * @return NULL on success, string describing the error on failure.
     */
    public function setUpConfigOptions(): ?string
    {
        $this->CfgSetup = [
            "Method" =>  [
                "Type" => FormUI::FTYPE_OPTION,
                "Label" => "CAPTCHA Method",
                "Help" =>
                "Select what manner of CAPTCHA you wish to display.",
                "Options" => [
                    "Turnstile" => "Cloudflare Turnstile",
                ],
                "Default" => "Turnstile",
            ],
            "DisplayIfLoggedIn" => [
                "Type" => FormUI::FTYPE_FLAG,
                "Label" => "Display CAPTCHA for logged-in users",
                "Help" => "",
                "OnLabel" => "Yes",
                "OffLabel" => "No",
                "Default" => false,
            ],
            "AnonChallengeHeading" => [
                "Type" => FormUI::FTYPE_HEADING,
                "Label" => "Anonymous User Challenges",
            ],
            "AnonChallengeEnabled" => [
                "Type" => FormUI::FTYPE_FLAG,
                "Label" => "Challenge Anonymous Users",
                "Default" => false,
                "Help" => "Present a captcha to anonymous users who arrive on the"
                    ." one of the configured pages without any cookies set (implies they have"
                    ." not visited any other pages)."
            ],
            "AnonChallengeCpuLoadCutoff" => [
                "Type" => FormUI::FTYPE_NUMBER,
                "Label" => "System Load Threshold for Anonymous Challenges",
                "MinVal" => 0,
                "DefaultFunction" => function (): int {
                    $CoreCount = StdLib::getNumberOfCpuCores();
                    return ($CoreCount > 0) ? (int)($CoreCount * 0.75) : 4;
                },
                "DisplayIf" => [
                    "AnonChallengeEnabled" => true,
                ],
                "Help" => "Present captchas to anonymous cookie-less users only "
                        ." when the system load is above this level. Set to 0 to challenge"
                        ." all such users.",
            ],
            "AnonChallengePages" => [
                "Type" => FormUI::FTYPE_PARAGRAPH,
                "Label" => "Challenge Pages",
                "Default" => "SearchResults",
                "DisplayIf" => [
                    "AnonChallengeEnabled" => true,
                ],
                "Help" => "Pages where captchas will be presented to anonymous cookie-less users, "
                    ."listed one PageName per line.",
            ],
            "AnonChallengeFailedMessage" => [
                "Type" => FormUI::FTYPE_PARAGRAPH,
                "Label" => "Challenge Failed Message",
                "Default" => "Captcha challenge not correctly solved.",
                "DisplayIf" => [
                    "AnonChallengeEnabled" => true,
                ],
                "Help" => "Message to display on when users fail the captcha."
            ]
        ];

        $this->CfgSetup += TurnstileCaptcha::getConfigOptions();

        $this->Instructions = TurnstileCaptcha::getInstructions();

        return null;
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

        # configure Turnstile
        if ($this->getConfigSetting("Method") == "Turnstile") {
            return TurnstileCaptcha::initialize();
        }

        return null;
    }

    /**
     * Hook CAPTCHA plugin into the event system.
     * @return array Events to hook.
     */
    public function hookEvents(): array
    {
        return [
            "EVENT_PAGE_LOAD" => "handlePageLoad",
            "EVENT_USER_LOGIN" => "resetState",
            "EVENT_USER_LOGOUT" => "resetState",
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

        if (User::getCurrentUser()->isLoggedIn()
            && !$this->getConfigSetting("DisplayIfLoggedIn")) {
            return "";
        }

        if ($this->AlreadySolved) {
            return "";
        }

        $Html = "";

        $Method = $this->getConfigSetting("Method");
        switch ($Method) {
            case "Turnstile":
                $Html = TurnstileCaptcha::getCaptchaHtml($UniqueKey);
                break;

            default:
                throw new Exception(
                    "Unknown Captcha method (should be impossible)."
                );
        }

        return $Html;
    }

    /**
     * Verify a captcha code.
     * @param string $UniqueKey Unique key to distinguish this captcha from
     *         others on the page.
     * @return null|bool NULL: unable to display captcha
     *         TRUE: Captcha displayed and successfully solved
     *         FALSE: Captcha displayed but solved incorrectly
     */
    public function verifyCaptcha(string $UniqueKey = ""): ?bool
    {
        # if the user has already solved a captcha, don't prompt them anymore
        if ($this->AlreadySolved) {
            return true;
        }

        # if user is logged in and we did not show them a captcha, success
        if (User::getCurrentUser()->isLoggedIn()
            && !$this->getConfigSetting("DisplayIfLoggedIn")) {
            return true;
        }

        # start off assuming we won't have a captcha to display
        $Result = null;

        # attempt to validate the captcha, using whatever backend
        # is appropriate for our selected method
        $Method = $this->getConfigSetting("Method");
        switch ($Method) {
            case "Turnstile":
                $Result = TurnstileCaptcha::verifyCaptcha($UniqueKey);
                break;

            default:
                throw new Exception(
                    "Unknown Captcha method (should be impossible)."
                );
        }

        # if we could not display a captcha, bail
        if (is_null($Result)) {
            return $Result;
        }

        # if the validation succeeded, we want to stash that
        if ($Result === true) {
            $this->AlreadySolved = true;
            $_SESSION[self::SESSION_ALREADY_SOLVED] = true;
        }

        return $Result;
    }

    /**
     * Handler for EVENT_PAGE_LOAD that may redirect anonymous users who have
     * no cookies to a Captcha.
     * @param string $PageName Page being loaded.
     */
    public function handlePageLoad(string $PageName): array
    {

        # if cookies are set or challenges are disabled, bail
        if (count($_COOKIE) > 0 || $this->getConfigSetting("AnonChallengeEnabled") == false) {
            return ["PageName" => $PageName];
        }

        # if current page is not configured for a challenge, bail
        $ChallengePages = $this->getConfigSetting("AnonChallengePages");
        $ChallengePages = preg_split('%\v%', $ChallengePages, -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($ChallengePages) || !in_array($PageName, $ChallengePages)) {
            return ["PageName" => $PageName];
        }

        # if system load is below the configured cutoff, bail
        $LoadAverage = sys_getloadavg();
        $LoadChallengeCutoff = $this->getConfigSetting("AnonChallengCpuLoadCutoff");
        if (is_array($LoadAverage) && ($LoadAverage[0] < $LoadChallengeCutoff)) {
            return ["PageName" => $PageName];
        }

        # otherwise, challenge with a captcha
        $this->TrampolineTarget = ApplicationFramework::getInstance()->fullUrl();
        return ["PageName" => "P_Captcha_Trampoline"];
    }

    /**
     * When a user logs out, clear the flag indicating that they've solved a
     * captcha.
     */
    public function resetState(): void
    {
        if (isset($_SESSION[self::SESSION_ALREADY_SOLVED])) {
            unset($_SESSION[self::SESSION_ALREADY_SOLVED]);
        }
        $this->AlreadySolved = false;
    }

    /**
     * Get target URL for Captcha trampoline. (set in handlePageLoad)
     * @return string Target URL.
     */
    public function getTrampolineTarget(): string
    {
        return $this->TrampolineTarget;
    }

    # ---- PRIVATE METHODS ---------------------------------------------------

    const SESSION_ALREADY_SOLVED = "Captcha_AlreadySolved";

    private $AlreadySolved = false;
    private $TrampolineTarget = "";
}
