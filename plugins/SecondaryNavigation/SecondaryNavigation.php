<?PHP
#
#   FILE:  SecondaryNavigation.php
#
#   A plugin for the Metavus digital collections platform
#   Copyright 2020-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins;
use Exception;
use InvalidArgumentException;
use Metavus\HtmlButton;
use Metavus\Plugins\SecondaryNavigation\NavItem;
use Metavus\Plugins\SecondaryNavigation\NavMenu;
use Metavus\PrivilegeFactory;
use Metavus\PrivilegeSet;
use Metavus\SecureLoginHelper;
use Metavus\User;
use ScoutLib\Plugin;
use ScoutLib\ApplicationFramework;

/**
 * Plugin to enable editing of the secondary navigation menu
 */
class SecondaryNavigation extends Plugin
{
    /**
     * Set plugin attributes.
     */
    public function register(): void
    {
        $this->Name = "SecondaryNavigation";
        $this->Version = "1.0.2";
        $this->Description = "Make secondary navigation menu editable for each user.";
        $this->Author = "Internet Scout Research Group";
        $this->Url = "https://metavus.net";
        $this->Email = "support@metavus.net";
        $this->Requires = ["MetavusCore" => "1.2.0"];
        $this->EnabledByDefault = true;
    }

    /**
     * Set up keyword for inserting nav menu
     * @return string|null error string or null on success
     */
    public function initialize(): ?string
    {
        $AF = ApplicationFramework::getInstance();
        $AF->registerInsertionKeywordCallback(
            "P-SECONDARYNAVIGATION-DISPLAYMENU",
            [$this, "getSidebarContent"]
        );

        return null;
    }

    /**
     * Create the database tables necessary to use this plugin.
     * @return string|null NULL on success or an error message otherwise
     */
    public function install(): ?string
    {
        # set up database tables
        return $this->createTables($this->SqlTables);
    }

    /**
     * Uninstall the plugin.
     * @return null|string NULL if successful or an error message otherwise
     */
    public function uninstall(): ?string
    {
        # remove tables from database
        return $this->dropTables($this->SqlTables);
    }

    /**
     * Set up configuration options.
     * @return null|string NULL if successful or an error message otherwise
     */
    public function setUpConfigOptions(): ?string
    {
        # normalize OfferedNavItems for option list
        $this->CfgSetup["DefaultNavigation"] = [
            "Type" => "Paragraph",
            "Label" => "Default Navigation Items",
            "Help" => "Items to be added to applicable users' Secondary"
                    ." Navigation menus by default.Each line should contain"
                    ." one link that identically matches a link from the list"
                    ." of offered navigation items (Ex.index.php?P=SysAdmin).",
            "Height" => 10,
            "ValidateFunction" => function ($FieldName, $FieldValue) {
                if (trim($FieldValue) == "") {
                    return null;
                }
                $Links = explode("\n", $FieldValue);
                $OfferedItems = $this->getOfferedNavItems();
                $InvalidLinks = [];
                foreach ($Links as $Link) {
                    if (!isset($OfferedItems[trim($Link)])) {
                        $InvalidLinks[] = trim($Link);
                    }
                }
                return (count($InvalidLinks) ? "Link(s) not found in offered "
                        ."navigation item list: ".implode(", ", $InvalidLinks) : null);
            },
            "Default" => "index.php?P=SysAdmin\nindex.php?P=UserList\nindex.php?P=MDHome"
        ];
        $this->CfgSetup["AdditionalNavItems"] = [
            "Type" => "Paragraph",
            "Label" => "Additional Offered Navigation Items",
            "Help" => "Add offered links in the format 'label|link|"
                ."privileges(constants separated by commas, optional)|description(optional)'."
                ."For example: 'Administration|index.php?P=SysAdmin|PRIV_SYSADMIN,"
                ."PRIV_COLLECTIONADMIN,PRIV_USERADMIN|View and change system settings.'",
            "Height" => 10,
            "ValidateFunction" => function ($FieldName, $FieldValue) {
                if (trim($FieldValue) == "") {
                    return null;
                }
                $AdditionalItems = $this->parseAdditionalNavItems($FieldValue);
                foreach ($AdditionalItems as $Item) {
                    if ($Item["Label"] == "") {
                        return "Each item requires a label.";
                    }
                    if ($Item["URL"] == "") {
                        return "Each item requires a link.";
                    }
                    if (!(self::urlLooksValid($Item["URL"]) ||
                        file_exists("pages/".$Item["URL"].".php") ||
                        file_exists("local/pages/".$Item["URL"].".php"))) {
                        return defaulthtmlentities($Item["URL"])
                        ." is not a valid relative or absolute URL.";
                    }
                    if (!is_array($Item["Privs"])) {
                        return "One or more privilege constants are invalid.";
                    }
                }
                return null;
            },
            "Default" => ""
        ];
        return null;
    }

    /**
     * Set NavItems for user when no NavMenu exists (depending on privileges)
     * @param int $OwnerId ID of user to create NavItems for
     */
    public function setDefaultLinks(int $OwnerId): void
    {
        $User = new User($OwnerId);
        $ToOrder = [];

        # Add filler NavItem to menu to stop from re-generating when emptied by user
        # this will never be seen by the user since we use getItemIdsInOrder to retrieve items
        NavItem::create($OwnerId, "Filler Item", "");

        # create NavItems for default links (if user has privileges)
        $DefaultItems = strlen($this->getConfigSetting("DefaultNavigation")) ?
            explode("\n", $this->getConfigSetting("DefaultNavigation")) : [];
        $OfferedItems = $this->getOfferedNavItems();
        foreach ($DefaultItems as $Link) {
            $NewItem = $OfferedItems[trim($Link)];
            if ($NewItem["Privs"]->meetsRequirements($User)) {
                $ToOrder[] = NavItem::create($OwnerId, $NewItem["Label"], trim($Link), false);
            }
        }

        # add NavItems to the order
        $NavMenu = new NavMenu($OwnerId);
        foreach ($ToOrder as $NavItem) {
            $NavMenu->append($NavItem);
        }
    }

    /**
     * Get HTML for SecondaryNavigation menu in sidebar
     * @return string HTML for navigation menu with links for user if logged in
     */
    public function getSidebarContent(): string
    {
        $UseSecureLogin = isset($_SERVER["HTTPS"]) ? false : true;
        $PubKeyParams = SecureLoginHelper::getCryptKey();

        # retrieve user currently logged in
        $User = User::getCurrentUser();

        ob_start();
        ?>
            <div class="bg-secondary text-white" id="mv-secondary-navigation-menu">
                <?PHP if ($User->isLoggedIn()) { ?>
                <!-- BEGIN MENU AREA -->
                <div class="col-12">
                    <div class="row">
                        You are logged in.
                    </div>
                    <div class="row">
                        Welcome, <?= $User->get("UserName") ?>
                    </div>
                    <div class="row">
                        <div class="col-12">
                            <?PHP $this->displayNavItems(); ?>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-9">
                            <?PHP
                                $this->displayNavItem("Preferences", "index.php?P=Preferences");
                                $this->displayNavItem("Log Out", "index.php?P=UserLogout");
                            ?>
                        </div>
                        <div class="col-3">
                            <?PHP $this->displayNavEditButtons(); ?>
                        </div>
                    </div>
                </div>
                <!-- END MENU AREA -->

                <?PHP } else { ?>
                    (not logged in)
                <?PHP  } ?>
            </div>
        <?PHP
        $HTML = ob_get_clean();
        return (is_string($HTML)) ? $HTML : "";
    }

    /**
     * Get NavMenu for user and display all NavItems in order
     */
    public function displayNavItems(): void
    {
        # retrieve user currently logged in
        $User = User::getCurrentUser();

        if (!NavMenu::userNavMenuExists($User->id())) {
            $this->setDefaultLinks($User->id());
        }
        $NavMenu = new NavMenu($User->id());
        $ItemIds = $NavMenu->getItemIdsInOrder();
        foreach ($ItemIds as $ItemId) {
            $NavItem = new NavItem($ItemId);
            # if this is an offered link that the user doesn't have privs for
            if (isset($this->OfferedItems[$NavItem->link()]["Privs"]) &&
                !$this->OfferedItems[$NavItem->link()]["Privs"]->meetsRequirements($User)) {
                # delete this NavItem
                $NavMenu->removeItemFromOrder($NavItem->id());
                $NavItem->destroy();
                continue;
            }
            $this->displayNavItem($NavItem->label(), $NavItem->link());
        }
    }

    /**
     * Display a single NavItem
     * @param string $Label text for link to be displayed
     * @param string $Link url or relative link to direct to
     */
    public function displayNavItem($Label, $Link): void
    {
        ?>
        <div class="mv-secondary-navigation-menu-item" title="<?= htmlspecialchars($Label) ?>"
            ><a class="text-light" href="<?= $Link ?>"
                > <?= $Label ?></a>
        </div>
        <?PHP
    }

    /**
     * Display Add/Edit buttons
     */
    public function displayNavEditButtons(): void
    {
        $AF = ApplicationFramework::getInstance();
        $NavMenu = new NavMenu(User::getCurrentUser()->id());
        $NormalizedUrl = $this->normalizeUrl($AF->getUncleanRelativeUrlWithParams());
        $AddLink = "index.php?P=P_SecondaryNavigation_EditItem&AL=".urlencode($NormalizedUrl).
            "&PT=".urlencode($AF->getUnfilteredPageTitle() ?? "");

        $AddButton = new HtmlButton("Add");
        $AddButton->setIcon("Plus.svg");
        $AddButton->setSize(HtmlButton::SIZE_SMALL);
        $AddButton->addClass("float-end");
        if ($NavMenu->navItemExists($NormalizedUrl)
            || $NormalizedUrl == "index.php?P=Preferences") {
            $AddButton->disable();
        }
        $AddButton->setLink($AddLink);

        $EditButton = new HtmlButton("Edit");
        $EditButton->setIcon("Pencil.svg");
        $EditButton->setSize(HtmlButton::SIZE_SMALL);
        $EditButton->addClass("float-end");
        $EditButton->setTitle("Edit secondary navigation bar.");
        $EditButton->setLink("index.php?P=P_SecondaryNavigation_EditNav");
        ?>
            <div class="row">
                <div class="col">
                    <?= $AddButton->getHtml(); ?>
                </div>
            </div>
            <div class="row">
                <div class="col">
                    <?= $EditButton->getHtml(); ?>
                </div>
            </div>
        <?PHP
    }

    /**
     * Determine whether the given label is allowed (no html tags)
     * @param string|null $FieldName of form field (or null if not used as FormUI validate function)
     * @param string $FieldValue value to validate
     * @return string|null null if label is valid (no html tags), string containing error otherwise
     */
    public static function validateLabel($FieldName, $FieldValue): ?string
    {
        if ($FieldValue == strip_tags($FieldValue)) {
            return null;
        }
        return "Label cannot contain HTML tags.";
    }

    /**
     * Get Tree with structure to display NavItems (Should be depth = 1 since you can't nest)
     * @param NavMenu $NavMenu containing NavItems to display
     * @return array representing structure in which to display the NavItems
     */
    public function getTree(NavMenu $NavMenu): array
    {
        $ItemIds = $NavMenu->getItemIdsInOrder();
        $Tree = [];
        foreach ($ItemIds as $Index => $ItemId) {
            $NavItem = new NavItem($ItemId);
            $Tree[$Index]["Id"] = $ItemId;
            $Tree[$Index]["Label"] = htmlspecialchars($NavItem->label());
            $Tree[$Index]["Link"] = $NavItem->link();
        }
        return $Tree;
    }

    /**
     * Add passed info to list of offered NavItems
     * @param string $Label label of NavItem
     * @param string $Link link of NavItem
     * @param PrivilegeSet $RequiredPrivileges privileges required to add this item
     * @param string $Description description of page linked to (OPTIONAL)
     */
    public function offerNavItem(
        string $Label,
        string $Link,
        PrivilegeSet $RequiredPrivileges,
        string $Description = ""
    ): void {
        # keying on link since it makes checking privs easier later
        $this->OfferedItems[$this->normalizeUrl($Link)] = [
            "Label" => $Label,
            "Privs" => $RequiredPrivileges,
            "Description" => $Description
        ];
    }

    /**
     * Return list of offered NavItems for display on EditNav
     * @return array OfferedItems with privileges/labels keyed on links
     */
    public function getOfferedNavItems(): array
    {
        $Items = $this->OfferedItems;
        # add any additional items to offer from config value
        $ConfigItems = $this->parseAdditionalNavItems(
            $this->getConfigSetting("AdditionalNavItems")
        );
        foreach ($ConfigItems as $Item) {
            # in case this is a file name and not a URL
            if (!$this->urlLooksValid($Item["URL"])) {
                $Item["URL"] = "index.php?P=".$Item["URL"];
            }
            $Items[$Item["URL"]] = [
                "Label" => $Item["Label"],
                "Privs" => new PrivilegeSet($Item["Privs"]),
                "Description" => $Item["Description"]
            ];
        }
        uasort($Items, function ($a, $b) {
            return strcmp($a["Label"], $b["Label"]);
        });
        return $Items;
    }

    /**
     * Append an offered nav item to the current user's navigation if it was
     * not already present.
     * @param string $Link URL of the item to add.
     * @throws Exception when no nav item has been offered for the provided link
     */
    public function addOfferedItemToNavForCurrentUser(
        string $Link
    ): void {
        # retrieve user currently logged in
        $User = User::getCurrentUser();

        if (!$User->isLoggedIn()) {
            throw new Exception(
                "Attempt to modify a user's navigation when no user "
                ."is logged in."
            );
        }

        # normalize the provided link
        $Link = $this->normalizeUrl($Link);

        # throw an exception if the provided link was not valid
        $OfferedItems = $this->getOfferedNavItems();
        if (!isset($OfferedItems[$Link])) {
            throw new InvalidArgumentException(
                "Attempt to add an invalid item to a user's navigation."
                ."No entry for ".$Link." has been offered."
            );
        }

        # get the user's nav
        $NavMenu = new NavMenu($User->id());

        # if this link is already in it, nothing to do
        if ($NavMenu->navItemExists($Link)) {
            return;
        }

        # create and add the requested item
        $NavItem = NavItem::create(
            $User->id(),
            $OfferedItems[$Link]["Label"],
            $Link
        );
        $NavMenu->append($NavItem);
    }

    /**
     * Remove a nav item from the current user's navigation if it is present.
     * @param string $Link URL of the item to remove
     */
    public function removeItemFromNavForCurrentUser(
        string $Link
    ): void {
        # retrieve user currently logged in
        $User = User::getCurrentUser();

        if (!$User->isLoggedIn()) {
            throw new Exception(
                "Attempt to modify a user's navigation when no user "
                ."is logged in."
            );
        }

        # normalize the provided link
        $Link = $this->normalizeUrl($Link);

        # get the user's nav
        $NavMenu = new NavMenu($User->id());

        # remove instances of the provided link
        foreach ($NavMenu->getItems() as $NavItem) {
            if ($NavItem->link() == $Link) {
                $NavMenu->removeItemFromOrder($NavItem->id());
                $NavItem->destroy();
            }
        }
    }

    /**
     * Normalize a URL by alphabetizing parameters
     * @param string $UncleanUrl URL to normalize
     * @return string normalized URL (alphabetized parameters)
     */
    private function normalizeUrl(string $UncleanUrl): string
    {
        # if there are no parameters url is already "normal"
        if (strpos($UncleanUrl, "&") === false) {
            return $UncleanUrl;
        }
        # split url, where index 0 should be index.php?P=PageName
        $SplitUrl = explode("&", $UncleanUrl);
        $CleanUrl = $SplitUrl[0];
        unset($SplitUrl[0]);
        sort($SplitUrl);
        foreach ($SplitUrl as $Parameter) {
            $CleanUrl .= "&".$Parameter;
        }
        return $CleanUrl;
    }

    /**
     * Convert user-inputted additional Nav Items to expected format for OfferedNavItems
     * @param string $NavItemText text from config setting to convert
     * @return array with containing keys URL, Label, Privs, and Description
     */
    private function parseAdditionalNavItems(string $NavItemText): array
    {
        if (strlen(trim($NavItemText)) == 0) {
            return [];
        }
        $AdditionalItems = explode("\n", $NavItemText);
        $FormattedItems = [];
        foreach ($AdditionalItems as $RawItem) {
            $AdditionalItem = explode("|", $RawItem);
            $Privs = isset($AdditionalItem[2]) && strlen(trim($AdditionalItem[2])) ?
                PrivilegeFactory::normalizePrivileges(explode(",", trim($AdditionalItem[2]))) : [];
            $FormattedItems[] = [
                "URL" => isset($AdditionalItem[1]) ? $this->normalizeUrl(
                    trim($AdditionalItem[1])
                ) : "",
                "Label" => isset($AdditionalItem[0]) ? trim($AdditionalItem[0]) : "",
                "Privs" => $Privs,
                "Description" => (isset($AdditionalItem[3]) ? trim($AdditionalItem[3]) : "")
            ];
        }
        return $FormattedItems;
    }

    /**
     * Check whether or not a given string looks like a valid URL
     * @param string $Url to check whether or not it looks valid
     * @return bool true if looks valid, false otherwise
     */
    public static function urlLooksValid(string $Url): bool
    {
        $AF = ApplicationFramework::getInstance();

        # check if this is a known mapped clean URL
        if ($AF->getUncleanRelativeUrlWithParamsForPath(trim($Url)) != $Url) {
            return true;
        }
        # check if this is a valid absolute URL
        $ValidSchemes = ["mailto", "http", "https"];
        if (in_array(parse_url($Url, PHP_URL_SCHEME), $ValidSchemes)) {
            if (filter_var($Url, FILTER_VALIDATE_URL)) {
                return true;
            }
        }
        # check if this is points to our primary file (index.php) with a page argument
        if (parse_url($Url, PHP_URL_PATH) == "index.php") {
            parse_str((string)parse_url($Url, PHP_URL_QUERY), $UrlQuery);
            if (isset($UrlQuery["P"])) {
                return true;
            }
        }
        return false;
    }

    private $SqlTables = [
        "NavItems" => "CREATE TABLE SecondaryNavigation_NavItems (
                NavItemId    INT NOT NULL AUTO_INCREMENT,
                OwnerId      INT,
                Link         TEXT,
                Label        TEXT,
                NextNavItemId INT,
                PreviousNavItemId INT,
                CreatedByUser INT,
                PRIMARY KEY  (NavItemId))",
    ];

    private $OfferedItems = [];
}
