<?PHP
#
#   FILE: EditMetadataField.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2012-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus;
use Exception;
use ScoutLib\ApplicationFramework;
use ScoutLib\PluginManager;
use ScoutLib\StdLib;

# ----- LOCAL FUNCTIONS ------------------------------------------------------

/**
 * Load the field with the given field ID, checking that the field is valid.
 * @param int $Id Metadata field ID
 * @return MetadataField|null metadata field object if successful, else NULL
 */
function loadMetadataField($Id)
{
    # the schema ID is unnecessary here
    $Field = MetadataField::getField($Id);

    # make sure the field is valid
    if ($Field->status() !== MetadataSchema::MDFSTAT_OK) {
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

    if (!Qualifier::itemExists($Id)) {
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
function getFieldDefaults($Type)
{
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
function extractFieldInput(array $Values)
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
            if (isFieldValueBlank($Input[$Key]) || $Input[$Key] == "--") {
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

    # $Input doesn't contain AssociatedQualifierList if user deselects all options
    # add AssociatedQualifierList entry with empty array when this is the case
    if (!array_key_exists("AssociatedQualifierList", $Input)) {
        $Input["AssociatedQualifierList"] = array();
    }

    return $Input;
}

/**
 * Get the form data using the given field as a source.
 * @param MetadataField $Field Metadata field
 * @return array form data
 */
function getFormDataFromField(MetadataField $Field)
{
    $Schema = new MetadataSchema($Field->schemaId());

    $FormData = array();

    $DeprecatedMethods = array(
        "ViewingPrivilege", "EditingPrivilege",
        "AuthoringPrivilege", "TreeBrowsingPrivilege"
    );

    # start with the basic fields
    foreach (getFieldDefaults($Field->type()) as $Key => $Value) {
        if (method_exists("Metavus\\MetadataField", $Key) &&
            !in_array($Key, $DeprecatedMethods)) {
            $FormData[$Key] = $Field->$Key();
        }
    }

    # determine if the field can be populated
    $CanPopulate = $Field->type() == MetadataSchema::MDFTYPE_TREE;
    $CanPopulate = $CanPopulate ||
        $Field->type() == MetadataSchema::MDFTYPE_CONTROLLEDNAME;
    $CanPopulate = $CanPopulate ||
        $Field->type() == MetadataSchema::MDFTYPE_OPTION;

    # various other information
    $FormData["Id"] = $Field->id();
    $FormData["Type"] = $Field->type();
    $FormData["Name"] = $Field->name();
    $FormData["AllowedTypes"] = $Field->getAllowedConversionTypes();
    $FormData["TypeAsName"] = $Field->typeAsName();
    $FormData["AssociatedQualifierList"] = array_keys($Field->associatedQualifierList());
    $FormData["MappedName"] = $Schema->fieldToStdNameMapping($Field->id());
    $FormData["Owner"] = $Field->owner();
    $FormData["HasOwner"] = !!$Field->owner();
    $FormData["UsedByPrivsets"] = MetadataSchema::fieldUsedInPrivileges($Field->id());
    $FormData["FieldExists"] = true;
    $FormData["IsStandard"] =
        !is_null($Schema->fieldToStdNameMapping($Field->id()));
    $FormData["CanPopulate"] = $CanPopulate;
    $FormData["CanExport"] = $CanPopulate;
    $FormData["ObfuscateValueForAnonymousUsers"] = $Field->obfuscateValueForAnonymousUsers();

    $FormData["ReferenceableSchemaIds"] = $Field->referenceableSchemaIds();

    # privileges
    $FormData["ViewingPrivileges"] = $Field->viewingPrivileges();
    $FormData["AuthoringPrivileges"] = $Field->authoringPrivileges();
    $FormData["EditingPrivileges"] = $Field->editingPrivileges();

    # sane defaults that might get changed below
    $FormData["HasInactiveOwner"] = false;
    $FormData["IsLastTreeField"] = false;

    # whether or not the field has an inactive owner
    if ($Field->owner()) {
        $PluginManager = PluginManager::getInstance();
        $FormData["HasInactiveOwner"] = !in_array(
            $Field->owner(),
            $PluginManager->getActivePluginList()
        );
    }

    # whether or not the field is the last tree field
    if ($Field->type() == MetadataSchema::MDFTYPE_TREE) {
        if (count($Schema->getFields(MetadataSchema::MDFTYPE_TREE)) == 1) {
            $FormData["IsLastTreeField"] = true;
        }
    }

    if (InterfaceConfiguration::getInstance()->getBool("ResourceRatingsEnabled") &&
            $Field->name() == "Cumulative Rating") {
        $FormData["IsTogglable"] = false;
    } else {
        $FormData["IsTogglable"] = !$FormData["IsStandard"] &&
            (!$FormData["HasOwner"] || !$FormData["HasInactiveOwner"]);
    }

    return $FormData;
}

/**
 * Get the form data using the metadata field defaults.
 * @param MetadataSchema $Schema Schema containing the field.
 * @param int $Type Field type to assume (optional, defaults to text)
 * @param array $Override Values to override the defaults and must be valid
 * @return array form data
 */
function getFormDataFromDefaults(
    MetadataSchema $Schema,
    $Type = MetadataSchema::MDFTYPE_TEXT,
    array $Override = []
) {
    $FormData = array();

    # get the basic fields first
    foreach (getFieldDefaults($Type) as $Key => $Value) {
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
    $FormData["AllowedTypes"] = $Schema->getAllowedFieldTypes();
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
 * @param MetadataSchema $Schema Metadata schema that contains the field being
 *         edited or created.
 * @return array an array of errors (field name => error message)
 */
function validateInput(array &$Input, $Type, MetadataSchema $Schema)
{
    $Errors = array();

    # the first block of fields that apply to every metadata field type
    $Validation = array(
        "Type" => array("ValidateType", $Input),
        "Name" => array("ValidateName", $Input, $Schema),
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
    $H_PrivsetUI = new PrivilegeEditingUI($Schema->id());
    try {
        # if there's an error, this will throw an exception
        $H_PrivsetUI->getPrivilegeSetsFromForm();
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
function isFieldValueBlank($Value)
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
function validateInputForField(array $Input, MetadataField $Field)
{
    $Type = StdLib::getArrayValue($Input, "Type");

    $Schema = new MetadataSchema($Field->schemaId());
    # do generic validation first
    $Errors = validateInput($Input, $Type, $Schema);

    # do custom checking of the type, name, and enabled given the field
    $Errors["Type"] = validateTypeForField($Input, $Field);
    $Errors["Name"] = validateNameForField($Input, $Field);
    $Errors["Enabled"] = validateEnabledForField($Input, $Field);

    # remove fields that don't have an error message and reset indices
    return array_merge(array_filter($Errors));
}

/**
 * Validate the field enabled within the context of the given field.
 * @param array $Input User-provided input
 * @param MetadataField $Field Metadata field
 * @return null|string NULL on success or message on invalid input
 */
function validateEnabledForField(array $Input, MetadataField $Field)
{
    $Enabled = StdLib::getArrayValue($Input, "Enabled");
    $Id = $Field->id();
    $Schema = new MetadataSchema($Field->schemaId());

    # cannot disable fields used for privilege settings
    if (!$Enabled && MetadataSchema::fieldUsedInPrivileges($Id)) {
        return "Cannot disable fields used in privilege settings.";
    }

    # check if this field is currently mapped
    if (!$Enabled && $Schema->fieldToStdNameMapping($Id) !== null) {
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
function validateType(array $Input)
{
    $Type = StdLib::getArrayValue($Input, "Type");

    # the field type field is required
    if (isFieldValueBlank($Type)) {
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
function validateTypeForField(array $Input, MetadataField $Field)
{
    $Type = StdLib::getArrayValue($Input, "Type");

    # use the checks from ValidateType()
    $TypeError = validateType($Input);

    # if there's an error already, just return it
    if (!is_null($TypeError) && strlen($TypeError)) {
        return $TypeError;
    }

    $AllowedConversionTypes = $Field->getAllowedConversionTypes();

    # the (conversion) type must be okay
    if ($Type != $Field->type() && !isset($AllowedConversionTypes[$Type])) {
        # get the allowed conversion types as a human-readable string
        $HumanField = new HumanMetadataField($Field);
        $TypeString = $HumanField->getAllowedConversionTypes();

        return "The field must be one of the following types: ".$TypeString.".";
    }
    return null;
}

/**
 * Validate the field name.
 * @param array $Input User-provided input
 * @param MetadataSchema $Schema Metadata schema containing the field.
 * @return null|string NULL on success or message on invalid input
 */
function validateName(array $Input, MetadataSchema $Schema)
{
    $Name = StdLib::getArrayValue($Input, "Name");

    # the name field is required
    if (isFieldValueBlank($Name)) {
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
    if ($Schema->fieldExists(trim($Name))) {
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
function validateNameForField(array $Input, MetadataField $Field)
{
    $Name = StdLib::getArrayValue($Input, "Name");

    # the field name field is required
    if (isFieldValueBlank($Name)) {
        return "The <i>Name</i> field is required.";
    }

    # there are restrictions on what can be put in a name
    if (preg_match("/[^a-zA-Z0-9 \(\)]+/", $Name)) {
        return "The <i>Name</i> field can only contain "
            ."letters, numbers, spaces, and parentheses.";
    }

    $Schema = new MetadataSchema($Field->schemaId());

    $TrimmedName = trim($Name);
    if ($Field->name() == $TrimmedName) {
        return null;
    }
    # names must be unique when trailing space is removed

    if (!$Field->newNameForFieldIsValid($Name)) {
        return "The <i>Name</i> field value given is already in use.";
    }

    return null;
}

/**
 * Validate the field label.
 * @param array $Input User-provided input
 * @return null|string NULL on success or message on invalid input
 */
function validateLabel(array $Input)
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
function validateDefaultQualifier(array $Input)
{
    $DefaultQualifier = StdLib::getArrayValue($Input, "DefaultQualifier");

    # it's okay if the default qualifier isn't set
    if (isFieldValueBlank($DefaultQualifier)) {
        return null;
    }

    $Qualifier = loadQualifier($DefaultQualifier);

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
function validateUserPrivilegeRestrictions(array $Input)
{
    $PrivilegeFactory = new PrivilegeFactory();
    $UserPrivilegeRestrictions =
        StdLib::getArrayValue($Input, "UserPrivilegeRestrictions", array());

    # check each restriction
    foreach ($UserPrivilegeRestrictions as $Restriction) {
        if (!$PrivilegeFactory->privilegeValueExists($Restriction)) {
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
function validateUpdateMethod(array $Input)
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
function validatePointDefaultValue(array $Input)
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
function validateAssociatedQualifierList(array $Input)
{
    $AssociatedQualifierList = StdLib::getArrayValue($Input, "AssociatedQualifierList");

    # it's okay if there are none selected
    if (isFieldValueBlank($AssociatedQualifierList)) {
        return null;
    }

    $QualifierFactory = new QualifierFactory();
    $QualifierList = $QualifierFactory->getItemNames();

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
function validateBoolean(array $Input, $Key, $Name)
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
function validateNumeric(
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
    if (!$Required && isFieldValueBlank($Value)) {
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
function validateNonempty(array $Input, $Key, $Name)
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
function validatePrivilege(array $Input, $Key, $Name, $NotSetOk = false)
{
    $Value = StdLib::getArrayValue($Input, $Key);

    # if it's okay for the field not to be set
    if ($NotSetOk && isFieldValueBlank($Value)) {
        return null;
    }

    $PrivilegeFactory = new PrivilegeFactory();

    if (!$PrivilegeFactory->privilegeValueExists($Value)) {
        return "The privilege given for the <i>" . $Name . "</i> field is invalid.";
    }
    return null;
}

/**
* Validate the option list threshold value.
* @param array $Input User-provided input.
* @return null|string Returns NULL on success or message on invalid input.
*/
function validateOptionListThreshold(array $Input)
{
    $MaxAllowedValue = 100000000;
    $OptionListThreshold = StdLib::getArrayValue($Input, "OptionListThreshold");
    $AjaxThreshold = StdLib::getArrayValue($Input, "AjaxThreshold");

    # the field is required
    if (isFieldValueBlank($OptionListThreshold)) {
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
function validateAjaxThreshold(array $Input)
{
    $MaxAllowedValue = 100000000;
    $AjaxThreshold = StdLib::getArrayValue($Input, "AjaxThreshold");
    $OptionListThreshold = StdLib::getArrayValue($Input, "OptionListThreshold");

    # the field is required
    if (isFieldValueBlank($AjaxThreshold)) {
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
 * @return array Warning or error messages, if any.
 */
function saveInput(MetadataField $Field, array $Input): array
{
    $Warnings = [];
    $AF = ApplicationFramework::getInstance();

    if (array_key_exists("Name", $Input)) {
        $Input["Name"] = trim($Input["Name"]);
    }

    $AssociatedQualifierList = $Field->associatedQualifierList();

    # remove association with any current qualifiers
    foreach ($AssociatedQualifierList as $Id => $Name) {
        $Field->unassociateWithQualifier($Id);
    }

    $AssociateWith = StdLib::getArrayValue($Input, "AssociatedQualifierList", array());

    # associated with any given qualifiers
    foreach ($AssociateWith as $Id) {
        $Field->addQualifier($Id);
    }

    # some fields can't be edited for the cumulative rating field
    if ($Field->name() == "Cumulative Rating") {
        unset($Input["TextFieldSize"]);
        unset($Input["DefaultValue"]);
        unset($Input["MinValue"]);
        unset($Input["MaxValue"]);
    }

    # change type if needed
    if (isset($Input["Type"])) {
        $TypeChangeWarnings = $Field->checkIfTypeChangeWillModifyData($Input["Type"]);
        if (count($TypeChangeWarnings) > 0) {
            $WarningsField = StdLib::getArrayValue($_POST, "F_HasWarnings");
            # display a warning if there isn't one yet, otherwise change the type
            # given that the user confirmed the change by submitting again
            if ($WarningsField === "0") {
                foreach ($TypeChangeWarnings as $TypeChangeWarning) {
                    $Warnings[] = $TypeChangeWarning;
                }
                $Warnings[] = "Please resubmit this form with the type change to confirm "
                    ."that you want to change the field type anyway.";
            } elseif ($WarningsField === "1") {
                $Field->type($Input["Type"]);
            }
        } else {
            $Field->type($Input["Type"]);
        }
    }

    # handle the privileges separately
    foreach (array("view", "author", "edit") as $PrivilegeType) {
        $PrivilegeTypeName = $PrivilegeType . "ingPrivileges";

        # the privilege type is valid and it has a valid logic value
        if (isset($Input[$PrivilegeTypeName])) {
            if ($PrivilegeType == "view") {
                # pull out the new and old privilege sets
                $NewPrivs = $Input[$PrivilegeTypeName];
                $OldPrivs = $Field->{$PrivilegeTypeName}();

                # see which flags they look for
                $NewFlagsChecked = $NewPrivs->privilegeFlagsChecked();
                $OldFlagsChecked = $OldPrivs->privilegeFlagsChecked();

                # sort both to be sure the order is consistent
                sort($NewFlagsChecked);
                sort($OldFlagsChecked);

                # see which user fields they check
                $NewUserFieldsChecked = array_unique(array_merge(
                    $NewPrivs->fieldsWithUserComparisons("=="),
                    $NewPrivs->fieldsWithUserComparisons("!=")
                ));
                $OldUserFieldsChecked = array_unique(array_merge(
                    $OldPrivs->fieldsWithUserComparisons("=="),
                    $OldPrivs->fieldsWithUserComparisons("!=")
                ));

                # sort for consistency
                sort($NewUserFieldsChecked);
                sort($OldUserFieldsChecked);

                # if the fields checked or the flags checked have been
                # modified, flush the user perms cache to remove
                # potentially stale values
                if ($NewFlagsChecked != $OldFlagsChecked ||
                     $NewUserFieldsChecked != $OldUserFieldsChecked) {
                    RecordFactory::clearViewingPermsCache();
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

    if ($Field->type() == MetadataSchema::MDFTYPE_TREE) {
        $DefaultQualifier = $Field->defaultQualifier();

        if ($Field->usesQualifiers() && $DefaultQualifier) {
            # percolate the default qualifier for tree fields
            setQualifierIdForClassifications($Field, $DefaultQualifier);
        }
    }

    if ($Field->type() == MetadataSchema::MDFTYPE_CONTROLLEDNAME) {
        $DefaultQualifier = $Field->defaultQualifier();

        if ($Field->usesQualifiers() && $DefaultQualifier) {
            # set the default qualifier for controlled name fields
            setQualifierIdForControlledNames($Field, $DefaultQualifier);
        }
    }

    # save list of referenceable schemas for Reference fields.
    if ($Field->type() == MetadataSchema::MDFTYPE_REFERENCE) {
        $Field->referenceableSchemaIds($Input["ReferenceableSchemaIds"]);
    }

    # clear caches in case any of our changes affect page output
    $AF->clearPageCache();
    SearchFacetUI::clearCaches();

    return $Warnings;
}

/**
 * If checkbox is ticked, update values of fields to default value
 * @param MetadataField $Field Metadata Field
 */

function updateValues(MetadataField $Field): void
{
    # check if field is of valid type, if not, return
    $TypesToUpdate = [
        MetadataSchema::MDFTYPE_TEXT,
        MetadataSchema::MDFTYPE_NUMBER,
        MetadataSchema::MDFTYPE_POINT,
    ];
    if (!in_array($Field->type(), $TypesToUpdate)) {
        return;
    }
    # set ResourceFactory using SchemaId from field to get resources fitting specified schema
    $ResourceFactory = new RecordFactory($Field->schemaId());

    # get resources where field to update is not set
    $Resources = $ResourceFactory->getIdsOfMatchingRecords([$Field->id() => "NULL"]);
    foreach ($Resources as $Index => $ResourceId) {
        $Resources[$Index] = new Record($ResourceId);
    }

    # update field values
    foreach ($Resources as $Resource) {
        $Resource->set($Field->id(), $Field->defaultValue());
    }
}

/**
 * Set the default qualifier for the tree field at all levels if not already set
 * to some other value.
 * @param MetadataField $Field Tree metadata field
 * @param int $DefaultQualifier Default qualifier ID
 * @return void
 */
function setQualifierIdForClassifications(MetadataField $Field, $DefaultQualifier)
{
    $Factory = new ClassificationFactory();
    $Ids = $Factory->getItemIds("FieldId = ".$Field->id());

    foreach ($Ids as $Id) {
        $Classification = new Classification($Id);
        $OldQualifier = loadQualifier($Classification->qualifierId());

        # if there is no old qualifier or the qualifier name is blank, set
        # the default qualifier ID to the provided value
        if (is_null($OldQualifier) || !$OldQualifier->name()) {
            $Classification->qualifierId($DefaultQualifier);
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
function setQualifierIdForControlledNames(MetadataField $Field, $DefaultQualifier)
{
    $Factory = new ControlledNameFactory();
    $Ids = $Factory->getItemIds("FieldId = ".$Field->id());

    foreach ($Ids as $Id) {
        $ControlledName = new ControlledName($Id);
        $OldQualifier = loadQualifier($ControlledName->qualifierId());

        # if there is no old qualifier or the qualifier name is blank, set
        # the default qualifier ID to the provided value
        if (is_null($OldQualifier) || !$OldQualifier->name()) {
            $ControlledName->qualifierId($DefaultQualifier);
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
function deleteField(MetadataField $Field)
{
    # caller should check if this field is used in privilege settings
    if (MetadataSchema::fieldUsedInPrivileges($Field->id())) {
        throw new Exception("Metadata fields used in privilege "
                ."settings cannot be deleted.");
    }

    $Schema = new MetadataSchema($Field->schemaId());
    $FieldId = $Field->id();
    $IntConfig = InterfaceConfiguration::getInstance();

    # re-assign browsing field ID if that is what is being deleted and the
    # schema is the default one
    if ($IntConfig->getInt("BrowsingFieldId") == $FieldId
        && $Schema->id() == MetadataSchema::SCHEMAID_DEFAULT) {
        $TreeFields = $Schema->getFields(MetadataSchema::MDFTYPE_TREE);

        # remove the field to be deleted from the list
        unset($TreeFields[$FieldId]);

        # make sure at least one tree field exists for browsing
        if (!count($TreeFields)) {
            return;
        }

        # reassign the browsing field
        $TreeField = array_shift($TreeFields);
        $IntConfig->setInt("BrowsingFieldId", $TreeField->id());
    }

    # drop the field
    $Schema->dropField($FieldId);

    # clear page cache in case deleting the field affects page output
    ApplicationFramework::getInstance()->clearPageCache();
}

/**
 * Get the input from the list of user input that is valid, based on which ones
 * are known to be invalid.
 * @param array $Input User input giving new settings. Keys are either MetadataField method names
 *   or one of (Viewing|Authoring|Editing)Priviliges(Logic)?.
 * @param array $InvalidInput List of invalid settings that the user attempted
 *   to provide (array values give setting names that were invalid and
 *   correspond to keys in the $Input array)
 * @param PrivilegeEditingUI $PrivsetUI Privilege editing UI for the schema
 *         that contains (or will contain) this field.
 * @param MetadataField $MField Metadata field being modified or null when creating new fields.
 * @return array valid user input fields
 * @see getFieldDefaults()
 */
function getValidInput(
    array $Input,
    array $InvalidInput,
    PrivilegeEditingUI $PrivsetUI,
    ?MetadataField $MField = null
) {
    $ValidInput = array();
    $Defaults = getFieldDefaults($Input["Type"] ?? null);

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
    foreach ($PrivsetUI->getPrivilegeSetsFromForm() as $PrivType => $PrivData) {
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
function getReturnToPage($Value, bool $PromptDBRebuild = false): string
{
    $Suffix = "";

    # get the suffix from a metadata schema if not using the default schema
    if ($Value instanceof MetadataSchema) {
        if ($Value->id() != MetadataSchema::SCHEMAID_DEFAULT) {
            $Suffix = "&SC=".$Value->id();
        }
    # get the suffix from a metadata field if not using the default schema
    } elseif ($Value instanceof MetadataField) {
        if ($Value->schemaId() != MetadataSchema::SCHEMAID_DEFAULT) {
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

# ----- MAIN -----------------------------------------------------------------

$H_FixedDefaults = MetadataField::$CommonDefaults;
$H_TypeBasedDefaults = MetadataField::$TypeBasedDefaults;

$H_Warnings = [];

# extract field and
$FieldIdGiven = StdLib::getArrayValue($_GET, "Id", false);
$Field = $FieldIdGiven ? loadMetadataField(StdLib::getArrayValue($_GET, "Id")) : null;
$SchemaId = ($Field !== null) ?
          $Field->schemaId() :
          StdLib::getArrayValue($_GET, "SC", StdLib::getArrayValue($_POST, "F_SchemaId"));
$H_PrivsetUI = new PrivilegeEditingUI($SchemaId);
$Operation = StdLib::getArrayValue($_POST, "Submit");
$Input = extractFieldInput($_POST);
$FieldRequiredFor = array(
    "Update Field", "Enable", "Disable", "Delete Field", "Populate Field..."
);

$QualifierFactory = new QualifierFactory();
$H_Qualifiers = $QualifierFactory->getItemNames();

$AF = ApplicationFramework::getInstance();

$AF->setPageTitle(($FieldIdGiven ? "Edit" : "Add") . " Metadata Field");

if (!User::requirePrivilege(PRIV_SYSADMIN, PRIV_COLLECTIONADMIN)) {
    return;
}

# field ID given, but it's invalid. go back to the list of fields
if ($FieldIdGiven && !($Field instanceof MetadataField)) {
    $AF->setJumpToPage(getReturnToPage($SchemaId));
    return;
}

# no field ID given, but one is required. go back to the list of fields
if (!$FieldIdGiven && in_array($Operation, $FieldRequiredFor)) {
    $AF->setJumpToPage(getReturnToPage($SchemaId));
    return;
}

# cancel editing
if ($Operation == "Cancel") {
    $AF->setJumpToPage(getReturnToPage($SchemaId));
    return;
}

# no schema ID given
if (is_null($SchemaId)) {
    # use the schema ID for the field if posible
    if ($Field instanceof MetadataField) {
        $SchemaId = $Field->schemaId();
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

    $Errors = validateInput($Input, $Type, $H_Schema);
    $ValidInput = getValidInput($Input, array_keys($Errors), $H_PrivsetUI);

    # at least one field value is invalid
    if (count($Errors)) {
        # load the data so the form can be displayed and the issues resolved
        $H_Errors = $Errors;
        $H_FieldData = getFormDataFromDefaults($H_Schema, $Type, $ValidInput);
    } else {
        # create a new field
        $Schema = $H_Schema;
        $Field = $Schema->addField("XTEMPFIELDNAMEX", $Type);

        # save the input and make the field permanent
        $H_Warnings = saveInput($Field, $ValidInput);
        $Field->isTempItem(false);

        # update values to default if necesary
        if (isset($Input["UpdateValues"])) {
            updateValues($Field);
        }

        # go back to the field list
        $AF->setJumpToPage(getReturnToPage($H_Schema));
        return;
    }
# update an existing field (field checks are above)
} elseif ($Operation == "Update Field") {
    # validate the user input
    $Errors = validateInputForField($Input, $Field);
    $ValidInput = getValidInput(
        $Input,
        array_keys($Errors),
        $H_PrivsetUI,
        $Field
    );
    # save the valid fields, update values to default if update values is checked
    $H_Warnings = saveInput($Field, $ValidInput);
    if (isset($Input["UpdateValues"])) {
        updateValues($Field);
    }

    $RecordFactory = new RecordFactory($Field->schemaId());
    # search DB rebuild should be queued if the collection is not large
    # we chose 2000 records to be the cutoff point for queue-able search DB rebuilds
    if ($RecordFactory->getItemCount() < 2000) {
        SearchEngine::queueDBRebuildForSchema($Field->schemaId());
        $JmpToPage = getReturnToPage($Field);
    } else {
        # if the collection is large, prompt the user to do a search DB rebuild
        $JmpToPage = getReturnToPage($Field, true);
    }

    # if there were no issues and no warnings, just go back to the field list
    if (!count($Errors) && !count($H_Warnings)) {
        $AF->setJumpToPage($JmpToPage);
        return;
    }

    # otherwise load the data so the form can be displayed and the issues
    # resolved
    $H_Errors = $Errors;
    $H_FieldData = getFormDataFromField($Field);
# delete a field (field checks are above)
} elseif ($Operation == "Delete Field") {
    if (MetadataSchema::fieldUsedInPrivileges($Field->id())) {
        $H_Errors["Enabled"] = "Cannot delete a metadata field "
                ."used in privilege setting.";
        $H_FieldData = getFormDataFromField($Field);
        return;
    }

    $Confirmation = StdLib::getArrayValue($_POST, "F_Confirmation", false);

    # delete if confirmed
    if ($Confirmation) {
        $AF->setJumpToPage(getReturnToPage($Field));
        deleteField($Field);
        return;
    # otherwise request the user to confirm
    } else {
        # save valid user input in case the delete button was pressed accidentally
        $Input = extractFieldInput($_POST);
        $Errors = validateInputForField($Input, $Field);
        $ValidInput = getValidInput($Input, array_keys($Errors), $H_PrivsetUI);
        $H_Warnings = saveInput($Field, $ValidInput);
        if (isset($Input["UpdateValues"])) {
            updateValues($Field);
        }

        $AF->setJumpToPage("ConfirmDeleteMetadataField&Id=".$Field->id());
    }
# populate a tree field (field checks are above)
} elseif ($Operation == "Populate Field...") {
    # save valid user input
    $Input = extractFieldInput($_POST);
    $Errors = validateInputForField($Input, $Field);
    $ValidInput = getValidInput($Input, array_keys($Errors), $H_PrivsetUI);
    $H_Warnings = saveInput($Field, $ValidInput);
    if (isset($Input["UpdateValues"])) {
        updateValues($Field);
    }

    $AF->setJumpToPage("PopulateField&ID=".$Field->id());
# enable a field through AJAX, so no redirect (field checks are above)
} elseif ($Operation == "Enable") {
    $Field->enabled(true);
    $AF->clearPageCache();
    $AF->suppressHtmlOutput();
    return;
# disable a field through AJAX, so no redirect (field checks are above)
} elseif ($Operation == "Disable") {
    if (validateEnabledForField($Input, $Field) === null) {
        $Field->enabled(false);
    }
    $AF->clearPageCache();
    $AF->suppressHtmlOutput();
    return;
# the form was not submitted and a field ID was given. setup for editing the field
} elseif ($FieldIdGiven) {
    $H_Errors = array();
    if (isset($_GET["ERR"])) {
        $H_Errors[] = $_GET["ERR"];
    }
    $H_FieldData = getFormDataFromField($Field);
# the form was not submitted and a field ID was not given. setup for adding a new field
} else {
    $H_Errors = array();
    $H_FieldData = getFormDataFromDefaults($H_Schema);
}
