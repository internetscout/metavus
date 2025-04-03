<?PHP
#
#   FILE:  AddControlledName.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2011-2021 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus;

use ScoutLib\StdLib;
use ScoutLib\ApplicationFramework;

# ----- MAIN -----------------------------------------------------------------

PageTitle("Add Controlled Name");

# check that the user is authorized to view the page
if (!CheckAuthorization(PRIV_NAMEADMIN)) {
    return;
}

$AF = ApplicationFramework::getInstance();

# get the schema
$H_Schema = new MetadataSchema(
    StdLib::getArrayValue($_GET, "SC", MetadataSchema::SCHEMAID_DEFAULT)
);

$FieldId = intval(StdLib::getFormValue("F_FieldId", StdLib::getFormValue("FieldId")));
if ($H_Schema->FieldExists($FieldId)) {
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

    if ($H_Field->UsesQualifiers()) {
        if ($H_Field->HasItemLevelQualifiers()) {
            #first value is "--"
            $Items = $H_Field->AssociatedQualifierList();
            $Items["--"] = "--";
            ksort($Items);

            $FormFields["Qualifier"] = [
                "Type" => FormUI::FTYPE_OPTION,
                "Label" => "Qualifier",
                "Options" => $Items
            ];
        } elseif (Qualifier::ItemExists($H_Field->DefaultQualifier())) {
            $Qualifier = new Qualifier($H_Field->DefaultQualifier());
            $FormFields["Qualifier"] = [
                "Type" => FormUI::FTYPE_TEXT,
                "Label" => "Qualifier",
                "Value" => $Qualifier->Name(),
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
    if (ControlledName::ControlledNameExists($H_ControlledName, $H_Field->Id())) {
        $H_ErrorMessage = "The controlled name already exists.";
        return;
    }

    $CN = ControlledName::Create($H_ControlledName, $H_Field->Id());
    if ($H_Qualifier !== "--") {
        $CN->QualifierId($H_Qualifier);
    }
    if (!is_null($H_VariantName) && strlen(trim($H_VariantName))) {
        $CN->VariantName($H_VariantName);
    }
    $H_SuccessfullyAdded = true;
}
