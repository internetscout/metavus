<?PHP
#
#   FILE:  TurnstileCaptcha.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2026 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins\Captcha;
use ScoutLib\ApplicationFramework;
use Metavus\FormUI;

/**
 * Captcha implementation using Cloudflare's Turnstile.
 */
class TurnstileCaptcha extends Captcha
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Get config options for Turnstile CAPTCHA.
     * @return array Config options.
     */
    public static function getConfigOptions(): array
    {
        $Options = [
            "TurnstileHeading" => [
                "Type" => FormUI::FTYPE_HEADING,
                "Label" => "Turnstile CAPTCHA",
            ],
            "TurnstileSiteKey" => [
                "Type" => FormUI::FTYPE_TEXT,
                "Label" => "Turnstile Site Key",
                "Help" => "Site key for the <a href='https://developers.cloudflare.com/turnstile/'"
                    .">Cloudflare Turnstile</a> service. Displayed below the widget name in the "
                    ."list of Turnstile widgets in your Cloudflare Dashboard under "
                    ."Protect &amp; Connect / Application Security / Turnstile.",
            ],
            "TurnstileSecretKey" => [
                "Type" => FormUI::FTYPE_TEXT,
                "Label" => "Turnstile Secret Key",
                "Help" => "Listed at the bottom of the Edit Widget page accessed from the "
                    ."list of widgets.",
            ],
        ];

        return $Options;
    }

    /**
     * Initialize Turnstile CAPTCHA.
     * @return ?string NULL on success or a string describing the problem on error.
     */
    public static function initialize(): ?string
    {
        $Plugin = \Metavus\Plugins\Captcha::getInstance(true);

        $SiteKey = $Plugin->getConfigSetting("TurnstileSiteKey");
        $SecretKey = $Plugin->getConfigSetting("TurnstileSecretKey");

        $MissingSettings = [];
        if ($SiteKey === null || strlen($SiteKey) == 0) {
            $MissingSettings[] = "Turnstile Site Key";
        }
        if ($SecretKey === null || strlen($SecretKey) == 0) {
            $MissingSettings [] = "Turnstile Secret Key";
        }
        if (count($MissingSettings) > 0) {
            return implode(" and ", $MissingSettings)." must be configured.";
        }

        self::$SiteKey = $SiteKey;
        self::$SecretKey = $SecretKey;
        return null;
    }

    /**
     * Get the HTML to display a captcha.
     * @param string $UniqueKey Unique key to distinguish this captcha from
     *       others on the page.
     * @return string Captcha HTML.
     * // phpcs:disable Generic.Files.LineLength.MaxExceeded
     * @see https://developers.cloudflare.com/turnstile/get-started/client-side-rendering/#explicit-rendering
     * @see https://developers.cloudflare.com/turnstile/get-started/client-side-rendering/widget-configurations/#complete-configuration-reference
     * // phpcs:enable
     */
    public static function getCaptchaHtml(string $UniqueKey = "") : string
    {
        static $HeaderInserted = false;
        if (!$HeaderInserted) {
            $ScriptUrl = "https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit";
            $Html = '<script src="'.$ScriptUrl.'" defer></script>';

            ApplicationFramework::getInstance()->addPageHeaderContent($Html);
            $HeaderInserted = true;
        }

        $Id = "cf-turnstile".(strlen($UniqueKey) > 0 ? "-".$UniqueKey : "");
        $Config = [
            "sitekey" => self::$SiteKey,
        ];
        if (strlen($UniqueKey) > 0) {
            $Config["response-field-name"] = "cf-turnstile-response-".$UniqueKey;
        }

        $Html = '<div id="'.$Id.'"></div>'
            .'<script>'
            .'$(window).on("load", function(){'
            .'var config = '.json_encode($Config).';';

        if (self::$AutoSubmitting) {
            $Html .= 'config["callback"] = function(token) {'
                .'$("#'.$Id.'").parents("form").first().trigger("submit");'
                .'};';
        }

        $Html .= 'turnstile.render("#'.$Id.'", config);'
            .'});'
            .'</script>';

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
    public static function verifyCaptcha(string $UniqueKey = ""): ?bool
    {
        $VerifyEndpoint = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

        $InputName = "cf-turnstile-response"
            .(strlen($UniqueKey) > 0 ? "-".$UniqueKey : "");

        $Token = $_POST[$InputName] ?? '';

        $Data = [
            'secret' => self::$SecretKey,
            'response' => $Token,
        ];

        $StreamOptions = [
            'http' => [
                'header' => "Content-type: application/x-www-form-urlencoded\r\n",
                'method' => 'POST',
                'content' => http_build_query($Data),
            ]
        ];

        $Stream = stream_context_create($StreamOptions);
        $Response = file_get_contents($VerifyEndpoint, false, $Stream);

        # return null when we can't verify the response one way or the other
        if ($Response === false) {
            return null;
        }

        # otherwise, decode response
        $Response = json_decode($Response, true);

        # return null if the response was not an array or does not contain a
        # 'success' element
        if (!is_array($Response) || !isset($Response['success'])) {
            return null;
        }

        # and pass along the 'success' bool
        return $Response['success'];
    }

    # ---- PRIVATE INTERFACE -------------------------------------------------
    private static $SiteKey = "";
    private static $SecretKey = "";
}
