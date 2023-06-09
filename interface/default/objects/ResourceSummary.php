<?PHP
#
#   FILE:  ResourceSummary.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2018-2023 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus;

use ScoutLib\StdLib;

/**
* Base class for resource summary display.
*/
abstract class ResourceSummary
{
    # ---- CONFIGURATION -----------------------------------------------------

    const ALLOWED_TAGS = "<b><i><u><sub><sup><strike><a>";

    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
    * Load resource summary of type appropriate to specific resource.
    * @param int $RecordId ID of record to summarize.
    * @return ResourceSummary New resource summary.
    */
    public static function create(int $RecordId)
    {
        $Resource = new Record($RecordId);
        $ResourceName = $Resource->getSchema()->resourceName();
        $ClassName = __CLASS__."_".str_replace(" ", "", $ResourceName);
        if (!class_exists($ClassName)) {
            $ClassName = __CLASS__."_Default";
        }

        // @phpstan-ignore-next-line
        return new $ClassName($RecordId);
    }

    /**
    * Constructor for class.
    * @param int $RecordId ID of record to summarize.
    */
    public function __construct(int $RecordId)
    {
        # if this->Resource has not yet been set by a child class's __construct(),
        # set it now
        if (is_null($this->Resource)) {
            $this->Resource = new Record($RecordId);
        }
        $this->Editable = $this->Resource->userCanEdit(User::getCurrentUser());
    }

    /**
    * Display (output HTML) for resource summary.
    */
    abstract public function display();

    /**
     * Display compact resource summary (by default, falling back to the
     * regular summary).
     */
    public function displayCompact()
    {
        static::display();
    }

    /**
    * Get/set whether resource should be marked as editable (usually
    * meaning whether the "Edit" button should be displayed).Default is
    * to display the button if the resource is editable by current user.
    * @param bool $NewValue TRUE to display button.(OPTIONAL)
    * @return bool TRUE if button will be displayed, otherwise FALSE.
    */
    public function editable(bool $NewValue = null)
    {
        if ($NewValue !== null) {
            $this->Editable = $NewValue;
        }
        return $this->Editable;
    }

    /**
    * Get/set whether to include resource type info in the summary.
    * (Useful when displaying resources of multiple types within the
    * same page or area.)  Defaults to FALSE.
    * @param bool $NewValue TRUE to display type.(OPTIONAL)
    * @return bool TRUE if type will be displayed, otherwise FALSE.
    */
    public function includeResourceType(bool $NewValue = null)
    {
        if ($NewValue !== null) {
            $this->IncludeResourceType = $NewValue;
        }
        return $this->IncludeResourceType;
    }

    /**
     * Get/set whether to display a screenshot in the summary.
     * Defaults to TRUE.
     * @param bool $NewValue TRUE to display screenshot (OPTIONAL)
     * @return bool TRUE if screenshot will be displayed, FALSE otherwise.
     */
    public function showScreenshot(bool $NewValue = null)
    {
        if ($NewValue !== null) {
            $this->ShowScreenshot = $NewValue;
        }
        return $this->ShowScreenshot;
    }

    /**
    * Get/set terms to highlight in summary.
    * @param array|string $NewValue Array or string containing terms.
    * @return array Terms to be highlighted.
    */
    public function termsToHighlight($NewValue = null)
    {
        if ($NewValue !== null) {
            if (!is_array($NewValue)) {
                $NewValue = preg_split(
                    "%[\s,|]+%",
                    $NewValue,
                    -1,
                    PREG_SPLIT_NO_EMPTY
                );
            }
            $this->TermsToHighlight = $NewValue;
        }
        return $this->TermsToHighlight;
    }

    /**
    * Get/set additional context to pass when signaling events.
    * @param array $NewValue Array of values, with value names for index.
    * @return array Array of additional context values, with value names
    *       (as previously supplied) for index.
    */
    public function additionalContext($NewValue = null)
    {
        if ($NewValue !== null) {
            $this->AdditionalContext = $NewValue;
        }
        return $this->AdditionalContext;
    }


    # ---- PRIVATE INTERFACE -------------------------------------------------

    protected $AdditionalContext = [];
    protected $Editable = false;
    protected $ShowScreenshot = true;
    protected $IncludeResourceType = false;
    protected $Resource = null;
    protected $TermsToHighlight = [];

    /**
    * Return value for field, routed through display filter event.
    * @param mixed $Field Field ID or full name of field or a Field object.
    * @return mixed Requested object(s) or value(s).Returns empty array
    *       (for field types that allow multiple values) or NULL (for field
    *       types that do not allow multiple values) if no values found.Returns
    *       NULL if field does not exist or was otherwise invalid.
    */
    protected function getFieldValue($Field)
    {
        $Value = $this->Resource->Get($Field, true);
        $SignalResult = $GLOBALS["AF"]->SignalEvent(
            "EVENT_FIELD_DISPLAY_FILTER",
            [
                "Field" => $Field,
                "Resource" => $this->Resource,
                "Value" => $Value
            ]
        );
        return $SignalResult["Value"];
    }

    /**
    * Get standard field value that has had any disallowed tags stripped, been
    * truncated to the specified length, and had any terms highligted.
    * @param string $FieldName Name of standard field.
    * @param int $MaxLength Maximum length in characters (not counting tags).
    * @return string|false Formatted field value or FALSE if no value available.
    */
    protected function getFormattedFieldValue(string $FieldName, int $MaxLength)
    {
        $Value = false;
        $Field = $this->Resource->getSchema()->GetFieldByMappedName($FieldName);
        if (($Field instanceof MetadataField) &&
            $this->Resource->UserCanViewField(User::getCurrentUser(), $Field)) {
            $Value = $this->getFieldValue($Field);
            $Value = strip_tags($Value ?? "", static::ALLOWED_TAGS);
            $Value = StdLib::neatlyTruncateString($Value, $MaxLength);
            $Value = $this->highlightTerms($Value);
        }
        return $Value;
    }

    /**
    * Highlight terms in string that have been indicated for highlighting.
    * @param string $Value String in which to highlight terms.
    * @return string String with terms highlighted.
    */
    protected function highlightTerms($Value)
    {
        if (count($this->TermsToHighlight)) {
            $Patterns = [];
            $Replacements = [];
            foreach ($this->TermsToHighlight as $Term) {
                $SafeTerm = preg_quote($Term, "/");

                $Patterns[] = "/([^a-z]{1})(".$SafeTerm.")([^a-z]{1})/i";
                $Replacements[] = "\\1<strong>\\2</strong>\\3";

                $Patterns[] = "/^(".$SafeTerm.")([^a-z]{1})/i";
                $Replacements[] = "<strong>\\1</strong>\\2";

                $Patterns[] = "/([^a-z]{1})(".$SafeTerm.")$/i";
                $Replacements[] = "\\1<strong>\\2</strong>";

                $Patterns[] = "/^(".$SafeTerm.")$/i";
                $Replacements[] = "<strong>\\1</strong>";
            }
            $Value = preg_replace($Patterns, $Replacements, $Value);
        }
        return $Value;
    }

    /**
    * Signal HTML insertion point event.
    * @param string $Location Location string to pass with signal.
    */
    protected function signalInsertionPoint($Location)
    {
        $PageName = $GLOBALS["AF"]->GetPageName();
        $Context = $this->AdditionalContext;
        $Context["ResourceId"] = $this->Resource->Id();
        $Context["Resource"] = $this->Resource;
        $GLOBALS["AF"]->SignalEvent(
            "EVENT_HTML_INSERTION_POINT",
            [ $PageName, $Location, $Context ]
        );
    }
}
