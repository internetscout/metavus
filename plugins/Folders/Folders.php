<?PHP
#
#   FILE:  Folders.php
#
#   A plugin for the Metavus digital collections platform
#   Copyright 2018-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins;
use Exception;
use Metavus\FormUI;
use Metavus\FullRecordHelper;
use Metavus\Plugins\Folders\Common;
use Metavus\Plugins\Folders\Folder;
use Metavus\Plugins\Folders\FolderDisplayUI;
use Metavus\Plugins\Folders\FolderFactory;
use Metavus\PrivilegeSet;
use Metavus\Record;
use Metavus\User;
use Metavus\UserFactory;
use ScoutLib\ApplicationFramework;
use ScoutLib\Database;
use ScoutLib\Plugin;
use ScoutLib\PluginManager;
use ScoutLib\StdLib;

/**
 * Provides foldering functionality for resources.
 */
class Folders extends Plugin
{
    /**
     * Register information about this plugin.
     */
    public function register(): void
    {
        $this->Name = "Folders";
        $this->Version = "1.2.1";
        $this->Description = "Allows users to organize groups of items"
                ." using folders.";
        $this->Author = "Internet Scout Research Group";
        $this->Url = "https://metavus.net";
        $this->Email = "support@metavus.net";
        $this->Requires = [
            "MetavusCore" => "1.2.0"
        ];
        $this->InitializeAfter = ["DBCleanUp", "AccountPruner"];
        $this->EnabledByDefault = true;

        $this->CfgSetup["DisplayInSidebar"] = [
            "Type" => "Flag",
            "Label" => "Display In Sidebar",
            "Help" => "Whether to automatically add a display of the current"
                    ." folder to the sidebar.",
            "Default" => true,
        ];
        $this->CfgSetup["NumDisplayedResources"] = [
            "Type" => "Number",
            "Label" => "Number to Display",
            "Help" => "The number of resources to display in the sidebar for"
                     ." the selected folder",
            "MaxVal" => 20
        ];
        $this->CfgSetup["PrivsToTransferFolders"] = [
            "Type" => FormUI::FTYPE_PRIVILEGES,
            "AllowMultiple" => true,
            "Label" => "Privilege needed to transfer folders",
            "Help" => "Only users with any of the selected privilege flags "
                     ."will be able to perform a folder transfer.",
            "Default" => [
                PRIV_SYSADMIN,
                PRIV_NEWSADMIN,
                PRIV_RESOURCEADMIN,
                PRIV_CLASSADMIN,
                PRIV_NAMEADMIN,
                PRIV_RELEASEADMIN,
                PRIV_USERADMIN,
                PRIV_COLLECTIONADMIN
            ],
        ];
        $this->CfgSetup["PrivsToAddCoverImage"] = [
            "Type" => FormUI::FTYPE_PRIVILEGES,
            "AllowMultiple" => true,
            "Label" => "Privilege Needed for Adding Cover Images",
            "Help" => "Only users with at least one of the selected privilege flags "
                ."will be able to add cover images to folders.",
            "Default" => [
                PRIV_SYSADMIN,
                PRIV_NEWSADMIN,
                PRIV_RESOURCEADMIN,
                PRIV_CLASSADMIN,
                PRIV_NAMEADMIN,
                PRIV_RELEASEADMIN,
                PRIV_USERADMIN,
                PRIV_COLLECTIONADMIN
            ],
        ];
    }

    /**
     * Startup initialization for plugin.
     * @return NULL if initialization was successful, otherwise a string containing
     *       an error message indicating why initialization failed.
     */
    public function initialize(): ?string
    {
        # set up clean URL mapping for folders (folders/folder_id/normalized_folder_name)
        $AF = ApplicationFramework::getInstance();
        $AF->addCleanUrlWithCallback(
            "%^folders/0*([1-9][0-9]*)%",
            "P_Folders_ViewFolder",
            ["FolderId" => "\$1"],
            [$this, "CleanUrlTemplate"]
        );

        # add extra function dirs that we need
        # (must be added explicitly because our files get loaded
        #       on pages other than ours)
        $BaseName = $this->getBaseName();
        $DirsToAdd = [
            "plugins/".$BaseName."/interface/default/include/",
            "plugins/".$BaseName."/interface/%ACTIVEUI%/include/",
            "local/plugins/".$BaseName."/interface/default/include/",
            "local/plugins/".$BaseName."/interface/%ACTIVEUI%/include/",
        ];
        $AF->addFunctionDirectories($DirsToAdd, true);
        $AF->addIncludeDirectories($DirsToAdd, true);

        # if the user is logged in and may need the Folders javascript interface,
        # require the necessary files
        if (User::getCurrentUser()->isLoggedIn()) {
            $Files = ["Folders_Main.css", "jquery-ui.js", "Folders_Support.js",
                "Folders_Main.js"
            ];
            foreach ($Files as $File) {
                $AF->requireUIFile($File);
            }
        }

        # register our insertion keywords
        $AF->registerInsertionKeywordCallback(
            "P-FOLDERS-CURRENTFOLDERBOX",
            [$this, "getHtmlForCurrentFolderBox"]
        );

        # report success
        return null;
    }

    /**
     * Create the database tables necessary to use this plugin.
     * @return string|null on success or an error message otherwise
     */
    public function install(): ?string
    {
        $Result = $this->createTables(self::SQL_TABLES);
        if ($Result !== null) {
            return $Result;
        }

        $this->setConfigSetting("NumDisplayedResources", 5);

        return null;
    }

    /**
     * Uninstall the plugin.
     * @return NULL|string NULL if successful or an error message otherwise
     */
    public function uninstall(): ?string
    {
        return $this->dropTables(self::SQL_TABLES);
    }

    /**
     * Declare the events this plugin provides to the application framework.
     * @return array the events this plugin provides
     * Deprecated for new interfaces, please use the provided insertion points.
     */
    public function declareEvents(): array
    {
        return ["Folders_EVENT_INSERT_BUTTON_CHECK" => ApplicationFramework::EVENTTYPE_CHAIN];
    }

    /**
     * Hook the events into the application framework.
     * @return array the events to be hooked into the application framework
     */
    public function hookEvents(): array
    {
        $Events = [
            "EVENT_HTML_INSERTION_POINT" => [
                "insertButtonHTML",
                "insertAllButtonHTML",
                "insertResourceNote",
                "insertRemoveAllButtonHTML",
            ],
            "EVENT_PAGE_LOAD" => "addButtons",
            "EVENT_IN_HTML_HEADER" => "addJSHeader",
        ];

        # add hook for the Database Clean Up plugin if it's enabled
        $PluginMgr = PluginManager::getInstance();
        if ($PluginMgr->pluginEnabled("DBCleanUp")) {
            $Events["DBCleanUp_EXTERNAL_CLEAN"] = "DatabaseCleanUp";
        }

        # add hook for the Account Pruner plugin if it's enabled
        if ($PluginMgr->pluginEnabled("AccountPruner")) {
            $Events["AccountPruner_EVENT_DO_NOT_PRUNE_USER"] = "PreventAccountPruning";
        }

        return $Events;
    }

    /**
     * Get HTML for box display list of items in current folder.
     * @return string HTML for box.
     */
    public function getHtmlForCurrentFolderBox(): string
    {
        # do not display box if no user logged in
        if (!User::getCurrentUser()->isLoggedIn()) {
            return "";
        }

        ob_start();
        FolderDisplayUI::printFolderSidebarContent();
        return (string)ob_get_clean();
    }

    /**
     * Perform database cleanup operations when signaled by the DBCleanUp plugin.
     */
    public function databaseCleanUp(): void
    {
        $Database = new Database();

        # remove folder items in folders that no longer exist
        $Database->query("
            DELETE FII FROM FolderItemInts FII
            LEFT JOIN Folders F ON FII.FolderId = F.FolderId
            WHERE F.FolderId IS NULL");

        # remove selected folders for folders that no longer exist
        $Database->query("
            DELETE FSF FROM Folders_SelectedFolders FSF
            LEFT JOIN Folders F ON FSF.FolderId = F.FolderId
            WHERE F.FolderId IS NULL");

        # find folder items for resources that no longer exist
        $Database->query("
            SELECT FII.FolderId, FII.ItemId AS ResourceId FROM FolderItemInts FII
            LEFT JOIN Folders F ON FII.FolderId = F.FolderId
            LEFT JOIN Records R ON FII.ItemId = R.RecordId
            WHERE F.ContentType = '".intval(Folder::getItemTypeId("Resource"))."'
            AND R.RecordId IS NULL");

        # remove the resources from the folders they belong to
        while (false !== ($Row = $Database->fetchRow())) {
            $Folder = new Folder($Row["FolderId"]);
            $ResourceId = $Row["RecordId"];

            # mixed item type folder
            if ($Folder->containsItem($ResourceId, "Resource")) {
                $Folder->removeItem($ResourceId, "Resource");
            # single item type folder
            } else {
                $Folder->removeItem($ResourceId);
            }
        }
    }

    /**
     * Get the selected folder for the given owner.
     * @param mixed $Owner User object, user ID, or NULL to use the global user object.
     * @return Folder|null selected folder or NULL if it can't be retrieved.
     */
    public function getSelectedFolder($Owner = null): ?Folder
    {
        $Owner = $this->normalizeOwner($Owner);

        if ($Owner !== null && isset(self::$SelectedFolders[$Owner])) {
            return self::$SelectedFolders[$Owner];
        }

        $FolderFactory = new FolderFactory($Owner);
        $SelectedFolder = $FolderFactory->getSelectedFolder();

        if ($Owner !== null) {
            self::$SelectedFolders[$Owner] = $SelectedFolder;
        }
        return $SelectedFolder;
    }

    /**
     * Get the resource folder for the given owner.
     * @param mixed $Owner User object, user ID, or NULL to use the global user object.
     * @return Folder|null resource folder that contains folders of resources or
     *   NULL if it can't be retrieved.
     */
    public function getResourceFolder($Owner = null): ?Folder
    {
        $Owner = $this->normalizeOwner($Owner);
        $FolderFactory = new FolderFactory($Owner);

        $ResourceFolder = $FolderFactory->getResourceFolder();

        return $ResourceFolder;
    }

    /**
     * Insert the button "Add All to Folder" into HTML,
     * so that pages don't have to contain Folder-specific html.
     * @param string $PageName The name of the page that signaled the event.
     * @param string $Location Describes the location on the page where the
     *      insertion point occurs.
     * @param array $Context Specific info (e.g."ReturnTo" address) that are needed to
     *      generate HTML.
     *      Context must include:
     *        $Context["ReturnToString"] the return to string for the button;
     *        $Context["SearchParametersForUrl"] the search groups that the search results
     *          that the button adds to the folder;
     *        $Context["SortParamsForUrl"] the sorting parameters for the search results
     *          that the button adds to the folder;
     *        $Context["NumberSearchResults"] the number of results returned by the
     *          initial search.If this number is too large, the add to folder button
     *          is greyed out
     */
    public function insertAllButtonHTML($PageName, $Location, $Context = null): void
    {
        # retrieve user currently logged in
        $User = User::getCurrentUser();

        $IsLoggedIn = $User->isLoggedIn();

        # we need the user to be logged in, on page "SearchResults",
        # pressed a search results button,
        # and the context to be set in order to proceed
        if (!$IsLoggedIn || $PageName != "SearchResults" ||
            $Location != "Search Results Buttons" || !isset($Context)) {
               return;
        }

        $MaxResourcesPerAdd = PHP_INT_MAX;
        $ReturnToString = $Context["ReturnToString"];
        $SearchParameters = $Context["SearchParameters"];
        $SortParamsForUrl = $Context["SortParamsForUrl"];
        $NumberSearchResults = $Context["NumberSearchResults"];

        if (is_array($NumberSearchResults)) {
            $NewNumberSearchResults = 0;
            foreach ($NumberSearchResults as $ResultCount) {
                $NewNumberSearchResults += $ResultCount;
            }
            $NumberSearchResults = $NewNumberSearchResults;
        }

        $SearchParametersForUrl = $SearchParameters->UrlParameterString();

        # we only proceed if there are search results present
        if (!$Context["NumberSearchResults"]) {
            return;
        }

        $TooManySearchResults = false;

        if ($NumberSearchResults > $MaxResourcesPerAdd) {
            $TooManySearchResults = true;
        }

        $AddAllURL = "index.php?P=P_Folders_AddSearchResults&RF=1";

        if ($SearchParametersForUrl) {
            $AddAllURL = $AddAllURL."&".$SearchParametersForUrl;
        }

        // add item type
        if (isset($Context["ItemType"])) {
            $AddAllURL = $AddAllURL."&ItemType=".$Context["ItemType"];
        }

        $AddAllURL = $AddAllURL."&ReturnTo=".$ReturnToString;

        # call out to the external display function to hand off processing
        FolderDisplayUI::insertAllButtonHTML(
            $TooManySearchResults,
            $MaxResourcesPerAdd,
            $AddAllURL
        );
    }

    /**
     * Insert the button "Remove All From Folder" to HTML
     * @param string $PageName Name of page that signaled the event
     * @param string $Location Location on page where insertion point occurs
     * @param array $Context Specific info (e.g."ReturnTo" address) that are needed to
     *      generate HTML.
     *      Context must include:
     *        $Context["ReturnToString"] the return to string for the button;
     *        $Context["SearchParametersForUrl"] the search parameters that
     *              yield the search results to remove from the folder;
     *        $Context["NumberSearchResults"] the number of search results
     *              returned by search parameters, used to determine if
     *              there's anything to remove.
     */
    public function insertRemoveAllButtonHTML(
        string $PageName,
        string $Location,
        ?array $Context = null
    ): void {
        $IsLoggedIn = User::getCurrentUser()->isLoggedIn();

        # If user is logged in, on page "SearchResults", press a search results button,
        # and context is set and contains the number of search results
        if (!$IsLoggedIn || ($PageName != "SearchResults") ||
            ($Location != "Search Results Buttons") || (!isset($Context)) ||
            (!isset($Context["NumberSearchResults"]))) {
                return;
        }

        $ReturnToString = $Context["ReturnToString"];
        $SearchParametersForUrl = $Context["SearchParameters"]->UrlParameterString();
        $RemoveAllURL = "index.php?P=P_Folders_RemoveSearchResults&RF=1";

        if ($SearchParametersForUrl) {
            $RemoveAllURL .= "&".$SearchParametersForUrl;
        }

        $RemoveAllURL .= "&ReturnTo=".$ReturnToString;

        # assume none of the search results are in the folder
        $ResultsInFolder = false;
        if (array_key_exists("SearchResults", $Context)) {
            foreach ($Context["SearchResults"] as $Result) {
                if ($this->getSelectedFolder()->containsItem($Result->id())) {
                    $ResultsInFolder = true;
                    break;
                }
            }
        }

        # call out to the external display function to hand off processing
        FolderDisplayUI::insertRemoveAllButtonHTML($RemoveAllURL, $ResultsInFolder);
    }

    /**
     * Prevent accounts of users from removal if they meet one of the following
     * conditions:
     * @li The user has created a new folder or modified the
     *      automatically-created one.
     * @li The user has added at least one resource to at least one folder.
     * @param int $UserId User identifier.
     * @return bool Returns TRUE if the user account shouldn't be removed and FALSE if
     *      the account can be removed as far as the Folders plugin is concerned.
     */
    public function preventAccountPruning($UserId): bool
    {
        $UserId = intval($UserId);
        $Database = new Database();

        # query for the number of folders for the user that are not the
        # automatically-created folder or are that folder but it has been
        # changed
        $NumFolders = $Database->queryValue("
            SELECT COUNT(*) AS NumFolders
            FROM Folders
            WHERE OwnerId = ".$UserId."
            AND FolderName != 'ResourceFolderRoot'
            AND FolderName != 'Main Folder'", "NumFolders");

        # the user has manually created a folder or has modified the name of the
        # automatically-created one and shouldn't be removed
        if ($NumFolders > 0) {
            return true;
        }

        $ResourceItemTypeId = Folder::getItemTypeId("Resource");

        # query for the number of resources the user has put into folders
        $NumResources = $Database->queryValue("
            SELECT COUNT(*) AS NumResources
            FROM Folders F
            LEFT JOIN FolderItemInts FII on F.FolderId = FII.Folderid
            WHERE FII.FolderId IS NOT NULL
            AND F.OwnerId = ".$UserId."
            AND F.ContentType = ".$ResourceItemTypeId, "NumResources");

        # the user has put at least one resource into a folder and shouldn't be
        # removed
        if ($NumResources > 0) {
            return true;
        }

        # don't determine whether or not the user should be removed
        return false;
    }

    /**
     * Callback for constructing clean URLs to be inserted by the application
     * framework when more than regular expression replacement is required.
     * This method is passed to ApplicationFramework::AddCleanURL().
     * @param array $Matches Array of matches from preg_replace().
     * @param string $Pattern Original pattern for clean URL mapping.
     * @param string $Page Page name for clean URL mapping.
     * @param string $SearchPattern Full pattern passed to preg_replace().
     * @return string Replacement to be inserted in place of match.
     */
    public function cleanUrlTemplate($Matches, $Pattern, $Page, $SearchPattern): string
    {
        if ($Page == "P_Folders_ViewFolder") {
            # if no ID found
            if (count($Matches) <= 2) {
                # return match unchanged
                return $Matches[0];
            }

            $FolderId = (int)$Matches[2];
            if (!Folder::itemExists($FolderId)) {
                return $Matches[0];
            }

            # get the URL for the folder
            $Url = Common::getShareUrl(new Folder($FolderId));

            return "href=\"".defaulthtmlentities($Url)."\"";
        }

        # return match unchanged
        return $Matches[0];
    }

    /**
     * Generates "add" and "remove" urls for the current folder buttons for a resource
     * and passes them to an external display function.
     * @param string $PageName The name of the page that signaled the event.
     * @param string $Location Describes the location on the page where the
     *      insertion point occurs.
     * @param array $Context Specific info (e.g."ReturnTo" address) that are needed to
     *      generate HTML.
     *      For the buttons to work, $Context must be set and include:
     *         $Context["Resource"], the resource for which we are
     *         printing the button
     */
    public function insertButtonHTML($PageName, $Location, $Context = null): void
    {
        $DisplayLocations = [
            "Resource Summary Buttons",
            "Buttons Top",
            "Resource Display Buttons",
        ];

        if (is_null($Context) || !in_array($Location, $DisplayLocations)) {
            return;
        }

        if (!User::getCurrentUser()->isLoggedIn() || !isset($Context["Resource"])) {
            return;
        }

        $AF = ApplicationFramework::getInstance();
        $Result = $AF->signalEvent(
            "Folders_EVENT_INSERT_BUTTON_CHECK",
            [
                "Resource" => $Context["Resource"],
                "ShouldInsert" => true,
            ]
        );

        if (!$Result["ShouldInsert"]) {
            return;
        }

        $ResourceId = $Context["Resource"]->Id();
        $Folder = $this->getSelectedFolder();
        $FolderId = $Folder->id();

        $ReturnToString = urlencode($AF->getCleanRelativeUrl());
        $InFolder = $Folder->containsItem($ResourceId);
        $RemoveActionURL = ApplicationFramework::baseUrl()
            ."index.php?P=P_Folders_RemoveItem&FolderId="
            .urlencode((string)$FolderId)."&ItemId="
            .urlencode($ResourceId)."&ReturnTo=".$ReturnToString;
        $AddActionURL = ApplicationFramework::baseUrl()
            ."index.php?P=P_Folders_AddItem&ItemId="
            .urlencode($ResourceId)."&FolderId="
            .urlencode((string)$FolderId)."&ReturnTo=".$ReturnToString;

        # call out to the external display function to hand off processing
        FolderDisplayUI::insertButtonHTML(
            $InFolder,
            $AddActionURL,
            $RemoveActionURL,
            $FolderId,
            $ResourceId,
            $Location
        );
    }

    /**
     * Retrieves folder note for a particular resource when called if there
     * is a note and inserts it via an external function call.
     * For the note to work, $Context must be set and include $Context["ResourceId"],
     * the ID of the resource for which we are printing the note.
     * @param string $PageName The name of the page that signaled the event.
     * @param string $Location Describes the location on the page where the
     *      insertion point occurs.
     * @param array $Context Specific info (e.g."ReturnTo" address) that are needed to
     *      generate HTML.
     */
    public function insertResourceNote($PageName, $Location, $Context = null): void
    {
        # only display when we have Context, are on ViewFolder, and are
        # located After Resource Description
        if (is_null($Context) || $PageName != "P_Folders_ViewFolder" ||
            $Location != "After Resource Description") {
            return;
        }

        $PublicFolder = false;
        if (array_key_exists("FolderId", $Context)) {
            $Folder = new Folder($Context["FolderId"]);
            $PublicFolder = $Folder->isShared();
        }

        # if no user is logged in and this folder is not public, bail
        if (!User::getCurrentUser()->isLoggedIn() && !$PublicFolder) {
            return;
        }

        $ResourceId = $Context["ResourceId"];
        $Folder = isset($Folder) ? $Folder : $this->getSelectedFolder();

        # if the resource given in our context is not in our folder, bail
        if (!$Folder->containsItem($ResourceId)) {
            return;
        }

        # otherwise, get the note for this item
        $ResourceNote = $Folder->noteForItem($ResourceId);
        $ReturnToString = urlencode((ApplicationFramework::getInstance())->getCleanRelativeUrl());
        $EditResourceNoteURL = "index.php?P=P_Folders_ChangeResourceNote&FolderId="
            . $Folder->id() . "&ItemId=" . $ResourceId . "&ReturnTo="
            . $ReturnToString;

        # call out to the external display function
        FolderDisplayUI::insertResourceNote(
            $ResourceNote,
            $Folder->id(),
            $ResourceId,
            $EditResourceNoteURL
        );
    }

    /**
     * Add Javascript globals to page header (hooked to EVENT_IN_HTML_HEADER).
     */
    public function addJSHeader(): void
    {
        if (!User::getCurrentUser()->isLoggedIn()) {
            return;
        }

        $AF = ApplicationFramework::getInstance();

        ?>
        <script type="text/javascript">
        var Folders_AddIcon = "<?= ApplicationFramework::baseUrl()
            .$AF->gUIFile("FolderPlus.svg") ?>";
        var Folders_RemoveIcon = "<?= ApplicationFramework::baseUrl()
            .$AF->gUIFile("FolderMinus.svg") ?>";
        </script>
        <?PHP
    }

    /**
     * Called on page load, to add our buttons.
     * @param string $PageName The name of the page that signaled the event.
     */
    public function addButtons(string $PageName): void
    {
        # bail if we're not on full record page or PhotoLibrary's photo display page
        if ($PageName != "FullRecord" && $PageName != "P_PhotoLibrary_DisplayPhoto") {
            return;
        }

        # bail if no user logged in
        if (!User::getCurrentUser()->isLoggedIn()) {
            return;
        }

        # get the RecordId
        $RecordId = $_GET["ID"];

        # if it was invalid, bail
        if (!Record::itemExists($RecordId)) {
            return;
        }

        FullRecordHelper::setRecord(
            new Record($RecordId)
        );

        $Folder = $this->getSelectedFolder();

        if ($Folder->containsItem($RecordId)) {
            $Verb = "Remove";
            $IconName = "FolderMinus";
            $Title = "Remove this resource from the currently selcted folder.";
        } else {
            $Verb = "Add";
            $IconName = "FolderPlus";
            $Title = "Add this resource to the currently selected folder.";
        }

        $AF = ApplicationFramework::getInstance();

        $ReturnToString = urlencode($AF->getCleanRelativeUrl());

        $Attributes = [
            "data-itemid" => $RecordId,
            "data-folderid" => $Folder->id(),
            "data-verb" => $Verb,
        ];

        $Link = ApplicationFramework::baseUrl()
            .'index.php?P=P_Folders_'.$Verb."Item"
            .'&ItemId=$ID'
            .'&FolderId='.urlencode((string)$Folder->id())
            .'&ReturnTo='.$ReturnToString;

        FullRecordHelper::addButtonForPage(
            $Verb,
            htmlspecialchars($Link),
            $Title,
            $IconName,
            "mv-folders-".strtolower($Verb)."resource",
            $Attributes
        );
    }

    /* HELPER FUNCTIONS FOR ITEM MANIPULATION */

    /**
     * Takes a ReturnTo array that's set by the calling page and strips old Folders
     * errors from the URL so they don't pile up.
     * @param array $ReturnTo The array set based on how the calling page was reached.
     * @return array the ReturnTo array passed in with the old
     *     folders errors removed.
     */
    public static function clearPreviousFoldersErrors($ReturnTo): array
    {
        $ReturnQuery = [];
        if (array_key_exists('query', $ReturnTo)) {
            parse_str($ReturnTo['query'], $ReturnQuery);

            foreach ($ReturnQuery as $key => $value) {
                if (is_string($value) && "E_FOLDERS" == substr($value, 0, 9)) {
                    unset($ReturnQuery[$key]);
                }
            }

            $ReturnTo['query'] = http_build_query($ReturnQuery);
        }

        return $ReturnTo;
    }

    /**
     * Print JSON using given information in JsonHelper format, replaces use of JsonHelper
     * @param string $State to display, "OK" for success, "ERROR" for error
     * @param string $Message (optional) to display
     */
    private static function printJson(string $State, string $Message = ""): void
    {
        $JsonArray = [
            "data" => [],
            "status" => [
                "state" => $State,
                "message" => $Message,
                "numWarnings" => 0,
                "warnings" => []
            ]
        ];
        print(json_encode($JsonArray));
    }

    /**
     * Executes the page's result response based on how the page was reached,
     * delivering success/error via a print(json_encode) (reached with AJAX)
     * or via the standard jump pages (not reached with AJAX)
     * @param array $Errors Error codes incurred that are relevant to
     *      processing, it is empty on success.
     */
    public static function processPageResponse($Errors): void
    {
        $AF = ApplicationFramework::getInstance();
        if (ApplicationFramework::reachedViaAjax()) {
            $AF->beginAjaxResponse();

            # take success as an empty $Errors array
            if (empty($Errors)) {
                self::printJson("OK");
            # else there was a failure and we see it in the error message
            } else {
                $ErrorMessages = [];

                # get the messages for each error we incurred; if we get an array key
                # not found it's a desired failure -- developers are responsible
                # for defining error messages for Folders within the plugin
                foreach ($Errors as $Error) {
                    array_push($ErrorMessages, self::$ErrorsMaster[$Error]);
                }

                self::printJson("ERROR", implode(', ', $ErrorMessages));
            }
        } else {
            # Set up the response return address:
            # if "ReturnTo" is set, then we should return to that address
            if (StdLib::getArrayValue($_GET, "ReturnTo", false)) {
                $ReturnTo = parse_url($_GET['ReturnTo']);
            # else we should return to the page which this page is being directed from
            } elseif (isset($_SERVER["HTTP_REFERER"])) {
                $ReturnTo = parse_url($_SERVER["HTTP_REFERER"]);
            # return to ManageFolders page if nothing is set
            } else {
                $ReturnTo = parse_url(
                    $AF->getCleanRelativeUrlForPath(
                        "index.php?P=P_Folders_ManageFolders"
                    )
                );
            }
            if ($ReturnTo === false) {
                throw new Exception("Parsing return URL failed.");
            }

            # clear out any previous folder errors in the params
            $ReturnTo = self::clearPreviousFoldersErrors($ReturnTo);

            # Process success or failure:
            # take success as an empty $Errors array
            if (empty($Errors)) {
                if (array_key_exists('query', $ReturnTo)) {
                    $JumpToPage = $ReturnTo['path']."?".$ReturnTo['query'];
                } else {
                    $JumpToPage = $ReturnTo['path'];
                }
                $AF->setJumpToPage($JumpToPage);
            # else there was a failure and we see it in the error message
            } else {
                # we expect the messages to be retrieved on the page if we are
                # not using Ajax, as the messages are too cumbersome for URL params
                $ErrorsForUrl = [];
                $Count = 0;

                foreach ($Errors as $Error) {
                    $ErrorContainer = [];
                    $Index = 'ER'.$Count;
                    $ErrorsForUrl[$Index] = $Error;
                    $Count++;
                }

                $JumpToPage = $ReturnTo['path']."?".$ReturnTo['query']."&".
                      http_build_query($ErrorsForUrl);
                $AF->setJumpToPage($JumpToPage);
            }
        }
    }

    # define the error messages we use throughout the Folders plugin
    public static $ErrorsMaster = [
        "E_FOLDERS_NOSUCHITEM" => "That is not a valid item id.",
        "E_FOLDERS_RESOURCENOTFOUND" => "Resource not found.",
        "E_FOLDERS_NOSUCHFOLDER" => "Not a valid folder.",
        "E_FOLDERS_NOTFOLDEROWNER" => "You do not own the selected folder."
    ];

    /**
     * Convert passed in user to id form, or return null if no user found/user in invalid
     * @param int|null|User $Owner representing a owner's id or user object
     * @return int|null id of user if possible, null if no user found
     */
    private function normalizeOwner($Owner = null): ?int
    {
        # retrieve user currently logged in
        $User = User::getCurrentUser();

        if (is_object($Owner) && method_exists($Owner, "id")) {
            return $Owner->id();
        }
        if (is_int($Owner) && (new UserFactory())->userExists($Owner)) {
            return $Owner;
        }
        if ($User->isLoggedIn()) {
            return $User->id();
        }
        (ApplicationFramework::getInstance())->logMessage(
            ApplicationFramework::LOGLVL_WARNING,
            "User id not found in attempt to get user id at ".StdLib::getMyCaller()
        );
        return null;
    }

    private static $SelectedFolders = [];

    public const SQL_TABLES = [
        "SelectedFolders" => "CREATE TABLE Folders_SelectedFolders (
                OwnerId      INT,
                FolderId     INT,
                PRIMARY KEY  (OwnerId)
            )",
    ];
}
