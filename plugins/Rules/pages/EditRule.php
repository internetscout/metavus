<?PHP
#
#   FILE:  EditRule.php (Rules plugin)
#
#   Part of the Metavus digital collections platform
#   Copyright 2017-2024 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

use Metavus\ChangeSetEditingUI;
use Metavus\FormUI;
use Metavus\MetadataSchema;
use Metavus\Plugins\Mailer;
use Metavus\Plugins\Rules\Rule;
use Metavus\PrivilegeSet;
use Metavus\SearchParameterSet;
use ScoutLib\StdLib;
use ScoutLib\ApplicationFramework;
use ScoutLib\PluginManager;

# ----- LOCAL FUNCTIONS ------------------------------------------------------

/**
 * Check that a PrivilegeSet includes user-related components.
 * @param string $FieldName Name of form field for which value is being validated.
 * @param PrivilegeSet $PrivSet The privilege set to check.
 * @return string|null NULL if value is okay or error message if not.
 */
function CheckThatPrivilegesHaveUserComponents(string $FieldName, PrivilegeSet $PrivSet)
{
    return (count($PrivSet->GetPossibleNecessaryPrivileges())
            || count($PrivSet->FieldsWithUserComparisons())) ? null
            : "The conditions for <i>Email Recipients</i> must include at"
                    ." least one user privilege or user field comparison,"
                    ." to limit the potential recipient list.";
}


/**
 * Construct the list of fields that we want included in our privsets.
 * @return array of fields to include.
 */
function GetFieldsForPrivset(): array
{
    $PrivFields = [];
    foreach (MetadataSchema::GetAllSchemas() as $SchemaId => $Schema) {
        # for the User schema
        if ($SchemaId == MetadataSchema::SCHEMAID_USER) {
            # supported types, in the order they should be displayed
            $SupportedFieldTypesInOrder = [
                MetadataSchema::MDFTYPE_USER,
                MetadataSchema::MDFTYPE_FLAG,
                MetadataSchema::MDFTYPE_OPTION,
                MetadataSchema::MDFTYPE_TIMESTAMP,
                MetadataSchema::MDFTYPE_NUMBER
            ];

            # fields we want to skip
            # UserId is included because UserId = UserId of Recipient
            # will always be true
            $UserFieldsToExclude = ["UserId"];

            # iterate over each type
            foreach ($SupportedFieldTypesInOrder as $Type) {
                # get fields of that type
                foreach ($Schema->GetFields($Type) as $FieldId => $Field) {
                    # if this field should be excluded, skip it
                    if (in_array($Field->Name(), $UserFieldsToExclude)) {
                        continue;
                    }
                    # otherwise add it to the list
                    $PrivFields[$FieldId] = $Field;
                }
            }
        } else {
            # for all other schemas, add all the User fields
            $UserFields = $Schema->GetFields(MetadataSchema::MDFTYPE_USER);
            foreach ($UserFields as $FieldId => $Field) {
                $PrivFields[$FieldId] = $Field;
            }
        }
    }

    return $PrivFields;
}

/**
 * Get the list of fields that can be edited by the UPDATEFIELDVALUES action type.
 * @return array Editable fields [SchemaId => [FieldIds], ... ]
 */
function FieldsToEdit()
{
    static $FieldsToEdit;

    if (!isset($FieldsToEdit)) {
        $TypesToEdit = MetadataSchema::MDFTYPE_DATE |
            MetadataSchema::MDFTYPE_TIMESTAMP |
            MetadataSchema::MDFTYPE_FLAG |
            MetadataSchema::MDFTYPE_OPTION;

        $AllSchemas = MetadataSchema::GetAllSchemas();
        foreach ($AllSchemas as $SchemaId => $Schema) {
            # force the schema id to be of type int for subsequent usage
            $SchemaId = (int)$SchemaId;
            $FieldsToEdit[$SchemaId] = [];

            foreach ($Schema->GetFields($TypesToEdit) as $Field) {
                if ($Field->Editable()) {
                    $FieldsToEdit[$SchemaId][] = $Field->Id();
                }
            }
        }
    }

    return $FieldsToEdit;
}

/**
 * Get HTML for a ChangeSetEditingUI to be used in a FormUI CUSTOMCONTENT field.
 * @param int $SchemaId Schema in use.
 * @param ChangeSetEditingUI $Editor Editor to generate output for.
 * @return string HTML for a field editor.
 */
function GetHtmlForFieldEditor($SchemaId, $Editor): string
{
    ob_start();
    print "<b>".(new MetadataSchema($SchemaId))->Name()."</b>";
    $Editor->DisplayAsTable();
    $Result = ob_get_contents();
    ob_end_clean();

    if ($Result === false) {
        throw new Exception("Failed to get the HTML for field editor.");
    }

    return $Result;
}

# ----- MAIN -----------------------------------------------------------------

# check permissions
CheckAuthorization(PRIV_SYSADMIN, PRIV_COLLECTIONADMIN);

$AF = ApplicationFramework::getInstance();
$PluginMgr = PluginManager::getInstance();

# retrieve rule ID
$H_RuleId = StdLib::getFormValue("ID");
if ($H_RuleId === null) {
    throw new Exception("No rule ID specified.");
}
$H_IsNewRule = ($H_RuleId == "NEW") ? true : false;

# if editing existing rule
if (!$H_IsNewRule) {
    # load rule
    $H_Rule = new Rule($H_RuleId);
}

# set up editing form
$FormFields = [
    "Rule Heading" => [
        "Type" => FormUI::FTYPE_HEADING,
        "Label" => "Rule",
    ],
    "Enabled" => [
        "Type" => FormUI::FTYPE_FLAG,
        "Label" => "Enabled",
        "Default" => true,
    ],
    "Name" => [
        "Type" => FormUI::FTYPE_TEXT,
        "Label" => "Name",
        "Placeholder" => "(rule name)",
        "Required" => true,
    ],
    "Frequency" => [
        "Type" => FormUI::FTYPE_OPTION,
        "Label" => "Check Frequency",
        "Help" => "How often to check for items that match the rule.",
        "Options" => [
            60 => "Hourly",
            240 => "Every 4 Hours",
            480 => "Every 8 Hours",
            1440 => "Daily",
            10080 => "Weekly",
            0 => "Continuously",
        ],
        "Default" => 60,
    ],
    # ------------------------------------------------
    "Condition Heading" => [
        "Type" => FormUI::FTYPE_HEADING,
        "Label" => "If",
    ],
    "SearchParams" => [
        "Type" => FormUI::FTYPE_SEARCHPARAMS,
        "Label" => "Search Parameters",
        "Help" => "Search parameters that need to be met for items to match the rule.",
        "Required" => true,
        "MaxFieldLabelLength" => 45,
        "MaxValueLabelLength" => 25
    ],
    # ------------------------------------------------
    "Action Heading" => [
        "Type" => FormUI::FTYPE_HEADING,
        "Label" => "Then",
    ],
    "Action" => [
        "Type" => FormUI::FTYPE_OPTION,
        "Label" => "Action",
        "Help" => "What to do when new items that match the rule are found.",
        "Options" => [],
        "Required" => true,
    ],
];

# if creating a new rule
if ($H_IsNewRule) {
    # start with empty values
    $FormValues = ["SearchParams" => new SearchParameterSet()];
} else {
    # load existing values
    $FormValues = [
        "Name" => $H_Rule->Name(),
        "Enabled" => $H_Rule->Enabled(),
        "Frequency" => $H_Rule->CheckFrequency(),
        "SearchParams" => $H_Rule->SearchParameters(),
        "Action" => $H_Rule->Action()
    ];
}

$FormFields["Action"]["Options"][Rule::ACTION_UPDATEFIELDVALUES] =
    "Update Field Values";
# set as default action
$FormFields["Action"]["Default"] = Rule::ACTION_UPDATEFIELDVALUES;

# create ChangeSetEditingUIs for each schema containing editable fields
$H_FieldEditors = [];
foreach (FieldsToEdit() as $SchemaId => $FieldIds) {
    if (count($FieldIds)) {
        $H_FieldEditors[$SchemaId] = new ChangeSetEditingUI(
            "FieldEditor_".$SchemaId,
            $SchemaId
        );
    }
}

# if support for "Send Email" action is available
if ($PluginMgr->PluginEnabled("Mailer")) {
    # add additional action option
    $FormFields["Action"]["Options"][Rule::ACTION_SENDEMAIL] = "Send Email";

    # add additional settings for action
    $MailerPlugin = Mailer::getInstance();
    $FormFields["SendEmail_Template"] = [
        "Type" => FormUI::FTYPE_OPTION,
        "Label" => "Email Template",
        "Help" => "The template to use when sending emails.",
        "Options" => $MailerPlugin->GetTemplateList(),
        "DisplayIf" => ["Action" => Rule::ACTION_SENDEMAIL]
    ];
    $FormFields["SendEmail_Privileges"] = [
        "Type" => FormUI::FTYPE_PRIVILEGES,
        "Label" => "Email Recipients",
        "Help" => "Emails will be sent to users for whom specified conditions are satisfied.",
        "Required" => true,
        "ValidateFunction" => "CheckThatPrivilegesHaveUserComponents",
        "Schemas" => [],
        "MetadataFields" => GetFieldsForPrivset(),
        "DisplayIf" => ["Action" => Rule::ACTION_SENDEMAIL]
    ];
    $FormFields["SendEmail_ConfirmBeforeSending"] = [
        "Type" => FormUI::FTYPE_FLAG,
        "Label" => "Email Requires Confirmation",
        "Help" => "If enabled, emails will be queued for confirmation "
            ."rather than sent immediately.",
        "DisplayIf" => ["Action" => Rule::ACTION_SENDEMAIL]
    ];

    # if adding new rule
    if ($H_IsNewRule) {
        # set blank privilege set for recipients for form
        $FormValues["SendEmail_Privileges"] = new PrivilegeSet();
    }

    # update the default action
    $FormFields["Action"]["Default"] = Rule::ACTION_SENDEMAIL;
}

# get action params from form
if (!$H_IsNewRule && isset($H_Rule)) {
    # retrieve and set existing settings for form
    $ActionParams = $H_Rule->ActionParameters();

    if (!isset($FormValues["Action"])) {
        throw new Exception("No rule action specified (should be impossible).");
    }

    switch ($FormValues["Action"]) {
        case Rule::ACTION_UPDATEFIELDVALUES:
            foreach ($ActionParams["EditParams"] as $ScId => $Params) {
                if (isset($H_FieldEditors[$ScId])) {
                    $H_FieldEditors[$ScId]->LoadConfiguration(
                        $Params
                    );
                }
            }
            break;

        case Rule::ACTION_SENDEMAIL:
            $FormValues["SendEmail_Template"] = $ActionParams["Template"];
            $FormValues["SendEmail_Privileges"] = new PrivilegeSet(
                $ActionParams["Privileges"]
            );
            $FormValues["SendEmail_ConfirmBeforeSending"] =
                $ActionParams["ConfirmBeforeSending"];
            break;

        default:
            throw new Exception("Unsupported rule action type");
    }
}

# and configure field editing buttons for adding more fields
foreach (FieldsToEdit() as $SchemaId => $FieldIds) {
    if (count($FieldIds)) {
        $H_FieldEditors[$SchemaId]->AddFieldButton("Add field", $FieldIds);
    }
}

$FormFields["FieldUpdates"] = [
    "Type" => FormUI::FTYPE_CUSTOMCONTENT,
    "Label" => "Updates To Apply",
    "DisplayIf" => ["Action" => Rule::ACTION_UPDATEFIELDVALUES],
    "Content" => "",
];

# load the HTML for each ChangeSetEditingUI into the form fields
foreach ($H_FieldEditors as $SchemaId => $Editor) {
    $FormFields["FieldUpdates"]["Content"] .=
        GetHtmlForFieldEditor($SchemaId, $Editor);
}

# instantiate form UI
$H_FormUI = new FormUI($FormFields, $FormValues);

$ButtonPushed = StdLib::getFormValue("Submit");
switch ($ButtonPushed) {
    case "Add":
    case "Save":
        # check values and bail out if any are invalid
        if ($H_FormUI->ValidateFieldInput()) {
            return;
        }

        # retrieve values from form
        $NewSettings = $H_FormUI->GetNewValuesFromForm();
        $SearchParams = $NewSettings["SearchParams"];
        $Action = $NewSettings["Action"];

        # retrieve action-specific attributes for rule
        switch ($Action) {
            case Rule::ACTION_SENDEMAIL:
                $ActionParams = [
                    "Template" => $NewSettings["SendEmail_Template"],
                    "Privileges" => $NewSettings["SendEmail_Privileges"]->Data(),
                    "ConfirmBeforeSending" => $NewSettings["SendEmail_ConfirmBeforeSending"]
                ];
                break;

            case Rule::ACTION_UPDATEFIELDVALUES:
                $EditParams = [];
                foreach ($H_FieldEditors as $ScId => $Editor) {
                    $Data = $Editor->GetValuesFromFormData();
                    $EditParams[$ScId] = $Data;
                }

                $ActionParams = ["EditParams" => $EditParams];
                break;

            default:
                throw new Exception("Unsupported rule action type");
        }

        # if adding new rule
        if ($H_IsNewRule) {
            # create new rule
            $H_Rule = Rule::Create($SearchParams, $Action, $ActionParams);
        } else {
            # load existing rule
            $H_Rule = new Rule($H_RuleId);

            # save updated search parameters
            $H_Rule->SearchParameters($SearchParams);

            # switch to ACTION_NONE and run rule to update list of matches
            $H_Rule->action(Rule::ACTION_NONE);
            $H_Rule->run();

            # save potentially updated action
            $H_Rule->Action($Action);
            $H_Rule->ActionParameters($ActionParams);
        }

        # save common new attributes for rule
        $H_Rule->Enabled($NewSettings["Enabled"]);
        $H_Rule->Name($NewSettings["Name"]);
        $H_Rule->CheckFrequency($NewSettings["Frequency"]);

        # return to rule list
        $AF->SetJumpToPage("P_Rules_ListRules");
        break;

    case "Cancel":
        # return to rule list
        $AF->SetJumpToPage("P_Rules_ListRules");
        break;
}
