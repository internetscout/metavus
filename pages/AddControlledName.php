<?PHP
#
#   FILE:  AddControlledName.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2011-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus;
use ScoutLib\StdLib;
use ScoutLib\ApplicationFramework;

# ----- MAIN -----------------------------------------------------------------
$AF = ApplicationFramework::getInstance();
$AF->setPageTitle("Add Controlled Name");

# check that the user is authorized to view the page
if (!User::requirePrivilege(PRIV_NAMEADMIN)) {
    return;
}

# get the schema
$H_Schema = new MetadataSchema(
    StdLib::getArrayValue($_GET, "SC", MetadataSchema::SCHEMAID_DEFAULT)
);

$FieldId = intval(StdLib::getFormValue("F_FieldId", StdLib::getFormValue("FieldId")));
if ($H_Schema->fieldExists($FieldId)) {
    $H_Field = $H_Schema->getField($FieldId);
} else {
    $Fields = $H_Schema->getFields(MetadataSchema::MDFTYPE_CONTROLLEDNAME);
    $H_Field = count($Fields) ? array_shift($Fields) : null;
}

# extract form values
$H_ControlledName = StdLib::getFormValue("F_ControlledName");
$H_VariantName = StdLib::getFormValue("F_Variant");
$H_Qualifier = StdLib::getFormValue("F_Qualifier");

# values used only when the form is submitted
$H_SuccessfullyAdded = false;
$H_ErrorMessage = null;

if (!is_null($H_Field)) {
    $FormFields = [
        "ControlledName" => [
            "Type" => FormUI::FTYPE_TEXT,
            "Label" => "Controlled Name"
        ],
        "Variant" => [
            "Type" => FormUI::FTYPE_PARAGRAPH,
            "Label" => "Variants"
        ]
    ];

    if ($H_Field->usesQualifiers()) {
        if ($H_Field->hasItemLevelQualifiers()) {
            #first value is "--"
            $Items = $H_Field->associatedQualifierList();
            $Items["--"] = "--";
            ksort($Items);

            $FormFields["Qualifier"] = [
                "Type" => FormUI::FTYPE_OPTION,
                "Label" => "Qualifier",
                "Options" => $Items
            ];
        } elseif (Qualifier::itemExists($H_Field->defaultQualifier())) {
            $Qualifier = new Qualifier($H_Field->defaultQualifier());
            $FormFields["Qualifier"] = [
                "Type" => FormUI::FTYPE_TEXT,
                "Label" => "Qualifier",
                "Value" => $Qualifier->name(),
                "ReadOnly" => true
            ];
        }
    }
    $H_FormUI = new FormUI($FormFields);
}

# if the form was submitted
if (StdLib::getFormValue("Submit") == "Add") {
    if (!strlen(trim($H_ControlledName))) {
        $H_ErrorMessage = "The controlled name cannot be blank.";
        return;
    }

    # check if provided name already exists, if not, create one
    if (ControlledName::controlledNameExists($H_ControlledName, $H_Field->id())) {
        $H_ErrorMessage = "The controlled name already exists.";
        return;
    }

    $CN = ControlledName::create($H_ControlledName, $H_Field->id());
    if ($H_Qualifier !== "--") {
        $CN->qualifierId($H_Qualifier);
    }
    if (!is_null($H_VariantName) && strlen(trim($H_VariantName))) {
        $CN->variantName($H_VariantName);
    }
    $H_SuccessfullyAdded = true;
}
