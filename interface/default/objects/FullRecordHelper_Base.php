<?PHP
#
#   FILE:  FullRecordHelper_Base.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2021-2022 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus;

use Exception;
use htmLawed;
use ScoutLib\Date;
use ScoutLib\StdLib;

/**
 * Class to provide helper methods for constructing a full record page.
 * This class should only include methods that retrieve and/or process
 * data for display on the full record page, and should NOT include code
 * that generates HTML or general-purpose methods that belong in Record
 * or another class.This is a singleton class, with access obtained
 * via a getInstance() method.The setRecord() static method must be
 * called before an instance is first retrieved, to set the record to be
 * displayed on the full record page.
 */
abstract class FullRecordHelper_Base
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Get universal instance of class.
     * @return static Class instance.
     */
    public static function getInstance()
    {
        if (!isset(self::$Record)) {
            throw new Exception("The record to be displayed on the full"
                    ." record page must be set via setRecord() before an"
                    ." instance of FullRecordHelper is retrieved.");
        }
        if (!isset(self::$Instance)) {
            self::$Instance = new static();
        }
        return self::$Instance;
    }

    /**
     * Set Record to be displayed for full record.
     */
    public static function setRecord(Record $Record)
    {
        # save record for our use
        self::$Record = $Record;
    }

    /**
     * Get standard metadata fields viewable by the current user.If a field
     * is not viewable, it will not be included in the return array.
     * @return array MetadataField objects, with standard field names for
     *      the index.
     */
    public function getStdFields(): array
    {
        return $this->StdMFields;
    }

    /**
     * Get values for the standard fields (Title, Description, Url, File,
     * Screenshot) viewable by the current user.If a field is not viewable,
     * it will not be included in the return array.
     * @return array Values, indexed by standard field name.
     */
    public function getStdFieldValues(): array
    {
        $Values = [];
        foreach (self::$StdFieldNames as $StdFieldName) {
            if (isset($this->StdMFields[$StdFieldName])) {
                $Values[$StdFieldName] = $this->getPreparedValueForField(
                    $this->StdMFields[$StdFieldName]
                );
            }
        }
        return $Values;
    }

    /**
     * Check whether a specified field is one of the standard fields.
     * @param MetadataField $Field Metadata field to check.
     * @return bool TRUE if field is one of the standard fields, otherwise FALSE.
     */
    public function isStdField(MetadataField $Field): bool
    {
        foreach ($this->StdMFields as $StdField) {
            if ($Field->id() == $StdField->id()) {
                return true;
            }
        }
        return false;
    }

    /**
     * Get metadata fields viewable by the current user.If a field
     * is not viewable, it will not be included in the return array.
     * @return array MetadataField objects, with field IDs for the index.
     */
    public function getFields(): array
    {
        return $this->MFields;
    }

    /**
     * Retrieve values for fields viewable by the current user, in display
     * order.If a field is not viewable, it will not be included in the
     * return array.Values are prepared according to the field type before
     * being returned, as follows:
     *      CONTROLLEDNAME/OPTION/TREE - Array of names, surrounded by
     *          tags (<a></a>) that link them to page for search results
     *          for records with those values, with ControlledName
     *          or Classification IDs for the index.
     *      DATE - Date value, formatted by StdLib::getPrettyDate()
     *          in verbose mode.
     *      EMAIL - Email address either obfuscated or surrounded by
     *          tags (<a></a>) with a "mailto:" href value, depending
     *          on the settings for the field.
     *      FILE - Array with file download links for the indexes and
     *          file names for the values.
     *      FLAG - "On" or "off" label for field, according to field value.
     *      NUMBER - Field value, as a string.
     *      POINT - A string with X and Y values, separated by a comma
     *          and a space.
     *      REFERENCE - Array with referred record IDs for the index,
     *          and referred record titles surrounded by tags (<a></a>)
     *          that link to the full record page for the record for
     *          the value.
     *      SEARCHPARAMETERSET - Text description of parameter set
     *          linked its search results, including HTML formatting.
     *      TEXT/PARAGRAPH - Text value, escaped for display (e.g.
     *          any entities escaped and newlines converted if field does
     *          not allow HTML).
     *      URL - Array with "GoTo" links for the indexes and URLs for
     *          the values.
     * @return array Prepared field values, with field IDs for index, or,
     *      where subgroups are used in the display order, a group name for
     *      the index, and an array of ID/value pairs for the value.
     */
    public function getFieldValues(): array
    {
        return $this->getDataForFields([$this, "getPreparedValueForField"]);
    }

    /**
     * Retrieve qualifiers for fields that have qualifiers and are
     * viewable by the current user.If a field does not have a qualifier
     * or is not viewable, it will not be included in the return array.
     * @return array Qualifier objects, with field IDs for the index, or
     *      FALSE for fields or field values that have no qualifier.
     *      Qualifiers (or FALSE entries where no qualifier) are returned
     *      in the same order and structure and with the same indexes as
     *      returned by getFieldValues().
     */
    public function getFieldQualifiers(): array
    {
        return $this->getDataForFields([$this, "getQualifierForField"]);
    }

    /**
     * Get URL for "Update" button for field.
     * @param MetadataField $Field Metadata field to generate link for.
     * @return string URL string.
     */
    public function getUpdateButtonLink(MetadataField $Field): string
    {
        return "index.php?P=UpdateTimestampFromButton"
                ."&amp;ID=".self::$Record->id()
                ."&amp;FI=".$Field->id();
    }

    /**
     * Get URL for record edit page.
     * @return string URL string.
     */
    public function getRecordEditLink(): string
    {
        return self::$Record->getEditPageUrl();
    }

    /**
     * Get URL for full image viewing page for image field value.
     * @param MetadataField $Field Metadata field to generate link for.
     * @param Image $Image Image object.
     * @return string URL string.
     */
    public function getImageViewLink(MetadataField $Field, Image $Image): string
    {
        return "index.php?P=FullImage"
                ."&amp;ID=".$Image->id()
                ."&amp;RI=".self::$Record->id()
                ."&amp;FI=".$Field->id();
    }

    /**
     * Get suffix for use in CSS class names to distinguish metadata field type.
     * @param MetadataField $Field Metadata field to generate suffix for.
     * @return string Suffix string.
     */
    public function getCssClassSuffixForField(MetadataField $Field): string
    {
        return strtolower(str_replace(" ", "", $Field->typeAsName()));
    }

    /**
     * Get details for buttons to be displayed in a way that applies to
     * the record itself.
     * @return array Details for any buttons, as an associative array with
     *      the indexes "Label", "Link", "Title", "IconName", "AdditionalCssClasses",
     *      and "Attributes".
     */
    public function getButtonsForPage(): array
    {
        $Buttons = [];
        foreach (self::$ButtonsForPage as $ButtonInfo) {
            $ButtonInfo["Link"] = str_replace(
                '$ID',
                self::$Record->id(),
                $ButtonInfo["Link"]
            );
            $Buttons[] = $ButtonInfo;
        }
        return $Buttons;
    }

    /**
     * Add button to full record page, to be displayed in a way that applies
     * to the record itself.In the $Link argument, the string "$ID" will be
     * replaced with the record ID number, when displaying the button.The button
     * will have an.mv-item-NN CSS class, where NN is the record ID.
     * @param string $Label Label for button.
     * @param string $Link Target URL for button.
     * @param string $Title Descriptive title for button.
     * @param string $IconName Base name of SVG file for icon.(OPTIONAL,
     *      but strongly recommended)
     * @param string $AdditionalCssClasses Additional CSS classes to include.
     *     (OPTIONAL)
     * @param array $Attributes Items to include in HTML attributes, keyed
     *     with the attribute name.(OPTIONAL)
     */
    public static function addButtonForPage(
        string $Label,
        string $Link,
        string $Title,
        string $IconName = null,
        string $AdditionalCssClasses = "",
        array $Attributes = []
    ) {
        self::$ButtonsForPage[] = [
            "Label" => $Label,
            "Link" => $Link,
            "Title" => $Title,
            "IconName" => $IconName,
            "AdditionalCssClasses" => trim(
                "mv-itemid-".self::$Record->id()." ".$AdditionalCssClasses
            ),
            "Attributes" => $Attributes,
        ];
    }


    # ---- PRIVATE INTERFACE -------------------------------------------------

    private $MFields;
    private $StdMFields;
    protected $User;

    private static $ButtonsForPage = [];
    private static $Instance;
    private static $Record;
    private static $StdFieldNames = [
        "Title",
        "Description",
        "Url",
        "File",
        "Screenshot",
    ];

    /**
     * Class constructor.
     */
    private function __construct()
    {
        # assume that full record page is for user currently logged in (if any)
        $this->User = User::getCurrentUser();

        # load metadata fields viewable by user
        $this->MFields = $this->getViewableMFields();
        $this->StdMFields = $this->getViewableStdMFields();
    }

    /**
     * Retrieve data for all metadata fields in display order using
     * specified function.
     * @param callable $DataRetrievalFunc Function to call to get data,
     *      with MetadataField as the parameter.
     * @return array Retrieved data, with field IDs for index, or,
     *      where subgroups are used in the display order, a group name for
     *      the index, and an array of ID/data pairs for the value.
     */
    private function getDataForFields(callable $DataRetrievalFunc): array
    {
        $DOItems = self::$Record->getSchema()->getDisplayOrder()->getItems();
        $Data = [];
        foreach ($DOItems as $DOItem) {
            if ($DOItem instanceof MetadataField) {
                $MFieldId = $DOItem->id();
                if (isset($this->MFields[$MFieldId])) {
                    $Data[$MFieldId] = $DataRetrievalFunc(
                        $this->MFields[$MFieldId]
                    );
                }
            } elseif ($DOItem instanceof MetadataFieldGroup) {
                $GroupItems = $DOItem->getItemIds();
                $GroupName = $DOItem->name();
                foreach ($GroupItems as $GroupItemInfo) {
                    $MFieldId = $GroupItemInfo["ID"];
                    if (isset($this->MFields[$MFieldId])) {
                        $Data[$GroupName][$MFieldId] = $DataRetrievalFunc(
                            $this->MFields[$MFieldId]
                        );
                    }
                }
            } else {
                throw new Exception("Item of illegal type encountered in display order.");
            }
        }
        return $Data;
    }

    /**
     * Get value for specified field, prepared for display based on
     * the field type.IMPORTANT:  If changes or additions are made
     * to value preparation in this method, the public documentation
     * for getFieldValues() must be updated accordingly.
     * @param MetadataField $Field Metadata field to retrieve value(s) for.
     * @return string|array Prepared value or values.
     * @see getFieldValues() for documentation on how values are prepared.
     */
    private function getPreparedValueForField(MetadataField $Field)
    {
        $RawValue = self::$Record->getForDisplay($Field);
        $Value = [];
        $EscapeValue = true;
        switch ($Field->type()) {
            case MetadataSchema::MDFTYPE_CONTROLLEDNAME:
            case MetadataSchema::MDFTYPE_OPTION:
                foreach ($RawValue as $CName) {
                    $Value[$CName->id()] = $this->getSearchLinkedVersionOfValue(
                        $CName->name(),
                        $Field
                    );
                }
                $EscapeValue = false;
                break;

            case MetadataSchema::MDFTYPE_DATE:
                if ($RawValue instanceof Date) {
                    $PrecisionForBasicDates = Date::PRE_BEGINYEAR
                            | Date::PRE_BEGINMONTH
                            | Date::PRE_BEGINDAY;
                    if ($RawValue->precision() == $PrecisionForBasicDates) {
                        $Value = StdLib::getPrettyDate(
                            $RawValue->formatted(),
                            true,
                            ""
                        );
                    } else {
                        $Value = $RawValue->formatted();
                    }
                } else {
                    $Value = StdLib::getPrettyDate($RawValue, true, "");
                }
                break;

            case MetadataSchema::MDFTYPE_EMAIL:
                if (!$this->User->isLoggedIn() && $Field->obfuscateValueForAnonymousUsers()) {
                    $Value = StdLib::obfuscateEmailAddress($RawValue);
                } else {
                    $Value = is_null($RawValue) ? ""
                            : "<a href='mailto:".htmlspecialchars($RawValue)
                                    ."'>".htmlspecialchars($RawValue)."</a>";
                    $EscapeValue = false;
                }
                break;

            case MetadataSchema::MDFTYPE_FILE:
                foreach ($RawValue as $File) {
                    $Value[$File->getLink()] = $File->name();
                }
                break;

            case MetadataSchema::MDFTYPE_FLAG:
                $Value = $RawValue ? $Field->flagOnLabel() : $Field->flagOffLabel();
                break;

            case MetadataSchema::MDFTYPE_IMAGE:
                $Value = $RawValue;
                break;

            case MetadataSchema::MDFTYPE_NUMBER:
                $Value = (string)$RawValue;
                break;

            case MetadataSchema::MDFTYPE_POINT:
                $Value = (isset($RawValue["X"])
                        && is_numeric($RawValue["X"])
                        && isset($RawValue["Y"])
                        && is_numeric($RawValue["Y"]))
                        ? $RawValue["X"].", ".$RawValue["Y"]
                        : "";
                break;

            case MetadataSchema::MDFTYPE_REFERENCE:
                $Value = $this->prepareReferenceValue($RawValue);
                $EscapeValue = false;
                break;

            case MetadataSchema::MDFTYPE_SEARCHPARAMETERSET:
                $Value = $this->getLinkedSearchDescription($RawValue);
                $EscapeValue = false;
                break;

            case MetadataSchema::MDFTYPE_TEXT:
            case MetadataSchema::MDFTYPE_PARAGRAPH:
                $Value = $RawValue;
                if ($Field->allowHtml()) {
                    # (strip cross-site scripting threats and "style" attributes)
                    if (!is_null($Value)) {
                        $Value = htmLawed::hl(
                            $Value,
                            ["safe" => 1, "deny_attribute" => "style"]
                        );
                    }
                } else {
                    $Value = !is_null($Value) ? htmlspecialchars($Value) : "";
                    $Value = nl2br($Value);
                }
                $EscapeValue = false;
                break;

            case MetadataSchema::MDFTYPE_TIMESTAMP:
                $Value = StdLib::getPrettyTimestamp($RawValue, true, "");
                break;

            case MetadataSchema::MDFTYPE_TREE:
                foreach ($RawValue as $Classif) {
                    $Value[$Classif->id()] = $this->getSearchLinkedVersionOfValue(
                        $Classif->fullName(),
                        $Field
                    );
                }
                $EscapeValue = false;
                break;

            case MetadataSchema::MDFTYPE_URL:
                if (strlen((string)$RawValue) > 0) {
                    $GoToLink = "index.php?P=GoTo&ID=".self::$Record->id()
                        ."&MF=".$Field->id();
                    $Value[$GoToLink] = $RawValue;
                }
                break;

            case MetadataSchema::MDFTYPE_USER:
                foreach ($RawValue as $CName) {
                    $Value[$CName->id()] = $CName->name();
                }
                break;

            case MetadataSchema::MDFTYPE_IMAGE:
            default:
                $Value = $RawValue;
                break;
        }

        # escape any HTML entities in value (unless previously suppressed)
        if ($EscapeValue) {
            $Value = $this->escapePreparedValue($Value);
        }

        return $Value;
    }

    /**
     * Prepare Reference field value(s) for display.IMPORTANT:  If changes
     * or additions are made to value preparation in this method, the public
     * documentation for getFieldValues() must be updated accordingly.
     * @param array $RawValue Value(s) to be prepared.
     * @return array Prepared value(s).
     * @see getFieldValues() for documentation on how values are prepared.
     */
    private function prepareReferenceValue(array $RawValue): array
    {
        $Value = [];
        foreach ($RawValue as $RefRecordId => $RefRecord) {
            if (!$RefRecord->userCanView($this->User)) {
                continue;
            }
            $Link = $RefRecord->getViewPageUrl();
            $Value[$RefRecordId] = "<a href=\"".$Link."\">"
                    .htmlspecialchars($RefRecord->getMapped("Title"))."</a>";
        }
        return $Value;
    }

    /**
     * Get version of string value surrounded by <a> tag linking it to search
     * results for value.
     * @param string $Value Value to link.
     * @param MetadataField $Field Field from which value comes.
     * @return string Value with search results link tag added.
     */
    private function getSearchLinkedVersionOfValue(
        string $Value,
        MetadataField $Field
    ): string {
        $SearchParams = new SearchParameterSet();
        $SearchParams->addParameter("=".$Value, $Field);
        $Link = "index.php?P=SearchResults&".$SearchParams->UrlParameterString();
        $Title = "Search for records where ".$Field->getDisplayName()
                ." is also \"".htmlspecialchars($Value)."\"";
        return "<a href=\"".$Link."\" title=\"".htmlspecialchars($Title)."\">"
                .htmlspecialchars($Value)."</a>";
    }

    /**
     * Get search description of provided SearchParameterSet with link tag to search results.
     * @param SearchParameterSet $Set Search parameter set to use.
     * @return string Search description surrounded by link tag to search results.
     */
    private function getLinkedSearchDescription(
        SearchParameterSet $Set
    ): string {
        $Link = "index.php?P=SearchResults&".$Set->urlParameterString();
        $Title = "Search for records where ".$Set->textDescription(false);
        return "<a href=\"".$Link."\" title=\"".htmlspecialchars($Title)."\">"
                .$Set->textDescription()."</a>";
    }

    /**
     * Escape any HTML entities in prepared values.
     * @param string|array $PreparedValue Value(s) to escape.
     * return string|array Escaped value(s).
     */
    private function escapePreparedValue($PreparedValue)
    {
        if (is_array($PreparedValue)) {
            foreach ($PreparedValue as $Index => $Value) {
                if (is_string($Value)) {
                    $PreparedValue[$Index] = htmlspecialchars($Value);
                }
            }
        } elseif (is_string($PreparedValue)) {
            $PreparedValue = htmlspecialchars($PreparedValue);
        }
        return $PreparedValue;
    }

    /**
     * Retrieve qualifiers for values for specified field.
     * @param MetadataField $MField Metadata field to retrieve qualifier(s) for.
     * @return Qualifier|false|array Qualifier or qualifiers, or FALSE for fields
     *      or field values that have no qualifiers.
     */
    private function getQualifierForField(MetadataField $MField)
    {
        $Values = $this->getPreparedValueForField($MField);

        # if field does not support qualifiers or they are not to be displayed
        if (!$MField->usesQualifiers() || !$MField->showQualifiers()) {
            # if field supports multiple values
            if (is_array($Values)) {
                # return array with FALSE for each value
                $Qualifiers = [];
                foreach ($Values as $ValueIndex => $Value) {
                    $Qualifiers[$ValueIndex] = false;
                }
                return $Qualifiers;
            } else {
                return false;
            }
        }

        # if field supports multiple values
        $DefaultQualifierId = $MField->defaultQualifier();
        $DefaultQualifier = (($DefaultQualifierId !== false)
                        && Qualifier::itemExists($DefaultQualifierId))
                ? new Qualifier($DefaultQualifierId)
                : false;
        if (is_array($Values)) {
            $Qualifiers = [];
            if ($MField->hasItemLevelQualifiers()) {
                foreach ($Values as $ValueIndex => $Value) {
                    switch ($MField->type()) {
                        case MetadataSchema::MDFTYPE_CONTROLLEDNAME:
                        case MetadataSchema::MDFTYPE_OPTION:
                            $ValueObj = new ControlledName($ValueIndex);
                            break;

                        case MetadataSchema::MDFTYPE_TREE:
                            $ValueObj = new Classification($ValueIndex);
                            break;

                        default:
                            throw new Exception("Item-level qualifiers"
                                    ." encountered on unexpected metadata field"
                                    ." type (".$MField->typeAsName().").");
                    }
                    $Qualifiers[$ValueIndex] = $ValueObj->qualifier();
                }
            } else {
                # return array with default qualifier for each value
                foreach ($Values as $ValueIndex => $Value) {
                    $Qualifiers[$ValueIndex] = $DefaultQualifier;
                }
            }
            return $Qualifiers;
        } else {
            if ($MField->hasItemLevelQualifiers()) {
                throw new Exception("Item-level qualifiers encountered on"
                        ." unexpected metadata field type (".$MField->typeAsName().").");
            } else {
                # return default qualifier for field
                return $DefaultQualifier;
            }
        }
    }

    /**
     * Load metadata fields viewable by user.
     * @return array Metadata fields, indexed by field ID.
     */
    private function getViewableMFields(): array
    {
        $Schema = self::$Record->getSchema();
        $Fields = $Schema->getFields(null, MetadataSchema::MDFORDER_DISPLAY);
        foreach ($Fields as $FieldId => $Field) {
            if (!self::$Record->userCanViewField($this->User, $Field)) {
                unset($Fields[$FieldId]);
            }
        }
        return $Fields;
    }

    /**
     * Load standard (mapped) metadata fields viewable by user.
     * @return array Standard metadata fields, indexed by standard field name.
     */
    private function getViewableStdMFields(): array
    {
        $Schema = self::$Record->getSchema();
        $Fields = [];
        foreach (self::$StdFieldNames as $FieldName) {
            $Field = $Schema->getFieldByMappedName($FieldName);
            if (($Field !== null) && self::$Record->userCanViewField($this->User, $Field)) {
                $Fields[$FieldName] = $Field;
            }
        }
        return $Fields;
    }
}
