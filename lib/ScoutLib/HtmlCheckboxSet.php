<?PHP
#
#   FILE:  HtmlCheckboxSet.php
#
#   Part of the ScoutLib application support library
#   Copyright 2019-2021 Edward Almasy and Internet Scout Research Group
#   http://scout.wisc.edu
#
# @scout:phpstan

namespace ScoutLib;

/**
 * Convenience class for generating a set of HTML checkbox form elements.
 */
class HtmlCheckboxSet extends HtmlInputSet
{

    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Get HTML for set.
     * @return string Generated HTML.
     */
    public function getHtml()
    {
        # if there were no options, return nothing
        if (count($this->Options) == 0) {
            return "";
        }

        $Html = "<div class='mv-inputset mv-checkboxset' "
            ."data-num-rows='".$this->numRows()."' "
            ."style='grid-template-columns: repeat(".$this->numRows().", 1fr)'>";
        foreach ($this->Options as $Value => $Label) {
            $Html .= "<div class='mv-inputset-item mv-checkboxset-item'>"
                .$this->generateInput("checkbox", $Value, $Label, true)
                ."</div>";
        }
        $Html .= "</div>";

        # return generated HTML to caller
        return $Html;
    }

    /**
     * Determine the number of columns for a checkbox grid based on the length
     *   of the longest option value.
     * @return int Number of columns.
     */
    private function numColumns() : int
    {
        # determine the length of the longest value in order to determine how many
        # options will be displayed per row
        $MaxValueLength = max(array_map("strlen", $this->Options));

        # determine how many values per row based on length of longest value
        if ($MaxValueLength > 25) {
            $NumColumns = 2;
        } elseif ($MaxValueLength > 17) {
            $NumColumns = 3;
        } elseif ($MaxValueLength > 12) {
            $NumColumns = 4;
        } else {
            $NumColumns = 5;
        }

        # if we have fewer items than columns, just put everything on a single
        # row by specifying a single repeating column
        if (count($this->Options) < $NumColumns) {
            return 1;
        }

        return $NumColumns;
    }

    /**
     * Determine the number of rows for a checkbox grid based on the number of
     * columns.
     * @return int Number of rows.
     */
    private function numRows() : int
    {
        return (int)ceil(count($this->Options) / $this->numColumns());
    }
}
