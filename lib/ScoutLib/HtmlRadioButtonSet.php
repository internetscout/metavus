<?PHP
#
#   FILE:  HtmlRadioButtonSet.php
#
#   Part of the ScoutLib application support library
#   Copyright 2017-2021 Edward Almasy and Internet Scout Research Group
#   http://scout.wisc.edu
#
# @scout:phpstan

namespace ScoutLib;

/**
 * Convenience class for generating a set of HTML radio button form elements.
 */
class HtmlRadioButtonSet extends HtmlInputSet
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

        $Html = "<div class='mv-inputset mv-radiobuttonset'>";
        foreach ($this->Options as $Value => $Label) {
            $Html .= "<div class='mv-inputset-item mv-radiobuttonset-item'>"
                .$this->generateInput("radio", $Value, $Label, false)
                ."</div>";
        }
        $Html .= "</div>";

        # return generated HTML to caller
        return $Html;
    }
}
