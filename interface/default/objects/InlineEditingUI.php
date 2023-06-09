<?PHP
#
#   FILE:  InlineEditingUI.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2019-2021 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus;

/**
 * Provide a div that can be edited in place on the page.
 */
class InlineEditingUI
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Constructor.
     * @param string $UpdateUrl Url to which content changes will be posted
     * via AJAX. Updated content will be provided in $_POST["Content"]. The
     * reply should be a json object. On success, it should be
     *   {"status": "OK", "content": "Updated HTML for display goes"}
     #   An "updates" array can also be included, where each element is
     #     {"selector" : "SelectorForJQuery", "html": "NewContentForThatSelector" }
     #   This can be used to update other elements on the page in addition to
     #     the edited content (e.g., last modification times)
     * On failure, it should be
     *   {"status": "error", "message" : "A description of what went wrong"}
     */
    public function __construct(string $UpdateUrl)
    {
        $this->UpdateUrl = $UpdateUrl;
        $this->EditorNumber = self::$NextEditorNumber;

        self::$NextEditorNumber++;
    }

    /**
     * Get/set the HTML that will be displayed in viewing mode, after any
     * necessary post-processing has been done to it (e.g., for Keyword
     * replacements)
     * @param string $NewValue Updated value
     * @return string Display data
     */
    public function htmlToDisplay(string $NewValue = null) : string
    {
        if (!is_null($NewValue)) {
            $this->DisplayData = $NewValue;
        }

        return $this->DisplayData;
    }

    /**
     * Get/set the source data that will be displayed in edit mode. No keywords
     * will be expanded. Any processing (e.g. via TabbedContentUI should be
     * omitted for this data).
     * @param string $NewValue Updated value
     * @return string Source data
     */
    public function sourceData(string $NewValue = null) : string
    {
        if (!is_null($NewValue)) {
            $this->SourceData = $NewValue;
        }

        return $this->SourceData;
    }

    /**
     * Get/set CSS selectors that should be shown after editing is
     * started, with selectors for other modes automatically hidden.
     * @param array $Selectors Selectors in jQuery format.
     * @return array Current list of selectors.
     */
    public function onEditShowSelectors(array $Selectors = null)
    {
        return $this->JSControlsSelectorsForEvent("onedit", $Selectors);
    }

    /**
     * Get/set CSS selectors that should be shown after content is
     * modified, with selectors for other modes automatically hidden.
     * @param array $Selectors Selectors in jQuery format.
     * @return array Current list of selectors.
     */
    public function onChangeShowSelectors(array $Selectors = null)
    {
        return $this->JSControlsSelectorsForEvent("onchange", $Selectors);
    }

    /**
     * Get/set CSS selectors that should be shown after changes are
     * discarded, with selectors for other modes automatically hidden.
     * @param array $Selectors Selectors in jQuery format.
     * @return array Current list of selectors.
     */
    public function onDiscardShowSelectors(array $Selectors = null)
    {
        return $this->JSControlsSelectorsForEvent("ondiscard", $Selectors);
    }

    /**
     * Get/set CSS selectors that should be shown after editing is
     * canceled, with selectors for other modes automatically hidden.
     * @param array $Selectors Selectors in jQuery format.
     * @return array Current list of selectors.
     */
    public function onCancelShowSelectors(array $Selectors = null)
    {
        return $this->JSControlsSelectorsForEvent("oncancel", $Selectors);
    }

    /**
     * Get/set CSS selectors that should be shown after changes are
     * saved, with selectors for other modes automatically hidden.
     * @param array $Selectors Selectors in jQuery format.
     * @return array Current list of selectors.
     */
    public function onSaveShowSelectors(array $Selectors = null)
    {
        return $this->JSControlsSelectorsForEvent("onsave", $Selectors);
    }

    /**
     * Get HTML for the editing controls (e.g., Save and Cancel buttons).
     */
    public function getEditingControlsHtml()
    {
        $LoadingImg = $GLOBALS["AF"]->GUIFile(self::LOADING_IMG_FILE_NAME);

        $Html = '<div class="mv-inline-edit-controls" '
            .'data-editor="'.$this->EditorNumber.'">';

        $Buttons = ["Edit", "Save Changes", "Cancel", "Discard"];
        foreach ($Buttons as $Button) {
            $Html .= $this->getButtonHtml($Button);
        }

        $Html .= '<span class="mv-loading" style="display: none">'
            .'<img src="'.$LoadingImg.'"></span></div>';

        return $Html;
    }

    /**
     * Get HTML for an inline editing widget.
     */
    public function getHtml()
    {
        require_once($GLOBALS["AF"]->gUIFile("CKEditorSetup.php"));
        $GLOBALS["AF"]->requireUIFile("InlineEditingUI.js");

        $Html = '<div class="mv-inline-edit-display" '
            .'data-editor="'.$this->EditorNumber.'">'
            .'<div class="mv-inline-edit-content">'.$this->htmlToDisplay().'</div>'
            .'</div>'
            .'<div class="mv-inline-edit-edit" data-editor="'.$this->EditorNumber.'" '
            .'data-updateurl="'.$this->UpdateUrl.'" '
            .'style="display: none; clear: both;">'
            .'<div class="mv-inline-edit-error alert alert-danger" style="display: none"></div>'
            .'<div id="mv-inline-'.$this->EditorNumber.'" contenteditable="true">'
            .$GLOBALS["AF"]->escapeInsertionKeywords($this->sourceData())
            .'</div>'
            .'</div>'
            .'<script type="text/javascript">'
            .'var inlineEditControlsSelectors = inlineEditControlsSelectors || []; '
            .'inlineEditControlsSelectors['.$this->EditorNumber.'] = '
            .json_encode($this->ControlsSelectors).'; '
            .'</script>';

        # if HTML for dialog boxes has not yet been generated, include that as
        # well
        if (!self::$DialogsGenerated) {
            $Html .= '<div id="mv-inline-edit-dialog-confirm" '
                .'title="Discard Changes?" style="display: none;">'
                .'<p>Once discarded, changes are lost and cannot be recovered.</p>'
                .'</div>';

            self::$DialogsGenerated = true;
        }

        return $Html;
    }

    /**
     * Print an inline editing widget.
     */
    public function display()
    {
        print $this->getHtml();
    }

    # ---- PRIVATE INTERFACE -------------------------------------------------

    private $DisplayData = "";
    private $SourceData = "";
    private $UpdateUrl;
    private $EditorNumber;

    private $ControlsSelectors = [
        "onedit" => [],
        "onchange" => [],
        "onsave" => [],
        "oncancel" => [],
        "ondiscard" => [],
    ];

    const LOADING_IMG_FILE_NAME = "loading.gif";
    const EDIT_IMG_FILE_NAME = "pencil.png";
    const SAVE_IMG_FILE_NAME = "accept.png";
    const DISCARD_IMG_FILE_NAME = "cross.png";

    private static $NextEditorNumber = 0;
    private static $DialogsGenerated = false;

    /**
     * Get/set CSS selectors indicating the controls that should be shown
     *   after a given editing event. Selectors configured for other editing
     *   events will first be hidden before selectors for the specified event
     *   are shown.
     * @param string $EventName Event to handle. Events are:
     *    'onedit' for when editing is started
     *    'onchange' for when a change is made to content
     *    'onsave' for when content is saved
     *    'oncancel' for when editing is canceled without any changes
     *    'ondiscard' for when changes are discarded.
     * @param array $Selectors New value to set (OPTIONAL)
     * @return array Current list of selectors for the specified event.
     */
    private function JSControlsSelectorsForEvent(string $EventName, array $Selectors = null)
    {
        if (!array_key_exists($EventName, $this->ControlsSelectors)) {
            throw new \Exception("Invalid event name provided: ".$EventName);
        }

        if (!is_null($Selectors)) {
            $this->ControlsSelectors[$EventName] = $Selectors;
        }

        return $this->ControlsSelectors[$EventName];
    }


    /**
     * Get HTML for a specified button.
     * @param string $ButtonName Name of the button to generate.
     * @return string Html for the button
     */
    private function getButtonHtml(string $ButtonName) : string
    {
        $CssClassSuffix = [
            "Edit" => "edit",
            "Save Changes" => "save",
            "Cancel" => "cancel",
            "Discard" => "discard",
        ];

        $Icons = [
            "Edit" => self::EDIT_IMG_FILE_NAME,
            "Save Changes" => self::SAVE_IMG_FILE_NAME,
            "Discard" => self::DISCARD_IMG_FILE_NAME,
        ];

        $IsHidden = ($ButtonName != "Edit") ? true : false;

        $Html = '<button ';

        if ($IsHidden) {
            $Html .= ' style="display: none;"';
        }

        $Html .= 'class="btn btn-primary btn-sm '
            .'mv-inline-edit-btn-'.$CssClassSuffix[$ButtonName];

        if (isset($Icons[$ButtonName])) {
            $Html .= ' mv-button-iconed">'
                .'<img src="'.$GLOBALS["AF"]->gUIFile($Icons[$ButtonName]).'" '
                .'class="mv-button-icon" alt="">';
        } else {
            $Html .= '">';
        }

        $Html .= $ButtonName.'</button>';

        return $Html;
    }
}
