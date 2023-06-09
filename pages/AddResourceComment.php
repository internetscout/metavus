<?PHP
#
#   FILE:  AddResourceComment.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2012-2021 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

use Metavus\FormUI;
use Metavus\Message;
use Metavus\Record;
use ScoutLib\ApplicationFramework;
use ScoutLib\StdLib;
use Metavus\User;

if (!CheckAuthorization(PRIV_SYSADMIN, PRIV_POSTCOMMENTS)) {
    return;
}

$AF = ApplicationFramework::getInstance();

# assume we're not previewing the page until told in the form values
$H_Preview = false;

# get message using message Id
$H_MessageId = StdLib::getFormValue("MI", StdLib::getFormValue("MessageId"));

# retrieve user currently logged in
$User = User::getCurrentUser();

# get message ID if editing, or resource ID and session info if adding new comment
if (!is_null($H_MessageId)) {
    if (!Message::ItemExists($H_MessageId)) {
        FormUI::logError("Invalid message requested.");
        return;
    } else {
        $Message = new Message($H_MessageId);
        if ($User->Id() == $Message->PosterId() ||
            $User->HasPriv(PRIV_SYSADMIN)) {
            # set body text and resource using information from message
            $H_Body = $Message->Body();
            if (!Record::ItemExists($Message->ParentId())) {
                FormUI::LogError("Invalid resource specified");
                $AF->logMessage(
                    ApplicationFramework::LOGLVL_WARNING,
                    "Invalid ResourceId (".$Message->ParentId().") on AddResourceComment "
                    ."for MessageId ".$H_MessageId
                );
                return;
            } else {
                $H_Resource = new Record($Message->ParentId());
            }
        } else {
            FormUI::logError("You don't have permissions to edit that message.");
            return;
        }
    }
} else {
    # get resource using resource Id
    $ResourceId = StdLib::getFormValue("RI", StdLib::getFormValue("ResourceId"));

    if (is_null($ResourceId)) {
        FormUI::logError("No resource ID specified.");
        return;
    } elseif (!Record::ItemExists($ResourceId)) {
        FormUI::logError("Invalid resource ID specified.");
        return;
    } else {
        $H_Resource = new Record($ResourceId);
    }

    # set body text
    if (isset($_SESSION["Body"])) {
        $H_Body = $_SESSION["Body"];
        unset($_SESSION["Body"]);
    } else {
        $H_Body = "";
    }
}

$MaxCommentLengthConfig = $AF->getMultiValueInterfaceSetting("MaxCommentLength");
$SchemaName = ($H_Resource->getSchema())->name();
if (!is_null($MaxCommentLengthConfig)) {
    # if the current schema has a specified comment length, then use it.
    # if not then default to the "Resources" schema limit.
    $H_MaxCommentLength = isset($MaxCommentLengthConfig[$SchemaName]) ?
        $MaxCommentLengthConfig[$SchemaName] :
        $MaxCommentLengthConfig["Resources"];
} else {
    # log a warning message when we fail to parse interface.ini
    # or when interface.ini is lacking the "MaxCommentLength" setting
    # and fall back to using 2000 as the max comment length.
    $AF->logMessage(
        ApplicationFramework::LOGLVL_WARNING,
        "The required configuration setting (\"MaxCommentLength\") was not present in the".
        " configuration file"
    );
    $H_MaxCommentLength = 2000;
}

# if we came from preview, get the previewed form body
$H_Body = StdLib::getFormValue("F_Body", $H_Body);

$TitleField = $H_Resource->getSchema()->GetFieldByMappedName("Title");

$H_Title = $H_Resource->UserCanViewField($User, $TitleField)
        ? $H_Resource->Get($TitleField)
        : "";

# get form fields with values retrieved above
$FormFields = [
    "Resource" => [
        "Label" => "Resource",
        "Type" => FormUI::FTYPE_TEXT,
        "ReadOnly" => true,
        "Value" => $H_Title,
    ],
    "Comment" => [
        "Label" => "Comment",
        "Type" => FormUI::FTYPE_PARAGRAPH,
        "Value" => defaulthtmlentities($H_Body),
        "Required" => true,
        "MaxLength" => $H_MaxCommentLength
    ],
    "Verification" => [
        "Label" => "Verification Code",
        "Type" => FormUI::FTYPE_CAPTCHA,
    ],
];

# create FormUI from form fields
$H_FormUI = new FormUI($FormFields);
$H_FormUI->addValidationParameters($H_MaxCommentLength);

# if editing, add the message ID as a hidden field
if (isset($H_MessageId)) {
    $H_FormUI->addHiddenField("MessageId", $H_MessageId);
}

# add the id of the resource you're editing as a hidden field
$H_FormUI->addHiddenField("ResourceId", (string)$H_Resource->id());

# get value of button pushed on submission
$ButtonPushed = StdLib::getFormValue("Submit", "");

switch ($ButtonPushed) {
    case "Add Comment":
        # if "F_Body" is set, we came from Preview and it was already validated
        # otherwise validate form inputs and bail if any are invalid
        if (strlen(StdLib::getFormValue("F_Body", ""))) {
            $CommentBody = StdLib::getFormValue("F_Body");
        } elseif ($H_FormUI->validateFieldInput()) {
            return;
        } else {
            $CommentBody = $H_FormUI->getNewValuesFromForm()["Comment"];
        }

        # create a new message
        $Message = Message::Create();
        $Message->ParentId($H_Resource->id());
        $Message->ParentType(Message::PARENTTYPE_RESOURCE);
        $Message->DatePosted(date("YmdHis"));
        $Message->PosterId($User->Id());
        $Message->Subject("Comment On: " . $H_Title);
        $Message->Body($CommentBody);
        $AF->setJumpToPage("FullRecord&ID=" . $H_Resource->id());
        return;
    case "Preview":
        # validate form inputs and bail if any are invalid
        if ($H_FormUI->validateFieldInput()) {
            return;
        }
        $H_Body = $H_FormUI->getNewValuesFromForm()["Comment"];
        $H_Preview = true;
        break;
    case "Edit Comment":
        break;
    case "Update Comment":
        # if "F_Body" is set, we came from Preview and it was already validated
        # otherwise validate form inputs and bail if any are invalid
        if (strlen(StdLib::getFormValue("F_Body", ""))) {
            $CommentBody = StdLib::getFormValue("F_Body");
        } elseif ($H_FormUI->validateFieldInput()) {
            return;
        } else {
            $CommentBody = $H_FormUI->getNewValuesFromForm()["Comment"];
        }

        $Message = new Message($H_MessageId);
        if (!($User->Id() == $Message->PosterId()) && !($User->HasPriv(PRIV_SYSADMIN))) {
            FormUI::logError("You don't have permission to edit this comment.");
            return;
        }
        $Message->Body($CommentBody);
        $Message->DateEdited(date("YmdHis"));
        $Message->EditorId($User->Id());
        $AF->setJumpToPage("FullRecord&ID=" . $H_Resource->id());
        return;
    case "Delete Comment":
        if (Message::ItemExists($H_MessageId)) {
            $Message = new Message($H_MessageId);
            $Message->destroy();
        }
        $AF->setJumpToPage("FullRecord&ID=" . $H_Resource->id());
        return;
    case "Cancel":
        $AF->setJumpToPage("FullRecord&ID=" . $H_Resource->id());
        return;
}
