<?PHP
#
#   FILE:  EditOptionList.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2012-2021 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#

namespace Metavus;

use ScoutLib\StdLib;

PageTitle("Edit Option List");
if (!CheckAuthorization(PRIV_NAMEADMIN)) {
    return;
}


# ----- CONFIGURATION  -------------------------------------------------------

# ----- EXPORTED FUNCTIONS ---------------------------------------------------
# (functions intended for use in corresponding HTML file)

# ----- LOCAL FUNCTIONS ------------------------------------------------------
# (functions intended for use only within this file)

# ----- MAIN -----------------------------------------------------------------

if (isset($_GET["FI"]) || isset($_POST["F_FieldId"])) {
    $FieldId = intval(isset($_GET["FI"]) ? $_GET["FI"] : $_POST["F_FieldId"]);

    $Field = new MetadataField($FieldId);
    if ($Field && ($Field->Type() == MetadataSchema::MDFTYPE_OPTION)) {
        # reload the schema using the field's schema ID
        $Schema = new MetadataSchema($Field->SchemaId());

        $G_Field = $Field;
        $G_OptionNames = $G_Field->GetPossibleValues();
        $G_Options = array();
        foreach ($G_OptionNames as $Id => $Name) {
            $G_Options[$Id] = new ControlledName($Id);
        }
    }
} else {
    # get the schema ID or use the default one if not specified
    $SchemaId = StdLib::getArrayValue($_GET, "SC", MetadataSchema::SCHEMAID_DEFAULT);

    # retrieve field if specified
    $Schema = new MetadataSchema($SchemaId);
}


# if form submitted
if (isset($_POST["F_Submit"])) {
    # start action message block
    $G_Msgs[] = "For the <i>".$G_Field->GetDisplayName()."</i> option list:";

    # if user requested that changes be saved
    if ($_POST["F_Submit"] == "Save Changes") {
        # while there are field values to process
        $Index = 0;
        while (isset($_POST["F_OptionId".$Index])) {
            # save edited value
            $Id = $_POST["F_OptionId".$Index];
            if ($_POST["F_Option".$Index] != $G_Options[$Id]->Name()) {
                $OldName = $G_Options[$Id]->Name();
                $NewName = $_POST["F_Option".$Index];
                $G_Options[$Id]->Name($NewName);
                $G_Msgs[] = "option <i>".$OldName."</i> changed to <i>".$NewName."</i>";
            }

            # if change requested and value selected
            if (isset($_POST["F_ConfirmRemap"])
                    && ($_POST["F_ConfirmRemap"] == $Id)
                    && ($_POST["F_RemapTo".$Index] != -1)) {
                # change all usage of this option to specified option
                $G_Msgs[] = "associations for option <i>".$G_Options[$Id]->Name()
                        ."</i> remapped to <i>"
                        .$G_Options[$_POST["F_RemapTo".$Index]]->Name()."</i>";
                $G_Options[$Id]->RemapTo($_POST["F_RemapTo".$Index]);
            # else if deletion requested
            } elseif (isset($_POST["F_ConfirmDelete".$Index])) {
                $G_Msgs[] = "option <i>".$G_Options[$Id]->Name()."</i> deleted";
                $G_Options[$Id]->destroy(true);
            }

            # move to next field
            $Index++;
        }

        # save new default setting
        $DefaultValue = StdLib::getArrayValue($_POST, "F_Default");
        if ($DefaultValue == -1 || is_null($DefaultValue)) {
            $DefaultValue = false;
        }
        if ($DefaultValue != $G_Field->DefaultValue()) {
            $G_Field->DefaultValue($DefaultValue);

            if ($G_Field->Type() == MetadataSchema::MDFTYPE_OPTION
                && is_array($DefaultValue) && count($DefaultValue)) {
                $Message = "default value changed to ";
                $Count = 0;

                foreach ($DefaultValue as $Value) {
                    $Name = defaulthtmlentities($G_Options[$Value]->Name());
                    $Message .= "<i>".$Name."</i>";

                    $Count++;

                    if ($Count < count($DefaultValue)) {
                        $Message .= ", ";
                    }
                }

                $G_Msgs[] = $Message;
            } else {
                $G_Msgs[] = "default value "
                        .(($DefaultValue == null) ? "cleared"
                        : "changed to <i>"
                                .$G_Options[$DefaultValue]->Name()."</i>");
            }
        }

        # if new value supplied
        if (isset($_POST["F_ConfirmAdd"]) && strlen(trim($_POST["F_AddName"]))) {
            # add new value
            $NewName = ControlledName::create(trim($_POST["F_AddName"]), $G_Field->Id());
            $NewQualifierId = StdLib::getFormValue("F_AddQualifier");
            if (is_numeric($NewQualifierId)) {
                $NewName->qualifierId($NewQualifierId);
            }
            $G_Msgs[] = "new option <i>".$_POST["F_AddName"]."</i> added";
        }

        # reload options
        $G_OptionNames = $G_Field->GetPossibleValues();
        $G_Options = array();
        foreach ($G_OptionNames as $Id => $Name) {
            $G_Options[$Id] = new ControlledName($Id);
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
        $Values = $Field->DefaultValue();
        if (is_array($Values)) {
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
    # load list of possible fields
    $G_OptionFields = $Schema->GetFields(
        MetadataSchema::MDFTYPE_OPTION,
        MetadataSchema::MDFORDER_EDITING
    );
}
