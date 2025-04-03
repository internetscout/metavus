<?PHP
#
#   FILE:  ItemFactory.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2013-2021 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus;

use ScoutLib\ApplicationFramework;
use ScoutLib\StdLib;

/**
 * Given a metadata field, this class returns human-readable values for each
 * value of the field.
 */
class HumanMetadataField
{

    /**
     * Save the field that will be used to generate the human-readable values.
     * @param MetadataField $Field Metadata field.
     */
    public function __construct(MetadataField $Field)
    {
        $this->Field = $Field;
    }

    /**
     * Get the human-readable error status of the field.
     * @return string Human-readable error status
     */
    public function status(): string
    {
        switch ($this->Field->status()) {
            case MetadataSchema::MDFSTAT_OK:
                return "OK";
            case MetadataSchema::MDFSTAT_ERROR:
                return "Error";
            case MetadataSchema::MDFSTAT_DUPLICATENAME:
                return "Duplicate field name";
            case MetadataSchema::MDFSTAT_DUPLICATEDBCOLUMN:
                return "Duplicate database column";
            case MetadataSchema::MDFSTAT_FIELDDOESNOTEXIST:
                return "Field does not exist";
            case MetadataSchema::MDFSTAT_ILLEGALNAME:
                return "Illegal field name";
            case MetadataSchema::MDFSTAT_DUPLICATELABEL:
                return "Duplicate label name";
            case MetadataSchema::MDFSTAT_ILLEGALLABEL:
                return "Illegal label name";
        }

        return $this->NotSetText;
    }

    /**
     * Get the human-readable field type of the field.
     * @return string Human-readable field type
     */
    public function type(): string
    {
        return MetadataField::$FieldTypeDBEnums[$this->Field->type()];
    }

    /**
     * Get the human-readable field type of the field.
     * @return string Human-readable field type
     */
    public function typeAsName(): string
    {
        return $this->Field->typeAsName();
    }

    /**
     * Get the human-readable display name of the field.
     * @return string|null Human-readable display name of the field
     */
    public function getDisplayName()
    {
        return $this->Field->getDisplayName();
    }

    /**
     * Get the human-readable name of the field.
     * @return string Human-readable field name
     */
    public function name(): string
    {
        return $this->Field->name();
    }

    /**
     * Get the human-readable label of the field.
     * @return string Human-readable field label
     */
    public function label(): string
    {
        return $this->getValueCheckingLength($this->Field->label());
    }

    /**
     * Get the human-readable allowed conversion types of the field.
     * @return string Human-readable allowed conversion types of the field
     */
    public function getAllowedConversionTypes(): string
    {
        $Value = $this->Field->getAllowedConversionTypes();

        return count($Value) ? implode(", ", $Value) : $this->NotSetText;
    }

    /**
     * Get the human-readable string that indicates if the field is a temporary
     * field.
     * @return string Human-readable string indicating if the field is temporary
     */
    public function isTempItem(): string
    {
        return $this->getYesNo($this->Field->isTempItem());
    }

    /**
     * Get the human-readable field ID.
     * @return int Human-readable field ID
     */
    public function id(): int
    {
        return $this->Field->id();
    }

    /**
     * Get the human-readable database field name of the field.
     * @return string Human-readable database field name
     */
    public function dbFieldName(): string
    {
        return $this->Field->dBFieldName();
    }

    /**
     * Get the human-readable description of the field.
     * @return string Human-readable field description
     */
    public function description(): string
    {
        # for our purposes, HTML code and some whitespace are not human-readable
        $Value = strip_tags($this->Field->description());
        $Value = trim(str_replace(["\r", "\n", "\t"], " ", $Value));
        $Value = preg_replace('/ +/', " ", $Value);

        return $this->getValueCheckingLength($Value);
    }

    /**
     * Get the human-readable instructions of the field.
     * @return string Human-readable field instructions
     */
    public function instructions(): string
    {
        # for our purposes, HTML code and some whitespace are not human-readable
        $Value = strip_tags($this->Field->instructions());
        $Value = trim(str_replace(["\r", "\n", "\t"], " ", $Value));
        $Value = preg_replace('/ +/', " ", $Value);

        return $this->getValueCheckingLength($Value);
    }

    /**
     * Get the human-readable field owner.
     * @return string Human-readable field owner
     */
    public function owner(): string
    {
        return $this->getValueCheckingLength($this->Field->owner());
    }

    /**
     * Get the human-readable string that indicates if the field is enabled.
     * @return string Human-readable string indicating if the field is enabled
     */
    public function enabled(): string
    {
        return $this->getYesNo($this->Field->enabled());
    }

    /**
     * Get the human-readable string that indicates if the field is optional.
     * @return string Human-readable string indicating if the field is optional
     */
    public function optional(): string
    {
        return $this->getYesNo($this->Field->optional());
    }

    /**
     * Get the human-readable string that indicates if the field is editable.
     * @return string Human-readable string indicating if the field is editable
     */
    public function editable(): string
    {
        return $this->getYesNo($this->Field->editable());
    }

    /**
     * Get the human-readable string that indicates if multiple field values are
     * permitted.
     * @return string Human-readable string indicating if multiple field values
     *   are permitted
     */
    public function allowMultiple(): string
    {
        return $this->getYesNo($this->Field->allowMultiple());
    }

    /**
     * Get the human-readable string that indicates if the field is included in
     * keyword searches.
     * @return string Human-readable string indicating if the field is included
     *   in keyword searches
     */
    public function includeInKeywordSearch(): string
    {
        return $this->getYesNo($this->Field->includeInKeywordSearch());
    }

    /**
     * Get the human-readable string that indicates if the field is included in
     * advanced search options
     * @return string Human-readable string indicating if the field is included
     *   in advanced search options
     */
    public function includeInAdvancedSearch(): string
    {
        return $this->getYesNo($this->Field->includeInAdvancedSearch());
    }

    /**
     * Get the human-readable string that indicates if the field is included in
     * faceted search options.
     * @return string Returns the human-readable string indicating if the field is
     *      included in faceted search options.
     */
    public function includeInFacetedSearch(): string
    {
        return $this->getYesNo($this->Field->includeInFacetedSearch());
    }

    /**
     * Get the human-readable string that indicates if the field is included in
     * sort options.
     * @return string Human-readable string indicating if the field is included
     *   in sort options
     */
    public function includeInSortOptions(): string
    {
        return $this->getYesNo($this->Field->includeInSortOptions());
    }

    /**
     * Get the human-readable string that indicates if the field is included in
     * the recommender system.
     * @return string Human-readable string indicating if the field is included
     *   in the recommender system
     */
    public function includeInRecommender(): string
    {
        return $this->getYesNo($this->Field->includeInRecommender());
    }

    /**
     * Get the human-readable size of text field inputs.
     * @return string Human-readable size of text field inputs
     */
    public function textFieldSize(): string
    {
        return $this->getValueCheckingLength($this->Field->textFieldSize());
    }

    /**
     * Get the human-readable maximum size of text field values.
     * @return string Human-readable maximum size of text field values
     */
    public function maxLength(): string
    {
        return $this->getValueCheckingLength($this->Field->maxLength());
    }

    /**
     * Get the human-readable number of rows of paragraph field inputs.
     * @return string Human-readable number of rows of paragraph field inputs
     */
    public function paragraphRows(): string
    {
        return $this->getValueCheckingLength($this->Field->paragraphRows());
    }

    /**
     * Get the human-readable number of columns of paragraph field inputs.
     * @return string Human-readable number of columns of paragraph field inputs
     */
    public function paragraphCols(): string
    {
        return $this->getValueCheckingLength($this->Field->paragraphCols());
    }

    /**
     * Get the human-readable minimum value for number fields.
     * @return string Human-readable minimum value for number fields
     */
    public function minValue(): string
    {
        return $this->getValueCheckingLength($this->Field->minValue());
    }

    /**
     * Get the human-readable maximum value for number fields.
     * @return string Human-readable maximum value for number fields
     */
    public function maxValue(): string
    {
        return $this->getValueCheckingLength($this->Field->maxValue());
    }

    /**
     * Get the human-readable flag-on label for flag fields.
     * @return string Human-readable flag-on label for flag fields
     */
    public function flagOnLabel(): string
    {
        return $this->getValueCheckingLength($this->Field->flagOnLabel());
    }

    /**
     * Get the human-readable flag-off label for flag fields.
     * @return string Human-readable flag-off label for flag fields
     */
    public function flagOffLabel(): string
    {
        return $this->getValueCheckingLength($this->Field->flagOffLabel());
    }

    /**
     * Get the human-readable field date format.
     * @return string Human-readable field date format
     */
    public function dateFormat(): string
    {
        return $this->getValueCheckingLength($this->Field->dateFormat());
    }

    /**
     * Get the human-readable search weight of the field.
     * @return string Human-readable field search weight
     */
    public function searchWeight(): string
    {
        return $this->getValueCheckingLength($this->Field->searchWeight());
    }

    /**
     * Get the human-readable recommender weight of the field.
     * @return string Human-readable field recommender weight
     */
    public function recommenderWeight(): string
    {
        return $this->getValueCheckingLength($this->Field->recommenderWeight());
    }

    /**
     * Get the human-readable string indicating if the field uses qualifiers.
     * @return string Human-readable string indicating if the field uses
     *       qualifiers.
     */
    public function usesQualifiers(): string
    {
        return $this->getYesNo($this->Field->usesQualifiers());
    }

    /**
     * Get the human-readable string indicating if qualifiers are shown for the
     * field.
     * @return string Human-readable string indicating if qualifiers are shown
     *       for the field.
     */
    public function showQualifiers(): string
    {
        return $this->getYesNo($this->Field->showQualifiers());
    }

    /**
     * Get the human-readable default qualifier of the field.
     * @return string Human-readable default qualifier of the field.
     */
    public function defaultQualifier(): string
    {
        $DefaultQualifier = $this->Field->defaultQualifier();

        if ($DefaultQualifier > 0) {
            $Qualifier = new Qualifier($DefaultQualifier);

            return $Qualifier->name();
        }

        return $this->NotSetText;
    }

    /**
     * Get the human-readable string indicating if HTML is allowed as the value.
     * @return string Human-readable string indicating if HTML is allowed as the
     *       value.
     */
    public function allowHTML(): string
    {
        return $this->getYesNo($this->Field->allowHTML());
    }

    /**
     * Get the human-readable string indicating if a WYSIWYG editor should be
     * used when editing the field value.
     * @return string Human-readable string indicating if a WYSIWYG editor
     *       should be used when editing the field value.
     */
    public function useWysiwygEditor(): string
    {
        return $this->getYesNo($this->Field->useWysiwygEditor());
    }

    /**
     * Get the human-readable string indicating if the field should be used for
     * OAI sets.
     * @return string Human-readable string indicating if the field should be
     *   used for OAI sets.
     */
    public function useForOaiSets(): string
    {
        return $this->getYesNo($this->Field->useForOaiSets());
    }

    /**
     * Get the human-readable number of AJAX search results to display for the
     * field.
     * @return int Human-readable number of AJAX search results to display.
     */
    public function numAjaxResults(): int
    {
        return $this->Field->numAjaxResults();
    }

    /**
     * Get the human-readable string indicating if the field should be enabled
     * when the owner/plugin is available.
     * @return string Human-readable string indicating if the field should be
     *       enabled when the owner returns.
     */
    public function enableOnOwnerReturn(): string
    {
        return $this->getYesNo($this->Field->enableOnOwnerReturn());
    }

    /**
     * Get the human-readable user privilege restrictions of user fields.
     * @return string Human-readable user privilege restrictions of user fields.
     */
    public function userPrivilegeRestrictions(): string
    {
        $Value = $this->Field->userPrivilegeRestrictions();
        $Values = [];

        # need to map each privilege ID to its text
        foreach ($Value as $Id) {
            $Values[] = $this->mapPrivilege($Id);
        }

        return count($Values) ? implode(", ", $Values) : $this->NotSetText;
    }

    /**
     * Get the human-readable point precision of point fields.
     * @return string Human-readable point precision of point fields.
     */
    public function pointPrecision(): string
    {
        return $this->getValueCheckingLength($this->Field->pointPrecision());
    }

    /**
     * Get the human-readable point decimal digits of point fields.
     * @return string Human-readable point decimal digits of point fields.
     */
    public function pointDecimalDigits(): string
    {
        return $this->getValueCheckingLength($this->Field->pointDecimalDigits());
    }

    /**
     * Get the human-readable default value of the field.
     * @return string Human-readable default value of the field.
     */
    public function defaultValue(): string
    {
        $Type = $this->Field->type();
        $Value = $this->Field->defaultValue();

        if ($Type == MetadataSchema::MDFTYPE_POINT) {
            $XText = null;
            $X = $Value["X"];
            $Y = $Value["Y"];

            if (!is_null($X) && strlen($X)) {
                $XText = "X: " . $X;
            }

            if (!is_null($Y) && strlen($Y)) {
                return (strlen($XText) ? $XText . ", " : "") . "Y: " . $Y;
            }

            return $this->NotSetText;
        }

        if ($Type == MetadataSchema::MDFTYPE_OPTION) {
            # multiple default values are set
            if (is_array($Value)) {
                $Names = [];
                foreach ($Value as $Id) {
                    $ControlledName = new ControlledName($Id);

                    $Names[] = $ControlledName->name();
                }

                return implode(", ", $Names);
            # else if only one default value
            } elseif ($Value) {
                $ControlledName = new ControlledName($Value);

                return $ControlledName->name();
            }

            return $this->NotSetText;
        }

        if ($Type == MetadataSchema::MDFTYPE_FLAG) {
            return $Value ? $this->flagOnLabel() : $this->flagOffLabel();
        }

        return $this->getValueCheckingLength($Value);
    }

    /**
     * Get the human-readable update method of the field.
     * @return string Human-readable update method of the field
     */
    public function updateMethod(): string
    {
        $Value = $this->Field->updateMethod();
        $String = StdLib::getArrayValue(MetadataField::$UpdateTypes, $Value);

        return $this->getValueCheckingLength($String);
    }

    /**
     * Get the human-readable possible values of the field. This is only
     * meaningful for Tree, ControlledName, Option, Flag, and User fields.
     * @return string Human-readable possible values of the field
     */
    public function getPossibleValues(): string
    {
        $Value = $this->Field->getPossibleValues();

        return count($Value) ? implode(", ", $Value) : $this->NotSetText;
    }

    /**
     * Get the human-readable count of possible values of the field. This is
     * only meaningful for Tree, ControlledName, Option, Flag, and User fields.
     * @return string Human-readable count of possible values of the field
     */
    public function getCountOfPossibleValues(): string
    {
        return $this->getValueCheckingLength($this->Field->getCountOfPossibleValues());
    }

    /**
     * Get the human-readable string that indicates if the field has item-level
     * qualifiers.
     * @return string Human-readable string indicating if the field has
     *   item-level qualifiers
     */
    public function hasItemLevelQualifiers(): string
    {
        return $this->getYesNo($this->Field->hasItemLevelQualifiers());
    }

    /**
     * Get the human-readable list of associated qualifiers of the field.
     * @return string Human-readable list of associated field qualifiers
     */
    public function associatedQualifierList(): string
    {
        $Value = $this->Field->associatedQualifierList();

        return count($Value) ? implode(", ", $Value) : $this->NotSetText;
    }

    /**
     * Get the human-readable list of unassociated qualifiers of the field.
     * @return string Human-readable list of unassociated field qualifiers
     */
    public function unassociatedQualifierList(): string
    {
        $Value = $this->Field->unassociatedQualifierList();

        return count($Value) ? implode(", ", $Value) : $this->NotSetText;
    }

    /**
     * Get the text that is used when a value is not set.
     * @return string text used when a value is not set
     */
    public function getNotSetText(): string
    {
        return $this->NotSetText;
    }

    /**
     * Set the text that is used when a value is not set.
     * @param string $Text Text to be used when a value is not set.
     * @return void
     */
    public function setNotSetText($Text): void
    {
        $this->NotSetText = $Text;
    }

    /**
     * Get the human-readable string for a boolean-like value.
     * @param mixed $Value Boolean-like value.
     * @return string the human-readable string for the value
     */
    protected function getYesNo($Value): string
    {
        return $Value ? "Yes" : "No";
    }

    /**
     * Get the value or the not-set text depending on the length of the value.
     * @param mixed $Value Value to check.
     * @return string the value if the string length is greater than zero or the
     *   not-set text if it is zero
     */
    protected function getValueCheckingLength($Value): string
    {
        return !empty($Value) ? $Value : $this->NotSetText;
    }

    /**
     * Map a privilege value to a privilege name.
     * @param string|int $Value Privilege value.
     * @return string|null Privilege name.
     */
    protected function mapPrivilege($Value)
    {
        if (!isset(self::$PrivilegeList)) {
            $this->loadPrivilegeList();
        }

        return StdLib::getArrayValue(self::$PrivilegeList, $Value);
    }

    /**
     * Load the static privilege list.
     * @return void
     */
    protected function loadPrivilegeList(): void
    {
        $PrivilegeFactory = new PrivilegeFactory();

        self::$PrivilegeList = $PrivilegeFactory->getPrivileges(true, false);
    }

    /**
     * Map a UserIsValue value to a name.
     * @param int $Value UserIsValue value
     * @return string|null UserIsValue name
     */
    protected function mapUserIsValue($Value)
    {
        return StdLib::getArrayValue(self::$UserIsValueList, $Value);
    }

    /**
     * Map a UserValue value to a field display name.
     * @param int $Value UserValue value
     * @return string|null user field display name
     */
    protected function mapUserValue(int $Value)
    {
        if (!isset(self::$UserFieldList)) {
            $this->loadUserFieldList();
        }

        return StdLib::getArrayValue(self::$UserFieldList, $Value);
    }

    /**
     * Load the static user field list.
     * @return void
     */
    protected function loadUserFieldList(): void
    {
        $Schema = new MetadataSchema($this->Field->schemaId());
        $UserFields = $Schema->getFields(MetadataSchema::MDFTYPE_USER);

        # make sure the list is set to something even if there are no user
        # fields
        self::$UserFieldList = [];

        foreach ($UserFields as $Field) {
            self::$UserFieldList[$Field->id()] = $Field->getDisplayName();
        }
    }

    /**
     * The metadata field that is having its values returned in human-readable
     * form.
     * @var MetadataField $Field
     */
    protected $Field;

    /**
     * The text used when a value is not set.
     * @var string $NotSetText
     */
    protected $NotSetText = "--";

    /**
     * A static array of privilege values and names.
     * @var ?array $PrivilegeList
     */
    protected static $PrivilegeList;

    /**
     * A static array of UserIsValue strings.
     * @var array $UserIsValueList
     */
    protected static $UserIsValueList = [
        MetadataField::USERISVALUE_OR => "or",
        MetadataField::USERISVALUE_AND => "and"
    ];

    /**
     * A static array of user fields.
     * @var ?array $UserFieldList
     */
    protected static $UserFieldList;
}
