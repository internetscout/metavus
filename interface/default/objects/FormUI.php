<?PHP
#
#   FILE:  FormUI.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2016-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus;
use Exception;
use ScoutLib\ApplicationFramework;
use ScoutLib\HtmlOptionList;
use ScoutLib\HtmlCheckboxSet;
use ScoutLib\HtmlRadioButtonSet;
use ScoutLib\PluginManager;
use ScoutLib\StdLib;

/**
* Child class (covering presentation elements only) supplying a standard user
* interface for presenting and working with HTML forms.
*
* NOTE:  If any fields of type FTYPE_PRIVILEGE are included in the form, the
*   enclosing <form> must have the class "priv-form" to work correctly.  (This
*   is a requirement of the PrivilegeEditingUI class.)
*
* USAGE EXAMPLE
*
* This implements a form for editing widget attributes, which are encapsulated
* within a "Widget" class, including photographs of widgets.  The photos are
* displayed in a separate form section, below the other attributes.  If the
* variable $DoNotEditName evaluates to TRUE, the name attribute of the widget
* will be displayed but not be editable.  The same validation function can be
* used for multiple form fields by checking the value of $FieldName inside the
* function to decide how to validate the incoming value(s).
*
* @code
*
*     function MyValidateFunc($FieldName, $FieldValues, $AllFieldValues, $WidgetId)
*     {
*         if ($ValueLooksOkay) {
*             return NULL;
*         }
*
*         return "An informative message about why the value(s) were invalid.";
*     }
*     $FormFields = [
*             "WidgetName" => [
*                     "Type" => FormUI::FTYPE_TEXT,
*                     "Label" => "Widget Name",
*                     "Placeholder" => "(name of widget goes here)",
*                     "Help" => "Name to use for widget.",
*                     "ValidateFunction" => "MyValidateFunc",
*                     "Required" => TRUE,
*             ],
*             "WidgetDescription" => [
*                     "Type" => FormUI::FTYPE_PARAGRAPH,
*                     "Label" => "Description",
*                     "UseWYSIWYG" => TRUE,
*                     "Help" => "Lurid description of widget.",
*             ],
*             "WidgetType" => [
*                     "Type" => FormUI::FTYPE_OPTION,
*                     "Label" => "Type of Widget",
*                     "Help" => "The type of widget.",
*                     "Options" => [
*                             WidgetClass::WT_NONE => "--",
*                             WidgetClass::WT_FIRST => "First Type",
*                             WidgetClass::WT_SECOND => "Second Type",
*                     ],
*             ],
*             "WidgetCount" => [
*                     "Type" => FormUI::FTYPE_NUMBER,
*                     "Label" => "Number of Widgets",
*                     "MinVal" => 1,
*                     "MaxVal" => 100,
*                     "Required" => TRUE,
*                     "AllowFloats" => FALSE,
*                     "Value" => $Widget->Count(),
*             ],
*             "PhotoSectionHeader" => [
*                     "Type" => FormUI::FTYPE_HEADING,
*                     "Label" => "Other Widget Info",
*             ],
*             "WidgetPhotos" => [
*                     "Type" => FormUI::FTYPE_IMAGE,
*                     "Label" => "Widget Images",
*                     "Help" => "Photos of widgets.",
*                     "AllowMultiple" => TRUE,
*                     "InsertIntoField" => "WidgetDescription",
*             ],
*     ];
*     if ($DoNotEditName) {
*         $FormFields["WidgetName"]["ReadOnly"] = TRUE;
*     }
*     $FormValues = [
*             "WidgetName" => $Widget->Name(),
*             "WidgetDescription" => $Widget->Description(),
*             "WidgetType" => $Widget->Type(),
*             "WidgetPhotos" => array_keys(
*                     $Widget->PhotosWithIdsForArrayIndex());
*     ];
*     $H_FormUI = new FormUI($FormFields, $FormValues);
*     $H_FormUI->AddValidationParameters($Widget->Id());
*
*     switch (StdLib::getFormValue($H_FormUI->getButtonName())) {
*         case "Upload":
*             $H_FormUI->HandleUploads();
*             break;
*         case "Delete":
*             $H_FormUI->HandleDeletes();
*             break;
*         default:
*             break;
*     }
*     $AF = ApplicationFramework::getInstance();
*     switch ($ButtonPushed) {
*         case "Save":
*             if ($H_FormUI->ValidateFieldInput() == 0) {
*                 $NewValues = $H_FormUI->GetNewValuesFromForm();
*                 $Widget->Name($NewValues["WidgetName"]);
*                 $Widget->Description($NewValues["WidgetDescription"]);
*                 $Widget->Count($NewValues["WidgetCount"]);
*                 $AF->SetJumpToPage("BackToWhereverWhenDone");
*             }
*             break;
*     }
*
*     FormUI::DisplayErrorBlock();
*     (start form)
*     $H_FormUI->DisplayFormTable();
*     (add submit buttons)
*     (end form)
*
* @endcode
*/
class FormUI extends FormUI_Base
{
    const OPTION_LIST_MAX_DEFAULT_DISPLAY_SIZE = 15;

    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
    * Display HTML table for form.
    * @param string $TableId CSS ID for table element.  (OPTIONAL)
    * @param string $TableStyle CSS styles for table element.  (OPTIONAL)
    * @param string $TableCssClass Additional CSS class for table element. (OPTIONAL)
    * @return void
    */
    public function displayFormTable(
        ?string $TableId = null,
        ?string $TableStyle = null,
        ?string $TableCssClass = null
    ) : void {
        # display nothing if there are no fields
        if (!count($this->FieldParams)) {
            return;
        }

        # setup counter for handling popups
        $this->DialogCount = 0;

        $AF = ApplicationFramework::getInstance();

        foreach (["jquery-ui.js", "jquery-ui.css"] as $File) {
            $AF->requireUIFile($File);
        }

        $AF->requireUIFile(
            "jquery.cookie.js",
            ApplicationFramework::ORDER_FIRST
        );

        # check whether table should be split into sections
        $TableIsSectioned = false;
        foreach ($this->FieldParams as $Name => $Params) {
            if ($Params["Type"] == self::FTYPE_HEADING) {
                $TableIsSectioned = true;
            }
        }

        # find first Paragraph field with WYSIWYG enabled for Image Insert-L and Insert-R buttons
        foreach ($this->FieldParams as $Name => $Params) {
            if ($Params["Type"] == "Paragraph" && $Params["UseWYSIWYG"]) {
                $this->FirstWYSIWYGParagraphField = $Name;
                break;
            }
        }

        # be sure that we have an ID to use for the inline CSS we'll generate
        # later to set appropriate column widths
        if (is_null($TableId)) {
            $TableId = "FormUI_Auto".self::$FormIdCounter;
            self::$FormIdCounter++;
        }

        $GroupNumber = 0;
        $HeaderShown = false;

        # begin table
        // @codingStandardsIgnoreStart
        ?><table class="table table-striped<?PHP
        if ($TableIsSectioned) {  print(" mv-table-sectioned");  }
        if (!is_null($TableCssClass)) {  print(" ".$TableCssClass);  }
        ?> mv-content-sysconfigtable mv-form-table"<?PHP
        if ($TableId) {  print(" id=\"".$TableId."\"");  }
        if ($TableStyle) {  print(" style=\"".$TableStyle."\"");  }
        print (" data-uniquekey=\"".$this->UniqueKey."\"");
        ?>>
        <?PHP
        // @codingStandardsIgnoreEnd

        if (reset($this->FieldParams)["Type"] != self::FTYPE_HEADING) {
            ?><tbody class="mv-form-group" data-group="<?= $GroupNumber ?>"><?PHP
        }
        # for each field
        $AF = ApplicationFramework::getInstance();
        foreach ($this->FieldParams as $Name => $Params) {
            # generate name for field
            $FormFieldName = $this->getFormFieldName($Name);
            # get $CookieName using page name and $Name without non-alphanumeric characters
            $CookieName = $AF->getPageName()."_"
                .preg_replace("/[^a-zA-Z0-9]/", "", $Name);

            # if field is actually a section heading
            if ($Params["Type"] == self::FTYPE_HEADING) {
                $GroupNumber++;

                # split table section and display heading
                $CssClass = "table-dark mv-form-group-head";
                if ($Params["Collapsible"]) {
                    $CssClass .= " mv-form-collapsible";
                    $IsOpen = intval(
                        $_COOKIE[$CookieName] ?? $Params["OpenByDefault"]
                    );
                } else {
                    $IsOpen = 1;
                }

                if ($HeaderShown) {
                    print "</tbody>";
                }
                ?>
                <tbody class="mv-form-group-header">
                <tr class="<?= $CssClass ?>" id="section-<?= $FormFieldName ?>"
                  ><th colspan="3" scope="rowspan"
                       data-group="<?= $GroupNumber ?>" data-cookie="<?= $CookieName ?>"
                       data-open="<?= $IsOpen ?>">
                <?PHP if ($Params["Collapsible"]) { ?>
                  (<span class="mv-form-group-indicator"><?= $IsOpen ? "-" : "+"; ?></span>)
                <?PHP } ?>
                <?= $Params["Label"] ?></th></tr>
                </tbody>
                <tbody class="mv-form-group <?= ($IsOpen == 0) ? "mv-form-group-hidden" : "" ?>"
                data-group="<?= $GroupNumber ?>">
                <?PHP
                $HeaderShown = true;
            } elseif ($Params["Type"] == self::FTYPE_GROUPEND) {
                $GroupNumber++;
                ?>
                </tbody>
                <tbody class="mv-form-group" data-group="<?= $GroupNumber ?>">
                <?PHP
            } elseif ($Params["Type"] == self::FTYPE_CUSTOMCONTENT) {
                # ignore coding standards here because phpcs gets
                # confused about indentation when switching in and out
                # of php
                // @codingStandardsIgnoreStart
                ?>
                <tr id="row-<?= $FormFieldName ?>" class="mv-content-tallrow mv-form-fieldtype-customcontent">
                    <th class="mv-content-tallrow-th" valign="top">
                        <?PHP
                            $this->displayHelp($Name, $Params);
                        ?>
                        <label class="mv-form-pseudolabel"><?= $Params["Label"]  ?></label>
                    </th>
                    <td <?PHP  if (!isset($Params["Help"]) ) {
                                    print "colspan=\"2\"";  }  ?>><?PHP
                    if (isset($Params["Callback"]))
                    {
    call_user_func_array(
$Params["Callback"],
$Params["Parameters"]
);
                    } else {
    print $Params["Content"];
                    }
                    ?></td>
                    <?PHP  if (isset($Params["Help"]) && $Params["HelpType"] == self::HELPTYPE_COLUMN) {  ?>
                    <td class="mv-content-help-cell"><?= $Params["Help"] ?></td>
                    <?PHP  }  ?>
                </tr>
                <?PHP
                // @codingStandardsIgnoreEnd
            } elseif ($Params["Type"] == self::FTYPE_CAPTCHA) {
                $this->displayCaptchaField($Name, $FormFieldName, $Params);
            } else {
                # determine if row may have taller content
                $ShortRowFieldTypes = [
                    self::FTYPE_DATETIME,
                    self::FTYPE_FLAG,
                    self::FTYPE_METADATAFIELD,
                    self::FTYPE_NUMBER,
                    self::FTYPE_PASSWORD,
                    self::FTYPE_TEXT,
                    self::FTYPE_URL,
                    self::FTYPE_USER,
                ];
                $IsTallRow =
                    ($Params["Type"] == self::FTYPE_QUICKSEARCH) ||
                    (!isset($Params["Units"])
                        && !in_array($Params["Type"], $ShortRowFieldTypes)
                        && (($Params["Type"] != self::FTYPE_OPTION)
                                || (isset($Params["Rows"])
                                        && ($Params["Rows"] > 1))) );

                # load up value(s) to go into field
                $Value = $this->getFieldValue($Name);

                # set up CSS classes for table row
                $RowClass = "mv-form-fieldtype-".
                    str_replace(" ", "_", strtolower($Params["Type"]));
                if ($Params["Type"] == "MetadataField") {
                    $RowClass .= " mv-form-schemaid-"
                            .StdLib::getArrayValue(
                                $Params,
                                "SchemaId",
                                MetadataSchema::SCHEMAID_DEFAULT
                            );
                }
                $RowClass .= " mv-form-field-".strtolower(
                    preg_replace("/[^a-zA-Z0-9]/", "", $Name)
                );
                $RowClass .= $IsTallRow ? " mv-content-tallrow" : "";
                $RowClassAttrib = ' class="'.$RowClass.'"';

                # set up CSS classes for row header cell
                $HeaderClass = $IsTallRow ? "mv-content-tallrow-th" : "";
                $HeaderClassAttrib = strlen($HeaderClass)
                        ? ' class="'.$HeaderClass.'"' : "";

                # set up CSS classes for row label
                $LabelClass = "mv-form-pseudolabel";
                if (isset(self::$ErrorMessages[$this->UniqueKey][$Name])) {
                    $LabelClass .= " mv-form-error";
                }
                $ReadOnly = $this->isReadOnlyField($Name);
                if ($Params["Required"] && !$ReadOnly) {
                    $LabelClass .= " mv-form-requiredfield";
                }

                # set up min/max note if applicable
                unset($RangeNotePieces);
                if (isset($Params["MinVal"])) {
                    $RangeNotePieces[] = "Minimum: <i>".$Params["MinVal"]."</i>";
                }
                if (isset($Params["MaxVal"])) {
                    $RangeNotePieces[] = "Maximum: <i>".$Params["MaxVal"]."</i>";
                }
                if (isset($Params["RecVal"])) {
                    $RangeNotePieces[] = "Recommended: <i>".$Params["RecVal"]."</i>";
                }
                if (isset($RangeNotePieces)) {
                    $RangeNote = "(".implode(", ", $RangeNotePieces).")";
                } else {
                    $RangeNote = "";
                }

                $HasHelpColumn = isset($Params["Help"])
                    && $Params["HelpType"] == self::HELPTYPE_COLUMN;
                $FieldColumnIsWide = !isset($RangeNotePieces)
                    && !(isset($Params["Help"]) &&
                         $Params["HelpType"] == self::HELPTYPE_COLUMN);

                # ignore coding standards here because phpcs gets
                # confused about indentation when switching in and out
                # of php
                // @codingStandardsIgnoreStart
                ?>
                <tr<?= ($IsTallRow ? " valign=\"top\"" : "").$RowClassAttrib
                            ?> id="row-<?= $FormFieldName ?>">
                    <th<?= $HeaderClassAttrib ?>>
                        <?PHP
                            $this->displayHelp($Name, $Params);
                        ?>
                        <label for="<?=  $FormFieldName
                                ?>" class="<?=  $LabelClass  ?>"><?=
                                $Params["Label"]  ?></label>
                    </th>
                    <td <?PHP if ($FieldColumnIsWide) { ?> colspan="2" <?PHP } ?>>
                        <?PHP $this->displayFormField($Name, $Value, $Params);  ?>
                    </td>
                    <?PHP  if ($HasHelpColumn) {  ?>
                    <td class="mv-content-help-cell"><?= $Params["Help"] ?></td>
                    <?PHP  } elseif (isset($RangeNotePieces)) {  ?>
                    <td class="mv-content-help-cell"><?= $RangeNote ?></td>
                    <?PHP  }  ?>
                </tr>
                <?PHP
                // @codingStandardsIgnoreEnd
            }
        }

        # end table
        ?></tbody>
        </table><?PHP

        # set the width of the first column
        print "<style type='text/css'>"
              ."#".$TableId." tbody th { "
              ."min-width: calc(".$this->lengthOfLongestFieldName()."ex + 50px);"
              ." }"
            ."</style>";

        # add any hidden form fields
        print $this->getHiddenFieldsHtml();

        # add any needed JavaScript
        $this->printSupportingJavascript($TableId);

        # pull in WYSIWYG editor setup if needed
        if ($this->UsingWysiwygEditor) {
            require_once($AF->gUIFile("CKEditorSetup.php"));
        }
    }
    // @codingStandardsIgnoreEnd

    /**
     * Display HTML block with error messages (if any).
     * @param string|null $UniqueKey key for errors,
     *     when one page has multiple FormUI/children instances (OPTIONAL).
     * @return void
     */
    public static function displayErrorBlock($UniqueKey = null): void
    {
        $ErrorText = "";
        if (count(self::$ErrorMessages)) {
            $DisplayedMsgs = [];
            $Errors = self::getLoggedErrors($UniqueKey);
            foreach ($Errors as $Field => $Msgs) {
                foreach ($Msgs as $Msg) {
                    if (!in_array($Msg, $DisplayedMsgs)) {
                        $ErrorText .= "<li>" . $Msg . "</li>\n";
                        $DisplayedMsgs[] = $Msg;
                    }
                }
            }
        }
        if (strlen($ErrorText)) {
            print "<ul class=\"mv-form-error\">\n";
            print $ErrorText;
            print "</ul>\n";
        }
    }

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
    public function getSubmitButtonHtml(
        string $Label,
        ?string $IconFile = null,
        ?string $Classes = null
    ): string {
        # if no icon file name supplied
        if ($IconFile === null) {
            # use icon matching label if available
            $IconFile = self::$ButtonIconFiles[$Label] ?? null;
        }

        # if no additional class supplied
        if ($Classes === null) {
            # use additional classes matching label if available
            $Classes = self::$ButtonExtraClasses[$Label]
                    ?? self::$ButtonDefaultClass;
        }

        # construct icon tag
        if ($IconFile === null) {
            $IconTag = "";
        } else {
            $AF = ApplicationFramework::getInstance();
            $IconTag = "<img src=\"".$AF->gUIFile($IconFile)."\" alt=\"\""
                    ." class=\"mv-button-icon\" /> ";
            $Classes .= " mv-button-iconed";
        }

        # assemble button
        $ButtonHtml = "<button type=\"submit\" name=\"".$this->getButtonName()
                ."\" class=\"btn ".$Classes."\" value=\"".$Label."\">"
                .$IconTag.$Label."</button>";

        return $ButtonHtml;
    }

    /**
     * Display the image for field's tooltip with help text
     * @param string $Help text to display on hover
     * @return void
     */
    public static function displayHoverHelp(string $Help): void
    {
        $AF = ApplicationFramework::getInstance();
        ?>
        <img class="mv-form-instructions"
        src="<?PHP $AF->pUIFile("help.png"); ?>"
        alt="?" title="<?= htmlspecialchars($Help, ENT_COMPAT) ?>"/>
        <?PHP
    }

    /**
     * Display the image for field's tooltip with hidden popup div
     * @param string $Name name of field (to be displayed in popup)
     * @param string $Help text to display on popup
     * @param string $DialogId ID JavaScript uses to show/hide popup
     * @return void
     */
    public static function displayDialogHelp(string $Name, string $Help, string $DialogId): void
    {
        $AF = ApplicationFramework::getInstance();
        $AF->requireUIFile("CW-Tooltips.js");
        ?>
        <img class="mv-form-instructions"
            src="<?PHP $AF->pUIFile("help.png"); ?>"
            alt="?" data-fieldid="<?= $DialogId ?>"/>
        <div id="<?= 'mv-dialog-'.$DialogId ?>" class="tooltip-dialog"
            style="display: none;" title="<?= htmlspecialchars($Name, ENT_COMPAT) ?>">
            <p><?= $Help ?></p>
        </div>
        <?PHP
    }

    /**
    * Handle image deletion, removing deleted images from text fields
    * where they may have been inserted.
    * @return void
    */
    public function handleDeletes(): void
    {
        parent::handleDeletes();

        $TextFieldsToCheck = [];

        # check for text fields that may contain images
        foreach ($this->FieldParams as $Name => $Params) {
            if (isset($Params["InsertIntoField"])
                    && (($Params["Type"] == self::FTYPE_FILE)
                            || ($Params["Type"] == self::FTYPE_IMAGE))) {
                $TextFieldsToCheck[] = $Params["InsertIntoField"];
            }
        }

        # load images to check
        $Images = [];
        foreach ($this->DeletedImages as $ImageId) {
            $Images[$ImageId] = new Image($ImageId);
        }

        # load files to check
        $Files = [];
        foreach ($this->DeletedFiles as $FileId) {
            $Files[$FileId] = new File($FileId);
        }

        # for each text field potentially containing references to deleted items
        foreach ($TextFieldsToCheck as $FieldName) {
            # get HTML form field name for field
            $FormFieldName = $this->getFormFieldName($FieldName);

            # for each deleted image
            foreach ($this->DeletedImages as $ImageId) {
                $ImageUrl = $Images[$ImageId]->url("mv-image-preview");

                # strip the floating div that the Insert buttons create
                $NewValue = preg_replace(
                    '%<div class="mv-form-image-(right|left)">'
                    .'<img [^>]*src="'.$ImageUrl.'"[^>]*>'
                    .'(<div [^>]*>.*?</div>)?'
                    .'</div>%',
                    "",
                    $this->getFieldValue($FieldName)
                );

                # if any <img> tags for this image remain in the html, strip those too
                $NewValue = preg_replace(
                    '%<img [^>]*src="'.$ImageUrl.'"[^>]*>%',
                    "",
                    $NewValue
                );

                $_POST[$FormFieldName] = $NewValue;
            }

            # for each deleted file
            foreach ($this->DeletedFiles as $FileId) {
                # strip out any tags we inserted that reference that file
                $FileLink = $Files[$FileId]->getLink();
                $_POST[$FormFieldName] = preg_replace(
                    "%<a [^>]*href=\""
                                .preg_quote(htmlspecialchars($FileLink), '%')
                                ."\"[^>]*>(.*?)</a>%",
                    "\1",
                    $this->getFieldValue($FieldName)
                );
            }
        }
    }

    /**
     * Set default value for threshold at or below which FTYPE_OPTION fields
     * display as radio buttons or checkboxes, rather than dropdown lists.
     * @param int $NewValue New threshold.
     * @return void
     */
    public function setDefaultOptionDisplayThreshold(int $NewValue): void
    {
        $this->OptionDisplayThreshold = $NewValue;
    }


    # ---- PRIVATE INTERFACE -------------------------------------------------

    protected $OptionDisplayThreshold = 6;
    protected $UsingWysiwygEditor = false;

    # icons to be used on submit buttons (Label => IconFile)
    private static $ButtonIconFiles = [
        "Cancel" => "Cross.svg",
        "Create" => "Check.svg",
        "Save" => "Disk.svg",
    ];
    # additional CSS classes to be used on submit buttons (Label => Classes)
    private static $ButtonExtraClasses = [
        "Cancel" => "btn-danger",
    ];
    # default CSS class to be used on submit buttons if label not found in above list
    private static $ButtonDefaultClass = "btn-primary";

    /**
    * Display HTML form field for specified field.
    * @param string $Name Field name.
    * @param mixed $Value Current value for field.
    * @param array $Params Field parameters.
    * @return void
    */
    protected function displayFormField(string $Name, $Value, array $Params): void
    {
        switch ($Params["Type"]) {
            case self::FTYPE_TEXT:
            case self::FTYPE_DATETIME:
            case self::FTYPE_NUMBER:
            case self::FTYPE_URL:
            case self::FTYPE_PASSWORD:
                $this->displayTextField($Name, $Value, $Params);
                break;

            case self::FTYPE_PARAGRAPH:
                $this->displayParagraphField($Name, $Value, $Params);
                break;

            case self::FTYPE_FLAG:
                $this->displayFlagField($Name, $Value, $Params);
                break;

            case self::FTYPE_OPTION:
                $this->displayOptionField($Name, $Value, $Params);
                break;

            case self::FTYPE_METADATAFIELD:
                $this->displayMetadataFieldField($Name, $Value, $Params);
                break;

            case self::FTYPE_PRIVILEGES:
                $this->displayPrivilegesField($Name, $Value, $Params);
                break;

            case self::FTYPE_POINT:
                $this->displayPointField($Name, $Value, $Params);
                break;

            case self::FTYPE_SEARCHPARAMS:
                $this->displaySearchParamsField($Name, $Value, $Params);
                break;

            case self::FTYPE_USER:
            case self::FTYPE_QUICKSEARCH:
                $this->displayQuicksearchField($Name, $Value, $Params);
                break;

            case self::FTYPE_FILE:
                $this->displayFileField($Name, $Value, $Params);
                break;

            case self::FTYPE_IMAGE:
                $this->displayImageField($Name, $Value, $Params);
                break;
        }

        if (isset($Params["Units"])) {
            ?>&nbsp;<span><?= $Params["Units"] ?></span><?PHP
        }

        if (isset($Params["AdditionalHtml"])) {
            print '<div class="mv-form-additional-html">'
                .$Params["AdditionalHtml"]
                .'</div>';
        }
    }

    /**
    * Display HTML form field for Text, DateTime, Number, Url, and Password fields.
    * @param string $Name Field name.
    * @param mixed $Value Current value for field.
    * @param array $Params Field parameters.
    */
    protected function displayTextField(
        string $Name,
        $Value,
        array $Params
    ): void {
        $AF = ApplicationFramework::getInstance();
        $ReadOnly = $this->isReadOnlyField($Name);
        $FieldName = $this->getFormFieldName($Name);

        $DefaultSize = ($Params["Type"] == self::FTYPE_NUMBER) ? 6 : 40;
        $DefaultMaxLen = ($Params["Type"] == self::FTYPE_NUMBER) ? 12 : 80;
        $Size = $Params["Size"] ?? (isset($Params["MaxVal"])
                ? (strlen((string)(intval($Params["MaxVal"]) + 1)))
                : $DefaultSize);
        $MaxLen = $Params["MaxLength"] ?? (isset($Params["MaxVal"])
                ? (strlen((string)(intval($Params["MaxVal"]) + 3)))
                : $DefaultMaxLen);
        $Placeholder = $Params["Placeholder"] ?? "(".strtolower($Params["Label"]).")";
        $InputType = ($Params["Type"] == self::FTYPE_PASSWORD)
                ? "password" : "text";
        $EscapedValue = htmlspecialchars(
            $AF->escapeInsertionKeywords($Value ?? "")
        );
        print('<input type="'.$InputType.'" dir="auto" size="'.$Size.'" maxlength="'
                .$MaxLen.'" id="'.$FieldName.'" name="'.$FieldName.'"'
                .' value="'.$EscapedValue.'"'
                .' placeholder=" '.htmlspecialchars($Placeholder).'"'
                .($ReadOnly ? " readonly" : "").' />');
        if (($Params["Type"] == self::FTYPE_DATETIME)
                && !$ReadOnly
                && $Params["UpdateButton"]) {
            print('<button type="button" class="btn btn-primary mv-timestamp-update '
                  .'mv-button-iconed" '
                  .'title="Update this field to the current time" '
                  .'onclick=\'$("#'.$FieldName.'").val('
                  .'(new Date(Date.now() - ((new Date()).getTimezoneOffset() * 60000))'
                  .'.toISOString()'
                  .'.replace("T"," ").replace(/\.\d{3}Z/,"")));\'/>'
                  .'<img src="'.$AF->gUIFile('RefreshArrow.svg').'"'
                  .'alt="" class="mv-button-icon" /> Update</button>');
        }
    }

    /**
    * Display HTML form field for Paragraph field.
    * @param string $Name Field name.
    * @param mixed $Value Current value for field.
    * @param array $Params Field parameters.
    */
    protected function displayParagraphField(
        string $Name,
        $Value,
        array $Params
    ): void {
        $AF = ApplicationFramework::getInstance();
        $ReadOnly = $this->isReadOnlyField($Name);
        $FieldName = $this->getFormFieldName($Name);

        $Rows = isset($Params["Rows"]) ? $Params["Rows"]
                : (isset($Params["Height"]) ? $Params["Height"] : 4);
        $Columns = isset($Params["Columns"]) ? $Params["Columns"]
                : (isset($Params["Width"]) ? $Params["Width"] : 40);
        $MaxLen = isset($Params["MaxLength"]) ? $Params["MaxLength"] : "";
        # ENT_SUBSTITUTE, to supersede htmlspecialchars's default flags.
        $EscapedValue = htmlspecialchars(
            $AF->escapeInsertionKeywords($Value ?? ""),
            ENT_SUBSTITUTE
        );
        print('<textarea rows="'.$Rows.'" cols="'.$Columns
                .'" id="'.$FieldName.'" name="'.$FieldName.'" maxlength="'.$MaxLen.'"'
                .($ReadOnly ? " readonly" : "")
                .($Params["UseWYSIWYG"] ? ' class="ckeditor"' : "").'>'
        .$EscapedValue
                .'</textarea>');
        if ($Params["UseWYSIWYG"]) {
            $this->UsingWysiwygEditor = true;
        }
    }

    /**
    * Display HTML form field for Flag field.
    * @param string $Name Field name.
    * @param mixed $Value Current value for field.
    * @param array $Params Field parameters.
    */
    protected function displayFlagField(
        string $Name,
        $Value,
        array $Params
    ): void {
        $ReadOnly = $this->isReadOnlyField($Name);
        $FieldName = $this->getFormFieldName($Name);

        if (array_key_exists("OnLabel", $Params)
                && array_key_exists("OffLabel", $Params)) {
            print('<input type="radio" id="'.$FieldName.'On" name="'
                    .$FieldName.'" value="1"'
                    .($Value ? ' checked' : '')
                    .($ReadOnly ? ' disabled' : '')
                    .' /> <label for="'.$FieldName.'On">'.$Params["OnLabel"]
                    ."</label>\n");
            print('<input type="radio" id="'.$FieldName.'Off" name="'
                    .$FieldName.'" value="0"'
                    .($Value ? '' : ' checked')
                    .($ReadOnly ? ' disabled' : '')
                    .' /> <label for="'.$FieldName.'Off">'.$Params["OffLabel"]
                    ."</label>\n");
        } else {
            print('<input type="checkbox" id="'.$FieldName.'" name="'
                    .$FieldName.'" '
                    .($Value ? ' checked' : '')
                    .($ReadOnly ? ' disabled' : '')
                    ." />\n");
        }
    }

    /**
    * Display HTML form field for Option field.
    * @param string $Name Field name.
    * @param mixed $Value Current value for field.
    * @param array $Params Field parameters.
    */
    protected function displayOptionField(
        string $Name,
        $Value,
        array $Params
    ): void {
        if (count($Params["Options"]) == 0) {
            print "<i>(no values defined for this field)</i>";
            return;
        }

        $FieldName = $this->getFormFieldName($Name);
        if ($this->isRadioButtonField($Name)) {
            $OptList = new HtmlRadioButtonSet(
                $FieldName,
                $Params["Options"],
                $Value
            );
        } elseif ($this->isCheckboxField($Name)) {
            $OptList = new HtmlCheckboxSet(
                $FieldName,
                $Params["Options"],
                $Value
            );
        } else {
            if (isset($Params["OptionType"])
                    && ($Params["OptionType"] == self::OTYPE_LISTSET)) {
                $OptList = new HtmlOptionListSet(
                    $FieldName,
                    $Params["Options"],
                    $Value
                );
            } else {
                $OptList = new HtmlOptionList(
                    $FieldName,
                    $Params["Options"],
                    $Value
                );
                $OptList->multipleAllowed($Params["AllowMultiple"]);
                $OptList->size(isset($Params["Rows"]) ? $Params["Rows"] : 1);
                if (isset($Params["Rows"])) {
                    $OptListSize = $Params["Rows"];
                } else {
                    if ($Params["AllowMultiple"]) {
                        $OptListSize = min(
                            count($Params["Options"]),
                            self::OPTION_LIST_MAX_DEFAULT_DISPLAY_SIZE
                        );
                    } else {
                        $OptListSize = 1;
                    }
                }
                $OptList->size($OptListSize);
            }
        }
        if ($this->isReadOnlyField($Name)) {
            $OptList->disabled(true);
        }
        print '<div class="mv-form-optlist">';
        $OptList->printHtml();
        print '</div>';
    }

    /**
    * Display HTML form field for Option field.
    * @param string $Name Field name.
    * @param mixed $Value Current value for field.
    * @param array $Params Field parameters.
    */
    protected function displayMetadataFieldField(
        string $Name,
        $Value,
        array $Params
    ): void {
        $FieldName = $this->getFormFieldName($Name);
        $FieldTypes = StdLib::getArrayValue($Params, "FieldTypes");
        $SchemaId = StdLib::getArrayValue(
            $Params,
            "SchemaId",
            MetadataSchema::SCHEMAID_DEFAULT
        );
        $Schema = new MetadataSchema($SchemaId);
        print $Schema->getFieldsAsOptionList(
            $FieldName,
            $FieldTypes,
            $Value,
            !$Params["AllowMultiple"] && !$Params["Required"],
            null,
            $Params["AllowMultiple"],
            $this->isReadOnlyField($Name)
        );
    }

    /**
    * Display HTML form field for Option field.
    * @param string $Name Field name.
    * @param mixed $Value Current value for field.
    * @param array $Params Field parameters.
    */
    protected function displayPrivilegesField(
        string $Name,
        $Value,
        array $Params
    ): void {
        # (convert legacy previously-stored values if necessary)
        if (is_array($Value)) {
            $PrivSet = new PrivilegeSet();
            $PrivSet->addPrivilege($Value);
            $Value = $PrivSet;
        }

        $Schemas = StdLib::getArrayValue($Params, "Schemas");
        $MFields = StdLib::getArrayValue($Params, "MetadataFields", []);
        $PEditor = new PrivilegeEditingUI($Schemas, $MFields);
        $FieldName = $this->getFormFieldName($Name, false);
        $PEditor->displaySet($FieldName, $Value);
    }

    /**
    * Display HTML form field for Option field.
    * @param string $Name Field name.
    * @param mixed $Value Current value for field.
    * @param array $Params Field parameters.
    */
    protected function displayPointField(
        string $Name,
        $Value,
        array $Params
    ): void {
        $FieldName = $this->getFormFieldName($Name);
        $ReadOnlyAttrib = $this->isReadOnlyField($Name) ? " readonly" : "";
        print '<input type="text" '
                .' id="'.$FieldName.'" name="'.$FieldName.'_X"'
                .' value="'.htmlspecialchars($Value["X"] ?? "").'"'
                .' size="'.$Params["Size"].'" '
                .$ReadOnlyAttrib.' />'
                .'<input type="text" '
                .' id="'.$FieldName.'" name="'.$FieldName.'_Y"'
                .' value="'.htmlspecialchars($Value["Y"] ?? "").'"'
                .' size="'.$Params["Size"].'" '
                .$ReadOnlyAttrib.' />';
    }

    /**
    * Display HTML form field for Option field.
    * @param string $Name Field name.
    * @param mixed $Value Current value for field.
    * @param array $Params Field parameters.
    */
    protected function displaySearchParamsField(
        string $Name,
        $Value,
        array $Params
    ): void {
        $FieldName = $this->getFormFieldName($Name);
        $SPEditor = new SearchParameterSetEditingUI($FieldName, $Value);

        if (isset($Params["MaxFieldLabelLength"])) {
            $SPEditor->maxFieldLabelLength($Params["MaxFieldLabelLength"]);
        }
        if (isset($Params["MaxValueLabelLength"])) {
            $SPEditor->maxValueLabelLength($Params["MaxValueLabelLength"]);
        }

        $SPEditor->displayAsTable(null, "mv-table-nostripes table-borderless");
    }

    /**
    * Display HTML form field for Option field.
    * @param string $Name Field name.
    * @param mixed $Value Current value for field.
    * @param array $Params Field parameters.
    */
    protected function displayQuicksearchField(
        string $Name,
        $Value,
        array $Params
    ): void {
        $FieldName = $this->getFormFieldName($Name);
        $ReadOnly = $this->isReadOnlyField($Name);

        if (is_null($Value)) {
            $Value = [];
        }

        if (!$ReadOnly) {
            print "<div class='mv-quicksearchset mv-mutable-widget'>";
        }

        # set up some helpers that abstract over the
        # differences between a USER and a QUICKSEARCH field
        if ($Params["Type"] == self::FTYPE_USER) {
            if (isset($Params["Field"])) {
                $MField = MetadataField::getField($Params["Field"]);
                $Search = $Params["Field"];
                $AllowMultiple = $MField->allowMultiple();
            } else {
                $Search = QuickSearchHelper::USER_SEARCH;
                $AllowMultiple = $Params["AllowMultiple"];
            }
            $UFactory = new UserFactory();
            $NameFn = function ($Key, $Val) use ($UFactory) {
                return $UFactory->userExists($Val) ?
                        (new User($Val))->name() : "" ;
            };
            $IdFn = function ($Key, $Val) {
                return $Val;
            };
        } else {
            $MField = MetadataField::getField($Params["Field"]);
            $Search = $Params["Field"];
            $AllowMultiple = $MField->allowMultiple();
            $NameFn = function ($Key, $Val) use ($MField) {
                if ($MField->type() == MetadataSchema::MDFTYPE_REFERENCE) {
                    $Resource = new Record($Key);
                    return $Resource->getMapped("Title");
                } else {
                    return $Val;
                }
            };
            $IdFn = function ($Key, $Val) {
                return $Key;
            };
        }

        # filter out empty incoming values
        $Value = array_filter(
            $Value,
            function ($x) {
                return strlen($x) > 0;
            }
        );

        if (count($Value)) {
            # iterate over incoming values
            foreach ($Value as $Key => $Val) {
                # pull out the corresponding name/id
                $VName = $NameFn($Key, $Val);
                $VId = $IdFn($Key, $Val);

                # print UI elements
                if ($ReadOnly) {
                    print "<p>".defaulthtmlentities($VName)."</p>";
                } else {
                    QuickSearchHelper::printQuickSearchField(
                        $Search,
                        $VId,
                        defaulthtmlentities($VName),
                        false,
                        $FieldName
                    );
                }
            }
        }

        if (!$ReadOnly) {
            # display a blank row for adding more values
            # when we have no values or when we allow more
            if (isset($MField) && $MField->getCountOfPossibleValues() == 0) {
                print "(<i>no values defined for this field</i>)";
            } elseif (count($Value) == 0 || $AllowMultiple) {
                QuickSearchHelper::printQuickSearchField(
                    $Search,
                    "",
                    "",
                    $AllowMultiple,
                    $FieldName
                );
            }
            print "</div>";
        }
    }

    /**
    * Display HTML image form field for specified field.
    * @param string $Name Field name.
    * @param mixed $Value Current value for field.
    * @param array $Params Field parameters.
    */
    protected function displayImageField(string $Name, $Value, array $Params): void
    {
        $AF = ApplicationFramework::getInstance();
        $FieldName = $this->getFormFieldName($Name);

        # normalize incoming value
        $Images = is_array($Value) ? $Value
                : (($Value === null) ? [] : [$Value]);
        $ReadOnly = $this->isReadOnlyField($Name, $Params);

        # begin value table
        print '<table class="mv-form-image-table">';

        # for each incoming value
        $ImagesDisplayed = 0;
        foreach ($Images as $Image) {
            # skip if image is a placeholder to indicate no images for field
            if ($Image == self::NO_VALUE_FOR_FIELD) {
                continue;
            }

            # load up image object if ID supplied
            if (is_numeric($Image)) {
                $Image = new Image(intval($Image));
            }

            # skip image if it has been deleted
            if (in_array($Image->Id(), $this->DeletedImages)) {
                continue;
            }

            # load various image attributes for use in HTML
            $ImageUrlSource = defaulthtmlentities($Image->url("mv-image-large"));
            $ImageId = $Image->Id();
            $ImageAltTextFieldName = $FieldName."_AltText_".$ImageId;
            $ImageAltText = defaulthtmlentities(
                isset($_POST[$ImageAltTextFieldName])
                    ? $_POST[$ImageAltTextFieldName]
                : $Image->AltText()
            );

            $DeleteFieldName = $this->getFormFieldName("ImageToDelete");

            $InsertButtonHtml =  $this->getHtmlForImageInsertionButtons(
                $Image
            );

            # add table row for image
            ?><tr>
                <td><a href="<?= $ImageUrlSource ?>" target="_blank"
                       ><?= $Image->getHtml("mv-image-thumbnail") ?></a></td>
                <?PHP if (!$ReadOnly) { ?>
                  <td style="white-space: nowrap;"><label for="<?=
                        $ImageAltTextFieldName ?>" class="mv-form-pseudolabel">
                        Alt Text:</label><input type="text" size="20"
                        maxlength="120" name="<?= $ImageAltTextFieldName ?>"
                        value="<?= $ImageAltText ?>"
                        placeholder=" (alternate text for image)"></td>
                  <td><?= $InsertButtonHtml ?><br/><button type="submit"
                        name="<?= $this->getButtonName() ?>"
                        class="btn btn-danger mv-button-iconed"
                        onclick="$('#<?= $DeleteFieldName ?>').val('<?= $ImageId
                        ?>');" value="Delete"><img src="<?= $AF->gUIFile('Delete.svg'); ?>" alt=""
                                                class="mv-button-icon" /> Delete</button></td>
                <?PHP } ?>
            </tr><?PHP
            $ImagesDisplayed++;

            # add image ID to hidden fields
            $this->HiddenFields[$FieldName."_ID"][] = $Image->Id();

            # add container to hold ID of any image to be deleted
            if (!isset($this->HiddenFields[$DeleteFieldName])) {
                $this->HiddenFields[$DeleteFieldName] = "";
            }
        }

        # if no images were displayed and an image entry was skipped
        if (($ImagesDisplayed == 0) && count($Images)) {
            # add marker to indicate no images to hidden fields
            $this->HiddenFields[$FieldName."_ID"][] = self::NO_VALUE_FOR_FIELD;
        }

        # add table row for new image upload
        if (!$ReadOnly && ($Params["AllowMultiple"] || ($ImagesDisplayed == 0))) {
            $ImageAltTextFieldName = $FieldName."_AltText_NEW";
            ?><tr>
                <td><input type="file" name="<?= $FieldName ?>" /></td>
                <td style="white-space: nowrap;"><label for="<?=
                        $ImageAltTextFieldName ?>" class="mv-form-pseudolabel">
                        Alt Text:</label><input type="text" size="20"
                        maxlength="120" name="<?= $ImageAltTextFieldName ?>"
                        placeholder=" (alternate text for image)"></td>
                <td><button type="submit" name="<?= $this->getButtonName() ?>"
                        class="btn btn-primary mv-button-iconed" value="Upload"><img
                        src="<?= $AF->gUIFile('Upload.svg'); ?>" alt=""
                        class="mv-button-icon" /> Upload</button></td>
            </tr><?PHP
        }

        # end value table
        print '</table>';
    }

    /**
    * Display HTML file form field for specified field.
    * @param string $Name Field name.
    * @param mixed $Value Current value for field.
    * @param array $Params Field parameters.
    * @return void
    */
    protected function displayFileField(string $Name, $Value, array $Params): void
    {
        $AF = ApplicationFramework::getInstance();
        $FieldName = $this->getFormFieldName($Name);

        # normalize incoming value
        $Files = is_array($Value) ? $Value
                : (($Value === null) ? [] : [$Value]);
        $ReadOnly = $this->isReadOnlyField($Name, $Params);

        # begin value table
        print '<table class="mv-form-filefield-table"'
            .' data-fieldname="'.defaulthtmlentities($Name).'">';

        # for each incoming value
        $FilesDisplayed = 0;
        $InsertButtonHtml = "";
        foreach ($Files as $File) {
            # skip if file is a placeholder to indicate no files for field
            if ($File == self::NO_VALUE_FOR_FIELD) {
                continue;
            }

            # load up file object if ID supplied
            if (is_numeric($File)) {
                $File = new File($File);
            }

            # skip file if it has been deleted
            if (in_array($File->Id(), $this->DeletedFiles)) {
                continue;
            }

            # load various attributes for use in HTML
            $FileId = $File->Id();
            $FileUrl = $File->GetLink();
            $FileName = $File->Name();
            $FileType = $File->getMimeType();
            $SafeFileName = htmlspecialchars(
                str_replace("'", "\\'", $FileName)
            );
            $FileLinkTag = "<a href=\"".htmlspecialchars($FileUrl)."\""
                        ." class=\"mv-form-filefield-value\""
                        ." data-fileid=\"".$FileId."\""
                        ." data-filetype=\"".htmlspecialchars($FileType)."\""
                        ." target=\"_blank\">"
                        .$SafeFileName."</a>";
            $DeleteFieldName = $this->getFormFieldName("FileToDelete");

            # build up HTML for any insert buttons
            if (isset($Params["InsertIntoField"])) {
                $InsertField = $this->getFormFieldName($Params["InsertIntoField"]);
                $InsertCommand = defaulthtmlentities(
                    "CKEDITOR.instances['".$InsertField
                    ."'].insertHtml('".$FileLinkTag."');"
                );
                $InsertButtonHtml = '<button type="button" onclick="'
                        .$InsertCommand.'">Insert</button>';
            }

            # add table row for file
            ?><tr>
                <td><?= $FileLinkTag ?></td>
                <?PHP if (!$ReadOnly) { ?>
                  <td><?= $InsertButtonHtml ?><button type="submit"
                        name="<?= $this->getButtonName() ?>"
                        class="btn btn-danger mv-button-iconed mv-button-delete-file"
                        onclick="$('#<?= $DeleteFieldName ?>').val('<?= $FileId
                        ?>');" value="Delete"><img src="<?= $AF->gUIFile('Delete.svg'); ?>" alt=""
                                class="mv-button-icon" /> Delete</button></td>
                <?PHP } ?>
            </tr><?PHP
            $FilesDisplayed++;

            # add file ID to hidden fields
            $this->HiddenFields[$FieldName."_ID"][] = $FileId;

            # add container to hold ID of any file to be deleted
            if (!isset($this->HiddenFields[$DeleteFieldName])) {
                $this->HiddenFields[$DeleteFieldName] = "";
            }
        }

        # if no files were displayed and a file entry was skipped
        if (($FilesDisplayed == 0) && count($Files)) {
            # add marker to indicate no files to hidden fields
            $this->HiddenFields[$FieldName."_ID"][] = self::NO_VALUE_FOR_FIELD;
        }

        # add table row for new file upload
        if ($Params["AllowMultiple"] || ($FilesDisplayed == 0)) {
            ?><tr>
                <td><input type="file" name="<?= $FieldName ?>" /></td>
                <td><button type="submit" name="<?= $this->getButtonName() ?>"
                     class="btn btn-primary mv-button-iconed" value="Upload"><img
                     src="<?= $AF->gUIFile('Upload.svg'); ?>" alt=""
                     class="mv-button-icon" /> Upload</button></td>
            </tr><?PHP
        }

        # end value table
        print '</table>';
    }

    /**
     * Print any javascript necessary for this form.
     * @param string $FormTableId ID of the table containing our form
     */
    protected function printSupportingJavascript(string $FormTableId): void
    {
        parent::printSupportingJavascript($FormTableId);

        $this->printFieldHidingJavascript();
    }

    /**
     * Print any JavaScript required to support toggling display of fields
     * or sections.
     * @return void
     */
    protected function printFieldHidingJavascript(): void
    {
        # for each form field
        foreach ($this->FieldParams as $ToggledField => $Params) {
            # if field has togglers (other fields that can toggle this field)
            if (isset($Params["DisplayIf"])) {
                # for each toggler
                foreach ($Params["DisplayIf"] as $Toggler => $ToggleValues) {
                    # add field to list of fields toggled by this toggler
                    $FieldsToggled[$Toggler][] = $ToggledField;

                    # add values to list of values that toggle this field
                    if (!is_array($ToggleValues)) {
                        $ToggleValues = [$ToggleValues];
                    }
                    $FieldToggleValues[$ToggledField][$Toggler] = $ToggleValues;
                }
            }
        }

        ?>
        <script type="text/javascript">
        (function($){
        <?PHP
        # if there were fields that toggle other fields
        if (isset($FieldsToggled) && isset($FieldToggleValues)) {
            # start JavaScript code

            # for each toggler
            foreach ($FieldsToggled as $Toggler => $ToggledFields) {
                # begin function called when toggler changes
                $TogglerFFName = $this->getFormFieldName((string)$Toggler);
                ?>
                $("[id^=<?= $TogglerFFName ?>]").change(function(){
                <?PHP

                # for each togglee (field being toggled)
                foreach ($ToggledFields as $ToggledField) {
                    # get JavaScript condition for this togglee
                    $ConditionJS = $this->getFieldToggleConditionJS(
                        $FieldToggleValues[$ToggledField]
                    );

                    # add toggle code for togglee
                    $ToggledFieldFFName = $this->getFormFieldName($ToggledField);
                    ?>
                    $("#row-<?= $ToggledFieldFFName ?>")[<?=
                            $ConditionJS ?> ? "show" : "hide"]();
                    <?PHP
                }

                # end function for field changing

                # fix up the table striping (note :even applies the
                # mv-table-striped-odd class because jQuery uses zero-based indexing)
                // @codingStandardsIgnoreStart
                ?>
                $(".mv-table-striped > tbody").each(function(ix,val){
                    $(val).children('tr').removeClass(
                        'mv-table-striped-even mv-table-striped-odd mv-table-striped-force');
                    $(val).children("tr:visible:even").addClass(
                        'mv-table-striped-odd mv-table-striped-force');
                    $(val).children("tr:visible:odd").addClass(
                        'mv-table-striped-even mv-table-striped-force');
                  });
                }).change();
                <?PHP
                // @codingStandardsIgnoreEnd
            }
        }

        ?>
        $(".mv-form-collapsible").click(function(ev){
            var tgt = $(ev.target);
            if (tgt.is(".mv-form-group-indicator")) {
                tgt = tgt.parent();
            }

            tgt.data('open', 1 - tgt.data('open'));
            $.cookie(tgt.data('cookie'), tgt.data('open'));

            $(".mv-form-group[data-group="+tgt.data('group')+"]")
                 .toggleClass('mv-form-group-hidden');
            $(".mv-form-group-indicator", tgt).text(
                tgt.data('open') ? "-" : "+"
            );
        });
        }(jQuery));
        </script>
        <?PHP
    }

    /**
    * Get JavaScript snippet that can be used for toggling a form field.
    * @param array $ToggleValues Values that toggle field, with togglers
    *       (form fields to which may contain the values) for the index.
    * @return string JavaScript condition string.
    */
    private function getFieldToggleConditionJS($ToggleValues): string
    {
        # for each toggler
        $SubConditionStrings = [];
        foreach ($ToggleValues as $Toggler => $ToggleValues) {
            # start with fresh subcondition list
            $SubConditions = [];

            # for each toggle value
            $TogglerFFName = $this->getFormFieldName($Toggler);
            foreach ($ToggleValues as $Value) {
                # build subcondition for value
                if ($this->FieldParams[$Toggler]["Type"] == self::FTYPE_FLAG) {
                    if ($Value) {
                        $SubConditions[] = "($(\"input[name=".$TogglerFFName
                                ."]\").is(\":checked:visible\"))";
                    } else {
                        $SubConditions[] = "(!$(\"input[name=".$TogglerFFName
                                ."]\").is(\":checked:visible\"))";
                    }
                } else {
                    if ($this->isRadioButtonField($Toggler)) {
                        $SubConditions[] = "($(\"input[name=".$TogglerFFName
                                ."]:checked\").val() == \"".$Value."\")";
                    } else {
                        $SubConditions[] = "($(\"#".$TogglerFFName
                                ."\").val() == \"".$Value."\")";
                    }
                }
            }

            # assemble subconditions into condition
            if (count($SubConditions) > 1) {
                $SubConditionStrings[] = "(".implode(" || ", $SubConditions).")";
            } else {
                $SubConditionStrings[] = $SubConditions[0];
            }
        }

        # assemble conditions into condition string
        $ConditionString = implode(" && ", $SubConditionStrings);

        return $ConditionString;
    }



    /**
    * Check whether specified field should be displayed as radio buttons.
    * @param string $Name Name of field.
    * @return bool TRUE if field should use radio buttons, otherwise FALSE.
    */
    private function isRadioButtonField(string $Name): bool
    {
        $Params = $this->FieldParams[$Name];
        if ($Params["Type"] == self::FTYPE_OPTION && !$Params["AllowMultiple"]) {
            if (isset($Params["OptionType"]) && $Params["OptionType"] == self::OTYPE_INPUTSET) {
                return true;
            }

            $Threshold = $Params["OptionThreshold"] ?? $this->OptionDisplayThreshold;
            return (count($Params["Options"]) <= $Threshold) ? true : false;
        }

        return false;
    }

    /**
    * Check whether specified field should be displayed as checkboxes.
    * @param string $Name Name of field.
    * @return bool TRUE if field should use radio buttons, otherwise FALSE.
    */
    private function isCheckboxField(string $Name): bool
    {
        $Params = $this->FieldParams[$Name];
        if ($Params["Type"] == self::FTYPE_OPTION && $Params["AllowMultiple"]) {
            if (isset($Params["OptionType"]) &&
                $Params["OptionType"] == self::OTYPE_INPUTSET) {
                return true;
            }

            $Threshold = $Params["OptionThreshold"] ?? $this->OptionDisplayThreshold;
            return (count($Params["Options"]) <= $Threshold) ? true : false;
        }

        return false;
    }

    /**
    * Check whether specified field should be displayed as checkboxes.
    * @param string $Name Name of field.
    * @param ?array $Params Field parameters array.  [OPTIONAL, if not
    *       supplied, $this->FieldParams will be used]
    * @return bool TRUE if field should use radio buttons, otherwise FALSE.
    */
    private function isReadOnlyField(string $Name, ?array $Params = null): bool
    {
        if ($Params === null) {
            $Params = $this->FieldParams[$Name];
        }
        return isset($Params["ReadOnlyFunction"])
                ? $Params["ReadOnlyFunction"]($Name)
                : ($Params["ReadOnly"] ?? false);
    }

    /**
     * Get the length of the longest field name in the form in characters.
     * @return int Length of the longest field name.
     */
    private function lengthOfLongestFieldName() : int
    {
        # determine the width of the longest field name
        $Length = 0;
        foreach ($this->FieldParams as $Name => $Params) {
            # if field is actually a section heading
            if ($Params["Type"] == self::FTYPE_HEADING) {
                continue;
            }

            $Length = max($Length, strlen($Name));
        }

        return $Length;
    }


    /**
     * Display a captcha field.
     * @param string $Name Field name.
     * @param string $FormFieldName HTML form field name.
     * @param array $Params Field parameters.
     * @return void
     */
    private function displayCaptchaField(
        string $Name,
        string $FormFieldName,
        array $Params
    ) : void {
        $AF = ApplicationFramework::getInstance();
        $PlugManager = PluginManager::getInstance();
        if (!$PlugManager->pluginEnabled("Captcha")) {
            return;
        }

        $CPlugin = $PlugManager->getPlugin("Captcha");

        $CaptchaKey = md5($this->UniqueKey.$Name);
        $CaptchaHtml = $CPlugin->getCaptchaHtml($CaptchaKey);

        # getCatchaHtml() returns an empty string when no captcha should be displayed
        if (strlen($CaptchaHtml) == 0) {
            return;
        }

        $LabelClass = "mv-form-pseudolabel";
        if (isset(self::$ErrorMessages[$this->UniqueKey][$Name])) {
            $LabelClass .= " mv-form-error";
        }

        ?>
        <tr id="row-<?= $FormFieldName ?>" class="mv-content-tallrow mv-form-fieldtype-captcha">
          <th class="mv-content-tallrow-th" valign="top">
            <img class="mv-form-instructions"
                 src="<?PHP $AF->pUIFile("help.png"); ?>"
                 alt="?" title="Enter the verification code to prove you are not a spam robot."/>
            <label class="<?= $LabelClass ?>"><?= $Params["Label"] ?></label>
          </th>
          <td><?= $CaptchaHtml ?></td>
          <td><?= $Params["Help"] ?? "" ?></td>
        </tr><?PHP
    }

    /**
     * Helper function for displaying hover/dialog tooltips
     * @param string $Name name of field to be displayed in dialog header
     * @param array $Params tooltip contents
     * @return void
     */
    private function displayHelp($Name, $Params): void
    {
        if (!isset($Params["Help"])) {
            return;
        }

        switch ($Params["HelpType"]) {
            case self::HELPTYPE_DIALOG:
                self::displayDialogHelp(
                    $Name,
                    $Params["Help"],
                    $this->UniqueKey.$this->DialogCount
                );
                $this->DialogCount++;
                break;
            case self::HELPTYPE_HOVER:
                self::displayHoverHelp($Params["Help"]);
                break;
            case self::HELPTYPE_COLUMN:
                # to be displayed/handled elsewhere
                return;
            default:
                throw new Exception("Invalid HelpType: ".$Params["HelpType"]);
        }
    }

    /**
     * Get html for image insertion buttons.
     * @param Image $Image Image to add buttons for
     * @return string Generated html
     */
    private function getHtmlForImageInsertionButtons(Image $Image): string
    {
        if (is_null($this->FirstWYSIWYGParagraphField)) {
            return "";
        }

        $InsertField = $this->getFormFieldName($this->FirstWYSIWYGParagraphField);
        $ImageUrl = $Image->url("mv-image-preview");
        $SafeAltText = htmlspecialchars($Image->altText(), ENT_QUOTES |  ENT_HTML5);

        # note: the insertion commands below for onclick have to match those in
        # RecordEditingUI.js for CKEDITOR's instanceReady event
        $InsertLeftCommand = defaulthtmlentities(
            "mv_insertImage("
            ."CKEDITOR.instances['".$InsertField."'],"
            ."'left',"
            ."'".$ImageUrl."',"
            ."'".$SafeAltText."',"
            ."false"
            .");"
        );
        $InsertRightCommand = defaulthtmlentities(
            "mv_insertImage("
            ."CKEDITOR.instances['".$InsertField."'],"
            ."'right',"
            ."'".$ImageUrl."',"
            ."'".$SafeAltText."',"
            ."false"
            .");"
        );
        $InsertLeftCaptionedCommand = defaulthtmlentities(
            "mv_insertImage("
            ."CKEDITOR.instances['".$InsertField."'],"
            ."'left',"
            ."'".$ImageUrl."',"
            ."'".$SafeAltText."',"
            ."true"
            .");"
        );
        $InsertRightCaptionedCommand = defaulthtmlentities(
            "mv_insertImage("
            ."CKEDITOR.instances['".$InsertField."'],"
            ."'right',"
            ."'".$ImageUrl."',"
            ."'".$SafeAltText."',"
            ."true"
            .");"
        );

        $RemoveCommand =  defaulthtmlentities(
            "mv_removeImage("
            ."CKEDITOR.instances['".$InsertField."'],"
            ."'".$ImageUrl."'"
            .");"
        );

        $ButtonOpenTag = '<button type="button" '
            .'class="btn btn-primary mv-form-insert-btn"';

        $InsertButtonHtml =
            $ButtonOpenTag.' onclick="'.$InsertLeftCommand.'" '
            .'title="Insert left-aligned image">Insert-L</button>'
            .$ButtonOpenTag.' onclick="'.$InsertRightCommand.'" '
            .'title="Insert right-aligned image">Insert-R</button>'
            .'<br/>'
            .$ButtonOpenTag.' onclick="'.$InsertLeftCaptionedCommand.'" '
            .'title="Insert left-aligned image with caption">Insert-L-C</button>'
            .$ButtonOpenTag.' onclick="'.$InsertRightCaptionedCommand.'" '
            .'title="Insert right-aligned image with caption">Insert-R-C</button>'
            .'<br/>'
            .$ButtonOpenTag.' onclick="'.$RemoveCommand.'" '
            .'title="Remove image inserts from text '
            .'(does not delete the image)">Remove</button>';

        return $InsertButtonHtml;
    }

    private $DialogCount;
    private $FirstWYSIWYGParagraphField = null;
    private static $FormIdCounter = 0;
}
