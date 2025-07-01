<?PHP
#
#   FILE:  ChangeSetEditingUI.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2014-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus;

use ScoutLib\ApplicationFramework;
use ScoutLib\Date;
use ScoutLib\HtmlOptionList;
use Exception;

/**
 * Class supplying a standard interface for editing the values contained in a
 * specified set of metadata fields.
 */
class ChangeSetEditingUI
{
    /**
     * Create a UI for specifying edits to metadata fields.
     * @param string $EditFormName Name to use for the HTML elements.
     *    The form cannot contain any input elements whose names are
     *    EditFormName.
     * @param int $SchemaId Schema Id (OPTIONAL, default Resource schema).
     */
    public function __construct(
        string $EditFormName,
        int $SchemaId = MetadataSchema::SCHEMAID_DEFAULT
    ) {
        $this->EditFormName = $EditFormName;
        $this->Schema = new MetadataSchema($SchemaId);

        $this->Fields = [];

        $this->AllowedFieldTypes =
            MetadataSchema::MDFTYPE_TEXT |
            MetadataSchema::MDFTYPE_PARAGRAPH |
            MetadataSchema::MDFTYPE_NUMBER |
            MetadataSchema::MDFTYPE_DATE |
            MetadataSchema::MDFTYPE_TIMESTAMP |
            MetadataSchema::MDFTYPE_FLAG |
            MetadataSchema::MDFTYPE_TREE |
            MetadataSchema::MDFTYPE_CONTROLLEDNAME |
            MetadataSchema::MDFTYPE_OPTION |
            MetadataSchema::MDFTYPE_URL |
            MetadataSchema::MDFTYPE_REFERENCE ;
    }

    /**
     * Add a field to the list of editable fields.
     * @param int|string $FieldNameOrId Field name or id
     * @param string $CurrentValue Initial value to display
     * @param mixed $CurrentOperator Initial operator (one of the OP_XX class constants)
     * @param bool $AllowRemoval TRUE if this field should be removable
     *   (OPTIONAL, default FALSE)
     * @return void
     */
    public function addField(
        $FieldNameOrId,
        $CurrentValue = null,
        $CurrentOperator = null,
        $AllowRemoval = false
    ) : void {
        # if a field name was passed in, convert it to a field id
        if (!is_numeric($FieldNameOrId)) {
            $FieldNameOrId = $this->Schema->getField($FieldNameOrId)->Id();
        }

        $this->Fields[] = [
            "Type" => "Regular",
            "FieldId" => $FieldNameOrId,
            "CurrentValue" => $CurrentValue,
            "CurrentOperator" => $CurrentOperator,
            "AllowRemoval" => $AllowRemoval
        ];
    }

    /**
     * Add a selectable field to the list of editable fields.
     * @param array $FieldTypesOrIds Either an array of FieldIds, or a
     *   bitmask of MDFTYPE_ constants specifying allowed fields
     *   (OPTIONAL, defaults to all fields in the schema supported by the
     *   editing UI)
     * @param int|null $CurrentFieldId FieldId giving the field selected by default
     *   (OPTIONAL, default NULL)
     * @param string $CurrentValue Initial value to display
     * @param mixed $CurrentOperator Initial operator to display (one of the
     *   OP_XX class constants)
     * @param bool $AllowRemoval TRUE if this field should be removable
     *   (OPTIONAL, default TRUE)
     * @return void
     */
    public function addSelectableField(
        $FieldTypesOrIds = null,
        $CurrentFieldId = null,
        $CurrentValue = null,
        $CurrentOperator = null,
        $AllowRemoval = true
    ) : void {
        $Options = $this->typesOrIdsToFieldList($FieldTypesOrIds);

        if (count($Options) > 0) {
            $this->Fields[] = [
                "Type" => "Selectable",
                "FieldId" => $CurrentFieldId,
                "SelectOptions" => $Options,
                "CurrentValue" => $CurrentValue,
                "CurrentOperator" => $CurrentOperator,
                "AllowRemoval" => $AllowRemoval
            ];
        }
    }

    /**
     * Add a button to create more fields above the button.
     * @param string $Label Label to display on the button (OPTIONAL, default
     *   "Add field")
     * @param mixed $FieldTypesOrIds Either an array of FieldIds, or a
     *   bitmask of MDFTYPE_ constants specifying allowed fields
     *   (OPTIONAL, defaults to all fields in the schema supported by the
     *    editing UI)
     * @return void
     */
    public function addFieldButton($Label = "Add field", $FieldTypesOrIds = null) : void
    {
        $Options = $this->typesOrIdsToFieldList($FieldTypesOrIds);

        if (count($Options) > 0) {
            $this->Fields[] = [
                "Type" => "AddButton",
                "SelectOptions" => $Options,
                "Label" => $Label,
                "CurrentOperator" => null,
                "CurrentValue" => null,
                "AllowRemoval" => true
            ];
        }
    }

    /**
     * Display editing form elements enclosed in a <table>.  Note that
     * it still must be wrapped in a <form> that has a submit button.
     * @param ?string $TableId HTML identifier to use (OPTIONAL, default
     *   NULL)
     * @param ?string $TableStyle CSS class to attach for this table
     *   (OPTIONAL, default NULL)
     * @return void
     */
    public function displayAsTable(?string $TableId = null, ?string $TableStyle = null) : void
    {
        print('<table id="'.defaulthtmlentities($TableId).'" '
              .'class="'.defaulthtmlentities($TableStyle).'">');
        $this->displayAsRows();
        print('</table>');
    }

    /**
     * Display the table rows for the editing form, without the
     * surrounding <table> tags.
     * @return void
     */
    public function displayAsRows(): void
    {
        $AF = ApplicationFramework::getInstance();

        # make sure the necessary javascript is required
        $AF->requireUIFile("ChangeSetEditingUI.js");

        # get a list of the fields examined in this chunk of UI, to
        # use when constructing the value selector
        $FieldsExamined = [];
        foreach ($this->Fields as $FieldRow) {
            if ($FieldRow["Type"] == "Regular") {
                $FieldsExamined[] = $FieldRow["FieldId"];
            } else {
                $FieldsExamined = array_merge(
                    $FieldsExamined,
                    $FieldRow["SelectOptions"]
                );
            }
        }
        $FieldsExamined = array_unique($FieldsExamined);

        # iterate over each field adding edit rows for all of them
        print('<tr class="mv-feui-empty '.$this->EditFormName.'" '.
              'data-formname="'.$this->EditFormName.'"><td colspan="4">'.
              '<i>No fields selected for editing</i></td></tr>');

        # note that all of the fields we create for these rows will be named
        # $this->EditFormName.'[]' , combining them all into an array of results per
        #   http://php.net/manual/en/faq.html.php#faq.html.arrays
        foreach ($this->Fields as $FieldRow) {
            $CurOp = $FieldRow["CurrentOperator"];
            $CurVal = $FieldRow["CurrentValue"];
            $AllowRemoval = $FieldRow["AllowRemoval"];

            print('<tr class="field_row '.$this->EditFormName
                  .($FieldRow["Type"] == "AddButton" ? ' template_row' : '').'">');

            print("<td>");
            if ($FieldRow["AllowRemoval"]) {
                print("<span class=\"btn btn-primary btn-sm "
                      ."mv-feui-delete\">X</span>");
            }
            print("</td>");

            if ($FieldRow["Type"] == "Regular") {
                $Field = MetadataField::getField($FieldRow["FieldId"]);

                # for fields that cannot be selected, we already know
                # the type and can print field-specific elements
                # (operators, etc) rather than relying on js to clean
                # them up for us.

                $TypeName = defaulthtmlentities(
                    str_replace(' ', '', strtolower($Field->typeAsName()))
                );
                if ($Field->type() == MetadataSchema::MDFTYPE_OPTION &&
                    $Field->allowMultiple()) {
                    $TypeName = "mult".$TypeName;
                }

                # encode the field for this row in a form value
                print(
                    '<td>'
                    .$Field->name()
                    .'<input type="hidden"'
                    .' name="'.$this->EditFormName.'[]"'
                    .' class="field-subject field-static field-type-'.$TypeName.'"'
                    .' value="S_'.$Field->id().'">'
                    .'</td>'
                );

                # determine operators, make a select widget
                print("<td>");
                $this->printOperatorSelectorForField($FieldRow);
                print("</td>");

                # print necessary editing elements
                print ("<td>");
                $this->printEditElementsForField($FieldRow);
                print ("</td>");
            } else {
                # for selectable fields, we need to generate all the
                # html elements that we might need and then depend on
                # javascript to display only those that are relevant

                # each field will have five elements

                # 1. a field selector
                print ("<td>");
                $this->printFieldSelector($FieldRow);
                print ("</td>");

                # 2.  an operator selector
                print ("<td>");
                $this->printOperatorSelector($CurOp, $AllowRemoval);
                print ("</td>");


                print ("<td>");
                # 3. a value selector (for option and flag values)
                $this->printValueSelector($FieldsExamined);

                # 4. two text entries (free-form text and possible replacement)
                print('<input type="text" class="field-value-edit" '
                     .'name="'.$this->EditFormName.'[]" '
                     .'value="'.defaulthtmlentities($CurVal).'">'
                     .'<input type="text" class="field-value-repl" '
                     .'name="'.$this->EditFormName.'[]" '
                     .'value="'.defaulthtmlentities($CurVal).'">');

                # 5. an ajax search box
                $this->printQuicksearch($FieldRow);

                print ("</td>");
                if ($FieldRow["Type"] == "AddButton") {
                    print(
                        '</tr><tr class="button_row"><td colspan="4">'
                        .'<span class="btn btn-primary btn-sm mv-feui-add mv-button-iconed">'
                        .'<img src="'.$AF->gUIFile('Plus.svg')
                        .'" alt="" class="mv-button-icon" /> '
                        .defaulthtmlentities($FieldRow["Label"]).'</button></td></tr>'
                    );
                }
            }
            print('</tr>');
        }
    }

    /**
     * Extract values from a dynamic field edit/modification form.
     * @return array of arrays like
     *      ["FieldId" => $FieldId, "Op" => $Operator, "Val" => $Value,] ...]
     *  extracted from the $_POST data for $EditFormName.
     */
    public function getValuesFromFormData(): array
    {
        $Results = [];

        if (!isset($_POST[$this->EditFormName])) {
            return $Results;
        }

        # extract the array of data associated with our EditFormName
        $FormData = $_POST[$this->EditFormName];

        while (count($FormData)) {
            # first element of each row is a field id
            $FieldId = array_shift($FormData);
            $Op = array_shift($FormData);

            # when the row was static, it'll have a 'S_' prefix
            # to make it non-numeric
            if (!is_numeric($FieldId)) {
                # remove the S_ prefix to get the real field id
                $FieldId = substr($FieldId, 2);

                # grab the value(s) for this field
                $Val = array_shift($FormData);
                if ($Op == Record::CHANGE_FIND_REPLACE) {
                    $TextVal2 = array_shift($FormData);
                } else {
                    $TextVal2 = null;
                }
            } else {
                # for selectable fields, we'll have all possible
                # elements and will need to grab the correct ones for
                # the currently selected field
                $SelectVal = array_shift($FormData);
                $TextVal   = array_shift($FormData);
                $TextVal2  = array_shift($FormData);
                $SearchVal = array_shift($FormData);

                $Field = MetadataField::getField((int)$FieldId);

                switch ($Field->type()) {
                    case MetadataSchema::MDFTYPE_PARAGRAPH:
                    case MetadataSchema::MDFTYPE_URL:
                    case MetadataSchema::MDFTYPE_TEXT:
                    case MetadataSchema::MDFTYPE_NUMBER:
                    case MetadataSchema::MDFTYPE_DATE:
                    case MetadataSchema::MDFTYPE_TIMESTAMP:
                        $Val = $TextVal;
                        break;

                    case MetadataSchema::MDFTYPE_TREE:
                    case MetadataSchema::MDFTYPE_CONTROLLEDNAME:
                    case MetadataSchema::MDFTYPE_REFERENCE:
                        $Val = $SearchVal;
                        break;

                    case MetadataSchema::MDFTYPE_FLAG:
                    case MetadataSchema::MDFTYPE_OPTION:
                        $Val = $SelectVal;
                        break;

                    default:
                        throw new Exception("Unsupported field type");
                }
            }

            $ResRow = [
                "FieldId" => $FieldId,
                "Op" => $Op,
                "Val" => $Val
            ];
            if ($Op == Record::CHANGE_FIND_REPLACE) {
                $ResRow["Val2"] = $TextVal2;
            }

            $Results[] = $ResRow;
        }

        return $Results;
    }

    /**
     * Load a configured set of fields.
     * @param array $Data Fields to load in the format from getValuesFromFormData()
     * @return void
     * @see getValuesFromFormData()
     */
    public function loadConfiguration($Data): void
    {
        foreach ($Data as $Row) {
            # convert a legacy CHANGE_REPLACE into CHANGE_SET
            # (see comments at the top of Record.php)
            if ($Row["Op"] == 6) {
                $Row["Op"] = Record::CHANGE_SET;
            }

            $this->addField(
                $Row["FieldId"],
                ($Row["Op"] == Record::CHANGE_FIND_REPLACE) ?
                [$Row["Val"], $Row["Val2"]] :
                $Row["Val"],
                $Row["Op"],
                true
            );
        }
    }

    /**
     * Record a message that a form value was invalid.
     * There is an array of error messages for each field.
     * @param string $Msg Error message to record.
     * @param int $FieldId ID of Metadata Field that has an invalid value.
     * @return void
     */
    public function logError(string $Msg, int $FieldId): void
    {
        $this->ErrorMessages[$FieldId][] = $Msg;
    }

    /**
    * Display HTML block with any error messages.
    * @return void
    */
    public function displayErrorBlock(): void
    {
        $ErrorText = "";
        if (count($this->ErrorMessages) == 0) {
            return;
        }

        foreach ($this->ErrorMessages as $FieldId => $Messages) {
            foreach ($Messages as $Message) {
                $ErrorText .= "<li>".
                      MetadataField::getField($FieldId)->name().
                      ": ".$Message."</li>\n";
            }
        }
        print "<ul class=\"mv-form-error\">\n";
        print $ErrorText;
        print "</ul>\n";
    }

    /**
     * Check that the value provided for the metadata field is valid.
     * Return an explanatory error message if the value is not valid.
     * @return int The number of fields with invalid values.
     */
    public function validateFieldInput(): int
    {
        $this->ErrorMessages = [];
        $ErrorCount = 0;
        $ChangeRows = $this->getValuesFromFormData();
        foreach ($ChangeRows as $ChangeRow) {
            $FieldId = (int)$ChangeRow["FieldId"];
            $Value = trim($ChangeRow["Val"]);
            $Field = MetadataField::getField($FieldId);
            $Operator = $ChangeRow["Op"];
            if ($Operator  == Record::CHANGE_CLEARALL) {
                # no value is required for clear all, so skip validation
                continue;
            }
            switch ($Field->type()) {
                case MetadataSchema::MDFTYPE_TEXT:
                case MetadataSchema::MDFTYPE_PARAGRAPH:
                case MetadataSchema::MDFTYPE_URL:
                case MetadataSchema::MDFTYPE_NUMBER:
                case MetadataSchema::MDFTYPE_DATE:
                case MetadataSchema::MDFTYPE_TREE:
                case MetadataSchema::MDFTYPE_OPTION:
                case MetadataSchema::MDFTYPE_CONTROLLEDNAME:
                case MetadataSchema::MDFTYPE_REFERENCE:
                    if (!strlen($Value)) {
                        $this->logError(
                            "Blank value not allowed.",
                            $Field->id()
                        );
                        $ErrorCount++;
                        continue 2;
                        # skip checking additional errors for the field
                        # after logging that it is blank
                    }
            }

            switch ($Field->type()) {
                case MetadataSchema::MDFTYPE_NUMBER:
                    if (!is_numeric(trim($Value))) {
                        $this->logError(
                            "Non-numeric value not allowed.",
                            $Field->id()
                        );
                        $ErrorCount++;
                    }
                    break;
                case MetadataSchema::MDFTYPE_DATE:
                    if (!Date::isValidDate($Value)) {
                        $this->logError("Invalid date.", $Field->id());
                        $ErrorCount++;
                    }
                    break;
                case MetadataSchema::MDFTYPE_TIMESTAMP:
                    if (!strtotime(strval($Value))) {
                        $this->logError(
                            "Invalid timestamp.",
                            $Field->id()
                        );
                        $ErrorCount++;
                    }
                    break;
                case MetadataSchema::MDFTYPE_FLAG:
                    if (!in_array($Value, ["1", "0"])) {
                        $this->logError(
                            "Value must be 1 or 0.",
                            $Field->id()
                        );
                        $ErrorCount++;
                    }
                    break;
                case MetadataSchema::MDFTYPE_TREE:
                    if (!is_numeric($Value)) {
                        $this->logError(
                            "Non-numeric value not allowed.",
                            $Field->id()
                        );
                        $ErrorCount++;
                    } elseif (!Classification::itemExists((int)$Value)) {
                        $this->logError(
                            "Non-existing value.",
                            $Field->id()
                        );
                        $ErrorCount++;
                    }
                    break;
                case MetadataSchema::MDFTYPE_OPTION:
                case MetadataSchema::MDFTYPE_CONTROLLEDNAME:
                    if (!is_numeric($Value)) {
                        $this->logError(
                            "Non-numeric value not allowed.",
                            $Field->id()
                        );
                        $ErrorCount++;
                    } elseif (!ControlledName::itemExists((int)$Value)) {
                        $this->logError(
                            "Non-existing value.",
                            $Field->id()
                        );
                        $ErrorCount++;
                    }
                    break;
                case MetadataSchema::MDFTYPE_REFERENCE:
                    if (!is_numeric($Value)) {
                        $this->logError(
                            "Non-numeric value not allowed.",
                            $Field->id()
                        );
                        $ErrorCount++;
                    } elseif (!Record::itemExists((int)$Value)) {
                        $this->logError(
                            "Non-existing value.",
                            $Field->id()
                        );
                        $ErrorCount++;
                    }
                    break;
            }
        }
        return $ErrorCount;
    }


    # ---- PRIVATE INTERFACE -------------------------------------------------

    private $EditFormName;
    private $Fields;
    private $Schema;
    protected $ErrorMessages = [];

    # mapping of operator constants to their friendly names
    private $OpNames = [
        Record::CHANGE_NOP => "Do nothing",
        Record::CHANGE_SET => "Set",
        Record::CHANGE_CLEAR => "Clear Specified Value",
        Record::CHANGE_CLEARALL => "Clear All Values",
        Record::CHANGE_APPEND => "Append",
        Record::CHANGE_PREPEND => "Prepend",
        Record::CHANGE_FIND_REPLACE => "Find/Replace"
    ];

    # to store a bitmask
    private $AllowedFieldTypes;

    /**
     * Print the <select> element used for choosing which metadata field to
     * edit in selectable field rows (i.e. those that allow the user a choice
     * of which field to edit).
     * @param array $FieldRow Row from $this->Fields for the field that we're
     *   printing. Needs to have SelectOptions, Type, and FieldId elements.
     * @return void
     */
    private function printFieldSelector(array $FieldRow): void
    {
        $Options = [];
        $OptionClasses = [];
        $OptionData = [];

        foreach ($FieldRow["SelectOptions"] as $FieldId) {
            $Field = MetadataField::getField($FieldId);
            $TypeName = defaulthtmlentities(
                str_replace(' ', '', strtolower($Field->typeAsName()))
            );

            if ($Field->type() == MetadataSchema::MDFTYPE_OPTION &&
                $Field->allowMultiple()) {
                $TypeName = "mult".$TypeName;
            }

            if (!$Field->optional()) {
                $TypeName .= " required";
            }

            $Options[$Field->id()] = $Field->name();
            $OptionClasses[$Field->id()] = "field-type-" . $TypeName;
            $OptionData[$Field->id()]["maxnumsearchresults"] = $Field->numAjaxResults();
        }

        $FieldOptionList = new HtmlOptionList($this->EditFormName . "[]", $Options);
        $FieldOptionList->classForList("field-subject");
        $FieldOptionList->classForOptions($OptionClasses);
        $FieldOptionList->dataForOptions($OptionData);
        # determine if there is selected field (i.e. when this row isn't an add button)
        if ($FieldRow["Type"] != "AddButton") {
            $FieldOptionList->selectedValue($FieldRow["FieldId"]);
        }
        print $FieldOptionList->getHtml();
    }

    /**
     * Print the <select> element used for choosing which operator to apply
     * when editing a metadata field in selectable field rows (i.e. those that
     * allow the user a choice of which field to edit).
     * @param int|null $CurOp Currently selected operation as a Record::CHANGE_ constant.
     * @param bool $AllowRemoval TRUE if this field can be deleted, FALSE otherwise.
     * @return void
     */
    private function printOperatorSelector(?int $CurOp, bool $AllowRemoval): void
    {
        $TimeTypes = ["field-type-date", "field-type-timestamp"];
        $TextTypes = ["field-type-url", "field-type-text", "field-type-paragraph"];
        $OtherTypes = [
            "field-type-controlledname",
            "field-type-flag",
            "field-type-multoption",
            "field-type-number",
            "field-type-option",
            "field-type-reference",
            "field-type-tree"
        ];

        $AllTypesButTimeString = join(" ", array_merge($TextTypes, $OtherTypes));
        $AllTypesString = join(" ", array_merge($TimeTypes, $TextTypes, $OtherTypes));
        $TextTypesString = join(" ", $TextTypes);

        # display all available operators, annotated such that
        # js can switch between them
        # (CLEAR is not currently supported for time types because
        # it's not obvious how values should be compared to determine
        # what matches. For example, if a user specifies "2020-" for a
        # Date field, would that match only Dates with a BeginDate of
        # "2020-" and no end date? Or would it match any Date beginning
        # and/or ending in 2020?)
        $Options = [
            Record::CHANGE_SET => $this->OpNames[Record::CHANGE_SET],
            Record::CHANGE_CLEAR => $this->OpNames[Record::CHANGE_CLEAR],
            Record::CHANGE_CLEARALL => $this->OpNames[Record::CHANGE_CLEARALL],
            Record::CHANGE_APPEND => $this->OpNames[Record::CHANGE_APPEND],
            Record::CHANGE_PREPEND => $this->OpNames[Record::CHANGE_PREPEND],
            Record::CHANGE_FIND_REPLACE => $this->OpNames[Record::CHANGE_FIND_REPLACE]
        ];
        $OptionClasses = [
            Record::CHANGE_SET => $AllTypesString,
            Record::CHANGE_CLEAR => $AllTypesButTimeString,
            Record::CHANGE_CLEARALL => $AllTypesString,
            Record::CHANGE_APPEND => $TextTypesString,
            Record::CHANGE_PREPEND => $TextTypesString,
            Record::CHANGE_FIND_REPLACE => $TextTypesString
        ];
        # for fields that cannot be removed, allow a 'do nothing' option
        if (!$AllowRemoval) {
            $Options = [Record::CHANGE_NOP => $this->OpNames[Record::CHANGE_NOP]] + $Options;
            $OptionClasses = [Record::CHANGE_NOP => $AllTypesString] + $OptionClasses;
        }
        $OperatorOptionList = new HtmlOptionList($this->EditFormName . "[]", $Options);
        $OperatorOptionList->classForList("field-operator");
        $OperatorOptionList->classForOptions($OptionClasses);
        $OperatorOptionList->selectedValue($CurOp);
        print $OperatorOptionList->getHtml();
    }


    /**
     * Print the <select> element used for choosing which operator to apply
     * when editing a metadata field in non-selectable field rows (i.e. those that
     * only ever apply to a single field).
     * Print a <select> element for choosing an operator in a non-selectable field row.
     * @param array $FieldRow Row from $this->Fields for the field that we're
     *   printing. Needs to have FieldId, CurrentOperator, and AllowRemoval elements.
     * @return void
     */
    private function printOperatorSelectorForField(array $FieldRow): void
    {
        $Field = MetadataField::getField($FieldRow["FieldId"]);
        $OptionSelections = [];

        if (!$FieldRow["AllowRemoval"]) {
            $OptionSelections[] = Record::CHANGE_NOP;
        }

        switch ($Field->type()) {
            case MetadataSchema::MDFTYPE_URL:
            case MetadataSchema::MDFTYPE_TEXT:
            case MetadataSchema::MDFTYPE_PARAGRAPH:
                $OptionSelections[] = Record::CHANGE_SET;
                if ($Field->optional()) {
                    $OptionSelections[] = Record::CHANGE_CLEARALL;
                }
                $OptionSelections[] = Record::CHANGE_CLEAR;
                $OptionSelections[] = Record::CHANGE_APPEND;
                $OptionSelections[] = Record::CHANGE_PREPEND;
                $OptionSelections[] = Record::CHANGE_FIND_REPLACE;
                break;

            case MetadataSchema::MDFTYPE_FLAG:
                $OptionSelections[] = Record::CHANGE_SET;
                break;

            case MetadataSchema::MDFTYPE_TIMESTAMP:
            case MetadataSchema::MDFTYPE_DATE:
            case MetadataSchema::MDFTYPE_NUMBER:
                $OptionSelections[] = Record::CHANGE_SET;
                if ($Field->optional()) {
                    $OptionSelections[] = Record::CHANGE_CLEARALL;
                }
                break;

            case MetadataSchema::MDFTYPE_OPTION:
            case MetadataSchema::MDFTYPE_TREE:
            case MetadataSchema::MDFTYPE_CONTROLLEDNAME:
            case MetadataSchema::MDFTYPE_REFERENCE:
                $OptionSelections[] = Record::CHANGE_SET;
                $OptionSelections[] = Record::CHANGE_CLEAR;

                if ($Field->optional() &&
                    ($Field->type() != MetadataSchema::MDFTYPE_OPTION ||
                     $Field->allowMultiple() )) {
                    $OptionSelections[] = Record::CHANGE_CLEARALL;
                }
                break;

            default:
                throw new Exception("Unsupported field type");
        }

        $Options = [];
        foreach ($OptionSelections as $OptionsSelection) {
            $Options[$OptionsSelection] = $this->OpNames[$OptionsSelection];
        }

        $OperatorOptionList = new HtmlOptionList($this->EditFormName . "[]", $Options);
        $OperatorOptionList->selectedValue($FieldRow["CurrentOperator"]);
        print $OperatorOptionList->getHtml();
    }

    /**
     * Print a <select> element that can choose from among the controlled
     * vocabularies for a given set of fields.
     * @param array $FieldIds List of field ids.
     * @return void
     */
    private function printValueSelector(array $FieldIds): void
    {
        $Options = [];
        $OptionClasses = [];

        foreach ($FieldIds as $FieldId) {
            $Field = MetadataField::getField($FieldId);
            if ($Field->type() == MetadataSchema::MDFTYPE_FLAG ||
                $Field->type() == MetadataSchema::MDFTYPE_OPTION) {
                foreach ($Field->getPossibleValues() as $Id => $Val) {
                    $Options[$Id] = $Val;
                    $CssClass = "field-id-" . $Field->id();
                    if (!isset($OptionClasses[$Id])) {
                        $OptionClasses[$Id] = $CssClass;
                    } else {
                        $OptionClasses[$Id] .= " ".$CssClass;
                    }
                }
            }
        }

        $ValueSelectorOptionList = new HtmlOptionList($this->EditFormName . "[]", $Options);
        $ValueSelectorOptionList->classForOptions($OptionClasses);
        $ValueSelectorOptionList->classForList("field-value-select");
        print $ValueSelectorOptionList->getHtml();
    }

    /**
     * Print HTML elements for editing values from a given field.
     * @param array $FieldRow Row from $this->Fields for the field that we're
     *   printing. Needs to have FieldId, CurrentValue, and CurrentOperator
     *   elements.
     * @return void
     */
    private function printEditElementsForField(array $FieldRow): void
    {
        $Field = MetadataField::getField($FieldRow["FieldId"]);
        $CurVal = $FieldRow["CurrentValue"];
        $CurOp = $FieldRow["CurrentOperator"];

        switch ($Field->type()) {
            case MetadataSchema::MDFTYPE_URL:
            case MetadataSchema::MDFTYPE_TEXT:
            case MetadataSchema::MDFTYPE_PARAGRAPH:
            case MetadataSchema::MDFTYPE_NUMBER:
            case MetadataSchema::MDFTYPE_DATE:
            case MetadataSchema::MDFTYPE_TIMESTAMP:
                if ($CurOp == Record::CHANGE_FIND_REPLACE) {
                    print('<input type="text" '
                          .'name="'.$this->EditFormName.'[]" '
                          .'value="'.defaulthtmlentities($CurVal[0]).'">');
                    print('<input type="text" class="field-value-repl" '
                          .'name="'.$this->EditFormName.'[]" '
                          .'value="'.defaulthtmlentities($CurVal[1]).'">');
                } else {
                    print('<input type="text" '
                          .'class="field-value-edit" '
                          .'name="'.$this->EditFormName.'[]" '
                          .'value="'.defaulthtmlentities($CurVal).'">');
                }
                break;

            case MetadataSchema::MDFTYPE_TREE:
            case MetadataSchema::MDFTYPE_CONTROLLEDNAME:
            case MetadataSchema::MDFTYPE_REFERENCE:
                $this->printQuicksearch($FieldRow);
                break;

            case MetadataSchema::MDFTYPE_FLAG:
            case MetadataSchema::MDFTYPE_OPTION:
                $OptionList = new HtmlOptionList(
                    $this->EditFormName . "[]",
                    $Field->getPossibleValues()
                );
                $OptionList->classForOptions("field-id-" . $Field->id());
                $OptionList->selectedValue($CurVal);
                print $OptionList->getHtml();
                break;
        }
    }

    /**
     * Print HTML elements for incremental search in a given field.
     * @param array $FieldRow Row from $this->Fields for the field that we're
     *   printing. Needs to have CurrentValue and Type elements. For rows that
     *   aren't an add button it also needs to have a FieldId element.
     * @return void
     */
    private function printQuicksearch(array $FieldRow): void
    {
        # (CurrentValue in quick search fields will be a ClassificationId, a
        # ControlledNameId, or a RecordId)
        $CurValueId = trim($FieldRow["CurrentValue"] ?? "");
        $DisplayVal = "";

        # if this quicksearch is for a specific field
        if ($FieldRow["Type"] != "AddButton" && !is_null($FieldRow["FieldId"])) {
            # pull out that field
            $FieldId = $FieldRow["FieldId"];
            $Field = MetadataField::getField($FieldId);

            # and look up a human-friendly display value
            if (strlen($CurValueId) > 0) {
                $DisplayVal = ($Field->type() == MetadataSchema::MDFTYPE_REFERENCE) ?
                    (new Record(intval($CurValueId)))->getMapped("Title") :
                    $Field->getFactory()->getItem($CurValueId)->name();
            }
        } else {
            $FieldId = QuickSearchHelper::DYNAMIC_SEARCH;
        }

        QuickSearchHelper::printQuickSearchField(
            $FieldId,
            $CurValueId,
            $DisplayVal,
            false,
            $this->EditFormName
        );
    }

    /**
    * Convert FieldTypesOrIds into a list of fields.
    * @param null|array $FieldTypesOrIds NULL, array of FieldIds, or
    *     Bitmask of MDFType values
    * @return array of FieldIds
    */
    private function typesOrIdsToFieldList(?array $FieldTypesOrIds): array
    {
        $Result = [];

        if ($FieldTypesOrIds === null) {
            $FieldTypesOrIds = $this->AllowedFieldTypes;
        }

        if (is_array($FieldTypesOrIds)) {
            # if given a list of fields, check each that exists in our schema
            $FieldsToCheck = [];
            foreach ($FieldTypesOrIds as $FieldId) {
                if ($this->Schema->fieldExists($FieldId)) {
                    $FieldsToCheck[] = $this->Schema->getField($FieldId);
                }
            }
        } else {
            # if given a bitmask, check all fields of the requested types from our schema
            $FieldsToCheck = $this->Schema->getFields($FieldTypesOrIds);
        }

        # include all editable fields that are of an allowed type
        foreach ($FieldsToCheck as $Field) {
            if ($Field->editable() &&
                ($Field->type() & $this->AllowedFieldTypes) != 0) {
                $Result[] = $Field->id();
            }
        }

        return $Result;
    }
}
