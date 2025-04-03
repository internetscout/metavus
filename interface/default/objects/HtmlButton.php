<?PHP
#
#   FILE:  HtmlButton.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2023-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus;
use Exception;
use ScoutLib\ApplicationFramework;

/**
 * Convenience class for generating HTML for a standard button.
 */
class HtmlButton extends \ScoutLib\HtmlButton
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Generate and return HTML to display button.
     * @return string HTML for button.
     * @throws Exception If button size is invalid.
     * @throws Exception If marked as Submit button and link was set.
     * @throws Exception If marked as Submit button and onClick value was set.
     */
    public function getHtml(): string
    {
        # check to make sure we do not have a submit button with a link or onClick
        if ($this->IsSubmitButton) {
            if ($this->Link !== null) {
                throw new Exception("Submit button \"".$this->Label
                        ."\" also has a link set.");
            }
        }

        # determine whether this should be made into a submit button
        $MakeSubmitButton = $this->IsSubmitButton
                || (($this->Link === null) && ($this->OnclickValue === ""));

        # assemble HTML for icon if needed
        if ($this->IconFileName) {
            $AF = ApplicationFramework::getInstance();
            $IconAttribs = [
                "class" => "mv-button-icon",
                "src" => $AF->gUIFile($this->IconFileName),
            ];
            $IconHtml = $this->assembleHtmlElement("img", "", $IconAttribs)." ";
        } else {
            $IconHtml = "";
        }

        # assemble HTML for entire button and return it to caller
        $RawAttribs = [];
        $Attribs["class"] = $this->getCssClassString();
        $Content = $IconHtml . ($this->HtmlLabel ? $this->Label : htmlspecialchars($this->Label));
        if (strlen($this->Name)) {
            $Attribs["name"] = $this->Name;
        }
        if ($this->Link !== null) {
            $Tag = "a";
            $Attribs["href"] = $this->Link;
            if ($this->ShouldOpenNewTab) {
                $Attribs["target"] = "_blank";
            }
        } else {
            $Tag = "button";
            if ($MakeSubmitButton) {
                $Attribs["type"] = "submit";
                $Attribs["value"] = $this->Label;
                if (!isset($Attribs["name"])) {
                    $Attribs["name"] = "Submit";
                }
            } else {
                $Attribs["type"] = "button";
            }
            # disable button elements with attribute;
            # hyperlink elements are handled with "disabled" class instead
            if ($this->IsDisabled) {
                $Attribs["disabled"] = "";
            }
        }
        if ($this->Title !== "") {
            $Attribs["title"] = $this->Title;
        }
        if ($this->OnclickValue !== "") {
            $RawAttribs["onclick"] = $this->OnclickValue;
        }
        if (isset($this->Value)) {
            $Attribs["value"] = $this->Value;
        }
        if (isset($this->Id)) {
            $Attribs["id"] = $this->Id;
        }
        if (isset($this->AriaLabel)) {
            $Attribs["aria-label"] = $this->AriaLabel;
        }
        if ($this->Hide) {
            $Attribs["style"] = "display: none";
        }
        foreach ($this->CustomAttributes as $key => $value) {
            $Attribs[$key] = $value;
        }
        return self::assembleHtmlElement($Tag, $Content, $Attribs, $RawAttribs);
    }


    # ---- PRIVATE INTERFACE -------------------------------------------------

    /**
     * Get list of CSS classes for button.
     * @return string CSS class names.
     * @throws Exception If button size is invalid.
     */
    private function getCssClassString(): string
    {
        # start with button base class
        $Classes[] = "btn";

        # add semantic class
        $Classes[] = $this->SemanticClass ?? "btn-primary";

        # add class for button size if needed
        switch ($this->Size) {
            case self::SIZE_SMALL:
                $Classes[] = "btn-sm";
                break;

            case self::SIZE_MEDIUM:
                break;

            case self::SIZE_LARGE:
                $Classes[] = "btn-lg";
                break;

            default:
                throw new Exception("Invalid size (\"".$this->Size
                        ."\") for button \"".$this->Label."\".");
        }

        # add any additional classes requested
        $Classes[] = $this->AdditionalClasses;

        # add class for icon if needed
        if ($this->IconFileName) {
            $Classes[] = "mv-button-iconed";
        }

        # add disabled class for disabled hyperlink (anchor tag) buttons;
        # other buttons are disabled using "disabled" attribute
        if ($this->IsDisabled && $this->Link !== null) {
            $Classes[] = "disabled";
        }

        # assemble class into string and return it to caller
        return join(" ", $Classes);
    }
}
