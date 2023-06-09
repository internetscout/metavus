<?PHP
#
#   FILE:  PrivilegeEditingUI.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2015-2021 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#

namespace Metavus;

use Exception;
use ScoutLib\HtmlOptionList;
use ScoutLib\ApplicationFramework;

/**
* User interface element for editing PrivilegeSets.  The enclosing form
* must have the class "priv-form".
*/
class PrivilegeEditingUI
{
    /**
    * Constructor for privilege editing UI.
    * @param int|array $Schemas SchemaId or array of SchemaIds that will be
    *       used for any fields referenced in privilege conditions
    *       (OPTIONAL, defaults to all Schemas)
    * @param array $MetadataFields Array of metadata field objects (keyed by
    *       FieldId) listing fields that should be displayed.  If this
    *       argument is specified, the $SchemaIds argument must be
    *       NULL.
    */
    public function __construct($Schemas = null, $MetadataFields = [])
    {
        $this->Fields = [];

        # if Schemas was NULL, use all schemas
        if ($Schemas === null) {
            $Schemas = MetadataSchema::getAllSchemas();
        } else {
            # ensure incoming value is an array
            if (!is_array($Schemas)) {
                $Schemas = [$Schemas];
            }

            # if we have an array of ints, convert to an array of MetadataSchema objects
            if (is_numeric(reset($Schemas))) {
                $NewSchemas = [];
                foreach ($Schemas as $SchemaId) {
                    $NewSchemas[$SchemaId] = new MetadataSchema($SchemaId);
                }
                $Schemas = $NewSchemas;
            }
        }

        # ensure incoming value is an array
        if (!is_array($MetadataFields)) {
            $MetadataFields = [$MetadataFields];
        }

        # if we have an array of ints, convert to an array of MetadataField objects
        if (is_numeric(reset($MetadataFields))) {
            $NewMetadataFields = [];
            foreach ($MetadataFields as $FieldId) {
                $NewMetadataFields[$FieldId] = new MetadataField($FieldId);
            }
            $MetadataFields = $NewMetadataFields;
        }

        # types we support, in the order they should be displayed
        $SupportedFieldTypesInOrder = [
            MetadataSchema::MDFTYPE_USER,
            MetadataSchema::MDFTYPE_FLAG,
            MetadataSchema::MDFTYPE_OPTION,
            MetadataSchema::MDFTYPE_TIMESTAMP,
            MetadataSchema::MDFTYPE_NUMBER
        ];

        # add all requested Schemas
        foreach ($Schemas as $SchemaId => $Schema) {
            # iterate over the supported types so that fields are
            # returned grouped by type
            foreach ($SupportedFieldTypesInOrder as $Type) {
                $this->Fields += $Schema->GetFields($Type);
            }
        }

        # and add requested fields
        foreach ($MetadataFields as $FieldId => $Field) {
            if (!in_array($Field->Type(), $SupportedFieldTypesInOrder)) {
                throw new Exception(
                    "Field ".$Field->Name()." (Id=".$Field->Id().")"
                    ." is invalid for PrivilegeEditing -- ".$Field->TypeAsName()
                    ." fields are not supported."
                );
            }
            $this->Fields[$FieldId] = $Field;
        }
    }

    /**
    * Display interface for editing specified privilege set.
    * @param string $Identifier Alphanumeric identifier for this privilege set.
    * @param PrivilegeSet $PrivilegeSet Current values for privilege set.
    * @param bool $IsNested For recursion only - DO NOT USE.
    */
    public function displaySet(
        string $Identifier,
        PrivilegeSet $PrivilegeSet,
        bool $IsNested = false
    ) {
        $AF = ApplicationFramework::getInstance();
        # include needed JavaScript
        $AF->RequireUIFile("PrivilegeEditingUI.js");

        # build form field prefix
        $FFPrefix = "F_Priv_".$Identifier."_";

        # retrieve privilege logic, conditions, subsets, and required user privileges
        $Logic = $PrivilegeSet->usesAndLogic() ? "AND" : "OR";
        $Conditions = $PrivilegeSet->getConditions();
        $Subsets = $PrivilegeSet->getSubsets();
        $RequiredUserPrivileges = $PrivilegeSet->getPrivileges();

        # add "Top-Level Logic" option list if we are at the top of the hierarchy
        if (!$IsNested) {
            print '<div class="priv-set">'
                .'<fieldset class="priv-fieldset priv-logic">'
                .'<label for="'.$FFPrefix.'Logic">Top-Level Logic:</label>';

            $OptList = new HtmlOptionList(
                $FFPrefix."Logic",
                ["AND" => "AND", "OR" => "OR"],
                $Logic
            );
            $OptList->printHtml();

            print '</fieldset>';
        }

        # if there are no conditions set
        if ($PrivilegeSet->isEmpty()) {
            # print out message indicating no conditions yet set
            print("<i>(no requirements)</i><br/>");
        } else {
            # print out each user privilege
            foreach ($RequiredUserPrivileges as $Privilege) {
                print "<fieldset class=\"priv-fieldset mv-peui-fieldset\">";

                $this->displaySubjectField($FFPrefix, "current_user");
                $this->displayOperatorField($FFPrefix);
                $this->displayValueField($FFPrefix, $Privilege);

                print "</fieldset>";
            }

            # print out each condition
            foreach ($Conditions as $Condition) {
                print "<fieldset class=\"priv-fieldset mv-peui-fieldset\">";

                $this->displaySubjectField($FFPrefix, $Condition["FieldId"]);
                $this->displayOperatorField($FFPrefix, $Condition["Operator"]);

                try {
                    $Field = new MetadataField($Condition["FieldId"]);
                } catch (Exception $e) {
                    # do nothing here, but we'd like to continue
                }

                if (isset($Field) && $Field->type() == MetadataSchema::MDFTYPE_OPTION) {
                    # Option fields use the selector menu, rather than a
                    #       form field.
                    # Values are ControlledName Ids, prefixed with a "C"
                    #       to distinguish them from privilge flag numbers.
                    $this->displayValueField(
                        $FFPrefix,
                        "C".$Condition["Value"],
                        "NULL"
                    );
                } else {
                    $this->displayValueField($FFPrefix, null, $Condition["Value"]);
                }

                print "</fieldset>";
            }

            # print out each subset
            foreach ($Subsets as $Subset) {
                print "<fieldset class=\"priv-fieldset mv-peui-fieldset\">";

                $this->displaySubjectField($FFPrefix, "set_entry");
                $this->displayOperatorField($FFPrefix);
                $this->displayValueField(
                    $FFPrefix,
                    $Subset->usesAndLogic() ? "AND" : "OR"
                );

                # end the fieldset for the set entry row
                print "</fieldset>";

                # print the nested fields
                $this->displaySet($Identifier, $Subset, true);

                # begin a new fieldset for the set exit row
                print "<fieldset class=\"priv-fieldset mv-peui-fieldset\">";

                $this->displaySubjectField($FFPrefix, "set_exit");
                $this->displayOperatorField($FFPrefix);
                $this->displayValueField($FFPrefix);

                print "</fieldset>";
            }
        }

        # if we are at the top level
        if (!$IsNested) {
            $NumBlankRows = 6;

            # print a number of blank rows to be used if JavaScript is disabled
            for ($Index = 0; $Index < $NumBlankRows; $Index++) {
                print "<fieldset class=\"priv-fieldset mv-peui-fieldset priv-extra\">";
                $this->displaySubjectField($FFPrefix);
                $this->displayOperatorField($FFPrefix);
                $this->displayValueField($FFPrefix);
                print "</fieldset>";
            }

            # print a blank row for cloning within JavaScript
            print "<fieldset class=\"priv-fieldset mv-peui-fieldset priv-js-clone_target\">";
            $this->displaySubjectField($FFPrefix);
            $this->displayOperatorField($FFPrefix);
            $this->displayValueField($FFPrefix);
            print "</fieldset>";
            # print the button to add a new row when using JavaScript
            print "<button class=\"btn btn-primary btn-sm priv-js-add mv-button-iconed\"><img "
            ."src=\"".$AF->GUIFile('Plus.svg')."\" alt=\"\" "
            ."class=\"mv-button-icon\"/> Add Condition</button>";
            # print the closing div for the set
            print "</div>";
        }
    }

    /**
    * Construct new privilege sets from available form ($_POST) data.
    * @return array Returns an array of PrivilegeSet objects.
    */
    public function getPrivilegeSetsFromForm()
    {
        # for each form field
        $Sets = [];
        $Logics = [];
        foreach ($_POST as $FieldName => $FieldValue) {
            # if field looks like privilege set data
            if (preg_match("/^F_Priv_/", $FieldName)) {
                # extract identifier from field name
                $Pieces = explode("_", $FieldName);
                $Identifier = $Pieces[2];

                # if field looks like privilege set top-level logic
                if (preg_match("/_Logic\$/", $FieldName)) {
                    # save logic for later use
                    $Logics[$Identifier] = $FieldValue;
                } else {
                    # retrieve privilege set from field
                    $Sets[$Identifier] = $this->extractPrivilegeSetFromFormData($FieldValue);
                }
            }
        }

        # for each top-level logic found
        foreach ($Logics as $Identifier => $Logic) {
            # if no corresponding privilege set was found
            if (!isset($Sets[$Identifier])) {
                # load empty set for this identifier
                $Sets[$Identifier] = new PrivilegeSet();
            }

            # set logic in corresponding privilege set
            $Sets[$Identifier]->usesAndLogic(($Logic == "AND") ? true : false);
        }

        # return any privilege sets found to caller
        return $Sets;
    }

    /**
    * Retrieve privilege set from specified form ($_POST) data fields.
    * @param string $Identifier Identifier of privilege set to return.
    * @return object Privilege set or FALSE if no privilege set form data
    *       found with the specified identifier.
    */
    public function getPrivilegeSetFromForm($Identifier)
    {
        $Sets = $this->getPrivilegeSetsFromForm();
        return isset($Sets[$Identifier]) ? $Sets[$Identifier] : false;
    }

    # ---- PRIVATE INTERFACE -------------------------------------------------

    private $Fields;
    private $OptionValues;

    /**
    * Print HTML select element used to decide the subject of a condition
    * (where conditions are comprised of 'subject', 'verb', and 'object',
    * and subject can be a metadata field, Current User, etc).
    * @param string $FFPrefix Prefix to use for form fields.
    * @param mixed $Selected The value to select. (OPTIONAL)
    */
    private function displaySubjectField($FFPrefix, $Selected = null)
    {
        # construct the list of options to present for this field
        # present all the metadata fields using their FieldIds
        $ValidSelections = array_keys($this->Fields);

        # represent other elements as strings, which need to match the
        #   values in GetPrivilegeSetFromFormData()
        $ValidSelections[] = "current_user";
        $ValidSelections[] = "set_entry";
        $ValidSelections[] = "set_exit";

        $AllSchemas = MetadataSchema::getAllSchemas();

        # construct 2d array of our fields keyed by schemaid
        $Fields = [];
        foreach ($this->Fields as $Id => $Field) {
            $Fields[$Field->SchemaId()][$Id] = $Field;
        }

        # build up the list options to included
        $Options = [];
        $OptionCSS = [];

        foreach ($Fields as $ScId => $ScFields) {
            $FieldOptions = [];
            foreach ($ScFields as $Id => $Field) {
                $SafeClassType = strtolower($Field->TypeAsName());
                $FieldOptions[$Id] = "[".$AllSchemas[$ScId]->AbbreviatedName()."] "
                        .$Field->GetDisplayName();
                $OptionCSS[$Id] = "priv priv-option priv-field-subject "
                        ."priv-type-".$SafeClassType."_field";
            }

            $OptLabel = $AllSchemas[$ScId]->Name();
            $Options[$OptLabel] = $FieldOptions;
        }

        # add Current User entry
        $Options["current_user"] = "Current User";
        $OptionCSS["current_user"] = "priv priv-option priv-field-subject "
                ."priv-type-privilege";

        # add subgroup begin marker
        $Options["set_entry"] = "(";
        $OptionCSS["set_entry"] = "priv priv-option priv-field-subject "
                ."priv-type-set_entry";

        # add subgroup end marker
        $Options["set_exit"] = ")";
        $OptionCSS["set_exit"] = "priv priv-option priv-field-subject "
                ."priv-type-set_exit";

        # check if the data we were given contains an invalid field,
        # and if so complain
        if (!is_null($Selected) && !in_array($Selected, $ValidSelections, true)) {
            $Options[$Selected] = "INVALID FIELD";
            $OptionCSS[$Selected] = "priv priv-option priv-field-subject "
                    ."priv-type-user_field";
        }


        $OptionList = new HtmlOptionList($FFPrefix."[]", $Options, $Selected);
        $OptionList->classForOptions($OptionCSS);
        $OptionList->classForList(
            "priv priv-field priv-select priv-field-subject priv-type-user_field "
                ."priv-type-flag_field priv-type-option_field priv-type-timestamp_field "
                ."priv-type-date_field priv-type-number_field priv-type-set_entry "
            ."priv-type-set_exit"
        );

        $OptionList->printHtml();
    }

    /**
    * Print the form field for the operator field.
    * @param string $FFPrefix Prefix to use for form fields.
    * @param mixed $Selected The value to select. (OPTIONAL)
    */
    private function displayOperatorField($FFPrefix, $Selected = null)
    {
        $Options = [];
        $OptionCSS = [];
        $CommonStyles = "priv priv-option priv-field-operator";

        # use css styles on each option to indicate which fields it applies to

        # equal and not equal work for User, Flag, Option, and Number fields
        foreach (["==", "!="] as $Op) {
            $Options[$Op] = $Op;
            $OptionCSS[$Op] = $CommonStyles." priv-type-user_field "
                    ."priv-type-flag_field priv-type-option_field priv-type-number_field";
        }

        # less and greater work for Timestamp, Date, and Number fields
        foreach (["<", ">"] as $Op) {
            $Options[$Op] = $Op;
            $OptionCSS[$Op] = $CommonStyles
                ." priv-type-timestamp_field priv-type-date_field priv-type-number_field";
        }

        $OptionList = new HtmlOptionList($FFPrefix."[]", $Options, $Selected);
        $OptionList->classForOptions($OptionCSS);
        $OptionList->classForList(
            "priv priv-field priv-select priv-field-operator priv-type-user_field "
            ."priv-type-flag_field priv-type-option_field priv-type-timestamp_field "
            ."priv-type-date_field priv-type-number_field"
        );

        $OptionList->printHtml();
    }

    /**
    * Print the form fields for the value field.
    * @param string $FFPrefix Prefix to use for form fields.
    * @param mixed $Selected The value to select for the select box. (OPTIONAL)
    * @param mixed $Value The existing value for the input box. (OPTIONAL)
    */
    private function displayValueField($FFPrefix, $Selected = null, $Value = null)
    {
        $this->printPrivilegeValueSelectorField($FFPrefix, $Selected);
        $this->printPrivilegeValueInputField($FFPrefix, $Value);
    }

    /**
    * Construct a new privilege set from the given array of form data.
    * @param array $FormData An array of form data coming from elements
    *   generated by PrintPrivilegeFields().  For example, if
    *   PrintPrivilegeFields() was called on the previous page with
    *   PrivilegeType = "ViewingPrivileges", you should you should pass
    *   $_POST["F_ViewingPrivileges"] in here.  This variable will be
    *   modified by reference.
    * @return PrivilegeSet Returns a PrivilegeSet object.
    * @throws Exception If invalid data is given.
    */
    private function extractPrivilegeSetFromFormData(array &$FormData)
    {
        $NewPrivilegeSet = new PrivilegeSet();
        $Privileges = $this->getPrivileges();
        $SupportedOperators = ["==", "!=", "<", ">"];

        while (count($FormData)) {
            # extract the form fields
            $SubjectField = array_shift($FormData);
            $OperatorField = array_shift($FormData);
            $ValueSelectField = array_shift($FormData);
            $ValueInputField = array_shift($FormData);

            # privilege condition
            if ($SubjectField == "current_user") {
                # invalid privilege ID
                if (!isset($Privileges[$ValueSelectField])
                        || is_null($ValueSelectField)) {
                    throw new Exception("Invalid privilege (".$ValueSelectField.")");
                }

                $NewPrivilegeSet->addPrivilege($ValueSelectField);

            # metadata field condition
            } elseif (is_numeric($SubjectField)) {
                # invalid field ID
                if (!isset($this->Fields[$SubjectField])) {
                    throw new Exception("Invalid or unsupported field ("
                            .$SubjectField.")");
                }

                # invalid operator
                if (!in_array($OperatorField, $SupportedOperators)) {
                    throw new Exception("Invalid or unsupported operator ("
                            .$OperatorField.")");
                }

                $MetadataField = $this->Fields[$SubjectField];

                switch ($MetadataField->Type()) {
                    case MetadataSchema::MDFTYPE_USER:
                        $Value = null;
                        break;

                    case MetadataSchema::MDFTYPE_FLAG:
                        $Value = 1;
                        break;

                    case MetadataSchema::MDFTYPE_TIMESTAMP:
                    case MetadataSchema::MDFTYPE_DATE:
                    case MetadataSchema::MDFTYPE_NUMBER:
                        $Value = $ValueInputField;
                        break;

                    case MetadataSchema::MDFTYPE_OPTION:
                        # strip the "C" prefix used to distinguish controlled
                        #       names from priv flags
                        $Value = intval(substr($ValueSelectField, 1));
                        break;

                    default:
                        $Value = null;
                        break;
                }

                $NewPrivilegeSet->addCondition($MetadataField, $Value, $OperatorField);

            # entering a nested privilege set
            } elseif ($SubjectField == "set_entry") {
                # the logic is invalid
                if ($ValueSelectField != "AND" && $ValueSelectField != "OR") {
                    throw new Exception("Invalid privilege set logic ("
                            .$ValueSelectField.")");
                }

                $NestedPrivilegeSet = $this->extractPrivilegeSetFromFormData($FormData);

                # only add the nested privilege set if it's not empty.
                if (!$NestedPrivilegeSet->isEmpty()) {
                    $NestedPrivilegeSet->usesAndLogic($ValueSelectField == "AND");
                    $NewPrivilegeSet->addSubset($NestedPrivilegeSet);
                }

            # exiting a privilege set
            } elseif ($SubjectField == "set_exit") {
                break;

            # unknown condition type
            } else {
                throw new Exception("Unknown condition type: ".$SubjectField);
            }
        }

        return $NewPrivilegeSet;
    }

    /**
    * Print a select box for the value field.
    * @param string $FFPrefix Prefix to use for form fields.
    * @param mixed $Selected The value to select. (OPTIONAL)
    */
    private function printPrivilegeValueSelectorField($FFPrefix, $Selected = null)
    {
        # build up the list of options
        $Options = [];
        $OptionCSS = [];
        $OptionData = [];

        # add entries for each user privilege flag
        $Privileges = $this->getPrivileges();
        foreach ($Privileges as $Id => $Privilege) {
            $Options[$Id] = $Privilege->Name();
            $OptionCSS[$Id] = "priv priv-option priv-field-value priv-type-privilege";
        }

        $OptionValues = $this->getOptionValuesForPrivset();
        foreach ($OptionValues as $FieldId => $Values) {
            foreach ($Values as $CNId => $CName) {
                $Options["C".$CNId] = $CName;
                $OptionCSS["C".$CNId] = "priv priv-option priv-field-value "
                        ."priv-type-option_field";
                $OptionData["C".$CNId]["field-id"] = $FieldId;
            }
        }

        $Options["AND"] = "AND";
        $OptionCSS["AND"] = "priv priv-option priv-field-value priv-type-set_entry";

        $Options["OR"] = "OR";
        $OptionCSS["OR"] = "priv priv-option priv-field-value priv-type-set_entry";

        $OptionList = new HtmlOptionList($FFPrefix."[]", $Options, $Selected);
        $OptionList->classForOptions($OptionCSS);
        $OptionList->dataForOptions($OptionData);
        $OptionList->classForList(
            "priv priv-field priv-select priv-field-value priv-type-option_field "
            ."priv-type-privilege priv-type-set_entry priv-type-set_exit"
        );

        $OptionList->printHtml();
    }

    /**
    * Print an input box for the value field.
    * @param string $FFPrefix Prefix to use for form fields.
    * @param mixed $Value The existing value. (OPTIONAL)
    */
    private function printPrivilegeValueInputField($FFPrefix, $Value = null)
    {
        $SafeValue = defaulthtmlentities($Value);
        ?>
      <input name="<?= $FFPrefix; ?>[]"
             type="text"
             class="priv priv-field priv-input priv-field-value
                    priv-type-timestamp_field priv-type-date_field
                    priv-type-number_field"
             value="<?= $SafeValue; ?>" />
        <?PHP
    }

    /**
    * Get the list of option values allowed for privilege sets
    * @return array Values in an array of arrays, with the outer array having
    *       FieldName for the index, and the inner arrays having OptionId
    *       for the index.
    */
    private function getOptionValuesForPrivset()
    {
        if (!isset($this->OptionValues)) {
            $this->OptionValues = [];

            foreach ($this->Fields as $FieldId => $Field) {
                if ($Field->Type() == MetadataSchema::MDFTYPE_OPTION) {
                    $this->OptionValues[$Field->Id()] = $Field->GetPossibleValues();
                }
            }
        }

        return $this->OptionValues;
    }

    /**
    * Get the list of privileges.
    * @return array Returns an array of all privileges.
    */
    private function getPrivileges()
    {
        static $Privileges;

        if (!isset($Privileges)) {
            $PrivilegeFactory = new PrivilegeFactory();
            $Privileges = $PrivilegeFactory->getPrivileges();
        }

        return $Privileges;
    }
}
