<?PHP
#
#   FILE:  User.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2013-2021 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus;

use Exception;
use ScoutLib\ApplicationFramework;
use ScoutLib\Database;
use ScoutLib\Email;
use ScoutLib\StdLib;

/**
 * Metavus-specific user class.
 */
class User extends \ScoutLib\User
{

    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Load user data from the given user info or from the session if available.
     * @param mixed $UserInfo A user ID or user name. (OPTIONAL)
     */
    public function __construct($UserInfo = null)
    {
        parent::__construct($UserInfo);

        if ($this->Result !== self::U_OKAY) {
            throw new Exception(
                "Unable to load user information."
            );
        }

        # try to fetch the associated resource if the user was found
        if (!$this->isAnonymous()) {
            $Resource = $this->fetchAssociatedResource($this->UserId);

            # the associated resource was successfully found
            if ($Resource instanceof Record) {
                $this->Resource = $Resource;
            # there was a problem finding the resource
            } else {
                throw new Exception(
                    "Unable to load corresponding resource for user"
                    ." (UserId=".$this->UserId.")."
                );
            }
        } else {
            $this->Resource = null;
        }
    }

    /**
     * Create new user.
     * @param string $UserName Login name for new user.
     * @return User Newly-created user.
     */
    public static function create(string $UserName)
    {
        # create base User object
        $PUser = parent::create($UserName);
        $UserId = $PUser->id();

        # create user record and make permanent
        $Record = Record::create(MetadataSchema::SCHEMAID_USER);
        $Record->set("UserId", $UserId);
        $Record->isTempRecord(false);

        # create our User object
        $User = new self($UserId);

        # set default values
        $SysConfig = SystemConfiguration::getInstance();
        $User->set("ActiveUI", $SysConfig->getString("DefaultActiveUI"));
        foreach ($SysConfig->getArray("DefaultUserPrivs") as $Privilege) {
            $User->grantPriv($Privilege);
        }

        return $User;
    }

    /**
     * Log the specified user in and associate the underlying Resource
     *     with this CWUser.
     * @param string $UserName User to log in.
     * @param string $Password User's password.
     * @param bool $IgnorePassword TRUE to skip password validation
     *     (OPTIONAL, default FALSE)
     * @return int Result of login attempt (U_ constant).
     */
    public function login(
        string $UserName,
        string $Password,
        bool $IgnorePassword = false
    ): int {
        parent::login($UserName, $Password, $IgnorePassword);

        if ($this->Result == self::U_OKAY) {
            $Resource = $this->fetchAssociatedResource($this->UserId);

            # the associated resource was successfully found
            if ($Resource instanceof Record) {
                $this->Resource = $Resource;
            # there was a problem finding the resource
            } else {
                throw new Exception(
                    "Unable to load corresponding resource for user."
                );
            }
        }

        return $this->Result;
    }

    /**
     * Log this user out and disassociate their underlying Resource from this CWUser.
     */
    public function logout()
    {
        parent::logout();
        $this->Resource = null;
    }

    /**
     * THIS FUNCTION HAS BEEN DEPRECATED
     * This provides compatibility for interfaces written to use a
     * version of PrivilegeSet from CWIS 3.0.0 to 3.1.0.
     * @param PrivilegeSet $NewValue New value (OPTIONAL, default NULL)
     * @return PrivilegeSetCompatibilityShim for use in legacy code.
     */
    public function privileges(PrivilegeSet $NewValue = null)
    {
        if ($NewValue !== null) {
            throw new Exception(
                "Attempt to set user privileges with User::Privileges(), "
                ."which is no longer supported"
            );
        }

        return new PrivilegeSetCompatibilityShim($this);
    }

    /**
     * Get the ID of the user resource associated with the user.
     * @return int|null Returns the ID of the associated user resource or NULL
     *      if it's not available, e.g., the user isn't logged in.
     */
    public function resourceId()
    {
        return ($this->Resource !== null) ? $this->Resource->Id() : null;
    }

    /**
     * Get the associated user resource for this user.
     * @return Record|null Returns the associated user resource or NULL if it's
     *      not available, e.g., the user isn't logged in.
     */
    public function getResource()
    {
        return ($this->Resource !== null) ? $this->Resource : null;
    }

    /**
     * Determine if a user has a given privilege, or satisfies the
     * conditions specified by a given privilege set.  Calling this
     * function with a PrivilegeSet as the first argument is supported
     * only for backwards compatibility.  New code should not do this.
     * @param int|array|PrivilegeSet $Privilege Privilege or aray of privileges
     *      or privilege set to check.
     * @param int|Record $Privileges Additional privileges (as in
     *      parent::HasPriv()), or a Resource to use if the first arg was a
     *      PrivilegeSet.
     * @return bool TRUE if the user has the specified privilege (or satisfies
     *      the requirements of the specified privilege set.
     */
    public function hasPriv($Privilege, $Privileges = null): bool
    {
        static $ErrorsLoggedFrom = [];
        if ($Privilege instanceof PrivilegeSet) {
            $MyCaller = StdLib::getMyCaller();
            if (!isset($ErrorsLoggedFrom[$MyCaller])) {
                $GLOBALS["AF"]->logError(
                    ApplicationFramework::LOGLVL_WARNING,
                    "User::hasPriv() called with instance of PrivilegeSet, "
                    .StdLib::getMyCaller()." use PrivilegeSet::meetsRequirements() instead."
                );
                $ErrorsLoggedFrom[$MyCaller] = true;
            }
            if ($Privileges instanceof Record) {
                return $Privilege->meetsRequirements($this, $Privileges);
            } else {
                return $Privilege->meetsRequirements($this);
            }
        } else {
            $Args = func_get_args();
            if (is_array($Args[0])) {
                if (count($Args) == 1) {
                    $Args = $Args[0];
                } else {
                    $Args = array_merge($Args[0], array_slice($Args, 1));
                }
            }
            if (in_array(PRIV_ISLOGGEDIN, $Args) && $this->isLoggedIn()) {
                return true;
            }

            $Callable = "parent::hasPriv";
            if (is_callable($Callable)) {
                $Args = self::filterPrivileges($Args);
                if (count($Args) > 0) {
                    return call_user_func_array($Callable, $Args);
                }
            }

            return false;
        }
    }

    /**
     * Grant privilege to a a user.
     * @param int $Privilege Privilege to grant.
     * @throws Exception On attempts to grant a pseudo-privilege.
     * @see User::GrantPriv() for return values.
     */
    public function grantPriv(int $Privilege)
    {
        if (self::isPseudoPrivilege($Privilege)) {
            throw new Exception("Attempt to grant pseudo-privilege to user");
        }

        return parent::grantPriv($Privilege);
    }

    /**
     * Clear current user privs and replace them with the specified list.
     * @param array $NewPrivileges New privilege list to
     *   assign. Pseudo-privileges are filtered from the list passed to
     *   User::setPrivList().
     */
    public function setPrivList(array $NewPrivileges)
    {
        parent::setPrivList(self::filterPrivileges($NewPrivileges));
    }

    /**
     * Get the URL for activating a user account, with parameters.
     * @return string Account activation URL.
     */
    public function getAccountActivationUrl() : string
    {
        return $GLOBALS["AF"]->baseUrl()."index.php?P=ActivateAccount&"
            .$this->getAccountActivationUrlParameters();
    }

    /**
     * Get the URL parameters for an account activation request.
     * @return string Account activation URL parameters.
     */
    public function getAccountActivationUrlParameters() : string
    {
        return "UN=".urlencode($this->get("UserName"))
            ."&AC=".$this->getMailChangeCode();
    }

    /**
     * Get the URL for manual account activation.
     * @return string Manual account activation URL.
     */
    public function getAccountManualActivationUrl() : string
    {
        return $GLOBALS["AF"]->baseUrl()."index.php?P=ManuallyActivateAccount";
    }

    /**
     * Send a confirmation message to a newly supplied email address to verify
     *   that a user actually has access to it.
     * @param string $NewEmail Email address to verify
     */
    public function sendEmailConfirmationEmail(string $NewEmail)
    {
        $ActivationUrl = $this->getAccountActivationUrl();
        $ManualActivationUrl = $this->getAccountManualActivationUrl();
        $ActivationUrlParameters = "?".$this->getAccountActivationUrlParameters();

        $OurSubstitutions = [
            "CHANGEURL" => $ActivationUrl,
            "CHANGEPARAMETERS" => $ActivationUrlParameters,
            "MANUALCHANGEURL" => $ManualActivationUrl,
            "NEWEMAILADDRESS" => $NewEmail,
            "USERNAME" => $this->get("UserName"),
            "IPADDRESS" => @$_SERVER["REMOTE_ADDR"],
            "CHANGECODE" => $this->getMailChangeCode(),
        ];
        $IntConfig = InterfaceConfiguration::getInstance();
        $MailTemplate = $IntConfig->getInt("EmailChangeTemplateId");

        # get mailer template and send email
        $Mailer = $GLOBALS["G_PluginManager"]->getPlugin("Mailer");

        $Mailer->sendEmail(
            $MailTemplate,
            $NewEmail,
            [],
            $OurSubstitutions
        );
    }

    /**
     * Send user account activation email.
     * @return bool TRUE when a message was sent, FALSE otherwise
     */
    public function sendActivationEmail()
    {
        $IntConfig = InterfaceConfiguration::getInstance();
        return $this->sendCustomActivationEmail(
            $IntConfig->getInt("ActivateAccountTemplateId")
        );
    }

    /**
     * Send user account activation email using a custom activation template.
     * @param int $TemplateId Mailer template to use
     * @param array $Resources Resources to use with template (OPTIONAL)
     * @return bool TRUE when a message was sent, FALSE otherwise
     */
    public function sendCustomActivationEmail(
        int $TemplateId,
        $Resources = []
    ) {
        $ActivationUrlParameters = "?UN=".urlencode($this->get("UserName"))
            ."&AC=".$this->getActivationCode();
        $ActivationUrl = $GLOBALS["AF"]->baseUrl()
            ."index.php".$ActivationUrlParameters."&P=ActivateAccount";
        $ManualActivationUrl = $GLOBALS["AF"]->baseUrl()
            ."index.php?P=ManuallyActivateAccount";

        $IntConfig = InterfaceConfiguration::getInstance();
        $Substitutions = [
            "PORTALNAME" => $IntConfig->getString("PortalName"),
            "ACTIVATIONURL" => $ActivationUrl,
            "ACTIVATIONPARAMETERS" => $ActivationUrlParameters,
            "MANUALACTIVATIONURL" => $ManualActivationUrl,
            "USERNAME" => $this->get("UserName"),
            "ACTIVATIONCODE" => $this->getActivationCode(),
            "EMAILADDRESS" => $this->get("EMail"),
        ];

        $MessagesSent = $GLOBALS["G_PluginManager"]
            ->getPlugin("Mailer")
            ->sendEmail(
                $TemplateId,
                $this,
                $Resources,
                $Substitutions
            );

        return ($MessagesSent > 0) ? true : false;
    }

    /**
     * Attempt to confirm a change to a user's email address.
     * @param string $ConfirmCode Email change confirmation code
     * @return bool TRUE for successful confirmation, FALSE otherwise
     */
    public function confirmEmailChange(string $ConfirmCode)
    {
        # if the provided code was invalid, don't change anything
        if (!$this->isMailChangeCodeGood($ConfirmCode)) {
            return false;
        }

        # get old and new email settings
        $OldEmail = $this->get("EMail");
        $NewEmail = $this->get("EMailNew");

        # if they were the same, then there's nothing to do
        if ($OldEmail == $NewEmail) {
            return false;
        }

        # but if they were different, change the user's email
        $this->set("EMail", $NewEmail);
        $GLOBALS["AF"]->signalEvent(
            "EVENT_USER_EMAIL_CHANGED",
            [
                "UserId" => $this->id(),
                "OldEmail" => $OldEmail,
                "NewEmail" => $NewEmail,
            ]
        );

        return true;
    }

    /**
     * Attempt to activate a user account.
     * @param string $ActivationCode Account activation code
     * @return bool TRUE on successful activation, FALSE otherwise.
     */
    public function activateAccount(string $ActivationCode)
    {
        if (!$this->isActivationCodeGood($ActivationCode)) {
            return false;
        }

        # otherwise, activate user
        $this->isActivated(true);
        return true;
    }

    /**
     * Change user's email address.
     * @param string $NewEmail New email address
     * @return string|null Status message string or NULL when nothing was done
     */
    public function changeUserEmail(string $NewEmail)
    {
        $OldEmail = $this->get("EMail");
        if ($OldEmail != $NewEmail) {
            $this->set("EMailNew", $NewEmail);
            $this->sendEmailConfirmationEmail($NewEmail);

            return "A confirmation message has been sent to the "
                ."new email address that you specified. Click the link it "
                ."contains to complete your address change.";
        } elseif ($NewEmail != $this->get("EMailNew")) {
            $this->set("EMailNew", $OldEmail);
            return "Your email address has been set to ".$OldEmail.".";
        }

        return null;
    }

    /**
     * Determine if a given user exists (for compatibility with Item::itemExists()).
     * @param int $UserId UserId to check.
     * @return bool TRUE for users that exist.
     */
    public static function itemExists($UserId)
    {
        return (new UserFactory())->userExists($UserId);
    }

    /**
     * Determine if a given field exists in the APUsers table.
     * (needed because FieldsOnlyInDatabase is a private static).
     * @param string $FieldName Field to look for
     * @return bool TRUE for fields that exist, FALSE for those that do not
     */
    public static function isDatabaseOnlyField(string $FieldName) : bool
    {
        StdLib::checkMyCaller(
            "Metavus\\UserFactory",
            "User::isDatabaseOnlyField() may only be called by UserFactory."
        );

        return in_array($FieldName, self::$FieldsOnlyInDatabase);
    }

    /**
     * Get all custom user fields.
     * @return array Returns an array of the custom user fields.
     */
    public static function getCustomUserFields(): array
    {
        static $CustomFields;

        if (!isset($CustomFields)) {
            $CustomFields = [];
            $Schema = new MetadataSchema(MetadataSchema::SCHEMAID_USER);

            foreach ($Schema->getFields() as $Field) {
                # they're custom if not owned by core software
                if (($Field->Owner() != "CWISCore")
                        && ($Field->Owner() != "MetavusCore")) {
                    $CustomFields[$Field->Id()] = $Field;
                }
            }
        }

        return $CustomFields;
    }

    /**
     * Get the default user fields.
     * @return array Returns an array of the default user fields.
     */
    public static function getDefaultUserFields(): array
    {
        static $DefaultFields;

        if (!isset($DefaultFields)) {
            $DefaultFields = [];
            $Schema = new MetadataSchema(MetadataSchema::SCHEMAID_USER);

            foreach ($Schema->getFields() as $Field) {
                # they're default if owned by core software
                if (($Field->Owner() == "CWISCore")
                        || ($Field->Owner() == "MetavusCore")) {
                    $DefaultFields[$Field->Id()] = $Field;
                }
            }
        }

        return $DefaultFields;
    }

    # ---- OVERRIDDEN METHODS ------------------------------------------------

    /**
     * Set new password, if supplied old password is correct.
     * @param string $OldPassword Current password.
     * @param string $NewPassword Password to set.
     * @param string $NewPasswordAgain Confirm new password.
     * @return int U_OKAY on success, a different U_ constant on fail
     */
    public function changePassword(
        string $OldPassword,
        string $NewPassword,
        string $NewPasswordAgain
    ): int {

        $Status = parent::changePassword(
            $OldPassword,
            $NewPassword,
            $NewPasswordAgain
        );

        if ($Status == self::U_OKAY) {
            # signal password change
            $GLOBALS["AF"]->SignalEvent(
                "EVENT_USER_PASSWORD_CHANGED",
                [
                    "UserId" => $this->id(),
                    "OldPassword" => $OldPassword,
                    "NewPassword" => $NewPassword,
                ]
            );
        }

        return $Status;
    }

    /**
     * Delete the user and its associated user resource. Methods should not be
     * called on the object after calling this method.
     * @return int Returns the status of the deletion attempt.
     */
    public function delete(): int
    {
        # delete the associated user resource if set
        if (isset($this->Resource)) {
            $this->Resource->destroy();
            $this->Result = self::U_OKAY;
        }

        return parent::delete();
    }

    /**
     * Get a value from the specified field.
     * @param string $FieldName The name of the field to get.
     * @return mixed Returns the field value or NULL if user data isn't available,
     *      e.g., the user isn't logged in.
     */
    public function get($FieldName)
    {
        # all values are NULL for anonymous users
        if ($this->isAnonymous()) {
            return null;
        }

        if (in_array($FieldName, self::$FieldsOnlyInDatabase)) {
            return parent::get($FieldName);
        } else {
            return $this->Resource->Get($FieldName);
        }
    }

    /**
     * Set a value for the specified field.
     * @param string|int|MetadataField $Field The field to set (name, id, or
     *       MetadataField object).
     * @param mixed $NewValue The value to which to set the field.
     * @return int Returns the status of the operation.
     */
    public function set($Field, $NewValue): int
    {
        if ($this->isAnonymous()) {
            throw new Exception(
                "Attempt to set User field value when "
                ."no user is logged in."
            );
        }

        # make sure Field is a FieldName
        if (is_int($Field)) {
            $Field = new MetadataField($Field);
        }
        if ($Field instanceof MetadataField) {
            $Field = $Field->name();
        }

        # nothing to do if the value hasn't changed
        $OldValue = $this->get($Field);
        if ($OldValue == $NewValue) {
            return self::U_OKAY;
        }

        # if this field is not among those that should only exists in
        # the APUsers table
        if (!in_array($Field, self::$FieldsOnlyInDatabase)) {
            # set it in our corresponding resource
            $this->Resource->set($Field, $NewValue);
        }

        # if the given field exists in the APUsers table, update that too
        if ($this->DB->fieldExists("APUsers", $Field)) {
            parent::set($Field, $NewValue);
        } else {
            # indicate success for fields that don't have a column in APUsers
            $this->Result = self::U_OKAY;
        }

        return $this->Result;
    }

    /**
     * Update user field values (both MFields and DB Fields) from data
     * provided via FormUI.
     * @param array $Values Form values
     */
    public function setFieldsFromFormData($Values)
    {
        $Schema = new MetadataSchema(MetadataSchema::SCHEMAID_USER);

        $UFactory = new UserFactory();
        foreach ($Values as $FieldName => $Value) {
            # skip form fields that don't actually exist either in the APUsers
            # table or as Metadata fields (e.g., PasswordAgain, EmailAgain)
            # and fields where the database field name differs from the form field name
            # (e.g., Password (form field name) vs UserPassword (DB name))
            if (!$UFactory->fieldExists($FieldName)) {
                continue;
            }

            # if this is a value for a metadata field
            if ($Schema->fieldExists($FieldName)) {
                # normalize the incoming value for set()
                $Value = RecordEditingUI::convertFormValueToMFieldValue(
                    $Schema->getField($FieldName),
                    $Value
                );
            }

            # and set the value
            $this->set($FieldName, $Value);
        }
    }

    /**
     * Get FormUI settings for a specified user form field.
     * @param string $FieldName FormUI field name to get settings for.
     * @return array FormUI-format settings.
     */
    public static function getFormSettingsForField(string $FieldName) : array
    {
        switch ($FieldName) {
            case "UserName":
                return [
                    "Type" => FormUI::FTYPE_TEXT,
                    "Label" => "User Name",
                    "ValidateFunction" => function ($FieldName, $Value) {
                        $UFactory = new UserFactory();
                        if ($UFactory->userNameExists($Value)) {
                            return "The user name you entered is already associated"
                                ." with an account.  If you have forgotten your"
                                ." password you can click"
                                ." <a href=\"index.php?P=ForgottenPassword&UN="
                                .urlencode($Value)."\">here</a>"
                                ." to send a reminder via e-mail.";
                        }

                        return null;
                    },
                    "Required" => true,
                    "Size" => 23,
                    "MaxLength" => 64,
                ];

            case "Password":
                return [
                    "Type" => FormUI::FTYPE_PASSWORD,
                    "Label" => "Password",
                    "ValidateFunction" => function ($FieldName, $Value, $Values) {
                        $PasswordErrors = User::checkPasswordForErrors(
                            $Values["Password"],
                            $Values["UserName"],
                            $Values["EMail"]
                        );

                        if (count($PasswordErrors)) {
                            return implode(
                                "<br/>",
                                array_map(
                                    "Metavus\\User::getStatusMessageForCode",
                                    $PasswordErrors
                                )
                            );
                        }
                    },
                    "Required" => true,
                    "Size" => 17,
                    "Help" => "(".User::getPasswordRulesDescription().")",
                ];

            case "PasswordAgain":
                return [
                    "Type" => FormUI::FTYPE_PASSWORD,
                    "Label" => "Password (Again)",
                    "ValidateFunction" => function ($FieldName, $Value, $Values) {
                        if ($Value != $Values["Password"]) {
                            return "Passwords must match.";
                        }
                        return null;
                    },
                    "Required" => true,
                    "Size" => 17,
                ];

            case "EMail":
                return [
                    "Type" => FormUI::FTYPE_TEXT,
                    "Label" => "E-mail Address",
                    "ValidateFunction" => function ($FieldName, $Value) {
                        if (filter_var($Value, FILTER_VALIDATE_EMAIL) === false) {
                            return "Invalid email address.";
                        }
                        return null;
                    },
                    "Required" => true,
                    "Size" => 30,
                    "MaxLength" => 80,
                    "Help" => "(must be valid to activate account)",
                ];

            case "EMailAgain":
                return [
                    "Type" => FormUI::FTYPE_TEXT,
                    "Label" => "E-mail Address (Again)",
                    "ValidateFunction" => function ($FieldName, $Value, $Values) {
                        if ($Value != $Values["EMail"]) {
                            return "EMail addresses do not match.";
                        }
                        return null;
                    },
                    "Required" => true,
                    "Size" => 30,
                    "MaxLength" => 80,
                ];

            default:
                throw new Exception("Unknown field ".$FieldName);
        }
    }

    /**
     * Get additional fields for user signup form provided by
     * EVENT_APPEND_HTML_TO_FORM.  (Event obsolete – may be replaced
     * at some point with different mechanism.)
     * @return array Additional form fields.
     */
    public static function getAdditionalSignupFormFields()
    {
        return [];
    }

    /**
     * Get/set whether user registration has been confirmed
     * Sets privs and signals event for account activation when set to true.
     * @param bool $NewValue Whether registration has been confirmed. (OPTIONAL)
     * @return bool Whether registration has been confirmed.
     */
    public function isActivated(bool $NewValue = null): bool
    {
        if (!is_null($NewValue)) {
            $WasActivated = parent::isActivated();
            parent::isActivated($NewValue);
            if (!$WasActivated && $NewValue) {
                $this->revokePriv(PRIV_USERDISABLED);
                $GLOBALS["AF"]->signalEvent(
                    "EVENT_USER_VERIFIED",
                    ["UserId" => $this->id()]
                );
            }
        }
        return parent::isActivated();
    }

    # ---- PRIVATE INTERFACE -------------------------------------------------

    /**
     * The user resource associated with the user or NULL if the user isn't
     * logged in.
     */
    protected $Resource = null;

    # list of fields that exist in APUsers that are not mirrored as MetadataFields
    private static $FieldsOnlyInDatabase = [
        # fields necessary to for user identification
        "UserId", "UserName", "EMail", "EMailNew",

        # fields necessary for authentication
        "UserPassword", "RegistrationConfirmed",

        # fields that can't be in a schema because they are updated by User
        "LastLoginDate", "LastActiveDate", "LastIPAddress", "LastLocation", "LoggedIn",

        # user preferences
        "ActiveUI", "BrowsingFieldId", "RecordsPerPage", "SearchSelections",
    ];

    /**
     * Fetch the associated user resource based off of a user ID.
     * @param int $UserId The user ID for the user associated with the resource.
     * @return int|Record Returns the associated user resource or an error
     *      status if an error occurs.
     */
    protected function fetchAssociatedResource($UserId)
    {
        if (self::$UserIdFieldId === null) {
            # get the user schema
            $Schema = new MetadataSchema(MetadataSchema::SCHEMAID_USER);

            # pull out the UserId field, which should only be one
            $Field = $Schema->getField("UserId");

            # and get its FieldId
            self::$UserIdFieldId = $Field->id();
        }

        # find the matching Resources (should only be one)
        $this->DB->query(
            "SELECT RecordId FROM RecordUserInts WHERE ".
            "FieldId=".self::$UserIdFieldId.
            " AND UserId=".intval($UserId)
        );
        $ResourceIds = $this->DB->FetchColumn("RecordId");
        $ResourceIdCount = count($ResourceIds);

        # no resource found
        if ($ResourceIdCount < 1) {
            return self::U_NOSUCHUSER;
        }

        # too many resources found
        if ($ResourceIdCount > 1) {
            throw new Exception(
                "Multiple resources exist for a single user, "
                ."which should be impossible"
            );
        }

        # construct the associated resource and return it
        return new Record(array_shift($ResourceIds));
    }

    /**
     * Determine if a privilege is one of the standard privileges built
     * in to CWIS.
     * @param int $Priv Privilege to check.
     * @return bool TRUE for standard privileges, FALSE otherwise
     */
    public static function isStandardPrivilege($Priv)
    {
        return (self::MIN_STANDARD_PRIV <= $Priv &&
                $Priv <= self::MAX_STANDARD_PRIV) ? true : false;
    }

    /**
     * Determine if a privilege is one of the pseudo-privileges used by
     * CWIS.
     * @param int $Priv Privilege to check.
     * @return bool TRUE for pseudo-privileges, FALSE otherwise
     */
    public static function isPseudoPrivilege($Priv)
    {
        return (self::MIN_PSEUDO_PRIV <= $Priv &&
                $Priv <= self::MAX_PSEUDO_PRIV) ? true : false;
    }

    /**
     * Determine if a privilege is a custom privilege created by a user
     * or by a plugin.
     * @param int $Priv Privilege to check.
     * @return bool TRUE for custom privileges, FALSE otherwise
     */
    public static function isCustomPrivilege($Priv)
    {
        if ($Priv >= self::MIN_CUSTOM_PRIV) {
            return true;
        }

        return false;
    }

    /**
     * Filter pseudo-privileges out of a privilege list.
     * @param array $Privs List to filter.
     * @return array Filtered list.
     */
    protected static function filterPrivileges($Privs)
    {
        $Result = [];
        foreach ($Privs as $Priv) {
            if (!self::isPseudoPrivilege($Priv)) {
                $Result[] = $Priv;
            }
        }

        return $Result;
    }

    const MIN_STANDARD_PRIV = 1;
    const MAX_STANDARD_PRIV = 74;

    const MIN_PSEUDO_PRIV = 75;
    const MAX_PSEUDO_PRIV = 99;

    const MIN_CUSTOM_PRIV = 100;
    const MAX_CUSTOM_PRIV = Database::INT_MAX_VALUE;

    private static $UserIdFieldId = null;
}
