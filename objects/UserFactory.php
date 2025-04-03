<?PHP
#
#   FILE:  UserFactory.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2013-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus;
use Exception;
use Metavus\User;
use ScoutLib\ApplicationFramework;
use ScoutLib\StdLib;

/**
 * Metavus-specific user factory class.
 */
class UserFactory extends \ScoutLib\UserFactory
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Construct the user factory object.
     */
    public function __construct()
    {
        parent::__construct();
        $this->ResourceFactory = new RecordFactory(MetadataSchema::SCHEMAID_USER);
    }

    /**
     * Find all users that meet the requirements for a specified privilege set.  This
     * is (currently) a very brute-force, inefficient implementation, so it should
     * not be used anywhere long execution times might be an issue.
     * @param PrivilegeSet $Privset Privilege Set giving the requirements to satisfy.
     * @param array $ResourceIds IDs of resources to use for comparisons, if the
     *       set includes resource-dependent conditions.  (OPTIONAL)
     * @return array Array with IDs of users that meet requirements for the index,
     *       and which resource IDs match for that user for the values.
     */
    public function findUsersThatMeetRequirements(
        PrivilegeSet $Privset,
        array $ResourceIds = []
    ): array {
        # if there are necessary privileges for this privilege set
        $ReqPrivs = $Privset->getPossibleNecessaryPrivileges();
        if (count($ReqPrivs)) {
            # start with only those users who have at least one of those privileges
            $UserIds = array_keys($this->getUsersWithPrivileges($ReqPrivs));
        } else {
            # start with all users
            $UserIds = $this->getUserIds();
        }

        # determine of individual resources will need to be checked
        $NeedToCheckResources =
            (count($ResourceIds) && count($Privset->fieldsWithUserComparisons()))
            ? true : false ;

        # build up a list of matching users
        $UsersThatMeetReqs = [];

        $MemLimit = StdLib::getPhpMemoryLimit();
        $ResourceCache = [];

        # iterate over all the users
        foreach ($UserIds as $UserId) {
            # load user
            $User = new User($UserId);

            if ($NeedToCheckResources) {
                # iterate over all the resources
                foreach ($ResourceIds as $ResourceId) {
                    # if we're running low on memory, nuke the resource cache
                    if (StdLib::getFreeMemory() / $MemLimit
                        < self::$LowMemoryThresh) {
                        $ResourceCache = [];
                    }

                    # load resource
                    if (!isset($ResourceCache[$ResourceId])) {
                        $ResourceCache[$ResourceId] = new Record($ResourceId);
                    }

                    # if user meets requirements for set
                    if ($Privset->meetsRequirements(
                        $User,
                        $ResourceCache[$ResourceId]
                    )) {
                        # add resource to user's list
                        $UsersThatMeetReqs[$UserId][] = $ResourceId;
                    }
                }
            } else {
                # if user meets requirements for set
                if ($Privset->meetsRequirements($User)) {
                    # add user to list
                    $UsersThatMeetReqs[$UserId] = $ResourceIds;
                }
            }
        }

        # return IDs for users that meet requirements to caller
        return $UsersThatMeetReqs;
    }

    /**
     * Get best guess at user account for site administrator/owner.  First
     * looks for a user whose email matches the AdminEmail system
     * configuration, falling back to the most recently logged in user with
     * PRIV_SYSADMIN
     * @return int|null UserId for the admin user or NULL if no user was found.
     */
    public function getSiteOwner()
    {
        # retrieve administrative email address for site
        $IntConfig = InterfaceConfiguration::getInstance();
        $AdminEmail = $IntConfig->getString("AdminEmail");

        # look for user with same email address
        $UserNames = $this->getMatchingUsers($AdminEmail, "EMail");

        # if one matching address is found assume that account is owner
        if (count($UserNames) == 1) {
            $UserIds = array_keys($UserNames);
            $OwnerId = array_pop($UserIds);
            return intval($OwnerId);
        }

        # look for users with system admin privileges
        $PrivUserNames = $this->getUsersWithPrivileges(PRIV_SYSADMIN);
        $PrivUserIds = array_keys($PrivUserNames);

        # if privileged user found assume user most recently logged in is owner
        if (count($PrivUserIds) > 0) {
            usort($PrivUserIds, function ($A, $B) {
                $UserA = new \ScoutLib\User($A);
                $UserB = new \ScoutLib\User($B);
                return ($UserA->get("LastLoginDate")
                        < $UserB->get("LastLoginDate")) ? -1 : 1;
            });
            $OwnerId = array_pop($PrivUserIds);
            return intval($OwnerId);
        }

        # otherwise, no owner was found
        return null;
    }

    /**
     * Determine if a specified field exists either in the User schema or in
     *   the APUsers database table.
     * @param string $FieldName Field to look for
     * @return bool TRUE for fields that exist, FALSE for those that do not
     */
    public static function fieldExists(string $FieldName) : bool
    {
        if (User::isDatabaseOnlyField($FieldName)) {
            return true;
        }

        $Schema = new MetadataSchema(MetadataSchema::SCHEMAID_USER);
        return $Schema->fieldExists($FieldName);
    }

    /**
     * Derive a unique username from an email address.
     * @param string $Email Source email address.
     * @return string Unique username.
     */
    public static function generateUniqueUsernameFromEmail(string $Email): string
    {
        $TrialName = explode('@', $Email);
        $TrialName = array_shift($TrialName);
        $TrialName = preg_replace("/[^A-Za-z0-9]/", "", $TrialName);
        $TrialName = strtolower($TrialName);

        # if this email address is very short, we'll pad it with some random
        # characters
        if (strlen($TrialName) < 2) {
            $TrialName .= GetRandomCharacters(2, "/[^a-hj-np-z0-9]/");
        }

        # see if the specified name exists
        $UFactory = new \ScoutLib\UserFactory();

        $Name = self::appendSuffix($TrialName, '');

        while ($UFactory->userNameExists($Name)) {
            $Name = self::appendSuffix(
                $TrialName,
                GetRandomCharacters(2)
            );
        }

        return $Name;
    }

    /**
     * Take a per-user list of resources and filter out the non-viewable ones.
     * @param array $ResourcesPerUser Array keyed by UserId giving a list of ResourceIds.
     * @return Array keyed by UserId giving the resources visible to
     * each user in the input.
     */
    public static function filterNonViewableResourcesFromPerUserLists(
        array $ResourcesPerUser
    ): array {
        $Result = [];

        $RFactories = [];
        foreach ($ResourcesPerUser as $UserId => $ResourceList) {
            $User = new User($UserId);
            $UserResources = [];

            $ResourcesPerSchema = RecordFactory::buildMultiSchemaRecordList(
                $ResourceList
            );
            foreach ($ResourcesPerSchema as $SchemaId => $ResourceIds) {
                if (!isset($RFactories[$SchemaId])) {
                    $RFactories[$SchemaId] = new RecordFactory($SchemaId);
                }

                $VisibleResources = $RFactories[$SchemaId]->filterOutUnviewableRecords(
                    $ResourceIds,
                    $User
                );

                if (count($VisibleResources)) {
                    $UserResources[$SchemaId] = $VisibleResources;
                }
            }

            if (count($UserResources)) {
                $Result[$UserId] = RecordFactory::flattenMultiSchemaRecordList(
                    $UserResources
                );
            }
        }

        return $Result;
    }


    # ---- OVERRIDDEN METHODS ------------------------------------------------

    /**
     * Create a new user. The second password and e-mail address parameters are
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
        # add the user to the APUsers table
        $PUser = parent::createNewUser(
            $UserName,
            $Password,
            $PasswordAgain,
            $EMail,
            $EMailAgain,
            $IgnoreErrorCodes
        );

        # user account creation did not succeed, so return the error codes
        if (!($PUser instanceof \ScoutLib\User)) {
            return $PUser;
        }

        # return new user object to caller
        return new User($PUser->id());
    }

    /**
     * Create a new user from provided data.
     * @param array $Values List of values keyed by field name.
     * @param bool $GenerateRandomPassword TRUE to use a randomly generated
     *   password and set the "Has No Password" user field so that the user will
     *   be prompted to provide a password (OPTIONAL, default FALSE)
     * @param bool $SendEmail TRUE to send an activation email (OPTIONAL, default TRUE)
     * @return User Newly created user
     * @throws Exception when the user cannot be created.
     */
    public function createNewUserFromFormData(
        array $Values,
        bool $GenerateRandomPassword = false,
        bool $SendEmail = true
    ) {
        $ErrorsToIgnore = [];

        # if we didn't ask for a password, use a randomly generated one
        if ($GenerateRandomPassword) {
            $Values["Password"] = GetRandomCharacters(12);
            $Values["PasswordAgain"] = $Values["Password"];
            $ErrorsToIgnore = [
                User::U_ILLEGALPASSWORD,
                User::U_ILLEGALPASSWORDAGAIN,
                User::U_PASSWORDTOOSHORT,
                User::U_PASSWORDTOOSIMPLE,
                User::U_PASSWORDNEEDSPUNCTUATION,
                User::U_PASSWORDNEEDSMIXEDCASE,
                User::U_PASSWORDNEEDSDIGIT,
            ];
        }

        $NewUser = $this->createNewUser(
            $Values["UserName"],
            $Values["Password"],
            $Values["PasswordAgain"],
            $Values["EMail"],
            $Values["EMailAgain"],
            $ErrorsToIgnore
        );

        if (!is_object($NewUser)) {
            throw new Exception(
                "User creation failed. Error codes: "
                .implode(", ", $NewUser)
            );
        }

        $NewUser->setFieldsFromFormData($Values);

        # always disable account until activated
        $NewUser->grantPriv(PRIV_USERDISABLED);

        if ($GenerateRandomPassword) {
            $NewUser->set("Has No Password", true);
        }

        ApplicationFramework::getInstance()->signalEvent(
            "EVENT_USER_ADDED",
            [
                "UserId" => $NewUser->id(),
                "Password" => $Values["Password"]
            ]
        );

        if ($SendEmail) {
            $NewUser->sendActivationEmail();
        }

        return $NewUser;
    }


    # ---- PRIVATE INTERFACE -------------------------------------------------

    /**
     * Append a suffix to a string (typically a username), truncating
     *   to keep the total length less than 24 characters.
     * @param string $TrialName Base string.
     * @param string $Suffix Suffix to append.
     * @param int $MaxLength Maximum length of the result (OPTIONAL,
     *   default 24 matching IsValidUsername() from Axis--User)
     * @return string Constructed string.
     */
    private static function appendSuffix(
        string $TrialName,
        string $Suffix,
        int $MaxLength = 24
    ): string {
        if (strlen($TrialName.$Suffix) > $MaxLength) {
            $TrialName = substr(
                $TrialName,
                0,
                $MaxLength - strlen($Suffix)
            );
        }

        return $TrialName.$Suffix;
    }

    /**
     * The resource factory for user resources.
     */
    protected $ResourceFactory;

    private static $LowMemoryThresh = 0.25;
}
