<?PHP
#
#   FILE:  MySearches.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2002-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins;
use Exception;
use Metavus\Plugins\MetricsRecorder;
use Metavus\Plugins\MySearches\MySearchesUI;
use Metavus\SavedSearchFactory;
use Metavus\SearchParameterSet;
use Metavus\User;
use ScoutLib\Plugin;
use ScoutLib\ApplicationFramework;
use ScoutLib\PluginManager;

/**
 * Displays data for the UI about a user's saved searches and recent searches.
 */
class MySearches extends Plugin
{
    # ---- STANDARD PLUGIN INTERFACE -----------------------------------------

    /**
     * Register information about this plugin.
     */
    public function register(): void
    {
        $this->Name = "My Searches";
        $this->Version = "1.1.0";
        $this->Description = "Provides data for the UI via events"
                ." about a user's saved searches and recent searches.";
        $this->Author = "Internet Scout Research Group";
        $this->Url = "https://metavus.net";
        $this->Email = "support@metavus.net";
        $this->Requires = [
            "MetavusCore" => "1.2.0",
            "MetricsRecorder" => "1.2.6",
        ];
        $this->EnabledByDefault = true;

        $this->CfgSetup["RecentSearches"] = [
            "Type" => "Flag",
            "Label" => "Show Recent Searches",
            "Default" => "Yes",
            "Help" => "Display recent searches information.",
            "OnLabel" => "Yes",
            "OffLabel" => "No",
        ];

        $this->CfgSetup["MySearches"] = [
            "Type" => "Flag",
            "Label" => "Show My Searches",
            "Default" => "Yes",
            "Help" => "Display users' searches information.",
            "OnLabel" => "Yes",
            "OffLabel" => "No",
        ];
    }

    /**
     * Initialize default settings.
     */
    public function initialize(): ?string
    {
        $AF = ApplicationFramework::getInstance();

        # add extra function dirs that we need
        # these are listed in reverse order because each will be added to the
        # beginning of the search list
        $BaseName = $this->getBaseName();
        $AF->addFunctionDirectories([
            "local/plugins/".$BaseName."/interface/%ACTIVEUI%/include/",
            "plugins/".$BaseName."/interface/%ACTIVEUI%/include/",
            "local/plugins/".$BaseName."/interface/default/include/",
            "plugins/".$BaseName."/interface/default/include/",
        ]);

        # register insertion keywords for our output
        $AF->registerInsertionKeywordCallback(
            "P-MYSEARCHES-SAVEDSEARCHBOX",
            [$this, "getHtmlForSavedSearchesBox"]
        );
        $AF->registerInsertionKeywordCallback(
            "P-MYSEARCHES-RECENTSEARCHBOX",
            [$this, "getHtmlForRecentSearchesBox"]
        );

        return null;
    }


    # ---- CALLABLE METHODS --------------------------------------------------

    /**
     * Get HTML for saved search list block if available and enabled.
     * @return string Generated HTML.
     */
    public function getHtmlForSavedSearchesBox(): string
    {
        # retrieve user currently logged in
        $User = User::getCurrentUser();

        # bail if display of saved searches is disabled or user is not logged in
        if (($this->getConfigSetting("MySearches") != "Yes") || !$User->isLoggedIn()) {
            return "";
        }

        $Searches = (new SavedSearchFactory())->getSearchesForUser(
            $User->id()
        );
        if (count($Searches) == 0) {
            return "";
        }

        $SearchesForDisplay = [];
        foreach ($Searches as $Search) {
            try {
                $SearchParams = $Search->SearchParameters();
                $SearchesForDisplay[] = [
                    "SearchURL" => "index.php?P=SearchResults&amp;"
                    .$SearchParams->UrlParameterString(),
                    "SearchTitle" => $SearchParams->TextDescription(
                        true,
                        false,
                        30
                    ),
                    "SearchName" => $Search->SearchName(),
                ];
            } catch (Exception $e) {
                ; # continue on if search data was not valid
            }
        }
        return MySearchesUI::getHtmlForSavedSearchesBlock($SearchesForDisplay);
    }

    /**
     * Get HTML for recent search list block if available and enabled.
     * @return string Generated HTML.
     */
    public function getHtmlForRecentSearchesBox(): string
    {
        $PluginManager = PluginManager::getInstance();

        # retrieve user currently logged in
        $User = User::getCurrentUser();

        # bail if display of recent searches is disabled or user is not logged in
        if (($this->getConfigSetting("RecentSearches") != "Yes") || !$User->isLoggedIn()) {
            return "";
        }

        # grab the recent searches for this user
        $MetricsRecorderPlugin = MetricsRecorder::getInstance();
        $SearchEventTypes = [
            MetricsRecorder::ET_SEARCH,
            MetricsRecorder::ET_ADVANCEDSEARCH
        ];
        $SearchEvents = $MetricsRecorderPlugin->getEventData(
            "MetricsRecorder",
            $SearchEventTypes,
            null,
            null,
            $User->id()
        );

        # bail if no searches were found for this user
        if (!count($SearchEvents)) {
            return "";
        }

        # flip them to be in reverse-date order
        $SearchEvents = array_reverse($SearchEvents);

        # generate a list of searches we should show
        $SearchesForDisplay = [];
        $DisplayedSearches = [];

        # iterate over our events
        foreach ($SearchEvents as $Search) {
            # extract the search data, generate a key for it
            $SearchData = $Search["DataOne"];
            $SearchKey = md5($SearchData);

            # if we haven't already displayed this search
            if (!isset($DisplayedSearches[$SearchKey])) {
                # attempt to get the search parameters out
                try {
                    $SearchParams = new SearchParameterSet($SearchData);

                    # mark this search as displayed, add it to our list
                    $DisplayedSearches[$SearchKey] = true;
                    $SearchesForDisplay[] = [
                        "SearchURL" => "index.php?P=SearchResults&amp;"
                                    .$SearchParams->urlParameterString(),
                        "SearchName" => "\n".$SearchParams->textDescription(true, false, 30)
                    ];
                } catch (Exception $e) {
                    ; # continue on if search data was invalid
                }
            }

            # exit the loop if we've already got enough searches
            if (count($SearchesForDisplay) > 5) {
                break;
            }
        }

        return MySearchesUI::getHtmlForRecentSearchesBlock($SearchesForDisplay);
    }
}
