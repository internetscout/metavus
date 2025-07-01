<?PHP
#
#   FILE:  EditUser.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2002-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
#   @scout:phpstan

use Metavus\FormUI;
use Metavus\ItemListUI;
use Metavus\PrivilegeFactory;
use Metavus\RecordEditingUI;
use Metavus\SavedSearch;
use Metavus\SavedSearchFactory;
use Metavus\User;
use Metavus\UserEditingUI;
use Metavus\UserFactory;
use ScoutLib\ApplicationFramework;
use ScoutLib\PluginManager;
use ScoutLib\StdLib;

/**
 * Validate a potential new password for a specified user.
 * @param string $NewPassword New password to check.
 * @return null|string NULL on success, error string describing the problem otherwise.
 */
function validateUserPassword(User $User, string $NewPassword)
{
    if (!strlen($NewPassword)) {
        return null;
    }

    $PasswordErrors = User::checkPasswordForErrors(
        $NewPassword,
        $User->get("UserName"),
        $User->get("EMail")
    );

    if (count($PasswordErrors) == 0) {
        return null;
    }

    return implode(
        ", ",
        array_map(
            "\\Metavus\\User::getStatusMessageForCode",
            $PasswordErrors
        )
    );
}

/**
 * Get privilege options that should be displayed for a given user in the
 * format required for a FormUI Option field.
 * @param User $User User to get options for.
 * @return array Privilege options.
 */
function getPrivOptions(User $User) : array
{
    $EditingMyself = $User->id() == User::getCurrentUser()->id();

    $Options = [];

    # for each privilege
    $Privileges = (new PrivilegeFactory())->getPrivilegeOptions();
    foreach ($Privileges as $Id => $Label) {
        # disable sysadmin or user account admin privileges as a safeguard
        if ($EditingMyself && ($Id == PRIV_SYSADMIN || $Id == PRIV_USERADMIN)) {
            continue;
        }

        $Options[$Id] = $Label;
    }

    return $Options;
}

/**
 * Get view link for a saved search for display in an ItemListUI.
 * @param SavedSearch $Item Search to get the link for.
 * @return string View link.
 */
function getSearchViewLink(SavedSearch $Item) : string
{
    return "index.php?P=SearchResults&"
        .$Item->searchParameters()->urlParameterString();
}

/**
 * Print list of saved searches for a user.
 * @param User $User User to list searches for.
 */
function printSearchesForUser(User $User): void
{
    $SSFactory = new SavedSearchFactory();
    $Searches = $SSFactory->getSearchesForUser($User->id());

    $SearchFields = [
        "SearchName" => [
            "Heading" => "Search Name",
            "MaxLength" => 80,
            "AllowHTML" => true,
            "ValueFunction" => function ($Item) {
                return htmlspecialchars($Item->searchName());
            },
        ],
        "Search Criteria" => [
            "AllowHTML" => true,
            "ValueFunction" => function ($Item) {
                return $Item->searchParameters()->textDescription();
            },
        ],
    ];

    $MailingsEnabled = PluginManager::getInstance()->pluginReady("SavedSearchMailings");
    if ($MailingsEnabled) {
        $FrequencyList = SavedSearch::getSearchFrequencyList();

        $SearchFields += [
            "Search Frequency" => [
                "ValueFunction" => function ($Item) use ($FrequencyList) {
                    return $FrequencyList[$Item->frequency()];
                }
            ],
        ];
    }

    $SearchList = new ItemListUI($SearchFields);
    $SearchList->noItemsMessage("No saved searches for user");
    $SearchList->fieldsSortableByDefault(false);

    $SearchList->addActionButton(
        "View",
        "getSearchViewLink"
    );
    $SearchList->addActionButton(
        "Edit",
        "index.php?P=AdvancedSearch&ID=\$ID"
    );
    $SearchList->addActionButton(
        "Delete",
        "index.php?P=ListSavedSearches&AC=Delete&ID=\$ID"
    );

    $SearchList->display($Searches);
}

/**
 * Get editing fields for a user in the format required by FormUI.
 * @param User $UserToEdit User being edited.
 * @return array Editing fields.
 */
function getEditingFormFields(User $UserToEdit) : array
{
    $AmEditingMyself = $UserToEdit->id() == User::getCurrentUser()->id() ? true : false;
    $Record = $UserToEdit->getResource();

    # login information section
    $FormFields = [
        "HEADING-Login" => [
            "Type" => FormUI::FTYPE_HEADING,
            "Label" => "Login Information",
        ],
    ];


    $FormFields["UserId"] = RecordEditingUI::getFormConfigForField(
        $Record,
        $Record->getSchema()->getField("UserId"),
        false
    );
    $FormFields["UserId"]["Value"] = [
        $UserToEdit->id()
    ];

    if ($AmEditingMyself) {
        $FormFields += [
            "NewPassword" => [
                "Label" => "Password",
                "Type" => FormUI::FTYPE_CUSTOMCONTENT,
                "Content" => "<i>(edit your own password on the "
                ."<a href='index.php?P=Preferences'>user preferences</a> page)</i>",
            ],
        ];
    } else {
        $FormFields += [
            "EMail" => [
                "Type" => FormUI::FTYPE_TEXT,
                "Label" => "Email",
                "Value" => $UserToEdit->get("EMail"),
                "ValidateFunction" => ["Metavus\\FormUI", "validateEmail"],
            ],
            "NewPassword" => [
                "Type" => FormUI::FTYPE_PASSWORD,
                "Label" => "New Password",
                "MaxLength" => 20,
                "Size" => 20,
                "Help" => User::getPasswordRulesDescription(),
                "ValidateFunction" => function ($FieldName, $Value) use ($UserToEdit) {
                    return validateUserPassword($UserToEdit, $Value);
                },
            ],
        ];
    }

    $FormFields += [
        "LastLogin" => [
            "Label" => "Last Login",
            "Type" => FormUI::FTYPE_CUSTOMCONTENT,
            "Content" => StdLib::getPrettyTimestamp(
                $UserToEdit->get("LastLoginDate"),
                true,
                "(never logged in)"
            ),
        ],
    ];

    # access information section
    $FormFields += [
        "HEADING-Access" => [
            "Type" => FormUI::FTYPE_HEADING,
            "Label" => "Access Information",
        ],
    ];

    if ($AmEditingMyself) {
        $FormFields += [
            "DisableUser" => [
                "Label" => "User Status",
                "Type" => FormUI::FTYPE_CUSTOMCONTENT,
                "Content" => "<i>(you cannot disable your own account)</i>",
            ],
        ];
    } else {
        $FormFields += [
            "DisableUser" => [
                "Label" => "User Status",
                "Type" => FormUI::FTYPE_FLAG,
                "Value" => $UserToEdit->hasPriv(PRIV_USERDISABLED),
                "OnLabel" => "Disabled",
                "OffLabel" => "Enabled",
            ]
        ];
    }

    $PrivOptions = getPrivOptions($UserToEdit);
    $FormFields += [
        "Privileges" => [
            "Label" => "Privileges",
            "Type" => FormUI::FTYPE_OPTION,
            "OptionType" => FormUI::OTYPE_LIST,
            "AllowMultiple" => true,
            "Options" => $PrivOptions,
            "Rows" => count($PrivOptions),
            "Value" => $UserToEdit->getPrivList(),
        ],
    ];

    if ($AmEditingMyself) {
        $ExtraPrivs = [];
        if ($UserToEdit->hasPriv(PRIV_SYSADMIN)) {
            $ExtraPrivs [] = "System Administrator";
        }

        if ($UserToEdit->hasPriv(PRIV_USERADMIN)) {
            $ExtraPrivs[] = "User Account Administator";
        };

        if (count($ExtraPrivs)) {
            $FormFields += [
                "PrivilegesNote" => [
                    "Label" => "Administrative Priviliges",
                    "Type" => FormUI::FTYPE_CUSTOMCONTENT,
                    "Content" => implode("<br/>", $ExtraPrivs)."<br/>"
                    ."<i>NOTE: You cannot remove administrative privileges "
                    ."from your own account.",
                ]
            ];
        }
    }


    # saved searches section
    $FormFields += [
        "HEADING-Searches" => [
            "Label" => "Searches",
            "Type" => FormUI::FTYPE_HEADING,
        ],
        "Searches" => [
            "Label" => "Saved Searches",
            "Type" => FormUI::FTYPE_CUSTOMCONTENT,
            "Callback" => "printSearchesForUser",
            "Parameters" => [ $UserToEdit ],
        ],
    ];

    # reset links section
    $FormFields += [
        "HEADING-Links" => [
            "Label" => "Reset Links",
            "Type" => FormUI::FTYPE_HEADING,
        ],
        "PasswordResetLink" => [
            "Label" => "Password Reset Link",
            "Type" => FormUI::FTYPE_CUSTOMCONTENT,
            "Callback" => function ($User) {
                $ResetUrlParameters = "&UN=".urlencode($User->get("UserName"))
                    ."&RC=".$User->getResetCode();

                print ApplicationFramework::baseUrl()
                    ."index.php?P=ResetPassword".$ResetUrlParameters;
            },
            "Parameters" => [ $UserToEdit ],
        ],
    ];

    if ($UserToEdit->hasPriv(PRIV_USERDISABLED)) {
        $FormFields += [
            "AccountActivationLink" => [
                "Label" => "Account Activation Link",
                "Type" => FormUI::FTYPE_CUSTOMCONTENT,
                "Callback" => function ($User) {
                    $ActivationUrlParameters = "&UN=".urlencode($User->get("UserName"))
                        ."&AC=".$User->getActivationCode();
                    print ApplicationFramework::baseUrl()
                        ."index.php?P=ActivateAccount".$ActivationUrlParameters;
                },
                "Parameters" => [ $UserToEdit ],
            ],
        ];
    }

    return $FormFields;
}

# ----- MAIN -----------------------------------------------------------------

# make sure the user has the privs required to access this page
if (!User::requirePrivilege(PRIV_SYSADMIN, PRIV_USERADMIN)) {
    return;
}

# get the user that we should be editing
$H_UserToEdit = null;
$UserName = StdLib::getFormValue("F_UserName");
$UFactory = new UserFactory();
if ($UserName) {
    if ($UFactory->userNameExists($UserName)) {
        $H_UserToEdit = new User($UserName);
    }
} elseif (isset($_GET["ID"])) {
    $Id = intval($_GET["ID"]);
    if ($UFactory->userExists($Id)) {
        $H_UserToEdit = new User($Id);
    }
}

# if no user was found, log an error
if (is_null($H_UserToEdit)) {
    $H_Error = "No user was found.";
    return;
}

$H_AmEditingMyself = $H_UserToEdit->id() == User::getCurrentUser()->id() ? true : false;

# set up user editing UI
$H_UserEditingUI = new UserEditingUI(
    $H_UserToEdit,
    getEditingFormFields($H_UserToEdit)
);

# see if a button was pushed
$Submit = StdLib::getArrayValue($_POST, "Submit");
if (is_null($Submit)) {
    return;
}

# by default, we'll want to go back to the user list after processing our
# actions
$AF = ApplicationFramework::getInstance();
$AF->setJumpToPage("UserList");

# if so, process the button action
switch ($Submit) {
    case "Save":
        if ($H_UserEditingUI->validateFieldInput() == 0) {
            $H_UserEditingUI->saveChanges();

            if (!$H_AmEditingMyself) {
                # process password changes
                $NewPassword = $H_UserEditingUI->getFieldValue("NewPassword");
                if (strlen($NewPassword) > 0) {
                    $H_UserToEdit->setPassword($NewPassword);
                    $H_UserToEdit->set("Has No Password", 0);
                    $H_UserEditingUI->logStatusMessage("Password was successfully set.");
                }

                # process email changes
                $NewEmail = $H_UserEditingUI->getFieldValue("EMail");
                $OldEmail = $H_UserToEdit->get("EMail");
                if (strlen($NewEmail) != 0 && $OldEmail != $NewEmail) {
                    $H_UserToEdit->set("EMail", $NewEmail);
                    $AF->signalEvent(
                        "EVENT_USER_EMAIL_CHANGED",
                        [
                            "UserId" => $H_UserToEdit->id(),
                            "OldEmail" => $OldEmail,
                            "NewEmail" => $NewEmail,
                        ]
                    );
                }
            }
        }

        # process priv changes
        $PrivsToSet = $H_UserEditingUI->getFieldValue("Privileges");
        if ($H_AmEditingMyself) {
            # don't let users remove SYSADMIN or USERADMIN from themselves
            foreach ([PRIV_SYSADMIN, PRIV_USERADMIN] as $Priv) {
                if ($H_UserToEdit->hasPriv($Priv)) {
                    $PrivsToSet[] = $Priv;
                }
            }
        }
        $H_UserToEdit->setPrivList($PrivsToSet);

        # and process disabled state
        if (!$H_AmEditingMyself) {
            # toggle disabled state
            if ($H_UserEditingUI->getFieldValue("DisableUser")) {
                $H_UserToEdit->grantPriv(PRIV_USERDISABLED);
            } else {
                $H_UserToEdit->revokePriv(PRIV_USERDISABLED);
            }
        }
        break;

    case "Delete":
        $_SESSION["UserRemoveArray"] = [$H_UserToEdit->id()];
        $AF->setJumpToPage("ConfirmRemoveUser");
        break;

    default:
        break;
}
