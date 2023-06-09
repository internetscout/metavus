<?PHP
#
#   FILE:  CleanURLs.php
#
#   A plugin for the Metavus digital collections platform
#   Copyright 2013-2022 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins;

use Metavus\Classification;
use Metavus\ClassificationFactory;
use Metavus\Record;
use ScoutLib\ApplicationFramework;
use ScoutLib\Plugin;

/**
 * Plugin for adding and maintaining clean (human- and SEO-friendly) URLs.
 */
class CleanURLs extends Plugin
{

    # ---- STANDARD PLUGIN INTERFACE -----------------------------------------

    /**
     * Called by the PluginManager to register the plugin.
     */
    public function register()
    {
        $this->Name = "Clean URL Manager";
        $this->Version = "1.0.2";
        $this->Description = "Provides SEO-friendly clean URLs for resource"
                ." browsing and full record pages.";
        $this->Author = "Internet Scout Research Group";
        $this->Url = "https://metavus.net";
        $this->Email = "support@metavus.net";
        $this->Requires = ["MetavusCore" => "1.0.0"];
        $this->EnabledByDefault = true;
    }

    /**
     * Initialize the plugin.  This is called after all plugins have been
     * loaded but before any methods for this plugin (other than Register()
     * or Initialize()) have been called.
     * @return string|null NULL if initialization was successful, otherwise a string
     *       containing an error message indicating why initialization failed.
     */
    public function initialize()
    {
        # bail out if no clean URL support in .htaccess file
        $AF = ApplicationFramework::getInstance();
        if (!$AF->cleanUrlSupportAvailable()) {
            return "Clean URL support rewrites not available in .htaccess file.";
        }

        # set up default complex clean URLs
        $UrlMappings = [
            # full record pages
            [
                "Page" => "FullRecord",
                "Pattern" => "%^r([0-9]+)(/[^/]*)?$%i",
                "GetVars" => ["ID" => "\$1"],
                "Template" => "r\$ID",
                "UseCallback" => true
            ],
            # classification browse pages
            [
                "Page" => "BrowseResources",
                "Pattern" => "%^b([0-9]+)%i",
                "GetVars" => ["ID" => "\$1"],
                "Template" => "b\$ID",
                "UseCallback" => true
            ],
            # file download
            [
                "Page" => "DownloadFile",
                "Pattern" => "%^downloads/([0-9]+)/.*%",
                "GetVars" => ["ID" => "\$1"],
                "Template" => "downloads/\$ID"
            ],
            # image view
            [
                "Page" => "ViewImage",
                "Pattern" => "%^viewimage/([0-9]+)_([0-9]+)_([0-9]+)_([fpt])(\.[a-z]+)?%",
                "GetVars" => [
                    "RI" => "\$1",
                    "FI" => "\$2",
                    "IX" => "\$3",
                    "T"  => "\$4"
                ],
                "Template" => "viewimage/\$RI_\$FI_\$IX_\$T"
            ],
            # URL field click (with field specified)
            [
                "Page" => "GoTo",
                "Pattern" => "%^g([0-9]+)/f([0-9]+)$%i",
                "GetVars" => [
                    "ID" => "$1",
                    "MF" => "$2"
                ],
                "Template" => "g\$ID/f\$MF"
            ],
            # URL field click (with field not specified)
            [
                "Page" => "GoTo",
                "Pattern" => "%^g([0-9]+)$%i",
                "GetVars" => ["ID" => "$1"],
                "Template" => "g\$ID"
            ],
            # keyword search
            [
                "Page" => "AdvancedSearch",
                "Pattern" => "%^s=(.+)%i",
                "GetVars" => ["FK" => "\$1"],
                "Template" => "s=\$FK"
            ],
            # explicit background task execution
            [
                "Page" => "RunBackgroundTasks",
                "Pattern" => "%^runtasks\$%i",
                "GetVars" => ["FK" => "\$1"],
                "Template" => "s=\$FK"
            ],
        ];

        # for each complex clean URL
        foreach ($UrlMappings as $Mapping) {
            # set clean URL in application framework
            if (isset($Mapping["UseCallback"])) {
                $AF->addCleanUrlWithCallback(
                    $Mapping["Pattern"],
                    $Mapping["Page"],
                    $Mapping["GetVars"],
                    [$this, "ReplacementCallback"]
                );
            } else {
                $AF->addCleanUrl(
                    $Mapping["Pattern"],
                    $Mapping["Page"],
                    $Mapping["GetVars"],
                    $Mapping["Template"]
                );
            }
        }

        # add default simple clean URLs
        $AF->addSimpleCleanUrl("browse", "BrowseResources");
        $AF->addSimpleCleanUrl("home", "Home");

        # report successful initialization
        return null;
    }

    /**
     * See if the current page should be redirected to a Canonical CleanURL.
     * @param string $PageName The currently loading page.
     * @return array Page to load, which may be different.
     */
    public function checkForRedirect($PageName)
    {
        # only GET and HEAD requests can be automatically redirected
        #  by a 301, so only check those
        if ($_SERVER["REQUEST_METHOD"] == "GET" ||
            $_SERVER["REQUEST_METHOD"] == "HEAD") {
            # get the basepath-relateive URL if the current page
            $ThisUrl = str_replace(
                ApplicationFramework::basePath(),
                "",
                $_SERVER["REQUEST_URI"]
            );

            # and see if we had a corresponding CleanUrl
            $CleanUrl = (ApplicationFramework::getInstance()
                    )->getCleanRelativeUrlForPath($ThisUrl);

            # if this wasn't the clean url
            if ($ThisUrl != $CleanUrl) {
                # stash the tgt CleanUrl for the Redirect page
                global $H_CleanUrl;
                $H_CleanUrl = $CleanUrl;

                # and go to the redirect page
                return ["PageName" => "P_CleanURLs_Redirect"];
            }
        }

        return ["PageName" => $PageName];
    }

    /**
     * Set up plugin events.
     * @return array of events to hook.
     */
    public function hookEvents()
    {
        return ["EVENT_PAGE_LOAD" => "CheckForRedirect"];
    }

    # ---- CALLABLE METHODS --------------------------------------------------

    /**
     * Callback for constructing clean URLs to be inserted by the application
     * framework when more than regular expression replacement is required.
     * This method is passed to ApplicationFramework::addCleanURL().
     * @param array $Matches Array of matches from preg_replace().
     * @param string $Pattern Original pattern for clean URL mapping.
     * @param string $Page Page name for clean URL mapping.
     * @param string $SearchPattern Full pattern passed to preg_replace().
     * @return string Replacement to be inserted in place of match.
     */
    public function replacementCallback($Matches, $Pattern, $Page, $SearchPattern)
    {
        # default to returning match unchanged
        $Replacement = $Matches[0];

        switch ($Page) {
            case "FullRecord":
            case "BrowseResources":
                # if resource/classification ID found
                if (count($Matches) > 2) {
                    # if target is a resource
                    $Id = $Matches[2];
                    if ($Page == "BrowseResources") {
                        # set clean URL prefix
                        $Prefix = "b";

                        # if classification ID was valid
                        $CFactory = new ClassificationFactory();
                        if ($CFactory->itemExists($Id)) {
                            # set title to full classification name
                            $Classification = new Classification($Id);
                            $Title = $Classification->name();
                        }
                    } else {
                        # set clean URL prefix
                        $Prefix = "r";

                        # if resource ID was valid
                        if (Record::itemExists($Id)) {
                            # set title to resource title
                            $Resource = new Record($Id);
                            $Title = $Resource->getMapped("Title");
                        }
                    }

                    # set title for use in URL if found
                    $UrlTitle = isset($Title)
                            ? "/".strtolower(preg_replace(
                                [
                                    "% -- %",
                                    "%\\s+%",
                                    "%[^a-zA-Z0-9_-]+%"
                                ],
                                [
                                    "--",
                                    "_",
                                    ""
                                ],
                                trim($Title)
                            )) : "";

                    # assemble replacement
                    $Replacement = "href=\"".$Prefix.$Id.$UrlTitle."\"";
                }
                break;
        }
        return $Replacement;
    }
}
