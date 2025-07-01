<?PHP
#
#   FILE:  ItemListUI.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2016-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus;
use Exception;
use ScoutLib\ApplicationFramework;
use ScoutLib\HtmlOptionList;
use ScoutLib\Item;
use ScoutLib\StdLib;

/**
* Class to provide a user interface for displaying a list of items.
*/
class ItemListUI
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Constructor for item list UI class.  Possible values for the inner
     * index in the $Fields parameter:
     *   AlignRight - if set and TRUE, heading and content for this field
     *      will be aligned right if possible,
     *   AllowHTML - if defined, values from the field will not have HTML
     *      characters escaped before the value is displayed,
     *   CssClassFunction - callback that accepts an item and field ID, and
     *      returns a CSS class to be added to the table cell in which the
     *      content is displayed,
     *   CssClasses - CSS classes to be applied to table cells containing
     *      values for this field,
     *   DefaultSortField - if defined, will mark field as being the default
     *      sort field
     *   DefaultToDescendingSort - if defined, will mark field as defaulting
     *      to descending (as opposed to ascending) sort order
     *   Heading - heading text for field column (if not supplied, the
     *      field name is used),
     *   Link - URL to which to link the field content (if present, $ID will
     *      be replaced with the field ID),
     *   LinkFunction - callback that accepts an item, and returns the value
     *      of the link or FALSE, if no url is to be linked.
     *   MaxLength - maximum length in characters for the field text,
     *   Sortable - if FALSE, sorting will not be available for the field.
     *   ValueFunction - callback that accepts an item and field ID, and
     *      returns the value to be printed.
     * The outer index for the $Fields parameter can be one of three types of
     * values, depending on what is passed for the $Items argument to the
     * display() method:
     *   Array index - If $Items is an array of associative arrays, then the
     *      index for $Fields should be an index into the inner array,
     *   Method Name - If $Items is an array of objects, then the index
     *      can be the name of a method callable for them, or
     *   Field Identifier - If $Items is an array of objects that have a
     *      "get()" method, then the index can be a value interpretable
     *      by that method.
     * If there are multiple ItemListUI instances on a single page,
     * each must have a TransportControlsUI with a unique ID
     *
     * @param array $Fields Associative array of associative arrays of
     *      information about fields to be displayed, with the inner and
     *      outer indexes as described above.
     * @param array $Items Array of item objects or array of associative
     *       arrays, with item IDs for the base index and (if an array of
     *       arrays) field identifiers (corresponding to the values used
     *       in the $Fields parameter to the constructor) for the associative
     *       array indexes.
     * @param ?int $ControlId Numerical ID for UI, for situations where
     *      there may be more than one item list UI on the page.  (OPTIONAL)
     */
    public function __construct(
        array $Fields,
        array $Items = [],
        ?int $ControlId = null
    ) {
        $AF = ApplicationFramework::getInstance();

        $this->Fields = $Fields;
        $this->Items = $Items;
        $this->Id = $ControlId;

        # initialize values from environment where available
        $Id = $this->Id ?? "";
        $this->StartingIndex = $_GET[self::PNAME_STARTINGINDEX.$Id] ?? null;
        $this->SortField = $_GET[self::PNAME_SORTFIELD.$Id] ?? null;
        $this->SortDescending = ($_GET[self::PNAME_REVERSESORT.$Id] ?? false)
                ? false : true;

        # set default base link
        $this->setBaseLink("index.php?P=".$AF->getPageName());

        # check for deprecated or duplicated parameters
        foreach ($this->Fields as $FieldId => $FieldInfo) {
            if (isset($FieldInfo["NoSorting"])) {
                $AF->logMessage(
                    ApplicationFramework::LOGLVL_WARNING,
                    "Deprecated 'NoSorting' parameter in use at ".StdLib::getMyCaller()
                            ." for field with ID '".$FieldId
                            ."', should be replaced by 'Sortable'."
                );
            }
            if (isset($FieldInfo["DefaultSortField"])) {
                if (isset($DefaultSortFieldFound)) {
                    $AF->logMessage(
                        ApplicationFramework::LOGLVL_WARNING,
                        "Duplicate 'DefaultSortField' parameter in use at "
                                .StdLib::getMyCaller()
                                ." for field with ID '".$FieldId."'."
                    );
                }
                $DefaultSortFieldFound = true;
            }
        }

        # set default base link
        $this->baseLink("index.php?P=".$AF->getPageName());
    }

    /**
     * Get HTML for list.
     * @return string HTML for list.
     */
    public function getHtml(): string
    {
        # determine whether list will run to multiple pages
        $ItemCount = $this->TotalItemCount ?? count($this->Items);
        $WeHaveAllItems = $this->TotalItemCount === null;
        $PagingIsNeeded = $ItemCount > $this->ItemsPerPage;

        # set up transport controls
        $this->setUpTransportControls();

        # start HTML with buttons above list
        $Html = $this->getTopButtonHtml();

        # add heading for entire list
        $Html .= $this->getListHeadingHtml();

        # if no items and no "show table even if empty"
        if (($ItemCount == 0) && !$this->DisplayEmptyTable) {
            # add "no items" message and exit
            $Html .= $this->getNoItemsMessageHtml();
            return $Html;
        }

        # if we are managing items (i.e. we have all items)
        if ($WeHaveAllItems) {
            # sort items (if requested)
            $this->SortedItems = $this->sortItems($this->Items);

            # reset starting index if checksum has changed
            $Id = $this->Id ?? "";
            $PreviousChecksum = $_GET[self::PNAME_CHECKSUM.$this->Id] ?? "";
            $CurrentChecksum = $this->getChecksumForItems($this->SortedItems);
            if ($CurrentChecksum != $PreviousChecksum) {
                $this->TransportUI->startingIndex(0);
            }

            # pare down items to one page worth or less (if necessary)
            $ItemsToDisplay = $this->SortedItems;
            if ($PagingIsNeeded) {
                $ItemsToDisplay = array_slice(
                    $ItemsToDisplay,
                    $this->TransportUI->startingIndex(),
                    $this->ItemsPerPage,
                    true
                );
            }
        } else {
            # use provided subset of items
            $this->SortedItems = $this->Items;
            $ItemsToDisplay = $this->Items;
        }

        # set base link for transport controls (must be done after sorting
        #       because it may include a list checksum)
        $this->TransportUI->baseLink($this->getFullBaseLink());

        # add HTML for main item table
        $Html .= $this->getMainTableHtml($ItemsToDisplay);

        # add HTML for transport controls (if needed)
        if ($PagingIsNeeded) {
            $Html .= $this->TransportUI->getHtml();
        }

        return $Html;
    }

    # ---- PUBLIC INTERFACE (List Attributes) --------------------------------

    /**
     * Set list of items to be displayed.  This overrides the list supplied
     * to the constructor.
     * @param array $Items Array of item objects or array of associative
     *       arrays, with item IDs for the base index and (if an array of
     *       arrays) field identifiers (corresponding to the values used
     *       in the $Fields parameter to the constructor) for the associative
     *       array indexes.
     */
    public function setItems(array $Items): void
    {
        $this->Items = $Items;
    }

    /**
     * Set base URL for current page, for any links that need to be
     * constructed.
     * @param string $NewValue New base URL, with any ampersands encoded
     *       as entities (&amp;).
     */
    public function setBaseLink(?string $NewValue = null): void
    {
        $this->BaseLink = $NewValue;
    }

    /**
     * Set list of $_GET variables to preserve by adding them to base link
     * whenever it is used.
     * @param array $NewValue Array of $_GET variable names.
     */
    public function setVariablesToPreserve(array $NewValue): void
    {
        $this->VariablesToPreserve = $NewValue;
    }

    /**
     * Set heading text to be printed above list.
     * @param string $NewValue New heading text.
     */
    public function setHeading(string $NewValue): void
    {
        $this->Heading = $NewValue;
    }

    /**
     * Set subheading text to be printed above list but below heading.
     * If $NewValue is an empty string, no subheading will be displayed.
     * @param string $NewValue New subheading text.
     */
    public function setSubheading(string $NewValue): void
    {
        $this->Subheading = $NewValue;
    }

    /**
     * Set printable name for items.  If never supplied, the item type
     * name defaults to "Item".
     * @param string $NewValue Item type name.
     */
    public function setItemTypeName(?string $NewValue = null): void
    {
        $this->ItemTypeName = $NewValue;
    }

    /**
     * Set maximum number of items per page.
     * @param int $NewValue New max number of items per page.
     */
    public function setItemsPerPage(?int $NewValue = null): void
    {
        $this->ItemsPerPage = $NewValue;
    }

    /**
     * Set message to display when there are no items to list.
     * @param string $NewValue New message.
     */
    public function setNoItemsMessage(?string $NewValue = null): void
    {
        $this->NoItemsMsg = $NewValue;
    }

    /**
     * Set total count of items.  If not specified via this method, the number
     * of items supplied will be assumed to be the total number of items.
     * @param int $ItemCount Total count of items.
     */
    public function setTotalItemCount(int $ItemCount): void
    {
        $this->TotalItemCount = $ItemCount;
    }

    /**
     * Set starting index for segment of items (within total list of items)
     * to be displayed.  If not specified, the index retrieved from $GET
     * will be used, or zero if no value is available from $GET.
     * @param int $Index Starting index.
     */
    public function setStartingIndex(int $Index): void
    {
        $this->StartingIndex = $Index;
    }

    /**
     * Set text to display on transport controls line.  If not specified,
     * this defaults to "Items X - Y of Z", where "X", "Y", and "Z" are
     * filled in appropriately for the current segment being displayed.
     * @param string $Msg Text to display on transport control line.
     */
    public function setTransportControlsMessage(string $Msg): void
    {
        $this->TransportMsg = $Msg;
    }

    /**
     * Set whether to display table when there are no items in list.
     * @param bool $NewValue If TRUE, table will be displayed.
     */
    public function willDisplayEmptyTable(bool $NewValue): void
    {
        $this->DisplayEmptyTable = $NewValue;
    }

    /**
     * Set whether fields are sortable by default.(Defauls to TRUE.)
     * @param bool $NewValue If FALSE, fields will not be sortable unless
     *      a "Sortable" parameter is set to TRUE in the field info.
     */
    public function fieldsSortableByDefault(bool $NewValue): void
    {
         $this->SortableByDefault = $NewValue ? true : false;
    }

    # ---- PUBLIC INTERFACE (List Decorations) -------------------------------

    /**
     * Set transport controls UI to be used.  If not supplied via this method,
     * transport controls with a default ID will be created and used.
     */
    public function setTransportControls(TransportControlsUI $TransportUI): void
    {
        $this->TransportUI = $TransportUI;

        # if transport controls have an ID set
        if ($TransportUI->id() != 0) {
            # if we have an ID set
            if ($this->Id !== null) {
                # check that transport controls ID is not different from ours
                if ($TransportUI->id() != $this->Id) {
                    throw new Exception("Supplied transport controls have a"
                            ." different ID from that specified for item list UI.");
                }
            } else {
                # get ID from transport controls
                $this->Id = $TransportUI->id();
            }
        }
    }

    /**
     * Add "button" above list.
     * @param string $Label Label for button.
     * @param string $Link URL that button should link to.
     * @param string $Icon Name of image file to display before the label.
     *      (OPTIONAL, defaults to no image)
     * @param string $Tooltip Text for "title" attribute, to display when
     *      hovering over checkbox.  (OPTIONAL)
     */
    public function addTopButton(
        string $Label,
        string $Link,
        ?string $Icon = null,
        ?string $Tooltip = null
    ): void {
        $this->Buttons[] = [
            "Label" => $Label,
            "Link" => $Link,
            "Icon" => $Icon,
            "Tooltip" => $Tooltip
        ];
    }

    /**
     * Add "checkbox" above list.When the checkbox is changed, the URL
     * provided in $Link is jumped to, with the correct value set for $VarName
     * appended.
     * @param string $Label Label for checkbox.
     * @param bool $Checked If TRUE, box should currently be checked.
     * @param string $VarName Name of variable to submit with form.
     * @param string $Link URL that form should submit to if box is toggled.
     *      If $VarName variable is present in the URL ($_GET) parameters,
     *      it will be removed.
     * @param string $Tooltip Text for "title" attribute, to display when
     *      hovering over checkbox.  (OPTIONAL)
     */
    public function addTopCheckbox(
        string $Label,
        bool $Checked,
        string $VarName,
        string $Link,
        ?string $Tooltip = null
    ): void {
        if (strpos($Link, $VarName."=") !== false) {
            $Link = preg_replace(
                "/(&|&amp;)".preg_quote($VarName, "/")."=[^&]*/",
                "",
                $Link
            );
        }
        $this->Buttons[] = [
            "Label" => $Label,
            "Checked" => $Checked,
            "VarName" => $VarName,
            "Link" => $Link,
            "Tooltip" => $Tooltip
        ];
    }

    /**
     * Add an "option list" above list.When an option in the option list is
     * clicked, the URL provided in $Link is immediately jumped to, with the
     * correct value set for $VarName appended.
     * @param array $Options Array of options, with form values for the
     *       array index and labels for the array values.For grouping,
     *       any of the options may actually be an array of options, with a
     *       group label for the index, and the array for the value.
     * @param string $VarName Name of variable to submit with form.
     * @param string $Link URL that form should submit to if option is clicked.
     *      If $VarName variable is present in the URL ($_GET) parameters,
     *      it will be removed.
     * @param string|array $Selected Currently selected form value or array
     *      of currently selected form values.  (OPTIONAL)
     * @param string $Tooltip Text for "title" attribute, to display when
     *      hovering over checkbox.  (OPTIONAL)
     */
    public function addTopOptionList(
        array $Options,
        string $VarName,
        string $Link,
        $Selected = null,
        ?string $Tooltip = null
    ): void {
        if (strpos($Link, $VarName."=") !== false) {
            $Link = preg_replace(
                "/(&|&amp;)".preg_quote($VarName, "/")."=[^&]*/",
                "",
                $Link
            );
        }
        $this->Buttons[] = [
            "Options" => $Options,
            "VarName" => $VarName,
            "Link" => $Link,
            "Selected" => $Selected,
            "Tooltip" => $Tooltip
        ];
    }

    /**
     * Add action "button" to each item in list.
     * @param string $Label Label for button.
     * @param string|callable $Link URL that button should link to, with $ID optionally
     *       in the string to indicate where the item ID should be inserted, or
     *       a function that is passed the item and should return the URL.
     * @param string $Icon Name of image file to display before the label.
     *       (OPTIONAL, defaults to no image)
     * @param callable $DisplayTestFunc Function that is passed the item, and
     *       should return TRUE if the buttons should be displayed for that
     *       item, and FALSE otherwise.(OPTIONAL, defaults to NULL, which
     *       will call "UserCanEdit()" method on item, if available, with
     *       current active user (User::getCurrentUser()))
     * @param array $AdditionalAttributes Additional attributes to add to
     *       the button, with HTML attribute name for the index and attribute
     *       value for the value.(OPTIONAL)
     */
    public function addActionButton(
        string $Label,
        $Link,
        ?string $Icon = null,
        ?callable $DisplayTestFunc = null,
        array $AdditionalAttributes = []
    ): void {
        $this->Actions[] = [
            "Label" => $Label,
            "Link" => $Link,
            "Icon" => $Icon,
            "TestFunc" => $DisplayTestFunc,
            "AddAttribs" => $AdditionalAttributes
        ];
    }

    # ---- PUBLIC INTERFACE (List Operations) --------------------------------

    /**
     * Sort list items. Notes:
     * - If list items are arrays, then items will be sorted by comparing the
     *      array elements at the index specified by the sort field.
     * - If list items are objects and the objects have a class method that is
     *      the same name as the sort field, then items will be sorted by
     *      comparing the results of the value returned by that sort field.
     * - Otherwise if the list items have a "getXYZ()" method, where "XYZ" is
     *      the sort field, the list items will be compared using the results
     *      of the value returned by that method.
     * - Otherwise if the list items have a "get()" method, the list items
     *      will be compared using the results of the value returned by the
     *      "get()" method when passing the sort field as a parameter to that
     *      method.
     * - If no sort field has been previously set or marked in the field
     *      definitions with the "DefaultSortField" parameter, then the first
     *      defined field will be used as the sort field.
     * - Value comparisons are done using the spaceship ("<=>") operator.
     * - This method just sets a flag to request sorting;  the actual sorting
     *      does not happen until just before the HTML is generated.
     */
    public function sort(): void
    {
        $this->ItemsShouldBeSorted = true;
    }

    /**
     * Get field that should be used for sorting.  Field values should
     * correspond to the outer index of the array supplied as the $Fields
     * parameter to the constructor.  This method is intended to be used
     * when application code is handling the sorting of items, before they
     * are passed to ItemListUI.
     * @param string $DefaultSortField Field to return if not sort field
     *      currently available.
     * @param int $Id Numberical ID for list / controls.  (OPTIONAL)
     * @return string Field that should be used for sorting.
     */
    public static function getSortField(
        string $DefaultSortField,
        ?int $Id = null
    ): string {
        $GetVarName = self::PNAME_SORTFIELD.($Id ?? "");
        return $_GET[$GetVarName] ?? $DefaultSortField;
    }

    /**
     * Get whether item sorting should be in descending order.  This method
     * is intended to be used when application code is handling the sorting
     * of items, before they are passed to ItemListUI.
     * @param bool $DefaultToDescending If TRUE, sorting will default to
     *      descending order if not otherwise specified.
     * @param int $Id Numberical ID for list / controls.  (OPTIONAL)
     * @return bool TRUE if sort should be in descending order, or FALSE
     *      if sort should be in ascending order.
     */
    public static function getSortDirection(
        bool $DefaultToDescending = false,
        ?int $Id = null
    ): bool {
        $GetVarName = self::PNAME_REVERSESORT.($Id ?? "");
        return $_GET[$GetVarName] ?? $DefaultToDescending;
    }

    # ---- PUBLIC INTERFACE (Deprecated Methods) -----------------------------

    /**
     * Get/set base URL for current page, for any links that need to be
     * constructed.
     * @param string $NewValue New base URL, with any ampersands encoded
     *       as entities (&amp;).(OPTIONAL)
     * @return string Current base URL value.
     * @deprecated 2025-04-29
     * @see setBaseLink()
     */
    public function baseLink(?string $NewValue = null): string
    {
        if ($NewValue !== null) {
            $this->setBaseLink($NewValue);
        }
        return $this->BaseLink;
    }

    /**
     * Get/set heading text to be printed above list.
     * @param string $NewValue New heading text.(OPTIONAL)
     * @return string Current heading text.
     * @deprecated 2025-04-29
     * @see setHeading()
     */
    public function heading(?string $NewValue = null): string
    {
        if ($NewValue !== null) {
            $this->setHeading($NewValue);
        }
        return $this->Heading;
    }

    /**
     * Get/set subheading text to be printed above list but below heading.
     * If $NewValue is an empty string, no subheading will be displayed.
     * @param string $NewValue New subheading text.(OPTIONAL)
     * @return string Current subheading text.
     * @deprecated 2025-04-29
     * @see setSubheading()
     */
    public function subheading(?string $NewValue = null): string
    {
        if ($NewValue !== null) {
            $this->setSubheading($NewValue);
        }
        return $this->Subheading;
    }

    /**
     * Get/set printable name for items.If never supplied, the item type
     * name defaults to "Item".
     * @param string $NewValue Item type name.(OPTIONAL)
     * @return string Current item type name.
     * @deprecated 2025-04-29
     * @see setItemTypeName()
     */
    public function itemTypeName(?string $NewValue = null): string
    {
        if ($NewValue !== null) {
            $this->setItemTypeName($NewValue);
        }
        return $this->ItemTypeName;
    }

    /**
     * Get/set maximum number of items per page.
     * @param int $NewValue New max number of items per page.(OPTIONAL)
     * @return int Current number of items per page.
     * @deprecated 2025-04-29
     * @see setItemsPerPage()
     */
    public function itemsPerPage(?int $NewValue = null): int
    {
        if ($NewValue !== null) {
            $this->setItemsPerPage($NewValue);
        }
        return $this->ItemsPerPage;
    }

    /**
     * Get/set message to display when there are no items to list.
     * @param string $NewValue New message.(OPTIONAL)
     * @return string Current "no items" message.
     * @deprecated 2025-04-29
     * @see setNoItemsMessage()
     */
    public function noItemsMessage(?string $NewValue = null): string
    {
        if ($NewValue !== null) {
            $this->setNoItemsMessage($NewValue);
        }
        return $this->NoItemsMsg;
    }

    /**
     * Print list HTML with specified items.If an array of items objects is
     * passed in, the item IDs in the array index are only used if the objects
     * do not have an Id() method.
     * @param array $Items Array of item objects or array of associative
     *       arrays, with item IDs for the base index and (if an array of
     *       arrays) field identifiers (corresponding to the values used
     *       in the $Fields parameter to the constructor) for the associative
     *       array indexes.
     * @param int $TotalItemCount Total number of items.(OPTIONAL, defaults
     *       to number of items passed in)
     * @param int $StartingIndex Current starting index.(OPTIONAL, defaults
     *       to value retrieved from $GET, or zero if no $GET value)
     * @param string $TransportMsg Text to display on transport control line.
     *       (OPTIONAL, defaults to "Items X - Y of Z")
     * @deprecated 2025-04-29
     * @see getHtml()
     */
    public function display(
        array $Items,
        ?int $TotalItemCount = null,
        ?int $StartingIndex = null,
        ?string $TransportMsg = null
    ): void {
        $this->setItems($Items);
        if ($TotalItemCount !== null) {
            $this->setTotalItemCount($TotalItemCount);
        }
        if ($StartingIndex !== null) {
            $this->setStartingIndex($StartingIndex);
        }
        if ($TransportMsg !== null) {
            $this->setTransportControlsMessage($TransportMsg);
        }
        print $this->getHtml();
    }


    # ---- PRIVATE INTERFACE -------------------------------------------------

    # parameter ($_GET) names (control ID is appended if non-zero)
    # (these MUST match the values defined in TransportControlsUI_Base)
    const PNAME_CHECKSUM = "CK";
    const PNAME_REVERSESORT = "RS";
    const PNAME_SORTFIELD = "SF";
    const PNAME_STARTINGINDEX = "SI";

    private $Actions = [];
    private $BaseLink;
    private $Buttons = [];
    private $DisplayEmptyTable = false;
    private $Fields;
    private $Heading = "";
    private $Id;
    private $Items = [];
    private $ItemsPerPage = 25;
    private $ItemsShouldBeSorted = false;
    private $ItemTypeName = "Item";
    private $NoItemsMsg;
    private $SortableByDefault = true;
    private $SortDescending;
    private $SortedItems;
    private $SortField;
    private $StartingIndex = null;
    private $Subheading = "";
    private $TotalItemCount = null;
    private $TransportMsg = null;
    private $TransportUI;
    private $VariablesToPreserve = [];

    /**
     * Set up transport controls UI and any associated data.
     */
    private function setUpTransportControls(): void
    {
        # instantiate transport controls UI if none previously supplied
        if (!isset($this->TransportUI)) {
            $this->TransportUI = new TransportControlsUI();
            $this->TransportUI->startingIndex($this->StartingIndex);
            $this->TransportUI->sortField($this->SortField);
            $this->TransportUI->reverseSortFlag(!$this->SortDescending);
        }

        # set default sort field in transport controls if one provided
        foreach ($this->Fields as $FieldId => $FieldInfo) {
            if ($FieldInfo["DefaultSortField"] ?? false) {
                $this->TransportUI->defaultSortField($FieldId);
                break;
            }
        }

        # set other transport controls settings
        $this->TransportUI->itemCount($this->TotalItemCount ?? count($this->Items));
        $this->TransportUI->itemsPerPage($this->ItemsPerPage);

        # set other transport controls settings (null values will not set anything)
        $this->TransportUI->message($this->TransportMsg);
        $this->TransportUI->startingIndex($this->StartingIndex);
    }

    /**
     * Get checksum for sorted version of current set of list items.
     * @param array $Items Set of items for which to generate checksum.
     * @return string Checksum string.
     */
    private function getChecksumForItems(array $Items): string
    {
        # sort items (if requested)
        $SortedItems = $this->sortItems($Items);

        # serialize sorted items to use in calculating checksum, falling back
        #       to serializing array keys if items include closures
        try {
            $SerializedItems = serialize($SortedItems);
        } catch (Exception $Ex) {
            if ($Ex->getMessage() == "Serialization of 'Closure' is not allowed") {
                $SerializedItems = serialize(array_keys($SortedItems));
            } else {
                throw $Ex;
            }
        }

        # calculate checksum and return it to caller
        return md5($SerializedItems);
    }

    /**
     * Get full base link, including any variables to preserve.
     * @return string Full base URL, with ampersands encoded (&amp;).
     */
    private function getFullBaseLink(): string
    {
        $Vars = [];

        # if we are managing items (i.e. we have all items)
        if ($this->TotalItemCount === null) {
            # check to make sure we have sorted items
            if (!is_array($this->SortedItems)) {
                throw new Exception("Attempt to generate item list full base"
                        ." link before items have been specified.");
            }

            # add list checksum value to variables
            $Id = $this->Id ?? "";
            $Vars[self::PNAME_CHECKSUM.$Id] =
                    $this->getChecksumForItems($this->SortedItems);
        }

        # add specified variables to preserve to variables
        foreach ($this->VariablesToPreserve as $VarName) {
            if (isset($_GET[$VarName])) {
                $Vars[$VarName] = $_GET[$VarName];
            }
        }

        # determine what variables are already set in supplied base link
        $SuppliedVarStr = parse_url(
            htmlspecialchars_decode($this->BaseLink),
            PHP_URL_QUERY
        );
        if ($SuppliedVarStr === false) {
            throw new Exception("Could not parse supplied base link (\""
                    .$this->BaseLink."\").");
        }
        parse_str($SuppliedVarStr, $SuppliedVars);

        # do not add vars that already set in supplied base link
        $Vars = array_diff_key($Vars, $SuppliedVars);

        # add remaining variables to supplied base link and return it to caller
        $FullBaseLink = $this->BaseLink
                .(count($Vars) ? "&amp;".http_build_query($Vars, "", "&amp;") : "");
        return $FullBaseLink;
    }

    # ---- PRIVATE INTERFACE (HTML Generation) -------------------------------

    /**
     * Generate HTML for any buttons above item list.
     * @return string Generated HTML.
     */
    private function getTopButtonHtml(): string
    {
        if (!count($this->Buttons)) {
            return "";
        }

        $Html = '<span class="mv-itemlistui-topbuttons">';
        foreach ($this->Buttons as $Info) {
            if (isset($Info["Tooltip"])) {
                $Html .= "<span title=\"".htmlspecialchars($Info["Tooltip"])."\">";
            } else {
                $Html .= "<span>";
            }

            if (isset($Info["Checked"])) {
                $CheckboxState = $Info["Checked"] ? "checked" : "";
                $OnChangeLinkBase = $Info["Link"]."&amp;".$Info["VarName"]."=";
                $OnChangeAction = "if (this.checked) {"
                        ."  window.location = '".$OnChangeLinkBase."1';"
                        ."} else {"
                        ."  window.location = '".$OnChangeLinkBase."0';"
                        ."}";
                $Html .= "<input type=\"checkbox\" name=\"".$Info["VarName"]."\" "
                        .$CheckboxState." onchange=\"".$OnChangeAction."\"> "
                        .$Info["Label"];
            } elseif (isset($Info["Options"])) {
                $TargetLink = $Info["Link"]."&amp;".$Info["VarName"]."=";

                $OptList = new HtmlOptionList(
                    $Info["VarName"],
                    $Info["Options"],
                    $Info["Selected"]
                );
                $OptList->submitOnChange(true);
                $OptList->onChangeAction("window.location.replace('".$TargetLink
                        ."' + this.options[this.selectedIndex].value)");
                $Html .= $OptList->getHtml();
            } else {
                $Button = new HtmlButton($Info["Label"]);
                $Button->setLink($Info["Link"]);
                if (strlen($Info["Icon"] ?? "")) {
                    $Button->setIcon($Info["Icon"]);
                }
                $Html .= $Button->getHtml();
            }
            $Html .= "</span>";
        }
        $Html .= "</span>";
        return $Html;
    }

    /**
     * Generate HTML for list heading.
     * @return string Generated HTML.
     */
    private function getListHeadingHtml(): string
    {
        $Html = "";

        if (strlen($this->Heading)) {
            if ($this->Heading == strip_tags($this->Heading)) {
                $Html .= "<h1>".$this->Heading."</h1>\n";
            } else {
                $Html .= $this->Heading."\n";
            }
        }

        if (strlen($this->Subheading)) {
            if ($this->Subheading == strip_tags($this->Subheading)) {
                $Html .= "<p class=\"mv-itemlistui-subheading\">"
                        .$this->Subheading."</p>\n";
            } else {
                $Html .= $this->Subheading."\n";
            }
        }

        return $Html;
    }

    /**
     * Generate HTML for "No Items" message.
     * @return string Generated HTML.
     */
    private function getNoItemsMessageHtml(): string
    {
        $Html = "<span class=\"mv-itemlistui-empty\">";
        if (!is_null($this->NoItemsMsg) && strlen($this->NoItemsMsg)) {
            $Html .= $this->NoItemsMsg;
        } elseif (strlen($this->TransportUI->itemTypeName())) {
            $Html .= "(no ".strtolower(StdLib::pluralize(
                $this->TransportUI->itemTypeName()
            ))." to display)";
        } else {
            $Html .= "(no items to display)";
        }
        $Html .= "</span>";
        return $Html;
    }

    /**
     * Generate HTML for main item table.
     * @param array $Items Items to display.
     * @return string Generated HTML.
     */
    private function getMainTableHtml(array $Items): string
    {
        # begin table
        $Html = '<table class="table table-striped mv-itemlistui">';

        # begin header row
        $Html .= "<thead><tr class='table-dark'>";

        # for each field
        foreach ($this->Fields as $FieldId => $FieldInfo) {
            $ClassAttrib = isset($FieldInfo["AlignRight"])
                    ? " class=\"text-end\"" : "";
            $Html .= "<th".$ClassAttrib.">"
                    .$this->getFieldHeadingHtml($FieldId)."</th>\n";
        }

        # add action header if needed
        if (count($this->Actions)) {
            $Html .= "<th>Actions</th>\n";
        }

        # end header row
        $Html .= "</tr></thead>\n";

        # begin content area
        $Html .= "<tbody>\n";

        # if no items in table
        if (!count($Items)) {
            # display "no items" table row
            $ColumnCount = count($this->Fields) + (count($this->Actions) ? 1 : 0);
            $Html .= "<tr><td colspan=\"".$ColumnCount."\">"
                    .$this->getNoItemsMessageHtml()."</td></tr>";
        } else {
            # add content rows
            foreach ($Items as $ItemId => $Item) {
                $Html .= $this->getRowHtml((string)$ItemId, $Item);
            }
        }

        # end content area
        $Html .= "</tbody>\n";

        # end item table
        $Html .= "</table>\n";

        return $Html;
    }

    /**
     * Generate HTML for table row.
     * @param string $ItemId ID for item for row.
     * @param Item|array $Item Object or array of data for row.
     * @return string Generated HTML.
     */
    private function getRowHtml($ItemId, $Item): string
    {
        $Html = "<tr>\n";

        foreach ($this->Fields as $FieldId => $FieldInfo) {
            # retrieve content for cell
            $Content = $this->getFieldContentHtml($Item, $ItemId, $FieldId);
            $FieldInfo = $this->Fields[$FieldId];

            # retrieve class for cell
            $Classes = isset($FieldInfo["AlignRight"]) ? "text-end" : "";
            if (isset($FieldInfo["CssClassFunction"])) {
                $Classes .= " ".$FieldInfo["CssClassFunction"]($Item, $FieldId);
            }
            if (isset($FieldInfo["CssClasses"])) {
                $Classes .= " ".$FieldInfo["CssClasses"];
            }
            $ClassAttrib = strlen($Classes)
                    ? " class=\"".trim($Classes)."\""
                    : "";

            # add cell to row
            $Html .= "<td".$ClassAttrib.">".$Content."</td>\n";
        }

        # add action button cell (if needed)
        if (count($this->Actions)) {
            $Html .= "<td>".$this->getActionButtonHtml($ItemId, $Item)."</td>\n";
        }

        $Html .= "</tr>\n";
        return $Html;
    }

    /**
     * Get HTML for field (column) heading.
     * @param string $FieldId ID of field.
     * @return string Generated HTML.
     */
    private function getFieldHeadingHtml(string $FieldId): string
    {
        # retrieve context values from transport controls
        $SortFieldId = $this->TransportUI->sortField();
        if ($SortFieldId === null) {
            $SortFieldId = $this->TransportUI->defaultSortField();
        }
        $ReverseSort = $this->TransportUI->reverseSortFlag();

        # if header value supplied
        if (isset($this->Fields[$FieldId]["Heading"])) {
            # use supplied value
            $Heading = $this->Fields[$FieldId]["Heading"];
        # else if field ID appears like it may be a name
        } elseif (!is_numeric($FieldId)) {
            $Heading = $FieldId;
        } else {
            $Heading = "(NO HEADER SET)";
        }

        # if sorting is enabled for field
        $Sortable = isset($this->Fields[$FieldId]["Sortable"])
                ? $this->Fields[$FieldId]["Sortable"]
                : (isset($this->Fields[$FieldId]["NoSorting"])
                        ? !$this->Fields[$FieldId]["NoSorting"]
                        : $this->SortableByDefault);
        if ($Sortable) {
            # build sort link
            $Id = $this->Id ?? "";
            $ParamsToExclude = [
                self::PNAME_CHECKSUM.$Id,
                self::PNAME_REVERSESORT.$Id,
                self::PNAME_SORTFIELD.$Id,
            ];
            $SortLink = $this->getFullBaseLink()."&amp;"
                    .self::PNAME_SORTFIELD.$Id."=".urlencode($FieldId)
                    .$this->TransportUI->urlParameterString(true, $ParamsToExclude);

            # determine current sort direction
            if (isset($this->Fields[$FieldId]["DefaultToDescendingSort"])) {
                $SortAscending = $ReverseSort ? true : false;
            } else {
                $SortAscending = $ReverseSort ? false : true;
            }

            # set sort direction indicator (if any)
            if ($FieldId == $SortFieldId) {
                $DirIndicator = ($SortAscending) ? "&uarr;" : "&darr;";
                if (!$ReverseSort) {
                    $SortLink .= "&amp;".self::PNAME_REVERSESORT.$Id."=1";
                }
            } else {
                $DirIndicator = "";
            }

            # add sort link and sort direction indicator to header
            $Heading = "<a href=\"".$SortLink."\">".$Heading."</a>".$DirIndicator;
        }

        return $Heading;
    }

    /**
     * Get HTML for field content for specified item.
     * @param mixed $Item Item (object or associative array) to generate
     *      content for.
     * @param string $ItemId ID of item.
     * @param string $FieldId ID of field.
     * @return string Generated HTML.
     */
    private function getFieldContentHtml($Item, string $ItemId, string $FieldId): string
    {
        # if there is value function defined for field
        $FieldInfo = $this->Fields[$FieldId];
        if (isset($FieldInfo["ValueFunction"])) {
            # call function for value
            $Value = $FieldInfo["ValueFunction"]($Item, $FieldId);
        } else {
            # if item is array
            if (is_array($Item)) {
                # retrieve value for field (if any) from item
                $Value = isset($Item[$FieldId])
                        ? $Item[$FieldId] : "";
            # else if item class has method that matches field ID
            } elseif (is_object($Item) && method_exists($Item, $FieldId)) {
                # get field value via item method
                $Value = $Item->$FieldId();
            # else if item class has retrieval method that matches field ID
            } elseif (is_object($Item) && method_exists($Item, "get".$FieldId)) {
                # get field value via retrieval method
                $MethodName = "get".$FieldId;
                $Value = $Item->$MethodName();
            # else if item class has generic "get" method
            } elseif (is_object($Item) && method_exists($Item, "get")) {
                # get field value from item via get()
                $Value = $Item->get($FieldId);
            } else {
                throw new Exception("Item encountered that does not have"
                        ." either a method matching the field ID (".$FieldId
                        .") or a get() method.");
            }

            if ($Value !== null) {
                # if value is an array use just the first element
                if (is_array($Value)) {
                    $Value = array_shift($Value) ?? "";
                }

                # trim value if max length specified for field
                if (isset($FieldInfo["MaxLength"]) && strlen($Value)) {
                    $Value = StdLib::neatlyTruncateString(
                        $Value,
                        $FieldInfo["MaxLength"]
                    );
                }

                # encode any HTML-significant chars in value if necessary
                if (!isset($FieldInfo["AllowHTML"])) {
                    $Value = htmlspecialchars($Value);
                }
            }
        }

        # get link value (if any)
        if (isset($FieldInfo["Link"])) {
            $Link = preg_replace(
                '/\$ID/',
                urlencode($ItemId),
                $FieldInfo["Link"]
            );
            $LinkStart = '<a href="'.$Link.'">';
            $LinkEnd = "</a>";
        } elseif (isset($FieldInfo["LinkFunction"])) {
            $Link = $FieldInfo["LinkFunction"]($Item);
            if (!strlen($Link)) {
                $LinkStart = "";
                $LinkEnd = "";
            } else {
                $Link = preg_replace(
                    '/\$ID/',
                    urlencode($ItemId),
                    $Link
                );
                $LinkStart = '<a href="'.$Link.'">';
                $LinkEnd = "</a>";
            }
        } else {
            $LinkStart = "";
            $LinkEnd = "";
        }

        return $LinkStart.$Value.$LinkEnd;
    }

    /**
     * Generate HTML for any action buttons for specified item.
     * @param string $ItemId Id of item to display buttons for.
     * @param mixed $Item Item to display buttons for.
     * @return string Generated HTML.
     */
    private function getActionButtonHtml(string $ItemId, $Item): string
    {
        $AF = ApplicationFramework::getInstance();
        # retrieve user currently logged in
        $User = User::getCurrentUser();

        $Html = "";
        foreach ($this->Actions as $ActionInfo) {
            if ($ActionInfo["TestFunc"] !== null) {
                $DisplayButton = $ActionInfo["TestFunc"]($Item);
            } elseif (is_object($Item) && method_exists($Item, "userCanEdit")) {
                $DisplayButton = $Item->userCanEdit($User);
            } else {
                $DisplayButton = true;
            }

            if ($DisplayButton) {
                $ButtonClasses = "btn btn-primary btn-sm";
                $ExtraAttribs = "";
                foreach ($ActionInfo["AddAttribs"] as $AttribName => $AttribValue) {
                    $AttribValue = htmlspecialchars($AttribValue);
                    if (strtolower($AttribName) == "class") {
                        $ButtonClasses .= " ".$AttribValue;
                    } else {
                        $ExtraAttribs .= " ".$AttribName
                                .'="'.$AttribValue.'"';
                    }
                }
                if ($ActionInfo["Icon"]) {
                    $IconFile = $AF->gUIFile($ActionInfo["Icon"]);
                    $IconTag = $IconFile
                            ? '<img class="mv-button-icon" src="'
                                    .$IconFile.'" alt=""> '
                            : "";
                    $ButtonClasses .= " mv-button-iconed";
                } else {
                    $IconTag = "";
                }
                if (is_callable($ActionInfo["Link"])) {
                    $Link = $ActionInfo["Link"]($Item);
                } else {
                    $Link = preg_replace(
                        '/\$ID/',
                        urlencode($ItemId),
                        $ActionInfo["Link"]
                    );
                }
                $Html .= '<a class="'.$ButtonClasses.'"'.$ExtraAttribs
                        .' href="'.$Link.'">'.$IconTag
                        .htmlspecialchars($ActionInfo["Label"]).'</a>';
            }
        }
        if ($Item instanceof Record) {
            ob_start();
            $AF->signalEvent(
                "EVENT_HTML_INSERTION_POINT",
                [
                    $AF->getPageName(),
                    "Resource Summary Buttons",
                    ["Resource" => $Item]
                ]
            );
            $Html .= ob_get_clean();
        }
        return $Html;
    }

    # ---- PRIVATE INTERFACE (Sorting) ---------------------------------------

    /**
     * Sort supplied items based on current sorting settings.
     * @param array $Items Items to be sorted.
     * @return array Sorted items.
     * @see sort()
     */
    private function sortItems(array $Items): array
    {
        if ($this->ItemsShouldBeSorted === false) {
            return $Items;
        }

        $DefaultSortField = $this->getDefaultSortField();
        $this->SortField = self::getSortField($DefaultSortField, $this->Id);
        $this->SortDescending =
                $this->Fields[$this->SortField]["DefaultToDescendingSort"]
                ?? false;
        $Id = $this->Id ?? "";
        if ($_GET[self::PNAME_REVERSESORT.$Id] ?? false) {
            $this->SortDescending = !$this->SortDescending;
        }
        uasort($Items, [$this, "sortFunc"]);
        return $Items;
    }

    /**
     * Sorting function for list items, to be used with usort().
     * @param array|object $ItemA First item to compare.
     * @param array|object $ItemB Second item to compare.
     * @return int Comparison value, as defined by usort().
     */
    private function sortFunc($ItemA, $ItemB): int
    {
        $ItemValueA = $this->sortValueRetrievalFunc($ItemA);
        $ItemValueB = $this->sortValueRetrievalFunc($ItemB);
        return $this->SortDescending
                ? $ItemValueB <=> $ItemValueA
                : $ItemValueA <=> $ItemValueB;
    }

    /**
     * Retrieval value for item, for use when sorting items.
     * @param array|object $Item Item to retrieval comparison value for.
     * @return mixed Retrieved value to compare.
     * @see sort()
     */
    private function sortValueRetrievalFunc($Item)
    {
        $SortField = $this->SortField;
        if (is_array($Item)) {
            if (isset($Item[$SortField])) {
                $ItemValue = $Item[$SortField];
            } else {
                throw new Exception("Attempt to sort array list value with"
                        ." no element matching \"".$SortField."\" sort field.");
            }
        } elseif (is_object($Item)) {
            if (method_exists($Item, $SortField)) {
                $ItemValue = $Item->$SortField();
            } elseif (method_exists($Item, "get".$SortField)) {
                $MethodName = "get".$SortField;
                $ItemValue = $Item->$MethodName();
            } elseif (method_exists($Item, "get")) {
                $ItemValue = $Item->get($SortField);
            } else {
                throw new Exception("Attempt to sort object list value with"
                        ." no method matching \"".$SortField."\" sort field"
                        ." and no get() method..");
            }
        } else {
            throw new Exception("Attempt to sort list value that was not"
                    ." either an array or an object.");
        }
        return $ItemValue;
    }

    /**
     * Get default sort field, from field definitions if available.  If
     * not available and no default default is provided, the first field
     * present in the field definitions is used.
     * @param ?string $DefaultDefaultField Value to use if no default
     *      available in field definitions.  (OPTIONAL)
     * @return string Default sort field.
     */
    private function getDefaultSortField(?string $DefaultDefaultField = null): string
    {
        # look for default sort field among field definitions
        foreach ($this->Fields as $FieldName => $Field) {
            if (isset($Field["DefaultSortField"])
                    && $Field["DefaultSortField"]) {
                $SortField = $FieldName;
                break;
            }
        }

        # if no default sort field found
        if (!isset($SortField)) {
            # use default default sort field if supplied
            if (isset($DefaultDefaultField)) {
                $SortField = $DefaultDefaultField;
            # otherwise use first field present in field definitions
            } else {
                $Fields = $this->Fields;
                reset($Fields);
                $SortField = key($Fields);
            }
        }

        return $SortField;
    }
}
