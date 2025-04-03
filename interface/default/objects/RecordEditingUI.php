<?PHP
#
#   FILE:  RecordEditingUI.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2019-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus;
use Exception;
use ScoutLib\ApplicationFramework;
use ScoutLib\Date;
use ScoutLib\StdLib;

/**
* Class supplying a standard user interface for editing records.
*/
class RecordEditingUI extends FormUI
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Create an editing interface object for a provided record.
     * @param Record $Record Record to edit.
     */
    public function __construct(Record $Record)
    {
        $this->Record = $Record;

        $FormFields = static::getFormFieldConfiguration();
        $FormValues = static::getFormFieldValues($FormFields);

        parent::__construct(
            $FormFields,
            $FormValues,
            "RecEditingUI_".$this->Record->id()
        );
    }

    /**
     * Set all fields ReadOnly.
     * @return void
     */
    public function setAllFieldsReadOnly(): void
    {
        foreach ($this->FieldParams as $FieldName => $Params) {
            $this->FieldParams[$FieldName]["ReadOnly"] = true;
            $this->FieldParams[$FieldName]["Required"] = false;

            if (isset($Params["AdditionalHtml"])) {
                unset($this->FieldParams[$FieldName]["AdditionalHtml"]);
            }

            if (isset($Params["UpdateButton"])) {
                $this->FieldParams[$FieldName]["UpdateButton"] = false;
            }
        }
    }

    /**
     * Save changes to underlying record.
     * @throws \Exception if field input was not validated before saving.
     * @return void
     */
    public function saveChanges(): void
    {
        # retrieve user currently logged in
        $User = User::getCurrentUser();

        if (!self::$FieldInputIsValidated) {
            throw new \Exception("Cannot save changes to Record without first validating input.");
        }
        $Fields = $this->Record->getSchema()->getFields(
            null,
            MetadataSchema::MDFORDER_EDITING
        );

        $this->UserCanModify = [];
        foreach ($Fields as $Field) {
            $Key = $Field->name();
            $this->UserCanModify[$Key] = $this->Record->userCanModifyField(
                $User,
                $Field
            );
        }

        # make sure all fields provided are actually valid fields before
        # continuing
        $NewValues = $this->getNewValuesFromForm();
        foreach (array_keys($NewValues) as $Key) {
            if (!static::isFormFieldValid($Key)) {
                throw new Exception(
                    "Value provided for invalid field: ".$Key
                );
            }
        }

        # iterate over provided values
        $RecordChanged = false;
        foreach ($NewValues as $Key => $Values) {
            $FieldChanged = static::saveValueFromFormField($Key, $Values);

            if ($FieldChanged) {
                $RecordChanged = true;

                # Unset value in POST so that the value in the record (which may have
                # been modified either by normalization in Record::set() or
                # POST_FIELD_EDIT_FILTER) will be reflected in the editing UI rather
                # than just displaying whatever was submitted even when it doesn't match
                # what was stored in the Record.This is a temporary measure that is only
                # necessary because FormUI is currently implicitly preferring values from
                # POST when they are available.Once FormUI has been fixed to remove
                # that implicit behavior, this unset() should be removed.
                unset($_POST[$this->getFormFieldName($Key)]);
            }
        }

        # if the record hasn't been modified, we're done
        if (!$RecordChanged) {
            return;
        }

        # Record::set() associates new files by duplicating them, so we need to
        # remove the copy created by the initial upload
        foreach ($this->AddedFiles as $FileId) {
            (new File($FileId))->destroy();
        }

        # delete any uploaded images that were not associated with the record
        foreach ($this->AddedImages as $ImageId) {
            if (!Image::itemExists($ImageId)) {
                continue;
            }

            $Image = new Image($ImageId);
            if ($Image->getIdOfAssociatedItem() == Image::NO_ITEM) {
                $Image->destroy();
            }
        }
    }

    /**
     * Display editing form.
     * @param string $TableId CSS ID for table element. (OPTIONAL)
     * @param string $TableStyle CSS styles for table element. (OPTIONAL)
     * @param string $TableCssClass Additional CSS class for table element. (OPTIONAL)
     * @return void
     */
    public function displayFormTable(
        ?string $TableId = null,
        ?string $TableStyle = null,
        ?string $TableCssClass = null
    ): void {
        $AF = ApplicationFramework::getInstance();
        # add checksums for initial values to determine if record has been updated on submission
        $MFields = $this->Record->getSchema()->getFields();
        foreach ($MFields as $MField) {
            if ($MField->enabled()) {
                $ChecksumFormFieldName = $this->getChecksumFormFieldName($MField->name());
                $EncodedValue = $this->getChecksumForValue($this->Record->get($MField));
                $this->addHiddenField($ChecksumFormFieldName, $EncodedValue);
            }
        }
        parent::displayFormTable(
            $TableId,
            $TableStyle,
            "mv-reui-form ".($TableCssClass ?? "")
        );

        $AF->requireUIFile("RecordEditingUI.js");

        $LoadingImg = $AF->gUIFile(self::LOADING_IMG_FILE_NAME);

        print <<<END
<div id="mv-vocabsearchpopup">
  <h3>Terms Selected</h3>
  <div class="TermsAssigned"></div>

  <span class="mv-num-results-per-page"><input type="number" min="5" max="500"> per page</span>
  <h3>Terms Available</h3>
  <div class="searchControls">
    <label>Search: <input class="searchString" type="text"></label>
    <span class="mv-loading" style="display: none"><img src="$LoadingImg"></span>
    <span class="mv-controls">
    <button class="btn btn-sm btn-primary mv-btn-add" type="button">Add</button><br/>
    <span class="mv-search-info">
      <span class="mv-search-pagination">
        <span class="mv-pagination-left">
          <button class="btn btn-sm btn-primary mv-btn-start" type="button">&#124;&lt;</button>
          <button class="btn btn-sm btn-primary mv-btn-page" data-pages="-5"
          type="button">&lt;&lt;</button>
          <button class="btn btn-sm btn-primary mv-btn-page" data-pages="-1"
          type="button">&lt;</button>
        </span>
        <span class="mv-pagination-center">
          <span class="startIndex">-</span> - <span class="endIndex">-</span> of <span
          class="resultCount">-</span>
        </span>
        <span class="mv-pagination-right">
          <button class="btn btn-sm btn-primary mv-btn-page" data-pages="+1"
          type="button">&gt;</button>
          <button class="btn btn-sm btn-primary mv-btn-page" data-pages="+5"
          type="button">&gt;&gt;</button>
          <button class="btn btn-sm btn-primary mv-btn-end" type="button">&gt;&#124;</button><br/>
        </span>
      </span>
    </span>
  </div>
  <div class="TermsAvailable"></div>
</div>
END;
    }

    /**
     * Validate user inputs by field type/given validation functions, as well as
     *  by checksums as to not overwrite another user's recent changes.
     *  If overwriting a recently changed field, clears post value for field
     *  (as to display updated value from recent changes) and logs error for user.
     * @return int number of invalid fields
     */
    public function validateFieldInput(): int
    {
        # retrieve user currently logged in
        $User = User::getCurrentUser();

        # validate normally, get error count
        $Errors = parent::validateFieldInput();
        $Schema = $this->Record->getSchema();
        $MFields = $Schema->getFields();

        # go through most recent values from record
        foreach ($MFields as $MField) {
            # if field isn't enabled don't check it
            if (!$MField->enabled()) {
                continue;
            }
            # escape if checking a value that auto updates
            if ($MField->updateMethod() != MetadataField::UPDATEMETHOD_NOAUTOUPDATE) {
                continue;
            }

            # if user cannot view or edit the field, no need to check it
            if (!$this->Record->userCanViewField($User, $MField) ||
                !$this->Record->userCanModifyField($User, $MField)) {
                continue;
            }

            $MFieldName = $MField->name();

            # nothing to check if no corresponding form field was configured
            if (!isset($this->FieldParams[$MFieldName])) {
                continue;
            }

            # get MetadataField type for determining how to display/for converting to format
            $MDFType = $MField->type();

            # current field value
            $Value = $this->Record->get($MField);

            # get form name, previous checksum, current checksum, and user values/checksum
            $ChecksumFormFieldName = $this->getChecksumFormFieldName($MFieldName);
            $InitialChecksum = StdLib::getFormValue($ChecksumFormFieldName);
            $CurrentChecksum = $this->getChecksumForValue($Value);
            $RawFormValue = $this->getFieldValue($MFieldName);
            $MFieldValue = $this->convertFormValueToMFieldValue($MField, $RawFormValue);
            $DisplayValue = $this->convertMFieldValueToDisplayValue($MFieldValue, $MField);
            $FormChecksum = $this->getChecksumForValue($MFieldValue);

            # compare field value at start of editing to field value now
            if ($InitialChecksum == $CurrentChecksum) {
                continue;
            }

            # we need to clear the post value so get the form field name
            $FormFieldName = $this->getFormFieldName($MFieldName);
            # FormUI prefers the $_POST value so we have to update that here.
            # This is intended as a temporary solution until we have a standard
            # way of modifying/updating a FormUI value.
            switch ($MDFType) {
                case MetadataSchema::MDFTYPE_IMAGE:
                case MetadataSchema::MDFTYPE_FILE:
                    $FileIdFieldName = $FormFieldName."_ID";
                    $_POST[$FileIdFieldName] =  array_keys($Value);
                    break;
                case MetadataSchema::MDFTYPE_POINT:
                    $_POST[$FormFieldName."_X"] = $Value["X"];
                    $_POST[$FormFieldName."_Y"] = $Value["Y"];
                    break;
                case MetadataSchema::MDFTYPE_TREE:
                case MetadataSchema::MDFTYPE_CONTROLLEDNAME:
                case MetadataSchema::MDFTYPE_OPTION:
                case MetadataSchema::MDFTYPE_USER:
                    $_POST[$FormFieldName] = array_keys($Value);
                    break;
                case MetadataSchema::MDFTYPE_DATE:
                    $_POST[$FormFieldName] = $Value->formatted();
                    break;
                default:
                    $_POST[$FormFieldName] = $Value;
                    break;
            }

            # compare user field value to field value at start of editing and current value
            # (no reason to do anything else if changed to same value)
            if ($FormChecksum == $InitialChecksum || $FormChecksum == $CurrentChecksum) {
                continue;
            }

            # log error and increment error count
            $this->logError("<b>" .$MFieldName
            ."</b> was changed while you were editing this Record. "
            .((isset($DisplayValue) && strlen($DisplayValue))
            ? "Your value was <b>".strval($DisplayValue)."</b>" :
            "Please check to confirm the updated value."));
            $Errors++;
        }
        # mark that we have validated
        self::$FieldInputIsValidated = true;
        # return error count
        return $Errors;
    }

    /**
     * Convert values from Record::get() format to display string
     * @param mixed $GetValue array of objects keyed on object IDs
     * @param MetadataField $MField field $GetValue is for
     * @return string|null display value for given value, or null
     *  if unreliable for display (often deleted) or unexpected type
     */
    private function convertMFieldValueToDisplayValue($GetValue, MetadataField $MField)
    {
        switch ($MField->type()) {
            case MetadataSchema::MDFTYPE_FLAG:
                $DisplayValue = ($GetValue) ? $MField->flagOnLabel() : $MField->flagOffLabel();
                break;
            case MetadataSchema::MDFTYPE_IMAGE:
            case MetadataSchema::MDFTYPE_FILE:
                $DisplayValue = null;
                break;
            case MetadataSchema::MDFTYPE_TREE:
            case MetadataSchema::MDFTYPE_USER:
            case MetadataSchema::MDFTYPE_CONTROLLEDNAME:
            case MetadataSchema::MDFTYPE_OPTION:
                $DisplayValue = [];
                foreach ($GetValue as $Item) {
                    if (is_object($Item) && method_exists($Item, "name")) {
                        $DisplayValue[] = $Item->name();
                    }
                }
                $DisplayValue = (count($DisplayValue) ? implode(", ", $DisplayValue) : null);
                break;
            case MetadataSchema::MDFTYPE_DATE:
                $DisplayValue = ($GetValue instanceof Date) ? $GetValue->formatted() : $GetValue;
                break;
            default:
                $DisplayValue = (is_string($GetValue)) ? $GetValue : null;
                break;
        }
        return $DisplayValue;
    }

    /**
     * Get form input name for adding/retrieving value from form
     * @param string $MFieldName name of metadata field to get form name for
     * @return string form input name
     */
    private function getChecksumFormFieldName(string $MFieldName): string
    {
        return "F_Initial_".str_replace(" ", "-", $MFieldName);
    }

    /**
     * Get checksum-string for given value
     * @param mixed $Value Some value to convert into checksum-string format
     * @return string checksum representing passed value
     */
    private function getChecksumForValue($Value): string
    {
        return (is_string($Value)) ? md5($Value) : md5(serialize($Value));
    }

    # ---- PUBLIC STATIC INTERFACE -------------------------------------------

    /**
     * Validate the format of a timestamp
     * @param string $FieldName Field being validated.
     * @param mixed $FieldValues Values provided by user.
     * @return string|null Error string when something goes wrong, null
     *   otherwise.
     */
    public static function validateTimestamp($FieldName, $FieldValues)
    {
        # empty values can't be invalid
        # (FormUI has a separate check to ensure that all required values were
        # non-empty, so we don't need to do that again here)
        if (strlen($FieldValues) == 0) {
            return null;
        }

        # values that strtotime cannot parse are invalid
        if (strtotime($FieldValues) === false) {
            return "<i>".$FieldName."</i>: Invalid time/date format.";
        }

        # values with zero for the month or day are invalid (but strtotime()
        # is content to parse them, so we can't rely on that)

        # set up regexen to match common date/time formats that we want to
        # check for invalid data
        $MonthRegex = "(?:" # (?: starts a non-capturing regex group
            ."[Jj]an(?:uary)?|"
            ."[Ff]eb(?:ruary)?|"
            ."[Mm]ar(?:ch)?|"
            ."[Aa]pr(?:il)?|"
            ."[Mm]ay|"
            ."[Jj]u(?:ne?|ly?)|"
            ."[Aa]ug(?:ust)?|"
            ."[Ss]ep(?:tember)?|"
            ."[Oo]ct(?:ober)?|"
            ."(?:[Nn]ov|[Dd]ec)(?:ember)?"
            .")";

        # "(?P<xx>...)" gives a 'named capture group', where anything that matches
        # the...will be in the $Matches array with key xx.
        $Patterns = [
            # YYYY-MM-DD
            "%(?P<year>[0-9]{4})[/-](?P<month>[0-9]{2})[/-](?P<day>[0-9]{2})%",
            # MM-DD-YYYY
            "%(?P<month>[0-9]{2})[/-](?P<day>[0-9]{2})[/-](?P<year>[0-9]{4})%",
            # MM-DD-YY
            "%(?P<month>[0-9]{2})[/-](?P<day>[0-9]{2})[/-](?P<year>[0-9]{2})%",
            # DD Mon YYYY
            "%(?P<day>[0-9]{1,2}) ".$MonthRegex." (?P<year>[0-9]{4})%",
            # Mon DD, YYYY
            "%".$MonthRegex." (?P<day>[0-9]{1,2}),? (?P<year>[0-9]{4})%",
        ];
        $NonZeroGroups = ["day", "month"];

        foreach ($Patterns as $Pattern) {
            if (preg_match($Pattern, $FieldValues, $Matches)) {
                foreach ($NonZeroGroups as $Group) {
                    if (isset($Matches[$Group]) && intval($Matches[$Group]) == 0) {
                        return "<i>".$FieldName."</i>: Invalid time/date format -- "
                            .$Group." cannot be zero.";
                    }
                }
                break;
            }
        }

        return null;
    }

    /**
     * Validate the format of a timestamp
     * @param string $FieldName Field being validated.
     * @param mixed $FieldValues Values provided by user.
     * @return string|null Error string when something goes wrong, null
     *   otherwise.
     */
    public static function validateDate($FieldName, $FieldValues)
    {
        # empty values can't be invalid
        # (FormUI has a separate check to ensure that all required values were
        # non-empty, so we don't need to do that again here)
        if (strlen($FieldValues) == 0) {
            return null;
        }

        if (Date::isValidDate($FieldValues) == false) {
            return "<i>".$FieldName."</i>: Invalid date format.";
        }

        return null;
    }

    /**
     * Get/set if metadata field groups should be open by default.
     * @param bool $NewSetting New setting (OPTIONAL)
     * @return bool TRUE when groups will be open by default
     */
    public static function groupsOpenByDefault(?bool $NewSetting = null)
    {
        if (!is_null($NewSetting)) {
            self::$GroupsOpenByDefault = $NewSetting;
        }
        return self::$GroupsOpenByDefault;
    }

    /**
     * Convert a value obtained from a form field into a value suitable for
     * Record::set().
     * @param MetadataField $MField Field to use.
     * @param mixed $FormValue Value from the form - can be one of several things:
     *  integer representing object ID, array of object IDs,
     *  array of object names/titles keyed on object IDs, or string,
     *  possibly representing flag value ("0" or "1") or a date.
     * @return mixed Value for Record::set()
     */
    public static function convertFormValueToMFieldValue(
        MetadataField $MField,
        $FormValue
    ) {
        switch ($MField->type()) {
            case MetadataSchema::MDFTYPE_CONTROLLEDNAME:
            case MetadataSchema::MDFTYPE_FILE:
            case MetadataSchema::MDFTYPE_IMAGE:
            case MetadataSchema::MDFTYPE_OPTION:
            case MetadataSchema::MDFTYPE_REFERENCE:
            case MetadataSchema::MDFTYPE_TREE:
            case MetadataSchema::MDFTYPE_USER:
                # normalize form value into an array of IDs
                if (is_array($FormValue)) {
                    # check if the data we are intrested in are inside the values or not
                    # data is in the array values if the keys are sequential from 0 to N-1 and
                    # all the array values are either numeric strings or empty
                    $DataInValues = array_keys($FormValue) === range(0, count($FormValue) - 1) &&
                    array_reduce($FormValue, function ($Carry, $Value) {
                        return $Carry && (empty($Value) || is_numeric((string) $Value));
                    }, true);

                    # filter out the empty array values
                    $FormValue = array_filter($FormValue, function ($Value) {
                        return (is_object($Value) || strlen((string) $Value) > 0);
                    });

                    # if the data we are interested in is not in the array values
                    # we are then interested in the array keys
                    if (!$DataInValues) {
                        $FormValue = array_keys($FormValue);
                    }
                } else {
                    if (is_object($FormValue)) {
                        if (!method_exists($FormValue, "id")) {
                            throw new Exception("Object passed that lacks an id().");
                        }
                        $FormValue = [$FormValue->id()];
                    } else {
                        $FormValue = [$FormValue];
                    }
                }
                $Class = $MField->getClassForValues();
                if (($Class === false)
                        || !class_exists($Class)
                        || !method_exists($Class, "ItemExists")) {
                    throw new Exception("No valid class available for field \""
                            .$MField->name()."\".");
                }
                $Value = [];
                foreach ($FormValue as $Id) {
                    if ($Class::itemExists($Id)) {
                        $Value[$Id] = new $Class(intval($Id));
                    }
                }
                break;
            case MetadataSchema::MDFTYPE_DATE:
                $FormValue = trim($FormValue);
                $Value = ($FormValue == "" || !Date::isValidDate($FormValue))
                    ? false
                    : new Date($FormValue);
                break;
            case MetadataSchema::MDFTYPE_FLAG:
                $FormValue = trim($FormValue);
                $Value = ($FormValue == "1");
                break;
            case MetadataSchema::MDFTYPE_PARAGRAPH:
                # in fields that allow html and use rich text editing
                if ($MField->allowHtml() && $MField->useWysiwygEditor()) {
                    # strip trailing whitespace in the formats that CKEditor
                    # often inserts (0xC2A0 is a UTF-8 non-breaking space
                    # character)
                    $FormValue = preg_replace(
                        '%(<p>(\s|\xC2\xA0|&nbsp;)*</p>\s*)+$%i',
                        '',
                        $FormValue
                    );
                }
                $Value = trim($FormValue);
                break;
            case MetadataSchema::MDFTYPE_TEXT:
                $Value = trim($FormValue);
                break;
            case MetadataSchema::MDFTYPE_POINT:
                if ($FormValue["X"] == "" && $FormValue["Y"] == "") {
                    $Value = false;
                } else {
                    $Value = $FormValue;
                }
                break;
            default:
                $Value = $FormValue;
                break;
        }
        return $Value;
    }


    # ---- PRIVATE INTERFACE -------------------------------------------------

    protected $Record;
    private $UserCanModify = [];
    private static $FieldInputIsValidated = false;

    private static $GroupsOpenByDefault = true;

    # mapping of MetadataField types to the FormUI elements used to edit them
    private static $TypeMap = [
        MetadataSchema::MDFTYPE_CONTROLLEDNAME => FormUI::FTYPE_OPTION,
        MetadataSchema::MDFTYPE_DATE => FormUI::FTYPE_TEXT,
        MetadataSchema::MDFTYPE_FLAG => FormUI::FTYPE_OPTION,
        MetadataSchema::MDFTYPE_IMAGE => FormUI::FTYPE_IMAGE,
        MetadataSchema::MDFTYPE_NUMBER => FormUI::FTYPE_NUMBER,
        MetadataSchema::MDFTYPE_OPTION => FormUI::FTYPE_OPTION,
        MetadataSchema::MDFTYPE_PARAGRAPH => FormUI::FTYPE_PARAGRAPH,
        MetadataSchema::MDFTYPE_POINT => FormUI::FTYPE_POINT,
        MetadataSchema::MDFTYPE_REFERENCE => FormUI::FTYPE_QUICKSEARCH,
        MetadataSchema::MDFTYPE_TEXT => FormUI::FTYPE_TEXT,
        MetadataSchema::MDFTYPE_TIMESTAMP => FormUI::FTYPE_DATETIME,
        MetadataSchema::MDFTYPE_TREE => FormUI::FTYPE_OPTION,
        MetadataSchema::MDFTYPE_URL => FormUI::FTYPE_URL,
        MetadataSchema::MDFTYPE_USER => FormUI::FTYPE_USER,
        MetadataSchema::MDFTYPE_FILE => FormUI::FTYPE_FILE,
        MetadataSchema::MDFTYPE_EMAIL => FormUI::FTYPE_TEXT,
        MetadataSchema::MDFTYPE_SEARCHPARAMETERSET => FormUI::FTYPE_SEARCHPARAMS
    ];

    /**
     * Set up form fields for editing.
     * @return array Form field configuration.
     */
    protected function getFormFieldConfiguration(): array
    {
        $OrderItems = $this->Record
            ->getSchema()
            ->getEditOrder()
            ->getItems();

        $FormFields = [];

        foreach ($OrderItems as $Item) {
            # to simplify the logic below, treat single MetadataFields
            # as groups of one field with FALSE for their groupid
            if ($Item instanceof MetadataField) {
                $GroupId = false;
                $Fields = [$Item];
            } else {
                $GroupId = $Item->id();
                $Fields = $Item->getFields();
            }

            # build a list of fields in this group
            $GroupFields = [];

            # retrieve user currently logged in
            $User = User::getCurrentUser();

            # iterate over the fields in our "group"
            foreach ($Fields as $MField) {
                # skip fields the user cannot see
                if (!$this->Record->userCanViewField($User, $MField)) {
                    continue;
                }

                # get field config and values for this element
                $Key = $MField->name();
                $GroupFields[$Key] = static::getFormConfigForField(
                    $this->Record,
                    $MField,
                    $this->Record->userCanModifyField($User, $MField)
                );
            }

            # if the group contained any fields visible to the user
            if (count($GroupFields)) {
                # add a group header for explicit groups
                if ($GroupId !== false) {
                    # groups with required fields should always be open by default
                    $OpenByDefault = self::$GroupsOpenByDefault;
                    foreach ($GroupFields as $FieldInfo) {
                        if ($FieldInfo["Required"]) {
                            $OpenByDefault = true;
                            break;
                        }
                    }

                    $FormFields["GROUP_".$GroupId] = [
                        "Type" => FormUI::FTYPE_HEADING,
                        "Label" => $Item->name(),
                        "Collapsible" => true,
                        "OpenByDefault" => $OpenByDefault,
                    ];
                }

                # add the fields and values for all groups
                $FormFields += $GroupFields;

                # if we were in an explicit group, end the group
                if ($GroupId !== false) {
                    $FormFields["GROUP_".$GroupId."_END"] = [
                        "Type" => FormUI::FTYPE_GROUPEND,
                    ];
                }
            }
        }

        return $FormFields;
    }

    /**
     * Get initial values for form fields from the underlying Record object.
     * @param array $FormFields List of form fields.
     * @return array Form values
     */
    protected function getFormFieldValues($FormFields)
    {
        $TypesToSkip = [
            FormUI::FTYPE_HEADING,
            FormUI::FTYPE_GROUPEND,
        ];

        $FormValues = [];
        foreach ($FormFields as $FieldName => $FieldConfig) {
            if (in_array($FieldConfig["Type"], $TypesToSkip)) {
                continue;
            }

            $FormValues[$FieldName] = static::getFormFieldValue(
                $FieldName
            );
        }

        return $FormValues;
    }

    /**
     * Get value for a named form field from the underlying Record object.
     * @param string $FormFieldName Form field name.
     * @return mixed Value suitable for including in a form.
     */
    protected function getFormFieldValue(string $FormFieldName)
    {
        $MField = $this->Record->getSchema()->getField($FormFieldName);

        $ObjectTypes = [
            MetadataSchema::MDFTYPE_FILE,
            MetadataSchema::MDFTYPE_IMAGE,
        ];

        $ReturnObject = in_array(
            $MField->type(),
            $ObjectTypes
        );

        return $this->convertMFieldValueToFormValue(
            $MField,
            $this->Record->get($MField, $ReturnObject)
        );
    }

    /**
     * Get FormUI configuration data for a specified field.
     * @param MetadataField $MField Metadata Field
     * @param bool $UserCanModify TRUE for fields that can be modified
     * @return array Array of config data formatted for use by FormUI.
     */
    public static function getFormConfigForField(
        Record $Record,
        MetadataField $MField,
        bool $UserCanModify
    ): array {
        # error out if we don't know how to map a field
        if (!isset(self::$TypeMap[$MField->type()])) {
            throw new Exception("Unmapped field type: ".$MField->typeAsName());
        }

        $HelpType = ApplicationFramework::getInstance()->getInterfaceSetting("TooltipsUseDialogs")
                ? FormUI::HELPTYPE_DIALOG
                : FormUI::HELPTYPE_HOVER;

        # set up general options that all fields have
        $FieldOptions = [
            "Type" => self::$TypeMap[$MField->type()],
            "Label" => $MField->getDisplayName(),
            "Required" => $MField->optional() ? false : true ,
            "Help" => $MField->description(),
            "HelpType" => $HelpType,
        ];

        if (!$UserCanModify) {
            $FieldOptions["ReadOnly"] = true;
            $FieldOptions["Required"] = false;
        }

        # set up type-specific options

        # Note: when adding arrays, if a key exists in both the one from the
        # right-hand array will be ignored, so we need the type specific options
        # on the left for them to override the defaults
        # (see https://www.php.net/manual/en/language.operators.array.php )
        $FieldOptions = self::getTypeSpecificOptions($MField, $UserCanModify)
            + $FieldOptions;

        if (!isset($FieldOptions["AdditionalHtml"])) {
            $FieldOptions["AdditionalHtml"] = "";
        }
        $FieldOptions["AdditionalHtml"] .=
            ApplicationFramework::getInstance()->formatInsertionKeyword(
                "FIELDEDIT",
                [
                    "FieldId" => $MField->id(),
                    "RecordId" => $Record->id(),
                ]
            );

        return $FieldOptions;
    }

    /**
     * Get type specific form field options for a given field.
     * @param MetadataField $MField Field to get options for
     * @param bool $UserCanModify TRUE for fields that can be modified
     * @return array Type specific FormUI options
     */
    private static function getTypeSpecificOptions(
        MetadataField $MField,
        bool $UserCanModify
    ): array {
        $AF = ApplicationFramework::getInstance();
        $Options = [];

        # retrieve user currently logged in
        $User = User::getCurrentUser();

        switch ($MField->type()) {
            case MetadataSchema::MDFTYPE_EMAIL:
                $Options["ValidateFunction"] = [static::class, "validateEmail"];
                # fall through

            case MetadataSchema::MDFTYPE_TEXT:
            case MetadataSchema::MDFTYPE_NUMBER:
            case MetadataSchema::MDFTYPE_URL:
                $Options["MaxLength"] = $MField->maxLength();
                $Options["Size"] = $MField->textFieldSize();
                break;

            case MetadataSchema::MDFTYPE_OPTION:
                $AllowedValues = $MField->getPossibleValues();
                $Options["AllowMultiple"] = $MField->allowMultiple();
                $Options["OptionType"] = FormUI::OTYPE_INPUTSET;
                $Options["Options"] = $AllowedValues;
                $Options["Rows"] = count($AllowedValues);

                if ($UserCanModify && $MField->optional()) {
                    $Options["AdditionalHtml"] = "<button type='button' "
                        ."class='mv-reui-clear btn btn-primary mv-button-iconed' "
                        ."data-fieldname='".htmlspecialchars($MField->name())."' "
                        ."><img src='".$AF->gUIFile("Broom.svg")
                        ."' alt='' class='mv-button-icon' /> Clear</button>";
                }
                break;

            case MetadataSchema::MDFTYPE_CONTROLLEDNAME:
            case MetadataSchema::MDFTYPE_TREE:
                # in self::$TypeMap, we set the default interface widget
                # for these fields to FTYPE_OPTION, but that may need to be
                # overridden depending on how many terms are in the vocabulary
                # for this field

                if ($UserCanModify &&
                    ($MField->getCountOfPossibleValues() > $MField->optionListThreshold())) {
                    $ShowAddButton = "false";
                    if ($MField->type() == MetadataSchema::MDFTYPE_CONTROLLEDNAME &&
                        $User->hasPriv(PRIV_NAMEADMIN)) {
                        $ShowAddButton = "true";
                    }
                    $Options["AdditionalHtml"] = "<button type='button' "
                        ."class='mv-reui-search btn btn-primary' "
                        ."data-fieldid='".$MField->id()."' "
                        ."data-schemaid='".$MField->schemaId()."' "
                        ."data-fieldtype='".$MField->typeAsName()."' "
                        ."data-fieldname='".htmlspecialchars($MField->name())."' "
                        ."data-showaddbutton='".$ShowAddButton."' "
                        .">Search</button>";
                }

                # if the vocab has more than ajaxThreshold options, use a
                # quicksearch box
                if ($MField->getCountOfPossibleValues() >= $MField->ajaxThreshold()) {
                    $Options["Type"] = FormUI::FTYPE_QUICKSEARCH;
                    $Options["Field"] = $MField->id();
                    break;
                }

                # get the list of allowed values
                $AllowedValues = $MField->getPossibleValues();

                # if it's more than the option list threshold
                if (count($AllowedValues) > $MField->optionListThreshold()) {
                    # if the field allows multiple entries, then use a list
                    # set instead of a single option field
                    if ($MField->allowMultiple()) {
                        $Options["OptionType"] = FormUI::OTYPE_LISTSET;
                    }
                } elseif ($UserCanModify && $MField->optional()) {
                    # when less than the option list threshold, add a 'Clear' button
                    # for modifiable and optional fields
                    $Options["AdditionalHtml"] = "<button type='button' "
                        ."class='mv-reui-clear btn btn-primary mv-button-iconed' "
                        ."data-fieldname='".htmlspecialchars($MField->name())."' "
                        ."><img src='".$AF->gUIFile('Broom.svg')
                        ."' alt='' class='mv-button-icon' /> Clear</button>";
                }

                $Options["AllowMultiple"] = $MField->allowMultiple();
                $Options["OptionThreshold"] = $MField->optionListThreshold();
                $Options["Options"] = $AllowedValues;
                $Options["Rows"] = count($AllowedValues);
                break;

            case MetadataSchema::MDFTYPE_REFERENCE:
                $Options["AllowMultiple"] = $MField->allowMultiple();
                $Options["Field"] = $MField->id();
                break;

            case MetadataSchema::MDFTYPE_USER:
                $Options["AllowMultiple"] = $MField->allowMultiple();
                $Options["Field"] = $MField->id();

                # provide an update button if configured to do so
                if ($User->isLoggedIn() && $UserCanModify &&
                    $MField->updateMethod() == MetadataField::UPDATEMETHOD_BUTTON) {
                    # single-value fields: set to current user
                    # multi-value fields: append current user
                    $Options["AdditionalHtml"] = "<button type='button' "
                        ."class='mv-reui-update-user btn btn-primary' "
                        ."data-userid='".$User->id()."' "
                        ."data-username='".htmlspecialchars($User->get("UserName"))
                        ."' "
                        ."data-allowmultiple='".($MField->allowMultiple() ? "true" : "false")."' "
                        .">Update</button>";
                }
                break;

            case MetadataSchema::MDFTYPE_FLAG:
                $AllowedValues = $MField->getPossibleValues();
                $Options["AllowMultiple"] = false;
                $Options["Options"] = $AllowedValues;
                $Options["Rows"] = count($AllowedValues);
                break;

            case MetadataSchema::MDFTYPE_PARAGRAPH:
                $Options["Rows"] = $MField->paragraphRows();
                $Options["Columns"] = $MField->paragraphCols();
                $Options["UseWYSIWYG"] = $MField->allowHtml() &&
                    $MField->useWysiwygEditor();
                break;

            case MetadataSchema::MDFTYPE_TIMESTAMP:
                $Options["ValidateFunction"] = [static::class, "validateTimestamp"];
                if ($UserCanModify &&
                    $MField->updateMethod() == MetadataField::UPDATEMETHOD_BUTTON) {
                    $Options["UpdateButton"] = true;
                }
                break;

            case MetadataSchema::MDFTYPE_DATE:
                $Options["ValidateFunction"] = [static::class, "validateDate"];
                break;

            case MetadataSchema::MDFTYPE_POINT:
                $Options["Size"] = $MField->pointDecimalDigits() +
                    $MField->pointPrecision() + 1;
                break;

            case MetadataSchema::MDFTYPE_IMAGE:
            case MetadataSchema::MDFTYPE_FILE:
                $Options["AllowMultiple"] = $MField->allowMultiple();
                break;

            case MetadataSchema::MDFTYPE_SEARCHPARAMETERSET:
                break;
        }

        return $Options;
    }

    /**
     * Convert values from a MetadataField into the format required by a form field.
     * @param MetadataField $MField Field from whence the value came
     * @param mixed $FieldValue Field value.
     * @return mixed Value for form.
     */
    private static function convertMFieldValueToFormValue(MetadataField $MField, $FieldValue)
    {
        switch ($MField->type()) {
            case MetadataSchema::MDFTYPE_OPTION:
            case MetadataSchema::MDFTYPE_USER:
                $Value = array_keys($FieldValue);
                break;

            case MetadataSchema::MDFTYPE_CONTROLLEDNAME:
            case MetadataSchema::MDFTYPE_TREE:
                if ($MField->getCountOfPossibleValues() < $MField->ajaxThreshold()) {
                    $Value = array_keys($FieldValue);
                    break;
                }

                /* fall through */
            default:
                $Value = $FieldValue;
                break;
        }

        return $Value;
    }

    /**
     * Set values in our underlying record based on form field data.
     * @param string $FormFieldName Field to set
     * @param mixed $Values New values in the format provided by the editing form
     * @return bool TRUE when the record was changed, FALSE otherwise
     */
    protected function saveValueFromFormField($FormFieldName, $Values): bool
    {
        # if we have no information on this field,
        if (!isset($this->UserCanModify[$FormFieldName])) {
            throw new Exception(
                "Attempt to set a value for ".$FormFieldName.", which "
                ."does not correspond to a Metadata field.Missing implementation "
                ." in saveValueFromFormField() for a parent class?"
            );
        }

        # if user can't actually modify this field, skip it
        if (!$this->UserCanModify[$FormFieldName]) {
            return false;
        }

        $MField = $this->Record->getSchema()->getField($FormFieldName);

        $Values = self::convertFormValueToMFieldValue($MField, $Values);

        # signal POST_FIELD_EDIT_FILTER
        $SignalResult = ApplicationFramework::getInstance()->signalEvent(
            "EVENT_POST_FIELD_EDIT_FILTER",
            [
                "Field" => $MField,
                "Resource" => $this->Record,
                "Value" => $Values,
            ]
        );

        $Values = $SignalResult["Value"];

        # if the filtered value is the same as the original,
        # then nothing needs to be changed and we can move on to the next
        # field
        if ($Values == $this->Record->get($MField)) {
            return false;
        }

        $this->Record->set($MField, $Values, true);
        $this->FieldValues[$FormFieldName] = $this->convertMFieldValueToFormValue(
            $MField,
            $this->Record->get($MField)
        );

        return true;
    }

    /**
     * Determine if a specified field name is valid for this form.Child
     * classes can extend this method to support additional, non-Metaadata
     * backed information in forms they provide.
     * @param string $FormFieldName Field to check
     * @return bool TRUE for valid fields, FALSE otherwise
     */
    protected function isFormFieldValid(string $FormFieldName): bool
    {
        return $this->Record->getSchema()->fieldExists($FormFieldName);
    }

    const LOADING_IMG_FILE_NAME = "loading.gif";
}
