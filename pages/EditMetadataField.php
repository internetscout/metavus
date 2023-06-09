<?PHP
#
#   FILE: EditMetadataField.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2012-2021 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus;

use Exception;
use ScoutLib\ApplicationFramework;
use ScoutLib\Database;
use ScoutLib\StdLib;

# ----- EXPORTED FUNCTIONS ---------------------------------------------------

/**
 * Transform an array of field type enumerations to an array of human-readable
 * strings.
 * @param array $Types Array of field type enumerations
 * @return array array of field human-readable strings
 */
function MakeTypesHumanReadable(array $Types)
{
    $Strings = MetadataField::$FieldTypeHumanEnums;

    foreach ($Types as $Constant => $Type) {
        $Types[$Constant] = StdLib::getArrayValue($Strings, $Constant, "Unknown");
    }

    return $Types;
}

/**
 * Get the list of all available qualifiers.
 * @return array list of all available qualifiers
 */
function GetQualifierList()
{
    $QualifierFactory = new QualifierFactory();
    $Qualifiers = $QualifierFactory->GetItemNames();

    return $Qualifiers;
}

/**
 * Get the first error from the given list of errors.
 * @param array $Errors List of errors
 * @return string|null the first error or NULL if none exist
 */
function GetFirstError(array $Errors)
{
    reset($Errors);
    return current($Errors) !== false ? current($Errors) : null;
}

# ----- LOCAL FUNCTIONS ------------------------------------------------------

/**
 * Load the field with the given field ID, checking that the field is valid.
 * @param int $Id Metadata field ID
 * @return MetadataField|null metadata field object if successful, else NULL
 */
function LoadMetadataField($Id)
{
    # the schema ID is unnecessary here
    $Field = new MetadataField($Id);

    # make sure the field is valid
    if ($Field->Status() !== MetadataSchema::MDFSTAT_OK) {
        return null;
    }

    return $Field;
}

/**
 * Load the qualifier with the given ID, checking that it is valid.
 * @param int|false $Id Qualifier ID or FALSE if no qualifier available.
 * @return Qualifier|null qualifier object if successful, else NULL
 */
function loadQualifier($Id)
{
    # make sure the ID looks valid before attempting to load the qualifier
    if (!$Id || $Id == "--") {
        return null;
    }

    if (!Qualifier::ItemExists($Id)) {
        return null;
    } else {
        return new Qualifier($Id);
    }
}

/**
 * Get the metadata field defaults for metadata fields with the given type.
 * @param int $Type Field type to assume, text is used if invalid
 * @return array metadata field defaults
 */
function GetFieldDefaults($Type)
{
    $Schema = $GLOBALS["H_Schema"];

    # get the type-based defaults to use, defaulting to text ones if necessary
    $TypeBasedDefaults = StdLib::getArrayValue(
        MetadataField::$TypeBasedDefaults,
        $Type,
        MetadataField::$TypeBasedDefaults[MetadataSchema::MDFTYPE_TEXT]
    );

    # merge the lists
    $Defaults = array_merge(MetadataField::$CommonDefaults, $TypeBasedDefaults);

    # not a field per se because it requires additional actions
    $Defaults["AssociatedQualifierList"] = array();
    $Defaults["Type"] = MetadataSchema::MDFTYPE_TEXT;
    $Defaults["Name"] = null;

    # privileges
    $Defaults["ViewingPrivilegesLogic"] = "AND";
    $Defaults["ViewingPrivileges"] = new PrivilegeSet();
    $Defaults["AuthoringPrivilegesLogic"] = "AND";
    $Defaults["AuthoringPrivileges"] = new PrivilegeSet();
    $Defaults["EditingPrivilegesLogic"] = "AND";
    $Defaults["EditingPrivileges"] = new PrivilegeSet();

    return $Defaults;
}

/**
 * Extract the user-provided field values from the given array of values.
 * Removes the "F_" prefix from the keys of the values.
 * @param array $Values Array of values, a subset of which are the field values
 * @return array extracted user-provided field values
 */
function ExtractFieldInput(array $Values)
{
    $Input = array();

    # extract form values
    foreach ($Values as $Key => $Value) {
        if (substr($Key, 0, 2) == "F_") {
            $Input[substr($Key, 2)] = $Value;
        }
    }

    # pick the most appropriate value for the default value field
    if (array_key_exists("DefaultValue", $Input)) {
        $Type = StdLib::getArrayValue($Input, "Type");
        $DefaultValues = StdLib::getArrayValue($Input, "DefaultValue", array());

        # numeric default value
        if ($Type == MetadataSchema::MDFTYPE_NUMBER) {
            $Input["DefaultValue"] = StdLib::getArrayValue($DefaultValues, "Numeric");
        # flag default value
        } elseif ($Type == MetadataSchema::MDFTYPE_FLAG) {
            $Input["DefaultValue"] = StdLib::getArrayValue($DefaultValues, "Flag");
        # point default value
        } elseif ($Type == MetadataSchema::MDFTYPE_POINT) {
            $Input["DefaultValue"] = array(
                "X" => StdLib::getArrayValue($DefaultValues, "Point1"),
                "Y" => StdLib::getArrayValue($DefaultValues, "Point2")
            );
        # everything else
        } else {
            $Input["DefaultValue"] = StdLib::getArrayValue($DefaultValues, "Generic");
        }
    }

    $MayContainDoubleHyphen = array(
        "DefaultQualifier",
        "TreeBrowsingPrivilege"
    );

    # transform values that might not be set to NULL
    foreach ($MayContainDoubleHyphen as $Key) {
        if (array_key_exists($Key, $Input)) {
            if (IsFieldValueBlank($Input[$Key]) || $Input[$Key] == "--") {
                $Input[$Key] = null;
            }
        }
    }

    # special handling for the user privilege restrictions field
    if (array_key_exists("UserPrivilegeRestrictions", $Input)) {
        $UserPrivilegeRestrictions = StdLib::getArrayValue($Input, "UserPrivilegeRestrictions");

        # transform input that's not an array to a blank value
        if (!is_array($UserPrivilegeRestrictions)) {
            $Input["UserPrivilegeRestrictions"] = array();
        }
    }

    return $Input;
}

/**
 * Get the form data using the given field as a source.
 * @param MetadataField $Field Metadata field
 * @return array form data
 */
function GetFormDataFromField(MetadataField $Field)
{
    $Schema = $GLOBALS["H_Schema"];

    $FormData = array();

    $DeprecatedMethods = array(
        "ViewingPrivilege", "EditingPrivilege",
        "AuthoringPrivilege", "TreeBrowsingPrivilege"
    );

    # start with the basic fields
    foreach (GetFieldDefaults($Field->Type()) as $Key => $Value) {
        if (method_exists("Metavus\\MetadataField", $Key) &&
            !in_array($Key, $DeprecatedMethods)) {
            $FormData[$Key] = $Field->$Key();
        }
    }

    # determine if the field can be populated
    $CanPopulate = $Field->Type() == MetadataSchema::MDFTYPE_TREE;
    $CanPopulate = $CanPopulate ||
        $Field->Type() == MetadataSchema::MDFTYPE_CONTROLLEDNAME;
    $CanPopulate = $CanPopulate ||
        $Field->Type() == MetadataSchema::MDFTYPE_OPTION;

    # various other information
    $FormData["Id"] = $Field->Id();
    $FormData["Type"] = $Field->Type();
    $FormData["Name"] = $Field->Name();
    $FormData["AllowedTypes"] = $Field->GetAllowedConversionTypes();
    $FormData["TypeAsName"] = $Field->TypeAsName();
    $FormData["AssociatedQualifierList"] = array_keys($Field->AssociatedQualifierList());
    $FormData["MappedName"] = $Schema->FieldToStdNameMapping($Field->Id());
    $FormData["Owner"] = $Field->Owner();
    $FormData["HasOwner"] = !!$Field->Owner();
    $FormData["UsedByPrivsets"] = MetadataSchema::FieldUsedInPrivileges($Field->Id());
    $FormData["FieldExists"] = true;
    $FormData["IsStandard"] =
        !is_null($Schema->FieldToStdNameMapping($Field->Id()));
    $FormData["CanPopulate"] = $CanPopulate;
    $FormData["CanExport"] = $CanPopulate;
    $FormData["ObfuscateValueForAnonymousUsers"] = $Field->ObfuscateValueForAnonymousUsers();

    $FormData["ReferenceableSchemaIds"] = $Field->ReferenceableSchemaIds();

    # privileges
    $FormData["ViewingPrivileges"] = $Field->ViewingPrivileges();
    $FormData["AuthoringPrivileges"] = $Field->AuthoringPrivileges();
    $FormData["EditingPrivileges"] = $Field->EditingPrivileges();

    # sane defaults that might get changed below
    $FormData["HasInactiveOwner"] = false;
    $FormData["IsLastTreeField"] = false;

    # whether or not the field has an inactive owner
    if ($Field->Owner()) {
        $FormData["HasInactiveOwner"] = !in_array(
            $Field->Owner(),
            $GLOBALS["G_PluginManager"]->GetActivePluginList()
        );
    }

    # whether or not the field is the last tree field
    if ($Field->Type() == MetadataSchema::MDFTYPE_TREE) {
        if (count($Schema->GetFields(MetadataSchema::MDFTYPE_TREE)) == 1) {
            $FormData["IsLastTreeField"] = true;
        }
    }

    if (InterfaceConfiguration::getInstance()->getBool("ResourceRatingsEnabled") &&
            $Field->Name() == "Cumulative Rating") {
        $FormData["IsTogglable"] = false;
    } else {
        $FormData["IsTogglable"] = !$FormData["IsStandard"] &&
            (!$FormData["HasOwner"] || !$FormData["HasInactiveOwner"]);
    }

    return $FormData;
}

/**
 * Get the form data using the metadata field defaults.
 * @param int $Type Field type to assume (optional, defaults to text)
 * @param array $Override Values to override the defaults and must be valid
 * @return array form data
 */
function GetFormDataFromDefaults(
    $Type = MetadataSchema::MDFTYPE_TEXT,
    array $Override = array()
) {
    $Schema = $GLOBALS["H_Schema"];
    $FormData = array();

    # get the basic fields first
    foreach (GetFieldDefaults($Type) as $Key => $Value) {
        if (method_exists("Metavus\\MetadataField", $Key)) {
            $FormData[$Key] = StdLib::getArrayValue($Override, $Key, $Value);
        }
    }

    $AssociatedQualifierList = StdLib::getArrayValue(
        $Override,
        "AssociatedQualifierList",
        $FormData["AssociatedQualifierList"]
    );

    # various other information
    $FormData["Id"] = null;
    $FormData["Type"] = $Type;
    $FormData["Name"] = StdLib::getArrayValue($Override, "Name");
    $FormData["AllowedTypes"] = $Schema->GetAllowedFieldTypes();
    $FormData["TypeAsName"] = MetadataField::$FieldTypeHumanEnums[$Type];
    $FormData["AssociatedQualifierList"] = $AssociatedQualifierList;
    $FormData["MappedName"] = null;
    $FormData["Owner"] = null;
    $FormData["HasOwner"] = false;
    $FormData["HasInactiveOwner"] = false;
    $FormData["UsedByPrivsets"] = false;
    $FormData["FieldExists"] = false;
    $FormData["IsStandard"] = false;
    $FormData["IsLastTreeField"] = false;
    $FormData["CanPopulate"] = false;
    $FormData["CanExport"] = false;
    $FormData["ObfuscateValueForAnonymousUsers"] = false;
    $FormData["ReferenceableSchemaIds"] = array(MetadataSchema::SCHEMAID_DEFAULT);

    return $FormData;
}

/**
 * Validate the user-provided field values. If the type given is not
 * valid, type-based checks will not be performed.
 * @param array $Input User-provided field values
 * @param int $Type Netadata field type
 * @return array an array of errors (field name => error message)
 */
function ValidateInput(array &$Input, $Type)
{
    $Errors = array();

    # the first block of fields that apply to every metadata field type
    $Validation = array(
        "Type" => array("ValidateType", $Input),
        "Name" => array("ValidateName", $Input),
        "Label" => array("ValidateLabel", $Input),
        "Description" => array("ValidateNonempty", $Input,
            "Description", "Description"
        ),
        "Enabled" => array("ValidateBoolean", $Input,
            "Enabled", "Enabled"
        ),
        "Editable" => array("ValidateBoolean", $Input,
            "Editable", "Editable"
        ),
        "CopyOnResourceDuplication" => array("ValidateBoolean", $Input,
            "CopyOnResourceDuplication", "Copy on Resource Duplication"
        )
    );

    # validation for text fields
    if ($Type == MetadataSchema::MDFTYPE_TEXT) {
        $Validation += array(
            "Optional" => array("ValidateBoolean", $Input,
                "Optional", "Optional"
            ),
            "UsesQualifiers" => array("ValidateBoolean", $Input,
                "UsesQualifiers", "Uses Qualifiers"
            ),
            "HasItemLevelQualifiers" => array("ValidateBoolean", $Input,
                "HasItemLevelQualifiers", "Has Item Level Qualifiers"
            ),
            "AssociatedQualifierList" => array("ValidateAssociatedQualifierList", $Input),
            "DefaultQualifier" => array("ValidateDefaultQualifier", $Input),
            "ShowQualifiers" => array("ValidateBoolean", $Input,
                "ShowQualifiers", "Show Qualifiers"
            ),
            "IncludeInKeywordSearch" => array("ValidateBoolean", $Input,
                "IncludeInKeywordSearch", "Include In Keyword Search"
            ),
            "IncludeInAdvancedSearch" => array("ValidateBoolean", $Input,
                "IncludeInAdvancedSearch", "Include In Advanced Search"
            ),
            "IncludeInSortOptions" => array("ValidateBoolean", $Input,
                "IncludeInSortOptions", "Include In Sort Options"
            ),
            "SearchWeight" => array("ValidateNumeric", $Input,
                "SearchWeight", "Search Weight", 1, 20
            ),
            "TextFieldSize" => array("ValidateNumeric", $Input,
                "TextFieldSize", "Field Size", 1
            ),
            "MaxLength" => array("ValidateNumeric", $Input,
                "MaxLength", "Max Length", 1
            ),
            "AllowHTML" => array("ValidateBoolean", $Input,
                "AllowHTML", "Allow HTML"
            )
        );
    # validation for paragraph fields
    } elseif ($Type == MetadataSchema::MDFTYPE_PARAGRAPH) {
        $Validation += array(
            "Optional" => array("ValidateBoolean", $Input,
                "Optional", "Optional"
            ),
            "UsesQualifiers" => array("ValidateBoolean", $Input,
                "UsesQualifiers", "Uses Qualifiers"
            ),
            "HasItemLevelQualifiers" => array("ValidateBoolean", $Input,
                "HasItemLevelQualifiers", "Has Item Level Qualifiers"
            ),
            "AssociatedQualifierList" => array("ValidateAssociatedQualifierList", $Input),
            "DefaultQualifier" => array("ValidateDefaultQualifier", $Input),
            "ShowQualifiers" => array("ValidateBoolean", $Input,
                "ShowQualifiers", "Show Qualifiers"
            ),
            "IncludeInKeywordSearch" => array("ValidateBoolean", $Input,
                "IncludeInKeywordSearch", "Include In Keyword Search"
            ),
            "IncludeInAdvancedSearch" => array("ValidateBoolean", $Input,
                "IncludeInAdvancedSearch", "Include In Advanced Search"
            ),
            "SearchWeight" => array("ValidateNumeric", $Input,
                "SearchWeight", "Search Weight", 1, 20
            ),
            "ParagraphRows" => array("ValidateNumeric", $Input,
                "ParagraphRows", "Paragraph Rows", 1
            ),
            "ParagraphCols" => array("ValidateNumeric", $Input,
                "ParagraphCols", "Paragraph Columns", 1
            ),
            "AllowHTML" => array("ValidateBoolean", $Input,
                "AllowHTML", "Allow HTML"
            ),
            "UseWysiwygEditor" => array("ValidateBoolean", $Input,
                "UseWysiwygEditor", "Use WYSIWYG Editor"
            )
        );
    # validation for number fields
    } elseif ($Type == MetadataSchema::MDFTYPE_NUMBER) {
        $Validation += array(
            "Optional" => array("ValidateBoolean", $Input,
                "Optional", "Optional"
            ),
            "UsesQualifiers" => array("ValidateBoolean", $Input,
                "UsesQualifiers", "Uses Qualifiers"
            ),
            "HasItemLevelQualifiers" => array("ValidateBoolean", $Input,
                "HasItemLevelQualifiers", "Has Item Level Qualifiers"
            ),
            "AssociatedQualifierList" => array("ValidateAssociatedQualifierList", $Input),
            "DefaultQualifier" => array("ValidateDefaultQualifier", $Input),
            "ShowQualifiers" => array("ValidateBoolean", $Input,
                "ShowQualifiers", "Show Qualifiers"
            ),
            "IncludeInKeywordSearch" => array("ValidateBoolean", $Input,
                "IncludeInKeywordSearch", "Include In Keyword Search"
            ),
            "IncludeInAdvancedSearch" => array("ValidateBoolean", $Input,
                "IncludeInAdvancedSearch", "Include In Advanced Search"
            ),
            "IncludeInSortOptions" => array("ValidateBoolean", $Input,
                "IncludeInSortOptions", "Include In Sort Options"
            ),
            "SearchWeight" => array("ValidateNumeric", $Input,
                "SearchWeight", "Search Weight", 1, 20
            ),
            "TextFieldSize" => array("ValidateNumeric", $Input,
                "TextFieldSize", "Field Size", 1
            ),
            "MinValue" => array("ValidateNumeric", $Input,
                "MinValue", "Minimum Value"
            ),
            "MaxValue" => array("ValidateNumeric", $Input,
                "MaxValue", "Maximum Value"
            ),
            "DefaultValue" => array("ValidateNumeric", $Input,
                "DefaultValue", "Default Value", null, null, false
            )
        );

        # these fields need to be validated for the rating since they aren't
        # saved
        if (StdLib::getArrayValue($Input, "Name") == "Cumulative Rating") {
            unset($Validation["TextFieldSize"]);
            unset($Validation["DefaultValue"]);
            unset($Validation["MinValue"]);
            unset($Validation["MaxValue"]);
        }
    # validation for date fields
    } elseif ($Type == MetadataSchema::MDFTYPE_DATE) {
        $Validation += array(
            "Optional" => array("ValidateBoolean", $Input,
                "Optional", "Optional"
            ),
            "UsesQualifiers" => array("ValidateBoolean", $Input,
                "UsesQualifiers", "Uses Qualifiers"
            ),
            "HasItemLevelQualifiers" => array("ValidateBoolean", $Input,
                "HasItemLevelQualifiers", "Has Item Level Qualifiers"
            ),
            "AssociatedQualifierList" => array("ValidateAssociatedQualifierList", $Input),
            "DefaultQualifier" => array("ValidateDefaultQualifier", $Input),
            "ShowQualifiers" => array("ValidateBoolean", $Input,
                "ShowQualifiers", "Show Qualifiers"
            ),
            "IncludeInAdvancedSearch" => array("ValidateBoolean", $Input,
                "IncludeInAdvancedSearch", "Include In Advanced Search"
            ),
            "IncludeInSortOptions" => array("ValidateBoolean", $Input,
                "IncludeInSortOptions", "Include In Sort Options"
            ),
            "SearchWeight" => array("ValidateNumeric", $Input,
                "SearchWeight", "Search Weight", 1, 20
            ),
            "TextFieldSize" => array("ValidateNumeric", $Input,
                "TextFieldSize", "Field Size", 1
            )
        );
    # validation for timestamp fields
    } elseif ($Type == MetadataSchema::MDFTYPE_TIMESTAMP) {
        $Validation += array(
            "Optional" => array("ValidateBoolean", $Input,
                "Optional", "Optional"
            ),
            "UsesQualifiers" => array("ValidateBoolean", $Input,
                "UsesQualifiers", "Uses Qualifiers"
            ),
            "HasItemLevelQualifiers" => array("ValidateBoolean", $Input,
                "HasItemLevelQualifiers", "Has Item Level Qualifiers"
            ),
            "AssociatedQualifierList" => array("ValidateAssociatedQualifierList", $Input),
            "DefaultQualifier" => array("ValidateDefaultQualifier", $Input),
            "ShowQualifiers" => array("ValidateBoolean", $Input,
                "ShowQualifiers", "Show Qualifiers"
            ),
            "IncludeInAdvancedSearch" => array("ValidateBoolean", $Input,
                "IncludeInAdvancedSearch", "Include In Advanced Search"
            ),
            "IncludeInSortOptions" => array("ValidateBoolean", $Input,
                "IncludeInSortOptions", "Include In Sort Options"
            ),
            "SearchWeight" => array("ValidateNumeric", $Input,
                "SearchWeight", "Search Weight", 1, 20
            ),
            "UpdateMethod" => array("ValidateUpdateMethod", $Input)
        );

    # validation for flag fields
    } elseif ($Type == MetadataSchema::MDFTYPE_FLAG) {
        $Validation += array(
            "UsesQualifiers" => array("ValidateBoolean", $Input,
                "UsesQualifiers", "Uses Qualifiers"
            ),
            "HasItemLevelQualifiers" => array("ValidateBoolean", $Input,
                "HasItemLevelQualifiers", "Has Item Level Qualifiers"
            ),
            "AssociatedQualifierList" => array("ValidateAssociatedQualifierList", $Input),
            "DefaultQualifier" => array("ValidateDefaultQualifier", $Input),
            "ShowQualifiers" => array("ValidateBoolean", $Input,
                "ShowQualifiers", "Show Qualifiers"
            ),
            "IncludeInAdvancedSearch" => array("ValidateBoolean", $Input,
                "IncludeInAdvancedSearch", "Include In Advanced Search"
            ),
            "FlagOnLabel" => array("ValidateNonempty", $Input,
                "FlagOnLabel", "Flag On Label"
            ),
            "FlagOffLabel" => array("ValidateNonempty", $Input,
                "FlagOffLabel", "Flag Off Label"
            ),
            "DefaultValue" => array("ValidateBoolean", $Input,
                "DefaultValue", "Default Value"
            )
        );
    # validation for tree fields
    } elseif ($Type == MetadataSchema::MDFTYPE_TREE) {
        $Validation += array(
            "Optional" => array("ValidateBoolean", $Input,
                "Optional", "Optional"
            ),
            "UsesQualifiers" => array("ValidateBoolean", $Input,
                "UsesQualifiers", "Uses Qualifiers"
            ),
            "HasItemLevelQualifiers" => array("ValidateBoolean", $Input,
                "HasItemLevelQualifiers", "Has Item Level Qualifiers"
            ),
            "AssociatedQualifierList" => array("ValidateAssociatedQualifierList", $Input),
            "DefaultQualifier" => array("ValidateDefaultQualifier", $Input),
            "ShowQualifiers" => array("ValidateBoolean", $Input,
                "ShowQualifiers", "Show Qualifiers"
            ),
            "IncludeInKeywordSearch" => array("ValidateBoolean", $Input,
                "IncludeInKeywordSearch", "Include In Keyword Search"
            ),
            "IncludeInAdvancedSearch" => array("ValidateBoolean", $Input,
                "IncludeInAdvancedSearch", "Include In Advanced Search"
            ),
            "IncludeInFacetedSearch" => array("ValidateBoolean", $Input,
                "IncludeInFacetedSearch", "Include In Faceted Search"
            ),
            "SearchWeight" => array("ValidateNumeric", $Input,
                "SearchWeight", "Search Weight", 1, 20
            ),
            "SearchGroupLogic" => array("ValidateNumeric", $Input,
                "SearchGroupLogic", "Search Group Logic", 1, 2
            ),
            "FacetsShowOnlyTermsUsedInResults" => array("ValidateBoolean", $Input,
                "FacetsShowOnlyTermsUsedInResults", "Facets Show All Terms"
            ),
            "OptionListThreshold" => array("ValidateOptionListThreshold", $Input),
            "AjaxThreshold" => array("ValidateAjaxThreshold", $Input),
            "NumAjaxResults" => array("ValidateNumeric", $Input,
                "NumAjaxResults", "Maximum Number of Search Results", 10
            ),
            "UseForOaiSets" => array("ValidateBoolean", $Input,
                "UseForOaiSets", "Use for OAI Sets"
            ),
            "DisplayAsListForAdvancedSearch" => array("ValidateBoolean", $Input,
                "DisplayAsListForAdvancedSearch", "Display As List For Advanced Search"
            ),
            "MaxDepthForAdvancedSearch" => array("ValidateNumeric", $Input,
                "MaxDepthForAdvancedSearch", "Maximum Depth For Advanced Search"
            ),
        );
    # validation for controlled name fields
    } elseif ($Type == MetadataSchema::MDFTYPE_CONTROLLEDNAME) {
        $Validation += array(
            "Optional" => array("ValidateBoolean", $Input,
                "Optional", "Optional"
            ),
            "UsesQualifiers" => array("ValidateBoolean", $Input,
                "UsesQualifiers", "Uses Qualifiers"
            ),
            "HasItemLevelQualifiers" => array("ValidateBoolean", $Input,
                "HasItemLevelQualifiers", "Has Item Level Qualifiers"
            ),
            "AssociatedQualifierList" => array("ValidateAssociatedQualifierList", $Input),
            "DefaultQualifier" => array("ValidateDefaultQualifier", $Input),
            "ShowQualifiers" => array("ValidateBoolean", $Input,
                "ShowQualifiers", "Show Qualifiers"
            ),
            "IncludeInKeywordSearch" => array("ValidateBoolean", $Input,
                "IncludeInKeywordSearch", "Include In Keyword Search"
            ),
            "IncludeInAdvancedSearch" => array("ValidateBoolean", $Input,
                "IncludeInAdvancedSearch", "Include In Advanced Search"
            ),
            "IncludeInFacetedSearch" => array("ValidateBoolean", $Input,
                "IncludeInFacetedSearch", "Include In Faceted Search"
            ),
            "SearchWeight" => array("ValidateNumeric", $Input,
                "SearchWeight", "Search Weight", 1, 20
            ),
            "SearchGroupLogic" => array("ValidateNumeric", $Input,
                "SearchGroupLogic", "Search Group Logic", 1, 2
            ),
            "FacetsShowOnlyTermsUsedInResults" => array("ValidateBoolean", $Input,
                "FacetsShowOnlyTermsUsedInResults", "Facets Show All Terms"
            ),
            "OptionListThreshold" => array("ValidateOptionListThreshold", $Input),
            "AjaxThreshold" => array("ValidateAjaxThreshold", $Input),
            "NumAjaxResults" => array("ValidateNumeric", $Input,
                "NumAjaxResults", "Maximum Number of Search Results", 10
            ),
            "UseForOaiSets" => array("ValidateBoolean", $Input,
                "UseForOaiSets", "Use for OAI Sets"
            )
        );
    # validation for option fields
    } elseif ($Type == MetadataSchema::MDFTYPE_OPTION) {
        $Validation += array(
            "Optional" => array("ValidateBoolean", $Input,
                "Optional", "Optional"
            ),
            "UsesQualifiers" => array("ValidateBoolean", $Input,
                "UsesQualifiers", "Uses Qualifiers"
            ),
            "HasItemLevelQualifiers" => array("ValidateBoolean", $Input,
                "HasItemLevelQualifiers", "Has Item Level Qualifiers"
            ),
            "AssociatedQualifierList" => array("ValidateAssociatedQualifierList", $Input),
            "DefaultQualifier" => array("ValidateDefaultQualifier", $Input),
            "ShowQualifiers" => array("ValidateBoolean", $Input,
                "ShowQualifiers", "Show Qualifiers"
            ),
            "IncludeInAdvancedSearch" => array("ValidateBoolean", $Input,
                "IncludeInAdvancedSearch", "Include In Advanced Search"
            ),
            "IncludeInFacetedSearch" => array("ValidateBoolean", $Input,
                "IncludeInFacetedSearch", "Include In Faceted Search"
            ),
            "SearchWeight" => array("ValidateNumeric", $Input,
                "SearchWeight", "Search Weight", 1, 20
            ),
            "SearchGroupLogic" => array("ValidateNumeric", $Input,
                "SearchGroupLogic", "Search Group Logic", 1, 2
            ),
            "FacetsShowOnlyTermsUsedInResults" => array("ValidateBoolean", $Input,
                "FacetsShowOnlyTermsUsedInResults", "Facets Show All Terms"
            ),
            "UseForOaiSets" => array("ValidateBoolean", $Input,
                "UseForOaiSets", "Use for OAI Sets"
            ),
            "AllowMultiple" => array("ValidateBoolean", $Input,
                "AllowMultiple", "Allow Multiple"
            )
        );
    # validation for user fields
    } elseif ($Type == MetadataSchema::MDFTYPE_USER) {
        $Validation += array(
            "Optional" => array("ValidateBoolean", $Input,
                "Optional", "Optional"
            ),
            "IncludeInKeywordSearch" => array("ValidateBoolean", $Input,
                "IncludeInKeywordSearch", "Include In Keyword Search"
            ),
            "IncludeInAdvancedSearch" => array("ValidateBoolean", $Input,
                "IncludeInAdvancedSearch", "Include In Advanced Search"
            ),
            "SearchWeight" => array("ValidateNumeric", $Input,
                "SearchWeight", "Search Weight", 1, 20
            ),
            "UserPrivilegeRestrictions" =>
                 array("ValidateUserPrivilegeRestrictions", $Input),
            "AllowMultiple" => array("ValidateBoolean", $Input,
                "AllowMultiple", "Allow Multiple"
            ),
            "UpdateMethod" => array("ValidateUpdateMethod", $Input)
        );
    # validation for image fields
    } elseif ($Type == MetadataSchema::MDFTYPE_IMAGE) {
        $Validation += array(
            "Optional" => array("ValidateBoolean", $Input, "Optional", "Optional"),
            "AllowMultiple" => array("ValidateBoolean", $Input,
                "AllowMultiple", "Allow Multiple"
            ),
            "UsesQualifiers" => array("ValidateBoolean", $Input,
                "UsesQualifiers", "Uses Qualifiers"
            ),
            "HasItemLevelQualifiers" => array("ValidateBoolean", $Input,
                "HasItemLevelQualifiers", "Has Item Level Qualifiers"
            ),
            "AssociatedQualifierList" => array("ValidateAssociatedQualifierList", $Input),
            "DefaultQualifier" => array("ValidateDefaultQualifier", $Input),
            "ShowQualifiers" => array("ValidateBoolean", $Input,
                "ShowQualifiers", "Show Qualifiers"
            ),
            "IncludeInKeywordSearch" => array("ValidateBoolean", $Input,
                "IncludeInKeywordSearch", "Include In Keyword Search"
            ),
            "IncludeInAdvancedSearch" => array("ValidateBoolean", $Input,
                "IncludeInAdvancedSearch", "Include In Advanced Search"
            ),
            "SearchWeight" => array("ValidateNumeric", $Input,
                "SearchWeight", "Search Weight", 1, 20
            ),
        );
    # validation for file fields
    } elseif ($Type == MetadataSchema::MDFTYPE_FILE) {
        $Validation += array(
            "Optional" => array("ValidateBoolean", $Input, "Optional", "Optional"),
            "UsesQualifiers" => array("ValidateBoolean", $Input,
                "UsesQualifiers", "Uses Qualifiers"
            ),
            "HasItemLevelQualifiers" => array("ValidateBoolean", $Input,
                "HasItemLevelQualifiers", "Has Item Level Qualifiers"
            ),
            "AssociatedQualifierList" => array("ValidateAssociatedQualifierList", $Input),
            "DefaultQualifier" => array("ValidateDefaultQualifier", $Input),
            "ShowQualifiers" => array("ValidateBoolean", $Input,
                "ShowQualifiers", "Show Qualifiers"
            ),
            "IncludeInKeywordSearch" => array("ValidateBoolean", $Input,
                "IncludeInKeywordSearch", "Include In Keyword Search"
            ),
            "IncludeInAdvancedSearch" => array("ValidateBoolean", $Input,
                "IncludeInAdvancedSearch", "Include In Advanced Search"
            ),
            "SearchWeight" => array("ValidateNumeric", $Input,
                "SearchWeight", "Search Weight", 1, 20
            )
        );
    # validation for URL fields
    } elseif ($Type == MetadataSchema::MDFTYPE_URL) {
        $Validation += array(
            "Optional" => array("ValidateBoolean", $Input, "Optional", "Optional"),
            "UsesQualifiers" => array("ValidateBoolean", $Input,
                "UsesQualifiers", "Uses Qualifiers"
            ),
            "HasItemLevelQualifiers" => array("ValidateBoolean", $Input,
                "HasItemLevelQualifiers", "Has Item Level Qualifiers"
            ),
            "AssociatedQualifierList" => array("ValidateAssociatedQualifierList", $Input),
            "DefaultQualifier" => array("ValidateDefaultQualifier", $Input),
            "ShowQualifiers" => array("ValidateBoolean", $Input,
                "ShowQualifiers", "Show Qualifiers"
            ),
            "IncludeInKeywordSearch" => array("ValidateBoolean", $Input,
                "IncludeInKeywordSearch", "Include In Keyword Search"
            ),
            "IncludeInAdvancedSearch" => array("ValidateBoolean", $Input,
                "IncludeInAdvancedSearch", "Include In Advanced Search"
            ),
            "IncludeInSortOptions" => array("ValidateBoolean", $Input,
                "IncludeInSortOptions", "Include In Sort Options"
            ),
            "SearchWeight" => array("ValidateNumeric", $Input,
                "SearchWeight", "Search Weight", 1, 20
            ),
            "TextFieldSize" => array("ValidateNumeric", $Input,
                "TextFieldSize", "Field Size", 1
            ),
            "MaxLength" => array("ValidateNumeric", $Input,
                "MaxLength", "Max Length", 1
            )
        );
    # validation for point fields
    } elseif ($Type == MetadataSchema::MDFTYPE_POINT) {
        $Validation += array(
            "Optional" => array("ValidateBoolean", $Input, "Optional", "Optional"),
            "UsesQualifiers" => array("ValidateBoolean", $Input,
                "UsesQualifiers", "Uses Qualifiers"
            ),
            "HasItemLevelQualifiers" => array("ValidateBoolean", $Input,
                "HasItemLevelQualifiers", "Has Item Level Qualifiers"
            ),
            "AssociatedQualifierList" => array("ValidateAssociatedQualifierList", $Input),
            "DefaultQualifier" => array("ValidateDefaultQualifier", $Input),
            "ShowQualifiers" => array("ValidateBoolean", $Input,
                "ShowQualifiers", "Show Qualifiers"
            ),
            "TextFieldSize" => array("ValidateNumeric", $Input,
                "TextFieldSize", "Field Size", 1
            ),
            "PointPrecision" => array("ValidateNumeric", $Input,
                "PointPrecision", "Point Precision", 1
            ),
            "PointDecimalDigits" => array("ValidateNumeric", $Input,
                "PointDecimalDigits", "Decimal Digits in Point", 0
            ),
            "DefaultValue" => array("ValidatePointDefaultValue", $Input)
        );
    # validation for reference fields
    } elseif ($Type == MetadataSchema::MDFTYPE_REFERENCE) {
        $Validation += array(
            "Optional" => array("ValidateBoolean", $Input, "Optional", "Optional"),
            "IncludeInAdvancedSearch" => array("ValidateBoolean", $Input,
                "IncludeInAdvancedSearch", "Include In Advanced Search"
            ),
            "SearchWeight" => array("ValidateNumeric", $Input,
                "SearchWeight", "Search Weight", 1, 20
            ),
            "NumAjaxResults" => array("ValidateNumeric", $Input,
                "NumAjaxResults", "Maximum Number of Search Results", 10
            ),
            "AllowMultiple" => array("ValidateBoolean", $Input,
                "AllowMultiple", "Allow Multiple"
            ),
        );
    }

    # final block of fields that apply to every metadata field type


    # attempt to validate the privilege sets
    global $H_PrivsetUI;
    try {
        # if there's an error, this will throw an exception
        $H_PrivsetUI->GetPrivilegeSetsFromForm();
    } catch (Exception $Exception) {
        # inform the user about what went wrong
        $Result = "One or more privilege settings is invalid: "
            .$Exception->getMessage();

        foreach (array("Viewing", "Authoring", "Editing") as $PrivName) {
            $Errors[$PrivName."Privileges"] = $Result;
        }
    }


    # perform validation (validation functions return strings on error or NULL
    # when no issues are found)
    foreach ($Validation as $Name => $Callback) {
        $Function = __NAMESPACE__."\\".array_shift($Callback);
        if (!is_callable($Function)) {
            throw new Exception("Provided callback was not valid.");
        }
        $Result = call_user_func_array($Function, $Callback);
        if ($Result !== null) {
            $Errors[$Name] = $Result;
        }
    }

    # if there were no errors, remove extraneous, unvalidated parameters from
    # $Input so that we aren't setting params that are invalid for our field
    # type
    if (count($Errors) == 0) {
        foreach (array_keys($Input) as $Key) {
            if (!isset($Validation[$Key])) {
                unset($Input[$Key]);
            }
        }
    }

    return $Errors;
}

/**
 * Determine if the given value is blank or empty if an array.
 * @param mixed $Value Value to check for blankness
 * @return bool TRUE if the value is blank or FALSE otherwise
 */
function IsFieldValueBlank($Value)
{
    if (is_array($Value)) {
        return !count($Value);
    }

    return is_null($Value) || !strlen(trim($Value));
}

/**
 * Validate the user-provided field values.
 * @param array $Input User-provided field values
 * @param MetadataField $Field Metadata field for validation
 * @return array an array of invalid fields and valid input
 */
function ValidateInputForField(array $Input, MetadataField $Field)
{
    $Type = StdLib::getArrayValue($Input, "Type");

    # do generic validation first
    $Errors = ValidateInput($Input, $Type);

    # do custom checking of the type, name, and enabled given the field
    $Errors["Type"] = ValidateTypeForField($Input, $Field);
    $Errors["Name"] = ValidateNameForField($Input, $Field);
    $Errors["Enabled"] = ValidateEnabledForField($Input, $Field);

    # remove fields that don't have an error message and reset indices
    return array_merge(array_filter($Errors));
}

/**
 * Validate the field enabled within the context of the given field.
 * @param array $Input User-provided input
 * @param MetadataField $Field Metadata field
 * @return null|string NULL on success or message on invalid input
 */
function ValidateEnabledForField(array $Input, MetadataField $Field)
{
    $Enabled = StdLib::getArrayValue($Input, "Enabled");
    $Id = $Field->Id();
    $Schema = $GLOBALS["H_Schema"];

    # cannot disable fields used for privilege settings
    if (!$Enabled && MetadataSchema::FieldUsedInPrivileges($Id)) {
        return "Cannot disable fields used in privilege settings.";
    }

    # check if this field is currently mapped
    if (!$Enabled && $Schema->FieldToStdNameMapping($Id) !== null) {
        return "Cannot disable fields that are set "
                ."as a standard field in System Configuration.";
    }
    return null;
}

/**
 * Validate the field type.
 * @param array $Input User-provided input
 * @return null|string NULL on success or message on invalid input
 */
function ValidateType(array $Input)
{
    $Type = StdLib::getArrayValue($Input, "Type");

    # the field type field is required
    if (IsFieldValueBlank($Type)) {
        return "The <i>Type</i> field is required.";
    }

    # the field type must be valid
    if (!isset(MetadataField::$FieldTypeDBEnums[$Type])) {
        return "The <i>Type</i> field is invalid.";
    }
    return null;
}

/**
 * Validate the field type within the context of the given field.
 * @param array $Input User-provided input
 * @param MetadataField $Field Metadata field
 * @return null|string NULL on success or message on invalid input
 */
function ValidateTypeForField(array $Input, MetadataField $Field)
{
    $Type = StdLib::getArrayValue($Input, "Type");

    # use the checks from ValidateType()
    $TypeError = ValidateType($Input);

    # if there's an error already, just return it
    if (!is_null($TypeError) && strlen($TypeError)) {
        return $TypeError;
    }

    $AllowedConversionTypes = $Field->GetAllowedConversionTypes();

    # the (conversion) type must be okay
    if ($Type != $Field->Type() && !isset($AllowedConversionTypes[$Type])) {
        # get the allowed conversion types as a human-readable string
        $HumanField = new HumanMetadataField($Field);
        $TypeString = $HumanField->GetAllowedConversionTypes();

        return "The field must be one of the following types: ".$TypeString.".";
    }
    return null;
}

/**
 * Validate the field name.
 * @param array $Input User-provided input
 * @return null|string NULL on success or message on invalid input
 */
function ValidateName(array $Input)
{
    $Name = StdLib::getArrayValue($Input, "Name");

    # the name field is required
    if (IsFieldValueBlank($Name)) {
        return "The <i>Name</i> field is required.";
    }

    # there are restrictions on what can be put in a name
    if (preg_match("/[^a-zA-Z0-9 \(\)]+/", $Name)) {
        return "The <i>Name</i> field can only contain "
            ."letters, numbers, spaces, and parentheses.";
    }

    # field name cannot be a number
    if (is_numeric($Name)) {
        return "The <i>Name</i> field value cannot be a number.";
    }

    # can't use reserved words
    $NormalizedName = preg_replace("/[^a-z0-9]/i", "", strtolower($Name));
    if ($NormalizedName == "resourceid" || $NormalizedName == "schemaid") {
        return "The <i>Name</i> field can't be a form "
            ."of <i>Resource ID</i> or <i>Schema ID</i>.";
    }

    # names must be unique when trailing space is removed
    $Schema = $GLOBALS["H_Schema"];
    if ($Schema->FieldExists(trim($Name))) {
        return "The <i>Name</i> field value given is already in use.";
    }
    return null;
}

/**
 * Validate the field name in the context of the given field.
 * @param array $Input User-provided input
 * @param MetadataField $Field Metadata field
 * @return null|string NULL on success or message on invalid input
 */
function ValidateNameForField(array $Input, MetadataField $Field)
{
    $Name = StdLib::getArrayValue($Input, "Name");

    # the field name field is required
    if (IsFieldValueBlank($Name)) {
        return "The <i>Name</i> field is required.";
    }

    # there are restrictions on what can be put in a name
    if (preg_match("/[^a-zA-Z0-9 \(\)]+/", $Name)) {
        return "The <i>Name</i> field can only contain "
            ."letters, numbers, spaces, and parentheses.";
    }

    $Schema = $GLOBALS["H_Schema"];

    # names must be unique when trailing space is removed
    if ($Field->Name() != trim($Name) && $Schema->FieldExists(trim($Name))) {
        return "The <i>Name</i> field value given is already in use.";
    }
    return null;
}

/**
 * Validate the field label.
 * @param array $Input User-provided input
 * @return null|string NULL on success or message on invalid input
 */
function ValidateLabel(array $Input)
{
    $Label = StdLib::getArrayValue($Input, "Label");

    # there are restrictions on what can be put in a label
    if (preg_match("/[^a-zA-Z0-9 ]+/", $Label)) {
        return "The <i>Display Name</i> field can only contain "
            ."letters, numbers, and spaces.";
    }
    return null;
}

/**
 * Validate the default qualifier.
 * @param array $Input User-provided input
 * @return null|string NULL on success or message on invalid input
 */
function ValidateDefaultQualifier(array $Input)
{
    $DefaultQualifier = StdLib::getArrayValue($Input, "DefaultQualifier");

    # it's okay if the default qualifier isn't set
    if (IsFieldValueBlank($DefaultQualifier)) {
        return null;
    }

    $Qualifier = LoadQualifier($DefaultQualifier);

    # make sure the qualifier exists
    if (!($Qualifier instanceof Qualifier)) {
        return "The <i>Default Qualifier</i> field must be a valid qualifier.";
    }

    $AssociatedQualifierList = StdLib::getArrayValue($Input, "AssociatedQualifierList");

    # the default qualifier needs to be in the associated qualifier list
    if (!in_array($DefaultQualifier, $AssociatedQualifierList)) {
        return "The <i>Default Qualifier</i> field be set to a field that is"
            ." selected in the <i>Allowed Qualifiers</i> field.";
    }
    return null;
}

/**
 * Validate the user privilege restrictions.
 * @param array $Input User-provided input
 * @return null|string NULL on success or message on invalid input
 */
function ValidateUserPrivilegeRestrictions(array $Input)
{
    $PrivilegeFactory = new PrivilegeFactory();
    $UserPrivilegeRestrictions =
        StdLib::getArrayValue($Input, "UserPrivilegeRestrictions", array());

    # check each restriction
    foreach ($UserPrivilegeRestrictions as $Restriction) {
        if (!$PrivilegeFactory->PrivilegeValueExists($Restriction)) {
            return "The privileges given for the <i>Restrict to Users With One"
                  ." of the Following</i> field are invalid.";
        }
    }
    return null;
}

/**
 * Validate the update method.
 * @param array $Input User-provided input
 * @return null|string NULL on success or message on invalid input
 */
function ValidateUpdateMethod(array $Input)
{
    $ValidValues = array_keys(MetadataField::$UpdateTypes);
    $UpdateMethod = StdLib::getArrayValue($Input, "UpdateMethod");

    if (isset($Input["UpdateMethod"]) &&
        !in_array($UpdateMethod, $ValidValues)) {
        return "The <i>Update Method</i> field is invalid.";
    }
    return null;
}

/**
 * Validate the default value field for points.
 * @param array $Input User-provided input
 * @return null|string NULL on success or message on invalid input
 */
function ValidatePointDefaultValue(array $Input)
{
    $DefaultValue = StdLib::getArrayValue($Input, "DefaultValue");
    $DefaultValue1 = StdLib::getArrayValue($DefaultValue, "X");
    $DefaultValue2 = StdLib::getArrayValue($DefaultValue, "Y");

    if (strlen($DefaultValue1) || strlen($DefaultValue2)) {
        if (!is_numeric($DefaultValue1)) {
            return "The values for the <i>Default Value</i> field must be numbers.";
        }

        if (!is_numeric($DefaultValue2)) {
            return "The values for the <i>Default Value</i> field must be numbers.";
        }
    }
    return null;
}

/**
 * Validate the associated qualifier list.
 * @param array $Input User-provided input
 * @return null|string NULL on success or message on invalid input
 */
function ValidateAssociatedQualifierList(array $Input)
{
    $AssociatedQualifierList = StdLib::getArrayValue($Input, "AssociatedQualifierList");

    # it's okay if there are none selected
    if (IsFieldValueBlank($AssociatedQualifierList)) {
        return null;
    }

    $QualifierList = GetQualifierList();

    # all the qualifiers must be valid
    if (count(array_diff($AssociatedQualifierList, array_keys($QualifierList)))) {
        return "The <i>Allowed Qualifiers</i> field is invalid.";
    }
    return null;
}

/**
 * Validate a boolean value.
 * @param array $Input User-provided input
 * @param string $Key Field value key
 * @param string $Name Field name displayed to the user
 * @return null|string NULL on success or message on invalid input
 */
function ValidateBoolean(array $Input, $Key, $Name)
{
    $Value = StdLib::getArrayValue($Input, $Key);

    if ($Value != 1 && $Value != 0) {
        return "The <i>" . $Name . "</i> field must be a yes or no value.";
    }
    return null;
}

/**
 * Validate a numeric value.
 * @param array $Input User-provided input
 * @param string $Key Field value key
 * @param string $Name Field name displayed to the user
 * @param int|null $Lower Lowest value the number can be (optional)
 * @param int|null $Upper Highest value the number can be (optional)
 * @param bool $Required Whether or not a value is required (optional)
 * @return null|string NULL on success or message on invalid input
 */
function ValidateNumeric(
    array $Input,
    $Key,
    $Name,
    $Lower = null,
    $Upper = null,
    $Required = true
) {
    $MaxAllowedValue = 100000000;
    $Value = StdLib::getArrayValue($Input, $Key);

    # the field is not required and is blank
    if (!$Required && IsFieldValueBlank($Value)) {
        return null;
    }

    # must be a number
    if (!is_numeric($Value)) {
        return "The <i>" . $Name . "</i> field must be a number.";
    }

    # must be greater than or equal to the lower bound, if specified
    if (is_numeric($Lower) && $Value < $Lower) {
        $Lower = number_format($Lower);
        return "The <i>" . $Name . "</i> field must be at least ".$Lower.".";
    }

    # must be less than or equal to the lower bound, if specified
    if (is_numeric($Upper) && $Value > $Upper) {
        $Upper = number_format($Upper);
        return "The <i>" . $Name . "</i> field must be no more than ".$Upper.".";
    }

    # must be less than a certain value because of technical limitations
    if ($Value > $MaxAllowedValue) {
        $MaxAllowedValue = number_format($MaxAllowedValue);
        return "The <i>" . $Name . "</i> field must be no more than "
              .$MaxAllowedValue.". This is for technical reasons.";
    }
    return null;
}

/**
 * Validate a nonempty value.
 * @param array $Input User-provided input
 * @param string $Key Field value key
 * @param string $Name Field name displayed to the user
 * @return null|string NULL on success or message on invalid input
 */
function ValidateNonempty(array $Input, $Key, $Name)
{
    $Value = StdLib::getArrayValue($Input, $Key);

    if (!strlen($Value)) {
        return "The <i>" . $Name . "</i> field is required.";
    }
    return null;
}

/**
 * Validate a privilege value.
 * @param array $Input User-provided input
 * @param string $Key Field value key
 * @param string $Name Field name displayed to the user
 * @param bool $NotSetOk Okay for value to be blank? (OPTIONAL, default FALSE)
 * @return null|string NULL on success or message on invalid input
 */
function ValidatePrivilege(array $Input, $Key, $Name, $NotSetOk = false)
{
    $Value = StdLib::getArrayValue($Input, $Key);

    # if it's okay for the field not to be set
    if ($NotSetOk && IsFieldValueBlank($Value)) {
        return null;
    }

    $PrivilegeFactory = new PrivilegeFactory();

    if (!$PrivilegeFactory->PrivilegeValueExists($Value)) {
        return "The privilege given for the <i>" . $Name . "</i> field is invalid.";
    }
    return null;
}

/**
* Validate the option list threshold value.
* @param array $Input User-provided input.
* @return null|string Returns NULL on success or message on invalid input.
*/
function ValidateOptionListThreshold(array $Input)
{
    $MaxAllowedValue = 100000000;
    $OptionListThreshold = StdLib::getArrayValue($Input, "OptionListThreshold");
    $AjaxThreshold = StdLib::getArrayValue($Input, "AjaxThreshold");

    # the field is required
    if (IsFieldValueBlank($OptionListThreshold)) {
        return "The <i>Option List Threshold</i> field is required.";
    }

    # must be a number
    if (!is_numeric($OptionListThreshold)) {
        return "The <i>Option List Threshold</i> field must be a number.";
    }

    # the field must be less than or equal to the AJAX threshold
    if ($OptionListThreshold > $AjaxThreshold) {
        return "The <i>Option List Threshold</i> field must be less than or "
              ."equal to the <i>AJAX Threshold</i> field.";
    }

    # must be less than a certain value because of technical limitations
    if ($OptionListThreshold > $MaxAllowedValue) {
        $MaxAllowedValue = number_format($MaxAllowedValue);
        return "The <i>Option List Threshold</i> field must be no more than "
              .$MaxAllowedValue.". This is for technical reasons.";
    }
    return null;
}

/**
* Validate the AJAX threshold value.
* @param array $Input User-provided input.
* @return null|string Returns NULL on success or message on invalid input.
*/
function ValidateAjaxThreshold(array $Input)
{
    $MaxAllowedValue = 100000000;
    $AjaxThreshold = StdLib::getArrayValue($Input, "AjaxThreshold");
    $OptionListThreshold = StdLib::getArrayValue($Input, "OptionListThreshold");

    # the field is required
    if (IsFieldValueBlank($AjaxThreshold)) {
        return "The <i>AJAX Threshold</i> field is required.";
    }

    # must be a number
    if (!is_numeric($AjaxThreshold)) {
        return "The <i>AJAX Threshold</i> field must be a number.";
    }

    # the field must be less than or equal to the AJAX threshold
    if ($AjaxThreshold < $OptionListThreshold) {
        return "The <i>AJAX Threshold</i> field must be greater than or "
              ."equal to the <i>Option List Threshold</i> field.";
    }

    # must be less than a certain value because of technical limitations
    if ($AjaxThreshold > $MaxAllowedValue) {
        $MaxAllowedValue = number_format($MaxAllowedValue);
        return "The <i>AJAX Threshold</i> field must be no more than "
              .$MaxAllowedValue.". This is for technical reasons.";
    }
    return null;
}

/**
 * Save the given input to the given metadata field. The input must be validated
 * at this point.
 * @param MetadataField $Field Metadata field
 * @param array $Input User input where keys correspond either to method names in
 *   MetadataField or one of (Viewing|Authoring|Editing)Priviliges(Logic)?.
 * @return void
 */
function SaveInput(MetadataField $Field, array $Input)
{
    if (array_key_exists("Name", $Input)) {
        $Input["Name"] = trim($Input["Name"]);
    }

    $AssociatedQualifierList = $Field->AssociatedQualifierList();

    # remove association with any current qualifiers
    foreach ($AssociatedQualifierList as $Id => $Name) {
        $Field->UnassociateWithQualifier($Id);
    }

    $AssociateWith = StdLib::getArrayValue($Input, "AssociatedQualifierList", array());

    # associated with any given qualifiers
    foreach ($AssociateWith as $Id) {
        $Field->addQualifier($Id);
    }

    # some fields can't be edited for the cumulative rating field
    if ($Field->Name() == "Cumulative Rating") {
        unset($Input["TextFieldSize"]);
        unset($Input["DefaultValue"]);
        unset($Input["MinValue"]);
        unset($Input["MaxValue"]);
    }

    # change type if needed
    if (isset($Input["Type"])) {
        $Field->type($Input["Type"]);
    }

    # handle the privileges separately
    foreach (array("View", "Author", "Edit") as $PrivilegeType) {
        $PrivilegeTypeName = $PrivilegeType . "ingPrivileges";

        # the privilege type is valid and it has a valid logic value
        if (isset($Input[$PrivilegeTypeName])) {
            if ($PrivilegeType == "View") {
                # pull out the new and old privilege sets
                $NewPrivs = $Input[$PrivilegeTypeName];
                $OldPrivs = $Field->{$PrivilegeTypeName}();

                # see which flags they look for
                $NewFlagsChecked = $NewPrivs->PrivilegeFlagsChecked();
                $OldFlagsChecked = $OldPrivs->PrivilegeFlagsChecked();

                # sort both to be sure the order is consistent
                sort($NewFlagsChecked);
                sort($OldFlagsChecked);

                # see which user fields they check
                $NewUserFieldsChecked = array_unique(array_merge(
                    $NewPrivs->FieldsWithUserComparisons("=="),
                    $NewPrivs->FieldsWithUserComparisons("!=")
                ));
                $OldUserFieldsChecked = array_unique(array_merge(
                    $OldPrivs->FieldsWithUserComparisons("=="),
                    $OldPrivs->FieldsWithUserComparisons("!=")
                ));

                # sort for consistency
                sort($NewUserFieldsChecked);
                sort($OldUserFieldsChecked);

                # if the fields checked or the flags checked have been
                # modified, flush the user perms cache to remove
                # potentially stale values
                if ($NewFlagsChecked != $OldFlagsChecked ||
                     $NewUserFieldsChecked != $OldUserFieldsChecked) {
                    $GLOBALS["AF"]->ClearPageCache();
                    RecordFactory::ClearViewingPermsCache();
                }
            }

            # save the privileges
            $Field->{$PrivilegeTypeName}($Input[$PrivilegeTypeName]);
        }

        # remove the privilege-related values so they aren't "saved" below
        unset($Input[$PrivilegeTypeName]);
        unset($Input[$PrivilegeTypeName."Logic"]);
    }

    $DeprecatedFunctions = array(
        "ViewingPrivilege", "EditingPrivilege",
        "AuthoringPrivilege","TreeBrowsingPrivilege"
    );

    $ClearWhenEmpty = ["Label", "Instructions"];
    $ClearWhenNull = ["DefaultQualifier"];
    foreach ($Input as $Key => $Value) {
        # do not call deprecated functions that happen to have the same
        # names as some of our form fields
        if (in_array($Key, $DeprecatedFunctions)) {
            continue;
        }

        # type was already handled
        if ($Key == "Type") {
            continue;
        }

        # remove leading/trailing whitespace from strings
        if (is_string($Value)) {
            $Value = trim($Value);
        }

        # if no value was provided and this attribute should not be cleared, move on
        if ($Value === "" && !in_array($Key, $ClearWhenEmpty)) {
            continue;
        }

        # if NULL was provided and this attribute should be cleared when NULL, switch
        # $Value to false so that Database::updateXXValue() methods will clear the
        # current setting
        if (is_null($Value) && in_array($Key, $ClearWhenNull)) {
            $Value = false;
        }

        # otherwise, set the updated value
        $Field->$Key($Value);
    }

    if ($Field->Type() == MetadataSchema::MDFTYPE_TREE) {
        $DefaultQualifier = $Field->DefaultQualifier();

        if ($Field->UsesQualifiers() && $DefaultQualifier) {
            # percolate the default qualifier for tree fields
            SetQualifierIdForClassifications($Field, $DefaultQualifier);
        }
    }

    if ($Field->Type() == MetadataSchema::MDFTYPE_CONTROLLEDNAME) {
        $DefaultQualifier = $Field->DefaultQualifier();

        if ($Field->UsesQualifiers() && $DefaultQualifier) {
            # set the default qualifier for controlled name fields
            SetQualifierIdForControlledNames($Field, $DefaultQualifier);
        }
    }

    # save list of referenceable schemas for Reference fields.
    if ($Field->Type() == MetadataSchema::MDFTYPE_REFERENCE) {
        $Field->ReferenceableSchemaIds($Input["ReferenceableSchemaIds"]);
    }

    # clear page cache in case any of our changes affect page output
    $GLOBALS["AF"]->ClearPageCache();
}

/**
 * If checkbox is ticked, update values of fields to default value
 * @param MetadataField $Field Metadata Field
 */

function UpdateValues(MetadataField $Field): void
{
    # check if field is of valid type, if not, return
    $TypesToUpdate = [
        MetadataSchema::MDFTYPE_TEXT,
        MetadataSchema::MDFTYPE_NUMBER,
        MetadataSchema::MDFTYPE_POINT,
    ];
    if (!in_array($Field->Type(), $TypesToUpdate)) {
        return;
    }
    # set ResourceFactory using SchemaId from field to get resources fitting specified schema
    $ResourceFactory = new RecordFactory($Field->SchemaId());

    # get resources where field to update is not set
    $Resources = $ResourceFactory->getIdsOfMatchingRecords([$Field->Id() => "NULL"]);
    foreach ($Resources as $Index => $ResourceId) {
        $Resources[$Index] = new Record($ResourceId);
    }

    # update field values
    foreach ($Resources as $Resource) {
        $Resource->Set($Field->Id(), $Field->DefaultValue());
    }
}

/**
 * Set the default qualifier for the tree field at all levels if not already set
 * to some other value.
 * @param MetadataField $Field Tree metadata field
 * @param int $DefaultQualifier Default qualifier ID
 * @return void
 */
function SetQualifierIdForClassifications(MetadataField $Field, $DefaultQualifier)
{
    $Factory = new ClassificationFactory();
    $Ids = $Factory->GetItemIds("FieldId = ".$Field->Id());

    foreach ($Ids as $Id) {
        $Classification = new Classification($Id);
        $OldQualifier = LoadQualifier($Classification->QualifierId());

        # if there is no old qualifier or the qualifier name is blank, set
        # the default qualifier ID to the provided value
        if (is_null($OldQualifier) || !$OldQualifier->Name()) {
            $Classification->QualifierId($DefaultQualifier);
        }
    }
}

/**
 * Set the default qualifier for the controlled name field if not already set to
 * some other value.
 * @param MetadataField $Field Controlled name metadata field
 * @param int $DefaultQualifier Default qualifier ID
 * @return void
 */
function SetQualifierIdForControlledNames(MetadataField $Field, $DefaultQualifier)
{
    $Factory = new ControlledNameFactory();
    $Ids = $Factory->GetItemIds("FieldId = ".$Field->Id());

    foreach ($Ids as $Id) {
        $ControlledName = new ControlledName($Id);
        $OldQualifier = loadQualifier($ControlledName->QualifierId());

        # if there is no old qualifier or the qualifier name is blank, set
        # the default qualifier ID to the provided value
        if (is_null($OldQualifier) || !$OldQualifier->Name()) {
            $ControlledName->QualifierId($DefaultQualifier);
        }
    }
}

/**
 * Delete the given field, reassigning the default browsing field and removing
 * OAI field mappings as necessary.
 * @param MetadataField $Field Metadata field
 * @throws Exception If the field being deleted is being used
 *      in privilege settings.
 * @return void
 */
function DeleteField(MetadataField $Field)
{
    # caller should check if this field is used in privilege settings
    if (MetadataSchema::FieldUsedInPrivileges($Field->Id())) {
        throw new Exception("Metadata fields used in privilege "
                ."settings cannot be deleted.");
    }

    $Schema = new MetadataSchema($Field->SchemaId());
    $FieldId = $Field->Id();
    $IntConfig = InterfaceConfiguration::getInstance();

    # re-assign browsing field ID if that is what is being deleted and the
    # schema is the default one
    if ($IntConfig->getInt("BrowsingFieldId") == $FieldId
        && $Schema->Id() == MetadataSchema::SCHEMAID_DEFAULT) {
        $TreeFields = $Schema->GetFields(MetadataSchema::MDFTYPE_TREE);

        # remove the field to be deleted from the list
        unset($TreeFields[$FieldId]);

        # make sure at least one tree field exists for browsing
        if (!count($TreeFields)) {
            return;
        }

        # reassign the browsing field
        $TreeField = array_shift($TreeFields);
        $IntConfig->setInt("BrowsingFieldId", $TreeField->Id());
    }

    # drop the field
    $Schema->DropField($FieldId);

    # clear page cache in case deleting the field affects page output
    $GLOBALS["AF"]->ClearPageCache();
}

/**
 * Get the input from the list of user input that is valid, based on which ones
 * are known to be invalid.
 * @param array $Input User input giving new settings. Keys are either MetadataField method names
 *   or one of (Viewing|Authoring|Editing)Priviliges(Logic)?.
 * @param array $InvalidInput List of invalid settings that the user attempted
 *   to provide (array values give setting names that were invalid and
 *   correspond to keys in the $Input array)
 * @param MetadataField $MField Metadata field being modified or null when creating new fields.
 * @return array valid user input fields
 * @see GetFieldDefaults()
 */
function GetValidInput(array $Input, array $InvalidInput, MetadataField $MField = null)
{
    $ValidInput = array();
    $Defaults = GetFieldDefaults($Input["Type"] ?? null);

    $ValidFields = array_keys(array_diff_key($Defaults, array_flip($InvalidInput)));
    $ValidFields = array_diff(
        $ValidFields,
        [
            "ViewingPrivileges",
            "ViewingPrivilegesLogic",
            "AuthoringPrivileges",
            "AuthoringPrivilegesLogic",
            "EditingPrivileges",
            "EditingPrivilegesLogic",
        ]
    );

    # list of fields where NULL is a valid input value, rather than an
    # indication that a default value should be used
    $CanBeNull = ["DefaultQualifier"];

    foreach ($ValidFields as $Key) {
        # if NULL was explicitly provided in the input data and is a valid
        # value for this field, pass NULL along
        if (array_key_exists($Key, $Input) &&
            is_null($Input[$Key]) && in_array($Key, $CanBeNull)) {
            $ValidInput[$Key] = null;
            continue;
        }

        # if no value or NULL provided, get value from current settings when
        # we have a field or from defaults when no field is available
        $ValidInput[$Key] = $Input[$Key] ??
            (is_null($MField) ? $Defaults[$Key] : $MField->$Key() );
    }

    # handle the privileges separately
    global $H_PrivsetUI;
    foreach ($H_PrivsetUI->getPrivilegeSetsFromForm() as $PrivType => $PrivData) {
        $ValidInput[$PrivType] = $PrivData;
    }

    return $ValidInput;
}

/**
* Get the page with necessary parameters when returning to the DBEditor page.
* @param mixed $Value MetadataSchema object, MetadataField object, or a schema ID.
* @param bool $PromptDBRebuild Whether we want to prompt the user to run a search DB rebuild.
* @return string Returns the page to return to.
*/
function GetReturnToPage($Value, bool $PromptDBRebuild = false): string
{
    $Suffix = "";

    # get the suffix from a metadata schema if not using the default schema
    if ($Value instanceof MetadataSchema) {
        if ($Value->Id() != MetadataSchema::SCHEMAID_DEFAULT) {
            $Suffix = "&SC=".$Value->id();
        }
    # get the suffix from a metadata field if not using the default schema
    } elseif ($Value instanceof MetadataField) {
        if ($Value->SchemaId() != MetadataSchema::SCHEMAID_DEFAULT) {
            $Suffix = "&SC=".$Value->schemaId();
        }
    # use the value directly if not using the default schema
    } elseif (!is_null($Value) && $Value != MetadataSchema::SCHEMAID_DEFAULT) {
        $Suffix = "&SC=" . urlencode($Value);
    }

    if ($PromptDBRebuild) {
        # add a param to prompt search database rebuild
        $Suffix .= "&PSDBR=1";
    }

    return "DBEditor" . $Suffix;
}

/**
* Get the metadata schema that is being edited.
* @return MetadataSchema Returns the metadata schema that is being edited.
*/
function GetMetadataSchema(): MetadataSchema
{
    return $GLOBALS["H_Schema"];
}

# ----- MAIN -----------------------------------------------------------------

global $H_Schema;
global $H_FieldData;
global $H_Errors;
global $H_FixedDefaults;
global $H_TypeBasedDefaults;
global $H_PrivsetUI;

$H_FixedDefaults = MetadataField::$CommonDefaults;
$H_TypeBasedDefaults = MetadataField::$TypeBasedDefaults;

# extract field and
$FieldIdGiven = StdLib::getArrayValue($_GET, "Id", false);
$Field = $FieldIdGiven ? LoadMetadataField(StdLib::getArrayValue($_GET, "Id")) : null;
$SchemaId = ($Field !== null) ?
          $Field->SchemaId() :
          StdLib::getArrayValue($_GET, "SC", StdLib::getArrayValue($_POST, "F_SchemaId"));
$H_PrivsetUI = new PrivilegeEditingUI($SchemaId);
$Operation = StdLib::getArrayValue($_POST, "Submit");
$Input = ExtractFieldInput($_POST);
$FieldRequiredFor = array(
    "Update Field", "Enable", "Disable", "Delete Field", "Populate Field..."
);

PageTitle(($FieldIdGiven ? "Edit" : "Add") . " Metadata Field");

if (!CheckAuthorization(PRIV_SYSADMIN, PRIV_COLLECTIONADMIN)) {
    return;
}

# field ID given, but it's invalid. go back to the list of fields
if ($FieldIdGiven && !($Field instanceof MetadataField)) {
    $GLOBALS["AF"]->SetJumpToPage(GetReturnToPage($SchemaId));
    return;
}

# no field ID given, but one is required. go back to the list of fields
if (!$FieldIdGiven && in_array($Operation, $FieldRequiredFor)) {
    $GLOBALS["AF"]->SetJumpToPage(GetReturnToPage($SchemaId));
    return;
}

# cancel editing
if ($Operation == "Cancel") {
    $GLOBALS["AF"]->SetJumpToPage(GetReturnToPage($SchemaId));
    return;
}

# no schema ID given
if (is_null($SchemaId)) {
    # use the schema ID for the field if posible
    if ($Field instanceof MetadataField) {
        $SchemaId = $Field->SchemaId();
    # otherwise use the default schema ID
    } else {
        $SchemaId = MetadataSchema::SCHEMAID_DEFAULT;
    }
}

# construct the schema object
$H_Schema = new MetadataSchema($SchemaId);

# add a new field
if ($Operation == "Add Field") {
    # validate the user input
    $Type = StdLib::getArrayValue($Input, "Type");
    $Errors = ValidateInput($Input, $Type);
    $ValidInput = GetValidInput($Input, array_keys($Errors));

    # at least one field value is invalid
    if (count($Errors)) {
        # load the data so the form can be displayed and the issues resolved
        $H_Errors = $Errors;
        $H_FieldData = GetFormDataFromDefaults($Type, $ValidInput);
    } else {
        # create a new field
        $Schema = $H_Schema;
        $Field = $Schema->AddField("XTEMPFIELDNAMEX", $Type);

        # save the input and make the field permanent
        SaveInput($Field, $ValidInput);
        $Field->IsTempItem(false);

        # update values to default if necesary
        if (isset($Input["UpdateValues"])) {
            UpdateValues($Field);
        }

        # go back to the field list
        $GLOBALS["AF"]->SetJumpToPage(GetReturnToPage($H_Schema));
        return;
    }
# update an existing field (field checks are above)
} elseif ($Operation == "Update Field") {
    # validate the user input
    $Errors = ValidateInputForField($Input, $Field);
    $ValidInput = GetValidInput($Input, array_keys($Errors), $Field);
    # save the valid fields, update values to default if update values is checked
    SaveInput($Field, $ValidInput);
    if (isset($Input["UpdateValues"])) {
        UpdateValues($Field);
    }

    $RecordFactory = new RecordFactory($Field->schemaId());
    # search DB rebuild should be queued if the collection is not large
    # we chose 2000 records to be the cutoff point for queue-able search DB rebuilds
    if ($RecordFactory->getItemCount() < 2000) {
        SearchEngine::queueDBRebuildForSchema($Field->schemaId());
        $JmpToPage = GetReturnToPage($Field);
    } else {
        # if the collection is large, prompt the user to do a search DB rebuild
        $JmpToPage = GetReturnToPage($Field, true);
    }

    # if there were no issues, just go back to the field list
    if (!count($Errors)) {
        $GLOBALS["AF"]->SetJumpToPage($JmpToPage);
        return;
    }

    # otherwise load the data so the form can be displayed and the issues
    # resolved
    $H_Errors = $Errors;
    $H_FieldData = GetFormDataFromField($Field);
# delete a field (field checks are above)
} elseif ($Operation == "Delete Field") {
    if (MetadataSchema::FieldUsedInPrivileges($Field->Id())) {
        $H_Errors["Enabled"] = "Cannot delete a metadata field "
                ."used in privilege setting.";
        $H_FieldData = GetFormDataFromField($Field);
        return;
    }

    $Confirmation = StdLib::getArrayValue($_POST, "F_Confirmation", false);

    # delete if confirmed
    if ($Confirmation) {
        $GLOBALS["AF"]->SetJumpToPage(GetReturnToPage($Field));
        DeleteField($Field);
        return;
    # otherwise request the user to confirm
    } else {
        # save valid user input in case the delete button was pressed accidentally
        $Input = ExtractFieldInput($_POST);
        $Errors = ValidateInputForField($Input, $Field);
        $ValidInput = GetValidInput($Input, array_keys($Errors));
        SaveInput($Field, $ValidInput);
        if (isset($Input["UpdateValues"])) {
            UpdateValues($Field);
        }

        $GLOBALS["AF"]->SetJumpToPage("ConfirmDeleteMetadataField&Id=".$Field->Id());
    }
# populate a tree field (field checks are above)
} elseif ($Operation == "Populate Field...") {
    # save valid user input
    $Input = ExtractFieldInput($_POST);
    $Errors = ValidateInputForField($Input, $Field);
    $ValidInput = GetValidInput($Input, array_keys($Errors));
    SaveInput($Field, $ValidInput);
    if (isset($Input["UpdateValues"])) {
        UpdateValues($Field);
    }

    $GLOBALS["AF"]->SetJumpToPage("PopulateField&ID=".$Field->Id());
# enable a field through AJAX, so no redirect (field checks are above)
} elseif ($Operation == "Enable") {
    $Field->Enabled(true);
    $GLOBALS["AF"]->ClearPageCache();
    $GLOBALS["AF"]->SuppressHTMLOutput();
    return;
# disable a field through AJAX, so no redirect (field checks are above)
} elseif ($Operation == "Disable") {
    if (ValidateEnabledForField($Input, $Field) === null) {
        $Field->Enabled(false);
    }
    $GLOBALS["AF"]->ClearPageCache();
    $GLOBALS["AF"]->SuppressHTMLOutput();
    return;
# the form was not submitted and a field ID was given. setup for editing the field
} elseif ($FieldIdGiven) {
    $H_Errors = array();
    if (isset($_GET["ERR"])) {
        $H_Errors[] = $_GET["ERR"];
    }
    $H_FieldData = GetFormDataFromField($Field);
# the form was not submitted and a field ID was not given. setup for adding a new field
} else {
    $H_Errors = array();
    $H_FieldData = GetFormDataFromDefaults();
}
