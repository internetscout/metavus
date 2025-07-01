<?PHP
#
#   FILE:  User.php
#
#   Part of the ScoutLib application support library
#   Copyright 2020-2025 Edward Almasy and Internet Scout Research Group
#   http://scout.wisc.edu
#
# @scout:phpstan

namespace ScoutLib;
use Exception;
use InvalidArgumentException;

/**
 * Class representing site user.
 */
class User
{
    # ---- CLASS CONSTANTS ---------------------------------------------------

    const PW_REQUIRE_PUNCTUATION = 1;
    const PW_REQUIRE_MIXEDCASE = 2;
    const PW_REQUIRE_DIGITS = 4;

    const U_OKAY = 0;
    const U_ERROR = 1;
    const U_BADPASSWORD = 2;
    const U_NOSUCHUSER = 3;
    const U_PASSWORDSDONTMATCH = 4;
    const U_EMAILSDONTMATCH = 5;
    const U_DUPLICATEUSERNAME = 6;
    const U_ILLEGALUSERNAME = 7;
    const U_EMPTYUSERNAME = 8;
    const U_ILLEGALPASSWORD = 9;
    const U_ILLEGALPASSWORDAGAIN = 10;
    const U_EMPTYPASSWORD = 11;
    const U_EMPTYPASSWORDAGAIN = 12;
    const U_ILLEGALEMAIL = 13;
    const U_ILLEGALEMAILAGAIN = 14;
    const U_EMPTYEMAIL = 15;
    const U_EMPTYEMAILAGAIN = 16;
    const U_NOTLOGGEDIN = 17;
    const U_TEMPLATENOTFOUND = 19;
    const U_DUPLICATEEMAIL = 20;
    const U_NOTACTIVATED = 21;
    const U_PASSWORDCONTAINSUSERNAME = 22;
    const U_PASSWORDCONTAINSEMAIL = 23;
    const U_PASSWORDTOOSHORT = 24;
    const U_PASSWORDTOOSIMPLE = 25;
    const U_PASSWORDNEEDSPUNCTUATION = 26;
    const U_PASSWORDNEEDSMIXEDCASE = 27;
    const U_PASSWORDNEEDSDIGIT = 28;

    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Class constructor.
     * @param int|string $UserInfo User ID or name or email.  (OPTIONAL)
     */
    public function __construct($UserInfo = null)
    {
        # create database connection
        $this->DB = new Database();

        # if no user info was supplied
        if ($UserInfo === null) {
            # if user ID is available from session
            if (isset($_SESSION["APUserId"]) && is_numeric($_SESSION["APUserId"])) {
                # look user up using ID from session
                $Condition = "UserId = " . intval($_SESSION["APUserId"]);
            }
            # else if user ID was supplied
        } elseif (is_numeric($UserInfo)) {
            # look user up using supplied ID
            $Condition = "UserId = " . intval($UserInfo);
        } elseif (is_string($UserInfo)) {
            # if user email was supplied
            if (filter_var($UserInfo, FILTER_VALIDATE_EMAIL)) {
                # look user up using supplied email
                $Condition = "EMail = '"
                    . addslashes(self::normalizeEmailAddress($UserInfo)) . "'";
            } else {
                # look user up using supplied name
                $Condition = "UserName = '" . addslashes($UserInfo) . "'";
            }
        }

        # if we are looking up user in database
        if (isset($Condition)) {
            # attempt to look up user
            $this->DB->query("SELECT * FROM APUsers WHERE " . $Condition);

            # if user was found
            if ($this->DB->numRowsSelected()) {
                # use user info from database
                $Record = $this->DB->fetchRow();
            }
        }

        # if no user info was supplied and no user data is loaded
        if (($UserInfo === null) && !isset($Record)) {
            # create an anonymous user
            $Record = [
                "UserId" => null,
                "LoggedIn" => false
            ];
        }

        # if a record was found
        if (isset($Record) && is_array($Record)) {
            # load data from record
            $this->UserId = $Record["UserId"];
            $this->LoggedIn = $Record["LoggedIn"] ? true : false;
            $this->Result = self::U_OKAY;

            # set up database value access
            $this->DB->setValueUpdateParameters(
                "APUsers",
                "UserId = '" . addslashes($this->UserId ?? "") . "'"
            );
        } else {
            # otherwise, set code indicating no user found
            $this->Result = self::U_NOSUCHUSER;
        }
    }

    /**
     * Get text error message for a specified error code.
     * @param int $StatusCode One of the U_ constants.
     * @return string error description
     */
    public static function getStatusMessageForCode(int $StatusCode): string
    {
        $APUserStatusMessages = array(
            self::U_OKAY => "The operation was successful.",
            self::U_ERROR => "There has been an error.",
            self::U_BADPASSWORD => "The password you entered was incorrect.",
            self::U_NOSUCHUSER => "No such user name was found.",
            self::U_PASSWORDSDONTMATCH =>
                "The new passwords you entered do not match.",
            self::U_EMAILSDONTMATCH =>
                "The e-mail addresses you entered do not match.",
            self::U_EMPTYUSERNAME =>
                "A user name is required.",
            self::U_DUPLICATEUSERNAME =>
                "The user name you requested is already in use.",
            self::U_ILLEGALUSERNAME =>
                "The user name you requested is too short, too long, "
                . "does not start with a letter, "
                . "or contains illegal characters.",
            self::U_ILLEGALPASSWORD =>
                "The new password you requested is not valid.",
            self::U_ILLEGALEMAIL =>
                "The e-mail address you entered appears to be invalid.",
            self::U_EMPTYEMAIL =>
                "An e-mail address is required.",
            self::U_EMPTYEMAILAGAIN =>
                "Email (again) is required.",
            self::U_NOTLOGGEDIN => "The user is not logged in.",
            self::U_TEMPLATENOTFOUND =>
                "An error occurred while attempting to generate e-mail. "
                . "Please notify the system administrator.",
            self::U_DUPLICATEEMAIL =>
                "The e-mail address you supplied already has an account "
                . "associated with it.",
            self::U_PASSWORDCONTAINSUSERNAME =>
                "The password you entered contains your username.",
            self::U_PASSWORDCONTAINSEMAIL =>
                "The password you entered contains your email address.",
            self::U_EMPTYPASSWORD => "Passwords may not be empty.",
            self::U_EMPTYPASSWORDAGAIN => "Password (again) may not be empty.",
            self::U_PASSWORDTOOSHORT =>
                "Passwords must be at least " . self::$PasswordMinLength
                . " characters long.",
            self::U_PASSWORDTOOSIMPLE =>
                "Passwords must have at least " . self::$PasswordMinUniqueChars
                . " different characters.",
            self::U_PASSWORDNEEDSPUNCTUATION =>
                "Passwords must contain at least one punctuation character.",
            self::U_PASSWORDNEEDSMIXEDCASE =>
                "Passwords must contain a mixture of uppercase and "
                . "lowercase letters",
            self::U_PASSWORDNEEDSDIGIT =>
                "Passwords must contain at least one number.",
        );

        return (isset($APUserStatusMessages[$StatusCode]) ?
            $APUserStatusMessages[$StatusCode] :
            "Unknown user status code: " . $StatusCode);
    }

    /**
     * Create new user.
     * @param string $UserName Login name for new user.
     * @return User Newly-created user.
     */
    public static function create(string $UserName)
    {
        $DB = new Database();
        $NormalizedUserName = static::normalizeUserName($UserName);
        $DB->query("INSERT INTO APUsers (UserName, CreationDate)"
            . " VALUES ('" . addslashes($NormalizedUserName) . "', NOW())");

        $UserId = $DB->getLastInsertId();
        $User = new self($UserId);
        return $User;
    }

    /**
     * Delete user from system.  After this is called, object should now longer
     * be used.
     * @return int U_OKAY if deletion succeeded.
     */
    public function delete(): int
    {
        # clear priv list values
        $this->DB->query("DELETE FROM APUserPrivileges WHERE UserId = '"
            . $this->UserId . "'");

        # delete user record from database
        $this->DB->query("DELETE FROM APUsers WHERE UserId = '" . $this->UserId . "'");

        # report to caller that everything succeeded
        $this->Result = self::U_OKAY;
        return $this->Result;
    }

    # ---- Getting/Setting Values --------------------------------------------

    /**
     * Get user ID.
     * @return int|null User ID or NULL if user not logged in.
     */
    public function id()
    {
        return $this->UserId;
    }

    /**
     * Get user name.
     * @return string User name.
     */
    public function name(): string
    {
        return (string)$this->get("UserName");
    }

    /**
     * Get the best available name associated with a user, i.e., the real name
     * or, if it isn't available, the user name.
     * @return string Returns the best available name for the user.
     */
    public function getBestName(): string
    {
        $RealName = $this->get("RealName");

        # the real name is available, so use it
        if (!is_null($RealName) && strlen(trim($RealName))) {
            return $RealName;
        }

        # the real name isn't available, so use the user name
        return $this->get("UserName");
    }

    /**
     * Get/set user's last location (within system).  Last active date and
     * last IP address are also updated, when a last location is set.
     * @param string $NewLocation New last location.  (OPTIONAL)
     * @return string|null Most recently-saved last location, or NULL if
     *      not associated with a particular user.
     */
    public function lastLocation(?string $NewLocation = null)
    {
        # return NULL if not associated with a particular user
        if ($this->UserId === null) {
            return null;
        }

        if ($NewLocation) {
            $this->DB->UpdateValue("LastLocation", $NewLocation);
            $this->DB->UpdateValue("LastActiveDate", date(StdLib::SQL_DATE_FORMAT));
            $this->DB->UpdateValue("LastIPAddress", $_SERVER["REMOTE_ADDR"]);
        }
        return $this->get("LastLocation");
    }

    /**
     * Get last active date/time for user (set when last location was last
     * updated).
     * @return string Last active date/time, in SQL date format.
     * @see User::LastLocation()
     */
    public function lastActiveDate(): string
    {
        return $this->get("LastActiveDate");
    }

    /**
     * Get last known IP address for user (set when last location was last
     * updated).
     * @return string IP address.
     * @see User::LastLocation()
     */
    public function lastIPAddress(): string
    {
        return $this->get("LastIPAddress");
    }

    /**
     * Retrieve value from specified field.
     * @param string $FieldName Name of field to retrieve value from.
     * @return string|null Requested value or NULL if no user.
     */
    public function get($FieldName)
    {
        # return NULL if not associated with a particular user
        if ($this->UserId === null) {
            return null;
        }

        return $this->DB->UpdateValue($FieldName);
    }

    /**
     * Retrieve value (formatted as a date) from specified field.
     * @param string $FieldName Name of field to retrieve value from.
     * @param string $Format Date format.  (OPTIONAL)
     * @return string|null Requested value or NULL if no user.
     */
    public function getDate(string $FieldName, ?string $Format = null)
    {
        # return NULL if not associated with a particular user
        if ($this->UserId === null) {
            return null;
        }

        # retrieve specified value from database
        if ($Format !== null) {
            $this->DB->query("SELECT DATE_FORMAT(`" . addslashes($FieldName)
                . "`, '" . addslashes($Format) . "') AS `" . addslashes($FieldName)
                . "` FROM APUsers WHERE UserId='" . $this->UserId . "'");
        } else {
            $this->DB->query("SELECT `".addslashes($FieldName)
                    ."` FROM APUsers WHERE UserId='".$this->UserId."'");
        }
        $Record = $this->DB->FetchRow();

        # return value to caller
        return $Record[$FieldName];
    }

    /**
     * Set value in specified field.
     * @param string $FieldName Name of field to set value in.
     * @param bool|int|string $NewValue New Value to set.
     * @return int Result of set operation (U_NOTLOGGEDIN or U_OKAY ).
     */
    public function set($FieldName, $NewValue): int
    {
        # return error if not associated with a particular user
        if ($this->UserId === null) {
            return self::U_NOTLOGGEDIN;
        }

        # transform booleans to 0 or 1 for storage
        if (is_bool($NewValue)) {
            $NewValue = $NewValue ? 1 : 0;
        }


        $this->DB->UpdateValue($FieldName, $NewValue);
        $this->Result = self::U_OKAY;
        return $this->Result;
    }


    # ---- Login Functions ---------------------------------------------------

    /**
     * Log user in.
     * @param string $UserName Name user supplied to log in.
     * @param string $Password Password user supplied to log in.
     * @param bool $IgnorePassword If FALSE, do not check password.
     * @return int Result of login attempt (U_ constant).
     */
    public function login(
        string $UserName,
        string $Password,
        bool $IgnorePassword = false
    ): int {
        # if user not found in DB
        $this->DB->query("SELECT * FROM APUsers"
            . " WHERE UserName = '"
            . addslashes(self::normalizeUserName($UserName)) . "'");
        if ($this->DB->NumRowsSelected() < 1) {
            # result is no user by that name
            $this->Result = self::U_NOSUCHUSER;
        } else {
            # if user account not yet activated
            $Record = $this->DB->FetchRow();
            if (!$Record["RegistrationConfirmed"]) {
                # result is user registration not confirmed
                $this->Result = self::U_NOTACTIVATED;
            } else {
                # grab password from DB
                $StoredPassword = $Record["UserPassword"];

                if (isset($Password[0]) && $Password[0] == " ") {
                    $Challenge = md5(date("Ymd") . $_SERVER["REMOTE_ADDR"]);
                    $StoredPassword = md5($Challenge . $StoredPassword);

                    $EncryptedPassword = trim($Password);
                } else {
                    # if supplied password matches encrypted password
                    $EncryptedPassword = crypt($Password, $StoredPassword);
                }

                if (($EncryptedPassword == $StoredPassword) || $IgnorePassword) {
                    # result is success
                    $this->Result = self::U_OKAY;

                    # store user ID for session
                    $this->UserId = $Record["UserId"];
                    $_SESSION["APUserId"] = $this->UserId;

                    # update database value access
                    $this->DB->SetValueUpdateParameters(
                        "APUsers",
                        "UserId = '" . addslashes($this->UserId) . "'"
                    );

                    # update last login date
                    $this->DB->query("UPDATE APUsers SET LastLoginDate = NOW(),"
                        . " LoggedIn = '1'"
                        . " WHERE UserId = '" . $this->UserId . "'");

                    # Check for old format hashes, and rehash if possible
                    if (($EncryptedPassword === $StoredPassword)
                            && (substr($StoredPassword, 0, 3) !== "$1$")
                            && ($Password[0] !== " ")) {
                        $NewPassword = crypt($Password, self::getSaltForCrypt());
                        $this->DB->query(
                            "UPDATE APUsers SET UserPassword='"
                            . addslashes($NewPassword) . "' "
                            . "WHERE UserId='" . $this->UserId . "'"
                        );
                    }

                    # set flag to indicate we are logged in
                    $this->LoggedIn = true;
                } else {
                    # result is bad password
                    $this->Result = self::U_BADPASSWORD;
                }
            }
        }

        # return result to caller
        return $this->Result;
    }

    /**
     * Log user out.
     */
    public function logout(): void
    {
        # clear user ID (if any) for session
        if (isset($_SESSION["APUserId"])) {
            unset($_SESSION["APUserId"]);
        }

        # if user is marked as logged in
        if ($this->isLoggedIn()) {
            # set flag to indicate user is no longer logged in
            $this->LoggedIn = false;

            # clear login flag in database
            $this->DB->query(
                "UPDATE APUsers SET LoggedIn = '0' "
                . "WHERE UserId='" . $this->UserId . "'"
            );
        }
    }

    /**
     * Report whether user is currently logged in.
     * @return bool TRUE if user is logged in, otherwise FALSE.
     * @see User::IsNotLoggedIn()
     */
    public function isLoggedIn(): bool
    {
        if (!isset($this->LoggedIn)) {
            $this->LoggedIn = $this->DB->queryValue(
                "
                SELECT LoggedIn FROM APUsers
                WHERE UserId='" . addslashes($this->UserId) . "'",
                "LoggedIn"
            ) ? true : false;
        }
        return $this->LoggedIn;
    }

    /**
     * Report whether user is not currently logged in.
     * @return bool TRUE if user is not logged in, otherwise FALSE.
     * @see User::IsLoggedIn()
     */
    public function isNotLoggedIn(): bool
    {
        return $this->isLoggedIn() ? false : true;
    }

    /**
     * Report whether user is anonymous user.
     * @return bool TRUE if user is anonymous, otherwise FALSE.
     */
    public function isAnonymous(): bool
    {
        return ($this->UserId === null) ? true : false;
    }


    # ---- Password Functions ------------------------------------------------

    /**
     * Check a password provided for a user.
     * @param string $Password Password to check
     * @return bool TRUE for correct passwords
     * @throws Exception for anonymous users
     */
    public function isPasswordCorrect(string $Password) : bool
    {
        # return error if not associated with a particular user
        if ($this->UserId === null) {
            throw new Exception(
                "Cannot check password for anonymous user."
            );
        }

        # if old password is not correct
        $StoredPassword = $this->DB->queryValue(
            "SELECT UserPassword FROM APUsers"
            . " WHERE UserId='" . $this->UserId . "'",
            "UserPassword"
        );
        $EncryptedPassword = crypt($Password, $StoredPassword);

        return ($EncryptedPassword == $StoredPassword);
    }

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
        # return error if not associated with a particular user
        if ($this->UserId === null) {
            return self::U_NOTLOGGEDIN;
        }

        # if old password is not correct
        $StoredPassword = $this->DB->queryValue("SELECT UserPassword FROM APUsers"
            . " WHERE UserId='" . $this->UserId . "'", "UserPassword");
        $EncryptedPassword = crypt($OldPassword, $StoredPassword);
        if ($EncryptedPassword != $StoredPassword) {
            # set status to indicate error
            $this->Result = self::U_BADPASSWORD;
            # else if both instances of new password do not match
        } elseif (self::normalizePassword($NewPassword)
            != self::normalizePassword($NewPasswordAgain)) {
            # set status to indicate error
            $this->Result = self::U_PASSWORDSDONTMATCH;
            # perform other validity checks
        } elseif (!self::isValidPassword(
            $NewPassword,
            $this->get("UserName"),
            $this->get("EMail")
        )) {
            # set status to indicate error
            $this->Result = self::U_ILLEGALPASSWORD;
        } else {
            # set new password
            $this->setPassword($NewPassword);

            # set status to indicate password successfully changed
            $this->Result = self::U_OKAY;
        }

        # report to caller that everything succeeded
        return $this->Result;
    }

    /**
     * Set new password for user.
     * @param string $NewPassword New password for user.
     * @throws Exception If no specific (non-anonymous) user is available.
     */
    public function setPassword(string $NewPassword): void
    {
        # check to make sure we are associated with an actual user
        if ($this->isAnonymous()) {
            throw new Exception("Attempt to set password for anonymous user.");
        }

        # generate encrypted password
        $EncryptedPassword = crypt(
            self::normalizePassword($NewPassword),
            self::getSaltForCrypt()
        );

        # save encrypted password
        $this->setEncryptedPassword($EncryptedPassword);
    }

    /**
     * Set new encrypted password for user.
     * @param string $NewEncryptedPassword New encrypted password for user.
     * @throws Exception If no specific (non-anonymous) user is available.
     */
    public function setEncryptedPassword($NewEncryptedPassword): void
    {
        # check to make sure we are associated with an actual user
        if ($this->isAnonymous()) {
            throw new Exception("Attempt to set encrypted password for anonymous user.");
        }

        # save encrypted password
        $this->DB->updateValue("UserPassword", $NewEncryptedPassword);
    }

    /**
     * Generate code for user to submit to confirm registration.
     * @return string Activation code.
     */
    public function getActivationCode(): string
    {
        # code is MD5 sum based on user name and encrypted password
        $ActivationCodeLength = 6;
        return $this->getUniqueCode("Activation", $ActivationCodeLength);
    }

    /**
     * Check whether registration confirmation (i.e. activation) code is valid.
     * @param string $Code Activation code to check.
     * @return bool TRUE if activation code is good, otherwise FALSE.
     */
    public function isActivationCodeGood(string $Code): bool
    {
        return (strtoupper(trim($Code)) == $this->getActivationCode()) ? true : false;
    }

    /**
     * Get/set whether user registration has been confirmed.
     * @param bool $NewValue Whether registration has been confirmed.  (OPTIONAL)
     * @return bool Whether registration has been confirmed.
     */
    public function isActivated(?bool $NewValue = null): bool
    {
        return $this->DB->updateBoolValue("RegistrationConfirmed", $NewValue);
    }

    /**
     * Generate code for user to submit to confirm password reset.
     * @return string Password reset code.
     */
    public function getResetCode(): string
    {
        # code is MD5 sum based on user name and encrypted password
        $ResetCodeLength = 10;
        return $this->getUniqueCode("Reset", $ResetCodeLength);
    }

    /**
     * Check whether password reset code is valid.
     * @param string $Code Reset code to check.
     * @return bool TRUE if reset code is good, otherwise FALSE.
     */
    public function isResetCodeGood(string $Code): bool
    {
        return (strtoupper(trim($Code)) == $this->getResetCode()) ? true : false;
    }

    /**
     * Generate code for user to submit to confirm mail change request
     * @return string Mail change confirmation code.
     */
    public function getMailChangeCode(): string
    {
        $ResetCodeLength = 10;
        return $this->getUniqueCode(
            "MailChange" . $this->get("EMail")
            . $this->get("EMailNew"),
            $ResetCodeLength
        );
    }

    /**
     * Check whether mail change confirmation code is valid.
     * @param string $Code Mail change confirmation code to check.
     * @return bool TRUE if mail change confirmation code is good, otherwise FALSE.
     */
    public function isMailChangeCodeGood(string $Code): bool
    {
        return (strtoupper(trim($Code)) == $this->getMailChangeCode()) ? true : false;
    }

    # ---- Privilege Functions -----------------------------------------------

    /**
     * Check whether user has specified privilege(s).
     * @param int|array $Privilege Privilege or array of privileges.
     * @param int $Privileges One or more additional privileges.  (variable length
     *       argument list) (OPTIONAL)
     * @return bool TRUE if user has one or more of specified privilege(s),
     *       otherwise FALSE.
     */
    public function hasPriv($Privilege, $Privileges = null): bool
    {
        $Args = func_get_args();

        # return FALSE if not associated with a particular user
        if ($this->UserId === null) {
            return false;
        }

        # bail out if empty array of privileges passed in
        if (is_array($Privilege) && !count($Privilege) && (func_num_args() < 2)) {
            return false;
        }

        # set up beginning of database query
        $Query = "SELECT COUNT(*) AS PrivCount FROM APUserPrivileges "
            . "WHERE UserId='" . $this->UserId . "' AND (";

        # add first privilege(s) to query (first arg may be single value or array)
        if (is_array($Privilege)) {
            $Sep = "";
            foreach ($Privilege as $Priv) {
                $Query .= $Sep . "Privilege='" . addslashes($Priv) . "'";
                $Sep = " OR ";
            }
        } else {
            $Query .= "Privilege='" . $Privilege . "'";
            $Sep = " OR ";
        }

        # add any privileges from additional args to query
        array_shift($Args);
        foreach ($Args as $Arg) {
            $Query .= $Sep . "Privilege='" . $Arg . "'";
            $Sep = " OR ";
        }

        # close out query
        $Query .= ")";

        # look for privilege in database
        $PrivCount = $this->DB->queryValue($Query, "PrivCount");

        # return value to caller
        return ($PrivCount > 0) ? true : false;
    }

    /**
     * Get an SQL query that will return IDs of all users that have the specified
     * privilege flags.  This method is useful primarily for subqueries.
     * @param int|array $Privilege Privilege or array of privileges.
     * @param int $Privileges One or more additional privileges.  (variable length
     *       argument list) (OPTIONAL)
     * @return string SQL query to retrieve user IDs.
     */
    public static function getSqlQueryForUsersWithPriv(
        $Privilege,
        $Privileges = null
    ): string {
        $Args = func_get_args();

        # set up beginning of database query
        $Query = "SELECT DISTINCT UserId FROM APUserPrivileges "
            . "WHERE ";

        # add first privilege(s) to query (first arg may be single value or array)
        if (is_array($Privilege)) {
            $Sep = "";
            foreach ($Privilege as $Priv) {
                $Query .= $Sep . "Privilege='" . addslashes($Priv) . "'";
                $Sep = " OR ";
            }
        } else {
            $Query .= "Privilege='" . $Privilege . "'";
            $Sep = " OR ";
        }

        # add any privileges from additional args to query
        array_shift($Args);
        foreach ($Args as $Arg) {
            $Query .= $Sep . "Privilege='" . $Arg . "'";
            $Sep = " OR ";
        }

        # return query to caller
        return $Query;
    }

    /**
     * Get an SQL query that will return IDs of all users that do not have the
     * specified privilege flags.  This method is useful primarily for subqueries.
     * @param int|array $Privilege Privilege or array of privileges.
     * @param int $Privileges One or more additional privileges.  (variable length
     *       argument list) (OPTIONAL)
     * @return string SQL query to retrieve user IDs.
     */
    public static function getSqlQueryForUsersWithoutPriv(
        $Privilege,
        $Privileges = null
    ): string {
        $Args = func_get_args();

        # set up beginning of database query
        $Query = "SELECT DISTINCT UserId FROM APUserPrivileges "
            . "WHERE ";

        # add first privilege(s) to query (first arg may be single value or array)
        if (is_array($Privilege)) {
            $Sep = "";
            foreach ($Privilege as $Priv) {
                $Query .= $Sep . "Privilege != '" . addslashes($Priv) . "'";
                $Sep = " AND ";
            }
        } else {
            $Query .= "Privilege != '" . $Privilege . "'";
            $Sep = " AND ";
        }

        # add any privileges from additional args to query
        array_shift($Args);
        foreach ($Args as $Arg) {
            $Query .= $Sep . "Privilege != '" . $Arg . "'";
            $Sep = " AND ";
        }

        # return query to caller
        return $Query;
    }

    /**
     * Give specified privilege to user.
     * @param int $Privilege Privilege to grant.
     * @throws Exception If no specific (non-anonymous) user is available.
     * @throws InvalidArgumentException If specified privilege appears invalid.
     */
    public function grantPriv(int $Privilege): void
    {
        # check to make sure we are associated with an actual user
        if ($this->isAnonymous()) {
            throw new Exception("Attempt to grant privilege (" . $Privilege
                . ") to anonymous user.");
        }

        # if user does not already have privilege
        $PrivCount = $this->DB->queryValue(
            "SELECT COUNT(*) AS PrivCount FROM APUserPrivileges"
            . " WHERE UserId = " . intval($this->UserId)
            . " AND Privilege = " . intval($Privilege),
            "PrivCount"
        );
        if ($PrivCount == 0) {
            # add privilege for this user to database
            $this->DB->query("INSERT INTO APUserPrivileges"
                . " (UserId, Privilege) VALUES"
                . " (" . intval($this->UserId) . ", " . intval($Privilege) . ")");
        }
    }

    /**
     * Remove specified privilege from user.
     * @param int $Privilege Privilege to revoke.
     * @throws Exception If no specific (non-anonymous) user is available.
     * @throws InvalidArgumentException If specified privilege appears invalid.
     */
    public function revokePriv(int $Privilege): void
    {
        # check to make sure we are associated with an actual user
        if ($this->isAnonymous()) {
            throw new Exception("Attempt to revoke privilege (" . $Privilege
                . ") from anonymous user.");
        }

        # remove privilege from database (if present)
        $this->DB->query("DELETE FROM APUserPrivileges"
            . " WHERE UserId = " . intval($this->UserId)
            . " AND Privilege = " . intval($Privilege));
    }

    /**
     * Retrieve current list of privileges for user.
     * @return array List of privileges (empty if anonymous user).
     */
    public function getPrivList(): array
    {
        # return empty list if not associated with a particular user
        if ($this->isAnonymous()) {
            return array();
        }

        # read privileges from database and return array to caller
        $this->DB->query("SELECT Privilege FROM APUserPrivileges"
            . " WHERE UserId = " . intval($this->UserId));
        return $this->DB->fetchColumn("Privilege");
    }

    /**
     * Set current list of privileges for user.
     * @param array $NewPrivileges New list of privileges for user.
     * @throws Exception If no specific (non-anonymous) user is available.
     */
    public function setPrivList(array $NewPrivileges): void
    {
        # check to make sure we are associated with an actual user
        if ($this->isAnonymous()) {
            throw new Exception("Attempt to set privileges for anonymous user.");
        }

        # clear old priv list values
        $this->DB->query("DELETE FROM APUserPrivileges"
            . " WHERE UserId = " . intval($this->UserId));

        # for each priv value passed in
        foreach ($NewPrivileges as $Privilege) {
            # set priv for user
            $this->grantPriv($Privilege);
        }
    }

    /**
     * Get the anonymous user (i.e., the User object that exists when no
     * user is logged in), useful when a permission check needs to know
     * if something should be visible to the general public.
     * @return static Anonymous user.
     */
    public static function getAnonymousUser()
    {
        # if we have a UserId in the session, move it aside
        if (isset($_SESSION["APUserId"])) {
            $OldUserId = $_SESSION["APUserId"];
            unset($_SESSION["APUserId"]);
        }

        # create a new anonymous user
        $CalledClass = static::class;
        $Result = new $CalledClass();

        # restore the $_SESSION value
        if (isset($OldUserId)) {
            $_SESSION["APUserId"] = $OldUserId;
        }

        # return our anonymous user
        return $Result;
    }

    /**
     * Get the user currently using the site.  If no current user has been set,
     * then the anonymous user is returned.
     * @return static Instance for user currently logged in (if any).
     */
    public static function getCurrentUser()
    {
        $CalledClass = get_called_class();
        if (!isset(static::$CurrentUser[$CalledClass])) {
            static::$CurrentUser[$CalledClass] = static::getAnonymousUser();
        }
        return static::$CurrentUser[$CalledClass];
    }

    /**
     * Set the user currently using the site.
     * @param User $NewValue New current user.
     */
    public static function setCurrentUser(User $NewValue): void
    {
        static::$CurrentUser[get_called_class()] = $NewValue;
    }


    # ---- Miscellaneous Functions -------------------------------------------

    /**
     * Generate (ostensibly) unique alphanumeric code for user.
     * @param string $SeedString Seed to use when generating code.
     * @param int $CodeLength Desired length of code, in characters (up to 32 chars).
     * @return string Generated code.
     */
    public function getUniqueCode(string $SeedString, int $CodeLength): string
    {
        # check to make sure we are associated with an actual user
        if ($this->UserId === null) {
            throw new Exception("Attempt to generate unique code for anonymous user.");
        }

        $Code = strtoupper(md5($this->name() . $this->get("UserPassword") . $SeedString));
        return substr($Code, 0, $CodeLength);
    }

    /**
     * Check whether a user name is valid (alphanumeric string of 2-24 chars
     * that begins with a letter).
     * @param string $UserName Username to check.
     * @return bool TRUE if user name is valid, otherwise FALSE.
     */
    public static function isValidUserName(string $UserName): bool
    {
        if (preg_match("/^[a-zA-Z][a-zA-Z0-9]{1,23}$/", $UserName)) {
            return true;
        }
        return false;
    }

    /**
     * Generate and return a standard-length randomly-generated password. It
     * will be generated in such a way as to comply with currently-configured
     * password requirements.
     * @return string The password.
     */
    public static function generateRandomPassword(): string
    {
        $PossibleChars =
            "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()_+{}[]:;<>";
        $Password = "";
        # satisfy punctuation requirement - note $^+<> do not count as punctuation
        if (self::$PasswordRules & self::PW_REQUIRE_PUNCTUATION) {
            $Password .= StdLib::getRandomCharacters(1, "!@#%&*()_{}[]:;");
        }
        # satisfy mixed case requirement
        if (self::$PasswordRules & self::PW_REQUIRE_MIXEDCASE) {
            $Password .= StdLib::getRandomCharacters(1, "ABCDEFGHIJKLMNOPQRSTUVWXYZ");
            $Password .= StdLib::getRandomCharacters(1, "abcdefghijklmnopqrstuvwxyz");
        }
        # satisfy digits requirement
        if (self::$PasswordRules & self::PW_REQUIRE_DIGITS) {
            $Password .= StdLib::getRandomCharacters(1, "0123456789");
        }
        # satisfy length requirement
        if (strlen($Password) < self::$PasswordMinLength) {
            $Password .= StdLib::getRandomCharacters(
                (self::$PasswordMinLength - strlen($Password)),
                $PossibleChars
            );
        }
        # satisfy unique chars requirement
        $UniqueCharCount = count(array_unique(str_split($Password)));
        while ($UniqueCharCount < self::$PasswordMinUniqueChars) {
            $UnusedChars = implode(array_diff(
                str_split($PossibleChars),
                str_split($Password)
            ));
            $Password .= StdLib::getRandomCharacters(1, $UnusedChars);
            $UniqueCharCount++;
        }
        # secure shuffle
        $Password = StdLib::shuffleString($Password);
        if (count(User::checkPasswordForErrors($Password)) > 0) {
            throw new Exception("Unable to create valid password (should be impossible).");
        }
        return $Password;
    }

    /**
     * Check whether a password is valid according to configured rules.
     * (User name and email address are required to support checking that
     * the password doesn't match either of those.)
     * @param string $Password Password to check.
     * @param string $UserName Username to check.
     * @param string $Email Email to check.
     * @return bool TRUE if password is valid, otherwise FALSE.
     */
    public static function isValidPassword(
        string $Password,
        string $UserName,
        string $Email
    ): bool {

        return count(self::checkPasswordForErrors(
            $Password,
            $UserName,
            $Email
        )) == 0 ?
            true : false;
    }

    /**
     * Determine if a provided password complies with the configured
     * rules, optionally checking that it does not contain a specified
     * username or email.
     * @param string $Password Password to check.
     * @param string $UserName Username to check.  (OPTIONAL)
     * @param string $Email Email address to check.  (OPTIONAL)
     * @return array of problems or empty array on success.
     */
    public static function checkPasswordForErrors(
        string $Password,
        ?string $UserName = null,
        ?string $Email = null
    ): array {

        # start off assuming no errors
        $Errors = array();

        # normalize incoming password
        $Password = self::normalizePassword($Password);

        # username provided and password contains username
        if ($UserName !== null &&
            stripos($Password, $UserName) !== false) {
            $Errors[] = self::U_PASSWORDCONTAINSUSERNAME;
        }

        # email provided and password contains email
        if ($Email !== null &&
            stripos($Password, $Email) !== false) {
            $Errors[] = self::U_PASSWORDCONTAINSEMAIL;
        }

        # length requirement
        if (strlen($Password) == 0) {
            $Errors[] = self::U_EMPTYPASSWORD;
        } elseif (strlen($Password) < self::$PasswordMinLength) {
            $Errors[] = self::U_PASSWORDTOOSHORT;
        }

        # unique characters requirement
        $UniqueCharCount = count(array_unique(str_split($Password)));
        if ($UniqueCharCount < self::$PasswordMinUniqueChars) {
            $Errors[] = self::U_PASSWORDTOOSIMPLE;
        }

        # for the following complexity checks, use unicode character properties
        # in PCRE as in: http://php.net/manual/en/regexp.reference.unicode.php

        # check for punctuation, uppercase letters, and numbers as per the system
        # configuration
        if (self::$PasswordRules & self::PW_REQUIRE_PUNCTUATION &&
            !preg_match('/\p{P}/u', $Password)) {
            $Errors[] = self::U_PASSWORDNEEDSPUNCTUATION;
        }

        if (self::$PasswordRules & self::PW_REQUIRE_MIXEDCASE &&
            (!preg_match('/\p{Lu}/u', $Password) ||
                !preg_match('/\p{Ll}/u', $Password))) {
            $Errors[] = self::U_PASSWORDNEEDSMIXEDCASE;
        }

        if (self::$PasswordRules & self::PW_REQUIRE_DIGITS &&
            !preg_match('/\p{N}/u', $Password)) {
            $Errors[] = self::U_PASSWORDNEEDSDIGIT;
        }

        return $Errors;
    }

    /**
     * Check whether an email address looks valid.
     * @param string $Email Email address to check.  (OPTIONAL)
     * @return bool TRUE if email address appears valid, otherwise FALSE.
     */
    public static function isValidLookingEmailAddress(string $Email): bool
    {
        return filter_var($Email, FILTER_VALIDATE_EMAIL) ? true : false;
    }

    /**
     * Get normalized version of email address.
     * @param string $Email Email address to normalize.
     * @return string Normalized version of address.
     */
    public static function normalizeEmailAddress(string $Email): string
    {
        return strtolower(trim($Email));
    }

    /**
     * Get normalized version of user name.
     * @param string $UserName Name to normalize.
     * @return string Normalized version of name.
     */
    public static function normalizeUserName(string $UserName): string
    {
        return trim($UserName);
    }

    /**
     * Get normalized version of password.
     * @param string $Password Password to normalize.
     * @return string Normalized version of password.
     */
    public static function normalizePassword(string $Password): string
    {
        return trim($Password);
    }

    /**
     * Set password requirements.
     * @param int $NewValue New password rules as a bitmask of PW_* constants.
     */
    public static function setPasswordRules(int $NewValue): void
    {
        self::$PasswordRules = $NewValue;
    }

    /**
     * Set password minimum length.
     * @param int $NewValue New minimum length.
     */
    public static function setPasswordMinLength(int $NewValue): void
    {
        self::$PasswordMinLength = $NewValue;
    }

    /**
     * Set password minimum unique characters.
     * @param int $NewValue New number of required unique characters.
     */
    public static function setPasswordMinUniqueChars(int $NewValue): void
    {
        self::$PasswordMinUniqueChars = $NewValue;
    }

    /**
     * Get a string describing the password rules.
     * @return string Descriptive string.
     */
    public static function getPasswordRulesDescription(): string
    {
        return "Passwords are case-sensitive, cannot contain your username or email, "
            . "must be at least " . self::$PasswordMinLength
            . " characters long, "
            . " have at least " . self::$PasswordMinUniqueChars
            . " different characters"
            . (self::$PasswordRules & self::PW_REQUIRE_PUNCTUATION ?
                ", include punctuation" : "")
            . (self::$PasswordRules & self::PW_REQUIRE_MIXEDCASE ?
                ", include capital and lowercase letters" : "")
            . (self::$PasswordRules & self::PW_REQUIRE_DIGITS ?
                ", include a number" : "") . ".";
    }


    # ---- PRIVATE INTERFACE -------------------------------------------------

    protected $DB;              # SQL database we use to store user information
    protected $LoggedIn;        # flag indicating whether user is logged in
    protected $Result;          # result of last operation
    protected $Status;
    protected $UserId = null;   # user ID number for reference into database

    protected static $CurrentUser;

    /**
     * Get a salt to use with crypt()
     * @return string Salt string.
     */
    private static function getSaltForCrypt(): string
    {
        # generate a password salt by grabbing CRYPT_SALT_LENGTH
        # random bytes, then base64 encoding while filtering out
        # non-alphanumeric characters to get a string all the hashes
        # accept as a salt
        # (CRYPT_SALT_LENGTH is a predefined PHP constant)
        $SaltRandomBytes = openssl_random_pseudo_bytes(CRYPT_SALT_LENGTH);
        $Salt = preg_replace(
            "/[^A-Za-z0-9]/",
            "",
            base64_encode($SaltRandomBytes)
        );

        # select the best available hashing algorithm, provide a salt
        # in the correct format for that algorithm
        if (CRYPT_SHA512 == 1) { /* @phpstan-ignore-line */
            return '$6$' . substr($Salt, 0, 16);
        } elseif (CRYPT_SHA256 == 1) { /* @phpstan-ignore-line */
            return '$5$' . substr($Salt, 0, 16);
        } elseif (CRYPT_BLOWFISH == 1) { /* @phpstan-ignore-line */
            return '$2y$' . substr($Salt, 0, 22);
        } elseif (CRYPT_MD5 == 1) { /* @phpstan-ignore-line */
            return '$1$' . substr($Salt, 0, 12);
        } elseif (CRYPT_EXT_DES == 1) { /* @phpstan-ignore-line */
            return '_' . substr($Salt, 0, 8);
        } else {
            return substr($Salt, 0, 2);
        }
    }

    private static $PasswordMinLength = 6;
    private static $PasswordMinUniqueChars = 4;

    # default to no additional requirements beyond length
    private static $PasswordRules = 0;
}
