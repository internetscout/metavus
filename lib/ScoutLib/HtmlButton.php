<?PHP
#
#   FILE:  HtmlButton.php
#
#   Part of the ScoutLib application support library
#   Copyright 2023-2025 Edward Almasy and Internet Scout Research Group
#   http://scout.wisc.edu
#
# @scout:phpstan

namespace ScoutLib;

/**
 * Base for convenience class for generating HTML for a standard button.
 * Child classes must at minimum implement a getHtml() method that generates
 * and returns the HTML to display the button.
 */
abstract class HtmlButton
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    const SIZE_SMALL = "small";
    const SIZE_MEDIUM = "medium";   # (default size)
    const SIZE_LARGE = "large";

    /**
     * Class constructor.
     * @param string $Label Label for button.
     */
    public function __construct(string $Label)
    {
        $this->Label = $Label;
    }

    /**
     * Set button label.
     * @param string $Label Updated label for button.
     */
    public function setLabel(string $Label): void
    {
        $this->Label = $Label;
    }

    /**
     * Make the label display as raw HTML.
     */
    public function makeHtmlLabel(): void
    {
        $this->HtmlLabel = true;
    }

    /**
     * Set button name (i.e. value of "name" attribute).  If no name
     * is set, then no "name" attribute will be included, unless the
     * button is marked as a submit button, in which case if no name has
     * been set then the button will be given a "name" attribute with a
     * value of "Submit".
     * @param string $Name Name for button.
     */
    public function setName(string $Name): void
    {
        $this->Name = $Name;
    }

    /**
     * Set URL to go to when button is pushed.
     * @param string $Link URL to go to.
     */
    public function setLink(string $Link): void
    {
        $this->Link = $Link;
    }

    /**
     * Make a link button open in a new tab.
     */
    public function makeOpenNewTab(): void
    {
        $this->ShouldOpenNewTab = true;
    }

    /**
     * Add icon to button.
     * @param string $IconFileName Name of image file containing icon.
     */
    public function setIcon(string $IconFileName): void
    {
        $this->IconFileName = $IconFileName;
    }

    /**
     * Add value to button.
     * @param string $Value Value for the button.
     */
    public function setValue(string $Value): void
    {
        $this->Value = $Value;
    }

    /**
     * Add ID to button.
     * @param string $Id ID for the button.
     */
    public function setId(string $Id): void
    {
        $this->Id = $Id;
    }

    /**
     * Add aria-label to button to provide additional information about its
     * purpose and functionality to assistive technologies, such as screen
     * readers.
     * @see https://www.w3.org/TR/WCAG20-TECHS/ARIA6.html
     * @param string $Label The aria-label for the button.
     */
    public function setAriaLabel(string $Label): void
    {
        $this->AriaLabel = $Label;
    }

    /**
     * Add attributes to button. Any attributes added this way will override
     * attributes set by code and any attributes previously set with the same
     * key.
     * @param array $Attributes Array of data attributes to add, with keys as
     *      attribute names and values as their values.
     */
    public function addAttributes(array $Attributes): void
    {
        $this->CustomAttributes = array_merge($this->CustomAttributes, $Attributes);
    }

    /**
     * Set size of button.  Buttons will be SIZE_MEDIUM if no size is set.
     * @param string $Size
     */
    public function setSize(string $Size): void
    {
        $this->Size = $Size;
    }

    /**
     * Set value for "onclick" attribute.
     * @param string $Value Value for attribute.
     */
    public function setOnclick(string $Value): void
    {
        $this->OnclickValue = $Value;
    }

    /**
     * Set value for "title" attribute.
     * @param string $Value Value for attribute.
     */
    public function setTitle(string $Value): void
    {
        $this->Title = $Value;
    }

    /**
     * Add specific semantic class (e.g. "btn-danger") for button.
     * @param string $Class Semantic class to use.
     */
    public function addSemanticClass(string $Class): void
    {
        $this->SemanticClass = $Class;
    }

    /**
     * Add additional CSS class(es) to button.
     * @param string $Class Class(es) to add.  Multiple class should be
     *      separated by spaces.
     */
    public function addClass(string $Class): void
    {
        $this->AdditionalClasses .= " ".$Class;
    }

    /**
     * Make into submit button for form.  PLEASE NOTE: If a button does not
     * have a link or onClick value set, it will automatically be a submit
     * button.  Correspondingly, attempting to make a button a submit button
     * via this method will throw an exception at HTML generation time if the
     * button also has a link or onClick value set.
     */
    public function makeSubmitButton(): void
    {
        $this->IsSubmitButton = true;
    }

    /**
     * Make button disabled (but still displayed).
     */
    public function disable(): void
    {
        $this->IsDisabled = true;
    }

    /**
     * Hide button with style.
     */
    public function hide(): void
    {
        $this->Hide = true;
    }

    /**
     * Generate and return HTML to display button.
     * @return string HTML for button.
     */
    abstract public function getHtml(): string;


    # ---- PRIVATE INTERFACE -------------------------------------------------

    protected $AdditionalClasses = "";
    protected $AriaLabel;
    protected $CustomAttributes = [];
    protected $Hide = false;
    protected $HtmlLabel = false;
    protected $IconFileName;
    protected $Id;
    protected $IsDisabled = false;
    protected $IsSubmitButton = false;
    protected $Label;
    protected $Link = null;
    protected $Name = "";
    protected $OnclickValue = "";
    protected $SemanticClass;
    protected $ShouldOpenNewTab = false;
    protected $Size = self::SIZE_MEDIUM;
    protected $Title = "";
    protected $Value;

    /**
     * Assemble HTML element from supplied components.  Attributes may
     * be added without values by setting their incoming value to NULL.
     * @param string $Tag Element tag.
     * @param ?string $Content Element content.  (Content will NOT be
     *      encoded before use.)  [OPTIONAL]
     * @param array $Attribs Element attributes.  (Values WILL be
     *      encoded before use.)  [OPTIONAL]
     * @param array $RawAttribs Element attributes to use unchanged.
     *      (Values will NOT be encoded before use.)  [OPTIONAL]
     * @return string Assembled HTML code.
     */
    protected static function assembleHtmlElement(
        string $Tag,
        ?string $Content = null,
        array $Attribs = [],
        array $RawAttribs = []
    ): string {
        # assemble attribute string from incoming array
        $AttribString = "";
        array_walk(
            $Attribs,
            function ($Value, $Key) use (&$AttribString) {
                $AttribString .= " ".$Key
                        .(($Value !== null) ? "=\"".htmlspecialchars($Value)."\"" : "");
            }
        );

        # add in any supplied raw attributes
        array_walk(
            $RawAttribs,
            function ($Value, $Key) use (&$AttribString) {
                $AttribString .= " ".$Key
                        .(($Value !== null) ? "=\"".$Value."\"" : "");
            }
        );

        # add leading space to attribute string if there were attributes
        $AttribString = strlen($AttribString) ? " ".$AttribString : "";

        # assemble element with content and return HTML to caller
        $Html = "<".$Tag.$AttribString;
        if ($Content !== null) {
            $Html .= ">".$Content."</".$Tag.">";
        } else {
            $Html .= "/>";
        }
        return $Html;
    }
}
