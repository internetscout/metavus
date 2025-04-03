<?PHP
#
#   FILE:  HtmlInputSet.php
#
#   Part of the ScoutLib application support library
#   Copyright 2017-2025 Edward Almasy and Internet Scout Research Group
#   http://scout.wisc.edu
#
# @scout:phpstan

namespace ScoutLib;

/**
 * Base class for classes generating HTML for a set of input form elements.
 */
abstract class HtmlInputSet
{

    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Class constructor.
     * @param string $ResultVar Name of form variable for select element.
     * @param array $Options Array of options, with form values for the
     *       array index and labels for the array values.
     * @param mixed $SelectedValue Currently selected form value or array
     *       of currently selected form values.  (OPTIONAL)
     */
    public function __construct(
        string $ResultVar,
        array $Options,
        $SelectedValue = null
    ) {
        $this->ResultVar = $ResultVar;
        $this->Options = $Options;
        $this->SelectedValue = $SelectedValue;
    }

    /**
     * Print HTML for set.
     */
    public function printHtml(): void
    {
        print $this->getHtml();
    }

    /**
     * Get HTML for set.
     * @return string Generated HTML.
     */
    abstract public function getHtml();

    /**
     * Get/set disabled options.
     * @param mixed $Options Option or array of options to disable.  If
     *       a single option then it should be the value and will be added
     *       to any existing disabled options, and if an array it should have
     *       the values for the index and will replace the current list of
     *       disabled options.  (OPTIONAL)
     * @return array Current disabled options.
     */
    public function disabledOptions($Options = null)
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
     * Get/set whether the whole option list is editable.
     * @param bool $NewValue If TRUE, list is not editable
     * (i.e. all options are disabled).
     * @return bool TRUE if list will not be editabled, otherwise FALSE.
     */
    public function disabled(?bool $NewValue = null)
    {
        if ($NewValue !== null) {
            $this->Disabled = $NewValue ? true : false;
        }
        return $this->Disabled;
    }

    # ---- PRIVATE INTERFACE -------------------------------------------------

    /**
     * Generate an HTML input of a specified type.
     * @param string $Type Input type to generate
     * @param string $Value Value for this input
     * @param string $Label Label for this input
     * @param bool $AllowMultiple TRUE for inputs that allow multiple
     *   selections, FALSE otherwise
     * @return string Generated HTML
     */
    protected function generateInput(
        string $Type,
        string $Value,
        string $Label,
        bool $AllowMultiple
    ) : string {
        # generate ID and name for elements
        $Id = $this->ResultVar."_"
            .preg_replace("%[^a-z0-9]%i", "", $Value);
        $Name = $this->ResultVar.($AllowMultiple ? "[]" : "");

        # start input element
        $Html = "<input type=\"".$Type."\""
            ." id=\"".htmlspecialchars($Id)."\""
            ." name=\"".htmlspecialchars($Name)."\""
            ." value=\"".htmlspecialchars($Value)."\"";

        # add in selected attribute if appropriate
        if ((is_array($this->SelectedValue)
             && in_array($Value, $this->SelectedValue))
            || ($Value == $this->SelectedValue)) {
            $Html .= " checked";
        }

        # add in disabled attribute if appropriate
        if ($this->Disabled
            || array_key_exists($Value, $this->DisabledOptions)) {
            $Html .= " disabled";
        }

        # end input element
        $Html .= ">";

        # add label
        $Html .= " <label for=\"".$Id."\">"
            .htmlspecialchars($Label)."</label>";

        return $Html;
    }

    protected $Options;
    protected $ResultVar;
    protected $Disabled = false;
    protected $DisabledOptions = array();
    protected $SelectedValue;
}
