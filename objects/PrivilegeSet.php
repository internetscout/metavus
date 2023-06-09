<?PHP
#
#   FILE:  PrivilegeSet.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2013-2022 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus;

use Exception;
use InvalidArgumentException;
use ScoutLib\ApplicationFramework;
use ScoutLib\StdLib;
use XMLWriter;

/**
 * Set of privileges used to access resource information or other parts of
 * the system.  A privilege set is a logic setting (AND/OR) and a combination
 * of privileges (integers), comparisons (field/operator/value triplets), and
 * privilege subsets (additional PrvilegeSet objects).
 */
class PrivilegeSet
{
    /**
     * Class constructor, used to create a new set or reload an existing
     * set from previously-constructed data.
     * @param mixed $Data Existing privilege set data, previously
     *       retrieved with PrivilegeSet::Data(), array of privilege
     *       values, or a single privilege value.  (OPTIONAL)
     * @throws InvalidArgumentException If data passed in was invalid.
     * @see PrivilegeSet::Data()
     */
    public function __construct($Data = null)
    {
        # if privilege data supplied
        if ($Data !== null) {
            # if data is an array of privileges
            if (is_array($Data)) {
                # set internal privilege set from array
                $this->Privileges = $Data;
            # else if data is a single privilege
            } elseif (is_numeric($Data)) {
                # set internal privilege set from data
                $this->Privileges = [$Data];
            } else {
                # set internal values from data
                $this->loadFromData($Data);
            }
        }
    }

    /**
     * Get/set privilege set data, in the form of an opaque string.  This
     * method can be used to retrieve an opaque string containing privilege
     * set data, which can then be saved (e.g. to a database) and later used
     * to reload a privilege set.  (Use instead of serialize() to avoid
     * future issues with internal class changes.)
     * @param string $NewValue New privilege set data.  (OPTIONAL)
     * @return string Current privilege set data (opaque value).
     * @throws InvalidArgumentException If data passed in was invalid.
     */
    public function data(string $NewValue = null): string
    {
        # if new data supplied
        if ($NewValue !== null) {
            # unpack privilege data and load
            $this->loadFromData($NewValue);
        }

        # serialize current data and return to caller
        $Data = [];
        if (count($this->Privileges)) {
            foreach ($this->Privileges as $Priv) {
                $Data["Privileges"][] = ($Priv instanceof self)
                        ? ["SUBSET" => $Priv->data()]
                        : $Priv;
            }
        }
        $Data["Logic"] = $this->Logic;
        return serialize($Data);
    }

    /**
     * Determine if a given user meets the requirements specified by
     * this PrivilegeSet.  Typically used to determine if a user should
     * be allowed access to a particular piece of data.
     * @param User $User User object to use in comparisons.
     * @param mixed $Resource Resource object to used for comparison, for
     *       sets that include user conditions.  (OPTIONAL)
     * @return bool TRUE if privileges in set are greater than or equal to
     *       privileges in specified set, otherwise FALSE.
     */
    public function meetsRequirements(User $User, $Resource = self::NO_RESOURCE): bool
    {
        # reset expiration date
        $this->ExpirationDate = false;
        return $this->meetsRequirementsRecursive($User, $Resource);
    }

    /**
     * If the result of the most recent meetsRequirements() call is only valid
     *   for a certain time because it contains comparisons against timestamp
     *   fields, get the time when it will expire.
     * @return bool|string FALSE when results will not expire, a string in SQL date format
     *   giving the expiration time if it will.
     */
    public function getResultExpirationDate()
    {
        if ($this->ExpirationDate === false) {
            return false;
        }
        return date(StdLib::SQL_DATE_FORMAT, $this->ExpirationDate);
    }

    /**
     * Add specified privilege to set.  If specified privilege is already
     * part of the set, no action is taken.
     * @param mixed $Privileges Privilege ID or object (or array of IDs or objects).
     * @see PrivilegeSet::removePrivilege()
     */
    public function addPrivilege($Privileges)
    {
        # convert incoming value to array if needed
        if (!is_array($Privileges)) {
            $Privileges = [$Privileges];
        }

        # for each privilege passed in
        foreach ($Privileges as $Privilege) {
            # add privilege if not currently in set
            if (!$this->includesPrivilege($Privilege)) {
                if ($Privilege instanceof Privilege) {
                    $Privilege = $Privilege->id();
                }
                $this->Privileges[] = $Privilege;
            }
        }
    }

    /**
     * Remove specified privilege from set.  If specified privilege is not
     * currently in the set, no action is taken.
     * @param mixed $Privilege Privilege ID or object to remove from set.
     * @see PrivilegeSet::addPrivilege()
     */
    public function removePrivilege($Privilege)
    {
        # remove privilege if currently in set
        if ($this->includesPrivilege($Privilege)) {
            if ($Privilege instanceof Privilege) {
                $Privilege = $Privilege->id();
            }
            $Index = array_search($Privilege, $this->Privileges);
            unset($this->Privileges[$Index]);
        }
    }

    /**
     * Check whether this privilege set includes the specified privilege.
     * @param mixed $Privilege Privilege ID or object to check.
     * @return bool TRUE if privilege is included, otherwise FALSE.
     */
    public function includesPrivilege($Privilege): bool
    {
        # check whether privilege is in our list and report to caller
        if ($Privilege instanceof Privilege) {
            $Privilege = $Privilege->id();
        }
        return $this->isInPrivilegeData($Privilege) ? true : false;
    }

    /**
     * Get privilege information as an array, with numerical indexes
     * except for the logic, which is contained in a element with the
     * index "Logic".  Values are either an associative array with
     * three elements, "FieldId", "Operator", and "Value", or a
     * PrivilegeSet object (for subsets).
     * @return array Array with privilege information.
     * @deprecated
     */
    public function getPrivilegeInfo(): array
    {
        # log a warning message to alert the usage of a deprecated function
        (ApplicationFramework::getInstance())->logMessage(
            ApplicationFramework::LOGLVL_WARNING,
            "Call to deprecated function ".__FUNCTION__. " at ".StdLib::getMyCaller()
        );

        # grab privilege information and add logic
        $Info = $this->Privileges;
        $Info["Logic"] = $this->Logic;

        # return privilege info array to caller
        return $Info;
    }

    /**
     * Get list of privileges.  (Intended primarily for supporting legacy
     * privilege operations -- list contains privilege IDs only, and does
     * not include conditions.)
     * @return array Array of privilege IDs.
     * @deprecated
     */
    public function getPrivilegeList(): array
    {
        # log a warning message to alert the usage of a deprecated function
        (ApplicationFramework::getInstance())->logMessage(
            ApplicationFramework::LOGLVL_WARNING,
            "Call to deprecated function ".__FUNCTION__. " at ".StdLib::getMyCaller()
        );

        return $this->getPrivileges();
    }

    /**
     * Get list of privilege flags included in set, NOT including those
     * in subgroups.
     * @return array List of privilege flags (integers).
     */
    public function getPrivileges(): array
    {
        $Privs = [];
        foreach ($this->Privileges as $Priv) {
            if (is_numeric($Priv)) {
                $Privs[] = $Priv;
            }
        }
        return $Privs;
    }

    /**
     * Get list of privilege flags included in set, INCLUDING all those
     * in subgroups.
     * @return array List of privilege flags (integers).
     */
    public function getAllPrivileges(): array
    {
        $Privs = $this->getPrivileges();
        foreach ($this->Privileges as $Priv) {
            if ($Priv instanceof PrivilegeSet) {
                $Privs = array_merge($Privs, $Priv->getPrivileges());
            }
        }
        return $Privs;
    }

    /**
     * Add condition to privilege set.  If the condition is already present
     * in the set, no action is taken.
     * @param mixed $Field Metadata field object or ID to test against.
     * @param mixed $Value Value to test against.  User fields expect a
     *       UserId or expect NULL to test against the current user.
     *       Option fields expect a ControlledNameId.  Date and
     *       Timestamp fields expect either a UNIX timestamp or expect
     *       NULL to test against the current time.
     * @param string $Operator String containing operator to used for
     *       condition.  (Standard PHP operators are used.)  (OPTIONAL,
     *       defaults to "==")
     * @return bool TRUE if condition was added, otherwise FALSE.
     * @throws InvalidArgumentException If invalid field provided.
     */
    public function addCondition($Field, $Value = null, string $Operator = "=="): bool
    {
        # get field ID
        $FieldId = MetadataSchema::getCanonicalFieldIdentifier($Field);

        # set up condition array
        $Condition = [
            "FieldId" => intval($FieldId),
            "Operator" => trim($Operator),
            "Value" => $Value
        ];

        # if condition is not already in set
        if (!$this->isInPrivilegeData($Condition)) {
            # add condition to privilege set
            $this->Privileges[] = $Condition;
            return true;
        }
        return false;
    }

    /**
     * Remove condition from privilege set.  If condition was not present
     * in privilege set, no action is taken.
     * @param mixed $Field Metadata field object or ID to test against.
     * @param mixed $Value Value to test against.  (Specify NULL for User
     *       fields to test against current user.)
     * @param string $Operator String containing operator to used for
     *       condition.  (Standard PHP operators are used.)  (OPTIONAL,
     *       defaults to "==")
     * @param bool $IncludeSubsets TRUE to remove the condition from any
     *       subsets in which it appears as well (OPTIONAL, default FALSE).
     * @return bool TRUE if condition was removed, otherwise FALSE.
     */
    public function removeCondition(
        $Field,
        $Value = null,
        string $Operator = "==",
        bool $IncludeSubsets = false
    ): bool {

        $Result = false;

        # get field ID
        $FieldId = ($Field instanceof MetadataField) ? $Field->id() : $Field;

        # set up condition array
        $Condition = [
            "FieldId" => intval($FieldId),
            "Operator" => trim($Operator),
            "Value" => $Value
        ];

        # if condition is in set
        if ($this->isInPrivilegeData($Condition)) {
            # remove condition from privilege set
            $Index = array_search($Condition, $this->Privileges);
            unset($this->Privileges[$Index]);
            $Result = true;
        }

        if ($IncludeSubsets) {
            foreach ($this->Privileges as $Priv) {
                if ($Priv instanceof PrivilegeSet) {
                    $Result = ($Result || $Priv->removeCondition(
                        $FieldId,
                        $Value,
                        $Operator,
                        true
                    ));
                }
            }
        }

        return $Result;
    }

    /**
     * Get list of conditions present in privilege set, NOT including
     * those in subgroups.
     * @return array Array of associative arrays of conditions, with
     *      "FieldId", "Operator", and "Value" entries.
     */
    public function getConditions(): array
    {
        $Conditions = [];
        foreach ($this->Privileges as $Priv) {
            if (is_array($Priv)) {
                $Conditions[] = $Priv;
            }
        }
        return $Conditions;
    }

    /**
     * Get list of all conditions present in privilege set, INCLUDING
     * those in subgroups.
     * @return array Array of associative arrays of conditions, with
     *      "FieldId", "Operator", and "Value" entries.
     */
    public function getAllConditions(): array
    {
        $Conditions = $this->getConditions();
        foreach ($this->Privileges as $Priv) {
            if ($Priv instanceof PrivilegeSet) {
                $Conditions = array_merge($Conditions, $Priv->getConditions());
            }
        }
        return $Conditions;
    }

    /**
     * Check if the privilege set is empty (i.e. has no conditions, subsets,
     * or user privileges).
     * NOTE: This function doesn't care whether the privilege Logic is set or not.
     * @return bool TRUE if the set is empty. Otherwise, returns FALSE.
     */
    public function isEmpty(): bool
    {
        return (count($this->Privileges) == 0);
    }

    /**
     * Add subgroup of privileges/conditions to set.
     * @param PrivilegeSet $Set Subgroup to add.
     */
    public function addSubset(PrivilegeSet $Set)
    {
        # if subgroup is not already in set
        if (!$this->isInPrivilegeData($Set)) {
            # add subgroup to privilege set
            $this->Privileges[] = $Set;
        }
    }

    /**
     * Get a list of the privilege subsets included in the privilege set.
     * @return array List of privilege subsets found.
     */
    public function getSubsets(): array
    {
        $Subsets = [];
        foreach ($this->Privileges as $Privilege) {
            if ($Privilege instanceof self) {
                $Subsets[] = $Privilege;
            }
        }
        return $Subsets;
    }

    /**
     * Get/set whether all privileges/conditions in set are required (i.e.
     * "AND" logic), or only one privilege/condition needs to be met ("OR").
     * By default only one of the specified privilegs/conditions in a set
     * is required.
     * @param bool $NewValue Specify TRUE if all privileges are required,
     *       otherwise FALSE if only one privilege required.  (OPTIONAL)
     * @return bool TRUE if all privileges required, otherwise FALSE.
     */
    public function usesAndLogic(bool $NewValue = null): bool
    {
        if ($NewValue !== null) {
            $this->Logic = $NewValue ? "AND" : "OR";
        }
        return ($this->Logic == "AND") ? true : false;
    }

    /**
     * List which privilege flags (e.g. PRIV_COLLECTIONADMIN) are examined by
     *  this privset.
     * @return Array of privilege flags checked.
     */
    public function privilegeFlagsChecked(): array
    {
        $Privileges = $this->getPrivileges();
        $Subsets = $this->getSubsets();
        $Result = [];

        foreach ($Privileges as $Privilege) {
            $Result[] = $Privilege;
        }

        foreach ($Subsets as $Subset) {
            $Result = array_merge($Result, $Subset->privilegeFlagsChecked());
        }

        return array_unique($Result);
    }

    /**
     * List which fields in this privset are involved in UserIs or UserIsNot
     *  comparisons for this privilege set.
     * @param string $ComparisonType Comparison Type ("==" or "!="). (OPTIONAL,
     *       defaults to both comparisons)
     * @return Array of FieldIds that have a User comparison.
     */
    public function fieldsWithUserComparisons(string $ComparisonType = null): array
    {
        $Conditions = $this->getConditions();
        $Subsets = $this->getSubsets();
        $Result = [];

        foreach ($Subsets as $Subset) {
            $Result = array_merge(
                $Result,
                $Subset->fieldsWithUserComparisons($ComparisonType)
            );
        }

        foreach ($Conditions as $Condition) {
            if ((($Condition["Operator"] == $ComparisonType) || ($ComparisonType === null)) &&
                MetadataSchema::fieldExistsInAnySchema($Condition["FieldId"])) {
                $Field = new MetadataField($Condition["FieldId"]);

                if ($Field->type() == MetadataSchema::MDFTYPE_USER) {
                    $Result[] = $Condition["FieldId"];
                }
            }
        }

        return array_unique($Result);
    }

    /**
     * Get number of privilege comparisons in set, including those in subgroups.
     * @return int Comparison count.
     */
    public function comparisonCount(): int
    {
        $Count = 0;
        foreach ($this->Privileges as $Priv) {
            $Count += ($Priv instanceof self) ? $Priv->comparisonCount() : 1;
        }
        return $Count;
    }

    /**
     * Get all privileges that could be necessary to fulfill privilege set
     * requirements.  Not all of the privileges may be necessary, but a user
     * must have at least one of the listed privileges to qualify.  If there
     * is no set of privileges where at least one is definitely required, an
     * empty array is returned.
     * @return array Privilege IDs.
     */
    public function getPossibleNecessaryPrivileges(): array
    {
        # for each privilege requirement
        $NecessaryPrivs = [];
        foreach ($this->Privileges as $Priv) {
            # if requirement is comparison
            if (is_array($Priv)) {
                # if logic is OR
                if ($this->Logic == "OR") {
                    # bail out because no privileges are required
                    return [];
                }
            # else if requirement is subgroup
            } elseif ($Priv instanceof self) {
                # retrieve possible needed privileges from subgroup
                $SubPrivs = $Priv->getPossibleNecessaryPrivileges();

                # if no privileges were required by subgroup
                if (!count($SubPrivs)) {
                    # if logic is OR
                    if ($this->Logic == "OR") {
                        # bail out because no privileges are required
                        return [];
                    }
                } else {
                    # add subgroup privileges to required list
                    $NecessaryPrivs = array_merge($NecessaryPrivs, $SubPrivs);
                }
            # else requirement is privilege
            } else {
                # add privilege to required list
                $NecessaryPrivs[] = $Priv;
            }
        }

        # return possible needed privileges to caller
        return $NecessaryPrivs;
    }

    /**
     * Determine if a PrivilegeSet checks values from a specified field.
     * @param int $FieldId FieldId to check.
     * @return bool TRUE if the given field is checked, otherwise FALSE.
     */
    public function checksField($FieldId): bool
    {
        # iterate over all the privs in this privset
        foreach ($this->Privileges as $Priv) {
            # if this priv is a field condition that references the
            # provided FieldId, return true
            if (is_array($Priv) && $Priv["FieldId"] == $FieldId) {
                return true;
            # otherwise, if this was a privset then call ourself recursively
            } elseif ($Priv instanceof PrivilegeSet &&
                    $Priv->checksField($FieldId)) {
                return true;
            }
        }

        # found no references to this field, return FALSE
        return false;
    }

    /**
     * On unserialize(), make sure settings from non-namespaced stored objects
     *   are properly restored.
     */
    public function __wakeup()
    {
        StdLib::loadLegacyPrivateVariables($this);
    }

    /**
     * Clear internal caches.  This is primarily intended for situations where
     * memory may have run low.
     */
    public static function clearCaches()
    {
        self::$MetadataFieldCache = [];
        self::$ValueCache = [];
    }

    /**
     * Create a new PrivilegeSet from an XML file.
     * @param iterable $Xml Element containing privilege XML.
     * @param MetadataSchema $Schema the $Schema that invoked the PrivilegeSet creation.
     * @throws Exception if conversion fails.
     * @return PrivilegeSet Resulting PrivilegeSet upon conversion success.
     */
    public static function createFromXml($Xml, $Schema)
    {
        # create new privilege set
        $PrivSet = new PrivilegeSet();

        # for each XML child
        foreach ($Xml as $Tag => $Value) {
            # take action based on element name
            switch ($Tag) {
                case "PrivilegeSet":
                case "AddSubset":
                    # convert child data to new set
                    $NewSet = PrivilegeSet::createFromXML($Value, $Schema);

                    # add new set to our privilege set
                    $PrivSet->addSubset($NewSet);
                    break;

                case "AddCondition":
                    # start with default values for optional parameters
                    unset($ConditionField);
                    $ConditionValue = null;
                    $ConditionOperator = "==";

                    # pull out parameters
                    foreach ($Value as $ParamName => $ParamValue) {
                        $ParamValue = trim($ParamValue);
                        switch ($ParamName) {
                            case "Field":
                                if (!$Schema->fieldExists($ParamValue)) {
                                    # record error about unknown field and bail
                                    throw new Exception(
                                        "Unknown metadata field name found"
                                        ." in AddCondition (".$ParamValue.")."
                                    );
                                }
                                $ConditionField = $Schema->getField($ParamValue);
                                break;

                            case "Value":
                                $ConditionValue = (string)$ParamValue;

                                if ($ConditionValue == "NULL") {
                                    $ConditionValue = null;
                                } elseif ($ConditionValue == "TRUE") {
                                    $ConditionValue = true;
                                } elseif ($ConditionValue == "FALSE") {
                                    $ConditionValue = false;
                                }
                                break;

                            case "Operator":
                                $ConditionOperator = (string)$ParamValue;
                                break;

                            default:
                                # record error about unknown parameter name and bail
                                throw new Exception(
                                    "Unknown tag found in AddCondition (".$ParamName.")."
                                );
                        }
                    }

                    # if no field value
                    if (!isset($ConditionField)) {
                        # record error about no field value and bail
                        throw new Exception(
                            "No metadata field specified in AddCondition."
                        );
                    }

                    # if this is a metadata field
                    if ($ConditionField instanceof MetadataField && !is_null($ConditionValue)) {
                        # and if this field has a factory that provides a getItemIdByName method
                        $Factory = $ConditionField->getFactory();
                        if (is_object($Factory) && method_exists($Factory, "getItemIdByName")) {
                            # look up the id of the provided value
                            $ConditionValue = $Factory->getItemIdByName(
                                $ConditionValue
                            );

                            # if none was found, error out
                            if ($ConditionValue === false) {
                                throw new Exception(
                                    "Invalid value for field specified in AddCondition."
                                );
                            }
                        }
                    }

                    # add conditional to privilege set
                    $PrivSet->addCondition(
                        $ConditionField,
                        $ConditionValue,
                        $ConditionOperator
                    );
                    break;

                default:
                    # strip any excess whitespace off of value
                    $Value = trim($Value);

                    # if child looks like valid method name
                    if (method_exists("Metavus\\PrivilegeSet", $Tag)) {
                        # convert constants if needed
                        if (defined($Value)) {
                            $Value = constant($Value);
                        # convert booleans if needed
                        } elseif (strtoupper($Value) == "TRUE") {
                            $Value = true;
                        } elseif (strtoupper($Value) == "FALSE") {
                            $Value = false;
                        # convert privilege flag names if needed and appropriate
                        } elseif (preg_match("/Privilege$/", $Tag)) {
                            static $Privileges;
                            if (!isset($Privileges)) {
                                $PFactory = new PrivilegeFactory();
                                $Privileges = $PFactory->getPrivileges(true, false);
                            }
                            if (in_array($Value, $Privileges)) {
                                $Value = array_search($Value, $Privileges);
                            }
                        }

                        # set value using child data
                        $PrivSet->$Tag((string)$Value);
                    } else {
                        # record error about bad tag
                        throw new Exception("Unknown tag encountered (".$Tag.").");
                    }
                    break;
            }
        }

        # return PrivSet to caller
        return $PrivSet;
    }

    /**
     * Get privilege set as XML.  The returned XML will not include document
     * begin or end tags and may not be ideally formmatted.
     * @return string XML data.
     */
    public function getAsXml(): string
    {
        # set up XML writer
        $XOut = new XMLWriter();
        $XOut->openMemory();
        $XOut->setIndent(true);
        $XOut->setIndentString("    ");

        # write out logic
        $XOut->writeElement("UsesAndLogic", $this->usesAndLogic() ? "TRUE" : "FALSE");

        # write out privileges
        $PFactory = new PrivilegeFactory();
        foreach ($this->Privileges as $Priv) {
            if (is_numeric($Priv)) {
                $PrivConst = $PFactory->getPrivilegeConstantName((int)$Priv);
                if ($PrivConst !== false) {
                    $XOut->writeElement("AddPrivilege", $PrivConst);
                }
            }
        }

        # write out conditions
        foreach ($this->Privileges as $Priv) {
            if (is_array($Priv)) {
                $XOut->startElement("AddCondition");
                $Field = new MetadataField($Priv["FieldId"]);
                $Operator = $Priv["Operator"];
                $Value = $Priv["Value"];
                $XOut->writeElement("Field", $Field->name());
                if ($Operator != "==") {
                    $XOut->writeElement("Operator", $Operator);
                }
                if ($Value !== null) {
                    $ReasonableMaxNumberOfValues = 10000;
                    $PossibleValues = $Field->getPossibleValues(
                        $ReasonableMaxNumberOfValues
                    );
                    if (isset($PossibleValues[$Value])) {
                        $XOut->writeElement("Value", $PossibleValues[$Value]);
                    } else {
                        $XOut->writeElement("Value", $Value);
                    }
                }
                $XOut->endElement();
            }
        }

        # write out any subsets
        foreach ($this->Privileges as $Priv) {
            if ($Priv instanceof self) {
                $XOut->startElement("AddSubset");
                $XOut->writeRaw("\n".$Priv->getAsXml());
                $XOut->endElement();
            }
        }

        # return generated XML to caller
        return $XOut->flush();
    }


    # ---- PRIVATE INTERFACE -------------------------------------------------

    private $RFactories = [];
    private $Privileges = [];
    private $Logic = "OR";
    private $ExpirationDate = false;

    private static $MetadataFieldCache;
    private static $ValueCache;

    const NO_RESOURCE = "XXX NO RESOURCE XXX";

    /**
     * Load privileges from serialized data.
     * @param string $Serialized Privilege data.
     * @throws InvalidArgumentException If data passed in was invalid.
     */
    private function loadFromData(string $Serialized)
    {
        # save calling context in case load causes out-of-memory crash
        (ApplicationFramework::getInstance())->RecordContextInCaseOfCrash();

        # unpack new data
        $Data = unserialize($Serialized);
        if ($Data === false) {
            throw new InvalidArgumentException(
                "Invalid serialized data supplied (\"".$Serialized."\")."
            );
        }

        # unpack privilege data (if available) and load
        if (array_key_exists("Privileges", $Data)) {
            $this->Privileges = [];
            foreach ($Data["Privileges"] as $Priv) {
                if (is_array($Priv) && array_key_exists("SUBSET", $Priv)) {
                    $Subset = new PrivilegeSet();
                    $Subset->loadFromData($Priv["SUBSET"]);
                    $this->Privileges[] = $Subset;
                } else {
                    $this->Privileges[] = $Priv;
                }
            }
        }

        # load logic if available
        if (array_key_exists("Logic", $Data)) {
            $this->Logic = $Data["Logic"];
        }
    }

    /**
     * Determine if a given user meets the requirements specified by this
     * PrivilegeSet without resetting the $ExpirationDate; this version should be
     * used for recursive and class internal calls.
     * @param User $User User object to use in comparisons.
     * @param Record|string $Resource Resource object to used for comparison, for
     *       sets that include user conditions.  (OPTIONAL)
     * @return bool TRUE if privileges in set are greater than or equal to
     *       privileges in specified set, otherwise FALSE.
     */
    private function meetsRequirementsRecursive(User $User, $Resource = self::NO_RESOURCE): bool
    {
        # when there are no requirements, then every user meets them
        $Satisfied = true;

        # for each privilege requirement
        foreach ($this->Privileges as $Priv) {
            # if privilege is actually a privilege subgroup
            if ($Priv instanceof self) {
                # check if the subgroup is satisfied
                $Satisfied = $Priv->meetsRequirementsRecursive($User, $Resource);
            # else if privilege is actually a condition
            } elseif (is_array($Priv)) {
                # check if condition is satisfied for the given resource
                $Satisfied = $this->meetsCondition($Priv, $Resource, $User);
            # else privilege is actually a privilege
            } else {
                # check if user has the specified privilege
                $Satisfied = $User->hasPriv($Priv);
            }

            # for AND logic, we can bail as soon as the first
            # condition is not met
            if ($this->Logic == "AND") {
                if (!$Satisfied) {
                    break;
                }
            # conversely, for OR logic, we can bail as soon as any condition is met
            } else {
                if ($Satisfied) {
                    break;
                }
            }
        }

        # report result of the test back to caller
        return $Satisfied;
    }

    /**
     * Check whether this privilege set meets the specified condition.
     * @param array $Condition Condition to check, with "FieldId", "Operator",
     *      and "Value" entries..
     * @param Record|string $Resource Resource to use when checking.
     * @param User $User User to use when checking.
     * @return bool TRUE if condition is met, otherwise FALSE.
     */
    private function meetsCondition(array $Condition, $Resource, User $User): bool
    {
        # make sure metadata field is loaded
        $MFieldId = $Condition["FieldId"];
        if (!isset(self::$MetadataFieldCache[$MFieldId])) {
            self::$MetadataFieldCache[$MFieldId] =
                    !MetadataSchema::fieldExistsInAnySchema($Condition["FieldId"])
                    ? false
                    : new MetadataField($MFieldId);
        }

        # if the specified field does not exist
        if (self::$MetadataFieldCache[$MFieldId] === false) {
            # return a result that in effect ignores the condition
            return ($this->Logic == "AND") ? true : false;
        }

        # pull out provided field
        $Field = self::$MetadataFieldCache[$MFieldId];
        $Operator = $Condition["Operator"];
        $Value = $Condition["Value"];

        # determine if the provided operator is valid for the provided field
        if (!in_array($Operator, $this->validOperatorsForFieldType($Field->Type()))) {
            throw new Exception("Operator ".$Operator." not supported for "
                    .$Field->typeAsName()." fields");
        }

        # if we don't have a specific resource to check, then we want
        # to determine if this condition would be satisfied by any
        # resource
        if ($Resource == self::NO_RESOURCE) {
            $Count = $this->countResourcesThatSatisfyCondition(
                $User,
                $Field,
                $Operator,
                $Value
            );
            return $Count > 0 ? true : false;
        # else if resource is valid
        } elseif ($Resource instanceof Record) {
            # if this field is from a different schema than our resource
            # and also this field is not from the User schema, then there's
            # no comparison for us to do
            if ($Field->schemaId() != $Resource->getSchemaId() &&
                $Field->schemaId() != MetadataSchema::SCHEMAID_USER) {
                # return a result that in effect ignores the condition
                return ($this->Logic == "AND") ? true : false;
            }

            # normalize the incoming value for comparison
            $Value = $this->normalizeTargetValue($Field->Type(), $User, $Value);
            $FieldValue = $this->getNormalizedFieldValue($Field, $Resource, $User);

            # if comparison involves a date/time type and a target value that
            # is relative to 'now'
            $DateTypes = [MetadataSchema::MDFTYPE_TIMESTAMP, MetadataSchema::MDFTYPE_DATE];
            if (in_array($Field->type(), $DateTypes) &&
                StdLib::isRelativeDateString($Condition["Value"])) {
                # determine the offset between field value and target value
                $Offset = ($FieldValue - $Value);

                # if values will be equal in the future
                if ($Offset >= 0) {
                    # get timestamp when that will happen
                    $ExpirationDate = strtotime("now +".$Offset." seconds");

                    # update our stored ExpirationDate if needed
                    $this->ExpirationDate = $this->ExpirationDate === false ?
                        $ExpirationDate :
                        min($this->ExpirationDate, $ExpirationDate);
                }
            }

            # perform comparison, returning result
            return $this->compareNormalizedFieldValues($FieldValue, $Operator, $Value);
        } else {
            # error out because resource was illegal
            throw new Exception("Invalid Resource passed in for privilege set comparison.");
        }
    }

    /**
     * Determine the valid condition operators for a given field type.
     * @param int $FieldType Field type (one of the
     *     MetadataSchema::MDFTYPE_ constants).
     * @return array of valid operators.
     */
    private function validOperatorsForFieldType(int $FieldType): array
    {
        switch ($FieldType) {
            case MetadataSchema::MDFTYPE_USER:
                $ValidOps = ["=="];
                break;

            case MetadataSchema::MDFTYPE_DATE:
            case MetadataSchema::MDFTYPE_TIMESTAMP:
            case MetadataSchema::MDFTYPE_NUMBER:
                $ValidOps = ["==", "!=", "<=", "<", ">=", ">"];
                break;

            case MetadataSchema::MDFTYPE_FLAG:
            case MetadataSchema::MDFTYPE_OPTION:
                $ValidOps = ["==", "!="];
                break;

            default:
                $ValidOps = [];
                break;
        }

        return $ValidOps;
    }

    /**
     * Normalize a target value from a privilege set condition for comparison.
     * @param int $FieldType Metadata field type being compared (as a
     *     MetadataSchema::MDFTYPE_ constant).
     * @param User $User User for whom the comparison is being performed.
     * @param mixed $Value Target value for the comparison.
     * @return mixed Normalized value
     */
    private function normalizeTargetValue(int $FieldType, $User, $Value)
    {
        switch ($FieldType) {
            case MetadataSchema::MDFTYPE_DATE:
            case MetadataSchema::MDFTYPE_TIMESTAMP:
                # "Now" is encoded as NULL for timestamp and date comparisons
                if ($Value === null) {
                    $Value = time();
                # otherwise, parse the value to get a numeric timestamp
                } else {
                    $Value = strtotime($Value);
                }
                break;

            case MetadataSchema::MDFTYPE_USER:
                # "Current user" is encoded as NULL for user comparisons
                if ($Value === null) {
                    $Value = $User->id();
                }
                break;

            default:
                # no normalization needed for other field types
                break;
        }

        return $Value;
    }

    /**
     * Get a normalized field value from a resource for comparisons.
     * If the provided field is from the User schema, but the resource
     * is not, then the value will be taken from the User doing the
     * comparison rather than the resource (so that conditions like
     * User: ZIp Code = XXX work as expected).
     * @param MetadataField $Field Field to pull values from.
     * @param Record $Resource Resource to pull values from.
     * @param User $User User performing the comparison.
     * @return mixed Normalized value.  For User and Option fields,
     * this will be an array of Ids.  For Date and Timestamp fields, it
     * will be a UNIX timestamp.  For Number and Flag fields, it will
     * be the literal value stored in the database.
     */
    private function getNormalizedFieldValue(
        MetadataField $Field,
        Record $Resource,
        User $User
    ) {
        # if we have a cached normalized value for this field,
        # use that for comparisons
        $CacheKey = $Resource->id()."_".$Field->id();
        if (!isset(self::$ValueCache[$CacheKey])) {
            # if the given field comes from the User schema and our
            # resource does not, evaluate this comparison against the
            # provided $User rather than the provided $Resource
            # (this allows conditions like User: Zip Code = XXX to
            # work as expected rather than being skipped)
            if ($Field->schemaId() != $Resource->getSchemaId() &&
                $Field->schemaId() == MetadataSchema::SCHEMAID_USER) {
                $FieldValue = $User->get($Field->name());
            } else {
                # Note: Resource::Get() on a ControlledName with
                # IncludeVariants=TRUE does not return CNIds for
                # array indexes, which will break the normalization
                # below, so do not change this to add $IncludeVariants
                # without revising the normalization code below
                $FieldValue = $Resource->get($Field);
            }

            # normalize field value for comparison
            switch ($Field->type()) {
                case MetadataSchema::MDFTYPE_USER:
                case MetadataSchema::MDFTYPE_OPTION:
                    # get the UserIds or CNIds from this field
                    $FieldValue = array_keys($FieldValue);
                    break;

                case MetadataSchema::MDFTYPE_DATE:
                case MetadataSchema::MDFTYPE_TIMESTAMP:
                    # convert returned value to a numeric timestamp
                    $FieldValue = strtotime((string)$FieldValue);
                    break;

                case MetadataSchema::MDFTYPE_NUMBER:
                case MetadataSchema::MDFTYPE_FLAG:
                    # no conversion needed
                    break;

                default:
                    throw new Exception("Unsupported metadata field type ("
                            .print_r($Field->type(), true)
                            .") for condition in privilege set with resource.");
            }

            # cache the normalized value for subsequent reuse
            self::$ValueCache[$CacheKey] = $FieldValue;
        }

        return self::$ValueCache[$CacheKey];
    }

    /**
     * Compare normalized field and target values with a specified operator.
     * @param mixed $FieldValue Value from the subject resource.
     * @param string $Operator Operator for the comparison.
     * @param mixed $Value Target value of the comparison.
     * @return bool TRUE if $FieldValue $Operator $Value is satisfied, FALSE
     *     otherwise.
     */
    private function compareNormalizedFieldValues(
        $FieldValue,
        string $Operator,
        $Value
    ): bool {
        # compare field value and supplied value using specified operator

        # if this is a multi-value field, be sure that the provided
        # operator makes sense
        if (is_array($FieldValue) && !in_array($Operator, ["==", "!="])) {
            throw new Exception(
                "Multiple-value fields ony support == and != operators"
            );
        }

        switch ($Operator) {
            case "==":
                if (is_array($FieldValue)) {
                    # equality against multi-value fields is
                    # interpreted as 'contains', true if the
                    # target value is one of those set
                    $Result = in_array($Value, $FieldValue);
                } else {
                    $Result = ($FieldValue == $Value);
                }
                break;

            case "!=":
                if (is_array($FieldValue)) {
                    # not equal against multi-value fields is
                    # interpreted as 'does not contain', true as long as
                    # the target value is not one of those set
                    $Result = !in_array($Value, $FieldValue);
                } else {
                    $Result = ($FieldValue != $Value);
                }
                break;

            case "<":
                $Result = ($FieldValue < $Value);
                break;

            case ">":
                $Result = ($FieldValue > $Value);
                break;

            case "<=":
                $Result = ($FieldValue <= $Value);
                break;

            case ">=":
                $Result = ($FieldValue >= $Value);
                break;

            default:
                throw new Exception("Unsupported condition operator ("
                                    .print_r($Operator, true).") in privilege set.");
        }

        # report to caller whether condition was met
        return $Result ? true : false;
    }

    /**
     * Determine the number of resources in the collection that satisfy
     * a condition.
     * @param User $User User performing the comparisons.
     * @param MetadataField $Field Field being used in the comaprisons.
     * @param string $Operator Operator for comparisons.
     * @param mixed $Value Target value for comparisons.
     * @return int Number of resources where Resource->Get($Field)
     *      $Operator $Value is TRUE.
     */
    private function countResourcesThatSatisfyCondition(
        User $User,
        MetadataField $Field,
        string $Operator,
        $Value
    ): int {

        # get the SchemaId for this field
        $ScId = $Field->schemaId();

        # pull out an RFactory for the field's schema
        if (!isset($this->RFactories[$ScId])) {
            $this->RFactories[$ScId] = new RecordFactory($ScId);
        }

        $Value = $this->normalizeTargetValue($Field->type(), $User, $Value);

        switch ($Field->type()) {
            case MetadataSchema::MDFTYPE_USER:
                # if normalized user value is null because no user is logged in
                # then count will be zero because this will never match
                # (User fields only support == comparisons)
                if (is_null($Value)) {
                    $Count = 0;
                    break;
                }
                // otherwise, fall through

            case MetadataSchema::MDFTYPE_DATE:
            case MetadataSchema::MDFTYPE_TIMESTAMP:
            case MetadataSchema::MDFTYPE_NUMBER:
            case MetadataSchema::MDFTYPE_FLAG:
                $ValuesToMatch = [$Field->id() => $Value,];

                $Count = $this->RFactories[$ScId]->getCountOfMatchingRecords(
                    $ValuesToMatch,
                    true,
                    $Operator
                );
                break;

            case MetadataSchema::MDFTYPE_OPTION:
                # find the number of resources associated with this option
                $Count = $this->RFactories[$ScId]->associatedVisibleRecordCount(
                    $Value,
                    $User,
                    true
                );

                # if our Op was !=, then subtract the resources
                # that have the spec'd option out of the total to
                # figure out how many lack the option
                if ($Operator == "!=") {
                    $Count = $this->RFactories[$ScId]->getVisibleRecordCount(
                        $User
                    ) - $Count;
                }

                break;

            default:
                throw new Exception("Unsupported metadata field type ("
                        .print_r($Field->type(), true)
                        .") for condition in privilege set without resource.");
        }

        return $Count;
    }

    /**
     * Check whether specified item (privilege, condition, or subgroup) is
     * currently in the list of privileges.  This is necessary instead of just
     * using in_array() because in_array() generates NOTICE messages if the
     * array contains mixed types.
     * @param mixed $Item Item to look for.
     * @return bool TRUE if item found in privilege list, otherwise FALSE.
     */
    private function isInPrivilegeData($Item): bool
    {
        # step through privilege data
        foreach ($this->Privileges as $Priv) {
            # report to caller if item is found
            if (is_object($Item)) {
                if (is_object($Priv) && ($Item == $Priv)) {
                    return true;
                }
            } elseif (is_array($Item)) {
                if (is_array($Priv) && ($Item == $Priv)) {
                    return true;
                }
            } elseif ($Item == $Priv) {
                return true;
            }
        }

        # report to caller that item is not in privilege data
        return false;
    }
}
