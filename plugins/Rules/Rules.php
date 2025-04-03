<?PHP
#
#   FILE:  Rules.php
#
#   A plugin for the Metavus digital collections platform
#   Copyright 2012-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins;
use Metavus\FormUI;
use Metavus\Plugin;
use Metavus\Plugins\Rules\Rule;
use Metavus\Plugins\Rules\RuleFactory;
use Metavus\Record;
use Metavus\SystemConfiguration;
use Metavus\UserFactory;
use ScoutLib\ApplicationFramework;
use ScoutLib\Database;

/**
 * Plugin for defining and acting upon rules that describe a change in one or
 * more metadata field states and actions to take when those changes are detected.
 */
class Rules extends Plugin
{

    # ---- STANDARD PLUGIN INTERFACE -----------------------------------------

    /**
     * Set the plugin attributes.  At minimum this method MUST set $this->Name
     * and $this->Version.  This is called when the plugin is initially loaded.
     */
    public function register(): void
    {
        $this->Name = "Rules";
        $this->Version = "2.1.1";
        $this->Description = "Allows specifying rules that describe changes"
                ." in metadata that will trigger email to be sent or other"
                ." actions.";
        $this->Author = "Internet Scout Research Group";
        $this->Url = "https://metavus.net";
        $this->Email = "support@metavus.net";
        $this->Requires = [ "MetavusCore" => "1.2.0" ];
        $this->EnabledByDefault = true;

        $this->CfgSetup["MinutesBetweenChecks"] = [
            "Type" => FormUI::FTYPE_NUMBER,
            "Label" => "Rule Check Interval",
            "Units" => "minutes",
            "MaxVal" => 999999,
            "Default" => 5,
            "Help" => "The number of minutes between checks for rules"
                ." that may be ready to execute.  (This does not"
                ." include rules set to be checked <i>On Change</i>"
                ." which will be checked any time a resource is"
                ." updated.)",
        ];
        $this->CfgSetup["DefaultUser"] = [
            "Type" => FormUI::FTYPE_USER,
            "Label" => "Default User",
            "AllowMultiple" => false,
            "Help" => "The user to act as when performing rule actions"
                ." (e.g. setting values) when no other user is"
                ." defined by the action parameters.",
        ];
        $this->addAdminMenuEntry(
            "ListRules",
            "Automation Rules",
            [ PRIV_COLLECTIONADMIN ]
        );
    }

    /**
     * Initialize the plugin.  This is called (if the plugin is enabled) after
     * all plugins have been loaded but before any methods for this plugin
     * (other than register()) have been called.
     * @return null|string NULL if initialization was successful, otherwise a
     *      string or array of strings containing error message(s) indicating
     *      why initialization failed.
     */
    public function initialize(): ?string
    {
        Rule::setDefaultUser($this->getConfigSetting("DefaultUser")[0]);

        Record::registerObserver(
            Record::EVENT_SET,
            [$this, "resourceModifiedRuleCheck"]
        );

        return null;
    }

    /**
     * Perform any work needed when the plugin is first installed (for example,
     * creating database tables).
     * @return null|string NULL if installation succeeded, otherwise a string
     *       containing an error message indicating why installation failed.
     */
    public function install(): ?string
    {
        $this->setConfigSetting(
            "DefaultUser",
            [ (new UserFactory())->getSiteOwner() ]
        );

        # create database tables
        $DB = new Database();
        return $DB->createTables(Rule::SQL_TABLES);
    }

    /**
     * Perform any work needed when the plugin is uninstalled.
     * @return null|string NULL if uninstall succeeded, otherwise a string
     *       containing an error message indicating why uninstall failed.
     */
    public function uninstall(): ?string
    {
        # drop database tables
        $DB = new Database();
        return $DB->dropTables(Rule::SQL_TABLES);
    }

    /**
     * Hook the events into the application framework.
     * @return array Returns an array of events to be hooked into the application
     *      framework.
     */
    public function hookEvents(): array
    {
        return [
            "EVENT_PERIODIC" => "periodicRuleCheck",
            "EVENT_USER_PRIVILEGES_CHANGED" => "userPrivChangeRuleUpdate",
        ];
    }


    # ---- CALLABLE METHODS --------------------------------------------------

    /**
     * Reset rules for specified user, so that next run will reflect only
     * changes from the point where this method was called.
     * @param int $UserId ID of user to reset.
     */
    public static function resetRulesForUser(int $UserId): void
    {
        $AF = ApplicationFramework::getInstance();
        # if there are search index rebuild tasks running or queued
        if ($AF->taskIsInQueue(["\\Metavus\\SearchEngine", "runUpdateForItem"])) {
            # requeue ourselves
            $AF->requeueCurrentTask();
            return;
        }

        $RFactory = new RuleFactory();
        foreach ($RFactory->getItems() as $RuleId => $Rule) {
            $Rule->resetForUser($UserId);
        }
    }

    /**
     * Check rules when run as a background task.
     */
    public function checkRules(): void
    {
        $AF = ApplicationFramework::getInstance();
        # if there are search index rebuild tasks running or queued or
        #   if there's a reset task queued (because of user priv update)
        if ($AF->taskIsInQueue(["\\Metavus\\SearchEngine", "runUpdateForItem"])
                || $AF->taskIsInQueue([__CLASS__, "resetRulesForUser"])) {
            # requeue ourselves and exit
            $AF->requeueCurrentTask();
            return;
        }

        # for each rule ready to be checked
        $RFactory = new RuleFactory();
        foreach ($RFactory->getRulesReadyToCheck() as $RuleId => $Rule) {
            # check rule and perform any appropriate actions
            $Rule->run();
        }
    }

    /**
     * List rules from command line.  (Developer plugin command.)
     * Usage:  mvus plugin command rules list (or mvus pl com ru list)
     */
    public function commandList(): void
    {
        $Frequencies = [
            60 => "Hourly",
            240 => "Every 4 Hours",
            480 => "Every 8 Hours",
            1440 => "Daily",
            10080 => "Weekly",
            0 => "Continuously",
        ];
        $Format = "%2.2s  %-30.30s  %-7.7s  %-15s\n";
        printf($Format, "ID", "NAME", "ENABLED", "FREQUENCY");
        $RFactory = new RuleFactory();
        foreach ($RFactory->getItemIds() as $RuleId) {
            $Rule = new Rule($RuleId);
            printf(
                $Format,
                $RuleId,
                $Rule->name(),
                $Rule->enabled() ? "Yes" : "No",
                $Frequencies[$Rule->checkFrequency()]
            );
        }
    }

    /**
     * Enable rule from command line.  (Developer plugin command.)
     * Usage:  mvus plugin command rules enable RuleID (or mvus pl com ru enable RuleID)
     * @param array $Args Command line arguments to rule.
     */
    public function commandEnable(array $Args): void
    {
        $Rules = $this->convertCommandLineRuleArgument($Args);
        if ($Rules === false) {
            return;
        }
        if (is_array($Rules)) {
            foreach ($Rules as $Rule) {
                $Rule->enabled(true);
                print "Rule \"".$Rule->name()."\" (ID:".$Rule->id().") enabled.\n";
            }
            print count($Rules)." rules enabled.";
        } else {
            $Rule = $Rules;
            $Rule->enabled(true);
            print "Rule \"".$Rule->name()."\" (ID:".$Rule->id().") enabled.";
        }
    }

    /**
     * Disable rule from command line.  (Developer plugin command.)
     * Usage:  mvus plugin command rules disable RuleID (or mvus pl com ru disable RuleID)
     * @param array $Args Command line arguments to rule.
     */
    public function commandDisable(array $Args): void
    {
        $Rules = $this->convertCommandLineRuleArgument($Args);
        if ($Rules === false) {
            return;
        }
        if (is_array($Rules)) {
            foreach ($Rules as $Rule) {
                $Rule->enabled(false);
                print "Rule \"".$Rule->name()."\" (ID:".$Rule->id().") disabled.\n";
            }
            print count($Rules)." rules disabled.";
        } else {
            $Rule = $Rules;
            $Rule->enabled(false);
            print "Rule \"".$Rule->name()."\" (ID:".$Rule->id().") disabled.";
        }
    }

    /**
     * Run rule from command line.  (Developer plugin command.)
     * Usage:  mvus plugin command rules run RuleID (or mvus pl com ru run RuleID)
     * @param array $Args Command line arguments to rule.
     */
    public function commandRun(array $Args): void
    {
        $Rules = $this->convertCommandLineRuleArgument($Args);
        if ($Rules === false) {
            return;
        }
        if (is_array($Rules)) {
            foreach ($Rules as $Rule) {
                print "Running rule \"".$Rule->name()."\" (ID:".$Rule->id().")...";
                $Rule->run();
            }
            print count($Rules)." rules run.";
        } else {
            $Rule = $Rules;
            print "Running rule \"".$Rule->name()."\" (ID:".$Rule->id().")...";
            $Rule->run();
            print "done.\n";
        }
    }

    # ---- HOOKED METHODS ----------------------------------------------------

    /**
     * Check rules and take any corresponding actions (hooked to EVENT_PERIODIC).
     * @param string|null $LastRunAt Date and time the event was last run, in SQL
     *       date format, or NULL if unknown. (Passed in by the event signaler,
     *       but not used)
     * @return int Number of minutes before the even should be run again.
     */
    public function periodicRuleCheck($LastRunAt): int
    {
        # check the rules and take any necessary actions
        $this->checkRules();

        # return to caller the number of minutes before we should check again
        return $this->getConfigSetting("MinutesBetweenChecks");
    }

    /**
     * Queue task to check rules and take any corresponding actions (hooked
     * to Record::EVENT_SET observer).
     * @param int $Events Record::EVENT_* values OR'd together.
     * @param Record $Resource The Resource that has been modified.
     *       (passed in from the Event signaler, but not used)
     */
    public function resourceModifiedRuleCheck(int $Events, Record $Resource): void
    {
        $AF = ApplicationFramework::getInstance();

        # queue rule check task with (if possible) lower priority than
        #       search index rebuild
        $RuleCheckCallback = [$this, "checkRules"];
        $SearchEnginePriority = SystemConfiguration::getInstance()->getInt(
            "SearchEngineUpdatePriority"
        );
        $Priority = ApplicationFramework::getInstance()->getNextLowerBackgroundPriority(
            $SearchEnginePriority
        );
        $Description = "Check automation rules after modification of"
                ." <a href=\"r".$Resource->id()."\"><i>"
                .$Resource->getMapped("Title")."</i></a>";
        $AF->queueUniqueTask(
            $RuleCheckCallback,
            [],
            $Priority,
            $Description
        );
    }

    /**
     * Queue a task to check all rules but do NOT take corresponding
     * actions (hooked to EVENT_USER_PRIVILEGES_CHANGED).
     * @param int $UserId User whose privileges were updated.
     * @param array $OldPrivileges Privileges user previously had.
     * @param array $NewPrivileges Privileges user has now.
     */
    public function userPrivChangeRuleUpdate(
        int $UserId,
        array $OldPrivileges,
        array $NewPrivileges
    ): void {
        $AF = ApplicationFramework::getInstance();
        $RuleCheckCallback = [$this, "resetRulesForUser"];
        $SearchEnginePriority = SystemConfiguration::getInstance()->getInt(
            "SearchEngineUpdatePriority"
        );
        $Priority = ApplicationFramework::getInstance()->getNextLowerBackgroundPriority(
            $SearchEnginePriority
        );
        $Description = "Reset rules for user after modification of user privileges.";
        $AF->queueUniqueTask(
            $RuleCheckCallback,
            [$UserId],
            $Priority,
            $Description
        );
    }

    # ---- PRIVATE INTERFACE -------------------------------------------------

    /**
     * Convert command line rule ID argument to rule(s), with printed
     * error output if appropriate.
     * @param array $Args Command line arguments to rule.
     * @return array|Rule|false Rule or set of rules or FALSE if no valid
     *      rule ID found.
     */
    private function convertCommandLineRuleArgument(array $Args)
    {
        if (count($Args) == 0) {
            print "No rule ID supplied.";
            return false;
        } else {
            $RuleId = $Args[0];
            $RFactory = new RuleFactory();
            if (strtoupper($RuleId) == "ALL") {
                return $RFactory->getItems();
            } elseif (!$RFactory->itemExists($RuleId)) {
                print "No rule found with ID \"".$RuleId."\".";
                return false;
            } else {
                return new Rule($RuleId);
            }
        }
    }
}
