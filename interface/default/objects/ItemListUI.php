<?PHP
#
#   FILE:  ItemListUI.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2016-2024 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus;
use Exception;
use InvalidArgumentException;
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
     * Constructor for item list UI class.Possible values for the inner
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
     *      sort field (NOTE: does not do any actual sorting of items -- that
     *      must be done to the data before it is passed in),
     *   DefaultToDescendingSort - if defined, will mark field as defaulting
     *      to descending (as opposed to ascending) sort order
     *   Heading - heading text for field column (if not supplied, the
     *      field name is used),
     *   Link - URL to which to link the field content (if present, $ID will
     *      be replaced with the field ID),
     *   MaxLength - maximum length in characters for the field text,
     *   Sortable - if FALSE, sorting will not be available for the field.
     *   ValueFunction - callback that accepts an item and field ID, and
     *      returns the value to be printed.
     *   LinkFunction - callback that accepts an item, and returns the value
     *      of the link or FALSE, if no url is to be linked.
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
     * @param TransportControlsUI $TransportUI (OPTIONAL, if not supplied,
     *      transport controls with a default ID will be created and used)
     */
    public function __construct(
        array $Fields,
        $TransportUI = null
    ) {
        $AF = ApplicationFramework::getInstance();
        $this->Fields = $Fields;

        if ($TransportUI instanceof TransportControlsUI) {
            $this->TransportUI = $TransportUI;
        } elseif ($TransportUI === null) {
            $this->TransportUI = new TransportControlsUI();
        } else {
            throw new InvalidArgumentException("Second argument supplied that is"
                    ." not a TransportControlsUI object.");
        }
        $this->TransportUI->itemsPerPage($this->ItemsPerPage);

        foreach ($this->Fields as $FieldId => $FieldInfo) {
            if (isset($FieldInfo["DefaultSortField"])) {
                $this->TransportUI->defaultSortField($FieldId);
            }
            if (isset($FieldInfo["NoSorting"]) && !isset($NoSortingWarningGiven)) {
                $AF->logMessage(
                    ApplicationFramework::LOGLVL_WARNING,
                    "Deprecated 'NoSorting' parameter in use at ".StdLib::getMyCaller()
                            .", should be replaced by 'Sortable'."
                );
                $NoSortingWarningGiven = true;
            }
        }

        # set default base link
        $this->baseLink("index.php?P=".$AF->GetPageName());
    }

    /**
     * Get/set base URL for current page, for any links that need to be
     * constructed.
     * @param string $NewValue New base URL, with any ampersands encoded
     *       as entities (&amp;).(OPTIONAL)
     * @return string Current base URL value.
     */
    public function baseLink(?string $NewValue = null): string
    {
        if ($NewValue !== null) {
            $this->BaseLink = $NewValue;
            $this->TransportUI->baseLink($this->BaseLink);
        }
        return $this->BaseLink;
    }

    /**
     * Get/set list of $_GET variables to preserve by adding them to base link
     * whenever it is used.
     * @param array $NewValue Array of $_GET variable names.(OPTIONAL)
     * @return array Array with names of current $_GET variables to preserve.
     */
    public function variablesToPreserve(?array $NewValue = null): array
    {
        if ($NewValue !== null) {
            $this->VariablesToPreserve = $NewValue;
        }
        return $this->VariablesToPreserve;
    }

    /**
     * Get full base link, including any variables to preserve.
     * @return string Full base URL, with ampersands encoded (&amp;).
     */
    public function getFullBaseLink(): string
    {
        $Link = $this->BaseLink;
        foreach ($this->VariablesToPreserve as $VarName) {
            if (isset($_GET[$VarName])) {
                $Link .= "&amp;".$VarName."=".urlencode($_GET[$VarName]);
            }
        }
        return $Link;
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
     * Get/set heading text to be printed above list.
     * @param string $NewValue New heading text.(OPTIONAL)
     * @return string Current heading text.
     * @deprecated
     */
    public function heading(?string $NewValue = null): string
    {
        if ($NewValue !== null) {
            $this->setHeading($NewValue);
        }
        return $this->Heading;
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
     * Get/set subheading text to be printed above list but below heading.
     * If $NewValue is an empty string, no subheading will be displayed.
     * @param string $NewValue New subheading text.(OPTIONAL)
     * @return string Current subheading text.
     * @deprecated
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
     */
    public function itemTypeName(?string $NewValue = null): string
    {
        return $this->TransportUI->itemTypeName($NewValue);
    }

    /**
     * Get/set maximum number of items per page.
     * @param int $NewValue New max number of items per page.(OPTIONAL)
     * @return int Current number of items per page.
     */
    public function itemsPerPage(?int $NewValue = null): int
    {
        if ($NewValue !== null) {
            $this->ItemsPerPage = $NewValue;
            $this->TransportUI->ItemsPerPage($this->ItemsPerPage);
        }
        return $this->ItemsPerPage;
    }

    /**
     * Add "button" above list.
     * @param string $Label Label for button.
     * @param string $Link URL that button should link to.
     * @param string $Icon Name of image file to display before the label.
     *      (OPTIONAL, defaults to no image)
     * @param string $Tooltip Text for "title" attribute, to display when
     *      hovering over checkbox.  (OPTIONAL)
     * @return void
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
     * @return void
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
                "/(&|&amp;)".preg_quote($VarName)."=[^&]*/",
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
     * @return void
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
                "/(&|&amp;)".preg_quote($VarName)."=[^&]*/",
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
     * @return void
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

    /**
     * Get/set message to display when there are no items to list.
     * @param string $NewValue New message.(OPTIONAL)
     * @return string Current "no items" message.
     */
    public function noItemsMessage(?string $NewValue = null): string
    {
        if ($NewValue !== null) {
            $this->NoItemsMsg = $NewValue;
        }
        return $this->NoItemsMsg;
    }

    /**
     * Get/set whether to display table when there are no items in list.
     * @param bool $NewValue If TRUE, table will be displayed.(OPTIONAL)
     * @return bool TRUE if table will be displayed when no items.
     */
    public function willDisplayEmptyTable(?bool $NewValue = null): bool
    {
        if ($NewValue !== null) {
            $this->DisplayEmptyTable = $NewValue;
        }
        return $this->DisplayEmptyTable;
    }

    /**
     * Get the transport controls UI component used by the item list.
     * @return TransportControlsUI Transport control object (reference).
     */
    public function &transportUI()
    {
        return $this->TransportUI;
    }

    /**
     * Get/set whether fields are sortable by default.(Defauls to TRUE.)
     * @param bool $NewValue If FALSE, fields will not be sortable unless
     *      a "Sortable" parameter is set to TRUE in the field info.
     * @return bool If TRUE, fields will be sortable by default.
     */
    public function fieldsSortableByDefault(?bool $NewValue = null): bool
    {
        if ($NewValue !== null) {
             $this->SortableByDefault = $NewValue ? true : false;
        }
        return $this->SortableByDefault;
    }

    /**
     * Get HTML for list with specified items. If an array of item objects are
     * passed in, the item IDs in the array index are only used if the objects
     * do not have an Id() method.
     * @param array $Items Array of item objects or array of associative
     *       arrays, with item IDs for the base index and (if an array of
     *       arrays) field identifiers (corresponding to the values used
     *       in the $Fields parameter to the constructor) for the associative
     *       array indexes.
     * @return string HTML for list.
     */
    public function getHtml(array $Items): string
    {
        # retrieve context values from transport controls
        $StartingIndex = $this->TransportUI->StartingIndex($this->StartingIndex);

        # display buttons above list
        $Html = $this->getTopButtonHtml();

        # display heading for entire list
        $Html .= $this->getListHeadingHtml();

        # display "no items" message and exit if no items and no show empty table
        if (!$this->DisplayEmptyTable && !count($Items)) {
            $Html .= $this->getNoItemsMessageHtml();
            return $Html;
        }

        # begin item table
        $Html .= '<table class="table table-striped mv-itemlistui">';

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

        # if there are more items than are displayed
        if ($this->TotalItemCount > count($Items)) {
            # display transport controls
            $this->TransportUI->startingIndex($StartingIndex);
            $this->TransportUI->itemCount($this->TotalItemCount);
            if (strlen($this->TransportMsg ?? "")) {
                $this->TransportUI->message($this->TransportMsg);
            }
            $this->TransportUI->baseLink($this->getFullBaseLink());
            $Html .= $this->TransportUI->getHtml();
        }

        return $Html;
    }

    /**
     * Set total count of items.  If not specified, the number of items
     * supplied will be assumed to be the total number of items.
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
     * @return void
     * @deprecated
     */
    public function display(
        array $Items,
        ?int $TotalItemCount = null,
        ?int $StartingIndex = null,
        ?string $TransportMsg = null
    ): void {
        if ($TotalItemCount !== null) {
            $this->setTotalItemCount($TotalItemCount);
        }
        if ($StartingIndex !== null) {
            $this->setStartingIndex($StartingIndex);
        }
        if ($TransportMsg !== null) {
            $this->setTransportControlsMessage($TransportMsg);
        }
        print $this->getHtml($Items);
    }


    # ---- PRIVATE INTERFACE -------------------------------------------------

    private $Actions = [];
    private $BaseLink;
    private $Buttons = [];
    private $DisplayEmptyTable = false;
    private $Fields;
    private $Heading = "";
    private $ItemsPerPage = 25;
    private $NoItemsMsg;
    private $SortableByDefault = true;
    private $StartingIndex = null;
    private $Subheading = "";
    private $TotalItemCount = null;
    private $TransportMsg = null;
    private $TransportUI;
    private $VariablesToPreserve = [];

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
            if (isset($Info["Tooltip"]) && ($Info["Tooltip"] !== null)) {
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
     * Generate HTML for table row.
     * @param string $ItemId ID for item for row.
     * @param Item|array $Item Object or array of data for row.
     * @return string Generated HTML.
     */
    private function getRowHtml($ItemId, $Item)
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
        } elseif (is_string($FieldId) && !is_numeric($FieldId)) {
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
            $Id = $this->TransportUI->id() ? $this->TransportUI->Id() : "";
            $SortLink = $this->getFullBaseLink()."&amp;SF".$Id."=".urlencode($FieldId)
                    .$this->TransportUI->urlParameterString(true, ["SF".$Id, "RS".$Id]);

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
                    $SortLink .= "&amp;RS".$Id."=1";
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
            } elseif (is_object($Item) && method_exists($Item, "UserCanEdit")) {
                $DisplayButton = $Item->UserCanEdit($User);
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
}
