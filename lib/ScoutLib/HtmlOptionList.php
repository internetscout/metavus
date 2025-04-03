<?PHP
#
#   FILE:  HtmlOptionList.php
#
#   Part of the ScoutLib application support library
#   Copyright 2014-2025 Edward Almasy and Internet Scout Research Group
#   http://scout.wisc.edu
#
# @scout:phpstan

namespace ScoutLib;

/**
 * Convenience class for generating an HTML select/option form element.
 */
class HtmlOptionList
{

    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Class constructor.
     * @param string $ResultVar Name of form variable for select element.
     * @param array $Options Array of options, with form values for the
     *       array index and labels for the array values.  For grouping,
     *       any of the options may actually be an array of options, with a
     *       group label for the index, and the array for the value.
     * @param mixed $SelectedValue Currently selected form value or array
     *       of currently selected form values.  (OPTIONAL)
     */
    public function __construct(string $ResultVar, $Options, $SelectedValue = null)
    {
        $this->ResultVar = $ResultVar;
        $this->Options = $Options;
        $this->SelectedValue = $SelectedValue;
    }

    /**
     * Print HTML for list.
     */
    public function printHtml(): void
    {
        print $this->getHtml();
    }

    /**
     * Get HTML for list.
     * @return string Generated HTML.
     */
    public function getHtml(): string
    {
        # start out with empty HTML
        $Html = "";

        # if there are options or we are supposed to print even if no options
        if (count($this->Options) || $this->PrintIfEmpty) {
            # begin select element
            $Html .= $this->getSelectOpenTag();

            # for each option
            foreach ($this->Options as $Value => $Label) {
                # if option is actually a group of options
                if (is_array($Label)) {
                    # add group start tag
                    $Html .= "    <optgroup label=\""
                            .htmlspecialchars($Value)."\">\n";

                    # for each option in group
                    foreach ($Label as $GValue => $GLabel) {
                        # add tag for option
                        $Html .= $this->getOptionTag($GValue, $GLabel);
                    }

                    # add group end tag
                    $Html .= "    </optgroup>\n";
                } else {
                    $Html .= $this->getOptionTag($Value, $Label);
                }
            }

            # end select element
            $Html .= '</select>';
        }

        # return generated HTML to caller
        return $Html;
    }

    /**
     * Get/set disabled options.
     * @param mixed $Options Option or array of options to disable.  If
     *       a single option then it should be the value and will be added
     *       to any existing disabled options, and if an array it should have
     *       the values for the index and will replace the current list of
     *       disabled options.  (OPTIONAL)
     * @return array Current set of disabled options.
     */
    public function disabledOptions($Options = null): array
    {
        if ($Options !== null) {
            if (is_array($Options)) {
                $this->DisabledOptions = $Options;
            } else {
                $this->DisabledOptions[$Options] = "X";
            }
        }
        return $this->DisabledOptions;
    }

    /**
     * Get/set currently selected value or array of currently selected values.
     * @param mixed $NewValue Currently selected form value or array
     *       of currently selected form values.  (OPTIONAL)
     * @return mixed Selected value or array of currently selected values.
     */
    public function selectedValue($NewValue = null)
    {
        if ($NewValue !== null) {
            $this->SelectedValue = $NewValue;
        }
        return $this->SelectedValue;
    }

    /**
     * Get/set the list size (number of visible items).  Defaults to 1.
     * @param int $NewValue Current size.  (OPTIONAL)
     * @return int Current size.
     */
    public function size(?int $NewValue = null): int
    {
        if ($NewValue !== null) {
            $this->Size = intval($NewValue);
        }
        return $this->Size;
    }

    /**
     * Get/set whether multiple items may be selected.  Defaults to FALSE.
     * @param bool $NewValue If TRUE, users will be able to select multiple
     *       items. (OPTIONAL)
     * @return bool TRUE if users can select multiple items, otherwise FALSE.
     */
    public function multipleAllowed(?bool $NewValue = null): bool
    {
        if ($NewValue !== null) {
            $this->MultipleAllowed = $NewValue ? true : false;

            # adjust form field name (result variable) if needed
            if ($this->MultipleAllowed
                    && (substr($this->ResultVar, -2) != "[]")) {
                $this->ResultVar .= "[]";
            } elseif (!$this->MultipleAllowed
                    && (substr($this->ResultVar, -2) == "[]")) {
                $this->ResultVar = substr($this->ResultVar, 0, -2);
            }
        }
        return $this->MultipleAllowed;
    }

    /**
     * Get/set whether to submit the form when the list value is changed.
     * Defaults to FALSE.
     * @param bool $NewValue If TRUE, form will be submitted on
     *       change. (OPTIONAL)
     * @return bool TRUE if form will be submitted, otherwise FALSE.
     * @see HtmlOptionList::OnChangeAction()
     */
    public function submitOnChange(?bool $NewValue = null): bool
    {
        if ($NewValue !== null) {
            $this->SubmitOnChange = $NewValue ? true : false;
        }
        return $this->SubmitOnChange;
    }

    /**
     * Get/set action to take if form is submitted on change.  Defaults
     * to "submit()" (without the quotes).  No character escaping or other
     * processing is done to this value before it is added to the HTML, so
     * whatever is passed in must be pre-sanitized if needed, including
     * escaping any double quotation marks.  This setting has no effect if
     * submitOnChange() is set to FALSE.
     * @param string $NewValue New action.  (OPTIONAL)
     * @return string Current action.
     * @see HtmlOptionList::submitOnChange()
     */
    public function onChangeAction(?string $NewValue = null): string
    {
        if ($NewValue !== null) {
            $this->OnChangeAction = $NewValue;
        }
        return $this->OnChangeAction;
    }

    /**
     * Get/set whether list should be output even if there are no items.
     * If this is set to FALSE and there are no items in the list, getHtml()
     * will return an empty string and printHtml() will print nothing.
     * Defaults to TRUE.
     * @param bool $NewValue If TRUE, HTML will be returned/printed even if
     *       there are no items in the list.  (OPTIONAL)
     * @return bool TRUE if empty list will be printed, otherwise FALSE.
     */
    public function printIfEmpty(?bool $NewValue = null): bool
    {
        if ($NewValue !== null) {
            $this->PrintIfEmpty = $NewValue ? true : false;
        }
        return $this->PrintIfEmpty;
    }

    /**
     * Get/set whether the whole option list is editable.  NOTE: When the
     * list is not editable, values for it are not submitted with the form.
     * This is distinct from whether individual options are disabled.
     * @param bool $NewValue If TRUE, list is not editable.
     * @return bool TRUE if list will not be editabled, otherwise FALSE.
     */
    public function disabled(?bool $NewValue = null): bool
    {
        if ($NewValue !== null) {
            $this->Disabled = $NewValue ? true : false;
        }
        return $this->Disabled;
    }

    /**
     * Get/set CSS class(es) for the list.
     * @param string $NewValue String with class names, separated by spaces.
     * @return string|null Current classes, or NULL if no classes have been set.
     */
    public function classForList(?string $NewValue = null)
    {
        if ($NewValue !== null) {
            $this->ListClasses = $NewValue;
        }
        return $this->ListClasses;
    }

    /**
     * Get/set CSS class(es) for the options.  If separate classes per option
     * are supplied as an array, they do not have to be in the same order as
     * the options originally supplied to the constructor, and not all options
     * must be included.
     * @param mixed $NewValue String with class names, separated by spaces, or
     *       array of class name strings, indexed by option value.
     * @return mixed String with class names, separated by spaces, or array of
     *       class name strings, indexed by option value, or NULL if no option
     *       classes have been set.
     */
    public function classForOptions($NewValue = null)
    {
        if ($NewValue !== null) {
            $this->OptionClasses = $NewValue;
        }
        return $this->OptionClasses;
    }

    /**
     * Get/set HTML data attributes for the options.
     * @param array $NewValue Two-dimensional array of data attribute
     * values, first dimension keyed by option value, second keyed by data
     * attribute name (e.g., 'field-id' for a 'data-field-id'
     * attribute).
     * @return array Current data attributes (empty if none currently set).
     */
    public function dataForOptions($NewValue = null): array
    {
        if ($NewValue !== null) {
            $this->OptionData = $NewValue;
        }
        return $this->OptionData;
    }

    /**
     * Get/set the maximum number of character a label will be displayed.
     * If a label exceeds the limit, the extra characters will be taken off.
     * @param int $NewValue Maximum number of characters a label will be
     *       displayed (OPTIONAL, defaults to no limit). If zero is passed
     *       in, limit will be reset to none.
     * @return int Current maximum label length, or zero if there is no limit.
     */
    public function maxLabelLength(?int $NewValue = null): int
    {
        if ($NewValue !== null) {
            $this->MaxLabelLength = $NewValue;
        }
        return $this->MaxLabelLength;
    }

    /**
     * Add an attribute for the <select> tag for the list.  If this is called
     * multiple times for the same attribute name, only the value from the last
     * call will be used.
     * @param string $Name Attribute name.
     * @param string $Value Attribute value (should not be escaped).
     */
    public function addAttribute(string $Name, string $Value): void
    {
        $this->AdditionalAttributes[$Name] = $Value;
    }


    # ---- PRIVATE INTERFACE -------------------------------------------------

    protected $AdditionalAttributes = array();
    protected $Disabled = false;
    protected $DisabledOptions = array();
    protected $ListClasses = null;
    protected $MaxLabelLength = 0;
    protected $MultipleAllowed = false;
    protected $OnChangeAction = "submit()";
    protected $Options;
    protected $OptionClasses = null;
    protected $OptionData = array();
    protected $PrintIfEmpty = true;
    protected $ResultVar;
    protected $SelectedValue;
    protected $Size = 1;
    protected $SubmitOnChange = false;

    /**
     * Get HTML for one option.
     * @param string $Value Value for option.
     * @param string $Label Label for option.
     * @return string HTML for option.
     */
    protected function getOptionTag(string $Value, string $Label): string
    {
        # start option element
        $Html = '    <option value="'.htmlspecialchars($Value).'"';

        # add in selected attribute if appropriate
        if ((is_array($this->SelectedValue)
                        && in_array($Value, $this->SelectedValue))
                || ($Value == $this->SelectedValue)) {
            $Html .= ' selected';
        }

        # add in disabled attribute if appropriate
        if (array_key_exists($Value, $this->DisabledOptions)) {
            $Html .= ' disabled';
        }

        # add in class if requested
        if ($this->OptionClasses) {
            if (is_array($this->OptionClasses)) {
                if (isset($this->OptionClasses[$Value])) {
                    $Html .= ' class="'
                            .htmlspecialchars($this->OptionClasses[$Value]).'"';
                }
            } else {
                $Html .= ' class="'
                        .htmlspecialchars($this->OptionClasses).'"';
            }
        }

        # add in data attributes if requested
        if (isset($this->OptionData[$Value])) {
            foreach ($this->OptionData[$Value] as $DName => $DVal) {
                $DName = preg_replace('/[^a-z0-9-]/', '', strtolower($DName));
                $Html .= ' data-'.$DName.'="'.htmlspecialchars($DVal).'"';
            }
        }

        # truncate label to max length if specified
        if ($this->MaxLabelLength > 0) {
            $Label = substr($Label, 0, $this->MaxLabelLength);
        }

        # add label and end option element
        $Html .= ">".htmlspecialchars($Label)."</option>\n";

        return $Html;
    }

    /**
     * Get HTML for tag to begin list.
     * @return string HTML for select open tag.
     */
    protected function getSelectOpenTag(): string
    {
        $Html = '<select name="'.$this->ResultVar.'"'
                .' size="'.$this->Size.'"'
                .' id="'.$this->ResultVar.'"';

        if ($this->ListClasses) {
            $Html .= ' class="'.htmlspecialchars($this->ListClasses).'"';
        }

        if ($this->SubmitOnChange) {
            if ($this->OnChangeAction) {
                $Html .= ' onChange="'.$this->OnChangeAction.'"';
            } else {
                $Html .= ' onChange="submit()"';
            }
        }

        if ($this->MultipleAllowed) {
            $Html .= ' multiple';
        }

        if ($this->Disabled) {
            $Html .= ' disabled';
        }

        foreach ($this->AdditionalAttributes as $Name => $Value) {
            $Html .= " ".$Name."=\"".htmlspecialchars($Value)."\"";
        }

        $Html .= ">\n";

        return $Html;
    }
}
