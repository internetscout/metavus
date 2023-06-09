<?PHP
#
#   FILE:  GoogleAnalytics.php
#
#   A plugin for the Metavus digital collections platform
#   Copyright 2018-2020 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins;

use Metavus\WebAnalyticsPlugin;
use ScoutLib\ApplicationFramework;

/**
 * Plugin to add Google Analytics tracking code to the HTML header.  The code
 * is inserted via the EVENT_IN_HTML_HEADER event, so that event must be
 * signaled in the active user interface for the plugin to work correctly.
 */
class GoogleAnalytics extends WebAnalyticsPlugin
{

    # ---- STANDARD PLUGIN INTERFACE -----------------------------------------

    /**
     * Set plugin attributes.
     */
    public function register()
    {
        $this->Name = "Google Analytics";
        $this->Version = "1.2.0";
        $this->Description = "Add Google Analytics tracking code to the HTML"
                ." page header.";
        $this->Author = "Internet Scout";
        $this->Url = "http://scout.wisc.edu/cwis/";
        $this->Email = "scout@scout.wisc.edu";
        $this->Requires = ["MetavusCore" => "1.0.0"];
        $this->EnabledByDefault = false;
    }

    /**
     * Set up configuration options.
     * @return NULL on success or a string on failure.
     */
    public function setUpConfigOptions()
    {
        $this->CfgSetup["TrackingId"] = [
            "Type" => "Text",
            "Label" => "Default Tracking ID",
            "Help" => "Your Google Analytics tracking ID (should be in"
                ." the form <i>GT-XXXXXX</i>, <i>G-XXXXXX</i>, or <i>UA-NNNNNNNN-N</i>).",
            "Size" => 20,
            "Required" => true,
            "ValidateFunction" => function ($FieldName, $FieldValue) {
                if (!self::validateGoogleAnalyticsTrackingId($FieldValue)) {
                    return "Invalid Default Tracking ID value";
                }
                return null;
            },
        ];

        $this->CfgSetup["SiteVerificationCode"] = [
            "Type" => "Text",
            "Label" => "Site Verification Code",
            "Help" => "A verification code used by Google to confirm"
                ." your ownership of the site.",
            "Size" => 40,
            "MaxLength" => 100,
        ];

        $this->CfgSetup["EnhancedLinkAttribution"] = [
            "Type" => "Flag",
            "Label" => "Use Enhanced Link Attribution",
            "Help" => "Whether to include code for Enhanced Link"
                ." Attribution. (Legacy setting, only applied for "
                ."Universal Analytics properties. Will be removed in a future version.)",
            "Default" => true,
        ];

        $AlternateDomains = $GLOBALS["AF"]->getAlternateDomains();
        foreach ($AlternateDomains as $Domain) {
            $this->CfgSetup["TrackingId_".$Domain] = [
                "Type" => "Text",
                "Label" => "Tracking ID for ".$Domain,
                "Help" => "Google Analytics tracking ID for ".$Domain
                    .", if it is different than the default.",
                "Size" => 20,
                "Required" => false,
                "ValidateFunction" => function ($FieldName, $FieldValue) use ($Domain) {
                    if (strlen(trim($FieldValue)) > 0 &&
                        !self::validateGoogleAnalyticsTrackingId($FieldValue)) {
                        return "Invalid tracking ID for ".$Domain;
                    }
                    return null;
                },
            ];

            $this->CfgSetup["SiteVerificationCode_".$Domain] = [
                "Type" => "Text",
                "Label" => "Site Verification Code for ".$Domain,
                "Help" => "A verification code used by Google to confirm "
                    ."your ownership of ".$Domain.", if different from "
                    ."the default.",
                "Size" => 40,
                "MaxLength" => 100,
            ];
        }

        return null;
    }

    /**
     * Initialize the plugin.  This is called (if the plugin is enabled) after
     * all plugins have been loaded but before any methods for this plugin
     * (other than Register()) have been called.
     * @return string|null NULL if initialization was successful, otherwise a string containing
     *       an error message indicating why initialization failed.
     */
    public function initialize()
    {
        # if we do not have a GA tracking ID
        if ((strlen($this->configSetting("TrackingId") ?? "") == 0)
                && (strlen($this->configSetting("SiteVerificationCode") ?? "") == 0)) {
            # return error message about no tracking ID
            return "Either a Google Analytics tracking ID or a Google"
                    ." verification code has not been set.";
        }

        # report successful initialization
        return null;
    }

    /**
     * Hook methods to be called when specific events occur.
     * For events declared by other plugins the name string should start with
     * the plugin base (class) name followed by "::" and then the event name.
     * @return Array of method names to hook indexed by the event constants
     *       or names to hook them to.
     */
    public function hookEvents()
    {
        return ["EVENT_IN_HTML_HEADER" => "PrintTrackingCode"];
    }


    # ---- HOOKED METHODS ----------------------------------------------------

    /**
     * HOOKED METHOD: PrintTrackingCode
     * Write the code for Google Analytics tracking to output.
     */
    public function printTrackingCode()
    {
        $TrackingId = $this->getSettingForCurrentDomain("TrackingId");
        $SiteVerificationCode = $this->getSettingForCurrentDomain("SiteVerificationCode");

        $GlobalSiteTag = !preg_match("%^ua-%i", $TrackingId);

        if (strlen($TrackingId)) {
            // @phpcs:disable
            if ($GlobalSiteTag) {
                ?>
                <!-- Google tag (gtag.js) -->
                    <script async src="https://www.googletagmanager.com/gtag/js?id=<?= $TrackingId ?>"></script>
                    <script>
                    window.dataLayer = window.dataLayer || [];
                function gtag(){dataLayer.push(arguments);}
                    gtag('js', new Date());

                gtag('config', '<?= $TrackingId ?>');
                </script>
                <!-- end Google tag -->
                <?PHP
            } else {
                ?>
                <!-- Google Analytics (start) -->
                <script>
                    (function(i,s,o,g,r,a,m){i['GoogleAnalyticsObject']=r;i[r]=i[r]||function(){
                    (i[r].q=i[r].q||[]).push(arguments)},i[r].l=1*new Date();a=s.createElement(o),
                    m=s.getElementsByTagName(o)[0];a.async=1;a.src=g;m.parentNode.insertBefore(a,m)
                    })(window,document,'script','//www.google-analytics.com/analytics.js','ga');
                    ga('create', '<?= $TrackingId  ?>', 'auto');
                    <?PHP  if ($this->configSetting("EnhancedLinkAttribution")) {  ?>
                    ga('require', 'linkid', 'linkid.js');
                    <?PHP  }  ?>
                    ga('send', 'pageview');
                </script>
                <!-- Google Analytics (end) -->
                <?PHP
            }
            // @phpcs:enable
        }

        if (strlen($SiteVerificationCode)) {
            $GLOBALS["AF"]->addMetaTag(
                ["google-site-verification" => $SiteVerificationCode]
            );
        }
    }


    # ---- CALLABLE METHODS --------------------------------------------------

    /**
     * Validate a GA tracking id.
     * @param string $TrackingId Tracking ID to check.
     * @return bool TRUE for valid IDs, FALSE otherwise
     */
    public static function validateGoogleAnalyticsTrackingId(
        string $TrackingId
    ) : bool {
        # See https://developers.google.com/tag-platform/devguides/existing
        # for some notes about tag formats. Docs there are pretty loose about
        # what characters are allowed for 'X', so we've assumed any alphanumeric could
        # appear.
        return (bool)preg_match(
            '/^(ua-\d{4,10}(-\d{1,4})?|g-[a-z0-9]+|gt-[a-z0-9]+)$/i',
            $TrackingId
        );
    }
}
