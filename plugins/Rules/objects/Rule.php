<?PHP
#
#   FILE:  Rule.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2017-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins\Rules;
use Exception;
use Metavus\Plugins\Mailer;
use Metavus\PrivilegeSet;
use Metavus\Record;
use Metavus\RecordFactory;
use Metavus\SearchEngine;
use Metavus\SearchParameterSet;
use Metavus\User;
use Metavus\UserFactory;
use ScoutLib\Item;

/**
 * Class representing an individual rule in the Rules plugin.
 * Each rule has a set of search parameters associated with it, that are used
 * to select records.Depending on the action type for the rule, there may
 * also be a privilege set for the rule, that determines the set of users to
 * which the action applies.For actions that involve users, the set of
 * records for each user is pared down to only those records that are viewable
 * by the user.
 */
class Rule extends Item
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /** Action types.*/
    const ACTION_NONE = 0;
    const ACTION_SENDEMAIL = 1;
    const ACTION_UPDATEFIELDVALUES = 2;

    /** Rule check frequencies.(Values are in minutes.) */
    const CHECKFREQ_ONCHANGE = -1;
    const CHECKFREQ_HOURLY = 60;
    const CHECKFREQ_DAILY = 1440;
    const CHECKFREQ_WEEKLY = 10080;

    /**
     * Create new rule.
     * @param SearchParameterSet $SearchParams Parameters to use when checking rule.
     * @param int $Action Action to take when records match rule.
     * @param array $ActionParams Parameters for action.(OPTIONAL)
     * @return Rule New rule object.
     */
    public static function create(
        SearchParameterSet $SearchParams,
        int $Action,
        array $ActionParams = []
    ): Rule {
        # instantiate new Rule object
        $InitialValues = [
            "LastChecked" => date("Y-m-d H:i:s"),
            "CheckFrequency" => self::CHECKFREQ_HOURLY,
            "DateCreated" => date("Y-m-d H:i:s"),
            "CreatedBy" => User::getCurrentUser()->id(),
        ];
        $Rule = parent::createWithValues($InitialValues);

        # set initial list of found records
        $Rule->searchParameters($SearchParams);
        $Rule->action(self::ACTION_NONE);
        $Rule->run();

        # set up target action
        $Rule->action($Action);
        $Rule->actionParameters($ActionParams);

        # return new Rule object to caller
        return $Rule;
    }

    /**
     * Get/set whether the rule is enabled.
     * @param bool $NewValue TRUE to enable, or FALSE to disable.(OPTIONAL)
     * @return bool TRUE if rule is enabled, otherwise FALSE.
     */
    public function enabled(?bool $NewValue = null): bool
    {
        return $this->DB->updateBoolValue("Enabled", $NewValue);
    }

    /**
     * Get/set how often to check the rule.
     * @param int $NewValue New frequency, in minutes.(OPTIONAL)
     * @return int Current frequency.
     */
    public function checkFrequency(?int $NewValue = null): int
    {
        return $this->DB->updateIntValue("CheckFrequency", $NewValue);
    }

    /**
     * Get/set search parameters used to identify matching resources.
     * @param SearchParameterSet $NewValue New parameters.(OPTIONAL)
     * @return SearchParameterSet Current search parameters.
     */
    public function searchParameters(?SearchParameterSet $NewValue = null): SearchParameterSet
    {
        $NewStoredValue = ($NewValue === null) ? null
                : $NewValue->data();
        $StoredValue = $this->DB->updateValue("SearchParams", $NewStoredValue);
        return new SearchParameterSet(
            strlen($StoredValue) ? $StoredValue : null
        );
    }

    /**
     * Get/set action to be taken for records that match rule.
     * @param int $NewValue New action.(OPTIONAL)
     * @return int Current action.
     */
    public function action(?int $NewValue = null): int
    {
        return $this->DB->updateIntValue("Action", $NewValue);
    }

    /**
     * Get/set parameters for action to be taken for records that match rule.
     * @param array $NewValue New parameter settings, with parameter names
     *       for index.(OPTIONAL)
     * @return array Current parameters, with parameter names for index.
     */
    public function actionParameters(?array $NewValue = null): array
    {
        if ($NewValue !== null) {
            $NewValue = serialize($NewValue);
        }
        $NewValue = @unserialize($this->DB->updateValue("ActionParams", $NewValue));
        return ($NewValue === false) ? [] : $NewValue;
    }

    /**
     * Get/set criteria used to select users as target for actions.
     * @param PrivilegeSet|null $NewValue New criteria.(OPTIONAL)
     * @return PrivilegeSet|null Current criteria or NULL if no criteria set.
     */
    public function userSelectionCriteria(?PrivilegeSet $NewValue = null)
    {
        $ActionParams = $this->actionParameters();
        if (func_num_args() > 0) {
            $ActionParams["Privileges"] = ($NewValue === null)
                    ? $NewValue : $NewValue->data();
            $this->actionParameters($ActionParams);
            return $NewValue;
        } else {
            return isset($ActionParams["Privileges"])
                    ? new PrivilegeSet($ActionParams["Privileges"])
                    : null;
        }
    }

    /**
     * Check rule for new matching resources and take any needed actions.
     */
    public function run(): void
    {
        # search for records that match rule
        $RecordIds = $this->getRecordsThatMatchSearchParams();

        # get users for rule
        $UserIds = $this->getUsersForAction();

        # get list of records from last run
        $LastUserRecordIds = $this->lastMatchingIds();

        # for each user
        $UserRecordIds = [];
        foreach ($UserIds as $UserId) {
            # get subset of records that match for user
            $UserRecordIds[$UserId] = $this->getRecordsThatMatchForUser(
                $UserId,
                $RecordIds
            );

            # continue to next user if user record list is empty
            if (!count($UserRecordIds[$UserId])) {
                continue;
            }

            # set target resource list to user record list minus those in last run
            $TargetRecordIds = array_diff(
                $UserRecordIds[$UserId],
                $LastUserRecordIds[$UserId] ?? []
            );

            # perform action with target record list (if we have records)
            if (count($TargetRecordIds)) {
                $this->performAction($UserId, $TargetRecordIds);
            }
        }

        # save new list of matching records
        $this->lastMatchingIds($UserRecordIds);

        # update when rule was last checked
        $this->lastChecked("NOW");
    }

    /**
     * Reset rule for specified user, so that next run will reflect only
     * changes from the point where this method was called..
     * @param int $UserId ID of user to reset.
     */
    public function resetForUser(int $UserId): void
    {
        # search for records that match rule
        $RecordIds = $this->getRecordsThatMatchSearchParams();

        # get list of records from last run
        $LastUserRecordIds = $this->lastMatchingIds();

        # get subset of records that match for user
        $LastUserRecordIds[$UserId] = $this->getRecordsThatMatchForUser(
            $UserId,
            $RecordIds
        );

        # save new list of matching records
        $this->lastMatchingIds($LastUserRecordIds);
    }

    /**
     * Set the default user, to be used when needed for actions where a user
     * is needed, and no other user has been set and no appropriate internal
     * method for retrieving users is available.
     * @param int $UserId ID of user.
     */
    public static function setDefaultUser(int $UserId): void
    {
        self::$DefaultUserId = $UserId;
    }


    # ---- PRIVATE INTERFACE -------------------------------------------------

    private $ChangedResources = [];

    private static $DefaultUserId;

    /**
     * Get/set when the rule was last checked.
     * @param string $NewValue Check timestamp, in format understood
     *       by strtotime().(OPTIONAL)
     * @return int Previous check timestamp, as a Unix timestamp.
     */
    private function lastChecked(?string $NewValue = null): int
    {
        $NewStoredValue = ($NewValue === null) ? null
                : date("Y-m-d H:i:s", (int)strtotime($NewValue));
        $StoredValue = $this->DB->updateValue("LastChecked", $NewStoredValue);
        return strtotime($StoredValue);
    }

    /**
     * Perform action for rule, with the specified user and specified set
     * of records.
     * @param int $UserId ID of user for action.
     * @param array $RecordIds IDs of records.
     */
    private function performAction(int $UserId, array $RecordIds): void
    {
        switch ($this->action()) {
            case self::ACTION_SENDEMAIL:
                $this->performActionSendEmail($RecordIds, $UserId);
                break;

            case self::ACTION_UPDATEFIELDVALUES:
                $this->performActionUpdateFieldValues($RecordIds, $UserId);
                break;

            case self::ACTION_NONE:
                break;

            default:
                throw new Exception("Unknown action (".$this->action()
                        .") for rule \"".$this->name()."\" (".$this->id().").");
        }
    }

    /**
     * Perform action: send email using set template.
     * @param array $RecordIds IDs of records to use for action.
     * @param int $UserId ID of user to perform action for.
     */
    private function performActionSendEmail(array $RecordIds, int $UserId): void
    {
        # retrieve action parameters
        $Params = $this->actionParameters();
        $TemplateId = $Params["Template"];
        $ConfirmBeforeSending = $Params["ConfirmBeforeSending"];

        # retrieve mailer plugin
        $Mailer = Mailer::getInstance();

        # set up extra email substitutions
        $ExtraValues = ["SEARCHCRITERIA" => $this->searchParameters()->textDescription()];

        # set up user-specific extra email substitutions
        $ExtraValues["TOTALNEWMATCHES"] = count($RecordIds);

        # send email to user
        $Mailer->sendEmail(
            $TemplateId,
            $UserId,
            $RecordIds,
            $ExtraValues,
            $ConfirmBeforeSending
        );
    }

    /**
     * Perform action: Update field values (Timestamps, Flags, Option fields)
     * @param array $RecordIds IDs of records to use for action.
     * @param int $UserId ID of user to perform action for.
     * @see ChangeSetEditingUI::getValuesFromFormData()
     */
    private function performActionUpdateFieldValues(array $RecordIds, int $UserId): void
    {
        # filter out records that were updated on this invocation
        $RecordIds = array_diff($RecordIds, $this->ChangedResources);

        # bail out if no records left to be changed
        if (count($RecordIds) == 0) {
            return;
        }

        # retrieve action parameters
        # ($EditParams format is [SchemaId => ChangesToApply,...]
        #       where ChangesToApply is in the format used by
        #       ChangeSetEditingUI::getValuesFromFormData())
        $Params = $this->actionParameters();
        $EditParams = $Params["EditParams"];

        $User = new User($UserId);
        foreach ($RecordIds as $RecordId) {
            $Resource = new Record($RecordId);

            # if we have no changes to apply for this resource's schema, skip it
            if (!isset($EditParams[$Resource->getSchemaId()])) {
                continue;
            }

            $ResourceWasChanged = $Resource->applyListOfChanges(
                $EditParams[$Resource->getSchemaId()],
                $User
            );

            if ($ResourceWasChanged) {
                $this->ChangedResources[$RecordId] = true;
            }
        }
    }

    /**
     * Get users targeted by rule action.
     * @return array IDs of users.
     */
    private function getUsersForAction(): array
    {
        switch ($this->action()) {
            case self::ACTION_SENDEMAIL:
                $PrivSet = $this->userSelectionCriteria();
                $UFactory = new UserFactory();
                if ($PrivSet === null) {
                    $UserIds = $UFactory->getUserIds();
                } else {
                    $UserIds = array_keys(
                        $UFactory->findUsersThatMeetRequirements($PrivSet)
                    );
                }
                break;

            default:
                $ActionParams = $this->actionParameters();
                if (isset($ActionParams["Users"])) {
                    $UserIds = $ActionParams["Users"];
                } else {
                    if (!isset(self::$DefaultUserId)) {
                        throw new Exception("Default user ID has not been set.");
                    }
                    $UserIds = [ self::$DefaultUserId ];
                }
                break;
        }
        return $UserIds;
    }

    /**
     * Check whether user selection criteria for rule action involves records.
     * @return bool TRUE if criteria involves records, otherwise FALSE.
     */
    private function userSelectionCriteriaInvolvesRecords(): bool
    {
        switch ($this->action()) {
            case self::ACTION_SENDEMAIL:
                $PrivSet = $this->userSelectionCriteria();
                return count($PrivSet->getAllConditions()) ? true : false;
        }
        return false;
    }

    /**
     * Get records that match rule search parameters.
     * @return array IDs of matching records.
     */
    private function getRecordsThatMatchSearchParams(): array
    {
        # substitute last check date/time into search parameters as needed
        $SearchParams = $this->searchParameters();
        $SearchParams->replaceSearchString(
            "/^@ LAST_CHECKED\$/",
            "@ ".date("Y-m-d H:i:s", $this->lastChecked())
        );

        # perform search for matching records
        $SEngine = new SearchEngine();
        $RawResults = $SEngine->search($SearchParams);
        $RecordIds = array_keys($RawResults);

        return $RecordIds;
    }

    /**
     * Determine whether user meets selection criteria when specific record
     * is considered.
     * @param int $UserId ID of user to check.
     * @param int $RecordId ID of record.
     * @return bool TRUE if user meets criteria, otherwise FALSE.
     */
    private function userMeetsSelectionCriteriaForRecord(
        int $UserId,
        int $RecordId
    ): bool {
        switch ($this->action()) {
            case self::ACTION_SENDEMAIL:
                $PrivSet = $this->userSelectionCriteria();
                $User = new User($UserId);
                $Record = new Record($RecordId);
                return $PrivSet->meetsRequirements($User, $Record);

            default:
                return true;
        }
    }

    /**
     * Find records that match for user from supplied set.
     * @param int $UserId ID of user.
     * @param array $RecordIds Set of records to select from.
     * @return array IDs of records that match.
     */
    private function getRecordsThatMatchForUser(int $UserId, array $RecordIds): array
    {
        # if rule has user selection criteria that involves checks against a record
        if ($this->userSelectionCriteriaInvolvesRecords()) {
            # for each record
            $UserRecordIds = [];
            foreach ($RecordIds as $RecordId) {
                # if user+record meets user selection criteria
                if ($this->userMeetsSelectionCriteriaForRecord($UserId, $RecordId)) {
                    # add record to list of records for user
                    $UserRecordIds[] = $RecordId;
                }
            }
        } else {
            # use all records for user
            $UserRecordIds = $RecordIds;
        }

        # filter records for user down to those viewable by user (if we have records)
        if (count($UserRecordIds)) {
            $User = new User($UserId);
            $UserRecordIds = RecordFactory::multiSchemaFilterNonViewableRecords(
                $UserRecordIds,
                $User
            );
        }

        return $UserRecordIds;
    }

    /**
     * Get/set per user list of record IDs that last matched for this rule.
     * @param array $NewValue Array of arrays of record IDs, indexed at the
     *      top level by user ID.(OPTIONAL)
     * @return array Array of arrays of record IDs, indexed by user ID.
     */
    private function lastMatchingIds(?array $NewValue = null): array
    {
        $NewStoredValue = ($NewValue === null) ? null : serialize($NewValue);
        $StoredValue = $this->DB->updateValue("LastMatchingIds", $NewStoredValue);
        $Value = strlen(trim($StoredValue)) ? unserialize($StoredValue) : [];
        return $Value;
    }

    /**
     * Set the database access values (table name, ID column name, name column
     * name) for specified class.This may be overridden in a child class, if
     * different values are needed.
     * @param string $ClassName Class to set values for.
     */
    protected static function setDatabaseAccessValues(string $ClassName): void
    {
        if (!isset(self::$ItemIdColumnNames[$ClassName])) {
            self::$ItemIdColumnNames[$ClassName] = "RuleId";
            self::$ItemNameColumnNames[$ClassName] = "Name";
            self::$ItemTableNames[$ClassName] = "Rules_Rules";
        }
    }

    const SQL_TABLES = [
        "Rules_Rules" => "CREATE TABLE IF NOT EXISTS Rules_Rules (
                RuleId                  INT NOT NULL AUTO_INCREMENT,
                Name                    TEXT,
                Enabled                 INT DEFAULT 1,
                CheckFrequency          INT DEFAULT 60,
                LastChecked             DATETIME,
                SearchParams            BLOB,
                LastMatchingIds         MEDIUMBLOB,
                Action                  INT,
                ActionParams            BLOB,
                DateCreated             DATETIME,
                CreatedBy               INT,
                DateLastModified        DATETIME,
                LastModifiedBy          INT,
                INDEX                   Index_I (RuleId)
            );",
    ];
}
