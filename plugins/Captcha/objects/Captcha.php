<?PHP
#
#   FILE:  Captcha.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2026 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins\Captcha;

/**
 * Base class for all Captcha implementations.
 */
abstract class Captcha
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Get the HTML to display a captcha.
     * @param string $UniqueKey Unique key to distinguish this captcha from
     *         others on the page.
     * @return string Captcha HTML.
     */
    abstract public static function getCaptchaHtml(string $UniqueKey = "") : string;

    /**
     * Verify a captcha code.
     * @param string $UniqueKey Unique key to distinguish this captcha from
     *         others on the page.
     * @return null|bool NULL: unable to display captcha
     *        TRUE: Captcha displayed and successfully solved
     *        FALSE: Captcha displayed but solved incorrectly
     */
    abstract public static function verifyCaptcha(string $UniqueKey = ""): ?bool;

    /**
     * Get configuration options for a Captcha backend.
     * @return array Config options.
     */
    abstract public static function getConfigOptions() : array;

    /**
     * Initialize a Captcha backend.
     * @return NULL on success or a string describing the problem on error.
     */
    abstract public static function initialize(): ?string;


    /**
     * Make the Captchas on the page auto-submit their containing form when
     * successfully solved.
     */
    public static function makeAutoSubmitting(): void
    {
        self::$AutoSubmitting = true;
    }

    # ---- PRIVATE INTERFACE -------------------------------------------------

    protected static $AutoSubmitting = false;
}
