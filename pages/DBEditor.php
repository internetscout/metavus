<?PHP
#
#   FILE:  DBEditor.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2011-2021 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#

namespace Metavus;

use ScoutLib\StdLib;

# ----- EXPORTED FUNCTIONS ---------------------------------------------------

function PrintTextFieldAttributes(MetadataSchema $Schema)
{
    # Get the fields for the schema
    $Fields = $Schema->GetFields(
        MetadataSchema::MDFTYPE_TEXT,
        MetadataSchema::MDFORDER_ALPHABETICAL,
        true
    );

    foreach ($Fields as $Field) {
        PrintTextFieldRow($Field);
    }
}

function PrintParagraphFieldAttributes(MetadataSchema $Schema)
{
    # Get the fields for the schema
    $Fields = $Schema->GetFields(
        MetadataSchema::MDFTYPE_PARAGRAPH,
        MetadataSchema::MDFORDER_ALPHABETICAL,
        true
    );

    foreach ($Fields as $Field) {
        PrintParagraphFieldRow($Field);
    }
}

function PrintNumberFieldAttributes(MetadataSchema $Schema)
{
    # Get the fields for the schema
    $Fields = $Schema->GetFields(
        MetadataSchema::MDFTYPE_NUMBER,
        MetadataSchema::MDFORDER_ALPHABETICAL,
        true
    );

    foreach ($Fields as $Field) {
        PrintNumberFieldRow($Field);
    }
}

function PrintPointFieldAttributes(MetadataSchema $Schema)
{
    # Get the fields for the schema
    $Fields = $Schema->GetFields(
        MetadataSchema::MDFTYPE_POINT,
        MetadataSchema::MDFORDER_ALPHABETICAL,
        true
    );

    foreach ($Fields as $Field) {
        PrintPointFieldRow($Field);
    }
}

function PrintDateFieldAttributes(MetadataSchema $Schema)
{
    # Get the fields for the schema
    $Fields = $Schema->GetFields(
        MetadataSchema::MDFTYPE_DATE,
        MetadataSchema::MDFORDER_ALPHABETICAL,
        true
    );

    foreach ($Fields as $Field) {
        PrintDateFieldRow($Field);
    }
}

function PrintTimestampFieldAttributes(MetadataSchema $Schema)
{
    # Get the fields for the schema
    $Fields = $Schema->GetFields(
        MetadataSchema::MDFTYPE_TIMESTAMP,
        MetadataSchema::MDFORDER_ALPHABETICAL,
        true
    );

    foreach ($Fields as $Field) {
        PrintTimestampFieldRow($Field);
    }
}

function PrintFlagFieldAttributes(MetadataSchema $Schema)
{
    # Get the fields for the schema
    $Fields = $Schema->GetFields(
        MetadataSchema::MDFTYPE_FLAG,
        MetadataSchema::MDFORDER_ALPHABETICAL,
        true
    );

    foreach ($Fields as $Field) {
        PrintFlagFieldRow($Field);
    }
}

function PrintTreeFieldAttributes(MetadataSchema $Schema)
{
    # Get the fields for the schema
    $Fields = $Schema->GetFields(
        MetadataSchema::MDFTYPE_TREE,
        MetadataSchema::MDFORDER_ALPHABETICAL,
        true
    );

    foreach ($Fields as $Field) {
        PrintTreeFieldRow($Field);
    }
}

function PrintControlledNameFieldAttributes(MetadataSchema $Schema)
{
    # Get the fields for the schema
    $Fields = $Schema->GetFields(
        MetadataSchema::MDFTYPE_CONTROLLEDNAME,
        MetadataSchema::MDFORDER_ALPHABETICAL,
        true
    );

    foreach ($Fields as $Field) {
        PrintControlledNameFieldRow($Field);
    }
}

/**
 * THIS FUNCTION HAS BEEN DEPRECATED
 * Use PrintControlledNameFieldAttributes() instead
 */
function PrintContolledNameFieldAttributes(MetadataSchema $Schema)
{
    PrintControlledNameFieldAttributes($Schema);
}

function PrintOptionFieldAttributes(MetadataSchema $Schema)
{
    # Get the fields for the schema
    $Fields = $Schema->GetFields(
        MetadataSchema::MDFTYPE_OPTION,
        MetadataSchema::MDFORDER_ALPHABETICAL,
        true
    );

    foreach ($Fields as $Field) {
        PrintOptionFieldRow($Field);
    }
}

function PrintUserFieldAttributes(MetadataSchema $Schema)
{
    # Get the fields for the schema
    $Fields = $Schema->GetFields(
        MetadataSchema::MDFTYPE_USER,
        MetadataSchema::MDFORDER_ALPHABETICAL,
        true
    );

    foreach ($Fields as $Field) {
        PrintUserFieldRow($Field);
    }
}

function PrintImageFieldAttributes(MetadataSchema $Schema)
{
    # Get the fields for the schema
    $Fields = $Schema->GetFields(
        MetadataSchema::MDFTYPE_IMAGE,
        MetadataSchema::MDFORDER_ALPHABETICAL,
        true
    );

    foreach ($Fields as $Field) {
        PrintImageFieldRow($Field);
    }
}

function PrintFileFieldAttributes(MetadataSchema $Schema)
{
    # Get the fields for the schema
    $Fields = $Schema->GetFields(
        MetadataSchema::MDFTYPE_FILE,
        MetadataSchema::MDFORDER_ALPHABETICAL,
        true
    );

    foreach ($Fields as $Field) {
        PrintFileFieldRow($Field);
    }
}

function PrintUrlFieldAttributes(MetadataSchema $Schema)
{
    # Get the fields for the schema
    $Fields = $Schema->GetFields(
        MetadataSchema::MDFTYPE_URL,
        MetadataSchema::MDFORDER_ALPHABETICAL,
        true
    );

    foreach ($Fields as $Field) {
        PrintUrlFieldRow($Field);
    }
}

function PrintEmailFieldAttributes(MetadataSchema $Schema)
{
    # Get the fields for the schema
    $Fields = $Schema->getFields(
        MetadataSchema::MDFTYPE_EMAIL,
        MetadataSchema::MDFORDER_ALPHABETICAL,
        true
    );

    foreach ($Fields as $Field) {
        PrintEmailFieldRow($Field);
    }
}

function PrintReferenceFieldAttributes(MetadataSchema $Schema)
{
    # Get the fields for the schema
    $Fields = $Schema->GetFields(
        MetadataSchema::MDFTYPE_REFERENCE,
        MetadataSchema::MDFORDER_ALPHABETICAL,
        true
    );

    foreach ($Fields as $Field) {
        PrintReferenceFieldRow($Field);
    }
}

function PrintFieldAttributes(MetadataSchema $Schema, int $FieldType, string $NameOfPrintFunction)
{
    # Get the fields for the schema
    $Fields = $Schema->getFields(
        $FieldType,
        MetadataSchema::MDFORDER_ALPHABETICAL,
        true
    );

    foreach ($Fields as $Field) {
        $NameOfPrintFunction($Field);
    }
}

function PrintDefaultValue($Field)
{
    $DefaultValue = $Field->DefaultValue();

    if ($DefaultValue) {
        $Type = $Field->Type();

        if ($Type == MetadataSchema::MDFTYPE_OPTION) {
            if (!is_array($DefaultValue)) {
                $DefaultValue = array($DefaultValue);
            }

            $Count = 0;

            foreach ($DefaultValue as $Value) {
                $ControlledName = new ControlledName($Value);

                print "<i>".defaulthtmlentities($ControlledName->Name())."</i>";

                $Count++;

                if ($Count < count($DefaultValue)) {
                    print ", ";
                }
            }
        } elseif ($Type == MetadataSchema::MDFTYPE_POINT) {
            $X = defaulthtmlentities($DefaultValue["X"]);
            $Y = defaulthtmlentities($DefaultValue["Y"]);

            print "X: <i>".$X."</i>, Y: <i>".$Y."</i>";
        } else {
            print "<i>".defaulthtmlentities($Field->DefaultValue())."</i>";
        }
    } else {
        print "[No default value]";
    }
}

function PrintHasItemLevelQualifiers($Field)
{
    print GetYesNo($Field->HasItemLevelQualifiers());
}

function PrintDefaultQualifier($Field)
{
    $DefaultQualifier = $Field->DefaultQualifier();
    if ($DefaultQualifier > 0) {
        $Qualifier = new Qualifier($Field->DefaultQualifier());
        print defaulthtmlentities($Qualifier->Name());
    } else {
        print "--";
    }
}

/**
 * Transform a list of privilege names in various formats to a list of only
 * those privilege names that are valid along with their values. This was taken
 * from the NavEditor plugin.
 * @param $Privileges an array of privilege names
 * @return an array of valid privileges, with the name as the key
 */
function TransformPrivileges(array $Privileges)
{
    $PrivilegeFactory = new PrivilegeFactory();
    $AllPrivileges = $PrivilegeFactory->GetPrivileges(true, false);
    $PrivilegeConstants = $PrivilegeFactory->GetPredefinedPrivilegeConstants();
    $ValidPrivileges = array();

    foreach ($Privileges as $Privilege) {
        # predefined privilege name
        if (in_array($Privilege, $PrivilegeConstants)) {
            $Key = $Privilege;
            $Value = array_search($Key, $PrivilegeConstants);

            $ValidPrivileges[$Key] = $Value;
        # predefined privilege name without the PRIV_ prefix
        } elseif (in_array("PRIV_".$Privilege, $PrivilegeConstants)) {
            $Key = "PRIV_".$Privilege;
            $Value = array_search($Key, $PrivilegeConstants);

            $ValidPrivileges[$Key] = $Value;
        # predefined privilege description or custom privilege name
        } elseif (in_array($Privilege, $AllPrivileges)) {
            $Key = $Privilege;
            $Value = array_search($Key, $AllPrivileges);

            $ValidPrivileges[$Key] = $Value;
        } elseif (array_key_exists($Privilege, $AllPrivileges)) {
            $Key = $AllPrivileges[$Privilege];
            $Value = $Privilege;

            $ValidPrivileges[$Key] = $Value;
        }
    }

    return $ValidPrivileges;
}

# ----- LOCAL FUNCTIONS ------------------------------------------------------

# translate 1=Yes, 0=No
function GetYesNo($Var)
{
    return ($Var ? "Yes" : "No");
}

# translate flag on or flag off label
function GetFlagValue($Field)
{
    return ($Field->DefaultValue() ? $Field->FlagOnLabel() :
                $Field->FlagOffLabel());
}

# ----- MAIN -----------------------------------------------------------------

PageTitle("Metadata Field Editor");

if (!CheckAuthorization(PRIV_SYSADMIN, PRIV_COLLECTIONADMIN)) {
    return;
}

# retrieve the schema ID
$SchemaId = StdLib::getArrayValue($_GET, "SC", MetadataSchema::SCHEMAID_DEFAULT);

# construct the schema
$H_Schema = new MetadataSchema($SchemaId);

# retrieve if user should be prompted to run a search DB rebuild
$H_PromptDBRebuild = StdLib::getArrayValue($_GET, "PSDBR", false);
