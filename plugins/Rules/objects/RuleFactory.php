<?PHP
#
#   FILE:  RuleFactory.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2017-2020 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins\Rules;

use Metavus\Plugins\Rules\Rule;
use ScoutLib\ItemFactory;

/**
 * Factory class for Rule objects for Rules plugin.
 */
class RuleFactory extends ItemFactory
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Object constructor.
     */
    public function __construct()
    {
        # set up item factory base class
        parent::__construct(
            "\Metavus\Plugins\Rules\Rule",
            "Rules_Rules",
            "RuleId",
            "Name"
        );
    }

    /**
     * Retrieve rules that are ready to be checked.
     * @return array Rules (objects) ready to be checked, with rule IDs
     *       for the index.
     */
    public function getRulesReadyToCheck()
    {
        # retrieve IDs of rules in need of checking
        $CheckTest = "(Enabled = 1) AND ((UNIX_TIMESTAMP(LastChecked)"
                ." + (CheckFrequency * 60)) < UNIX_TIMESTAMP())";
        $this->DB->Query("SELECT RuleId FROM Rules_Rules WHERE ".$CheckTest);
        $RuleIds = $this->DB->FetchColumn("RuleId");

        # instantiate rule objects
        $Rules = [];
        foreach ($RuleIds as $RuleId) {
            $Rules[$RuleId] = new Rule($RuleId);
        }

        # return found rules (if any) to caller
        return $Rules;
    }

    /**
     * Retrieve rules that should be checked when resources change.
     * @return array Rules (objects) to be checked when items change, with rule
     *       IDs for the index.
     */
    public function getRulesToCheckOnChange()
    {
        # retrieve IDs of rules marked to be checked upon change
        $this->DB->Query("SELECT RuleId FROM Rules_Rules"
                ." WHERE CheckFrequency = ".Rule::CHECKFREQ_ONCHANGE);
        $RuleIds = $this->DB->FetchColumn("RuleId");

        # instantiate rule objects
        $Rules = [];
        foreach ($RuleIds as $RuleId) {
            $Rules[$RuleId] = new Rule($RuleId);
        }

        # return found rules (if any) to caller
        return $Rules;
    }


    # ---- PRIVATE INTERFACE -------------------------------------------------
}
