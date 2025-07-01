<?PHP
#
#   FILE:  FormUI_Base.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2016-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus;
use Exception;
use InvalidArgumentException;
use ScoutLib\ApplicationFramework;
use ScoutLib\PluginManager;
use ScoutLib\StdLib;

/**
 * Base class (covering non-presentation elements) supplying a standard user
 * interface for presenting and working with HTML forms.
 */
abstract class FormUI_Base
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /** Supported field types. */
    const FTYPE_FILE = "File";
    const FTYPE_FLAG = "Flag";
    const FTYPE_IMAGE = "Image";
    const FTYPE_METADATAFIELD = "MetadataField";
    const FTYPE_NUMBER = "Number";
    const FTYPE_OPTION = "Option";
    const FTYPE_PARAGRAPH = "Paragraph";
    const FTYPE_PASSWORD = "Password";
    const FTYPE_PRIVILEGES = "Privileges";
    const FTYPE_SEARCHPARAMS = "Search Parameters";
    const FTYPE_TEXT = "Text";
    const FTYPE_DATETIME = "Datetime";
    const FTYPE_URL = "URL";
    const FTYPE_USER = "User";
    const FTYPE_QUICKSEARCH = "Quick Search";
    const FTYPE_POINT = "Point";

    /** Supported field pseudo-types. */
    const FTYPE_HEADING = "Heading";
    const FTYPE_CAPTCHA = "Captcha";
    const FTYPE_CUSTOMCONTENT = "Custom Content";
    const FTYPE_GROUPEND = "Group End";

    /** Option field input types. */
    const OTYPE_INPUTSET = "HtmlInputSet";  # radio buttons or checkboxes
    const OTYPE_LIST = "OptionList";        # single option list
    const OTYPE_LISTSET = "OptionListSet";  # multiple option lists dynamically-created

    /** Help display types. */
    const HELPTYPE_DIALOG = "Help Dialog Text";
    const HELPTYPE_HOVER = "Help Hover Text";
    const HELPTYPE_COLUMN = "Help Column";

    /**
     * Class constructor.
     * @param array $FieldParams Associative array of associative arrays of
     *       form field parameters, with field names for the top index.
     * @param array $FieldValues Associative array of current values for
     *       form fields, with field names for the index.  (OPTIONAL, as values
     *       may also be supplied via $FieldParams.)
     * @param string $UniqueKey Unique string to include in form field names
     *       to distinguish them from other fields in the form.  (OPTIONAL)
     */
    public function __construct(
        array $FieldParams,
        array $FieldValues = [],
        ?string $UniqueKey = null
    ) {
        $ErrMsgs = [];
        $this->checkForUnrecognizedFieldParameters($FieldParams, $ErrMsgs);
        $this->checkForMissingFieldParameters($FieldParams, $ErrMsgs);
        $this->checkForInvalidFieldParameters($FieldParams, $ErrMsgs);

        if (count($ErrMsgs)) {
            $ErrMsgString = implode("  ", $ErrMsgs);
            throw new InvalidArgumentException($ErrMsgString);
        }

        # check for exceeded configured PHP POST size limit (post_max_size)
        $MaxPostSize = StdLib::convertPhpIniSizeToBytes(
            (string)ini_get("post_max_size")
        );
        if (empty($_POST) &&
            isset($_SERVER["CONTENT_LENGTH"]) && $_SERVER['CONTENT_LENGTH'] > $MaxPostSize) {
            if ($_SERVER['REQUEST_METHOD'] == 'POST') {
                $Message = "The content in your form exceeds the current POST "
                    ."size limit of ".($MaxPostSize / 1000000)." MB. This is "
                    ."usually from uploaded files that are too large. Please "
                    ."contact your server administrator if you need this limit "
                    ."(set via the PHP configuration parameter "
                    ."\"post_max_size\") increased.";
                $this->logError($Message, null, $this->UniqueKey);
            }
        }

        $this->normalizeFieldParameters($FieldParams);

        # save form parameters and values
        $this->FieldParams = $FieldParams;
        $this->FieldValues = $FieldValues;
        $this->UniqueKey = $UniqueKey;

        $this->AddedImages = $_POST[$this->getFormFieldName("AddedImageIds")] ?? [];
        $this->AddedFiles = $_POST[$this->getFormFieldName("AddedFileIds")] ?? [];
    }

    /**
     * Add additional field parameter, to recognize (as legal) and ignore.
     * @param string $ParamName Parameter name.
     * @param array $FieldTypes Field types for which parameter is valid.
     *       (OPTIONAL, defaults to all field types)
     * @return void
     */
    public static function ignoreFieldParameter(
        string $ParamName,
        ?array $FieldTypes = null
    ) : void {
        if ((in_array($ParamName, self::$UniversalFieldParameters))
                || isset(self::$TypeSpecificFieldParameters[$ParamName])) {
            throw new InvalidArgumentException("Cannot ignore existing"
                    ." field parameter '".$ParamName."'.");
        }

        if ($FieldTypes === null) {
            self::$UniversalFieldParameters[] = $ParamName;
        } else {
            self::$TypeSpecificFieldParameters[$ParamName] = $FieldTypes;
        }
    }

    /**
     * Validate initial values of form fields.
     * Any errors found are logged and can be retrieved with getLoggedErrors()
     * or displayed with displayErrorBlock().
     * @return int Number of errors found.
     */
    public function validateInitialValues(): int
    {
        $ErrorsFound = 0;
        foreach ($this->FieldParams as $Name => $Params) {
            $ErrorsFound +=
                    $this->validateFieldValue($Name, $this->FieldValues);
        }
        return $ErrorsFound;
    }

    /**
     * Add extra hidden field to form.
     * @param string $FieldName Form field name.
     * @param string $Value Form field value.
     * @return void
     */
    public function addHiddenField(string $FieldName, string $Value): void
    {
        $this->AdditionalHiddenFields[$FieldName] = $Value;
    }

    /**
     * Display HTML table with settings parameters.
     * @param string $TableId CSS ID for table element.  (OPTIONAL)
     * @param string $TableStyle CSS styles for table element.  (OPTIONAL)
     * @param string $TableCssClass Additional CSS class for table element. (OPTIONAL)
     * @return void
     */
    abstract public function displayFormTable(
        ?string $TableId = null,
        ?string $TableStyle = null,
        ?string $TableCssClass = null
    ) : void;

    /**
     * Get HTML for button with form-appropriate submit name.  If no icon file is
     * specified, an icon appropriate for the label will be used, if available.
     * If no additional classes are specified, any additional class appropriate
     * for the label will be used, if available.
     * @param string $Label Label to display on button.
     * @param string $IconFile Name of icon file (without leading path).  (OPTIONAL)
     * @param string $Classes Additional CSS classes for button.  (OPTIONAL)
     * @return string HTML for button.
     * @see getButtonName()
     */
    abstract public function getSubmitButtonHtml(
        string $Label,
        ?string $IconFile = null,
        ?string $Classes = null
    ): string;

    /**
     * Get value of the submit button that has standard name for this form.
     * (This usually covers file or image Delete or Add buttons, buttons
     * displayed with displaySubmitButton(), and any button that uses a name
     * obtained from getButtonName().)
     * @return string|null Button value or NULL if none available.
     * @see displaySubmitButton()
     * @see getButtonName()
     */
    public function getSubmitButtonValue(): ?string
    {
        return StdLib::getFormValue($this->getButtonName());
    }

    /**
     * Log error message for later display.
     * @param string $Msg Error message.
     * @param string $Field Field associated with error.  (OPTIONAL, defaults
     *       to no field association)
     * @param string $UniqueKey key to log error under, for form specific error handling
     * @return void
     */
    public static function logError(string $Msg, ?string $Field = null, $UniqueKey = ""): void
    {
        self::$ErrorMessages[$UniqueKey][$Field][] = $Msg;
    }

    /**
     * Get logged errors.
     * @param string $UniqueKey Key for errors to retrieve, or return all
     *      errors if no key specified.  (OPTIONAL).
     * @return array Logged errors, with associated fields for the index (NULL
     *      for errors with no association) and an array of error messages for
     *      each value.
     */
    public static function getLoggedErrors($UniqueKey = null): array
    {
        if ($UniqueKey !== null) {
            return self::$ErrorMessages[$UniqueKey] ?? [];
        } else {
            $AllErrors = [];
            foreach (self::$ErrorMessages as $ErrorsByField) {
                foreach ($ErrorsByField as $Field => $Errors) {
                    $AllErrors[$Field] = isset($AllErrors[$Field])
                            ? array_merge($AllErrors[$Field], $Errors)
                            : $Errors;
                }
            }
            return $AllErrors;
        }
    }

    /**
     * Report whether errors have been logged.
     * @param mixed $Field Field to check -- specify NULL to check for any
     *       errors with no field associated.  (OPTIONAL)
     * @param string $UniqueKey representing the UniqueKey of the field/errors to check (OPTIONAL).
     * @return bool TRUE if errors have been logged, otherwise FALSE.
     */
    public static function errorsLogged($Field = false, $UniqueKey = null): bool
    {
        if ($Field === false) {
            if (!is_null($UniqueKey)) {
                return count(self::$ErrorMessages[$UniqueKey]) ? true : false;
            } else {
                foreach (self::$ErrorMessages as $KeyErrorMessage) {
                    if (count($KeyErrorMessage)) {
                        return true;
                    }
                }
                return false;
            }
        } elseif (!is_null($UniqueKey)) {
            return isset(self::$ErrorMessages[$UniqueKey][$Field]) ? true : false;
        } else {
            foreach (self::$ErrorMessages as $KeyErrorMessage) {
                if (isset($KeyErrorMessage[$Field])) {
                    return true;
                }
            }
            return false;
        }
    }

    /**
     * Clear logged errors.
     * @param string $Field Clear only errors for specified field.  (OPTIONAL)
     * @param string $UniqueKey representing the UniqueKey of errors to remove (OPTIONAL).
     * @return void
     */
    public static function clearLoggedErrors(?string $Field = null, $UniqueKey = null): void
    {
        if ($Field === null) {
            if (strlen($UniqueKey)) {
                unset(self::$ErrorMessages[$UniqueKey]);
            } else {
                self::$ErrorMessages = [];
            }
        } elseif (!is_null($UniqueKey)) {
            unset(self::$ErrorMessages[$UniqueKey][$Field]);
        } else {
            foreach (self::$ErrorMessages as $KeyIndex => $KeyErrorMessage) {
                unset(self::$ErrorMessages[$KeyIndex][$Field]);
            }
        }
    }

    /**
     * Validate field values on submitted form.  Validation functions (specified
     * via the "ValidateFunction" parameter) should take a field name and value
     * as parameters, and return NULL if the field validates successfully, or
     * an error message if it does not.
     * @return int Number of fields with invalid values found.
     */
    public function validateFieldInput(): int
    {
        # retrieve raw field values
        $Values = $this->getRawNewValuesFromForm();

        # standardize data encoding
        $Values = $this->normalizeFormValueEncoding($Values);

        # for each field
        $ErrorsFound = 0;
        foreach ($this->FieldParams as $Name => $Params) {
            # verify any captchas
            if ($Params["Type"] == self::FTYPE_CAPTCHA) {
                if (!PluginManager::getInstance()->pluginReady("Captcha")) {
                    continue;
                }

                $CPlugin = $GLOBALS["G_PluginManager"]->getPlugin("Captcha");
                $CaptchaKey = md5($this->UniqueKey.$Name);
                if ($CPlugin->verifyCaptcha($CaptchaKey) === false) {
                    self::logError(
                        "CAPTCHA anti-spam verification failed.",
                        $Name,
                        $this->UniqueKey
                    );
                    $ErrorsFound++;
                }

                continue;
            }

            # nothing to do for pseudo fields
            if ($this->isFieldPseudoType($Params["Type"])) {
                continue;
            }

            # nothing to do for read only fields
            if ($this->isReadOnly($Name)) {
                continue;
            }

            # skip fields not shown to the user
            if (!$this->fieldWasDisplayedWhenFormWasSubmitted($Name)) {
                continue;
            }

            $ErrorsFound += $this->validateFieldValue($Name, $Values);
        }

        # report number of fields with invalid values found to caller
        return $ErrorsFound;
    }

    /**
     * Add values to be passed to input validation functions, in addition
     * to field name and value.
     * @return void
     * @see FormUI_Base::ValidateFieldInput()
     */
    public function addValidationParameters(): void
    {
        $this->ExtraValidationParams = func_get_args();
    }

    /**
     * Retrieve values set by form with per-field value normalization.
     * @return array Array of configuration settings, with setting names
     *       for the index, and new setting values for the values.
     */
    public function getNewValuesFromForm() : array
    {
        # start with raw values
        $NewSettings = $this->getRawNewValuesFromForm();

        # apply normalization that standardizes encoding (e.g., line endings)
        $NewSettings = $this->normalizeFormValueEncoding($NewSettings);

        # apply normalization that standardizes data formats (e.g., dates)
        $NewSettings = $this->normalizeFormValueData($NewSettings);

        # add in values for read only fields not present in form data
        $NewSettings = $this->setValuesForReadOnlyFields($NewSettings);

        return $NewSettings;
    }

    /**
     * Get value for form field.
     * @param string $FieldName Canonical field name.
     * @return mixed Value or array of values for field.
     * @throws InvalidArgumentException when provided field name is not valid.
     */
    public function getFieldValue(string $FieldName)
    {
        if (!isset($this->FieldParams[$FieldName])) {
            throw new InvalidArgumentException(
                "Attempt to retrieve value for invalid field: ".
                $FieldName
            );
        }

        # get base form field name
        $FieldType = $this->FieldParams[$FieldName]["Type"];
        $FormFieldName = $this->getFormFieldName(
            $FieldName,
            ($FieldType != self::FTYPE_PRIVILEGES)
        );

        $Value = $this->getFallbackValueForField($FieldName);

        switch ($FieldType) {
            case self::FTYPE_FILE:
            case self::FTYPE_IMAGE:
                # get name of image ID form field
                $FileIdFieldName = $FormFieldName."_ID";

                # use an updated value for this field if available
                if (isset($this->HiddenFields[$FileIdFieldName])) {
                    $Value = $this->HiddenFields[$FileIdFieldName];
                } elseif (isset($_POST[$FileIdFieldName])) {
                    # else use a value from form if available
                    $Value = $_POST[$FileIdFieldName];
                }

                # add in any previously-set extra values
                if (isset($this->ExtraValues[$FileIdFieldName])
                        && count($this->ExtraValues[$FileIdFieldName])) {
                    if (!is_array($Value)) {
                        $Value = [$Value];
                    }
                    $Value = array_merge(
                        $Value,
                        $this->ExtraValues[$FileIdFieldName]
                    );
                }
                break;

            case self::FTYPE_SEARCHPARAMS:
                # use incoming form value if available
                if (isset($_POST[$FormFieldName])) {
                    # use incoming form value
                    $SPEditor = new SearchParameterSetEditingUI($FormFieldName);
                    $Value = $SPEditor->getValuesFromFormData();
                }
                break;

            case self::FTYPE_PRIVILEGES:
                # use incoming form value if available
                $Schemas = StdLib::getArrayValue(
                    $this->FieldParams[$FieldName],
                    "Schemas"
                );
                $MFields = StdLib::getArrayValue(
                    $this->FieldParams[$FieldName],
                    "MetadataFields",
                    []
                );
                $PEditor = new PrivilegeEditingUI($Schemas, $MFields);
                $PSet = $PEditor->getPrivilegeSetFromForm($FormFieldName);
                if ($PSet instanceof PrivilegeSet) {
                    # use incoming form value
                    $Value = $PSet;
                }
                break;

            case self::FTYPE_QUICKSEARCH:
                $MField = MetadataField::getField(
                    $this->FieldParams[$FieldName]["Field"]
                );

                # use incoming values if available
                if (isset($_POST[$FormFieldName])) {
                    $Value = static::convertItemIdsToNames(
                        $MField,
                        $_POST[$FormFieldName]
                    );
                }
                break;

            case self::FTYPE_POINT:
                if (isset($_POST[$FormFieldName."_X"]) &&
                    isset($_POST[$FormFieldName."_Y"])) {
                    $Value = [
                        "X" => $_POST[$FormFieldName."_X"],
                        "Y" => $_POST[$FormFieldName."_Y"],
                    ];
                }
                break;

            default:
                # use incoming form value if available
                if (isset($_POST[$FormFieldName])) {
                    # use incoming form value
                    $Value = $_POST[$FormFieldName];
                }
                break;
        }

        # return value found to caller
        return $Value;
    }

    /**
     * Get the name of the submit button for file/image deletion/upload for
     *   this form.
     * @return string button name
     */
    public function getButtonName() : string
    {
        return $this->getFormFieldName("FormUISubmit");
    }

    /**
     * Handle image and file uploads.
     * @return void
     */
    public function handleUploads(): void
    {
        # for each form field
        foreach ($this->FieldParams as $FieldName => $FieldParams) {
            # move on to next field if this field does not allow uploads
            if (($FieldParams["Type"] != self::FTYPE_FILE)
                    && ($FieldParams["Type"] != self::FTYPE_IMAGE)) {
                continue;
            }

            # get the form field name
            $FormFieldName = $this->getFormFieldName($FieldName);

            # defaults
            $FileErrorCode = UPLOAD_ERR_OK;
            $UploadedFileName = null;

            # check for an upload via filepond
            $TmpFile = $this->preprocessFilepondUpload($FormFieldName);

            # if there was not one, look for an upload in _FILES
            if ($TmpFile === null) {
                # move on to next field if this field does not have an uploaded file
                if (!isset($_FILES[$FormFieldName]["name"])) {
                    continue;
                }
                $UploadedFileName = $_FILES[$FormFieldName]["name"];
                if (!strlen($UploadedFileName)) {
                    continue;
                }

                # check if the uploaded file has a temp file name generated
                if (!isset($_FILES[$FormFieldName]["tmp_name"])) {
                    continue;
                }
                $TmpFile = $_FILES[$FormFieldName]["tmp_name"];

                # check if the uploaded file has an error code.
                # there are error codes for successful and unsuccessful uploads,
                # however this check accounts for any server mishaps that would
                # lead to an unset error code.
                if (!isset($_FILES[$FormFieldName]['error'])) {
                    continue;
                }
                $FileErrorCode = $_FILES[$FormFieldName]['error'];
            }

            # if we don't have a file name, use the basename of the temp file
            if ($UploadedFileName === null) {
                $UploadedFileName = basename($TmpFile);
            }

            # check for errors
            switch ($FileErrorCode) {
                # if no error, we can proceed
                case UPLOAD_ERR_OK:
                    break;

                # check if file exceeded configured PHP upload size limit (upload_max_filesize)
                case UPLOAD_ERR_INI_SIZE:
                    $MaxFileSize = StdLib::convertPhpIniSizeToBytes(
                        (string)ini_get("upload_max_filesize")
                    );
                    $Message = "The file ".$UploadedFileName." exceeds the current"
                        ." upload size limit of ".($MaxFileSize / 1000000)." MB."
                        ." Please contact your server administrator if you need"
                        ." this limit (set via the PHP configuration parameter"
                        ." \"upload_max_filesize\") increased.";
                    $this->logError(
                        $Message,
                        $FieldName,
                        $this->UniqueKey
                    );
                    continue 2;

                # for other errors report that something went wrong and pass
                # the error code up to the user
                default:
                    $Message = "Error uploading file."
                        ." Error code: ".$FileErrorCode;
                    $this->logError(
                        $Message,
                        $FieldName,
                        $this->UniqueKey
                    );
                    continue 2;
            }

            switch ($FieldParams["Type"]) {
                case self::FTYPE_FILE:
                    # create new file object from uploaded file
                    $File = File::create($TmpFile, $UploadedFileName);

                    # check for errors during file object creation
                    if (!$File instanceof File) {
                        switch ($File) {
                            case File::FILESTAT_ZEROLENGTH:
                                $this->logError(
                                    "Uploaded file ".$UploadedFileName." was empty"
                                    ." (zero length).",
                                    $FieldName,
                                    $this->UniqueKey
                                );
                                break;

                            default:
                                $ErrorName = StdLib::getConstantName(
                                    "Metavus\\File",
                                    $File,
                                    "FILESTAT_"
                                );

                                $Message = "Error encountered with uploaded file "
                                    .$UploadedFileName.": ".$ErrorName."(Code ".$File."). "
                                    ."Temp upload location was ".$TmpFile.".";
                                $this->logError(
                                    $Message,
                                    $FieldName,
                                    $this->UniqueKey
                                );
                                break;
                        }
                    } else {
                        # add file ID to extra values
                        $this->ExtraValues[$FormFieldName."_ID"][] = $File->id();
                        $this->AddedFiles[] = $File->id();
                    }
                    break;

                case self::FTYPE_IMAGE:
                    $Errors = ImageFactory::checkImageStorageDirectories();

                    if (count($Errors) > 0) {
                        $this->logError(
                            "Problems with file storage directories: "
                            .implode(", ", $Errors),
                            $FieldName,
                            $this->UniqueKey
                        );
                        break;
                    }

                    # create new image object from uploaded file
                    try {
                        $Image = Image::create(
                            $TmpFile
                        );
                    } catch (Exception $Ex) {
                        $this->logError(
                            "Problem uploading file: "
                            .$Ex->getMessage(),
                            $FieldName,
                            $this->UniqueKey
                        );
                        break;
                    }

                    # set image object alternate text
                    $Image->altText($_POST[$FormFieldName."_AltText_NEW"]);

                    # add image ID to extra values
                    $this->ExtraValues[$FormFieldName."_ID"][] = $Image->id();
                    $this->AddedImages[] = $Image->id();
                    break;
            }

            # clean up filepond uploads, if any
            $this->postprocessFilepondUpload($FormFieldName);
        }
    }

    /**
     * Handle image and file deletions.
     * @return void
     */
    public function handleDeletes(): void
    {
        # if image ID to delete was supplied
        $DeleteFieldName = $this->getFormFieldName("ImageToDelete");
        $IncomingValue = StdLib::getFormValue($DeleteFieldName);
        if (is_numeric($IncomingValue)
                || (is_array($IncomingValue) && count($IncomingValue))) {
            # retrieve ID of image
            $ImageId = is_array($IncomingValue)
                    ? array_shift($IncomingValue)
                    : $IncomingValue;

            # add ID to deleted images list
            if (is_numeric($ImageId)) {
                $this->DeletedImages[] = $ImageId;
            }
        }

        # if file ID to delete was supplied
        $DeleteFieldName = $this->getFormFieldName("FileToDelete");
        $IncomingValue = StdLib::getFormValue($DeleteFieldName);
        if (is_numeric($IncomingValue)
                || (is_array($IncomingValue) && count($IncomingValue))) {
            # retrieve ID of file
            $FileId = is_array($IncomingValue)
                    ? array_shift($IncomingValue)
                    : $IncomingValue;

            # add ID to deleted files list
            if (is_numeric($FileId)) {
                $this->DeletedFiles[] = $FileId;
            }
        }
    }

    /**
     * Delete uploaded files and images, to be used when editing is canceled
     * without associating uploads with a record.
     * @return void
     */
    public function deleteUploads(): void
    {
        foreach ($this->AddedFiles as $FileId) {
            (new File($FileId))->destroy();
        }

        foreach ($this->AddedImages as $ImageId) {
            (new Image($ImageId))->destroy();
        }
    }

    /**
     * Set event to signal when retrieving values from form when settings
     * have changed.  If the supplied event parameters include parameter
     * names (indexes) of "SettingName", "OldValue", or "NewValue", the
     * parameter value will be replaced with an appropriate value before
     * the event is signaled.
     * @param string $EventName Name of event to signal.
     * @param array $EventParams Array of event parameters, with CamelCase
     *       parameter names for index.  (OPTIONAL)
     * @return void
     * @see FormUI_Base::GetNewsettingsFromForm()
     */
    public function setEventToSignalOnChange(
        string $EventName,
        array $EventParams = []
    ): void {
        $this->SettingChangeEventName = $EventName;
        $this->SettingChangeEventParams = $EventParams;
    }

    /**
     * Determine if a new form field value is different from an old one.
     * @param mixed $OldValue Old field value.
     * @param mixed $NewValue New field value.
     * @return bool Returns TRUE if the values are different and FALSE otherwise.
     */
    public static function didValueChange($OldValue, $NewValue): bool
    {
        # didn't change if they are identical
        if ($OldValue === $NewValue) {
            return false;
        }

        # need special cases from this point because PHP returns some odd results
        # when performing loose equality comparisons:
        # http://php.net/manual/en/types.comparisons.php#types.comparisions-loose

        # consider NULL and an empty string to be the same. this is in case a field
        # is currently set to NULL and receives an empty value from the form.
        # $_POST values are always strings
        if ((is_null($OldValue) && is_string($NewValue) && !strlen($NewValue))
            || (is_null($NewValue) && is_string($OldValue) && !strlen($OldValue))) {
            return false;
        }

        # if they both appear to be numbers and are equal
        if (is_numeric($OldValue) && is_numeric($NewValue) && $OldValue == $NewValue) {
            return false;
        }

        # true-like values
        if (($OldValue === true && ($NewValue === 1 || $NewValue === "1"))
            || ($NewValue === true && ($OldValue === 1 || $OldValue === "1"))) {
            return false;
        }

        # false-like values
        if (($OldValue === false && ($NewValue === 0 || $NewValue === "0"))
            || ($NewValue === false && ($OldValue === 0 || $OldValue === "0"))) {
            return false;
        }

        # arrays
        if (is_array($OldValue) && is_array($NewValue)) {
            # they certainly changed if the counts are different
            if (count($OldValue) != count($NewValue)) {
                return true;
            }

            # the algorithm for associative arrays is slightly different from
            # sequential ones. the values for associative arrays must match the keys
            if (count(array_filter(array_keys($OldValue), "is_string"))) {
                foreach ($OldValue as $Key => $Value) {
                    # it changed if the keys don't match
                    if (!array_key_exists($Key, $NewValue)) {
                        return true;
                    }

                    # the arrays changed if a value changed
                    if (self::didValueChange($Value, $NewValue[$Key])) {
                        return true;
                    }
                }
            } else {
                # sequential values don't have to have the same keys, just the same
                # values

                # sort them so all the values match up if they're equal
                sort($OldValue);
                sort($NewValue);

                foreach ($OldValue as $Key => $Value) {
                    # the arrays changed if a value changed
                    if (self::didValueChange($Value, $NewValue[$Key])) {
                        return true;
                    }
                }
            }

            # the arrays are equal
            return false;
        }

        # they changed
        return true;
    }

    /**
     * Load value of requested type from supplied data.
     * @param string $Type Type of value (FTYPE_*).
     * @param mixed $Data Data to use in loading value.
     * @return int|string|File|Image|PrivilegeSet|SearchParameterSet Loaded value.
     */
    public static function loadValue(string $Type, $Data)
    {
        switch ($Type) {
            case self::FTYPE_FILE:
            case self::FTYPE_IMAGE:
                $Value = ($Data === null) ? [] : $Data;
                break;

            case self::FTYPE_PRIVILEGES:
                $Value = ($Data instanceof PrivilegeSet) ? $Data
                        : new PrivilegeSet($Data);
                break;

            case self::FTYPE_SEARCHPARAMS:
                $Value = ($Data instanceof SearchParameterSet) ? $Data
                        : new SearchParameterSet($Data);
                break;

            default:
                $Value = $Data;
                break;
        }

        return $Value;
    }

    /**
     * Validate value as valid-appearing email address.  This is intended to be
     * used with the "ValidateFunction" capability, like this:
     * @code
     *     "ValidateFunction" => ["FormUI", "ValidateEmail"],
     * @endcode
     * (The "FormUI" part will be replaced by the appropropriate FormUI object
     * before the method is called.)
     * @param string $FieldName Name of form field.
     * @param string|array $FieldValues Form values being validated.
     * @return string|null Error message or NULL if value appears valid.
     */
    public function validateEmail(string $FieldName, $FieldValues)
    {
        if (!is_array($FieldValues)) {
            $FieldValues = [$FieldValues];
        }
        foreach ($FieldValues as $Value) {
            if (trim($Value) == "") {
                continue;
            }
            if (filter_var($Value, FILTER_VALIDATE_EMAIL) === false) {
                return "The value for <i>".$this->FieldParams[$FieldName]["Label"]
                        ."</i> does not appear to be a valid email address.";
            }
        }
        return null;
    }

    /**
     * Validate value as valid-appearing URL.  This is intended to be used
     * with the "ValidateFunction" capability, like this:
     * @code
     *     "ValidateFunction" => ["FormUI", "ValidateUrl"],
     * @endcode
     * (The "FormUI" part will be replaced by the appropriate FormUI object
     * before the method is called.)
     * @param string $FieldName Name of form field.
     * @param string|array $FieldValues Form values being validated.
     * @return string|null Error message or NULL if value appears valid.
     */
    public function validateUrl(string $FieldName, $FieldValues)
    {
        if (!is_array($FieldValues)) {
            $FieldValues = [$FieldValues];
        }
        foreach ($FieldValues as $Value) {
            if (trim($Value) == "") {
                continue;
            }
            if (filter_var($Value, FILTER_VALIDATE_URL) === false) {
                return "The value for <i>".$this->FieldParams[$FieldName]["Label"]
                        ."</i> does not appear to be a valid URL.";
            }
        }
        return null;
    }

    /**
     * Validate value as valid host name (i.e. one that can be resolved to an
     * IP address via DNS).  This is intended to be used with the "ValidateFunction"
     * capability, like this:
     * @code
     *     "ValidateFunction" => ["FormUI", "ValidateHostName"],
     * @endcode
     * (The "FormUI" part will be replaced by the appropropriate FormUI object
     * before the method is called.)
     * @param string $FieldName Name of form field.
     * @param string|array $FieldValues Form values being validated.
     * @return string|null Error message or NULL if value appears valid.
     */
    public function validateHostName(string $FieldName, $FieldValues)
    {
        if (!is_array($FieldValues)) {
            $FieldValues = [$FieldValues];
        }
        foreach ($FieldValues as $Value) {
            if (trim($Value) == "") {
                continue;
            }
            if (gethostbyname($Value) === $Value) {
                return "The value for <i>".$this->FieldParams[$FieldName]["Label"]
                        ."</i> does not appear to be a valid host name.";
            }
        }
        return null;
    }

    /**
     * Clean up data from incomplete or canceled downloads in the FilePond
     * upload directory.
     */
    public static function cleanFilePondUploadDir() : void
    {
        # nothing to do when FilePond upload dir does not (yet) exist
        if (!is_dir(self::FILEPOND_UPLOAD_DIR)) {
            return;
        }

        # directories where we've not added a new chunk of data in the last
        # MaxAge seconds are assumed to belong to canceled or interrupted
        # downloads
        $MaxAge = 3600;
        $Now = time();

        $DirsToDelete = [];

        $DirEntries = scandir(self::FILEPOND_UPLOAD_DIR);
        if ($DirEntries === false) {
            throw new Exception(
                "scandir() on ".self::FILEPOND_UPLOAD_DIR." failed"
                    ." (should be impossible)."
            );
        }
        foreach ($DirEntries as $Entry) {
            if ($Entry == "." || $Entry == "..") {
                continue;
            }

            $TargetPath = self::FILEPOND_UPLOAD_DIR."/".$Entry;

            # skip non-directories
            if (!is_dir($TargetPath)) {
                continue;
            }

            # if a file was added to the dir within the last MaxAge seconds
            # (which is what mtime means for dirs), then skip it
            if ($Now - filemtime($TargetPath) < $MaxAge) {
                continue;
            }

            # otherwise, it should be deleted
            $DirsToDelete [] = $TargetPath;
        }

        foreach ($DirsToDelete as $Dir) {
            $DirEntries = scandir($Dir);
            if ($DirEntries === false) {
                throw new Exception(
                    "scandir() on ".$Dir." failed"
                        ." (should be impossible)."
                );
            }
            foreach ($DirEntries as $File) {
                $TargetPath = $Dir."/".$File;
                if (is_file($TargetPath)) {
                    unlink($TargetPath);
                }
            }

            rmdir($Dir);
        }
    }


    # ---- PRIVATE INTERFACE -------------------------------------------------

    protected $AdditionalHiddenFields = [];
    protected $DeletedFiles = [];
    protected $DeletedImages = [];
    protected $AddedFiles = [];
    protected $AddedImages = [];
    protected $ExtraValidationParams = [];
    protected $ExtraValues = [];
    protected $FieldParams;
    protected $FieldValues;
    protected $HiddenFields = [];
    protected $SettingChangeEventName = null;
    protected $SettingChangeEventParams = [];
    protected $UniqueKey;

    protected static $ErrorMessages = [];
    protected static $TypeSpecificFieldParameters = [
        "AllowFloats" => [ self::FTYPE_NUMBER, ],
        "AllowMultiple" => [
            self::FTYPE_FILE,
            self::FTYPE_IMAGE,
            self::FTYPE_METADATAFIELD,
            self::FTYPE_OPTION,
            self::FTYPE_PRIVILEGES,
            self::FTYPE_QUICKSEARCH,
            self::FTYPE_USER,
        ],
        "Columns" => [ self::FTYPE_PARAGRAPH, ],
        "Field" => [ self::FTYPE_QUICKSEARCH, self::FTYPE_USER ],
        "FieldTypes" => [ self::FTYPE_METADATAFIELD, ],
        "Format" => [ self::FTYPE_DATETIME, ],
        "Height" => [ self::FTYPE_PARAGRAPH, ],
        "MaxFieldLabelLength" => [ self::FTYPE_SEARCHPARAMS, ],
        "MaxValueLabelLength" => [ self::FTYPE_SEARCHPARAMS, ],
        "MaxLength" => [
            self::FTYPE_NUMBER,
            self::FTYPE_PASSWORD,
            self::FTYPE_TEXT,
            self::FTYPE_PARAGRAPH,
            self::FTYPE_URL,
        ],
        "MaxVal" => [
            self::FTYPE_NUMBER,
            self::FTYPE_PASSWORD,
            self::FTYPE_TEXT,
            self::FTYPE_URL,
        ],
        "MetadataFields" => [ self::FTYPE_PRIVILEGES, ],
        "MinVal" => [ self::FTYPE_NUMBER, ],
        "OffLabel" => [ self::FTYPE_FLAG, ],
        "OnLabel" => [ self::FTYPE_FLAG, ],
        "Options" => [ self::FTYPE_OPTION, ],
        "OptionsFunction" => [ self::FTYPE_OPTION, ],
        "OptionType" => [self::FTYPE_OPTION, ],
        "OptionThreshold" => [ self::FTYPE_OPTION, ],
        "Placeholder" => [
            self::FTYPE_NUMBER,
            self::FTYPE_PASSWORD,
            self::FTYPE_TEXT,
            self::FTYPE_URL,
        ],
        "Rows" => [
            self::FTYPE_OPTION,
            self::FTYPE_PARAGRAPH,
        ],
        "SchemaId" => [ self::FTYPE_METADATAFIELD, ],
        "Schemas" => [ self::FTYPE_PRIVILEGES, ],
        "Size" => [
            self::FTYPE_NUMBER,
            self::FTYPE_PASSWORD,
            self::FTYPE_TEXT,
            self::FTYPE_URL,
            self::FTYPE_POINT,
        ],
        "UseWYSIWYG" => [ self::FTYPE_PARAGRAPH, ],
        "Width" => [ self::FTYPE_PARAGRAPH, ],
        "InsertIntoField" => [self::FTYPE_IMAGE, self::FTYPE_FILE, ],
        "Callback" => [self::FTYPE_CUSTOMCONTENT, ],
        "Content" => [self::FTYPE_CUSTOMCONTENT, ],
        "Parameters" => [self::FTYPE_CUSTOMCONTENT, ],
        "Collapsible" => [ self::FTYPE_HEADING, ],
        "OpenByDefault" => [ self::FTYPE_HEADING, ],
        "UpdateButton" => [self::FTYPE_DATETIME, ],
    ];
    protected static $UniversalFieldParameters = [
        "AdditionalHtml",
        "Default",
        "DefaultFunction",
        "DisplayIf",
        "Help",
        "HelpType",
        "Label",
        "ReadOnly",
        "ReadOnlyFunction",
        "RecVal",
        "Required",
        "Size",
        "Type",
        "Units",
        "ValidateFunction",
        "Value",
    ];

    /** Marker used to indicate currently no value for field. */
    const NO_VALUE_FOR_FIELD = "NO VALUE";

    # interpret a timestamp specified as a 4-digit integer as a year if the
    # number is within range
    protected const MIN_YEAR = 1902;
    protected const MAX_YEAR = 2200;
    protected const FOUR_DIGIT_NUMBER_REGEX = '/^\s*\d{4}\s*$/';

    const FILEPOND_UPLOAD_DIR = "tmp/FilePondUploads";

    /**
     * Normalize and format the value for a datetime form field.
     * If a 4-digit integer input for a timestamp is within a valid range,
     * interpret that integer as a year and produce a timestamp for the first
     * second of that year.
     * @param string $DateTimeValue Raw input value for a datetime field.
     * @param string $DateFormat Format string for storing/displaying the
     *         datetime value provided.
     * @return string|false Normalized datetime if the datetime value provided
     *         is not-empty and can be normalized with strtotime. Returns false
     *         if the provided datetime value is blank or cannot be normalized.
     */
    protected static function normalizeDateTimeValue(
        string $DateTimeValue,
        string $DateFormat
    ) {

        if (!strlen($DateTimeValue)) {
            return false;
        }

        # interpret a 4-digit number in this range as
        # being at the first second of the specified year
        if (preg_match(self::FOUR_DIGIT_NUMBER_REGEX, $DateTimeValue) === 1) {
            $YearNumber = (int) $DateTimeValue;
            if (($YearNumber >= self::MIN_YEAR)
                    && ($YearNumber <= self::MAX_YEAR)) {
                $DateTimeValue = $YearNumber."-01-01 00:00:00";
            }
        }

        $NormalizedDate = strtotime($DateTimeValue);
        # strtotime returns false when the value cannot be normalized to a
        # timestamp

        if ($NormalizedDate === false) {
            return false;
        }

        return date($DateFormat, $NormalizedDate);
    }

    /**
     * Print all the supporting javascript for our form.
     * @param string $FormTableId ID of the table containing our form.
     */
    protected function printSupportingJavascript(string $FormTableId) : void
    {
        $this->printDoubleClickSubmitLockoutJavascript($FormTableId);

        $this->printFilepondJavascript($FormTableId);
    }

    /**
     * Get Javascript snippet that prevents a FormUI form's "Submit" buttons
     * from submitting the form when the form has already been submitted.
     * @param string $FormTableId ID of table containing the form to disable
     *     submit buttons on once one of them is clicked.
     */
    private function printDoubleClickSubmitLockoutJavascript(
        string $FormTableId
    ): void {
        ?>
        <script type="text/javascript">
        $(document).ready(function() {
            var FormToLock = $("#<?= $FormTableId ?>").parents("form");
            $("button[type='submit']", FormToLock)
                    .on("click", function(Event) {
                 // determine if one of the form's "submit" buttons has already
                 // been clicked
                 if (FormToLock.data("clicked")) {
                     // block the form submission
                     Event.preventDefault();
                 } else {
                     // set a data attribute on the form to indicate that
                     // the form has been submitted
                     FormToLock.data("clicked", true);
                 }
            });
        });
        </script>
        <?PHP
    }

    /**
     * Output Javascript to use the filepond upload library.
     * @param string $FormTableId ID of table containing the form to disable
     *     submit buttons on once one of them is clicked.
     */
    private function printFilepondJavascript(
        string $FormTableId
    ): void {
        $SysConfig = SystemConfiguration::getInstance();

        # if filepond is disabled, nothing to do
        if (!$SysConfig->getBool("UseFilepond")) {
            return;
        }

        static $Initialized = false;
        if (!$Initialized) {
            $AF = ApplicationFramework::getInstance();
            $AF->requireUIFile("filepond.min.css");
            $AF->requireUIFile("filepond.js");
            $AF->requireUIFile("filepond.jquery.js");
            ?>
            <script type="text/javascript">
            $(document).ready(function() {
                FilePond.setOptions({
                    server: {
                        url: '<?= $AF->baseUrl() ?>lib/FilePond/server/index.php'
                    },
                    chunkUploads: true,
                    chunkSize: <?= $SysConfig->getInt("UploadChunkSize") * 1024 * 1024 ?>,
                    credits: false
                });
            });
            </script>
            <?PHP
            $Initialized = true;
        }
        ?>
        <script type="text/javascript">
        $(document).ready(function() {
            var Form = $("#<?= $FormTableId ?>").parents("form");
            $("input[type='file']", Form).filepond();
            $("button[type='submit'][value='Upload']", Form).hide();

            // on upload start, add 'data-clicked' to trigger the "lockout"
            // from printDoubleClickSubmitLockoutJavascript() so that users
            // can't submit the form before the upload completes
            Form.on('FilePond:processfilestart', function(Event) {
                Form.data("clicked", true);
                Form.data("upload-in-progress", true);
            });

            // on upload completion
            Form.on('FilePond:processfile', function(Event) {
                Form.data("clicked", false);
                Form.data("upload-in-progress", false);
                var Row = $(Event.target).parents("tr.mv-content-tallrow");
                if (Event.detail.error === null) {
                    $("button[type='submit'][value='Upload']", Row).click();
                }
            });

            // Add a 'beforeunload' handler to attempt to prevent the user
            // from navigating away from the page while an upload is in
            // progress
            // (cf. https://developer.mozilla.org/en-US/docs/Web/API/Window/beforeunload_event )
            $(window).on('beforeunload', function(Event) {
                if (Form.data("upload-in-progress")) {
                    Event.preventDefault();
                    Event.returnValue = true;
                }
            });
        });
        </script>
        <?PHP
    }


    /**
     * Get fallback value for field to use when no value is provided in
     *     submitted form data.
     * @param string $FieldName Field name.
     * @return int|string|File|Image|PrivilegeSet|SearchParameterSet Loaded value.
     */
    private function getFallbackValueForField(string $FieldName)
    {
        if (!isset($this->FieldParams[$FieldName])) {
            throw new Exception(
                "Attempt to get fallback value for a non-existent field "
                    .$FieldName
            );
        }

        # get fallback value for field (in case no value from form)
        if (isset($this->FieldValues[$FieldName])) {
            $Value = $this->FieldValues[$FieldName];
        } else {
            if (isset($this->FieldParams[$FieldName]["Value"])) {
                $ValueData = $this->FieldParams[$FieldName]["Value"];
            } elseif (isset($this->FieldParams[$FieldName]["Default"])) {
                $ValueData = $this->FieldParams[$FieldName]["Default"];
            } elseif (isset($this->FieldParams[$FieldName]["DefaultFunction"])) {
                $ValueData = $this->FieldParams[$FieldName]["DefaultFunction"](
                    $FieldName
                );
            } else {
                $ValueData = null;
            }

            $FieldType = $this->FieldParams[$FieldName]["Type"];
            $Value = self::loadValue($FieldType, $ValueData);
        }

        return $Value;
    }

    /**
     * Check for invalid field parameters.
     * @param array $FieldParams Associative array of associative arrays, with
     *       field names for the top-level index, and field parameter names for
     *       the second-level index.
     * @param array $ErrMsgs Current error message list.  (REFERENCE)
     * @return void
     */
    private function checkForInvalidFieldParameters(array $FieldParams, &$ErrMsgs): void
    {
        foreach ($FieldParams as $FieldName => $Params) {
            if (isset($Params["Type"]) && ($Params["Type"] == self::FTYPE_QUICKSEARCH)) {
                if (isset($Params["Field"])
                        && !MetadataSchema::fieldExistsInAnySchema($Params["Field"])) {
                    $ErrMsgs[] = "Specified search field for quicksearch form field "
                            .$FieldName." does not exist.";
                } else {
                    $MField = MetadataField::getField(
                        $FieldParams[$FieldName]["Field"]
                    );
                    $AllowedMFieldTypes = [
                        MetadataSchema::MDFTYPE_TREE,
                        MetadataSchema::MDFTYPE_CONTROLLEDNAME,
                        MetadataSchema::MDFTYPE_USER,
                        MetadataSchema::MDFTYPE_REFERENCE
                    ];
                    if (!in_array($MField->type(), $AllowedMFieldTypes)) {
                        $ErrMsgs[] = "Specified search field for quicksearch form field "
                                .$FieldName." is not a type that supports quicksearches.";
                    }
                }
            }

            $CallableParams = [
                "ValidateFunction" => "validation",
                "DefaultFunction" => "default value",
                "ReadOnlyFunction" => "read only",
            ];
            foreach ($CallableParams as $ParamName => $ParamDescrip) {
                if (isset($Params[$ParamName])) {
                    $PFunc = $Params[$ParamName];
                    if (is_array($PFunc) && is_subclass_of($PFunc[0], self::class)) {
                        $PFunc[0] = $this;
                    }
                    if (!is_callable($PFunc)) {
                        $ErrMsgs[] = "Uncallable ".$ParamDescrip." function"
                                ." for form field ".$FieldName.".";
                    }
                }
            }

            if (isset($Params["InsertIntoField"]) &&
                !isset($FieldParams[$Params["InsertIntoField"]])) {
                $ErrMsgs[] = "Unknown insertion field (".$Params["InsertIntoField"]
                    .") found for form field ".$FieldName.".";
            }

            if (array_key_exists("Default", $Params)) {
                if (isset($Params["Type"])) {
                    $FieldTypesThatRequireArrays = [
                        self::FTYPE_QUICKSEARCH,
                        self::FTYPE_USER,
                    ];
                    if (in_array($Params["Type"], $FieldTypesThatRequireArrays)
                            && !is_array($Params["Default"])) {
                        $ErrMsgs[] = "Default for form field ".$FieldName
                                ." must be an array.";
                    }
                }
            }

            if (isset($Params["OptionType"])
                    && ($Params["OptionType"] == self::OTYPE_LISTSET)
                    && (!isset($Params["AllowMultiple"])
                            || ($Params["AllowMultiple"] == false))) {
                $ErrMsgs[] = "Option type for form field ".$FieldName
                        ." set to LISTSET without also allowing multiple values";
            }
        }
    }

    /**
     * Check for missing field parameters.
     * @param array $FieldParams Associative array of associative arrays, with
     *       field names for the top-level index, and field parameter names for
     *       the second-level index.
     * @param array $ErrMsgs Current error message list.  (REFERENCE)
     * @return void
     */
    private function checkForMissingFieldParameters(array $FieldParams, &$ErrMsgs): void
    {
        foreach ($FieldParams as $FieldName => $Params) {
            if (!isset($Params["Type"])) {
                $ErrMsgs[] = "Type missing for form field ".$FieldName.".";
            } elseif (($Params["Type"] == self::FTYPE_OPTION)
                    && !isset($Params["Options"])
                    && !isset($Params["OptionsFunction"])) {
                $ErrMsgs[] = "Option values missing for form field ".$FieldName.".";
            } elseif (($Params["Type"] == self::FTYPE_OPTION)
                    && isset($Params["OptionsFunction"])
                    && !is_callable($Params["OptionsFunction"])) {
                $ErrMsgs[] = "Options function for form field "
                        .$FieldName." not callable.";
            } elseif (($Params["Type"] == self::FTYPE_QUICKSEARCH)
                    && (!isset($Params["Field"]))) {
                $ErrMsgs[] = "Field to search missing for quicksearch form field "
                    .$FieldName.".";
            } elseif ($Params["Type"] == self::FTYPE_CUSTOMCONTENT) {
                if (isset($Params["Callback"]) && isset($Params["Content"])) {
                    $ErrMsgs[] = "Callback and Content cannot both be provided "
                        ."for ".$FieldName;
                } elseif (!isset($Params["Callback"]) && !isset($Params["Content"])) {
                    $ErrMsgs[] = "Custom content field ".$FieldName." must specify "
                        ."either Callback or Content.";
                } elseif (isset($Params["Callback"]) && !is_callable($Params["Callback"])) {
                    $ErrMsgs[] = "Provided callback for ".$FieldName." is not callable.";
                } elseif (isset($Params["Parameters"]) && !is_array($Params["Parameters"])) {
                    $ErrMsgs[] = "Callback parameters must be an array for ".$FieldName;
                }
            } elseif (($Params["Type"] != self::FTYPE_GROUPEND)
                    && !isset($Params["Label"])) {
                $ErrMsgs[] = "Label missing for form field ".$FieldName.".";
            }
        }
    }

    /**
     * Check for unrecognized field parameters and field parameters that are
     * unsupported/unrecognized for the field type.
     * @param array $FieldParams Associative array of associative arrays, with
     *       field names for the top-level index, and field parameter names for
     *       the second-level index.
     * @param array $ErrMsgs Current error message list.  (REFERENCE)
     * @return void
     */
    private function checkForUnrecognizedFieldParameters(array $FieldParams, &$ErrMsgs): void
    {
        foreach ($FieldParams as $FieldName => $Params) {
            foreach ($Params as $Key => $Value) {
                # if parameter is not in either the list of parameters valid
                #   for all field types or the list of parameters valid for
                #   specific field types
                if (!in_array($Key, self::$UniversalFieldParameters)
                        && !isset(self::$TypeSpecificFieldParameters[$Key])) {
                    # add error message about illegal parameter
                    $ErrMsgs[] = "Unrecognized parameter '".$Key."' found"
                            ." for form field '".$FieldName."'.";
                } elseif (isset(self::$TypeSpecificFieldParameters[$Key])
                        && isset($Params["Type"])
                        && !in_array(
                            $Params["Type"],
                            self::$TypeSpecificFieldParameters[$Key]
                        )) {
                    # else if parameter is in the list of parameters valid for
                    #   specific field types and the field type is known and the
                    #   field type is not in the list of types which are valid
                    #   for that parameter

                    # add error message about parameter invalid for field type
                    $ErrMsgs[] = "Parameter '".$Key."' is not supported"
                            ." for field type ".$Params["Type"]
                            ." for form field '".$FieldName."'.";
                }
            }
        }
    }

    /**
     * Determine if a field is a pseudo-type.
     * @param string $FieldType Type to check (as a self::FTYPE_ constant).
     * @return bool TRUE for pseudo-types.
     */
    private function isFieldPseudoType(string $FieldType): bool
    {
        $PseudoTypes = [
            self::FTYPE_HEADING,
            self::FTYPE_CAPTCHA,
            self::FTYPE_CUSTOMCONTENT,
            self::FTYPE_GROUPEND,
        ];

        return in_array($FieldType, $PseudoTypes) ? true : false;
    }

    /**
     * Determine if a field is read-only.
     * @param string $FieldName Field to check.
     * @return bool TRUE for read-only fields.
     */
    private function isReadOnly(string $FieldName): bool
    {
        $Params = $this->FieldParams[$FieldName];

        return isset($Params["ReadOnlyFunction"])
            ? $Params["ReadOnlyFunction"]($FieldName)
            : $Params["ReadOnly"];
    }

    /**
     * Normalize field parameters.
     * @param array $FieldParams Associative array of associative arrays, with
     *       field names for the top-level index, and field parameter names for
     *       the second-level index.  (REFERENCE)
     * @return void
     */
    protected function normalizeFieldParameters(&$FieldParams): void
    {
        $BooleanParams = [
            "AllowMultiple",
            "ReadOnly",
            "Required",
            "UseWYSIWYG",
            "UpdateButton",
        ];
        foreach ($FieldParams as $FieldName => $Params) {
            if (isset($Params["Field"])) {
                $FieldParams[$FieldName]["Field"] =
                        MetadataSchema::getCanonicalFieldIdentifier($Params["Field"]);
            }

            foreach ($BooleanParams as $ParamName) {
                if (!isset($Params[$ParamName])) {
                    $FieldParams[$FieldName][$ParamName] = false;
                }
            }

            if (isset($Params["Help"]) && !isset($Params["HelpType"])) {
                $FieldParams[$FieldName]["HelpType"] = self::HELPTYPE_COLUMN;
            }

            switch ($Params["Type"]) {
                case self::FTYPE_CUSTOMCONTENT:
                    if (isset($Params["Callback"]) && !isset($Params["Parameters"])) {
                        $FieldParams[$FieldName]["Parameters"] = [];
                    }
                    break;

                case self::FTYPE_DATETIME:
                    $FieldParams[$FieldName]["Format"] =
                            $Params["Format"] ?? "Y-m-d H:i:s";
                    break;

                case self::FTYPE_HEADING:
                    if (!isset($Params["Collapsible"])) {
                        $FieldParams[$FieldName]["Collapsible"] = false;
                    }
                    if (!isset($Params["OpenByDefault"])) {
                        $FieldParams[$FieldName]["OpenByDefault"] = true;
                    }

                    if ($FieldParams[$FieldName]["Collapsible"] === false &&
                        $FieldParams[$FieldName]["OpenByDefault"] === false) {
                        throw new Exception(
                            "Non-collapsible section ".$FieldName." cannot also be "
                            ."closed by default."
                        );
                    }
                    break;

                case self::FTYPE_OPTION:
                    if (isset($Params["OptionsFunction"])) {
                        $FieldParams[$FieldName]["Options"] =
                                $Params["OptionsFunction"]($FieldName);
                    }
                    break;
            }
        }
    }

    /**
     * Display HTML form field for specified field.
     * @param string $Name Field name.
     * @param mixed $Value Current value for field.
     * @param array $Params Field parameters.
     * @return void
     */
    abstract protected function displayFormField(string $Name, $Value, array $Params): void;

    /**
     * Get HTML form field name for specified field.
     * @param string $FieldName Field name.
     * @param bool $IncludePrefix If TRUE, "F_" prefix is included.  (OPTIONAL,
     *       defaults to TRUE.)
     * @return string Form field name.
     */
    protected function getFormFieldName(string $FieldName, bool $IncludePrefix = true): string
    {
        return ($IncludePrefix ? "F_" : "")
                .($this->UniqueKey ? $this->UniqueKey."_" : "")
                .preg_replace("/[^a-zA-Z0-9]/", "", $FieldName);
    }

    /**
     * Get HTML for hidden form fields associated with form processing.
     * @return string Hidden field HTML.
     */
    protected function getHiddenFieldsHtml(): string
    {
        $Html = "";
        if (count($this->HiddenFields)) {
            foreach ($this->HiddenFields as $FieldName => $Value) {
                if (is_array($Value)) {
                    foreach ($Value as $EachValue) {
                        $Html .= '<input type="hidden" name="'.$FieldName
                                .'[]" value="'.htmlspecialchars($EachValue).'">';
                    }
                } else {
                    $Html .= '<input type="hidden" name="'.$FieldName
                        .'[]" id="'.$FieldName.'" value="'
                        .htmlspecialchars($Value).'">';
                }
            }
        }
        if (count($this->AdditionalHiddenFields)) {
            foreach ($this->AdditionalHiddenFields as $FieldName => $Value) {
                if (isset($this->HiddenFields[$FieldName])) {
                    throw new Exception("Additional hidden field \"".$FieldName
                            ."\" conflicts with class usage of hidden field.");
                }
                $Html .= '<input type="hidden" name="'.$FieldName
                        .'" id="'.$FieldName.'" value="'
                        .htmlspecialchars($Value).'">';
            }
        }

        $FileAndImageData = [
            "AddedImageIds" => $this->AddedImages,
            "AddedFileIds" => $this->AddedFiles,
        ];
        foreach ($FileAndImageData as $Name => $ItemIds) {
            if (count($ItemIds)) {
                $FormName = $this->getFormFieldName($Name);
                foreach ($ItemIds as $ItemId) {
                    $Html .= '<input type="hidden" name="'.$FormName
                        .'[]" value="'.htmlspecialchars($ItemId).'">';
                }
            }
        }

        return $Html;
    }

    /**
     * Check for an upload via the filepond upload library for a given form
     * field, returning the path to the uploaded file if there was one.
     * @param string $FormFieldName Form field name to check.
     * @return string|null Name of uploaded file or NULL when there was not one.
     * @throws Exception on scandir() failure.
     * @throws Exception on multiple files in one upload directory (not
     *     possible with our filepond configuration).
     * @see https://pqina.nl/filepond/
     */
    private function preprocessFilepondUpload(string $FormFieldName): ?string
    {
        if (!isset($_POST[$FormFieldName])) {
            return null;
        }

        if (strlen($_POST[$FormFieldName]) == 0) {
            throw new Exception(
                "No filename propvided for filepond upload "
                    ." (should be impossible)."
            );
        }

        # look in the `transfer` dir configured by lib/filepond/config.php,
        # which contains completed uploads
        $FilepondTransferDir = self::FILEPOND_UPLOAD_DIR."/".$_POST[$FormFieldName];

        $FilesToSkip = [".htaccess", ".metadata",];

        $Files = [];
        $DirEntries = scandir($FilepondTransferDir);
        if ($DirEntries === false) {
            throw new Exception(
                "scandir() on ".$FilepondTransferDir." failed"
                    ." (should be impossible)."
            );
        }
        foreach ($DirEntries as $File) {
            # skip non-file entries
            if (!is_file($FilepondTransferDir."/".$File)) {
                continue;
            }

            if (in_array($File, $FilesToSkip)) {
                continue;
            }

            $Files[] = $FilepondTransferDir."/".$File;
        }

        if (count($Files) == 0) {
            throw new Exception(
                "No files present in a filepond upload directory"
                    ." (should be impossible)."
            );
        }

        if (count($Files) > 1) {
            throw new Exception(
                "Multiple files in a filepond upload directory"
                    ." (should be impossible)."
            );
        }

        $TmpFile = array_shift($Files);

        return $TmpFile;
    }

    /**
     * Clean up after filepond uploads.
     * @param string $FormFieldName Form field name to check.
     */
    private function postprocessFilepondUpload(string $FormFieldName): void
    {
        if (!isset($_POST[$FormFieldName])
                || strlen($_POST[$FormFieldName]) == 0) {
            return;
        }

        # delete directory from this upload
        $TargetDir = self::FILEPOND_UPLOAD_DIR."/".$_POST[$FormFieldName];
        $DirEntries = scandir($TargetDir);
        if ($DirEntries === false) {
            throw new Exception(
                "scandir() on ".$TargetDir." failed"
                    ." (should be impossible)."
            );
        }
        foreach ($DirEntries as $Entry) {
            $TargetPath = $TargetDir."/".$Entry;
            if (is_file($TargetPath)) {
                unlink($TargetPath);
            }
        }

        rmdir($TargetDir);

        self::cleanFilePondUploadDir();
    }

    /**
     * Take an array of ItemIds and convert it to [ ItemId => ItemName ].
     * @param MetadataField $MField Field giving the namespace of the ItemIds.
     * @param array $ItemIds Int item ids.
     * @return array keyed by ItemId with values giving item names.
     */
    protected static function convertItemIdsToNames(MetadataField $MField, array $ItemIds): array
    {
        # define $ExistsFn to determine if an ItemId exists and
        # $NameFn to get its name
        switch ($MField->type()) {
            case MetadataSchema::MDFTYPE_TREE:
            case MetadataSchema::MDFTYPE_CONTROLLEDNAME:
            case MetadataSchema::MDFTYPE_OPTION:
                $Factory = $MField->getFactory();

                if ($Factory === null) {
                    throw new Exception(
                        "Unable to get factory for field"
                    );
                }

                $ExistsFn = function ($Id) use ($Factory) {
                    return is_numeric($Id) && $Factory->itemExists((int)$Id);
                };

                $NameFn = function ($Id) use ($Factory) {
                    return ($Factory->getItem($Id)->name());
                };
                break;

            case MetadataSchema::MDFTYPE_USER:
                $Factory = new UserFactory();

                $ExistsFn = function ($Id) use ($Factory) {
                    return $Factory->userExists($Id);
                };

                $NameFn = function ($Id) {
                    return (new User($Id))->name();
                };
                break;

            case MetadataSchema::MDFTYPE_REFERENCE:
                $ExistsFn = function ($Id) {
                    return Record::itemExists($Id);
                };

                $NameFn = function ($Id) {
                    return (new Record($Id))->getMapped("Title");
                };
                break;

            default:
                throw new Exception(
                    "Invalid field type for ItemId to ItemName conversion."
                );
        }

        # iterate over incoming values, extracting names for all the ones that exist
        $Value = [];
        foreach ($ItemIds as $Id) {
            if ($ExistsFn($Id)) {
                $Value[$Id] = $NameFn($Id);
            }
        }

        return $Value;
    }

    /**
     * Determine if a field was shown to the user.
     * @param string $FieldName Field name to check.
     * @return bool TRUE for fields that were displayed, FALSE otherwise.
     */
    protected function fieldWasDisplayedWhenFormWasSubmitted(string $FieldName): bool
    {
        # if this field might have been hidden
        $Params = $this->FieldParams[$FieldName];
        if (isset($Params["DisplayIf"])) {
            # iterate over all the togglers that might enable this field
            foreach ($Params["DisplayIf"] as $Toggler => $ToggleValues) {
                if (!is_array($ToggleValues)) {
                    $ToggleValues = [$ToggleValues];
                }

                $CurrentValue = $this->getFieldValue($Toggler);
                if (in_array($CurrentValue, $ToggleValues)) {
                    return true;
                }
            }

            # if no toggler was found to enable this field, it was hidden
            return false;
        } else {
            # if field cannot be hidden, then it must have been shown
            return true;
        }
    }

    /**
     * Set values for read-only fields in supplied field values array.
     *     (Any existing values will be overwritten.)
     * @param array $Values Form values, indexed by field name.
     * @return array Potentially extended list of form values, indexed by field name.
     */
    private function setValuesForReadOnlyFields(array $Values): array
    {
        foreach (array_keys($this->FieldParams) as $FieldName) {
            $FieldName = (string)$FieldName;
            if ($this->isReadOnly($FieldName)) {
                $Values[$FieldName] = $this->getFallbackValueForField($FieldName);
            }
        }

        return $Values;
    }

    /**
     * Validate value for form field, including checks that required values
     *     are supplied and read-only values were not modified, logging errors
     *     if any were found.
     * @param string $FieldName Name of form field to validate.
     * @param array $FieldValues Form field values, indexed by field name. All
     *     values are provided so that validation functions may implement
     *     checks that look at multiple fields.
     * @return int Number of errors found.
     */
    private function validateFieldValue(
        string $FieldName,
        array $FieldValues
    ): int {
        if (!isset($this->FieldParams[$FieldName])) {
            throw new Exception(
                "Attempt to validate a non-existent field "
                    .$FieldName
            );
        }

        $ErrorsFound = 0;
        $FieldParams = $this->FieldParams[$FieldName];
        $FieldValue = $FieldValues[$FieldName] ?? null;

        # if a validation function was defined
        if (isset($FieldParams["ValidateFunction"])) {
            # swap in our object if this is one of our methods
            $VFunc = $FieldParams["ValidateFunction"];
            if (is_array($VFunc) && is_subclass_of($VFunc[0], self::class)) {
                $VFunc[0] = $this;
            }

            # call validation function for value
            $Args = array_merge(
                [$FieldName, $FieldValue, $FieldValues],
                $this->ExtraValidationParams
            );
            $ErrMsg = call_user_func_array($VFunc, $Args);
            if ($ErrMsg === false) {
                throw new Exception("Calling validation function for"
                        ." parameter \"".$FieldName."\" failed.");
            }

            # log any resulting error
            if ($ErrMsg !== null) {
                self::logError($ErrMsg, $FieldName, $this->UniqueKey);
                $ErrorsFound++;
            }
        }

        # check if a read-only value was changed
        if ($this->isReadOnly($FieldName)) {
            $OldValue = $this->getFallbackValueForField($FieldName);
            if ($FieldValue != $OldValue) {
                # log error to indicate that a readonly value was changed
                self::logError(
                    "<i>".$FieldParams["Label"]."</i> is read-only, "
                        ."but appears to have been modified.",
                    $FieldName,
                    $this->UniqueKey
                );
                $ErrorsFound++;
            }

            return $ErrorsFound;
        }

        # check if a required field was missing
        if ($FieldParams["Required"]) {
            switch ($FieldParams["Type"]) {
                case self::FTYPE_SEARCHPARAMS:
                    $IsEmpty = $FieldValue->parameterCount() == 0;
                    break;

                case self::FTYPE_PRIVILEGES:
                    $IsEmpty = $FieldValue->comparisonCount() == 0;
                    break;

                default:
                    if (is_array($FieldValue)) {
                        $IsEmpty = count($FieldValue) == 0;
                    } else {
                        $IsEmpty = strlen(trim($FieldValue ?? "")) == 0;
                    }
                    break;
            }

            if ($IsEmpty) {
                # log error to indicate required value is missing
                self::logError(
                    "<i>".$FieldParams["Label"]."</i> is required.",
                    $FieldName,
                    $this->UniqueKey
                );
                $ErrorsFound++;

                return $ErrorsFound;
            }
        }

        # otherwise validate based on field type
        switch ($FieldParams["Type"]) {
            case self::FTYPE_NUMBER:
                $ErrorsFound += $this->validateNumberFieldValue($FieldName, $FieldValue);
                break;

            case self::FTYPE_URL:
                $ErrorsFound += $this->validateUrlFieldValue($FieldName, $FieldValue);
                break;

            case self::FTYPE_USER:
                $ErrorsFound += $this->validateUserFieldValue($FieldName, $FieldValue);
                break;

            case self::FTYPE_TEXT:
            case self::FTYPE_PARAGRAPH:
            case self::FTYPE_PASSWORD:
                $ErrorsFound += $this->validateTextFieldValue($FieldName, $FieldValue);
                break;
        }

        return $ErrorsFound;
    }

    /**
     * Validate data for a number field.
     * @param string $FieldName Name of form field to validate.
     * @param ?string $FieldValue Provided value.
     * @return int Number of errors found.
     */
    private function validateNumberFieldValue(
        string $FieldName,
        ?string $FieldValue
    ) : int {
        if (!isset($this->FieldParams[$FieldName])) {
            throw new Exception(
                "Attempt to validate a non-existent field "
                    .$FieldName
            );
        }

        $ErrorsFound = 0;
        $FieldParams = $this->FieldParams[$FieldName];

        # check if provided value is numeric
        if (!is_null($FieldValue) && strlen($FieldValue) > 0 && !is_numeric($FieldValue)) {
            self::logError(
                "<i>".$FieldParams["Label"]."</i> must be a number.",
                $FieldName,
                $this->UniqueKey
            );
            $ErrorsFound++;
            return $ErrorsFound;
        }

        # check if value falls within configured range
        if ((isset($FieldParams["MinVal"]) && ($FieldValue < $FieldParams["MinVal"]))
            || (isset($FieldParams["MaxVal"]) && ($FieldValue > $FieldParams["MaxVal"]))) {
            if (!isset($FieldParams["MaxVal"])) {
                self::logError(
                    "<i>".$FieldParams["Label"]."</i> must be "
                        .$FieldParams["MinVal"]." or greater.",
                    $FieldName,
                    $this->UniqueKey
                );
            } elseif (!isset($FieldParams["MinVal"])) {
                self::logError(
                    "<i>".$FieldParams["Label"]."</i> must be "
                        .$FieldParams["MaxVal"] ." or less.",
                    $FieldName,
                    $this->UniqueKey
                );
            } else {
                self::logError(
                    "<i>".$FieldParams["Label"]."</i> must be"
                        ." in the range ".$FieldParams["MinVal"]
                        ." to ".$FieldParams["MaxVal"].".",
                    $FieldName,
                    $this->UniqueKey
                );
            }
            $ErrorsFound++;
            return $ErrorsFound;
        }

        # check if the value is allowed to be a float.
        # if the param "AllowFloats" was not set, we should
        #       then default to false.
        if (!isset($FieldParams["AllowFloats"]) || !$FieldParams["AllowFloats"]) {
            if (is_numeric($FieldValue) && (floor((float)$FieldValue) != $FieldValue)) {
                self::logError(
                    "<i>".$FieldParams["Label"]."</i> cannot be a decimal number.",
                    $FieldName,
                    $this->UniqueKey
                );
                $ErrorsFound++;
            }
        }

        return $ErrorsFound;
    }

    /**
     * Validate data for a Url field.
     * @param string $FieldName Name of form field to validate.
     * @param ?string $FieldValue Provided value.
     * @return int Number of errors found.
     */
    private function validateUrlFieldValue(
        string $FieldName,
        ?string $FieldValue
    ) : int {
        if (!isset($this->FieldParams[$FieldName])) {
            throw new Exception(
                "Attempt to validate a non-existent field "
                    .$FieldName
            );
        }

        $ErrorsFound = 0;
        $FieldParams = $this->FieldParams[$FieldName];

        # make sure URL entered looks valid
        $IsValidUrl = filter_var($FieldValue, FILTER_VALIDATE_URL) !== false;
        if (!is_null($FieldValue) && strlen($FieldValue) > 0 && !$IsValidUrl) {
            self::logError(
                "Value \"".$FieldValue."\" does not appear to be a valid URL for <i>"
                    .$FieldParams["Label"]."</i>.",
                $FieldName,
                $this->UniqueKey
            );
            $ErrorsFound++;
        }

        $ErrorsFound += $this->validateTextFieldValue(
            $FieldName,
            $FieldValue
        );

        return $ErrorsFound;
    }

    /**
     * Validate data for a User field.
     * @param string $FieldName Name of form field to validate.
     * @param array $FieldValue Provided value.
     * @return int Number of errors found.
     */
    private function validateUserFieldValue(
        string $FieldName,
        array $FieldValue
    ) : int {
        if (!isset($this->FieldParams[$FieldName])) {
            throw new Exception(
                "Attempt to validate a non-existent field "
                    .$FieldName
            );
        }

        $ErrorsFound = 0;
        $FieldParams = $this->FieldParams[$FieldName];

        # make sure user name entered is valid
        $UFactory = new UserFactory();
        foreach ($FieldValue as $UId) {
            if (strlen($UId) && !$UFactory->userExists($UId)) {
                self::logError(
                    "User ID \"".$UId."\" not found for <i>"
                        .$FieldParams["Label"]."</i>.",
                    $FieldName,
                    $this->UniqueKey
                );
                $ErrorsFound++;
            }
        }

        return $ErrorsFound;
    }

    /**
     * Validate data for a textual field.
     * @param string $FieldName Name of form field to validate.
     * @param ?string $FieldValue Provided value.
     * @return int Number of errors found.
     */
    private function validateTextFieldValue(
        string $FieldName,
        ?string $FieldValue
    ) : int {
        if (!isset($this->FieldParams[$FieldName])) {
            throw new Exception(
                "Attempt to validate a non-existent field "
                    .$FieldName
            );
        }

        $ErrorsFound = 0;
        $FieldParams = $this->FieldParams[$FieldName];

        # make sure that the value length doesn't exceed max length if set
        $DefaultCharset = InterfaceConfiguration::getInstance()
            ->getString("DefaultCharacterSet");
        if (isset($FieldParams["MaxLength"]) &&
            !is_null($FieldValue) &&
            mb_strlen($FieldValue, $DefaultCharset) > $FieldParams["MaxLength"]) {
            self::logError(
                "<i>".$FieldParams["Label"]."</i> must not exceed "
                    .$FieldParams["MaxLength"]." characters.",
                $FieldName,
                $this->UniqueKey
            );
            $ErrorsFound++;
        }

        return $ErrorsFound;
    }

    /**
     * Perform normalization of form values that converts data to a standard
     *     encoding without changing the data it represents (e.g.,
     *     standardizing line endings).
     * @param array $FormValues Incoming form values.
     * @return array Normalized data.
     */
    private function normalizeFormValueEncoding($FormValues): array
    {

        foreach ($FormValues as $Name => $Values) {
            if (!isset($this->FieldParams[$Name])) {
                throw new Exception(
                    "Attempt to normalize encoding for a non-existent field "
                        .$Name
                );
            }

            $Params = $this->FieldParams[$Name];

            switch ($Params["Type"]) {
                case self::FTYPE_PARAGRAPH:
                    # normalize newlines to '\n' instead of '\r\n' or '\r'
                    $FormValues[$Name] = str_replace(["\r\n", "\r"], "\n", $Values);
                    break;

                default:
                    # nothing to do
                    break;
            }
        }

        return $FormValues;
    }

    /**
     * Perform normalization of form values that converts data to a standard
     *     format in ways that may alter or reformat the input (e.g.,
     *     converting date/time values into a standard format)
     * @param array $FormValues Incoming form values.
     * @return array Normalized data.
     */
    private function normalizeFormValueData($FormValues)
    {
        foreach ($FormValues as $Name => $Values) {
            if (!isset($this->FieldParams[$Name])) {
                throw new Exception(
                    "Attempt to normalize data for a non-existent field "
                        .$Name
                );
            }

            $Params = $this->FieldParams[$Name];

            switch ($Params["Type"]) {
                case self::FTYPE_DATETIME:
                    # normalizeDateTimeValue returns false if the
                    # value for a timestamp is empty or cannot be
                    # normalized
                    $FormValues[$Name] =
                        self::normalizeDateTimeValue(
                            $Values,
                            $Params["Format"]
                        );
                    break;

                default:
                    # nothing to do
                    break;
            }
        }

        return $FormValues;
    }

    /**
     * Retrieve values for user-editable fields set by form without
     *     any normalization. (No values will be returned for
     *     read-only fields and fields not shown to the user.)
     * @return array Array of configuration settings, with setting names
     *       for the index, and new setting values for the values.
     */
    private function getRawNewValuesFromForm(): array
    {
        # for each form field
        $NewSettings = [];
        foreach ($this->FieldParams as $Name => $Params) {
            if ($this->isFieldPseudoType($Params["Type"])) {
                continue;
            }

            # skip fields not shown to the user
            if (!$this->fieldWasDisplayedWhenFormWasSubmitted($Name)) {
                continue;
            }

            # skip read only fields
            if ($this->isReadOnly($Name)) {
                continue;
            }

            # determine form field name (matches mechanism in HTML)
            $FieldName = $this->getFormFieldName(
                $Name,
                ($Params["Type"] != self::FTYPE_PRIVILEGES)
            );

            # assume the value will not change
            $DidValueChange = false;
            $OldValue = isset($this->FieldValues[$Name])
                    ? $this->FieldValues[$Name]
                    : (isset($Params["Value"]) ? $Params["Value"] : null);
            $NewSettings[$Name] = $OldValue;
            $NewValue = null;

            # retrieve value based on configuration parameter type
            switch ($Params["Type"]) {
                case self::FTYPE_FLAG:
                    # if radio buttons were used
                    if (array_key_exists("OnLabel", $Params)
                            && array_key_exists("OffLabel", $Params)) {
                        if (isset($_POST[$FieldName])) {
                            $NewValue = ($_POST[$FieldName] == "1") ? true : false;

                            # flag that the values changed if they did
                            $DidValueChange = self::didValueChange(
                                $OldValue,
                                $NewValue
                            );

                            $NewSettings[$Name] = $NewValue;
                        }
                    } else {
                        # else checkbox was used
                        $NewValue = isset($_POST[$FieldName]) ? true : false;

                        # flag that the values changed if they did
                        $DidValueChange = self::didValueChange($OldValue, $NewValue);

                        $NewSettings[$Name] = $NewValue;
                    }
                    break;

                case self::FTYPE_OPTION:
                    $NewValue = StdLib::getArrayValue($_POST, $FieldName, []);

                    # flag that the values changed if they did
                    $DidValueChange = self::didValueChange($OldValue, $NewValue);

                    $NewSettings[$Name] = $NewValue;
                    break;

                case self::FTYPE_METADATAFIELD:
                    $NewValue = StdLib::getArrayValue($_POST, $FieldName, []);
                    if ($NewValue == "-1") {
                        $NewValue = [];
                    }

                    # flag that the values changed if they did
                    $DidValueChange = self::didValueChange($OldValue, $NewValue);

                    $NewSettings[$Name] = $NewValue;
                    break;

                case self::FTYPE_PRIVILEGES:
                    $Schemas = StdLib::getArrayValue($Params, "Schemas");
                    $MFields = StdLib::getArrayValue($Params, "MetadataFields", []);
                    $PEditor = new PrivilegeEditingUI($Schemas, $MFields);
                    $NewValues = $PEditor->getPrivilegeSetsFromForm();
                    $NewValue = $NewValues[$FieldName];
                    $DidValueChange = self::didValueChange($OldValue, $NewValue);
                    $NewSettings[$Name] = $NewValue;
                    break;

                case self::FTYPE_SEARCHPARAMS:
                    $SPEditor = new SearchParameterSetEditingUI($FieldName);
                    $NewValue = $SPEditor->getValuesFromFormData();
                    $DidValueChange = self::didValueChange($OldValue, $NewValue);
                    $NewSettings[$Name] = $NewValue;
                    break;

                case self::FTYPE_FILE:
                    $NewSettings[$Name] = StdLib::getArrayValue(
                        $_POST,
                        $FieldName."_ID",
                        []
                    );
                    foreach ($NewSettings[$Name] as $Index => $FileId) {
                        if ($FileId == self::NO_VALUE_FOR_FIELD ||
                            in_array($FileId, $this->DeletedFiles)) {
                            unset($NewSettings[$Name][$Index]);
                        }
                    }

                    # if we have any files in ExtraValues after a HandleUploads,
                    # include those too
                    $ExtraSettings = StdLib::getArrayValue(
                        $this->ExtraValues,
                        $FieldName."_ID",
                        []
                    );
                    foreach ($ExtraSettings as $FileId) {
                        if (!in_array($FileId, $NewSettings[$Name]) &&
                            !in_array($FileId, $this->DeletedFiles)) {
                            $NewSettings[$Name][] = $FileId;
                        }
                    }

                    break;

                case self::FTYPE_IMAGE:
                    $NewSettings[$Name] = StdLib::getArrayValue(
                        $_POST,
                        $FieldName."_ID",
                        []
                    );

                    foreach ($NewSettings[$Name] as $Index => $ImageId) {
                        if ($ImageId == self::NO_VALUE_FOR_FIELD ||
                            in_array($ImageId, $this->DeletedImages)) {
                            unset($NewSettings[$Name][$Index]);
                        } elseif (isset($_POST[$FieldName."_AltText_".$ImageId]) &&
                            Image::itemExists($ImageId)) {
                            # check if image exists to avoid breaking when
                            # image was deleted elsewhere
                            # (constructing an image nonexistent ID will throw an exception)
                            $Image = new Image($ImageId);
                            $Image->altText($_POST[$FieldName."_AltText_".$ImageId]);
                        }
                    }

                    # if we have any images in ExtraValues after a HandleUploads,
                    # include those too
                    $ExtraSettings = StdLib::getArrayValue(
                        $this->ExtraValues,
                        $FieldName."_ID",
                        []
                    );
                    foreach ($ExtraSettings as $ImageId) {
                        if (!in_array($ImageId, $NewSettings[$Name]) &&
                            !in_array($ImageId, $this->DeletedImages)) {
                            $NewSettings[$Name][] = $ImageId;
                            $AltTextKey = $FieldName."_AltText_".$ImageId;
                            if (isset($this->ExtraValues[$AltTextKey])) {
                                $Image = new Image($ImageId);
                                $Image->altText(
                                    $this->ExtraValues[$AltTextKey]
                                );
                            }
                        }
                    }

                    break;

                case self::FTYPE_USER:
                    if (isset($_POST[$FieldName])) {
                        # filter blank entries out of input
                        # (these come up when a user was deleted from the list)
                        $NewValue = [];
                        foreach ($_POST[$FieldName] as $UserId) {
                            if (strlen($UserId) > 0) {
                                $NewValue[] = $UserId;
                            }
                        }

                        $DidValueChange = self::didValueChange($OldValue, $NewValue);
                        $NewSettings[$Name] = $NewValue;
                    }
                    break;

                case self::FTYPE_POINT:
                    if (isset($_POST[$FieldName."_X"]) &&
                        isset($_POST[$FieldName."_Y"])) {
                        $NewValue = [
                            "X" => $_POST[$FieldName."_X"],
                            "Y" => $_POST[$FieldName."_Y"],
                        ];
                        $NewSettings[$Name] = $NewValue;
                    }
                    break;

                default:
                    if (isset($_POST[$FieldName])) {
                        $NewValue = $_POST[$FieldName];

                        # flag that the values changed if they did
                        $DidValueChange = self::didValueChange($OldValue, $NewValue);

                        $NewSettings[$Name] = $NewValue;
                    }
                    break;
            }

            # if value changed and there is an event to signal for changes
            if ($DidValueChange && $this->SettingChangeEventName) {
                # set info about changed value in event parameters if appropriate
                $EventParams = $this->SettingChangeEventParams;
                foreach ($EventParams as $ParamName => $ParamValue) {
                    switch ($ParamName) {
                        case "SettingName":
                            $EventParams[$ParamName] = $Name;
                            break;

                        case "OldValue":
                            $EventParams[$ParamName] = $OldValue;
                            break;

                        case "NewValue":
                            $EventParams[$ParamName] = $NewValue;
                            break;
                    }
                }

                # signal event
                ApplicationFramework::getInstance()->signalEvent(
                    $this->SettingChangeEventName,
                    $EventParams
                );
            }
        }

        # return updated setting values to caller
        return $NewSettings;
    }
}
