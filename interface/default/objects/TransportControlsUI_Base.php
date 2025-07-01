<?PHP
#
#   FILE:  TransportControlsUI_Base.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2015-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus;
use Exception;
use InvalidArgumentException;
use ScoutLib\StdLib;

/**
* Class to provide support for transport controls (used for paging back
* and forth through a list) in the user interface.  This is an abstract base
* class, that provides everything but the constants defining the $_GET
* variable names for values and the method that actually prints the HTML for
* the controls.  The intent is to provide the ability to customize that HTML
* by replacing just the child class in a different (custom, active) interface.
*/
abstract class TransportControlsUI_Base
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /** parameter ($_GET) names (control ID is appended if non-zero) */
    const PNAME_CHECKSUM = "CK";
    const PNAME_REVERSESORT = "RS";
    const PNAME_SORTFIELD = "SF";
    const PNAME_STARTINGINDEX = "SI";

    /**
     * Class constructor.  Retrieves values for starting index, sort field,
     * and reverse sort order from $_GET using the indexes "SI", "SF", and
     * "RS", respectively (indexes defined in TransportControlsUI class).
     * If there are multiple TransportControlUI instances on a single page,
     * each must have a unique ID.
     * @param int $ControlId Numerical ID for controls.  (OPTIONAL, defaults to 0)
     * @throws InvalidArgumentException If supplied ID duplicates the ID of
     *       an existing TransportControlUI instance.
     */
    public function __construct(?int $ControlId = null)
    {
        # if no ID supplied
        if ($ControlId === null) {
            # make sure IDs were not previously supplied
            if ((static::$NextDefaultId == self::INITIAL_DEFAULT_ID)
                    && count(self::$ActiveControls)) {
                throw new InvalidArgumentException("No ID supplied when IDs"
                        ." have been previously supplied.");
            }

            # assign default ID
            $ControlId = static::$NextDefaultId;
            static::$NextDefaultId++;
        } else {
            # make sure IDs were not previously omitted
            if (static::$NextDefaultId != self::INITIAL_DEFAULT_ID) {
                throw new InvalidArgumentException("ID supplied when IDs"
                        ." have been previously omitted.");
            }

            # make sure ID is not already in use
            if (isset(self::$ActiveControls[$ControlId])) {
                throw new InvalidArgumentException("Duplicate control ID ("
                        .$ControlId.").");
            }
        }

        # save our ID
        $this->Id = $ControlId;

        # retrieve current position (if any) from URL
        $Id = $this->Id ? $this->Id : "";
        $StartingIndex = StdLib::getFormValue(static::PNAME_STARTINGINDEX.$Id);
        if (($StartingIndex !== null) && is_numeric($StartingIndex)) {
            $this->startingIndex((int)$StartingIndex);
        }

        # retrieve sort fields (if any) from URL
        $SortField = StdLib::getFormValue(static::PNAME_SORTFIELD.$Id);

        if ($SortField !== null) {
            if (is_string($SortField) || is_numeric($SortField)) {
                $this->sortField((string)$SortField);
            } elseif (is_array($SortField) && isset($SortField[$ControlId])) {
                $this->sortField((string)$SortField[$ControlId]);
            }
        }
        $ReverseSortFlag = StdLib::getFormValue(static::PNAME_REVERSESORT.$Id);
        if ($ReverseSortFlag !== null) {
            if (is_string($ReverseSortFlag) || is_numeric($ReverseSortFlag)) {
                $this->reverseSortFlag((bool)$ReverseSortFlag);
            } elseif (is_array($ReverseSortFlag) && isset($ReverseSortFlag[$ControlId])) {
                $this->reverseSortFlag((bool)$ReverseSortFlag[$ControlId]);
            }
        }

        # add this instance to list of active controls
        self::$ActiveControls[$this->Id] =& $this;
    }

    /**
     * Filter supplied list of item IDs down to just the portion to be
     * displayed on the current page.  In addition to filtering, this also
     * sets the item count and items per page (so no calls to itemCount()
     * or itemsPerPage() are needed) and adds a checksum to control links,
     * so that paging is reset if the list of items changes.
     * @param array $AllItemIds All item IDs.
     * @param int $ItemsPerPage Maximum number of items on one page.
     * @return array Item IDs for current page.
     */
    public function filterItemIdsForCurrentPage(
        array $AllItemIds,
        int $ItemsPerPage
    ): array {
        # save total item count and items per page
        $this->ItemCount = count($AllItemIds);
        $this->ItemsPerPage = $ItemsPerPage;

        # calculate and save checksum
        $this->ItemChecksum = md5(serialize($AllItemIds));

        # pare down list to just IDs for current page and return it to caller
        return array_slice(
            $AllItemIds,
            $this->StartingIndex,
            $ItemsPerPage
        );
    }

    /**
     * Get ID for controls.
     * @return int Control ID.
     */
    public function id(): int
    {
        return $this->Id;
    }

    /**
     * Get/set maximum number of items per page.
     * @param int $NewValue Max number of items displayed per page.  (OPTIONAL)
     * @return int Current max number of items per page.
     */
    public function itemsPerPage(?int $NewValue = null): int
    {
        if ($NewValue !== null) {
            $this->ItemsPerPage = $NewValue;
        }
        return $this->ItemsPerPage;
    }

    /**
     * Get/set total count of items.
     * @param int $NewValue New total count of items.  (OPTIONAL)
     * @return int Current total count of items.
     */
    public function itemCount(?int $NewValue = null): int
    {
        if ($NewValue !== null) {
            $this->ItemCount = $NewValue;
        }
        return $this->ItemCount;
    }

    /**
     * Get/set current starting index values.
     * @param int $NewValue New starting index value.  (OPTIONAL)
     * @return int Current starting index value.
     */
    public function startingIndex(?int $NewValue = null): int
    {
        if ($NewValue !== null) {
            $this->StartingIndex = $NewValue;
        }
        return $this->StartingIndex;
    }

    /**
     * Get/set field currently used for sorting.
     * @param string $NewValue New sort field.  (OPTIONAL)
     * @return string|null Current sort field or NULL if none set..
     */
    public function sortField(?string $NewValue = null): ?string
    {
        if ($NewValue !== null) {
            $this->SortField = $NewValue;
        }
        return ($this->SortField === null) ? $this->DefaultSortField : $this->SortField;
    }

    /**
     * Get/set default sort field value.  This value determines whether or
     * not a sort field is included in the query string parameters.  If no
     * default sort field is set, then the sort field is always included.
     * @param string $NewValue New default sort field.  (OPTIONAL)
     * @return string|null Current default sort field or NULL if none set.
     */
    public function defaultSortField(?string $NewValue = null): ?string
    {
        if ($NewValue !== null) {
            $this->DefaultSortField = $NewValue;
        }
        return $this->DefaultSortField;
    }

    /**
     * Get/set whether to reverse the sort order from normal.
     * @param bool $NewValue New value.  (OPTIONAL)
     * @return bool TRUE to reverse sort order or FALSE to use normal order.
     */
    public function reverseSortFlag(?bool $NewValue = null): bool
    {
        if ($NewValue !== null) {
            $this->ReverseSortFlag = $NewValue;
        }
        return $this->ReverseSortFlag;
    }

    /**
     * Get string containing URL parameters, ready for inclusion in URL.
     * @param bool $EncodeSeparators If TRUE, "&amp;" is used to separate
     *       arguments, otherwise "&" is used.  (OPTIONAL, defaults to TRUE)
     * @param array $ExcludeParameters Array with one or more parameters to
     *       exclude from the parameter string.  (OPTIONAL)
     * @param bool $IncludeAllControls If TRUE, parameters for all current
     *      active TransportControlUI instances will be included.  (OPTIONAL,
     *      defaults to TRUE)
     * @return string URL parameter string, with leading "&amp;" or "&" if
     *       parameters are included.
     */
    public function urlParameterString(
        $EncodeSeparators = true,
        $ExcludeParameters = [],
        $IncludeAllControls = true
    ): string {
        $String = "";
        $Sep = $EncodeSeparators ? "&amp;" : "&";

        $Controls = $IncludeAllControls ? self::$ActiveControls
                : [ self::$ActiveControls[$this->Id] ];
        $QData = [];
        foreach ($Controls as $Ctrl) {
            $Id = $Ctrl->Id ? $Ctrl->Id : "";
            if ($Ctrl->startingIndex() != static::DEFAULT_STARTING_INDEX) {
                $QData[static::PNAME_STARTINGINDEX.$Id] = $Ctrl->startingIndex();
            }

            if (($Ctrl->defaultSortField() === null)
                    || ($Ctrl->sortField() != $Ctrl->defaultSortField())) {
                $QData[static::PNAME_SORTFIELD.$Id] = $Ctrl->sortField();
            }

            if ($Ctrl->reverseSortFlag() != static::DEFAULT_REVERSE_SORT_FLAG) {
                $QData[static::PNAME_REVERSESORT.$Id] = $Ctrl->reverseSortFlag();
            }
        }

        foreach ($ExcludeParameters as $Param) {
            unset($QData[$Param]);
        }

        if (count($QData)) {
            $String .= $Sep.http_build_query($QData, "", $Sep);
        }

        if (isset($this->ItemChecksum)) {
            $String .= $Sep."CK=".$this->ItemChecksum;
        }

        return $String;
    }

    /**
     * Get/set printable name for items.  If an item type name is supplied,
     * it will be used in the title attributes for the transport  control
     * buttons.  (This can help with accessibility.)  If never supplied, the
     * item type name defaults to "Item".
     * @param string $NewValue Item type name.  (OPTIONAL)
     * @return string Current item type name.
     */
    public function itemTypeName(?string $NewValue = null): string
    {
        if ($NewValue !== null) {
            $this->ItemTypeName = $NewValue;
        }
        return $this->ItemTypeName;
    }

    /**
     * Get/set base link for URLs.
     * @param string $NewValue New base URL.
     * @return string Current base link.
     */
    public function baseLink(?string $NewValue = null): string
    {
        if ($NewValue !== null) {
            $this->BaseLink = $NewValue;
        }
        return $this->BaseLink;
    }

    /**
     * Get/set message to display in between transport controls.
     * @param string $NewValue New message text.  (OPTIONAL)
     * @return string Current message text.
     */
    public function message(?string $NewValue = null): string
    {
        if ($NewValue !== null) {
            $this->Message = $NewValue;
        }
        return $this->Message;
    }

    /**
     * Generate and print HTML for transport controls.
     */
    abstract public function display(): void;

    /**
     * Generate and return HTML for transport controls.
     * @return string Generated HTML.
     */
    public function getHtml(): string
    {
        ob_start();
        $this->display();
        $Html = ob_get_clean();
        if ($Html === false) {
            throw new Exception("Unabled to retrieve buffered HTML.");
        }
        return $Html;
    }


    # ---- PRIVATE INTERFACE -------------------------------------------------

    const DEFAULT_REVERSE_SORT_FLAG = false;
    const DEFAULT_STARTING_INDEX = 0;
    const INITIAL_DEFAULT_ID = 0;

    protected $BaseLink;
    protected $DefaultSortField = null;
    protected $Id;
    protected $ItemChecksum;
    protected $ItemCount;
    protected $ItemsPerPage = 10;
    protected $ItemTypeName = "Item";
    protected $LastPageStartIndex;
    protected $Message = "";
    protected $ReverseSortFlag = self::DEFAULT_REVERSE_SORT_FLAG;
    protected $SortField = null;
    protected $StartingIndex = self::DEFAULT_STARTING_INDEX;

    protected static $ActiveControls = [];
    protected static $NextDefaultId = self::INITIAL_DEFAULT_ID;

    /**
     * Check indexes and make sure they are within bounds.
     * @return void
     */
    protected function checkIndexes(): void
    {
        # determine index of first item on the first and last page
        $ExtraItems = $this->ItemCount % $this->ItemsPerPage;
        $ItemsOnLastPage = ($ExtraItems == 0) ? $this->ItemsPerPage : $ExtraItems;
        $this->LastPageStartIndex = max(0, $this->ItemCount - $ItemsOnLastPage);

        # make sure starting index is within bounds
        if ($this->StartingIndex > $this->LastPageStartIndex) {
            $this->StartingIndex = $this->LastPageStartIndex;
        }
    }

    /**
     * Get distance to jump for fast forward/reverse.
     * @return int Number of items to jump.
     */
    protected function fastDistance(): int
    {
        return (int)floor($this->ItemCount / 5)
                - ((int)floor($this->ItemCount / 5) % $this->ItemsPerPage);
    }

    /**
     * Generate link with specified modified starting index.
     * @param int $StartingIndex Index to use.
     * @return string Generated link.
     */
    protected function getLinkWithStartingIndex(int $StartingIndex): string
    {
        # temporarily swap in supplied starting index
        $SavedStartingIndex = $this->StartingIndex;
        $this->StartingIndex = $StartingIndex;

        # get link with parameters
        $Link = $this->BaseLink.$this->urlParameterString();

        # restore starting index
        $this->StartingIndex = $SavedStartingIndex;

        # return link to caller
        return $Link;
    }

    # ---- TEST/LINK METHODS ------------------------------------------------

    /**
     * Report whether any forward buttons should be displayed.
     * @return bool TRUE if at least one forward button should be displayed,
     *       otherwise FALSE.
     */
    protected function showAnyForwardButtons(): bool
    {
        return ((int)($this->StartingIndex + $this->ItemsPerPage)
                < ($this->LastPageStartIndex + 1));
    }

    /**
     * Report whether any reverse buttons should be displayed.
     * @return bool TRUE if at least one reverse button should be displayed,
     *       otherwise FALSE.
     */
    protected function showAnyReverseButtons(): bool
    {
        return ($this->StartingIndex > 0);
    }

    /**
     * Report whether forward (one page) button should be displayed.
     * @return bool TRUE if forward button should be displayed, otherwise FALSE.
     */
    protected function showForwardButton(): bool
    {
        return ($this->StartingIndex < ($this->LastPageStartIndex - $this->ItemsPerPage));
    }

    /**
     * Report whether reverse button should be displayed.
     * @return bool TRUE if reverse button should be displayed, otherwise FALSE.
     */
    protected function showReverseButton(): bool
    {
        return (($this->StartingIndex + 1) >= ($this->ItemsPerPage * 2));
    }

    /**
     * Report whether fast forward button should be displayed.
     * @return bool TRUE if fast forward button should be displayed, otherwise FALSE.
     */
    protected function showFastForwardButton(): bool
    {
        return (($this->fastDistance() > $this->ItemsPerPage)
                && (($this->StartingIndex + $this->fastDistance())
                        < $this->LastPageStartIndex));
    }

    /**
     * Report whether fast reverse button should be displayed.
     * @return bool TRUE if fast reverse button should be displayed, otherwise FALSE.
     */
    protected function showFastReverseButton(): bool
    {
        return (($this->fastDistance() > $this->ItemsPerPage)
                && ($this->StartingIndex >= $this->fastDistance()));
    }

    /**
     * Get link for forward button.
     * @return string Link URL.
     */
    protected function forwardLink(): string
    {
        $Index = min(
            $this->LastPageStartIndex,
            ($this->StartingIndex + $this->ItemsPerPage)
        );
        return $this->getLinkWithStartingIndex($Index);
    }

    /**
     * Get link for reverse button.
     * @return string Link URL.
     */
    protected function reverseLink(): string
    {
        $Index = (int)max(0, ($this->StartingIndex - $this->ItemsPerPage));
        return $this->getLinkWithStartingIndex($Index);
    }

    /**
     * Get link for fast forward button.
     * @return string Link URL.
     */
    protected function fastForwardLink(): string
    {
        $Index = min(
            $this->LastPageStartIndex,
            ($this->StartingIndex + $this->fastDistance())
        );
        return $this->getLinkWithStartingIndex($Index);
    }

    /**
     * Get link for fast reverse button.
     * @return string Link URL.
     */
    protected function fastReverseLink(): string
    {
        $Index = (int)max(0, ($this->StartingIndex - $this->fastDistance()));
        return $this->getLinkWithStartingIndex($Index);
    }

    /**
     * Get link for button to go to end.
     * @return string Link URL.
     */
    protected function goToEndLink(): string
    {
        return $this->getLinkWithStartingIndex($this->LastPageStartIndex);
    }

    /**
     * Get link for button to go to start.
     * @return string Link URL.
     */
    protected function goToStartLink(): string
    {
        return $this->getLinkWithStartingIndex(0);
    }
}
