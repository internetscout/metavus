<?PHP
#
#   FILE:  HtmlOptionListSet.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2019-2020 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus;

use Exception;
use InvalidArgumentException;

/**
 * Convenience class for generating an HTML select/option form element.
 */
class HtmlOptionListSet extends \ScoutLib\HtmlOptionList
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
        parent::__construct(
            $ResultVar."[]",
            [ null => "--" ] + $Options,
            $SelectedValue
        );
    }

    /**
     * Get HTML for list.
     * @return string Generated HTML.
     */
    public function getHtml(): string
    {
        $GLOBALS["AF"]->RequireUIFile("HtmlOptionListSet.js");

        # save original selected values
        $OrigSetting = $this->SelectedValue;

        # track how many lists we've shown
        $ListsShown = 0;

        # start out with empty HTML
        $Html = "<div class='mv-optionlistset mv-mutable-widget' "
            ." data-dynamic='".($this->dynamic ? "true" : "false")."' "
            ." data-minlists='".$this->minLists."' "
            ." data-maxlists='".$this->maxLists."'>";

        # if we have any current settings
        if ($OrigSetting !== null) {
            $SelectedValues = is_array($OrigSetting) ?
                $OrigSetting : [ $OrigSetting ];

            # show one list for each setting
            foreach ($SelectedValues as $Value) {
                $this->SelectedValue = $Value;
                $Html .= parent::getHtml();
                $ListsShown++;
            }
        }

        # add any necessary additional lists
        if ($this->dynamic || $ListsShown < $this->minLists) {
            $this->SelectedValue = null;
            do {
                $Html .= parent::getHtml();
                $ListsShown++;
            } while ($ListsShown < $this->minLists);
        }

        $Html .= "</div>";

        # restore original selected values
        $this->SelectedValue = $OrigSetting;

        # return generated HTML to caller
        return $Html;
    }

    /**
     * Get/set whether multiple items may be selected. Provided for
     * compatibility with parent class, but multiple options are always
     * supported for HtmlOptionListSets.
     * @param bool $NewValue If TRUE, users will be able to select multiple
     *       items. (OPTIONAL)
     * @return bool TRUE if users can select multiple items, otherwise FALSE.
     * @throws Exception if NewValue set to false.
     */
    public function multipleAllowed(bool $NewValue = null): bool
    {
        if ($NewValue === false) {
            throw new Exception(
                "Multiple values are always allowd for an HtmlOptionListSet"
            );
        }

        return true;
    }

    /**
     * Get/set whether to submit the form when the list value is changed.
     * Provided for compatibility with parent class, but no onChange actions
     * are supported for HtmlOptionListSets.
     * @param bool $NewValue If TRUE, form will be submitted on
     *       change. (OPTIONAL)
     * @return bool TRUE if form will be submitted, otherwise FALSE.
     * @see HtmlOptionList::OnChangeAction()
     * @throws Exception if NewValue set to true.
     */
    public function submitOnChange(bool $NewValue = null): bool
    {
        if ($NewValue === true) {
            throw new Exception(
                "HtmlOptionListSet does not support submitOnChange"
            );
        }
        return false;
    }

    /**
     * Get/set action to take if form is submitted on change. Provided for
     *   compatibility with parent class, but no onChange actions are
     *   supported for HtmlOptionListSets.
     * @param string $NewValue New action.  (OPTIONAL)
     * @return string Current action.
     * @see HtmlOptionList::submitOnChange()
     */
    public function onChangeAction(string $NewValue = null): string
    {
        if ($NewValue !== null) {
            throw new Exception(
                "HtmlOptionListSet dos not support onChangeaction"
            );
        }
        return "";
    }

    /**
     * Get/set whether lists will be dynamically added and removed as
     * selections are made. Defaults to TRUE.
     * @param bool $NewValue New setting (OPTIONAL).
     * @return bool TRUE if list is dynamic.
     */
    public function dynamic(bool $NewValue = null) : bool
    {
        if ($NewValue !== null) {
            $this->dynamic = $NewValue;
        }
        return $this->dynamic;
    }

    /**
     * Get/set minimum number of option lists in this element.
     * @param int $NewValue New setting (OPTIONAL)
     * @return int Current minLists value.
     */
    public function minLists(int $NewValue = null) : int
    {
        if ($NewValue !== null) {
            if ($NewValue < 1) {
                throw new InvalidArgumentException(
                    "HtmlOptionListSet must contain at least one OptionList"
                );
            }

            if (is_array($this->SelectedValue) &&
                $NewValue < count($this->SelectedValue)) {
                throw new InvalidArgumentException(
                    "minLists cannot be less than the number of currently selected values."
                );
            }

            $this->minLists = $NewValue;
        }
        return $this->minLists;
    }

    /**
     * Get/set maximum number of option lists in this element.
     * @param int $NewValue New setting (OPTIONAL)
     * @return int Current maxLists value
     */
    public function maxLists(int $NewValue = null) : int
    {
        if ($NewValue !== null) {
            if ($NewValue < $this->minLists) {
                throw new Exception(
                    "maxLists cannot be less than minLists"
                );
            }
            $this->maxLists = $NewValue;
        }
        return $this->maxLists;
    }

    protected $dynamic = true;
    protected $minLists = 1;
    protected $maxLists = PHP_INT_MAX;
}
