<?PHP
#
#   FILE:  UserFactory.php
#
#   Part of the ScoutLib application support library
#   Copyright 2020-2025 Edward Almasy and Internet Scout Research Group
#   http://scout.wisc.edu
#
# @scout:phpstan

namespace ScoutLib;
use Exception;
use InvalidArgumentException;
use ScoutLib\Database;
use ScoutLib\User;

/**
 * Factory class for site users (User class).
 */
class UserFactory
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Object constructor.
     */
    public function __construct()
    {
        # create database connection
        $this->DB = new Database();

        # figure out user class name
        $this->UserClassName = preg_replace(
            '/Factory$/',
            '',
            get_called_class()
        );
    }

    /**
     * Create new user.  The second password and e-mail address parameters are
     * intended for second copies of each entered by the user.
     * @param string $UserName Login name for new user.
     * @param string $Password Password for new user.
     * @param string $PasswordAgain Second copy of password entered by user.
     * @param string $EMail E-mail address for new user.
     * @param string $EMailAgain Second copy of e-mail address entered by user.
     * @param array $IgnoreErrorCodes Array containing any error codes that should
     *       be ignored.  (OPTIONAL)
     * @return User|array User object or array of error codes.
     */
    public function createNewUser(
        string $UserName,
        string $Password,
        string $PasswordAgain,
        string $EMail,
        string $EMailAgain,
        ?array $IgnoreErrorCodes = null
    ) {

        # check incoming values
        $ErrorCodes = $this->testNewUserValues(
            $UserName,
            $Password,
            $PasswordAgain,
            $EMail,
            $EMailAgain
        );

        # discard any errors we are supposed to ignore
        if ($IgnoreErrorCodes) {
            $ErrorCodes = array_diff($ErrorCodes, $IgnoreErrorCodes);
        }

        # if error found in incoming values return error codes to caller
        if (count($ErrorCodes)) {
            return $ErrorCodes;
        }

        # create new user object
        $UserClass = $this->UserClassName;
        $User = $UserClass::create($UserName);

        # set password and e-mail address
        $User->setPassword($Password);
        $User->set("EMail", trim($EMail));
        $User->set("EMailNew", trim($EMail));

        # return new user object to caller
        return $User;
    }

    /**
     * Test new user values (usually used before creating new user).
     * @param string $UserName User name entered.
     * @param string $Password Password entered.
     * @param string $PasswordAgain Second copy of password entered.
     * @param string $EMail Email entered.
     * @param string $EMailAgain Second copy of email entered.
     * @return array Codes (U_* constants) for errors found (if any).
     */
    public function testNewUserValues(
        string $UserName,
        string $Password,
        string $PasswordAgain,
        string $EMail,
        string $EMailAgain
    ): array {

        $UserClass = $this->UserClassName;

        # normalize incoming values
        $UserName = $UserClass::NormalizeUserName($UserName);
        $Password = $UserClass::NormalizePassword($Password);
        $PasswordAgain = $UserClass::NormalizePassword($PasswordAgain);
        $EMail = $UserClass::NormalizeEMailAddress($EMail);
        $EMailAgain = $UserClass::NormalizeEMailAddress($EMailAgain);

        # start off assuming success
        $ErrorCodes = array();

        # check that provided username is valid
        if (strlen($UserName) == 0) {
            $ErrorCodes[] = User::U_EMPTYUSERNAME;
        } elseif (!$UserClass::IsValidUserName($UserName)) {
            $ErrorCodes[] = User::U_ILLEGALUSERNAME;
        } elseif ($this->userNameExists($UserName)) {
            $ErrorCodes[] = User::U_DUPLICATEUSERNAME;
        }

        # check that email is not already in use
        if ($this->emailAddressIsInUse($EMail)) {
            $ErrorCodes[] = User::U_DUPLICATEEMAIL;
        }

        # check for password problems
        $FoundOtherPasswordError = false;
        $PasswordErrors = $UserClass::CheckPasswordForErrors(
            $Password,
            $UserName,
            $EMail
        );

        # if there were problems, merge those in to our error list
        if (count($PasswordErrors)) {
            $ErrorCodes = array_merge($ErrorCodes, $PasswordErrors);
            $FoundOtherPasswordError = true;
        }

        # check that PasswordAgain was provided
        if (strlen($PasswordAgain) == 0) {
            $ErrorCodes[] = User::U_EMPTYPASSWORDAGAIN;
            $FoundOtherPasswordError = true;
            # and that PasswordAgain matches Password
        } elseif ($Password != $PasswordAgain) {
            $ErrorCodes[] = User::U_PASSWORDSDONTMATCH;
        }

        # check that provided email is valid
        $FoundOtherEMailError = false;
        if (strlen($EMail) == 0) {
            $ErrorCodes[] = User::U_EMPTYEMAIL;
            $FoundOtherEMailError = true;
        } elseif (!$UserClass::IsValidLookingEMailAddress($EMail)) {
            $ErrorCodes[] = User::U_ILLEGALEMAIL;
            $FoundOtherEMailError = true;
        }

        if (strlen($EMailAgain) == 0) {
            $ErrorCodes[] = User::U_EMPTYEMAILAGAIN;
            $FoundOtherEMailError = true;
        } elseif (!$UserClass::IsValidLookingEMailAddress($EMailAgain)) {
            $ErrorCodes[] = User::U_ILLEGALEMAILAGAIN;
            $FoundOtherEMailError = true;
        }

        if ($FoundOtherEMailError == false &&
            $EMail != $EMailAgain) {
            $ErrorCodes[] = User::U_EMAILSDONTMATCH;
        }

        return $ErrorCodes;
    }

    /**
     * Return number of users in the system.
     * @param string $Condition SQL condition (without "WHERE") to limit
     *      user count.  (OPTIONAL)
     * @return int Count of users.
     */
    public function getUserCount(?string $Condition = null): int
    {
        return $this->DB->queryValue("SELECT COUNT(*) AS UserCount FROM APUsers"
            . ($Condition ? " WHERE " . $Condition : ""), "UserCount");
    }

    /**
     * Get total number of user that matched last GetMatchingUsers() call.
     * @return int Number of users.
     * @see UserFactory::GetMatchingUser()
     */
    public function getMatchingUserCount(): int
    {
        return $this->MatchingUserCount;
    }

    /**
     * Get IDs for all users.
     * @return array User IDs.
     */
    public function getUserIds(): array
    {
        $this->DB->query("SELECT UserId FROM APUsers");
        return $this->DB->fetchColumn("UserId");
    }

    /**
     * Get users who are currently logged in (i.e. recently active and not logged out).
     * @param int $InactivityTimeout Number of minutes after which an inactive user
     *       is considered to be no longer logged in.  (OPTIONAL, defaults to 60)
     * @return Array of User objects.
     */
    public function getLoggedInUsers(int $InactivityTimeout = 60): array
    {
        # query IDs of logged-in users from database
        $LoggedInCutoffTime = date(
            "Y-m-d H:i:s",
            time() - ($InactivityTimeout * 60)
        );
        $this->DB->query("SELECT UserId FROM APUsers"
            . " WHERE LastActiveDate > '" . $LoggedInCutoffTime . "'"
            . " AND LoggedIn != '0'");
        $UserIds = $this->DB->FetchColumn("UserId");

        # load array of logged in users
        $ReturnValue = array();
        foreach ($UserIds as $Id) {
            $ReturnValue[$Id] = new User($Id);
        }

        # return array of user data to caller
        return $ReturnValue;
    }

    /**
     * Get users recently logged in.
     * @param string $Since Used to define "recently".  (OPTIONAL, defaults
     *       to 24 hours)
     * @param int $Limit Maximum number of users to return.
     * @return array Array of User objects, with IDs for the index.
     */
    public function getRecentlyLoggedInUsers(
        ?string $Since = null,
        int $Limit = 10
    ): array {
        # get users recently logged in during the last 24 hours if no date given
        if ($Since === null) {
            $Date = date("Y-m-d H:i:s", time() - (24 * 60 * 60));
        } else {
            $SinceSeconds = strtotime($Since);
            if ($SinceSeconds === false) {
                throw new InvalidArgumentException("Unable to parse \"Since\" arg.");
            }
            $Date = date("Y-m-d H:i:s", $SinceSeconds);
        }

        # query for the users who were logged in since the given date
        $this->DB->query("SELECT UserId FROM APUsers"
            . " WHERE LastActiveDate > '" . $Date . "'"
            . " AND LoggedIn != '1'"
            . " ORDER BY LastActiveDate DESC"
            . " LIMIT " . intval($Limit));
        $UserIds = $this->DB->FetchColumn("UserId");

        $ReturnValue = array();
        foreach ($UserIds as $Id) {
            $ReturnValue[$Id] = new User($Id);
        }

        # return array of user data to caller
        return $ReturnValue;
    }

    /**
     * Return array of user names who have the specified privileges.  Multiple
     * privileges can be passed in as parameters (rather than in an array), if
     * desired.
     * @return array User names with user IDs for the array index.
     */
    public function getUsersWithPrivileges(): array
    {
        # retrieve privileges
        $Args = func_get_args();
        if (is_array(reset($Args))) {
            $Args = reset($Args);
        }
        $Privs = array();
        foreach ($Args as $Arg) {
            if (is_array($Arg)) {
                $Privs = array_merge($Privs, $Arg);
            } else {
                $Privs[] = $Arg;
            }
        }

        # start with query string that will return all users
        $QueryString = "SELECT DISTINCT APUsers.UserId, UserName FROM APUsers"
            . (count($Privs) ? ", APUserPrivileges" : "");

        # for each specified privilege
        foreach ($Privs as $Index => $Priv) {
            # add condition to query string
            $QueryString .= ($Index == 0) ? " WHERE (" : " OR";
            $QueryString .= " APUserPrivileges.Privilege = " . $Priv;
        }

        # close privilege condition in query string and add user ID condition
        $QueryString .= count($Privs)
            ? ") AND APUsers.UserId = APUserPrivileges.UserId" : "";

        # add sort by user name to query string
        $QueryString .= " ORDER BY UserName ASC";

        # perform query
        $this->DB->query($QueryString);

        # copy query result into user info array
        $Users = $this->DB->FetchColumn("UserName", "UserId");

        # return array of users to caller
        return $Users;
    }

    /**
     * Get users who have values matching specified string in specified field.
     * @param string $SearchString String to match.
     * @param string $FieldName Database column name to search.  (OPTIONAL,
     *       defaults to "UserName")
     * @param string $SortFieldName Database column name to sort results by.
     *       (OPTIONAL, defaults to "UserName")
     * @param int $Offset Starting index for results.  (OPTIONAL)
     * @param int $Count Maximum number of results to return.  (OPTIONAL)
     * @return array Array with user IDs for the index and User objects for
     *       the values.
     */
    public function findUsers(
        string $SearchString,
        string $FieldName = "UserName",
        string $SortFieldName = "UserName",
        int $Offset = 0,
        int $Count = 9999999
    ): array {

        # retrieve matching user IDs
        $UserNames = $this->findUserNames(
            $SearchString,
            $FieldName,
            $SortFieldName,
            $Offset,
            $Count
        );

        # create user objects
        $Users = array();
        foreach ($UserNames as $UserId => $UserName) {
            $Users[$UserId] = new User($UserId);
        }

        # return array of user objects to caller
        return $Users;
    }

    /**
     * Get users who have values matching specified string in specified field.
     * @param string $SearchString String to match.
     * @param string $FieldName Database column name to search.  (OPTIONAL,
     *       defaults to "UserName")
     * @param string $SortFieldName Database column name to sort results by.
     *       (OPTIONAL, defaults to "UserName")
     * @param int $Offset Starting index for results.  (OPTIONAL)
     * @param int $Count Maximum number of results to return.  (OPTIONAL)
     * @param array $IdExclusions User IDs to exclude.  (OPTIONAL)
     * @param array $ValueExclusions User names to exclude.  (OPTIONAL)
     * @return array Array with user IDs for the index and user names for
     *       the values.
     */
    public function findUserNames(
        string $SearchString,
        string $FieldName = "UserName",
        string $SortFieldName = "UserName",
        int $Offset = 0,
        int $Count = 9999999,
        array $IdExclusions = [],
        array $ValueExclusions = []
    ): array {

        $UserClass = $this->UserClassName;

        # make sure the provided field name is valid
        if (!$this->DB->FieldExists("APUsers", $FieldName)) {
            throw new Exception(
                "There is no " . $FieldName . " Field in the APUsers table"
            );
        }

        # construct a database query
        $QueryString = "SELECT UserId, UserName FROM APUsers WHERE "
            . $FieldName . " = '" . addslashes($SearchString) . "'";

        # add each ID exclusion
        foreach ($IdExclusions as $IdExclusion) {
            $QueryString .= " AND " . $this->ItemIdFieldName . " != '"
                . addslashes($IdExclusion) . "' ";
        }

        # add each value exclusion
        foreach ($ValueExclusions as $ValueExclusion) {
            $QueryString .= " AND " . $this->ItemNameFieldName . " != '"
                . addslashes($ValueExclusion) . "' ";
        }

        $QueryString .= " ORDER BY " . $SortFieldName
            . " LIMIT " . $Offset . ", " . $Count;

        # retrieve matching user IDs
        $this->DB->query($QueryString);
        $UserNames = $this->DB->FetchColumn("UserName", "UserId");

        # return names/IDs to caller
        return $UserNames;
    }

    /**
     * Return array of users who have values matching search string (in
     * specific field if requested).  (Search string respects POSIX-compatible
     * regular expressions.)  Optimization: $SearchString = ".*." and
     * $FieldName = NULL will return all users ordered by $SortFieldName.
     * @param string $SearchString Search pattern.
     * @param string $FieldName Database column name to search.  (OPTIONAL)
     * @param string $SortFieldName Database column name to sort results by.
     *       (OPTIONAL, defaults to "UserName")
     * @param int $ResultsStartAt Starting index for results.  (OPTIONAL)
     * @param int $ReturnNumber Maximum number of results to return.  (OPTIONAL)
     * @return array Array with user IDs for the index and associative
     *       arrays of database columns for the values.
     * @see UserFactory::GetMatchingUserCount()
     */
    public function getMatchingUsers(
        string $SearchString,
        ?string $FieldName = null,
        string $SortFieldName = "UserName",
        int $ResultsStartAt = 0,
        ?int $ReturnNumber = null
    ): array {

        # start with empty array (to prevent array errors)
        $ReturnValue = array();

        # if empty search string supplied, return nothing
        $TrimmedSearchString = trim($SearchString);
        if (empty($TrimmedSearchString)) {
            return $ReturnValue;
        }

        # make sure ordering is done by user name if not specified
        $SortFieldName = empty($SortFieldName) ? "UserName" : $SortFieldName;

        # begin constructing the query
        $Query = "SELECT * FROM APUsers";
        $QueryOrderBy = " ORDER BY $SortFieldName";
        $QueryLimit = empty($ReturnNumber) ? "" : " LIMIT $ResultsStartAt, $ReturnNumber";

        # the Criteria Query will be used to get the total number of results without the
        # limit clause
        $CriteriaQuery = $Query;

        # if specific field comparison requested
        if (!empty($FieldName)) {
            # append queries with criteria
            $Query .= " WHERE " . $FieldName . " REGEXP '" . addslashes($SearchString) . "'";
            $CriteriaQuery = $Query;
            # optimize for returning all users
        } elseif ($SearchString == ".*.") {
            # set field name to username - this would be the first field
            # returned by a field to field search using the above RegExp
            $FieldName = "UserName";
        }

        # add order by and limit to query for optimizing
        $Query .= $QueryOrderBy . $QueryLimit;

        # execute query...
        $this->DB->query($Query);

        # ...and process query return
        while ($Record = $this->DB->FetchRow()) {
            # if specific field or all users requested
            if (!empty($FieldName)) {
                # add user to return array
                $ReturnValue[$Record["UserId"]] = $Record;

                # add matching search field to return array
                $ReturnValue[$Record["UserId"]]["APMatchingField"] = $FieldName;
            } else {
                # for each user data field
                foreach ($Record as $FName => $FValue) {
                    # if search string appears in data field
                    if (strpos($Record[$FName], $SearchString) !== false) {
                        # add user to return array
                        $ReturnValue[$Record["UserId"]] = $Record;

                        # add matching search field to return array
                        $ReturnValue[$Record["UserId"]]["APMatchingField"] = $FName;
                    }
                }
            }
        }

        # add matching user count
        $this->DB->query($CriteriaQuery);
        $this->MatchingUserCount = $this->DB->NumRowsSelected();

        # return array of matching users to caller
        return $ReturnValue;
    }

    /**
     * Retrieve item ID by name.
     * @param string $Name Name to match.
     * @return int|false ID or FALSE if name not found.
     */
    public function getItemIdByName(string $Name)
    {
        $Users = $this->findUserNames($Name);
        if (count($Users) == 0) {
            return false;
        }

        reset($Users);
        return (int) key($Users);
    }

    /**
     * Check whether user currently exists with specified ID.
     * @param int $UserId ID to check.
     * @return bool TRUE if user exists with that ID, otherwise FALSE.
     */
    public function userExists(int $UserId): bool
    {
        $UserCount = $this->DB->queryValue("SELECT COUNT(*) AS UserCount"
            . " FROM APUsers WHERE UserId = " . intval($UserId), "UserCount");
        return ($UserCount > 0) ? true : false;
    }

    /**
     * Check whether user name currently exists.
     * @param string $UserName Name to check.
     * @return bool TRUE if name is already in use, otherwise FALSE.
     */
    public function userNameExists(string $UserName): bool
    {
        # normalize user name
        $UserClass = $this->UserClassName;
        $UserName = $UserClass::NormalizeUserName($UserName);

        # check whether user name is already in use
        $NameCount = $this->DB->queryValue(
            "SELECT COUNT(*) AS NameCount FROM APUsers"
            . " WHERE UserName = '" . addslashes($UserName) . "'",
            "NameCount"
        );

        # report to caller whether name exists
        return ($NameCount > 0) ? true : false;
    }

    /**
     * Check whether e-mail address currently has account associated with it.
     * @param string $Address Address to check.
     * @return bool TRUE if address is in use, otherwise FALSE.
     */
    public function emailAddressIsInUse(string $Address): bool
    {
        # normalize address
        $UserClass = $this->UserClassName;
        $UserName = $UserClass::NormalizeEMailAddress($Address);

        # check whether address is already in use
        $AddressCount = $this->DB->queryValue(
            "SELECT COUNT(*) AS AddressCount FROM APUsers"
            . " WHERE EMail = '" . addslashes($Address) . "'",
            "AddressCount"
        );

        # report to caller whether address is in use
        return ($AddressCount > 0) ? true : false;
    }

    /**
     * Get the users sorted by when they signed up, starting with those who
     * signed up most recently. By default, the number of users returned is five.
     * @param int $Limit The maximum number of users to return.
     * @return array User objects, with user IDs for the index.
     */
    public function getNewestUsers(int $Limit = 5): array
    {
        $UserClass = $this->UserClassName;

        # assume no users will be found
        $Users = array();

        # fetch the newest users
        $this->DB->query("SELECT *"
            . " FROM APUsers"
            . " ORDER BY CreationDate DESC"
            . " LIMIT " . intval($Limit));
        $UserIds = $this->DB->FetchColumn("UserId");

        # for each user id found
        foreach ($UserIds as $UserId) {
            $Users[$UserId] = new $UserClass($UserId);
        }

        # return the newest users
        return $Users;
    }

    # ---- PRIVATE INTERFACE -------------------------------------------------

    protected $DB;
    protected $ItemIdFieldName;
    protected $ItemNameFieldName;
    protected $MatchingUserCount;
    protected $SortFieldName;

    private $UserClassName;
}
