<?PHP
#
#   FILE:  Page.php (Pages plugin)
#
#   Copyright 2012-2025 Edward Almasy and Internet Scout Research Group
#   http://scout.wisc.edu/cwis/
#
# @scout:phpstan

namespace Metavus\Plugins\Pages;
use Exception;
use Metavus\Image;
use Metavus\ImageFactory;
use Metavus\MetadataField;
use Metavus\MetadataSchema;
use Metavus\Plugins\Pages;
use Metavus\PrivilegeSet;
use Metavus\Record;
use Metavus\TabbedContentUI;
use ScoutLib\ApplicationFramework;
use ScoutLib\PluginManager;
use ScoutLib\StdLib;
use ScoutLib\User;

/**
 * Class representing individual pages in the Pages plugin.
 */
class Page extends Record
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Object constructor for loading an existing page.  (To create a new
     * page, use Page::Create().).
     * @param int $PageId ID of the page to load.
     */
    public function __construct($PageId)
    {
        # run base class constructor
        parent::__construct($PageId);

        # load viewing privilege setting if available
        $Data = $this->DB->queryValue(
            "SELECT ViewingPrivileges"
                ." FROM Pages_Privileges"
                ." WHERE PageId = ".intval($PageId),
            "ViewingPrivileges"
        );
        $this->ViewingPrivs = ($Data === false) ? new PrivilegeSet()
                : new PrivilegeSet($Data);
    }

    /**
     * Create a new page.
     * @param int $SchemaId XX (DO NOT USE -- for compatibility with Resource::Create())
     * @return Page Page object.
     * @throws \Exception If bad metadata schema ID parameter is provided.
     */
    public static function create(?int $SchemaId = null): Page
    {
        # bail out of erroneous schema ID was provided
        if (($SchemaId !== null) && ($SchemaId != PageFactory::$PageSchemaId)) {
            throw new \Exception("Unnecessary and erroneous schema ID provided.");
        }

        # create a resource
        $Resource = Record::create(PageFactory::$PageSchemaId);

        # reload resource as a page
        $Id = $Resource->Id();
        unset($Resource);
        $Page = new Page($Id);

        # return page to caller
        return $Page;
    }

    /**
     * Set value using field name, ID, or field object.
     * @param int|string|MetadataField $Field Field ID or full name of field
     *      or MetadataField object.
     * @param mixed $NewValue New value for field.
     * @param bool $Reset When TRUE Controlled Names, Classifications,
     *       and Options will be set to contain *ONLY* the contents of
     *       NewValue, rather than appending $NewValue to the current value.
     * @throws \Exception When attempting to set a value for a field that is
     *       part of a different schema than the resource.
     * @throws \InvalidArgumentException When attempting to set a controlled
     *       name with an invalid ID.
     * @see Record::set().
     */
    public function set($Field, $NewValue, bool $Reset = false)
    {
        $Field = $this->normalizeFieldArgument($Field);

        # return if we don't have a valid field
        if (!($Field instanceof MetadataField)) {
            return;
        }

        # handle image keyword replacement
        if ($Field->type() == MetadataSchema::MDFTYPE_PARAGRAPH) {
            $NewValue = ImageFactory::convertUrlsToKeywords($NewValue);
        }

        parent::set($Field, $NewValue, $Reset);

        if ($Field->name() == "Clean URL") {
            Pages::getInstance(true)
                ->clearCaches();
        }
    }

    /**
     * Remove page (and accompanying associations) from database and
     * delete any associated files.
     */
    public function destroy(): void
    {
        $this->DB->query("DELETE FROM Pages_Privileges WHERE PageId = ".$this->id());

        parent::destroy();
        Pages::getInstance(true)
            ->clearCaches();
    }

    /**
     * Get a value from a Page. Expands image keywords in the Paragraph fields to
     *   URLs.
     * @param int|string|MetadataField $Field Field ID or full name of field
     *      or MetadataField object.
     * @param bool $ReturnObject For field types that can return multiple values, if
     *      TRUE, returns array of objects, else returns array of values.
     *      Defaults to FALSE.
     * @param bool $IncludeVariants If TRUE, includes variants in return value.
     *      Only applicable for ControlledName fields.
     * @return mixed Requested object(s) or value(s).  Returns empty array
     *      (for field types that allow multiple values) or NULL (for field
     *      types that do not allow multiple values) if no values found.  Returns
     *      NULL if field does not exist or was otherwise invalid.
     * @see Record::get()
     */
    public function get($Field, bool $ReturnObject = false, bool $IncludeVariants = false)
    {
        $Field = $this->normalizeFieldArgument($Field);
        $Value = parent::get($Field, $ReturnObject, $IncludeVariants);

        if ($Field->type() == MetadataSchema::MDFTYPE_PARAGRAPH) {
            $Value = (new ImageFactory())->convertKeywordsToUrls((string)$Value);
        }

        return $Value;
    }

    /**
     * Determine if the given user can view the page.  The result of this
     * method may be modified via EVENT_RESOURCE_VIEW_PERMISSION_CHECK.
     * @param User $User User to check.
     * @param bool $AllowHooksToModify If TRUE, hooks to the modification
     *       event will be allowed to change the result.
     * @return bool TRUE if the user can view the page and FALSE otherwise
     */
    public function userCanView(User $User, bool $AllowHooksToModify = true): bool
    {
        # construct a key to use for our permissions cache
        $CacheKey = "UserCanView".$User->id();

        # if we don't have a cached value for this perm, compute one
        if (!isset($this->PermissionCache[$CacheKey])) {
            # check passes if user privileges are greater than resource set
            $CheckResult = ($this->ViewingPrivs->MeetsRequirements($User, $this)
                    && parent::userCanView($User, false)) ? true : false;

            # save the result of this check in our cache
            $this->PermissionCache[$CacheKey] = $CheckResult;
        }

        # retrieve value from cache
        $Value = $this->PermissionCache[$CacheKey];

        # allow any hooked functions to modify the result if allowed
        if ($AllowHooksToModify) {
            $SignalResult = ApplicationFramework::getInstance()->signalEvent(
                "EVENT_RESOURCE_VIEW_PERMISSION_CHECK",
                [
                    "Resource" => $this,
                    "User" => $User,
                    "CanView" => $Value,
                    "Schema" => $this->getSchema(),
                ]
            );
            $Value =  $SignalResult["CanView"];
        }

        # return result to caller
        return $Value;
    }

    /**
     * Get/set viewing privileges for page.
     * @param PrivilegeSet $NewValue New viewing privilege setting.  (OPTIONAL)
     * @return PrivilegeSet|null Current viewing privilege setting or NULL if no
     *       viewing privileges have been set.
     */
    public function viewingPrivileges(?PrivilegeSet $NewValue = null)
    {
        # if new privilege setting was supplied
        if ($NewValue !== null) {
            # save new setting in database
            $Value = $this->DB->queryValue(
                "SELECT ViewingPrivileges"
                    ." FROM Pages_Privileges"
                    ." WHERE PageId = ".intval($this->id()),
                "ViewingPrivileges"
            );
            if ($this->DB->NumRowsSelected()) {
                $this->DB->Query("UPDATE Pages_Privileges"
                        ." SET ViewingPrivileges ="
                        ." '".addslashes($NewValue->Data())."'"
                        ." WHERE PageId = ".intval($this->id()));
            } else {
                $this->DB->Query("INSERT INTO Pages_Privileges"
                        ." (PageId, ViewingPrivileges) VALUES"
                        ." (".intval($this->id()).","
                        ." '".addslashes($NewValue->Data())."')");
            }

            # clear cached permissions
            $this->clearPermissionsCache();

            # save new setting for local use
            $this->ViewingPrivs = $NewValue;
        } else {
            # if setting has not already been loaded
            if (!isset($this->ViewingPrivs)) {
                # load setting from database
                $Value = $this->DB->queryValue(
                    "SELECT ViewingPrivileges"
                        ." FROM Pages_Privileges"
                        ." WHERE PageId = ".intval($this->id()),
                    "ViewingPrivileges"
                );
                $this->ViewingPrivs = ($Value === false) ? null
                        : new PrivilegeSet($Value);
            }
        }

        # return current privilege settings to caller
        return $this->ViewingPrivs;
    }

    /**
     * Check whether current page contains jquery-ui tabs.
     * @return bool TRUE for pages that have tabs, FALSE otherwise.
     */
    public function containsTabs(): bool
    {
        return count($this->getTabNames()) > 0;
    }

    /**
     * Get the names of jquery-ui tabs on the page, where the names are the
     * down-cased alphanumeric portions of the text in the tab header (e.g.,
     * for a tab with a header that said "Student Success" the tab name would
     * be "studentsuccess").
     * @return array of tab names; empty for pages that have no tabs.
     */
    public function getTabNames(): array
    {
        if (!is_null($this->TabNames)) {
            return $this->TabNames;
        }

        preg_match_all(
            '%<h2 class="mv-tab-start">(.*)</h2>%',
            $this->get("Content"),
            $Matches,
            PREG_PATTERN_ORDER
        );

        $this->TabNames = [];

        foreach ($Matches[1] as $TabTitle) {
            $this->TabNames[] = strtolower(
                preg_replace(
                    "/[^A-Za-z0-9]/",
                    "",
                    strip_tags($TabTitle)
                )
            );
        }

        return $this->TabNames;
    }

    /**
     * Convert our internal markup for tabs into code that will display
     * tab block(s).
     * @param string $Html Html to process
     * @return string processed HTML.
     */
    public static function processTabMarkup(string $Html): string
    {
        $TabSetIndex = 0;
        ob_start();
        foreach (explode("\n", $Html) as $Line) {
            # if this is the beginning of a new tab
            if (preg_match('%<h2 class="mv-tab-start">(.*)</h2>%', $Line, $Matches)) {
                # start a new tab set if we do not have one open
                $TabUI = $TabUI ?? new TabbedContentUI();

                # begin new tab section
                $TabUI->beginTab(strip_tags($Matches[1]));
            # else if this is a tab end marker
            } elseif (preg_match('%<h4 class="mv-tab-end">(.*)</h4>%', $Line, $Matches)) {
                # if we have an open tab set
                if (isset($TabUI)) {
                    # output tab set content
                    $TabSetIndex++;
                    $TabUI->display("mv-tabset-".$TabSetIndex);

                    # close tab set
                    unset($TabUI);
                }
            } else {
                print $Line."\n";
            }
        }

        # if we have an open tab set
        if (isset($TabUI)) {
            # output tab set content
            $TabSetIndex++;
            $TabUI->display("mv-tabset-".$TabSetIndex);
        }

        # return generated HTML to caller
        $ProcessedHtml = ob_get_clean();
        if ($ProcessedHtml === false) {
            throw new Exception("Unabled to retrieve buffered HTML.");
        }
        return $ProcessedHtml;
    }

    /**
     * Get page summary text, based on page content.
     * @param int $Length Target length for summary, in characters.  (OPTIONAL,
     *       defaults to 320)
     * @return string Summary text.
     */
    public function getSummary(int $Length = 280): string
    {
        # get main page content
        $Content = $this->get("Content");

        # strip out any headings
        $Content = preg_replace("%<h[1-3]>.+</h[1-3]>%", "", $Content);

        # strip out any HTML
        $Content = strip_tags($Content);

        # strip any extraneous whitespace
        $Content = preg_replace("%\s+%", " ", trim($Content));

        # truncate result to desired length
        $Summary = StdLib::neatlyTruncateString($Content, $Length);

        # return "summary" to caller
        return $Summary;
    }

    /**
     *  Get/set whether Page is a temporary record.
     * @param bool $NewSetting TRUE/FALSE setting for whether resource is
     *       temporary. (OPTIONAL)
     * @return bool TRUE if resource is temporary record, or FALSE otherwise.
     */
    public function isTempRecord(?bool $NewSetting = null): bool
    {
        $OldId = $this->id();
        $Result = parent::isTempRecord($NewSetting);
        $NewId = $this->id();
        if ($NewId != $OldId) {
            $this->DB->query("UPDATE Pages_Privileges SET PageId = "
                .$NewId." WHERE PageId = ".$OldId);
        }
        return $Result;
    }


    # ---- PRIVATE INTERFACE -------------------------------------------------

    private $TabNames = null;
    private $ViewingPrivs;
}
