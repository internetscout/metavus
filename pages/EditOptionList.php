<?PHP
#
#   FILE:  EditOptionList.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2012-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
#   @scout:phpstan

namespace Metavus;

use Exception;
use ScoutLib\StdLib;

# ----- MAIN -----------------------------------------------------------------

PageTitle("Edit Option List");

if (!CheckAuthorization(PRIV_NAMEADMIN)) {
    return;
}

if (isset($_GET["FI"]) || isset($_POST["F_FieldId"])) {
    $FieldId = intval(isset($_GET["FI"]) ? $_GET["FI"] : $_POST["F_FieldId"]);

    $Field = MetadataField::getField($FieldId);
    if ($Field->Type() == MetadataSchema::MDFTYPE_OPTION) {
        # reload the schema using the field's schema ID
        $H_Schema = new MetadataSchema($Field->SchemaId());

        $G_Field = $Field;
        $H_OptionNames = $G_Field->GetPossibleValues();
        $H_Options = array();
        foreach ($H_OptionNames as $Id => $Name) {
            $H_Options[$Id] = new ControlledName($Id);
        }
    }
} else {
    # get the schema ID or use the default one if not specified
    $SchemaId = StdLib::getArrayValue($_GET, "SC", MetadataSchema::SCHEMAID_DEFAULT);

    # retrieve field if specified
    $H_Schema = new MetadataSchema($SchemaId);
}


# if form submitted
if (isset($_POST["Submit"])) {
    # check if required variable G_Field is set
    if (!isset($G_Field)) {
        throw new Exception("Attempt to edit option list from unknown field.");
    }

    # if either of the required variables are not loaded, reload them
    if (!isset($H_OptionNames) || !isset($H_Options)) {
        $H_OptionNames = $G_Field->GetPossibleValues();
        $H_Options = array();
        foreach ($H_OptionNames as $Id => $Name) {
            $H_Options[$Id] = new ControlledName($Id);
        }
    }

    # start action message block
    $G_Msgs[] = "For the <i>".$G_Field->GetDisplayName()."</i> option list:";

    # if user requested that changes be saved
    if ($_POST["Submit"] == "Save Changes") {
        $ToDelete = [];

        # while there are field values to process
        $Index = 0;
        while (isset($_POST["F_OptionId".$Index])) {
            # save edited value
            $Id = $_POST["F_OptionId".$Index];
            if ($_POST["F_Option".$Index] != $H_Options[$Id]->Name()) {
                $OldName = $H_Options[$Id]->Name();
                $NewName = $_POST["F_Option".$Index];
                $H_Options[$Id]->Name($NewName);
                $G_Msgs[] = "option <i>".$OldName."</i> changed to <i>".$NewName."</i>";
            }

            # if change requested and value selected
            if (isset($_POST["F_ConfirmRemap"])
                    && ($_POST["F_ConfirmRemap"] == $Id)
                    && ($_POST["F_RemapTo".$Index] != -1)) {
                # change all usage of this option to specified option
                $G_Msgs[] = "associations for option <i>".$H_Options[$Id]->Name()
                        ."</i> remapped to <i>"
                        .$H_Options[$_POST["F_RemapTo".$Index]]->Name()."</i>";
                $H_Options[$Id]->RemapTo($_POST["F_RemapTo".$Index]);
            # else if deletion requested
            } elseif (isset($_POST["F_ConfirmDelete".$Index])) {
                $FieldDefault = $G_Field->defaultValue();
                if ((is_array($FieldDefault) && in_array($Id, $FieldDefault)) ||
                       $FieldDefault == $Id) {
                    $G_Msgs[] = "cannot delete default value <i>".$H_Options[$Id]->Name()."</i>";
                } else {
                    $ToDelete[] = $Id;
                }
            }

            # move to next field
            $Index++;
        }

        # save new default setting
        $DefaultValue = StdLib::getArrayValue($_POST, "F_Default");
        if ($DefaultValue == -1 || is_null($DefaultValue)) {
            $DefaultValue = false;
        }
        if ($DefaultValue != $G_Field->defaultValue()
            && $G_Field->type() == MetadataSchema::MDFTYPE_OPTION
            && is_array($DefaultValue) && count($DefaultValue) > 0) {
            # unset all deleted options before setting default values
            foreach ($DefaultValue as $DefaultIndex => $Value) {
                $DeleteIndex = array_search($Value, $ToDelete);
                if ($DeleteIndex !== false) {
                    $G_Msgs[] =
                        "option <i>".$H_Options[$Value]->name()."</i> cannot be deleted"
                        ." and set as the default value at the same time.";
                    unset($DefaultValue[$DefaultIndex]);
                    unset($ToDelete[$DeleteIndex]);
                }
            }
            $DefaultValue = array_values($DefaultValue);
        }
        if ($DefaultValue != $G_Field->DefaultValue()) {
            if ($G_Field->Type() == MetadataSchema::MDFTYPE_OPTION
                && is_array($DefaultValue) && count($DefaultValue)) {
                $G_Field->DefaultValue($DefaultValue);
                $NameEscFunc = function ($Value) use ($H_Options) {
                    $Name = $H_Options[$Value]->Name();
                    return  "<i>".defaulthtmlentities($Name)."</i>";
                };
                $Names = array_map($NameEscFunc, $DefaultValue);
                $Message = "default value changed to "
                            .implode(", ", $Names);
                $G_Msgs[] = $Message;
            } elseif ($DefaultValue !== false && array_search($DefaultValue, $ToDelete) !== false) {
                        $G_Msgs[] =
                            "option <i>".$H_Options[$DefaultValue]->name()."</i> cannot be deleted"
                            ." and set as the default value at the same time.";
                        unset($ToDelete[array_search($DefaultValue, $ToDelete)]);
            } else {
                $G_Field->DefaultValue($DefaultValue);
                $G_Msgs[] = "default value "
                        .(($DefaultValue == null) ? "cleared"
                        : "changed to <i>"
                                .$H_Options[$DefaultValue]->Name()."</i>");
            }
        }

        foreach ($ToDelete as $Id) {
            $G_Msgs[] = "option <i>".$H_Options[$Id]->Name()."</i> deleted";
            $H_Options[$Id]->destroy(true);
        }

        # if new value supplied
        if (isset($_POST["F_ConfirmAdd"]) && strlen(trim($_POST["F_AddName"]))) {
            # add new value
            $NewName = ControlledName::create(trim($_POST["F_AddName"]), $G_Field->Id());
            $NewQualifierId = StdLib::getFormValue("F_AddQualifier");
            if (is_numeric($NewQualifierId)) {
                $NewName->qualifierId((int) $NewQualifierId);
            }
            $G_Msgs[] = "new option <i>".$_POST["F_AddName"]."</i> added";
        }

        # reload options
        $H_OptionNames = $G_Field->GetPossibleValues();
        $H_Options = array();
        foreach ($H_OptionNames as $Id => $Name) {
            $H_Options[$Id] = new ControlledName($Id);
        }
    } else {
        $G_Msgs[] = "editing cancelled and any changes discarded";
    }

    # update unset values if desired
    if (isset($_POST["F_UpdateValues"]) && $G_Field->DefaultValue() !== false) {
        # create resource factory and get resources with unset option fields if default field is set
        $ResourceFactory = new RecordFactory($G_Field->SchemaId());
        $ResourceIds = $ResourceFactory->getItemIds("RecordId NOT IN (SELECT RecordId ".
                "FROM RecordNameInts WHERE ControlledNameId IN ".
                "(SELECT ControlledNameId FROM ControlledNames ".
                "WHERE FieldId = ".$G_Field->Id()."))");

        # convert default value to set-able array
        $Values = $G_Field->DefaultValue();
        if (is_array($Values)) {
            # check if required variable DefaultValue is set
            if (!isset($DefaultValue)) {
                $DefaultValue = StdLib::getArrayValue($_POST, "F_Default");
            }
            $Values = array_fill_keys($DefaultValue, 1);
        }

        # iterate through and set unset option values
        foreach ($ResourceIds as $ResourceId) {
            $Resource = new Record($ResourceId);
            $Resource->set($G_Field->Id(), $Values);
        }
        $G_Msgs[] = "default value set on records that had no value";
    }

    # unset field so that list of possible fields is brought up again
    unset($G_Field);

    if (count($G_Msgs) == 1) {
            $G_Msgs[] = "no changes made";
    }
}

# if no valid field specified
if (!isset($G_Field)) {
    # check if required variable Schema is set
    if (!isset($H_Schema)) {
        throw new Exception("Variable \$H_Schema not set.");
    }

    # load list of possible fields
    $H_OptionFields = $H_Schema->GetFields(
        MetadataSchema::MDFTYPE_OPTION,
        MetadataSchema::MDFORDER_EDITING
    );
}
