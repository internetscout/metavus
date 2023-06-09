<?PHP
#
#   FILE:  MetadataField.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2012-2022 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus;

use Exception;
use InvalidArgumentException;
use ScoutLib\ApplicationFramework;
use ScoutLib\Database;
use ScoutLib\Date;
use ScoutLib\ItemFactory;
use ScoutLib\ObserverSupportTrait;
use ScoutLib\SearchEngine;
use ScoutLib\StdLib;
use XMLWriter;

/**
* Object representing a locally-defined type of metadata field.
*/
class MetadataField
{
    use ObserverSupportTrait;

    # ---- PUBLIC INTERFACE --------------------------------------------------

    # update methods for timestamp fields
    const UPDATEMETHOD_NOAUTOUPDATE   = "NoAutoUpdate";
    const UPDATEMETHOD_ONRECORDCREATE = "OnRecordCreate";
    const UPDATEMETHOD_BUTTON         = "Button";
    const UPDATEMETHOD_ONRECORDEDIT   = "OnRecordEdit";
    const UPDATEMETHOD_ONRECORDCHANGE = "OnRecordChange";
    const UPDATEMETHOD_ONRECORDRELEASE = "OnRecordRelease";

    # values for the *UserIsValue fields
    const USERISVALUE_OR = -1;
    const USERISVALUE_UNSET = 0;
    const USERISVALUE_AND = 1;

    # events that can be monitored via registerObserver()
    # (TO DO: push down into ObserverSupportTrait once our minimum
    #   supported PHP version allows constants in traits)
    const EVENT_SET = 1;
    const EVENT_CLEAR = 2;
    const EVENT_ADD = 4;
    const EVENT_REMOVE = 8;

    /**
     * Get current error status of object.
     * @return int Error status value drawn from MDFSTAT constants defined
     *       in the MetadataSchema class.
     */
    public function status()
    {
        return $this->ErrorStatus;
    }

    /**
     * Get/set type of metadata field (enumerated value).  Types are MDFTYPE_
     * constants defined in the MetadataSchema class.
     * @param int $NewValue New type for field.  (OPTIONAL)
     * @return int Current type for field.
     */
    public function type($NewValue = null): int
    {
        # if new value supplied
        $FTFieldName = $this->DB->updateValue("FieldType");
        if (($NewValue !== null) && ($NewValue != self::$FieldTypePHPEnums[$FTFieldName])) {
            # update database fields and store new type
            $this->modifyField(null, $NewValue);

            # update field attributes for new type
            $this->setFieldAttributes();
        }

        # return type to caller
        return self::$FieldTypePHPEnums[$FTFieldName];
    }

    /**
     * Get type of field as string.
     * @return string Field type.
     */
    public function typeAsName(): string
    {
        return $this->DB->updateValue("FieldType");
    }

    /**
     * Check whether field is a type that uses controlled vocabularies.
     * @return bool TRUE if field type is based on controlled vocabularies,
     *       otherwise FALSE.
     */
    public function isControlledVocabularyField(): bool
    {
        switch ($this->type()) {
            case MetadataSchema::MDFTYPE_CONTROLLEDNAME:
            case MetadataSchema::MDFTYPE_OPTION:
            case MetadataSchema::MDFTYPE_TREE:
                return true;

            default:
                return false;
        }
    }

    /**
     * Get ID of schema for field.
     * @return int Schema ID.
     */
    public function schemaId(): int
    {
        return $this->DB->updateValue("SchemaId");
    }

    /**
     * Get display name for field.  Returns label if available, or field name
     * if label is not set for field.
     * @return string Display name.
     */
    public function getDisplayName()
    {
        if (!is_null($this->label()) && strlen($this->label())) {
            $DisplayName = $this->label();
        } else {
            $DisplayName = $this->name();
        }
        return $DisplayName;
    }

    /**
     * Get/set name of field.  Field names are limited to alphanumerics, spaces,
     * and parentheses.
     * @param string $NewName New field name.  (OPTIONAL)
     * @return string Current field name.
     */
    public function name(string $NewName = null): string
    {
        $DB = $this->DB;
        # if new name specified
        if (($NewName !== null) && (trim($NewName) != $DB->updateValue("FieldName"))) {
            $NewName = trim($NewName);
            $NormalizedName = $this->normalizeFieldNameForDB(strtolower($NewName));

            # if field name is invalid
            if (!preg_match("/^[[:alnum:] \(\)]+$/", $NewName)) {
                # set error status to indicate illegal name
                $this->ErrorStatus = MetadataSchema::MDFSTAT_ILLEGALNAME;
            # if the new name is a reserved word
            } elseif ($NormalizedName == "resourceid" || $NormalizedName == "schemaid") {
                # set error status to indicate illegal name
                $this->ErrorStatus = MetadataSchema::MDFSTAT_ILLEGALNAME;
            # the name is okay but might be a duplicate
            } else {
                # check for duplicate name
                $DuplicateCount = $this->DB->queryValue(
                    "SELECT COUNT(*) AS RecordCount FROM MetadataFields"
                            ." WHERE FieldName = '" .addslashes($NewName) ."'"
                            ." AND SchemaId = " .intval($DB->updateValue("SchemaId")),
                    "RecordCount"
                );

                # if field name is duplicate
                if ($DuplicateCount > 0) {
                    # set error status to indicate duplicate name
                    $this->ErrorStatus = MetadataSchema::MDFSTAT_DUPLICATENAME;
                } else {
                    # modify database declaration to reflect new field name
                    $this->ErrorStatus = MetadataSchema::MDFSTAT_OK;
                    $this->modifyField($NewName);
                }
            }
        }

        # return value to caller
        return $DB->updateValue("FieldName");
    }

    /**
     * Get/set label for field.
     * @param string $NewLabel New label for field.  (OPTIONAL)
     * @return string|null Current label for field or NULL if no label.
     */
    public function label(string $NewLabel = null)
    {
        $ValidValueExp = '/^[[:alnum:] ]*$/';
        $Value = $this->DB->updateValue("Label");

        # if a new label was specified
        if (($NewLabel !== null) && (trim($NewLabel) != $Value)) {
            $NewLabel = trim($NewLabel);
            if (strlen($NewLabel) == 0) {
                $this->DB->updateValue("Label", false);
            } elseif (preg_match($ValidValueExp, $NewLabel)) {
            # if field label is valid
                $this->DB->updateValue("Label", $NewLabel);
                $Value = $NewLabel;
            # the field label is invalid
            } else {
                $this->ErrorStatus = MetadataSchema::MDFSTAT_ILLEGALLABEL;
            }
        }

        return $Value;
    }

    /**
     * Get metadata field types that this field can be converted to.
     * @return array Array with constants (MDFTYPE_ values) for the index and
     *       field type strings for the values.
     */
    public function getAllowedConversionTypes(): array
    {
        # determine type list based on our type
        switch ($this->type()) {
            case MetadataSchema::MDFTYPE_TEXT:
            case MetadataSchema::MDFTYPE_PARAGRAPH:
            case MetadataSchema::MDFTYPE_NUMBER:
            case MetadataSchema::MDFTYPE_FLAG:
            case MetadataSchema::MDFTYPE_URL:
            case MetadataSchema::MDFTYPE_EMAIL:
                $AllowedTypes = [
                    MetadataSchema::MDFTYPE_TEXT       => "Text",
                    MetadataSchema::MDFTYPE_PARAGRAPH  => "Paragraph",
                    MetadataSchema::MDFTYPE_NUMBER     => "Number",
                    MetadataSchema::MDFTYPE_FLAG       => "Flag",
                    MetadataSchema::MDFTYPE_URL        => "Url",
                    MetadataSchema::MDFTYPE_EMAIL      => "Email",
                ];
                break;

            case MetadataSchema::MDFTYPE_CONTROLLEDNAME:
            case MetadataSchema::MDFTYPE_OPTION:
                $AllowedTypes = [
                    MetadataSchema::MDFTYPE_CONTROLLEDNAME => "ControlledName",
                    MetadataSchema::MDFTYPE_OPTION         => "Option",
                    MetadataSchema::MDFTYPE_TREE           => "Tree",
                ];
                break;

            case MetadataSchema::MDFTYPE_DATE:
                $AllowedTypes = [
                    MetadataSchema::MDFTYPE_TEXT  => "Text",
                    MetadataSchema::MDFTYPE_DATE  => "Date",
                ];
                break;

            case MetadataSchema::MDFTYPE_IMAGE:
                $AllowedTypes = [
                    MetadataSchema::MDFTYPE_TEXT  => "Text",
                    MetadataSchema::MDFTYPE_IMAGE => "Still Image",
                ];
                break;

            case MetadataSchema::MDFTYPE_TREE:
                $AllowedTypes = [
                    MetadataSchema::MDFTYPE_CONTROLLEDNAME => "ControlledName",
                    MetadataSchema::MDFTYPE_OPTION         => "Option",
                    MetadataSchema::MDFTYPE_TREE           => "Tree",
                ];
                break;

            case MetadataSchema::MDFTYPE_TIMESTAMP:
            case MetadataSchema::MDFTYPE_USER:
            case MetadataSchema::MDFTYPE_FILE:
            case MetadataSchema::MDFTYPE_POINT:
            case MetadataSchema::MDFTYPE_REFERENCE:
            case MetadataSchema::MDFTYPE_SEARCHPARAMETERSET:
            default:
                $AllowedTypes = [];
                break;
        }

        # return type list to caller
        return $AllowedTypes;
    }

    /**
     * Get/set whether field is temporary instance.
     * @param bool $IsTempField If TRUE, field is a temporary instance, or
     *       if FALSE, field is non-temporary.  (OPTIONAL)
     * @return bool If TRUE, field is a temporary instance, or
     *       if FALSE, field is non-temporary.
     */
    public function isTempItem(bool $IsTempField = null): bool
    {
        $Schema = new MetadataSchema($this->schemaId());
        $ItemTableName = "MetadataFields";
        $ItemIdFieldName = "FieldId";
        $ItemFactoryObjectName = "Metavus\\MetadataSchema";
        $ItemAssociationTables = ["FieldQualifierInts"];
        $ItemAssociationFieldName = "MetadataFieldId";

        # if new temp item setting supplied
        if (!is_null($IsTempField)) {
            # if caller requested to switch
            if (($this->Id < 0 && $IsTempField == false) ||
                ($this->Id >= 0 && $IsTempField == true)) {
                # if field name is invalid
                if (strlen($this->normalizeFieldNameForDB($this->name())) < 1) {
                    # set error status to indicate illegal name
                    $this->ErrorStatus = MetadataSchema::MDFSTAT_ILLEGALNAME;
                } else {
                    # lock DB tables to prevent next ID from being grabbed
                    $DB = $this->DB;
                    $DB->query("
                        LOCK TABLES " .$ItemTableName ." WRITE,
                        MetadataSchemas WRITE");

                    # nuke stale field cache
                    self::$FieldCache = null;

                    # get next temp item ID
                    $OldItemId = $this->Id;
                    $Factory = new $ItemFactoryObjectName();
                    if ($IsTempField == true) {
                        $NewId = $Factory->GetNextTempItemId();
                    } else {
                        $NewId = $Factory->GetNextItemId();
                    }

                    # update metadata field id
                    $DB->query("UPDATE MetadataFields SET FieldId = " .$NewId
                            ." WHERE FieldId = " .$this->Id);
                    $this->Id = $NewId;

                    # release DB tables
                    $DB->query("UNLOCK TABLES");

                    # set parameters for database value update convenience
                    #       methods to reflect new field ID
                    $DB->setValueUpdateParameters(
                        "MetadataFields",
                        "FieldId = " .intval($this->Id)
                    );

                    # change associations
                    foreach ($ItemAssociationTables as $TableName) {
                        $DB->query("UPDATE " .$TableName ." " .
                                "SET " .$ItemAssociationFieldName ." = " .$NewId ." " .
                                "WHERE " .$ItemAssociationFieldName ." = " .$OldItemId);
                    }

                    # if changing item from temp to non-temp
                    if ($IsTempField == false) {
                        # add any needed database fields and/or entries
                        $this->addDatabaseFields();

                        # Signal that a new (real) field was added:
                        ApplicationFramework::getInstance()->SignalEvent(
                            "EVENT_FIELD_ADDED",
                            ["FieldId" => $NewId]
                        );

                        # set field order values for new field
                        $Schema->getDisplayOrder()->appendItem($NewId, "Metavus\\MetadataField");
                        $Schema->getEditOrder()->appendItem($NewId, "Metavus\\MetadataField");
                    }
                }
            }

            # clear caches in MetadataSchema
            MetadataSchema::clearStaticCaches();
        }

        # report to caller whether we are a temp item
        return ($this->Id < 0) ? true : false;
    }

    /**
     * Get/set privileges that allowing authoring values for this field.
     * @param PrivilegeSet $NewValue New PrivilegeSet value.  (OPTIONAL)
     * @return PrivilegeSet PrivilegeSet that allows authoring.
     */
    public function authoringPrivileges(PrivilegeSet $NewValue = null): PrivilegeSet
    {
        # if new privileges supplied
        if ($NewValue !== null) {
            # store new privileges in database
            $this->DB->updateValue("AuthoringPrivileges", $NewValue->data());
            $this->AuthoringPrivileges = $NewValue;
        }

        # return current value to caller
        return $this->AuthoringPrivileges;
    }

    /**
     * Get/set privileges that allowing editing values for this field.
     * @param PrivilegeSet $NewValue New PrivilegeSet value.  (OPTIONAL)
     * @return PrivilegeSet PrivilegeSet that allows editing.
     */
    public function editingPrivileges(PrivilegeSet $NewValue = null): PrivilegeSet
    {
        # if new privileges supplied
        if ($NewValue !== null) {
            # store new privileges in database
            $this->DB->updateValue("EditingPrivileges", $NewValue->data());
            $this->EditingPrivileges = $NewValue;
        }

        # return current value to caller
        return $this->EditingPrivileges;
    }

    /**
     * Get/set privileges that allowing viewing values for this field.
     * @param PrivilegeSet $NewValue New PrivilegeSet value.  (OPTIONAL)
     * @return PrivilegeSet PrivilegeSet that allows viewing.
     */
    public function viewingPrivileges(PrivilegeSet $NewValue = null): PrivilegeSet
    {
        # if new privileges supplied
        if ($NewValue !== null) {
            # store new privileges in database
            $this->DB->updateValue("ViewingPrivileges", $NewValue->data());
            $this->ViewingPrivileges = $NewValue;
        }

        # return current value to caller
        return $this->ViewingPrivileges;
    }

    /**
     * Get metadata field ID.
     * @return int Field ID.
     */
    public function id(): int
    {
        return $this->Id;
    }

    /**
     * Get base name of database column used to store metadata field
     * value.  (Only valid for some field types.)
     * @return string Column name.
     */
    public function dBFieldName(): string
    {
        return $this->normalizeFieldNameForDB($this->DB->updateValue("FieldName"));
    }

    /**
     * Get/set field description.
     * @param string $NewValue Updated description.  (OPTIONAL)
     * @return string Current field description.
     */
    public function description(string $NewValue = null): string
    {
        return $this->DB->updateValue("Description", $NewValue);
    }

    /**
     * Get/set field instructions.
     * @param string $NewValue Updated instructions.  (OPTIONAL)
     * @return string Current field instructions.
     */
    public function instructions(string $NewValue = null): string
    {
        $NewValue = ($NewValue === "" ? false : $NewValue);
        return $this->DB->updateValue("Instructions", $NewValue);
    }

    /**
     * Get/set field owner.
     * @param string $NewValue Updated owner.  (OPTIONAL)
     * @return string Current owner.
     */
    public function owner(string $NewValue = null): string
    {
        return $this->DB->updateValue("Owner", $NewValue);
    }

    /**
     * Get/set whether field is enabled.
     * @param bool $NewValue TRUE to enable field, or FALSE to disable.
     *       (OPTIONAL)
     * @return bool TRUE if field is enabled, otherwise FALSE.
     */
    public function enabled(bool $NewValue = null): bool
    {
        return $this->DB->updateBoolValue("Enabled", $NewValue);
    }

    /**
     * Get/set EnableOnOwnerReturn property, which determines if a field that
     *   was disabled because its owner disappeared should be re-enabled if
     *   the owner comes back.
     * @param bool $NewValue New value to set.
     * @return bool Current setting.
     * @see MetadataSchema::normalizeOwnedFields()
     */
    public function enableOnOwnerReturn(bool $NewValue = null)
    {
        return $this->DB->updateBoolValue("EnableOnOwnerReturn", $NewValue);
    }

    /**
     * Get/set whether a value is required for this field.
     * @param bool $NewValue TRUE to require a value, or FALSE to make
     *       entering a value optional.  (OPTIONAL)
     * @return bool TRUE if a value is required, otherwise FALSE.
     */
    public function optional(bool $NewValue = null): bool
    {
        static $WarningsLogged = [];

        $Value = $this->DB->updateBoolValue("Optional", $NewValue);

        if (!is_null($NewValue)) {
            if ($NewValue === true && $this->CannotBeOptional) {
                throw new InvalidArgumentException(
                    $this->typeAsName()." fields cannot be optional."
                );
            }
        }

        if ($Value === true && $this->CannotBeOptional) {
            $Value = false;

            if (!isset($WarningsLogged[$this->id()])) {
                ApplicationFramework::getInstance()->logMessage(
                    ApplicationFramework::LOGLVL_WARNING,
                    $this->typeAsName()." field "
                    .$this->name()." (Id: ".$this->id().") "
                    ."cannot be optional but is marked optional "
                    ."in the database."
                );
                $WarningsLogged[$this->id()] = true;
            }
        }

        return $Value;
    }

    /**
     * Get/set whether this field is editable.
     * @param bool $NewValue TRUE to indicate that field is editable,
     *       or FALSE to indicate it non-editable.  (OPTIONAL)
     * @return bool TRUE if field is editable, otherwise FALSE.
     */
    public function editable(bool $NewValue = null): bool
    {
        return $this->DB->updateBoolValue("Editable", $NewValue);
    }

    /**
     * Get/set whether to allow multiple values for field.
     * @param bool $NewValue TRUE to allow multiple values, or FALSE if
     *       only one value may be set.  (OPTIONAL)
     * @return bool TRUE if field allows multiple values, otherwise FALSE.
     */
    public function allowMultiple(bool $NewValue = null): bool
    {
        static $WarningsLogged = [];

        if ($NewValue !== null) {
            if ($NewValue === true && !$this->CanAllowMultiple) {
                throw new InvalidArgumentException(
                    $this->typeAsName()." fields cannot allow multiple values."
                );
            }

            if ($NewValue === false && $this->MustAllowMultiple) {
                throw new InvalidArgumentException(
                    $this->typeAsName()." fields must allow multiple values."
                );
            }
        }

        $Value = $this->DB->updateBoolValue("AllowMultiple", $NewValue);

        if ($Value === true && !$this->CanAllowMultiple) {
            $Value = false;

            if (!isset($WarningsLogged[$this->id()])) {
                ApplicationFramework::getInstance()->logMessage(
                    ApplicationFramework::LOGLVL_WARNING,
                    $this->typeAsName()." field ".$this->name()." (Id: ".$this->id().") "
                    ."cannot allow multiple values but is set to allow multiple values "
                    ."in the database."
                );
                $WarningsLogged[$this->id()] = true;
            }
        } elseif ($Value === false && $this->MustAllowMultiple) {
            $Value = true;

            if (!isset($WarningsLogged[$this->id()])) {
                ApplicationFramework::getInstance()->logMessage(
                    ApplicationFramework::LOGLVL_WARNING,
                    $this->typeAsName()." field ".$this->name()." (Id: ".$this->id().") "
                    ."must allow multiple values but is set not to allow multiple values "
                    ."in the database."
                );
                $WarningsLogged[$this->id()] = true;
            }
        }

        return $Value;
    }

    /**
     * Get/set whether to include field in keyword search.
     * @param bool $NewValue TRUE to include field, or FALSE if field should
     *       not be included.  (OPTIONAL)
     * @return bool TRUE if field should be included, otherwise FALSE.
     */
    public function includeInKeywordSearch(bool $NewValue = null): bool
    {
        return $this->DB->updateBoolValue("IncludeInKeywordSearch", $NewValue);
    }

    /**
     * Get/set whether to include field in advanced search.
     * @param bool $NewValue TRUE to include field, or FALSE if field should
     *       not be included.  (OPTIONAL)
     * @return bool TRUE if field should be included, otherwise FALSE.
     */
    public function includeInAdvancedSearch(bool $NewValue = null): bool
    {
        return $this->DB->updateBoolValue("IncludeInAdvancedSearch", $NewValue);
    }

    /**
     * Get/set whether to include field in faceted search.
     * @param bool $NewValue TRUE to include field, or FALSE if field should
     *       not be included.  (OPTIONAL)
     * @return bool TRUE if field should be included, otherwise FALSE.
     */
    public function includeInFacetedSearch(bool $NewValue = null): bool
    {
        return $this->DB->updateBoolValue("IncludeInFacetedSearch", $NewValue);
    }

    /**
     * Get/set the search group logic, used for both facets and
     * advanced search when more than one value is selected for this
     * field.
     * @param int $NewValue One of the SearchEngine::LOGIC_* consts
     * @return int Current SearchGroupLogic setting
     */
    public function searchGroupLogic(int $NewValue = null): int
    {
        if ($NewValue !== null) {
            # if a new value was passed, verify that it's a legal value
            if ($NewValue != SearchEngine::LOGIC_AND && $NewValue != SearchEngine::LOGIC_OR) {
                throw new Exception(
                    "Invalid NewValue for SearchGroupLogic(). "
                    ."Must be a SearchEngine::LOGIC_* constant."
                );
            }
        }

        return $this->DB->updateIntValue("SearchGroupLogic", $NewValue);
    }

    /**
     * Get/set whether a facet for this field should show all terms in the
     *   vocabulary or just those associated with the current set of search
     *   results.
     * @param bool $NewValue TRUE to only show those associated with the
     *   current result set, FALSE to show all terms.
     * @return bool Current FacetsShowOnlyTermsUsedInResults setting.
     */
    public function facetsShowOnlyTermsUsedInResults(bool $NewValue = null): bool
    {
        return $this->DB->updateBoolValue("FacetsShowOnlyTermsUsedInResults", $NewValue);
    }

    /**
     * Get/set whether to include field in search result sort options.
     * @param bool $NewValue TRUE to include field, or FALSE if field should
     *       not be included.  (OPTIONAL)
     * @return bool TRUE if field should be included, otherwise FALSE.
     */
    public function includeInSortOptions(bool $NewValue = null): bool
    {
        return $this->DB->updateBoolValue("IncludeInSortOptions", $NewValue);
    }

    /**
     * Get/set whether to include field in recommender system comparisons.
     * @param bool $NewValue TRUE to include field, or FALSE if field should
     *       not be included.  (OPTIONAL)
     * @return bool TRUE if field should be included, otherwise FALSE.
     */
    public function includeInRecommender(bool $NewValue = null): bool
    {
        return $this->DB->updateBoolValue("IncludeInRecommender", $NewValue);
    }

    /**
     * Get/set whether to duplciate this field when a resource is duplicated.
     * @param bool $NewValue Update setting.
     * @return bool Current setting.
     */
    public function copyOnResourceDuplication(bool $NewValue = null): bool
    {
        return $this->DB->updateBoolValue("CopyOnResourceDuplication", $NewValue);
    }

    /**
     * Get/set maximum length to store in a text field.
     * @param int $NewValue Updated value.
     * @return int Current setting.
     */
    public function maxLength(int $NewValue = null): int
    {
        return $this->DB->updateIntValue("MaxLength", $NewValue);
    }



    /**
     * Get/set the minimum value allowed for a number field.
     * @param float $NewValue Updated value.
     * @return float Current Setting.
     */
    public function minValue(float $NewValue = null): float
    {
        if ($NewValue !== null && !$this->CanHaveMinValue) {
            throw new InvalidArgumentException(
                "Attempt to set ".__METHOD__." for ".$this->typeAsName()." field."
            );
        }
        return $this->DB->updateFloatValue("MinValue", $NewValue);
    }

    /**
     * Get/set the maximum allowed value for a number field.
     * @param float $NewValue Updated value (OPTIONAL).
     * @return float Current setting.
     */
    public function maxValue(float $NewValue = null): float
    {
        if ($NewValue !== null && !$this->CanHaveMaxValue) {
            throw new InvalidArgumentException(
                "Attempt to set ".__METHOD__." for ".$this->typeAsName()." field."
            );
        }
        return $this->DB->updateFloatValue("MaxValue", $NewValue);
    }

    /**
     * Get/set the label displayed when a flag field is 'on'.
     * @param string $NewValue Updated value (OPTIONAL).
     * @return string Current setting.
     */
    public function flagOnLabel(string $NewValue = null): string
    {
        if ($NewValue !== null && $this->type() != MetadataSchema::MDFTYPE_FLAG) {
            throw new InvalidArgumentException(
                "Attempt to set ".__METHOD__." for ".$this->typeAsName()." field."
            );
        }
        return $this->DB->updateValue("FlagOnLabel", $NewValue);
    }

    /**
     * Get/set the label displayed when a flag field is 'off'.
     * @param string $NewValue Updated value (OPTIONAL).
     * @return string Current setting.
     */
    public function flagOffLabel(string $NewValue = null): string
    {
        if ($NewValue !== null && $this->type() != MetadataSchema::MDFTYPE_FLAG) {
            throw new InvalidArgumentException(
                "Attempt to set ".__METHOD__." for ".$this->typeAsName()." field."
            );
        }
        return $this->DB->updateValue("FlagOffLabel", $NewValue);
    }

    /**
     * Get/set the date format.
     * @param string $NewValue Updated value (OPTIONAL).
     * @return string Current setting.
     */
    public function dateFormat(string $NewValue = null): string
    {
        if ($NewValue !== null) {
            if ($this->type() != MetadataSchema::MDFTYPE_DATE &&
                $this->type() != MetadataSchema::MDFTYPE_TIMESTAMP) {
                throw new InvalidArgumentException(
                    "Attempt to set ".__METHOD__." for ".$this->typeAsName()." field."
                );
            }
        }
        return $this->DB->updateValue("DateFormat", $NewValue);
    }

    /**
     * Get/set the weight this field has for search results (higher
     * weights have a larger impact).
     * @param int $NewValue Updated value (OPTIONAL).
     * @return int Current setting.
     */
    public function searchWeight(int $NewValue = null): int
    {
        return $this->DB->updateIntValue("SearchWeight", $NewValue);
    }

    /**
     * Get/set the weight this field has for recommendations (higher
     * weights have a larger impact).
     * @param int $NewValue Updated value (OPTIONAL).
     * @return int Current setting.
     */
    public function recommenderWeight(int $NewValue = null): int
    {
        return $this->DB->updateIntValue("RecommenderWeight", $NewValue);
    }

    /**
     * Get/set if this field uses qualifiers.
     * @param bool $NewValue Updated value (OPTIONAL).
     * @return bool Current setting.
     */
    public function usesQualifiers(bool $NewValue = null): bool
    {
        return $this->DB->updateBoolValue("UsesQualifiers", $NewValue);
    }

    /**
     * Get/set if this field should display qualifiers on EditResource.
     * @param bool $NewValue Updated value (OPTIONAL).
     * @return bool Current setting.
     */
    public function showQualifiers(bool $NewValue = null): bool
    {
        return $this->DB->updateBoolValue("ShowQualifiers", $NewValue);
    }

    /**
     * Get/set the default qualifier for this field.
     * @param int|false $NewValue Updated value (OPTIONAL).
     * @return int|false Current setting.
     */
    public function defaultQualifier($NewValue = null)
    {
        if (!is_null($NewValue) && $NewValue !== false &&
            !Qualifier::itemExists($NewValue)) {
            throw new InvalidArgumentException(
                "Invalid qualifier ID provided."
            );
        }
        return $this->DB->updateIntValue("DefaultQualifier", $NewValue);
    }

    /**
     * Get/set if this field should allow HTML.
     * @param bool $NewValue Updated value (OPTIONAL).
     * @return bool Current setting.
     */
    public function allowHTML(bool $NewValue = null): bool
    {
        if ($NewValue !== null && $this->type() != MetadataSchema::MDFTYPE_PARAGRAPH) {
            throw new InvalidArgumentException(
                "Attempt to set allowHTML() for non-Paragraph field."
            );
        }
        return $this->DB->updateBoolValue("AllowHTML", $NewValue);
    }

    /**
     * Get/set if this field should be used to create OAI sets.
     * @param bool $NewValue Updated value (OPTIONAL).
     * @return bool Current setting.
     */
    public function useForOaiSets(bool $NewValue = null): bool
    {
        return $this->DB->updateBoolValue("UseForOaiSets", $NewValue);
    }

    /**
     * Get/set the current number of digits after the decimal point.
     * @param int $NewValue Updated value (OPTIONAL).
     * @return int Current setting.
     */
    public function pointPrecision(int $NewValue = null): int
    {
        if ($NewValue !== null && $this->type() != MetadataSchema::MDFTYPE_POINT) {
            throw new InvalidArgumentException(
                "Attempt to set ".__METHOD__." for ".$this->typeAsName()." field."
            );
        }

        $DB = $this->DB;
        if (($NewValue !== null) && ($this->id() >= 0) &&
            ($this->type() == MetadataSchema::MDFTYPE_POINT)) {
            $OldValue = $this->DB->updateIntValue("PointPrecision");

            if ($NewValue != $OldValue) {
                $Decimals  = $this->DB->updateIntValue("PointDecimalDigits");
                $TotalDigits = $NewValue + $Decimals;

                $this->DB->query("ALTER TABLE Records MODIFY COLUMN "
                           ."`" .$this->dBFieldName() ."X` "
                           ."DECIMAL(" .$TotalDigits ."," .$Decimals .")");
                $this->DB->query("ALTER TABLE Records MODIFY COLUMN "
                           ."`" .$this->dBFieldName() ."Y` "
                           ."DECIMAL(" .$TotalDigits ."," .$Decimals .")");
            }
        }

        return $DB->updateIntValue("PointPrecision", $NewValue);
    }

    /**
     * Get/set the total number of digits a point field should store.
     * @param int $NewValue Updated value (OPTIONAL).
     * @return int Current setting.
     */
    public function pointDecimalDigits(int $NewValue = null): int
    {
        if ($NewValue !== null && $this->type() != MetadataSchema::MDFTYPE_POINT) {
            throw new InvalidArgumentException(
                "Attempt to set ".__METHOD__." for ".$this->typeAsName()." field."
            );
        }

        $DB = $this->DB;
        if (($NewValue !== null) && ($this->id() >= 0) &&
            ($this->type() == MetadataSchema::MDFTYPE_POINT)) {
            $OldValue = $DB->updateIntValue("PointDecimalDigits");

            if ($NewValue != $OldValue) {
                $Precision = $DB->updateIntValue("PointPrecision");

                $TotalDigits = $NewValue + $Precision;

                $DB->query("ALTER TABLE Records MODIFY COLUMN "
                           ."`" .$this->dBFieldName() ."X` "
                           ."DECIMAL(" .$TotalDigits ."," .$NewValue .")");
                $DB->query("ALTER TABLE Records MODIFY COLUMN "
                           ."`" .$this->dBFieldName() ."Y` "
                           ."DECIMAL(" .$TotalDigits ."," .$NewValue .")");
            }
        }

        return $DB->updateIntValue("PointDecimalDigits", $NewValue);
    }

    /**
     * Get/set default value.
     * @param mixed $NewValue Updated value (OPTIONAL).
     * @return mixed Current setting or FALSE if no value set.
     */
    public function defaultValue($NewValue = null)
    {
        switch ($this->type()) {
            case MetadataSchema::MDFTYPE_POINT:
                # valid value given
                if (($NewValue !== null) && isset($NewValue["X"]) && isset($NewValue["Y"])) {
                    $NewValue = $NewValue["X"] ."," .$NewValue["Y"];
                # invalid value given
                } else {
                    $NewValue = null;
                }

                $Value = $this->DB->updateValue("DefaultValue", $NewValue);

                if (strlen($Value)) {
                    $tmp = explode(",", $Value);

                    if (count($tmp) == 2) {
                        return ["X" => $tmp[0], "Y" => $tmp[1]];
                    }
                }

                return ["X" => null, "Y" => null];

            case MetadataSchema::MDFTYPE_OPTION:
            case MetadataSchema::MDFTYPE_TREE:
                # multiple default values to set
                if (is_array($NewValue)) {
                    # empty array
                    if (count($NewValue) == 0) {
                        $NewValue = null;
                    # multiple defaults are allowed
                    } elseif ($this->allowMultiple()) {
                        $NewValue = serialize($NewValue);
                    # only one default is allowed so get the first one
                    } else {
                        $NewValue = array_shift($NewValue);
                    }
                }
                $Result = $this->DB->updateValue("DefaultValue", $NewValue);
                return empty($Result) || is_numeric($Result) ?
                    $Result : unserialize($Result);

            default:
                return $this->DB->updateValue("DefaultValue", $NewValue);
        }
    }

    /**
     * Get/set method by which field is updated.
     * @param string $NewValue New update method.
     * @return string Existing update method.
     */
    public function updateMethod(string $NewValue = null): string
    {
        return $this->DB->updateValue("UpdateMethod", $NewValue);
    }

    /**
     * get possible values (only meaningful for Trees, Controlled Names, Options,
     * Flags, and Users)
     * @param integer|NULL $MaxNumberOfValues Maximum number of values to get.
     * @param integer $Offset Offset into the list of values.
     * @return array ItemIds => Values
     */
    public function getPossibleValues($MaxNumberOfValues = null, int $Offset = 0): array
    {
        # retrieve values based on field type
        switch ($this->type()) {
            case MetadataSchema::MDFTYPE_TREE:
                $QueryString = "SELECT ClassificationId, ClassificationName"
                        ." FROM Classifications WHERE FieldId = " .$this->id()
                        ." ORDER BY ClassificationName";
                if ($MaxNumberOfValues) {
                    $QueryString .= " LIMIT " .intval($MaxNumberOfValues) ." OFFSET "
                        .intval($Offset);
                }
                $this->DB->query($QueryString);
                $PossibleValues = $this->DB->FetchColumn(
                    "ClassificationName",
                    "ClassificationId"
                );
                break;

            case MetadataSchema::MDFTYPE_CONTROLLEDNAME:
            case MetadataSchema::MDFTYPE_OPTION:
                $QueryString = "SELECT ControlledNameId, ControlledName"
                        ." FROM ControlledNames WHERE FieldId = " .$this->id()
                        ." ORDER BY ControlledName";
                if ($MaxNumberOfValues) {
                    $QueryString .= " LIMIT " .intval($MaxNumberOfValues) ." OFFSET "
                        .intval($Offset);
                }
                $this->DB->query($QueryString);
                $PossibleValues = $this->DB->FetchColumn(
                    "ControlledName",
                    "ControlledNameId"
                );
                break;

            case MetadataSchema::MDFTYPE_FLAG:
                $PossibleValues[0] = $this->flagOffLabel();
                $PossibleValues[1] = $this->flagOnLabel();
                break;

            case MetadataSchema::MDFTYPE_USER:
                $UserFactory = new UserFactory();
                $Restrictions = $this->userPrivilegeRestrictions();
                $PossibleValues = [];

                if (count($Restrictions)) {
                    $PossibleValues = $UserFactory->getUsersWithPrivileges(
                        $Restrictions
                    );
                } else {
                    $Users = $UserFactory->getMatchingUsers(".*.");

                    foreach ($Users as $Id => $Data) {
                        $PossibleValues[$Id] = $Data["UserName"];
                    }
                }
                break;

            default:
                # for everything else return an empty array
                $PossibleValues = [];
                break;
        }

        # return array of possible values to caller
        return $PossibleValues;
    }

    /**
     * Get count of possible values (only meaningful for Trees, Controlled Names,
     * Options, and Users)
     * @return int Number of possible values.
     */
    public function getCountOfPossibleValues(): int
    {
        # retrieve values based on field type
        switch ($this->type()) {
            case MetadataSchema::MDFTYPE_TREE:
                $Count = $this->DB->queryValue(
                    "SELECT count(*) AS ValueCount"
                        ." FROM Classifications WHERE FieldId = " .$this->id(),
                    "ValueCount"
                );
                break;

            case MetadataSchema::MDFTYPE_CONTROLLEDNAME:
            case MetadataSchema::MDFTYPE_OPTION:
                $Count = $this->DB->queryValue(
                    "SELECT count(*) AS ValueCount"
                        ." FROM ControlledNames WHERE FieldId = " .$this->id(),
                    "ValueCount"
                );
                break;

            case MetadataSchema::MDFTYPE_FLAG:
                $Count = 2;
                break;

            case MetadataSchema::MDFTYPE_USER:
                $Count = count($this->getPossibleValues());
                break;

            case MetadataSchema::MDFTYPE_REFERENCE:
                $SchemaIds = $this->referenceableSchemaIds();
                $Count = 0;
                foreach ($SchemaIds as $SchemaId) {
                    $Count += (new RecordFactory($SchemaId))
                        ->getItemCount();
                }
                break;

            default:
                # for everything else return an empty array
                $Count = 0;
                break;
        }

        # return count of possible values to caller
        return $Count;
    }

    /**
     * Load new from a Vocabulary or a vocabulary file.
     * @param mixed $Vocab Path to vocabulary file or
     *     Vocabulary object providing the terms to load.
     * @return int Number of terms added.
     */
    public function loadVocabulary($Vocab): int
    {
        $ValidTypes = [
            MetadataSchema::MDFTYPE_TREE,
            MetadataSchema::MDFTYPE_CONTROLLEDNAME,
            MetadataSchema::MDFTYPE_OPTION,
        ];

        if (!in_array($this->type(), $ValidTypes)) {
            throw new Exception(
                "Attempt to add a vocabulary to a type of field that does not "
                ."support vocabularies."
            );
        }

        if ($this->id() < 0) {
            throw new Exception(
                "Attempt to load a vocabulary into a temporary field"
            );
        }

        if (!$Vocab instanceof Vocabulary) {
            if (is_string($Vocab)) {
                $Vocab = new Vocabulary($Vocab);
            } else {
                throw new Exception("Invalid argument type.");
            }
        }

        $Terms = $Vocab->termList();

        # if new vocabulary has a qualifier
        if ($Vocab->hasQualifier()) {
            # if we already have a qualifier with the same name
            $QFactory = new QualifierFactory();
            if ($QFactory->nameIsInUse($Vocab->qualifierName())) {
                # if details for existing and new qualifier do not match
                $Qualifier = $QFactory->getItemByName(
                    $Vocab->qualifierName()
                );
                if ($Vocab->qualifierNamespace() != $Qualifier->nSpace() ||
                    ($Vocab->qualifierUrl() != $Qualifier->Url())) {
                    # error out
                    throw new Exception(
                        "The vocabulary <i>" .$Vocab->name()
                        ."</i> specifies a qualifier <i>"
                        .$Vocab->qualifierName() ."</i> that conflicts"
                        ." with an existing qualifier (has the same name but"
                        ." a different namespace or URL or both)."
                    );
                }

                # add new vocabulary with qualifier
                $AddedItemCount = $this->addTerms($Terms, $Qualifier);
                $this->addQualifier($Qualifier);
            } else {
                # add new vocabulary with qualifier
                $Qualifier = Qualifier::create($Vocab->qualifierName());
                $Qualifier->nSpace($Vocab->qualifierNamespace());
                $Qualifier->url($Vocab->qualifierUrl());
                $AddedItemCount = $this->addTerms($Terms, $Qualifier);
                $this->addQualifier($Qualifier);
            }
        } else {
            # add new vocabulary
            $AddedItemCount = $this->addTerms($Terms);
        }

        return $AddedItemCount;
    }

    /**
     * Get ID for specified value (only meaningful for Trees / Controlled Names / Options)
     * @param string $Value Value to search for.
     * @return integer|null ItemId for the specified value.
     */
    public function getIdForValue(string $Value)
    {
        # retrieve ID based on field type
        switch ($this->type()) {
            case MetadataSchema::MDFTYPE_TREE:
                $Id = $this->DB->queryValue(
                    "SELECT ClassificationId FROM Classifications"
                        ." WHERE ClassificationName = '" .addslashes($Value) ."'"
                        ." AND FieldId = " .$this->id(),
                    "ClassificationId"
                );
                break;

            case MetadataSchema::MDFTYPE_CONTROLLEDNAME:
            case MetadataSchema::MDFTYPE_OPTION:
                $Id = $this->DB->queryValue(
                    "SELECT ControlledNameId FROM ControlledNames"
                        ." WHERE ControlledName = '" .addslashes($Value) ."'"
                        ." AND FieldId = " .$this->id(),
                    "ControlledNameId"
                );
                break;

            default:
                # for everything else return NULL
                $Id = null;
                break;
        }

        # return ID for value to caller
        return $Id;
    }

    /**
     * Get value for specified ID (only meaningful for Trees / Controlled Names / Options)
     * @param int $Id ItemId to search for.
     * @return string|null Value for the specified ID.
     */
    public function getValueForId(int $Id)
    {
        # retrieve ID based on field type
        switch ($this->type()) {
            case MetadataSchema::MDFTYPE_TREE:
                $Value = $this->DB->queryValue(
                    "SELECT ClassificationName FROM Classifications"
                        ." WHERE ClassificationId = '" .intval($Id) ."'"
                        ." AND FieldId = " .$this->id(),
                    "ClassificationName"
                );
                break;

            case MetadataSchema::MDFTYPE_CONTROLLEDNAME:
            case MetadataSchema::MDFTYPE_OPTION:
                $Value = $this->DB->queryValue(
                    "SELECT ControlledName FROM ControlledNames"
                        ." WHERE ControlledNameId = '" .intval($Id) ."'"
                        ." AND FieldId = " .$this->id(),
                    "ControlledName"
                );
                break;

            default:
                # for everything else return NULL
                $Value = null;
                break;
        }

        # return ID for value to caller
        return $Value;
    }

    /**
     * Check how many times a specific value is currently used for this field.
     * This method is not valid for Date fields.
     * @param int|string|object|array $Value Value to check.  For Flag, Tree, Option, Image,
     *       and Controlled Name fields this must be an ID or an appropriate object.
     *       For Point fields this must be an associative array with two values
     *       with "X" and "Y" indexes.  Date fields are not supported.  For other
     *       field types, the literal value to check should be passed in.
     * @return int Number of times values is currently used.
     */
    public function valueUseCount($Value): int
    {
        # retrieve ID if object passed in
        if (($Value instanceof ControlledName) || ($Value instanceof Classification) ||
            ($Value instanceof Image)) {
            $Value = $Value->id();
        } elseif (!is_numeric($Value) && !is_array($Value) &&
            !($Value instanceof SearchParameterSet)) {
            throw new InvalidArgumentException("Argument of incorrect type " .
                "passed into MetadataField::valueUseCount() at " .StdLib::getMyCaller());
        }

        # check value based on field type
        $DB = $this->DB;
        $DBFieldName = $this->dBFieldName();
        switch ($this->type()) {
            case MetadataSchema::MDFTYPE_TEXT:
            case MetadataSchema::MDFTYPE_PARAGRAPH:
            case MetadataSchema::MDFTYPE_NUMBER:
            case MetadataSchema::MDFTYPE_IMAGE:
            case MetadataSchema::MDFTYPE_TIMESTAMP:
            case MetadataSchema::MDFTYPE_URL:
            case MetadataSchema::MDFTYPE_FLAG:
            case MetadataSchema::MDFTYPE_FILE:
            case MetadataSchema::MDFTYPE_EMAIL:
                if (is_array($Value) || is_object($Value)) {
                    throw new InvalidArgumentException(
                        "Argument of incorrect type " .
                        "passed into MetadataField::valueUseCount() at " .StdLib::getMyCaller()
                    );
                }
                $UseCount = $DB->queryValue(
                    "SELECT COUNT(*) AS UseCount"
                        ." FROM Records"
                        ." WHERE `" .$DBFieldName ."` = '" .addslashes((string)$Value) ."'"
                        ." AND SchemaId = " .intval($DB->updateValue("SchemaId")),
                    "UseCount"
                );
                break;

            case MetadataSchema::MDFTYPE_TREE:
                if (is_array($Value) || is_object($Value)) {
                    throw new InvalidArgumentException(
                        "Argument of incorrect type " .
                        "passed into MetadataField::valueUseCount() at " .StdLib::getMyCaller()
                    );
                }
                $UseCount = $DB->queryValue(
                    "SELECT COUNT(*) AS UseCount"
                        ." FROM RecordClassInts"
                        ." WHERE ClassificationId = " .intval($Value),
                    "UseCount"
                );
                break;

            case MetadataSchema::MDFTYPE_CONTROLLEDNAME:
            case MetadataSchema::MDFTYPE_OPTION:
                if (is_array($Value) || is_object($Value)) {
                    throw new InvalidArgumentException(
                        "Argument of incorrect type " .
                        "passed into MetadataField::valueUseCount() at " .StdLib::getMyCaller()
                    );
                }
                $UseCount = $DB->queryValue(
                    "SELECT COUNT(*) AS UseCount"
                        ." FROM RecordNameInts"
                        ." WHERE ControlledNameId = " .intval($Value),
                    "UseCount"
                );
                break;

            case MetadataSchema::MDFTYPE_POINT:
                if (!is_array($Value)) {
                    throw new InvalidArgumentException(
                        "Argument of incorrect type " .
                        "passed into MetadataField::valueUseCount() at " .StdLib::getMyCaller()
                    );
                }
                $UseCount = $DB->queryValue(
                    "SELECT COUNT(*) AS UseCount"
                        ." FROM Records"
                        ." WHERE `" .$DBFieldName ."X` = '" .$Value["X"] ."'"
                        ." AND `" .$DBFieldName ."Y` = '" .$Value["Y"] ."'"
                        ." AND SchemaId = " .intval($DB->updateValue("SchemaId")),
                    "UseCount"
                );
                break;

            case MetadataSchema::MDFTYPE_USER:
                if (is_array($Value) || is_object($Value)) {
                    throw new InvalidArgumentException(
                        "Argument of incorrect type " .
                        "passed into MetadataField::valueUseCount() at " .StdLib::getMyCaller()
                    );
                }
                $UseCount = $DB->queryValue(
                    "SELECT COUNT(*) AS UseCount"
                        ." FROM RecordUserInts"
                        ." WHERE UserId = " .intval($Value)
                        ." AND FieldId = " .$this->id(),
                    "UseCount"
                );
                break;

            case MetadataSchema::MDFTYPE_SEARCHPARAMETERSET:
                if (!($Value instanceof SearchParameterSet)) {
                    throw new InvalidArgumentException(
                        "Argument of incorrect type (" .gettype($Value)
                        .") supplied."
                    );
                }
                $UseCount = $DB->queryValue(
                    "SELECT COUNT(*) As UseCount"
                    ." FROM Records WHERE `" .$DBFieldName ."`"
                    ." = '" .addslashes($Value->data()) ."'",
                    "UseCount"
                );
                break;

            default:
                throw new Exception(__CLASS__ ."::" .__METHOD__ ."() called for"
                        ." unsupported field type (" .$this->type() .").");
        }

        # report use count to caller
        return $UseCount;
    }

    /**
     * Get/set whether field uses item-level qualifiers.
     * @param bool $NewValue Updated value (OPTIONAL).
     * @return bool TRUE if this field users item-lvel qualifiers,
     *     FALSE otherwise.
     */
    public function hasItemLevelQualifiers(bool $NewValue = null): bool
    {
        $DB = $this->DB;
        # if value provided different from present value
        if (($NewValue !== null) && ($NewValue != $DB->updateValue("HasItemLevelQualifiers"))) {
            # check if qualifier column currently exists
            $QualColName = $this->dBFieldName() ."Qualifier";
            $QualColExists = $DB->fieldExists("Records", $QualColName);

            # if new value indicates qualifiers should now be used
            if ($NewValue == true) {
                # if qualifier column does not exist in DB for this field
                if ($QualColExists == false) {
                    # add qualifier column in DB for this field
                    $DB->query("ALTER TABLE Records ADD COLUMN `"
                                     .$QualColName ."` INT");
                }
            } else {
                # if qualifier column exists in DB for this field
                if ($QualColExists == true) {
                    # remove qualifier column from DB for this field
                    $DB->query("ALTER TABLE Records DROP COLUMN `"
                                     .$QualColName ."`");
                }
            }
        }

        return $DB->updateBoolValue("HasItemLevelQualifiers", $NewValue);
    }

    /**
     * Get list of qualifiers associated with field.
     * @return array Associated qualifiers.
     */
    public function associatedQualifierList(): array
    {
        # start with empty list
        $List = [];

        # for each associated qualifier
        $DB = $this->DB;
        $DB->query("SELECT QualifierId FROM FieldQualifierInts"
                     ." WHERE MetadataFieldId = " .$DB->updateValue("FieldId"));
        while ($Record = $DB->fetchRow()) {
            # load qualifier object
            $Qual = new Qualifier($Record["QualifierId"]);

            # add qualifier ID and name to list
            $List[$Qual->id()] = $Qual->name();
        }

        # return list to caller
        return $List;
    }

    /**
     * Get list of qualifiers not associated with field.
     * @return array Qualifiers not associated.
     */
    public function unassociatedQualifierList(): array
    {
        # grab list of associated qualifiers
        $AssociatedQualifiers = $this->associatedQualifierList();

        # get list of all qualifiers
        $QFactory = new QualifierFactory();
        $AllQualifiers = $QFactory->getItemNames();

        # return list of unassociated qualifiers
        return array_diff($AllQualifiers, $AssociatedQualifiers);
    }

    /**
     * Associate qualifier with field.
     * @param mixed $Qualifier Qualifer ID, name, or object.
     * @throws InvalidArgumentException If unknown name supplied.
     */
    public function addQualifier($Qualifier)
    {
        # if qualifier object passed in
        if ($Qualifier instanceof Qualifier) {
            # grab qualifier ID from object
            $Qualifier = $Qualifier->id();
        # else if string passed in does not look like ID
        } elseif (!is_numeric($Qualifier)) {
            # assume string passed in is name and use it to retrieve ID
            $QFactory = new QualifierFactory();
            $Qualifier = $QFactory->getItemIdByName($Qualifier);
            if ($Qualifier === false) {
                throw new InvalidArgumentException("Unknown qualifier name (\""
                        .$Qualifier ."\").");
            }
        }

        # if not already associated
        $RecordCount = $this->DB->queryValue(
            "SELECT COUNT(*) AS RecordCount FROM FieldQualifierInts"
            ." WHERE QualifierId = " .$Qualifier
            ." AND MetadataFieldId = " .$this->id(),
            "RecordCount"
        );
        if ($RecordCount < 1) {
            # associate field with qualifier
            $this->DB->query("INSERT INTO FieldQualifierInts SET"
                             ." QualifierId = " .$Qualifier .","
                             ." MetadataFieldId = " .$this->id());
        }
    }



    /**
     * Delete a qualifier association.
     * @param mixed $QualifierIdOrObject Qualifier to remove from this field.
     */
    public function unassociateWithQualifier($QualifierIdOrObject)
    {
        # if qualifier object passed in
        if ($QualifierIdOrObject instanceof Qualifier) {
            # grab qualifier ID from object
            $QualifierIdOrObject = $QualifierIdOrObject->id();
        }

        # delete intersection record from database
        $this->DB->query("DELETE FROM FieldQualifierInts WHERE QualifierId = "
                         .$QualifierIdOrObject ." AND MetadataFieldId = " .
                         $this->id());
    }

    /**
     * Retrieve item factory object for this field.
     * @return mixed Corresponding factory for this field.
     */
    public function getFactory()
    {
        # if factory has not yet been set
        if ($this->Factory === false) {
            # set factory based on field type
            switch ($this->type()) {
                case MetadataSchema::MDFTYPE_TREE:
                    $this->Factory = new ClassificationFactory($this->id());
                    break;

                case MetadataSchema::MDFTYPE_CONTROLLEDNAME:
                case MetadataSchema::MDFTYPE_OPTION:
                    $this->Factory = new ControlledNameFactory($this->id());
                    break;

                case MetadataSchema::MDFTYPE_USER:
                    $this->Factory = new UserFactory();
                    break;

                default:
                    $this->Factory = null;
                    break;
            }
        }

        return $this->Factory;
    }

    /**
     * Determine if a user can view a specified field in the absence of
     * a resource.
     * @param User $User User to check.
     * @param bool $AllowHooksToModify Should events should be signaled
     *        (OPTIONAL, default TRUE)
     * @return TRUE when the user can view this field, FALSE otherwise.
     */
    public function userCanView(User $User, bool $AllowHooksToModify = true): bool
    {
        $CacheKey = "View" .$User->id() ."-" .
            ($AllowHooksToModify ? "1" : "0");

        # see if we have a cached permission for this field and user
        if (!isset($this->PermissionCache[$CacheKey])) {
            if (!$this->enabled()) {
                # the field should not be viewed if it is disabled
                $this->PermissionCache[$CacheKey] = false;
            } else {
                $Schema = new MetadataSchema($this->schemaId());

                # otherwise, evaluate the perms
                $CheckResult =
                    $Schema->viewingPrivileges()->meetsRequirements($User) &&
                    $this->viewingPrivileges()->meetsRequirements($User);

                # and optionally
                if ($AllowHooksToModify) {
                    $SignalResult = (ApplicationFramework::getInstance())->SignalEvent(
                        "EVENT_FIELD_VIEW_PERMISSION_CHECK",
                        [
                            "Field" => $this,
                            "Resource" => null,
                            "User" => $User,
                            "CanView" => $CheckResult
                        ]
                    );
                    $CheckResult =  $SignalResult["CanView"];
                }

                $this->PermissionCache[$CacheKey] = $CheckResult;
            }
        }

        return $this->PermissionCache[$CacheKey];
    }

    /**
     * Get/set the list of SchemaIds that provide allowable values for
     * a reference field.
     * @param int|array $Ids SchemaId or array/ of SchemaIds that are allowed (OPTIONAL).
     * @return array List of allowed SchemaIds.
     */
    public function referenceableSchemaIds($Ids = null): array
    {
        # if a new value was provided, convert it to a string
        if ($Ids !== null) {
            if (is_array($Ids)) {
                $Ids = implode(",", $Ids);
            }
        }

        # update/retrieve the value
        $Value = $this->DB->updateValue("ReferenceableSchemaIds", $Ids);

        # and convert stored string to an array
        return explode(",", $Value);
    }

    /**
     * Get/set the list of privileges a User field requires for a specific
     *   user to be a valid user in that field (e.g., a field may require
     *   PRIV_SYSADMIN for users to be valid entries)
     * @param array $NewValue List of privilege Ids to require.
     * @return array Current setting
     */
    public function userPrivilegeRestrictions(array $NewValue = null)
    {
        # new value
        if ($NewValue !== null) {
            $NewValue = serialize((array) $NewValue);
        }

        $Value = $this->DB->updateValue("UserPrivilegeRestrictions", $NewValue);

        # value set
        if (strlen($Value)) {
            $Value = (array) unserialize($Value);
        } else {
            $Value = $this->userPrivilegeRestrictions([]);
        }

        return $Value;
    }

    /**
     * Get the class name (if any) for values stored in this field.
     * @return string|false Class with namespace or FALSE if no class
     *      associated with field values.
     */
    public function getClassForValues()
    {
        $ValueClasses = [
            MetadataSchema::MDFTYPE_CONTROLLEDNAME => "Metavus\\ControlledName",
            MetadataSchema::MDFTYPE_FILE => "Metavus\\File",
            MetadataSchema::MDFTYPE_IMAGE => "Metavus\\Image",
            MetadataSchema::MDFTYPE_OPTION => "Metavus\\ControlledName",
            MetadataSchema::MDFTYPE_REFERENCE => "Metavus\\Record",
            MetadataSchema::MDFTYPE_TREE => "Metavus\\Classification",
            MetadataSchema::MDFTYPE_USER => "Metavus\\User"
        ];
        return $ValueClasses[$this->type()] ?? false;
    }

    /**
     * Notify registered observers about the specified event.
     * Observer functions should have the following signature:
     *      function myObserver(
     *          int $Event,
     *          int $RecordId,
     *          MetadataField $Field,
     *          $Value): void
     * Field types that can support multiple values produce ADD and REMOVE
     * events, while field types that cannot support multiple values produce
     * SET and CLEAR events.
     * @param int $Event Event to notify about (EVENT_ constant).
     * @param int $RecordId ID of record to which event applies.
     * @param mixed $Value Value associated with event.
     */
    public function notifyObservers(int $Event, int $RecordId, $Value): void
    {
        $Args = [ $Event, $RecordId, $this, $Value ];
        $this->notifyObserversWithArgs($Event, $Args, $this->Id);
    }

    # ---- PUBLIC INTERFACE: UI Functions ------------------------------------
    # (to be migrated into UI settings in the future)

    /**
     * Get/set the width of text fields.
     * @param int $NewValue Updated value.
     * @return int Current setting.
     */
    public function textFieldSize(int $NewValue = null): int
    {
        if ($NewValue !== null && $this->UsesMultiLineTextEditing) {
            throw new InvalidArgumentException(
                "Attempt to set ".__METHOD__." for ".$this->typeAsName()." field "
                ."(Id=".$this->Id." '".$this->name()."')."
            );
        }
        return $this->DB->updateIntValue("TextFieldSize", $NewValue);
    }

    /**
     * Get/set the number of rows to display for a paragraph field.
     * @param int $NewValue Updated value.
     * @return int Current setting.
     */
    public function paragraphRows(int $NewValue = null): int
    {
        if ($NewValue !== null && !$this->UsesMultiLineTextEditing) {
            throw new InvalidArgumentException(
                "Attempt to set ".__METHOD__." for ".$this->typeAsName()." field "
                ."(Id=".$this->Id." '".$this->name()."')."
            );
        }
        return $this->DB->updateIntValue("ParagraphRows", $NewValue);
    }

    /**
     * Get/set the number of columns to display for a paragraph field.
     * @param int $NewValue Updated value.
     * @return int Current setting.
     */
    public function paragraphCols(int $NewValue = null): int
    {
        if ($NewValue !== null && !$this->UsesMultiLineTextEditing) {
            throw new InvalidArgumentException(
                "Attempt to set ".__METHOD__." for ".$this->typeAsName()." field "
                ."(Id=".$this->Id." '".$this->name()."')."
            );
        }
        return $this->DB->updateIntValue("ParagraphCols", $NewValue);
    }

    /**
     * Get/set if this field should enable WYSIWYG editing.
     * @param bool $NewValue Updated value (OPTIONAL).
     * @return bool Current setting.
     */
    public function useWysiwygEditor(bool $NewValue = null): bool
    {
        if ($NewValue !== null && !$this->UsesMultiLineTextEditing) {
            throw new InvalidArgumentException(
                "Attempt to set ".__METHOD__." for ".$this->typeAsName()." field "
                ."(Id=".$this->Id." '".$this->name()."')."
            );
        }
        return $this->DB->updateBoolValue("UseWysiwygEditor", $NewValue);
    }

    /**
     * Get/set if this field should be displayed as a list on the
     * advanced search page.
     * @param bool $NewValue Updated value (OPTIONAL).
     * @return bool Current setting.
     */
    public function displayAsListForAdvancedSearch(bool $NewValue = null): bool
    {
        if ($NewValue !== null && !$this->CanDisplayAsList) {
            throw new InvalidArgumentException(
                "Attempt to set ".__METHOD__." for ".$this->typeAsName()." field "
                ."(Id=".$this->Id." '".$this->name()."')."
            );
        }

        return $this->DB->updateBoolValue("DisplayAsListForAdvancedSearch", $NewValue);
    }

    /**
     * Get/set maximum depth of classifications to display in the list
     * view on the AdvancedSearch page.
     * @param int $NewValue Updated value (OPTIONAL).
     * @return int Current setting.
     */
    public function maxDepthForAdvancedSearch(int $NewValue = null): int
    {
        if ($NewValue !== null && $this->type() != MetadataSchema::MDFTYPE_TREE) {
            throw new InvalidArgumentException(
                "Attempt to set ".__METHOD__." for ".$this->typeAsName()." field "
                ."(Id=".$this->Id." '".$this->name()."')."
            );
        }
        return $this->DB->updateIntValue("MaxDepthForAdvancedSearch", $NewValue);
    }

    /**
     * Get/set the number of vocabulary terms above which the
     * editing interface will switch from a set of checkboxes or radio buttons
     * to a set of option lists.
     * @param int $NewValue Updated value (OPTIONAL).
     * @return int Current setting.
     */
    public function optionListThreshold(int $NewValue = null): int
    {
        if ($NewValue !== null && !$this->CanBeEditedWithDynamicOptionLists) {
            throw new InvalidArgumentException(
                "Attempt to set ".__METHOD__." for ".$this->typeAsName()." field "
                ."(Id=".$this->Id." '".$this->name()."')."
            );
        }

        return $this->DB->updateIntValue("OptionListThreshold", $NewValue);
    }

    /**
     * Get/set the number of vocabulary terms above which the
     * editing interface will switch from a set of option lists to a set of
     * incremental search elements.
     * @param int $NewValue Updated value (OPTIONAL).
     * @return int Current setting.
     */
    public function ajaxThreshold(int $NewValue = null): int
    {
        if ($NewValue !== null && !$this->CanBeEditedWithIncrementalSearch) {
            throw new InvalidArgumentException(
                "Attempt to set ".__METHOD__." for ".$this->typeAsName()." field "
                ."(Id=".$this->Id." '".$this->name()."')."
            );
        }

        return $this->DB->updateIntValue("AjaxThreshold", $NewValue);
    }

    /**
     * Get/set the maximum number of results to display in an AJAX
     * dropdown.
     * @param int $NewValue Updated value (OPTIONAL).
     * @return int Current setting.
     */
    public function numAjaxResults(int $NewValue = null): int
    {
        if ($NewValue !== null && !$this->CanBeEditedWithIncrementalSearch) {
            throw new InvalidArgumentException(
                "Attempt to set ".__METHOD__." for ".$this->typeAsName()." field "
                ."(Id=".$this->Id." '".$this->name()."')."
            );
        }

        return $this->DB->updateIntValue("NumAjaxResults", $NewValue);
    }

    /**
     * Get/set whether or not to display full value to anonymous users
     * @param bool $NewValue Updated value (OPTIONAL).
     * @return bool Current setting.
     */
    public function obfuscateValueForAnonymousUsers(bool $NewValue = null): bool
    {
        if ($NewValue !== null && !$this->CanBeObfuscated) {
            throw new InvalidArgumentException(
                "Attempt to set ".__METHOD__." for ".$this->typeAsName()." field "
                ."(Id=".$this->Id." '".$this->name()."')."
            );
        }
        return $this->DB->updateIntValue("ObfuscateValueForAnonymousUsers", $NewValue);
    }

    /**
     * Get field specification as XML.  The returned XML will not include
     * document begin or end tags and may not be ideally formatted.
     * @return string XML data.
     */
    public function getAsXml(): string
    {
        # set up XML writer
        $XOut = new XMLWriter();
        $XOut->openMemory();
        $XOut->setIndent(true);
        $XOut->setIndentString("    ");

        # for each possible field attribute
        foreach (self::getFieldAttributeList() as $AttribName => $AttribInfo) {
            # skip attrib if field type is excluded or not among included types
            if ((isset($AttribInfo["IncludedTypes"])
                        && !($this->type() & $AttribInfo["IncludedTypes"]))
                    || ((isset($AttribInfo["ExcludedTypes"])
                        && ($this->type() & $AttribInfo["ExcludedTypes"])))) {
                continue;
            }

            # retrieve value for attribute
            $GetFunction = $AttribInfo["GetFunction"] ?? "";
            $GetMethod = lcfirst($AttribName);
            if (is_callable($GetFunction)) {
                $Value = ($GetFunction)($this, $AttribName, $XOut);
            } elseif (is_callable([$this, $GetMethod])) {
                $Value = ([$this, $GetMethod])();     // @phpstan-ignore-line
            } else {
                throw new Exception("Invalid retrieval function for attribute \""
                        .$AttribName."\".");
            }

            # error out if value is not a type writeable to XML
            if (!is_string($Value) && !is_numeric($Value) && !is_bool($Value)) {
                throw new Exception("Illegal value for attribute \""
                        .$AttribName."\" for field \"".$this->name()."\".");
            }

            # add attribute to XML
            $Value = (string)$Value;
            if (strlen($Value)) {
                $XOut->writeElement($AttribName, $Value);
            }
        }

        # return generated XML to caller
        return $XOut->flush();
    }

    /**
     * Get list of field attributes.  The returned associative array
     * will contain some of the following for each attribute:
     *      "GetFunction" - Callable function that can be used
     *          to retrieve attribute value.  Function will be
     *          assumed to have the following signature:
     *          (MetadataField $Field, string $AttribName, XMLWriter $XOut)
     *          and should either return a value that can be written
     *          out via XMLWriter::writeElement() or should write any
     *          appropriate XML out using the $XOut argument and return
     *          an empty string.
     *      "IncludedTypes" - Metadata field type constants ORed
     *          together) for types that have the attribute.
     *      "ExcludedTypes" - Metadata field type constants ORed
     *          together) for types that do not have the attribute.
     * Additional notes:
     *  - If "GetFunction" is not supplied, the attribute name is
     *      assumed to also be (with the first character made lower case)
     *      the name of a MetadataField method that can be called to
     *      retrieve the attribute value.
     *  - Attributes can have either "IncludedTypes" or "ExcludedTypes"
     *      but should not have both.
     *  - Attributes that do not have an included or excluded types list
     *      are assumed to apply to all field types.
     * @return array Information about possible field attributes, with
     *      attribute names in CamelCase for the index.
     */
    public static function getFieldAttributeList(): array
    {
        $PrivFunc = function ($Field, $AttribName, $XOut) {
            $RetrievalFunc = [$Field, $AttribName];
            if (!is_callable($RetrievalFunc)) {
                throw new Exception("Privilege retrieval method \""
                        .$AttribName."\" not callable.");
            }

            $Privs = ($RetrievalFunc)();
            if ($Privs->isEmpty()) {
                return "";
            }

            $XOut->startElement($AttribName);
            $XOut->writeRaw("\n".$Privs->getAsXml($XOut));
            $XOut->endElement();
            return "";
        };

        return [
            "Name" => [
            ],
            "Type" => [
                "GetFunction" => function (MetadataField $Field) {
                    $ConstantName = StdLib::getConstantName(
                        "Metavus\\MetadataSchema",
                        $Field->type()
                    );
                    return substr($ConstantName, strlen("MDFTYPE_"));
                },
            ],
            "Owner" => [
                "GetFunction" => function (MetadataField $Field) {
                    $Owner = $Field->owner();
                    return (($Owner != "CWISCore") && ($Owner != "MetavusCore"))
                            ? $Owner : "";
                },
            ],
            "AjaxThreshold" => [
                "IncludedTypes" => MetadataSchema::MDFTYPE_CONTROLLEDNAME
                    | MetadataSchema::MDFTYPE_REFERENCE
                    | MetadataSchema::MDFTYPE_TREE,
            ],
            "AllowHTML" => [
                "IncludedTypes" => MetadataSchema::MDFTYPE_PARAGRAPH
                    | MetadataSchema::MDFTYPE_TEXT,
            ],
            "AllowMultiple" => [
                "IncludedTypes" => MetadataSchema::MDFTYPE_FILE
                    | MetadataSchema::MDFTYPE_IMAGE
                    | MetadataSchema::MDFTYPE_OPTION
                    | MetadataSchema::MDFTYPE_REFERENCE
                    | MetadataSchema::MDFTYPE_TREE
                    | MetadataSchema::MDFTYPE_USER,
            ],
            "AuthoringPrivileges" => [
                "GetFunction" => $PrivFunc,
            ],
            "CopyOnResourceDuplication" => [
            ],
            "DateFormat" => [
                "IncludedTypes" => MetadataSchema::MDFTYPE_DATE
                    | MetadataSchema::MDFTYPE_TIMESTAMP,
            ],
            "DefaultQualifier" => [
                "ExcludedTypes" => MetadataSchema::MDFTYPE_REFERENCE
                    | MetadataSchema::MDFTYPE_SEARCHPARAMETERSET
                    | MetadataSchema::MDFTYPE_USER,
            ],
            "DefaultValue" => [
                "GetFunction" => function (MetadataField $Field) {
                    if ($Field->type() != MetadataSchema::MDFTYPE_POINT) {
                        return $Field->defaultValue();
                    }
                    $Value = $Field->defaultValue();
                    if (($Value["X"] == "") || ($Value["Y"] == "")) {
                        return "";
                    }
                    return $Value["X"].",".$Value["Y"];
                },
                "IncludedTypes" => MetadataSchema::MDFTYPE_FLAG
                    | MetadataSchema::MDFTYPE_NUMBER
                    | MetadataSchema::MDFTYPE_POINT
                    | MetadataSchema::MDFTYPE_TEXT,
            ],
            "Description" => [
            ],
            "DisplayAsListForAdvancedSearch" => [
                "IncludedTypes" => MetadataSchema::MDFTYPE_TREE,
            ],
            "Editable" => [
            ],
            "EditingPrivileges" => [
                "GetFunction" => $PrivFunc,
            ],
            "EnableOnOwnerReturn" => [
            ],
            "Enabled" => [
            ],
            "FacetsShowOnlyTermsUsedInResults" => [
                "IncludedTypes" => MetadataSchema::MDFTYPE_CONTROLLEDNAME
                    | MetadataSchema::MDFTYPE_OPTION
                    | MetadataSchema::MDFTYPE_TREE,
            ],
            "FlagOffLabel" => [
                "IncludedTypes" => MetadataSchema::MDFTYPE_FLAG,
            ],
            "FlagOnLabel" => [
                "IncludedTypes" => MetadataSchema::MDFTYPE_FLAG,
            ],
            "HasItemLevelQualifiers" => [
                "ExcludedTypes" => MetadataSchema::MDFTYPE_REFERENCE
                    | MetadataSchema::MDFTYPE_SEARCHPARAMETERSET
                    | MetadataSchema::MDFTYPE_USER,
            ],
            "IncludeInAdvancedSearch" => [
                "ExcludedTypes" => MetadataSchema::MDFTYPE_POINT
                    | MetadataSchema::MDFTYPE_SEARCHPARAMETERSET,
            ],
            "IncludeInFacetedSearch" => [
                "IncludedTypes" => MetadataSchema::MDFTYPE_CONTROLLEDNAME
                    | MetadataSchema::MDFTYPE_OPTION
                    | MetadataSchema::MDFTYPE_TREE,
            ],
            "IncludeInKeywordSearch" => [
                "IncludedTypes" => MetadataSchema::MDFTYPE_CONTROLLEDNAME
                    | MetadataSchema::MDFTYPE_EMAIL
                    | MetadataSchema::MDFTYPE_FILE
                    | MetadataSchema::MDFTYPE_IMAGE
                    | MetadataSchema::MDFTYPE_NUMBER
                    | MetadataSchema::MDFTYPE_OPTION
                    | MetadataSchema::MDFTYPE_PARAGRAPH
                    | MetadataSchema::MDFTYPE_TEXT
                    | MetadataSchema::MDFTYPE_TREE
                    | MetadataSchema::MDFTYPE_URL
                    | MetadataSchema::MDFTYPE_USER,
            ],
            "IncludeInRecommender" => [
                "IncludedTypes" => MetadataSchema::MDFTYPE_CONTROLLEDNAME
                    | MetadataSchema::MDFTYPE_EMAIL
                    | MetadataSchema::MDFTYPE_FILE
                    | MetadataSchema::MDFTYPE_IMAGE
                    | MetadataSchema::MDFTYPE_NUMBER
                    | MetadataSchema::MDFTYPE_OPTION
                    | MetadataSchema::MDFTYPE_PARAGRAPH
                    | MetadataSchema::MDFTYPE_TEXT
                    | MetadataSchema::MDFTYPE_TREE
                    | MetadataSchema::MDFTYPE_URL
            ],
            "IncludeInSortOptions" => [
                "IncludedTypes" => MetadataSchema::MDFTYPE_DATE
                    | MetadataSchema::MDFTYPE_EMAIL
                    | MetadataSchema::MDFTYPE_NUMBER
                    | MetadataSchema::MDFTYPE_TEXT
                    | MetadataSchema::MDFTYPE_TIMESTAMP
                    | MetadataSchema::MDFTYPE_URL,
            ],
            "Instructions" => [
            ],
            "Label" => [
            ],
            "MaxDepthForAdvancedSearch" => [
                "IncludedTypes" => MetadataSchema::MDFTYPE_TREE,
            ],
            "MaxLength" => [
                "IncludedTypes" => MetadataSchema::MDFTYPE_EMAIL
                    | MetadataSchema::MDFTYPE_TEXT
                    | MetadataSchema::MDFTYPE_URL,
            ],
            "MaxValue" => [
                "IncludedTypes" => MetadataSchema::MDFTYPE_NUMBER,
            ],
            "MinValue" => [
                "IncludedTypes" => MetadataSchema::MDFTYPE_NUMBER,
            ],
            "NumAjaxResults" => [
                "IncludedTypes" => MetadataSchema::MDFTYPE_CONTROLLEDNAME
                    | MetadataSchema::MDFTYPE_REFERENCE
                    | MetadataSchema::MDFTYPE_TREE,
            ],
            "ObfuscateValueForAnonymousUsers" => [
                "IncludedTypes" => MetadataSchema::MDFTYPE_EMAIL,
            ],
            "OptionListThreshold" => [
                "IncludedTypes" => MetadataSchema::MDFTYPE_CONTROLLEDNAME
                    | MetadataSchema::MDFTYPE_TREE,
            ],
            "Optional" => [
                "ExcludedTypes" => MetadataSchema::MDFTYPE_FLAG,
            ],
            "ParagraphCols" => [
                "IncludedTypes" => MetadataSchema::MDFTYPE_PARAGRAPH,
            ],
            "ParagraphRows" => [
                "IncludedTypes" => MetadataSchema::MDFTYPE_PARAGRAPH,
            ],
            "PointDecimalDigits" => [
                "IncludedTypes" => MetadataSchema::MDFTYPE_POINT,
            ],
            "PointPrecision" => [
                "IncludedTypes" => MetadataSchema::MDFTYPE_POINT,
            ],
            "RecommenderWeight" => [
                "IncludedTypes" => MetadataSchema::MDFTYPE_CONTROLLEDNAME
                    | MetadataSchema::MDFTYPE_EMAIL
                    | MetadataSchema::MDFTYPE_FILE
                    | MetadataSchema::MDFTYPE_IMAGE
                    | MetadataSchema::MDFTYPE_NUMBER
                    | MetadataSchema::MDFTYPE_OPTION
                    | MetadataSchema::MDFTYPE_PARAGRAPH
                    | MetadataSchema::MDFTYPE_TEXT
                    | MetadataSchema::MDFTYPE_TREE
                    | MetadataSchema::MDFTYPE_URL
            ],
            "ReferenceableSchemaIds" => [
                "GetFunction" => function ($Field, $AttribName, $XOut) {
                    $SchemaIds = $Field->referenceableSchemaIds();
                    if (count($SchemaIds) == 0) {
                        return "";
                    }
                    $XOut->startElement($AttribName);
                    foreach ($SchemaIds as $SchemaId) {
                        $Schema = new MetadataSchema($SchemaId);
                        $XOut->writeElement("MetadataSchemaName", $Schema->name());
                    }
                    $XOut->endElement();
                    return "";
                },
                "IncludedTypes" => MetadataSchema::MDFTYPE_REFERENCE,
            ],
            "SearchGroupLogic" => [
                "IncludedTypes" => MetadataSchema::MDFTYPE_CONTROLLEDNAME
                    | MetadataSchema::MDFTYPE_OPTION
                    | MetadataSchema::MDFTYPE_TREE,
            ],
            "SearchWeight" => [
                "ExcludedTypes" => MetadataSchema::MDFTYPE_FLAG
                    | MetadataSchema::MDFTYPE_POINT
                    | MetadataSchema::MDFTYPE_SEARCHPARAMETERSET,
            ],
            "ShowQualifiers" => [
                "ExcludedTypes" => MetadataSchema::MDFTYPE_REFERENCE
                    | MetadataSchema::MDFTYPE_SEARCHPARAMETERSET
                    | MetadataSchema::MDFTYPE_USER,
            ],
            "TextFieldSize" => [
                "IncludedTypes" => MetadataSchema::MDFTYPE_TEXT
                    | MetadataSchema::MDFTYPE_EMAIL
                    | MetadataSchema::MDFTYPE_URL
                    | MetadataSchema::MDFTYPE_POINT
                    | MetadataSchema::MDFTYPE_DATE,
            ],
            "UpdateMethod" => [
                "IncludedTypes" => MetadataSchema::MDFTYPE_TIMESTAMP,
            ],
            "UseForOaiSets" => [
                "IncludedTypes" => MetadataSchema::MDFTYPE_CONTROLLEDNAME
                    | MetadataSchema::MDFTYPE_OPTION
                    | MetadataSchema::MDFTYPE_TREE,
            ],
            "UseWysiwygEditor" => [
                "IncludedTypes" => MetadataSchema::MDFTYPE_PARAGRAPH,
            ],
            "UserPrivilegeRestrictions" => [
                "GetFunction" => function ($Field, $AttribName, $XOut) {
                    $PrivIds = $Field->userPrivilegeRestrictions();
                    if (count($PrivIds) == 0) {
                        return "";
                    }
                    $PFactory = new PrivilegeFactory();
                    $XOut->startElement($AttribName);
                    foreach ($PrivIds as $PrivId) {
                        $XOut->writeElement(
                            "Privilege",
                            $PFactory->getPrivilegeConstantName($PrivId)
                        );
                    }
                    $XOut->endElement();
                    return "";
                },
                "IncludedTypes" => MetadataSchema::MDFTYPE_USER,
            ],
            "UsesQualifiers" => [
                "ExcludedTypes" => MetadataSchema::MDFTYPE_REFERENCE
                    | MetadataSchema::MDFTYPE_SEARCHPARAMETERSET
                    | MetadataSchema::MDFTYPE_USER,
            ],
            "ViewingPrivileges" => [
                "GetFunction" => $PrivFunc,
            ],
        ];
    }


    # ---- PRIVATE INTERFACE -------------------------------------------------
    private $DB;
    private $Id;
    private $ErrorStatus;
    private $AuthoringPrivileges;
    private $EditingPrivileges;
    private $ViewingPrivileges;
    private $PermissionCache;
    private $Factory = false;

    # field limitations (keep in alphabetical order)
    private $CanAllowMultiple = false;
    private $CanBeEditedWithDynamicOptionLists = false;
    # (Dynamic Option Lists = new option lists are dynamically added as needed
    #  after terms are selected)
    private $CanBeEditedWithIncrementalSearch = false;
    private $CanBeObfuscated = false;
    private $CanDisplayAsList = false;
    private $CanHaveMaxValue = false;
    private $CanHaveMinValue = false;
    private $CannotBeOptional = false;
    private $MustAllowMultiple = false;
    private $UsesMultiLineTextEditing = false;

    /**
     * Storage for metadata field information to reduce repeated DB queries.
     */
    private static $FieldCache = null;

    /**
     * A map of metadata field types to human-readable strings.
     * @var array $FieldTypeHumanEnums
     */
    public static $FieldTypeHumanEnums = [
        MetadataSchema::MDFTYPE_TEXT             => "Text",
        MetadataSchema::MDFTYPE_PARAGRAPH        => "Paragraph",
        MetadataSchema::MDFTYPE_NUMBER           => "Number",
        MetadataSchema::MDFTYPE_DATE             => "Date",
        MetadataSchema::MDFTYPE_TIMESTAMP        => "Timestamp",
        MetadataSchema::MDFTYPE_FLAG             => "Flag",
        MetadataSchema::MDFTYPE_TREE             => "Tree",
        MetadataSchema::MDFTYPE_CONTROLLEDNAME   => "Controlled Name",
        MetadataSchema::MDFTYPE_OPTION           => "Option",
        MetadataSchema::MDFTYPE_USER             => "User",
        MetadataSchema::MDFTYPE_IMAGE            => "Image",
        MetadataSchema::MDFTYPE_FILE             => "File",
        MetadataSchema::MDFTYPE_URL              => "URL",
        MetadataSchema::MDFTYPE_POINT            => "Point",
        MetadataSchema::MDFTYPE_REFERENCE        => "Reference",
        MetadataSchema::MDFTYPE_EMAIL            => "Email",
        MetadataSchema::MDFTYPE_SEARCHPARAMETERSET => "Search Parameter Set"
    ];

    # field type DB/PHP enum translations
    public static $FieldTypeDBEnums = [
        MetadataSchema::MDFTYPE_TEXT             => "Text",
        MetadataSchema::MDFTYPE_PARAGRAPH        => "Paragraph",
        MetadataSchema::MDFTYPE_NUMBER           => "Number",
        MetadataSchema::MDFTYPE_DATE             => "Date",
        MetadataSchema::MDFTYPE_TIMESTAMP        => "TimeStamp",
        MetadataSchema::MDFTYPE_FLAG             => "Flag",
        MetadataSchema::MDFTYPE_TREE             => "Tree",
        MetadataSchema::MDFTYPE_CONTROLLEDNAME   => "ControlledName",
        MetadataSchema::MDFTYPE_OPTION           => "Option",
        MetadataSchema::MDFTYPE_USER             => "User",
        MetadataSchema::MDFTYPE_IMAGE            => "Still Image",
        MetadataSchema::MDFTYPE_FILE             => "File",
        MetadataSchema::MDFTYPE_URL              => "Url",
        MetadataSchema::MDFTYPE_POINT            => "Point",
        MetadataSchema::MDFTYPE_REFERENCE        => "Reference",
        MetadataSchema::MDFTYPE_EMAIL            => "Email",
        MetadataSchema::MDFTYPE_SEARCHPARAMETERSET => "SearchParameterSet"
    ];

    public static $FieldTypeDBAllowedEnums = [
        MetadataSchema::MDFTYPE_TEXT             => "Text",
        MetadataSchema::MDFTYPE_PARAGRAPH        => "Paragraph",
        MetadataSchema::MDFTYPE_NUMBER           => "Number",
        MetadataSchema::MDFTYPE_DATE             => "Date",
        MetadataSchema::MDFTYPE_TIMESTAMP        => "TimeStamp",
        MetadataSchema::MDFTYPE_FLAG             => "Flag",
        MetadataSchema::MDFTYPE_TREE             => "Tree",
        MetadataSchema::MDFTYPE_CONTROLLEDNAME   => "ControlledName",
        MetadataSchema::MDFTYPE_OPTION           => "Option",
        MetadataSchema::MDFTYPE_USER             => "User",
        MetadataSchema::MDFTYPE_IMAGE            => "Still Image",
        MetadataSchema::MDFTYPE_FILE             => "File",
        MetadataSchema::MDFTYPE_URL              => "Url",
        MetadataSchema::MDFTYPE_POINT            => "Point",
        MetadataSchema::MDFTYPE_REFERENCE        => "Reference",
        MetadataSchema::MDFTYPE_EMAIL            => "Email",
        MetadataSchema::MDFTYPE_SEARCHPARAMETERSET => "SearchParameterSet"
    ];

    public static $FieldTypePHPEnums = [
        "Text"                   => MetadataSchema::MDFTYPE_TEXT,
        "Paragraph"              => MetadataSchema::MDFTYPE_PARAGRAPH,
        "Number"                 => MetadataSchema::MDFTYPE_NUMBER,
        "Date"                   => MetadataSchema::MDFTYPE_DATE,
        "TimeStamp"              => MetadataSchema::MDFTYPE_TIMESTAMP,
        "Flag"                   => MetadataSchema::MDFTYPE_FLAG,
        "Tree"                   => MetadataSchema::MDFTYPE_TREE,
        "ControlledName"         => MetadataSchema::MDFTYPE_CONTROLLEDNAME,
        "Option"                 => MetadataSchema::MDFTYPE_OPTION,
        "User"                   => MetadataSchema::MDFTYPE_USER,
        "Still Image"            => MetadataSchema::MDFTYPE_IMAGE,
        "File"                   => MetadataSchema::MDFTYPE_FILE,
        "Url"                    => MetadataSchema::MDFTYPE_URL,
        "Point"                  => MetadataSchema::MDFTYPE_POINT,
        "Reference"              => MetadataSchema::MDFTYPE_REFERENCE,
        "Email"                  => MetadataSchema::MDFTYPE_EMAIL,
        "SearchParameterSet"     => MetadataSchema::MDFTYPE_SEARCHPARAMETERSET
    ];

    public static $UpdateTypes = [
        self::UPDATEMETHOD_NOAUTOUPDATE   => "Do not update automatically",
        self::UPDATEMETHOD_ONRECORDCREATE => "Update on record creation",
        self::UPDATEMETHOD_BUTTON         => "Provide an update button",
        self::UPDATEMETHOD_ONRECORDEDIT   => "Update when record is edited",
        self::UPDATEMETHOD_ONRECORDCHANGE => "Update when record is changed",
        self::UPDATEMETHOD_ONRECORDRELEASE => "Update when record is released to public",
    ];

    /**
     * Create a new metadata field.
     * @param int $SchemaId ID of schema in which to place field.
     * @param int $FieldType Metadata field type.
     * @param string $FieldName Name of metadata field.
     * @param bool $Optional If FALSE, field must always have a value.
     *       (OPTIONAL, defaults to TRUE)
     * @param mixed $DefaultValue Default value for field.
     * @return MetadataField New MetadataField object.
     * @throws InvalidArgumentException When field type is invalid.
     * @throws InvalidArgumentException When field name is duplicates name of
     *       another existing field.
     */
    public static function create(
        int $SchemaId,
        int $FieldType,
        string $FieldName,
        bool $Optional = null,
        $DefaultValue = null
    ): self {
        # error out if field type is bad
        if (empty(self::$FieldTypeDBEnums[$FieldType])) {
            throw new InvalidArgumentException("Bad field type (" .$FieldType .").");
        }

        # error out if field name is duplicate
        $DB = new Database();
        $FieldName = trim($FieldName);
        $DuplicateCount = $DB->queryValue(
            "SELECT COUNT(*) AS RecordCount FROM MetadataFields"
                        ." WHERE FieldName = '" .addslashes($FieldName) ."'"
                        ." AND SchemaId = " .intval($SchemaId),
            "RecordCount"
        );
        if ($DuplicateCount > 0) {
            throw new InvalidArgumentException("Duplicate field name (" .$FieldName .").");
        }

        # grab current user ID
        $UserId = User::getCurrentUser()->Get("UserId");

        # normalize schema ID
        $Schema = new MetadataSchema($SchemaId);
        $SchemaId = $Schema->id();

        # begin with no privilege requirements (schema privileges will still apply)
        $PrivData = (new PrivilegeSet())->data();

        # lock DB tables and get next temporary field ID
        $DB->query("LOCK TABLES MetadataFields WRITE");
        $FieldId = $Schema->getNextTempItemId();

        # add field to MDF table in database
        $DB->query("INSERT INTO MetadataFields"
                ." (FieldId, SchemaId, FieldName, FieldType, LastModifiedById,"
                        ." Optional, AuthoringPrivileges, EditingPrivileges,"
                        ." ViewingPrivileges)"
                ." VALUES ("
                .intval($FieldId) .", "
                .intval($SchemaId) .","
                ." '" .addslashes($FieldName) ."',"
                ." '" .self::$FieldTypeDBEnums[$FieldType] ."', "
                .intval($UserId) .", "
                .($Optional ? "1" : "0") .","
                ."'" .$DB->escapeString($PrivData) ."',"
                ."'" .$DB->escapeString($PrivData) ."',"
                ."'" .$DB->escapeString($PrivData) ."')");

        # release DB tables
        $DB->query("UNLOCK TABLES");

        # nuke potentially stale cache information
        self::$FieldCache = null;

        # load field object
        $Field = new MetadataField($FieldId);

        # set field defaults
        $Field->setDefaults();

        # set the default value if specified
        if ($DefaultValue !== null) {
            $Field->defaultValue($DefaultValue);
        }

        # clear caches in MetadataSchema
        MetadataSchema::clearStaticCaches();

        # return newly-constructed field to caller
        return $Field;
    }

    /**
     * Create duplicate of field.  The new field will be a temporary instance,
     * so if it is to persist, IsTempItem() must be called on it with FALSE.
     * The only difference between the original and the duplicate (other than
     * their IDs and possibly the temporary status) is that the duplicate will
     * have "(duplicate YYMMDD-HHMMSS)" appended to the field name.
     * @return self New duplicate field.
     */
    public function duplicate(): self
    {
        # create new field
        $NewName = $this->name() ." (duplicate " .date("ymd-His") .")";
        $NewField = self::create($this->schemaId(), $this->type(), $NewName);

        # copy all attributes to database record for new field
        $TableName = "MetadataFields";
        $IdColumn = "FieldId";
        $SrcId = $this->id();
        $DstId = $NewField->id();
        $ColumnsToExclude = ["FieldName"];
        $this->DB->CopyValues(
            $TableName,
            $IdColumn,
            $SrcId,
            $DstId,
            $ColumnsToExclude
        );

        # clear caches in MetadataSchema
        MetadataSchema::clearStaticCaches();

        # reload new field and return to caller
        return new MetadataField($NewField->id());
    }

    /**
     * Object contstructor, used to load an existing metadata field.  To create
     * new fields, use
     * @param int $FieldId ID of metadata field to load.
     * @return object New MetadataField object.
     */
    public function __construct(int $FieldId)
    {
        # assume everything will be okay
        $this->ErrorStatus = MetadataSchema::MDFSTAT_OK;

        # check if we have cached field info
        $this->DB = new Database();
        if (self::$FieldCache === null) {
            # if not, retrieve field info from database
            $this->DB->query("SELECT * FROM MetadataFields");
            while ($Row = $this->DB->fetchRow()) {
                self::$FieldCache[$Row["FieldId"]] = $Row;
            }
        }

        # error if requested field did not exist
        if (!array_key_exists($FieldId, self::$FieldCache)) {
            throw new InvalidArgumentException("Invalid metadata field ID ("
                    .$FieldId .") from "
                    .StdLib::getMyCaller() .".");
        }
        $Row = self::$FieldCache[$FieldId];
        $this->Id = $FieldId;

        # set up parameters for database value update convenience methods
        $this->DB->setValueUpdateParameters(
            "MetadataFields",
            "FieldId = " .intval($this->Id),
            $Row
        );

        # if privileges have not yet been initialized
        if (!strlen(strval($this->DB->updateValue("AuthoringPrivileges")))) {
            # set default values for privileges from metadata schema
            $Schema = new MetadataSchema($Row["SchemaId"]);
            $this->authoringPrivileges($Schema->authoringPrivileges());
            $this->editingPrivileges($Schema->editingPrivileges());
            $this->viewingPrivileges($Schema->viewingPrivileges());
        } else {
            # set privileges from stored values
            $this->AuthoringPrivileges = new PrivilegeSet(
                $Row["AuthoringPrivileges"]
            );
            $this->EditingPrivileges = new PrivilegeSet(
                $Row["EditingPrivileges"]
            );
            $this->ViewingPrivileges = new PrivilegeSet(
                $Row["ViewingPrivileges"]
            );
        }

        # set field attributes
        $this->setFieldAttributes();
    }

    /**
     * The metadata field defaults that are the same for all field types.
     * @var array $CommonDefaults
     */
    public static $CommonDefaults = [
        "AllowMultiple" => false,
        "CopyOnResourceDuplication" => true,
        "DefaultQualifier" => null,
        "DefaultValue" => null,
        "Description" => null,
        "Editable" => true,
        "Enabled" => true,
        "FacetsShowOnlyTermsUsedInResults" => false,
        "HasItemLevelQualifiers" => false,
        "IncludeInAdvancedSearch" => false,
        "IncludeInFacetedSearch" => false,
        "IncludeInKeywordSearch" => false,
        "IncludeInRecommender" => false,
        "IncludeInSortOptions" => true,
        "Instructions" => null,
        "Label" => null,
        "Optional" => true,
        "RecommenderWeight" => 1,
        "SearchGroupLogic" => SearchEngine::LOGIC_OR,
        "ShowQualifiers" => false,
        "UpdateMethod" => "NoAutoUpdate",
        "UseForOaiSets" => false,
        "UserPrivilegeRestrictions" => [],
        "UsesQualifiers" => false,
    ];

    /**
     * The metadata field defaults that vary depending on the field type.
     * @var array $TypeBasedDefaults
     */
    public static $TypeBasedDefaults = [
        MetadataSchema::MDFTYPE_TEXT  => [
            "MaxLength" => 100,
            "SearchWeight" => 1,
            "TextFieldSize" => 50,
        ],
        MetadataSchema::MDFTYPE_PARAGRAPH  => [
            "AllowHTML" => false,
            "MaxLength" => 100,
            "ParagraphCols" => 50,
            "ParagraphRows" => 4,
            "SearchWeight" => 1,
            "UseWysiwygEditor" => false,
        ],
        MetadataSchema::MDFTYPE_NUMBER  => [
            "MaxLength" => 100,
            "MaxValue" => 9999,
            "MinValue" => 1,
            "SearchWeight" => 1,
            "TextFieldSize" => 4,
        ],
        MetadataSchema::MDFTYPE_DATE  => [
            "DateFormat" => null,
            "MaxLength" => 100,
            "SearchWeight" => 1,
            "TextFieldSize" => 10,
        ],
        MetadataSchema::MDFTYPE_TIMESTAMP  => [
            "MaxLength" => 100,
            "SearchWeight" => 1,
            "TextFieldSize" => 50,
        ],
        MetadataSchema::MDFTYPE_FLAG  => [
            "DefaultValue" => 0,
            "DisplayAsListForAdvancedSearch" => false,
            "FlagOffLabel" => "Off",
            "FlagOnLabel" => "On",
            "Optional" => false,
            "SearchWeight" => 1,
            "TextFieldSize" => 50,
        ],
        MetadataSchema::MDFTYPE_TREE  => [
            "AjaxThreshold" => 50,
            "AllowMultiple" => true,
            "DisplayAsListForAdvancedSearch" => false,
            "FacetsShowOnlyTermsUsedInResults" => true,
            "MaxDepthForAdvancedSearch" => 1,
            "MaxLength" => 100,
            "NumAjaxResults" => 50,
            "OptionListThreshold" => 25,
            "SearchWeight" => 1,
            "TextFieldSize" => 50,
        ],
        MetadataSchema::MDFTYPE_CONTROLLEDNAME  => [
            "AjaxThreshold" => 50,
            "AllowMultiple" => true,
            "FacetsShowOnlyTermsUsedInResults" => false,
            "MaxLength" => 100,
            "NumAjaxResults" => 50,
            "OptionListThreshold" => 25,
            "SearchWeight" => 3,
            "TextFieldSize" => 50,
        ],
        MetadataSchema::MDFTYPE_OPTION  => [
            "DisplayAsListForAdvancedSearch" => false,
            "FacetsShowOnlyTermsUsedInResults" => false,
            "SearchWeight" => 3,
            "TextFieldSize" => 50,
        ],
        MetadataSchema::MDFTYPE_USER  => [
            "DisplayAsListForAdvancedSearch" => false,
            "MaxLength" => 100,
            "SearchWeight" => 1,
            "TextFieldSize" => 50,
        ],
        MetadataSchema::MDFTYPE_IMAGE  => [
            "CopyOnResourceDuplication" => false,
            "MaxLength" => 100,
            "SearchWeight" => 1,
            "TextFieldSize" => 50,
        ],
        MetadataSchema::MDFTYPE_FILE  => [
            "AllowMultiple" => true,
            "CopyOnResourceDuplication" => false,
            "SearchWeight" => 1,
            "TextFieldSize" => 50,
        ],
        MetadataSchema::MDFTYPE_URL  => [
            "SearchWeight" => 1,
            "TextFieldSize" => 50,
            "MaxLength" => 255
        ],
        MetadataSchema::MDFTYPE_POINT  => [
            "DefaultValue" => ["X" => null, "Y" => null],
            "MaxLength" => 100,
            "PointDecimalDigits" => 5,
            "PointPrecision" => 8,
            "SearchWeight" => 1,
            "TextFieldSize" => 10,
        ],
        MetadataSchema::MDFTYPE_REFERENCE  => [
            "ReferenceableSchemaIds" => [MetadataSchema::SCHEMAID_DEFAULT],
            "SearchWeight" => 1,
            "TextFieldSize" => 50,
        ],
        MetadataSchema::MDFTYPE_EMAIL => [
            "MaxLength" => 100,
            "ObfuscateValueForAnonymousUsers" => false,
            "SearchWeight" => 1,
            "TextFieldSize" => 50,
        ],
        MetadataSchema::MDFTYPE_SEARCHPARAMETERSET => [
            "SearchWeight" => 0,
            "TextFieldSize" => 50,
        ]
    ];

    /**
     * Set defaults values for the field.
     */
    public function setDefaults()
    {
        # set defaults that are the same for every field and not overridden by
        # a type-specific version
        foreach (self::$CommonDefaults as $Key => $Value) {
            if (!isset(self::$TypeBasedDefaults[$this->type()][$Key])) {
                $this->$Key($Value);
            }
        }

        # set defaults that depend on the type of the field
        foreach (self::$TypeBasedDefaults[$this->type()] as $Key => $Value) {
            $this->$Key($Value);
        }

        # tweak the update method if dealing with the date of record creation
        if ($this->name() == "Date Of Record Creation") {
            $this->updateMethod("OnRecordCreate");
        }
    }

    /**
     * Get a default value for a specified Metadata Field parameter when the
     * field type is not yet known.
     * @paran string $ParamName Parameter to look up.
     * @return mixed Default value.
     */
    public static function getDefaultValue(string $ParamName)
    {
        # check for any type-specific values; prefer the first one found since
        # we don't actually know the field type yet
        foreach (self::$TypeBasedDefaults as $Type => $Values) {
            if (isset($Values[$ParamName])) {
                return $Values[$ParamName];
            }
        }

        if (isset(self::$CommonDefaults[$ParamName])) {
            return self::$CommonDefaults[$ParamName];
        }

        return null;
    }

    /**
     *  Remove field from database (only for use by MetadataSchema object).
     */
    public function drop()
    {
        StdLib::checkMyCaller(
            "Metavus\\MetadataSchema",
            "Attempt to update drop Metadata Field at %FILE%:%LINE%."
            ." (Fields may only be dropped by MetadataSchema.)"
        );

        # clear other database entries as appropriate for field type
        $DB = $this->DB;
        $DBFieldName = $this->dBFieldName();
        $Schema = new MetadataSchema($this->schemaId());
        switch (self::$FieldTypePHPEnums[$DB->updateValue("FieldType")]) {
            case MetadataSchema::MDFTYPE_TEXT:
            case MetadataSchema::MDFTYPE_PARAGRAPH:
            case MetadataSchema::MDFTYPE_NUMBER:
            case MetadataSchema::MDFTYPE_USER:
            case MetadataSchema::MDFTYPE_IMAGE:
            case MetadataSchema::MDFTYPE_TIMESTAMP:
            case MetadataSchema::MDFTYPE_URL:
            case MetadataSchema::MDFTYPE_FLAG:
            case MetadataSchema::MDFTYPE_EMAIL:
            case MetadataSchema::MDFTYPE_SEARCHPARAMETERSET:
                # remove field from resources table
                if ($DB->fieldExists("Records", $DBFieldName)) {
                    $DB->query("ALTER TABLE Records DROP COLUMN `" .$DBFieldName ."`");
                }
                break;

            case MetadataSchema::MDFTYPE_POINT:
                if ($DB->fieldExists("Records", $DBFieldName ."X")) {
                    $DB->query("ALTER TABLE Records DROP COLUMN `" .$DBFieldName ."X`");
                    $DB->query("ALTER TABLE Records DROP COLUMN `" .$DBFieldName ."Y`");
                }
                break;

            case MetadataSchema::MDFTYPE_DATE:
                # remove fields from resources table
                if ($DB->fieldExists("Records", $DBFieldName ."Begin")) {
                    $DB->query("ALTER TABLE Records "
                            ."DROP COLUMN `" .$DBFieldName ."Begin`");
                    $DB->query("ALTER TABLE Records "
                            ."DROP COLUMN `" .$DBFieldName ."End`");
                    $DB->query("ALTER TABLE Records "
                            ."DROP COLUMN `" .$DBFieldName ."Precision`");
                }
                break;

            case MetadataSchema::MDFTYPE_TREE:
                $CFactory = new ClassificationFactory($this->Id);
                # fetch in depth order so that children will be deleted before parents
                $ClassificationIds = $CFactory->getItemIds(
                    null,
                    false,
                    "Depth",
                    false
                );
                foreach ($ClassificationIds as $ClassificationId) {
                    $Classification = new Classification($ClassificationId);
                    $Classification->destroy(false, true, true);
                }
                break;

            case MetadataSchema::MDFTYPE_CONTROLLEDNAME:
            case MetadataSchema::MDFTYPE_OPTION:
                $CFactory = new ControlledNameFactory($this->Id);
                $ControlledNameIds = $CFactory->getItemIds();
                foreach ($ControlledNameIds as $ControlledNameId) {
                    $ControlledName = new ControlledName($ControlledNameId);
                    $ControlledName->destroy(true);
                }
                break;

            case MetadataSchema::MDFTYPE_FILE:
                # for each file associated with this field
                $DB->query("SELECT FileId FROM Files WHERE FieldId = " .$this->Id);
                while ($FileId = $DB->fetchRow()) {
                    # delete file
                    $File = new File(intval($FileId));
                    $File->destroy();
                }
                break;

            case MetadataSchema::MDFTYPE_REFERENCE:
                # remove any resource references for the field
                $DB->query("DELETE FROM ReferenceInts WHERE FieldId = " .$this->Id);
                break;
        }

        # remove field from database
        $DB->query("DELETE FROM MetadataFields "
                   ."WHERE FieldId = " .$this->Id);

        # remove any qualifier associations
        $DB->query("DELETE FROM FieldQualifierInts WHERE MetadataFieldId = " .$this->Id);

        # get the order objects the field is part of
        foreach (MetadataFieldOrder::getOrdersForSchema($Schema) as $Order) {
            # remove it if it's a direct descendant
            $Order->RemoveItem($this->id(), "Metavus\\MetadataField");

            # also make sure to remove it if it's part of a group
            foreach ($Order->GetItemIds() as $Item) {
                if ($Item["Type"] == "Metavus\\MetadataFieldGroup") {
                    $Group = new MetadataFieldGroup($Item["ID"]);
                    $Group->removeItem($this->id(), "Metavus\\MetadataField");
                }
            }
        }

        # nuke stale field cache
        self::$FieldCache = null;

        # clear caches in MetadataSchema
        MetadataSchema::clearStaticCaches();
    }

    /**
     * Edit the name and/or type of this Metadata Field.
     * @param mixed $NewName Name to use when renaming or NULL to leave
     *     the current name alone.
     * @param mixed $NewType New field type that this field should be
     *     converted into or NULL when no type conversion is desired.
     */
    private function modifyField($NewName = null, $NewType = null)
    {
        $DB = $this->DB;
        # grab old DB field name
        $OldDBFieldName = $this->dBFieldName();
        $OldFieldType = null;

        # if new field name supplied
        if ($NewName !== null) {
            # cache the old name for options and controllednames below
            $OldName = $DB->updateValue("FieldName");

            # store new field name
            $DB->updateValue("FieldName", $NewName);

            # get new database field name
            $NewDBFieldName = $this->dBFieldName();
        } else {
            # set new field name equal to old field name
            $NewDBFieldName = $OldDBFieldName;
        }

        # if new type supplied
        if ($NewType !== null) {
            # grab old field type
            $OldFieldType = self::$FieldTypePHPEnums[$DB->updateValue("FieldType")];

            # store new field type
            $DB->updateValue("FieldType", self::$FieldTypeDBEnums[$NewType]);
        }

        # nuke potentially stale cache information
        self::$FieldCache = null;

        # clear caches in MetadataSchema
        MetadataSchema::clearStaticCaches();

        # if this is not a temporary field
        if ($this->id() >= 0) {
            # have field reset its factory
            $this->Factory = false;

            # modify field in DB as appropriate for field type
            $FieldType = self::$FieldTypePHPEnums[$DB->updateValue("FieldType")];
            switch ($FieldType) {
                case MetadataSchema::MDFTYPE_PARAGRAPH:
                    # alter field declaration in Records table
                    $DB->query("ALTER TABLE Records CHANGE COLUMN `"
                            .$OldDBFieldName ."` `"
                            .$NewDBFieldName ."` MEDIUMTEXT DEFAULT NULL");
                    break;

                case MetadataSchema::MDFTYPE_TEXT:
                case MetadataSchema::MDFTYPE_URL:
                case MetadataSchema::MDFTYPE_EMAIL:
                    # alter field declaration in Records table
                    $DB->query("ALTER TABLE Records CHANGE COLUMN `"
                            .$OldDBFieldName ."` `"
                            .$NewDBFieldName ."` TEXT DEFAULT NULL");
                    break;

                case MetadataSchema::MDFTYPE_SEARCHPARAMETERSET:
                    # alter field declaration in Records table
                    $DB->query("ALTER TABLE Records CHANGE COLUMN `"
                            .$OldDBFieldName ."` `"
                            .$NewDBFieldName ."` BLOB DEFAULT NULL");
                    break;

                case MetadataSchema::MDFTYPE_NUMBER:
                    # alter field declaration in Records table
                    $DB->query("ALTER TABLE Records CHANGE COLUMN `"
                            .$OldDBFieldName ."` `"
                            .$NewDBFieldName ."` INT DEFAULT NULL");

                    break;

                case MetadataSchema::MDFTYPE_POINT:
                    $Precision = $this->DB->updateIntValue("PointPrecision");
                    $Digits    = $this->DB->updateIntValue("PointDecimalDigits");
                    $DB->query("ALTER TABLE Records CHANGE COLUMN "
                               ."`" .$OldDBFieldName ."X` "
                               ."`" .$NewDBFieldName ."X`" .
                               " DECIMAL(" .$Precision ."," .$Digits .")");
                    $DB->query("ALTER TABLE Records CHANGE COLUMN "
                               ."`" .$OldDBFieldName ."Y` "
                               ."`" .$NewDBFieldName ."Y`" .
                               " DECIMAL(" .$Precision ."," .$Digits .")");
                    break;

                case MetadataSchema::MDFTYPE_FLAG:
                    # alter field declaration in Records table
                    $DB->query("ALTER TABLE Records CHANGE COLUMN `"
                               .$OldDBFieldName ."` `"
                               .$NewDBFieldName ."` INT"
                               ." DEFAULT " .intval($this->defaultValue()));

                    # set any unset values to default
                    $DB->query("UPDATE Records SET `" .$NewDBFieldName
                            ."` = " .intval($this->defaultValue())
                            ." WHERE `" .$NewDBFieldName ."` IS NULL");
                    break;

                case MetadataSchema::MDFTYPE_DATE:
                    # if new type supplied and new type is different from old
                    if (($NewType !== null) && ($NewType != $OldFieldType)) {
                        # if old type was time stamp
                        if ($OldFieldType == MetadataSchema::MDFTYPE_TIMESTAMP) {
                            # change time stamp field in resources table to begin date
                            $DB->query("ALTER TABLE Records CHANGE COLUMN `"
                                       .$OldDBFieldName ."` `"
                                       .$NewDBFieldName ."Begin` DATE "
                                       ."DEFAULT NULL");

                            # add end date and precision fields
                            $DB->query("ALTER TABLE Records "
                                    ."ADD COLUMN `" .$NewDBFieldName ."End` DATE");
                            $DB->query("ALTER TABLE Records "
                                    ."ADD COLUMN `" .$NewDBFieldName ."Precision`"
                                    ."INT DEFAULT NULL");


                            # set precision to reflect time stamp content
                            $DB->query("UPDATE Records "
                                    ."SET `" .$NewDBFieldName ."Precision` = "
                                    .(Date::PRE_BEGINYEAR | Date::PRE_BEGINMONTH
                                    | Date::PRE_BEGINDAY));
                        } else {
                            throw new Exception("ERROR: Attempt to convert metadata field "
                                    ."to date from type other than timestamp");
                        }
                    } else {
                        # change name of fields
                        $DB->query("ALTER TABLE Records CHANGE COLUMN `"
                                   .$OldDBFieldName ."Begin` `"
                                   .$NewDBFieldName ."Begin` DATE "
                                   ."DEFAULT NULL");
                        $DB->query("ALTER TABLE Records CHANGE COLUMN `"
                                   .$OldDBFieldName ."End` `"
                                   .$NewDBFieldName ."End` DATE "
                                   ."DEFAULT NULL");
                        $DB->query("ALTER TABLE Records CHANGE COLUMN `"
                                   .$OldDBFieldName ."Precision` `"
                                   .$NewDBFieldName ."Precision` INT "
                                   ."DEFAULT NULL");
                    }
                    break;

                case MetadataSchema::MDFTYPE_TIMESTAMP:
                    # if new type supplied and new type is different from old
                    if (($NewType !== null) && ($NewType != $OldFieldType)) {
                        # if old type was date
                        if ($OldFieldType == MetadataSchema::MDFTYPE_DATE) {
                            # change begin date field in resource table to time stamp
                            $DB->query("ALTER TABLE Records CHANGE COLUMN `"
                                       .$OldDBFieldName ."Begin` `"
                                       .$NewDBFieldName ."` DATETIME "
                                       ."DEFAULT NULL");

                            # drop end date and precision fields
                            $DB->query("ALTER TABLE Records DROP COLUMN `"
                                       .$OldDBFieldName ."End`");
                            $DB->query("ALTER TABLE Records DROP COLUMN `"
                                       .$OldDBFieldName ."Precision`");
                        } else {
                            throw new Exception("ERROR: Attempt to convert metadata field to "
                                    ."time stamp from type other than date");
                        }
                    } else {
                        # change name of field
                        $DB->query("ALTER TABLE Records CHANGE COLUMN `"
                                   .$OldDBFieldName ."` `"
                                   .$NewDBFieldName ."` DATETIME "
                                   ."DEFAULT NULL");
                    }
                    break;

                case MetadataSchema::MDFTYPE_TREE:
                    # if new type supplied and new type is different from old
                    if (($NewType !== null) && ($NewType != $OldFieldType)) {
                        # if old type was controlled name or option
                        if (($OldFieldType == MetadataSchema::MDFTYPE_CONTROLLEDNAME) ||
                            ($OldFieldType == MetadataSchema::MDFTYPE_OPTION)) {
                            $this->convertTreesAndControlledNames($NewType);
                        } else {
                            throw new Exception("ERROR: Attempt to convert metadata field to "
                                ."tree from type other than controlled name or option");
                        }
                    }
                    break;

                case MetadataSchema::MDFTYPE_CONTROLLEDNAME:
                case MetadataSchema::MDFTYPE_OPTION:
                    # if new type supplied and new type is different from old
                    if (($NewType !== null) && ($NewType != $OldFieldType)) {
                        # if old type was tree
                        $ValidConversionTypes = [
                            MetadataSchema::MDFTYPE_CONTROLLEDNAME,
                            MetadataSchema::MDFTYPE_OPTION
                        ];
                        if ($OldFieldType == MetadataSchema::MDFTYPE_TREE) {
                            $this->convertTreesAndControlledNames($NewType);
                        } elseif (!in_array($OldFieldType, $ValidConversionTypes)) {
                            $OldTypeName = self::$FieldTypeHumanEnums[$OldFieldType];
                            $NewTypeName = self::$FieldTypeHumanEnums[$NewType];
                            throw new Exception(
                                "ERROR: Attempt to convert metadata field to "
                                .$NewTypeName." from ".$OldTypeName.", can only convert from"
                                ." Tree, Controlled Name, or Option."
                            );
                        }
                    }
                    break;

                case MetadataSchema::MDFTYPE_REFERENCE:
                case MetadataSchema::MDFTYPE_IMAGE:
                case MetadataSchema::MDFTYPE_FILE:
                    break;
            }

            # if qualifier DB field exists
            if ($DB->fieldExists("Records", $OldDBFieldName ."Qualifier")) {
                # rename qualifier DB field
                $DB->query("ALTER TABLE Records CHANGE COLUMN `"
                           .$OldDBFieldName ."Qualifier` `"
                           .$NewDBFieldName ."Qualifier` INT ");
            }
        }
    }

    /**
     * Normalize field name for use as database field name.
     * @param string $Name Metadata field name to normalize.
     * @return string DB-safe name.
     */
    private function normalizeFieldNameForDB(string $Name): string
    {
        return preg_replace("/[^a-z0-9]/i", "", $Name)
                .(($this->schemaId() != MetadataSchema::SCHEMAID_DEFAULT)
                        ? $this->schemaId() : "");
    }

    /**
     * Add any necessary database fields and/or entries.
     */
    private function addDatabaseFields()
    {
        $DB = $this->DB;
        $BaseColName = $this->dBFieldName();

        # set up field(s) based on field type
        $Queries = [];
        $ColumnTypes = [];
        switch ($this->type()) {
            case MetadataSchema::MDFTYPE_PARAGRAPH:
                $Queries[$BaseColName] = "ALTER TABLE Records ADD COLUMN `" .$BaseColName
                        ."` MEDIUMTEXT DEFAULT NULL";
                $ColumnTypes[$BaseColName] = "MEDIUMTEXT";
                break;

            case MetadataSchema::MDFTYPE_TEXT:
            case MetadataSchema::MDFTYPE_URL:
            case MetadataSchema::MDFTYPE_EMAIL:
                $Queries[$BaseColName] = "ALTER TABLE Records ADD COLUMN `" .$BaseColName
                        ."` TEXT DEFAULT NULL";
                $ColumnTypes[$BaseColName] = "TEXT";
                break;

            case MetadataSchema::MDFTYPE_SEARCHPARAMETERSET:
                $Queries[$BaseColName] = "ALTER TABLE Records ADD COLUMN `" .$BaseColName
                        ."` BLOB DEFAULT NULL";
                $ColumnTypes[$BaseColName] = "BLOB";
                break;

            case MetadataSchema::MDFTYPE_NUMBER:
                $Queries[$BaseColName] = "ALTER TABLE Records ADD COLUMN `" .$BaseColName
                        ."` INT DEFAULT NULL";
                $ColumnTypes[$BaseColName] = "INT";
                break;

            case MetadataSchema::MDFTYPE_POINT:
                $ColType = "DECIMAL(" .$this->DB->updateIntValue("PointPrecision")
                        ."," .$this->DB->updateIntValue("PointDecimalDigits") .")";
                foreach ([$BaseColName ."X", $BaseColName ."Y"] as $ColName) {
                    $Queries[$ColName] = "ALTER TABLE Records ADD COLUMN `"
                            .$ColName ."` " .$ColType ." DEFAULT NULL";
                    $ColumnTypes[$ColName] = $ColType;
                }
                break;

            case MetadataSchema::MDFTYPE_FLAG:
                $Queries[$BaseColName] = "ALTER TABLE Records ADD COLUMN `" .$BaseColName
                        ."` INT DEFAULT NULL";
                $ColumnTypes[$BaseColName] = "INT";
                break;

            case MetadataSchema::MDFTYPE_DATE:
                foreach ([$BaseColName ."Begin", $BaseColName ."End"] as $ColName) {
                    $Queries[$ColName] = "ALTER TABLE Records ADD COLUMN `"
                            .$ColName ."` DATE DEFAULT NULL";
                    $ColumnTypes[$ColName] = "DATE";
                }
                $Queries[$BaseColName ."Precision"] = "ALTER TABLE Records "
                        ."ADD COLUMN `" .$BaseColName ."Precision` INT DEFAULT NULL";
                $ColumnTypes[$BaseColName ."Precision"] = "INT";
                break;

            case MetadataSchema::MDFTYPE_TIMESTAMP:
                $Queries[$BaseColName] = "ALTER TABLE Records ADD COLUMN `" .$BaseColName
                           ."` DATETIME DEFAULT NULL";
                $ColumnTypes[$BaseColName] = "DATETIME";
                break;
        }

        # execute any needed database queries
        foreach ($Queries as $ColName => $Query) {
            if ($DB->fieldExists("Records", $ColName)) {
                $CurrentColType = strtoupper($DB->getFieldType("Records", $ColName));
                if ($CurrentColType != $ColumnTypes[$ColName]) {
                    throw new Exception("Found existing column in Records table"
                            ." with incorrect type (found: " .$CurrentColType
                            .", expected: " .$ColumnTypes[$ColName] ." when adding"
                            ." column(s) for metadata field \"" .$this->name() ."\".");
                }
                continue;
            }
            if ($DB->query($Query) === false) {
                throw new Exception("Query failed when adding fields to database (\""
                        .$Query ."\").");
            }
        }
    }

    /**
     * Add terms to a controlled vocabulary field (ControlledName,
     * Option, Classification).
     * @param array $TermNames Array of term names to add.
     * @param Qualifier $Qualifier Qualifier for newly added terms.
     * @return int Number of terms added.
     */
    private function addTerms(array $TermNames, Qualifier $Qualifier = null): int
    {
        $Factory = $this->getFactory();

        if ($Factory === null) {
            throw new Exception(
                "Attempt to add terms to a field that does not "
                ."support them."
            );
        }

        # for each supplied term name
        $ItemClassName = $Factory->getItemClassName();
        $TermCount = 0;

        foreach ($TermNames as $Name) {
            # if term does not exist with this name
            $Name = trim($Name);
            if ($Factory->getItemByName($Name) === null) {
                # add term
                $NewTerm = ($ItemClassName == "Metavus\\ControlledName") ?
                         ControlledName::create($Name, $this->id()) :
                         Classification::create($Name, $this->id());

                $TermCount++;

                # assign qualifier to term if supplied
                if ($Qualifier !== null) {
                    $NewTerm->Qualifier($Qualifier);
                }
            }
        }

        # return count of terms added to caller
        return $TermCount;
    }

    /**
     * Modify the database to convert between Tree fields and
     *     ControlledName/Option fields.
     * @param int $NewType New field type that this field should be
     *     converted into.
     */
    private function convertTreesAndControlledNames($NewType)
    {
        if ($NewType == MetadataSchema::MDFTYPE_TREE) {
            $OldType = "ControlledName";
            $OldTable = "ControlledNames";
            $OldIdField = "ControlledNameId";
            $OldNameField = "ControlledName";
            $OldIntsTable = "RecordNameInts";
            $NewType = "Classification";
            $NewIdField = "ClassificationId";
            $NewIntsTable = "RecordClassInts";
        } else {
            $OldType = "Classification";
            $OldTable = "Classifications";
            $OldIdField = "ClassificationId";
            $OldNameField = "ClassificationName";
            $OldIntsTable = "RecordClassInts";
            $NewType = "ControlledName";
            $NewIdField = "ControlledNameId";
            $NewIntsTable = "RecordNameInts";
        }
        $DB = $this->DB;

        # load info about all old terms for the field
        $Query = "SELECT " .$OldIdField .", " .$OldNameField
                ." FROM " .$OldTable
                ." WHERE FieldId = " .$this->id();
        if ($OldType == "ControlledName") {
            $Query .= " ORDER BY LENGTH(ControlledName)";
        }
        $DB->query($Query);
        $AllEntries = $DB->fetchRows();

        # return if there are no terms to convert
        if (!count($AllEntries)) {
            return;
        }

        # get names of terms in the default value, should only apply
        # to Trees and Options
        $DefaultValue = $this->defaultValue();
        if (!empty($DefaultValue)) {
            $DefaultNames = [];
            $OldClass = __NAMESPACE__."\\".$OldType;
            if (!is_array($DefaultValue)) {
                $DefaultValue = [ $DefaultValue ];
            }
            foreach ($DefaultValue as $Id) {
                $DefaultNames[] = (new $OldClass($Id))->name();
            }
        }

        # create entries for the new type, building up a mapping of Ids as we go
        $OldToNewMap = [];
        foreach ($AllEntries as $Entry) {
            $NewClass = __NAMESPACE__ ."\\" .$NewType;

            $NewEntry = $NewClass::create($Entry[$OldNameField], $this->id());
            $OldToNewMap[$Entry[$OldIdField]] = $NewEntry->id();
        }

        # get all associations for old field
        $SelectQueryBase = "SELECT RecordId, " .$OldIdField
                ." FROM " .$OldIntsTable
                ." WHERE " .$OldIdField ." IN (";
        $MaxSelectQueryValueLength = Database::getMaxQueryLength()
                - strlen($SelectQueryBase);
        $SelectQueryValues = [];
        $SelectQueryValueLength = 0;
        $Associations = [];

        # iterate over values to select
        foreach (array_keys($OldToNewMap) as $OldValue) {
            # construct SQL values
            $SelectValueLength = strlen(strval($OldValue)) + 1; # add 1 for comma

            # if adding these values would make the query too long, run query
            #       on current values (add 1 for closing parenthesis)
            if (($SelectQueryValueLength + $SelectValueLength + 1) >= $MaxSelectQueryValueLength) {
                $DB->query($SelectQueryBase .implode(",", $SelectQueryValues) .")");
                $Associations = array_merge($Associations, $DB->fetchRows());
                $SelectQueryValues = [];
                $SelectQueryValueLength = 0;
            }

            # add values to queue
            $SelectQueryValues[] = $OldValue;
            $SelectQueryValueLength += $SelectValueLength;
        }

        # if values left to insert, insert them
        if (count($SelectQueryValues)) {
            $DB->query($SelectQueryBase .implode(",", $SelectQueryValues) .")");
        }
        $Associations = array_merge($Associations, $DB->fetchRows());

        # iterate over the assocations using mapping
        $NewAssociations = [];
        foreach ($Associations as $Association) {
            $NewAssociations[] = [
                $Association["RecordId"],
                $OldToNewMap[$Association[$OldType ."Id"]]
            ];
            (new Record($Association["RecordId"]))->queueSearchAndRecommenderUpdate();
        }
        $InsertQueryBase = "INSERT INTO " .$NewIntsTable
                ." (RecordId, " .$NewIdField .") VALUES ";
        $MaxInsertQueryValueLength = Database::getMaxQueryLength() - strlen($InsertQueryBase);
        $InsertQueryValues = [];
        $InsertQueryValueLength = 0;

        # iterate over new values to insert
        foreach ($NewAssociations as $NewAssociation) {
            # construct SQL values
            $InsertValues =
                "("
                .$NewAssociation[0] .","
                .$NewAssociation[1]
                .")";
            $InsertValueLength = strlen($InsertValues) + 1; # add 1 for comma

            # if adding these values would make the query too long, run query on current values
            if ($InsertQueryValueLength + $InsertValueLength >= $MaxInsertQueryValueLength) {
                $DB->query($InsertQueryBase .implode(",", $InsertQueryValues));
                $InsertQueryValues = [];
                $InsertQueryValueLength = 0;
            }

            # add values to queue
            $InsertQueryValues[] = $InsertValues;
            $InsertQueryValueLength += $InsertValueLength;
        }

        # if values left to insert, insert them
        if (count($InsertQueryValues)) {
            $DB->query($InsertQueryBase .implode(",", $InsertQueryValues));
        }

        # delete old associations from old intersection table
        $DeleteQueryBase = "DELETE FROM " .$OldIntsTable
                ." WHERE " .$OldIdField ." IN (";
        $MaxDeleteQueryValueLength = Database::getMaxQueryLength()
                - strlen($DeleteQueryBase);
        $DeleteQueryValues = [];
        $DeleteQueryValueLength = 0;

        # iterate over values to select
        foreach (array_keys($OldToNewMap) as $OldValue) {
            # construct SQL values
            $DeleteValueLength = strlen(strval($OldValue)) + 1; # add 1 for comma

            # if adding these values would make the query too long, run query
            #       on current values (add 1 for closing parenthesis)
            if (($DeleteQueryValueLength + $DeleteValueLength + 1) >= $MaxDeleteQueryValueLength) {
                $DB->query($DeleteQueryBase .implode(",", $DeleteQueryValues) .")");
                $DeleteQueryValues = [];
                $DeleteQueryValueLength = 0;
            }

            # add values to queue
            $DeleteQueryValues[] = $OldValue;
            $DeleteQueryValueLength += $DeleteValueLength;
        }

        # if values left to insert, insert them
        if (count($DeleteQueryValues)) {
            $DB->query($DeleteQueryBase .implode(",", $DeleteQueryValues) .")");
        }

        # delete values for old type
        $DB->query("DELETE FROM " .$OldTable ." WHERE FieldId = " .$this->id());
        if ($OldType == "Classification") {
            Classification::clearCaches();
        }

        $Factory = $this->getFactory();

        # update classification count if result is tree field
        if ($Factory instanceof ClassificationFactory) {
            $Factory->recalculateAllResourceCounts();
        }

        if ($NewType == MetadataSchema::MDFTYPE_CONTROLLEDNAME) {
            $this->defaultValue(false);
        } elseif (isset($DefaultNames)) {
            # update new default value Ids if any
            $NewIds = [];
            foreach ($DefaultNames as $DefaultName) {
                $NewIds[] = ($Factory->getItemByName($DefaultName))->id();
            }
            $this->defaultValue($NewIds);
        }
    }

    /**
     * Set internal variables that convey characteristics of this field type.
     */
    private function setFieldAttributes()
    {
        # reset all field limitation attributes to false (the default)
        $FieldAttributes = [
            "CanAllowMultiple",
            "CanBeEditedWithDynamicOptionLists",
            "CanBeEditedWithIncrementalSearch",
            "CanBeObfuscated",
            "CanDisplayAsList",
            "CanHaveMaxValue",
            "CanHaveMinValue",
            "CannotBeOptional",
            "MustAllowMultiple",
            "UsesMultiLineTextEditing",
        ];
        foreach ($FieldAttributes as $Key) {
            $this->$Key = false;
        }

        $SetToTrue = [
            "CanAllowMultiple" => [
                MetadataSchema::MDFTYPE_TREE,
                MetadataSchema::MDFTYPE_CONTROLLEDNAME,
                MetadataSchema::MDFTYPE_OPTION,
                MetadataSchema::MDFTYPE_USER,
                MetadataSchema::MDFTYPE_IMAGE,
                MetadataSchema::MDFTYPE_FILE,
                MetadataSchema::MDFTYPE_REFERENCE,
            ],
            "CanBeEditedWithDynamicOptionLists" => [
                MetadataSchema::MDFTYPE_TREE,
                MetadataSchema::MDFTYPE_CONTROLLEDNAME,
            ],
            "CanBeEditedWithIncrementalSearch" => [
                MetadataSchema::MDFTYPE_TREE,
                MetadataSchema::MDFTYPE_CONTROLLEDNAME,
            ],
            "CanBeObfuscated" => [
                MetadataSchema::MDFTYPE_EMAIL,
            ],
            "CanDisplayAsList" => [
                MetadataSchema::MDFTYPE_OPTION,
                MetadataSchema::MDFTYPE_TREE,
                MetadataSchema::MDFTYPE_FLAG,
                MetadataSchema::MDFTYPE_USER,
            ],
            "CanHaveMaxValue" => [
                MetadataSchema::MDFTYPE_NUMBER,
            ],
            "CanHaveMinValue" => [
                MetadataSchema::MDFTYPE_NUMBER,
            ],
            "CannotBeOptional" => [
                MetadataSchema::MDFTYPE_FLAG,
            ],
            "MustAllowMultiple" => [
                MetadataSchema::MDFTYPE_CONTROLLEDNAME,
            ],
            "UsesMultiLineTextEditing" => [
                MetadataSchema::MDFTYPE_PARAGRAPH,
            ],
        ];
        foreach ($SetToTrue as $Key => $AllowedTypes) {
            if (in_array($this->type(), $AllowedTypes)) {
                $this->$Key = true;
            }
        }
    }
}
