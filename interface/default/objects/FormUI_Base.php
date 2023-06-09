<?PHP
#
#   FILE:  FormUI_Base.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2016-2022 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus;

use Exception;
use InvalidArgumentException;
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
    const OTYPE_INPUTSET = "HtmlInputSet";
    const OTYPE_LIST = "OptionList";
    const OTYPE_LISTSET = "OptionListSet";

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
        string $UniqueKey = null
    ) {
        $ErrMsgs = [];
        $this->checkForUnrecognizedFieldParameters($FieldParams, $ErrMsgs);
        $this->checkForMissingFieldParameters($FieldParams, $ErrMsgs);
        $this->checkForInvalidFieldParameters($FieldParams, $ErrMsgs);

        if (count($ErrMsgs)) {
            $ErrMsgString = implode("  ", $ErrMsgs);
            throw new InvalidArgumentException($ErrMsgString);
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
     */
    public static function ignoreFieldParameter(
        string $ParamName,
        array $FieldTypes = null
    ) {
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
     * Add extra hidden field to form.
     * @param string $FieldName Form field name.
     * @param string $Value Form field value.
     */
    public function addHiddenField(string $FieldName, string $Value)
    {
        $this->AdditionalHiddenFields[$FieldName] = $Value;
    }

    /**
     * Display HTML table with settings parameters.
     * @param string $TableId CSS ID for table element.  (OPTIONAL)
     * @param string $TableStyle CSS styles for table element.  (OPTIONAL)
     * @param string $TableCssClass Additional CSS class for table element. (OPTIONAL)
     */
    abstract public function displayFormTable(
        string $TableId = null,
        string $TableStyle = null,
        string $TableCssClass = null
    );

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
        string $IconFile = null,
        string $Classes = null
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
    public function getSubmitButtonValue()
    {
        return StdLib::getFormValue($this->getButtonName());
    }

    /**
     * Log error message for later display.
     * @param string $Msg Error message.
     * @param string $Field Field associated with error.  (OPTIONAL, defaults
     *       to no field association)
     * @param string $UniqueKey key to log error under, for form specific error handling
     */
    public static function logError(string $Msg, string $Field = null, $UniqueKey = "")
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
     */
    public static function clearLoggedErrors(string $Field = null, $UniqueKey = null)
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
        # retrieve field values without normalizing (prevents date
        # normalization from accepting potentially invalid entries)
        $Values = $this->getRawNewValuesFromForm();

        # for each field
        $ErrorsFound = 0;
        foreach ($this->FieldParams as $Name => $Params) {
            # verify any captchas
            if ($Params["Type"] == self::FTYPE_CAPTCHA) {
                if (!$GLOBALS["G_PluginManager"]->pluginEnabled("Captcha")) {
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

            # skip fields not shown to the user
            if (!$this->fieldWasDisplayedWhenFormWasSubmitted($Name)) {
                continue;
            }

            # determine if field has a value set
            switch ($Params["Type"]) {
                case self::FTYPE_SEARCHPARAMS:
                    $IsEmpty = !$Values[$Name]->parameterCount();
                    break;

                case self::FTYPE_PRIVILEGES:
                    $IsEmpty = !$Values[$Name]->comparisonCount();
                    break;

                default:
                    if (is_array($Values[$Name])) {
                        $IsEmpty = !count($Values[$Name]);
                    } else {
                        $IsEmpty = !strlen(trim($Values[$Name]));
                    }
                    break;
            }

            # if field has validation function
            if (isset($Params["ValidateFunction"])) {
                # swap in our object if this is one of our methods
                $VFunc = $Params["ValidateFunction"];
                if (is_array($VFunc) && is_subclass_of($VFunc[0], self::class)) {
                    $VFunc[0] = $this;
                }

                # call validation function for value
                $Args = array_merge(
                    [$Name, $Values[$Name], $Values],
                    $this->ExtraValidationParams
                );
                $ErrMsg = call_user_func_array($VFunc, $Args);
                if ($ErrMsg === false) {
                    throw new Exception("Calling validation function for"
                            ." parameter \"".$Name."\" failed.");
                }

                # log any resulting error
                if ($ErrMsg !== null) {
                    self::logError($ErrMsg, $Name, $this->UniqueKey);
                    $ErrorsFound++;
                }
            }

            # if field is required and empty
            if ($IsEmpty && !$Params["ReadOnly"] && $Params["Required"]) {
                # log error to indicate required value is missing
                self::logError(
                    "<i>".$Params["Label"]."</i> is required.",
                    $Name,
                    $this->UniqueKey
                );
                $ErrorsFound++;
            } else {
                # else validate based on field type
                switch ($Params["Type"]) {
                    case self::FTYPE_NUMBER:
                        # make sure value is numeric and within any specified range
                        $Value = $Values[$Name];

                        if (strlen($Value) > 0 && !is_numeric($Value)) {
                            self::logError(
                                "<i>".$Params["Label"]."</i> must be a number.",
                                $Name,
                                $this->UniqueKey
                            );
                            $ErrorsFound++;
                        } elseif ((isset($Params["MinVal"])
                                        && ($Value < $Params["MinVal"]))
                                || (isset($Params["MaxVal"])
                                        && ($Value > $Params["MaxVal"]))) {
                            if (!isset($Params["MaxVal"])) {
                                self::logError("<i>".$Params["Label"]."</i> must be "
                                        .$Params["MinVal"]
                                        ." or greater.", $Name, $this->UniqueKey);
                            } elseif (!isset($Params["MinVal"])) {
                                self::logError("<i>".$Params["Label"]."</i> must be "
                                        .$Params["MaxVal"]
                                        ." or less.", $Name, $this->UniqueKey);
                            } else {
                                self::logError("<i>".$Params["Label"]."</i> must be"
                                        ." in the range ".$Params["MinVal"]
                                        ." to ".$Params["MaxVal"]
                                        .".", $Name, $this->UniqueKey);
                            }
                            $ErrorsFound++;
                        }
                        # check if the value is allowed to be a float.
                        # if the param "AllowFloats" was not set, we should
                        #       then default to false.
                        if (!isset($Params["AllowFloats"]) || !$Params["AllowFloats"]) {
                            if (is_numeric($Value) && (floor((float)$Value) != $Value)) {
                                self::logError(
                                    "<i>".$Params["Label"]
                                            ."</i> cannot be a decimal number.",
                                    $Name,
                                    $this->UniqueKey
                                );
                                $ErrorsFound++;
                            }
                        }
                        break;

                    case self::FTYPE_URL:
                        # make sure URL entered looks valid
                        if (!$IsEmpty && (filter_var(
                            $Values[$Name],
                            FILTER_VALIDATE_URL
                        ) === false)) {
                            self::logError("Value \"".$Values[$Name]
                                    ."\" does not appear to be a valid URL for <i>"
                                    .$Params["Label"]."</i>.", $Name, $this->UniqueKey);
                            $ErrorsFound++;
                        }

                        # make sure that the URL doesn't exceed max length if set
                        if (isset($Params["MaxLength"]) &&
                            strlen($Values[$Name]) > $Params["MaxLength"]) {
                            self::logError(
                                "<i>".$Params["Label"]."</i> must not exceed "
                                .$Params["MaxLength"]." characters.",
                                $Name,
                                $this->UniqueKey
                            );
                            $ErrorsFound++;
                        }
                        break;

                    case self::FTYPE_USER:
                        # make sure user name entered is valid
                        $UFactory = new UserFactory();
                        foreach ($Values[$Name] as $UId) {
                            if (strlen($UId) && !$UFactory->userExists($UId)) {
                                self::logError("User ID \"".$UId."\" not found for <i>"
                                        .$Params["Label"]
                                        ."</i>.", $Name, $this->UniqueKey);
                                $ErrorsFound++;
                            }
                        }
                        break;

                    case self::FTYPE_TEXT:
                    case self::FTYPE_PARAGRAPH:
                    case self::FTYPE_PASSWORD:
                        # make sure that the value length doesn't exceed max length if set
                        if (isset($Params["MaxLength"]) &&
                            strlen($Values[$Name]) > $Params["MaxLength"]) {
                            self::logError(
                                "<i>".$Params["Label"]."</i> must not exceed "
                                .$Params["MaxLength"]." characters.",
                                $Name,
                                $this->UniqueKey
                            );
                            $ErrorsFound++;
                        }
                        break;
                }
            }
        }

        # report number of fields with invalid values found to caller
        return $ErrorsFound;
    }

    /**
     * Add values to be passed to input validation functions, in addition
     * to field name and value.
     * @see FormUI_Base::ValidateFieldInput()
     */
    public function addValidationParameters()
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
        $NewSettings = $this->getRawNewValuesFromForm();

        foreach ($NewSettings as $Name => $Values) {
            $Params = $this->FieldParams[$Name];

            switch ($Params["Type"]) {
                case self::FTYPE_DATETIME:
                    $NewSettings[$Name] = strlen($Values) ?
                        date($Params["Format"], strtotime($Values)) : false;
                    break;

                case self::FTYPE_PARAGRAPH:
                    # normalize newlines to '\n' instead of '\r\n' or '\r'
                    $NewSettings[$Name] = str_replace(["\r\n", "\r"], "\n", $Values);
                    break;

                default:
                    # nothing to do
                    break;
            }
        }

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
            $Value = self::loadValue($FieldType, $ValueData);
        }

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
                $MField = new MetadataField(
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
     */
    public function handleUploads()
    {
        # for each form field
        foreach ($this->FieldParams as $FieldName => $FieldParams) {
            # move on to next field if this field does not allow uploads
            if (($FieldParams["Type"] != self::FTYPE_FILE)
                    && ($FieldParams["Type"] != self::FTYPE_IMAGE)) {
                continue;
            }

            # move on to next field if this field does not have an uploaded file
            $FormFieldName = $this->getFormFieldName($FieldName);
            if (!isset($_FILES[$FormFieldName]["name"])) {
                continue;
            }
            $UploadedFileName = $_FILES[$FormFieldName]["name"];
            if (!strlen($UploadedFileName)) {
                continue;
            }

            $TmpFile = $_FILES[$FormFieldName]["tmp_name"];

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
                                    .$UploadedFileName.": ".$ErrorName."(Code ".$File."). ";
                                if (strlen($TmpFile) == 0) {
                                    $MaxFileSize = StdLib::convertPhpIniSizeToBytes(
                                        (string)ini_get("upload_max_filesize")
                                    );

                                    $Message .= "No upload file was created on the server. "
                                        ."This most commonly occurs when your file exceeds the "
                                        ."configured upload_max_filesize (currently "
                                        .($MaxFileSize / 1048576)." MiB). "
                                        ."Contact your server administrator if you need this "
                                        ."limit increased.";
                                } else {
                                    $Message .= "Temp upload location was ".$TmpFile.".";
                                }

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
        }
    }

    /**
     * Handle image and file deletions.
     */
    public function handleDeletes()
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
     * Set event to signal when retrieving values from form when settings
     * have changed.  If the supplied event parameters include parameter
     * names (indexes) of "SettingName", "OldValue", or "NewValue", the
     * parameter value will be replaced with an appropriate value before
     * the event is signaled.
     * @param string $EventName Name of event to signal.
     * @param array $EventParams Array of event parameters, with CamelCase
     *       parameter names for index.  (OPTIONAL)
     * @see FormUI_Base::GetNewsettingsFromForm()
     */
    public function setEventToSignalOnChange(
        string $EventName,
        array $EventParams = []
    ) {
        $this->SettingChangeEventName = $EventName;
        $this->SettingChangeEventParams = $EventParams;
    }

    /**
     * Determine if a new form field value is different from an old one.
     * @param mixed $OldValue Old field value.
     * @param mixed $NewValue New field value.
     * @return bool Returns TRUE if the values are different and FALSE otherwise.
     */
    public static function didValueChange($OldValue, $NewValue)
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
     * @return mixed Loaded value.
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

    /**
     * Check for invalid field parameters.
     * @param array $FieldParams Associative array of associative arrays, with
     *       field names for the top-level index, and field parameter names for
     *       the second-level index.
     * @param array $ErrMsgs Current error message list.  (REFERENCE)
     */
    private function checkForInvalidFieldParameters(array $FieldParams, &$ErrMsgs)
    {
        foreach ($FieldParams as $FieldName => $Params) {
            if (isset($Params["Type"]) && ($Params["Type"] == self::FTYPE_QUICKSEARCH)) {
                if (isset($Params["Field"])
                        && !MetadataSchema::fieldExistsInAnySchema($Params["Field"])) {
                    $ErrMsgs[] = "Specified search field for quicksearch form field "
                            .$FieldName." does not exist.";
                } else {
                    $MField = new MetadataField(
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

            if (isset($Params["ValidateFunction"])) {
                $VFunc = $Params["ValidateFunction"];
                if (is_array($VFunc) && is_subclass_of($VFunc[0], self::class)) {
                    $VFunc[0] = $this;
                }

                if (!is_callable($VFunc)) {
                    $ErrMsgs[] = "Uncallable validation function for form field "
                        .$FieldName.".";
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
            } elseif (isset($Params["DefaultFunction"])
                    && !is_callable($Params["DefaultFunction"])) {
                $ErrMsgs[] = "Default callback form field ".$FieldName
                        ." is not callable.";
            }
        }
    }

    /**
     * Check for missing field parameters.
     * @param array $FieldParams Associative array of associative arrays, with
     *       field names for the top-level index, and field parameter names for
     *       the second-level index.
     * @param array $ErrMsgs Current error message list.  (REFERENCE)
     */
    private function checkForMissingFieldParameters(array $FieldParams, &$ErrMsgs)
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
     */
    private function checkForUnrecognizedFieldParameters(array $FieldParams, &$ErrMsgs)
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
     * Normalize field parameters.
     * @param array $FieldParams Associative array of associative arrays, with
     *       field names for the top-level index, and field parameter names for
     *       the second-level index.  (REFERENCE)
     */
    protected function normalizeFieldParameters(&$FieldParams)
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
     */
    abstract protected function displayFormField(string $Name, $Value, array $Params);

    /**
     * Get HTML form field name for specified field.
     * @param string $FieldName Field name.
     * @param bool $IncludePrefix If TRUE, "F_" prefix is included.  (OPTIONAL,
     *       defaults to TRUE.)
     * @return string Form field name.
     */
    protected function getFormFieldName(string $FieldName, bool $IncludePrefix = true)
    {
        return ($IncludePrefix ? "F_" : "")
                .($this->UniqueKey ? $this->UniqueKey."_" : "")
                .preg_replace("/[^a-zA-Z0-9]/", "", $FieldName);
    }

    /**
     * Get HTML for hidden form fields associated with form processing.
     */
    protected function getHiddenFieldsHtml()
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
     * Retrieve values set by form without any normalization.
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
                        $NewValue = array_filter(
                            $_POST[$FieldName],
                            function ($v) {
                                return strlen($v) > 0 ? true : false;
                            }
                        );

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
                $GLOBALS["AF"]->signalEvent(
                    $this->SettingChangeEventName,
                    $EventParams
                );
            }
        }

        # return updated setting values to caller
        return $NewSettings;
    }
}
