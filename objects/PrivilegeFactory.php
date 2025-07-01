<?PHP
#
#   FILE:  PrivilegeFactory.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2007-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus;
use ScoutLib\ItemFactory;

/**
 * Factory which extracts all defined privileges from the database
 * \nosubgrouping
 */
class PrivilegeFactory extends ItemFactory
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /** @name Setup/Initialization */
    /*@{*/

    /** Object constructor */
    public function __construct()
    {
        parent::__construct("Metavus\\Privilege", "CustomPrivileges", "Id", "Name");

        $AllConstants = get_defined_constants(true);
        $UserConstants = $AllConstants["user"];

        foreach ($UserConstants as $Name => $Value) {
            if (strpos($Name, "PRIV_") === 0) {
                $this->PrivilegeConstants[$Value] = $Name;
            }
        }
    }

    /*@}*/

    /** @name Accessors */
    /*@{*/

    /**
     * Get all privileges
     * @param bool $IncludePredefined Whether to include predefined
     *       privileges.  (OPTIONAL, defaults to TRUE)
     * @param bool $ReturnObjects Whether to return Privilege objects, rather
     *       than privilege names.  (OPTIONAL, defaults to TRUE)
     * @return array An array of privilege objects or strings with
     *       priv IDs for the index.
     */
    public function getPrivileges(
        bool $IncludePredefined = true,
        bool $ReturnObjects = true
    ): array {
        # if caller wants predefined privileges included
        if ($IncludePredefined) {
            # get complete list of privilege names
            $PrivNames = $this->getItemNames();
        } else {
            # read in only custom privileges from DB
            $PrivNames = parent::getItemNames();
        }

        # if caller requested objects to be returned
        if ($ReturnObjects) {
            $PrivObjects = [];

            # convert strings to objects and return to caller
            foreach ($PrivNames as $Id => $Name) {
                $PrivObjects[$Id] = new Privilege($Id);
            }

            return $PrivObjects;
        } else {
            # return strings to caller
            return $PrivNames;
        }
    }

    /**
     * Get the Privilege object with the given name.
     * @param string $Name Privilege name.
     * @return Privilege|null Privilege or NULL if one doesn't exist with the name.
     */
    public function getPrivilegeWithName(string $Name)
    {
        # predefined privilege constant name
        if (in_array($Name, $this->PrivilegeConstants)) {
            $Id = array_search($Name, $this->PrivilegeConstants);
            if (is_numeric($Id)) {
                return new Privilege((int)$Id);
            }
        }

        # predefined privilege constant description
        if (in_array($Name, Privilege::STD_PRIV_DESCRIPTIONS)) {
            $ConstantName = array_search($Name, Privilege::STD_PRIV_DESCRIPTIONS);
            $Id = array_search($ConstantName, $this->PrivilegeConstants);
            if (is_numeric($Id)) {
                return new Privilege((int)$Id);
            }
        }

        # custom privilege name
        $CustomPrivileges = $this->getPrivileges(false, false);
        foreach ($CustomPrivileges as $Id => $PrivilegeName) {
            if ($Name == $PrivilegeName) {
                return new Privilege($Id);
            }
        }

        return null;
    }

    /**
     * Get the Privilege object with the given value.
     * @param int $Value Privilege value.
     * @return object|null A Privilege object or NULL if one doesn't exist with the value.
     */
    public function getPrivilegeWithValue(int $Value)
    {
        # predefined privilege constant name
        if (array_key_exists($Value, $this->PrivilegeConstants)) {
            $Privilege = new Privilege($Value);

            return $Privilege;
        }

        $CustomPrivileges = $this->getPrivileges(false, false);

        # custom privilege name
        foreach ($CustomPrivileges as $Id => $PrivilegeName) {
            if ($Value == $Id) {
                $Privilege = new Privilege($Id);

                return $Privilege;
            }
        }

        return null;
    }

    /**
     * Get all predefined privilege constants and their values.
     * @return array An array with the privilege IDs for the index.
     */
    public function getPredefinedPrivilegeConstants(): array
    {
        return $this->PrivilegeConstants;
    }

    /**
     * Retrieve human-readable privilege names.  This method overloads
     * the inherited version from ItemFactory to add in the predefined
     * privileges. This will also filter out privs outside the
     * recognized ranges for Standard/Custom/Pseudo privileges defined
     * in User.
     * @param string $SqlCondition SQL condition (w/o "WHERE")
     *      for name retrieval. (OPTIONAL)
     * @param int $Limit Number of results to retrieve. (OPTIONAL)
     * @param int $Offset Beginning offset into results.  (OPTIONAL, defaults
     *       to 0, which is the first element)
     * @param array $Exclusions ItemIds to exclude from results. (OPTIONAL)
     * @return array Array with item names as values and item IDs as indexes
     */
    public function getItemNames(
        ?string $SqlCondition = null,
        ?int $Limit = null,
        int $Offset = 0,
        array $Exclusions = []
    ): array {
        $Names = parent::getItemNames($SqlCondition, $Limit, $Offset, $Exclusions);
        $Names = $Names + Privilege::STD_PRIV_DESCRIPTIONS;

        # divide into Standard, Custom, and Pseudo sections, list
        # alphabetically within each section
        $TmpNames = [];
        foreach (["Standard", "Custom", "Pseudo"] as $PType) {
            $TestFn = "is".$PType."Privilege";
            $Section = [];
            foreach ($Names as $Id => $Name) {
                if (User::$TestFn($Id)) {
                    $Section[$Id] = $Name;
                }
            }

            asort($Section);

            # append elements from $Section to $TmpNames
            # (see http://php.net/manual/en/language.operators.array.php)
            $TmpNames += $Section;
        }

        $Names = $TmpNames;

        return $Names;
    }

    /*@}*/

    /** @name Predicates */
    /*@{*/

    /**
     * Determine if a privilege with the given name exists.
     * @param string $Name Privilege name.
     * @return bool TRUE if a privilege with the given name exists.
     */
    public function privilegeNameExists(string $Name): bool
    {
        # predefined privilege constant name
        if (in_array($Name, $this->PrivilegeConstants)) {
            return true;
        }

        # predefined privilege constant description
        if (in_array($Name, Privilege::STD_PRIV_DESCRIPTIONS)) {
            return true;
        }

        $CustomPrivileges = $this->getPrivileges(false, false);

        # custom privilege name
        if (in_array($Name, $CustomPrivileges)) {
            return true;
        }

        return false;
    }

    /**
     * Determine if a privilege with the given value exists.
     * @param int $Value Privilege value.
     * @return bool TRUE if a privilege with the value exists, otherwise FALSE.
     */
    public function privilegeValueExists(int $Value): bool
    {
        # predefined privilege constant name
        if (array_key_exists($Value, $this->PrivilegeConstants)) {
            return true;
        }

        $CustomPrivileges = $this->getPrivileges(false);

        foreach ($CustomPrivileges as $Privilege) {
            if ($Value == $Privilege->id()) {
                return true;
            }
        }

        return false;
    }

    /*@}*/

    /**
     * Convert a given array of strings into privilege constants
     * @param array $Privs array of strings representing privilege constants
     * @return array|bool array of privilege constants or false when
     *  invalid privilege is included in array
     */
    public static function normalizePrivileges(array $Privs)
    {
        $Privileges = [];
        $PFactory = new PrivilegeFactory();
        foreach ($Privs as $Priv) {
            unset($PrivilegeId);

            $Priv = strtoupper(trim($Priv));

            if ((strpos($Priv, "PRIV_") === 0) && defined($Priv)) {
                $PrivilegeId = constant($Priv);
            } elseif (defined("PRIV_".$Priv)) {
                $PrivilegeId = constant("PRIV_".$Priv);
            } elseif (defined("PRIV_".$Priv."ADMIN")) {
                $PrivilegeId = constant("PRIV_".$Priv."ADMIN");
            } elseif (is_numeric($Priv)
                    && ((User::isStandardPrivilege((int)$Priv))
                            || $PFactory->itemExists((int)$Priv))) {
                $PrivilegeId = $Priv;
            }

            if (isset($PrivilegeId)) {
                $Privileges[] = $PrivilegeId;
            } else {
                return false;
            }
        }
        return $Privileges;
    }

    /**
     * Get a list of privileges, excluding pseudo-privileges
     * @return array privilege labels keyed on privilege ID
     */
    public function getPrivilegeOptions()
    {
        $PrivilegeOptions = [];
        $Privileges = $this->getPrivileges(true, false);
        foreach ($Privileges as $Id => $Label) {
            if ($Id == PRIV_USERDISABLED || User::isPseudoPrivilege($Id)) {
                continue;
            }
            $PrivilegeOptions[$Id] = $Label;
        }

        return $PrivilegeOptions;
    }

    /**
     * Get privilege constant name for specified value.
     * @param int $PrivValue Privilege value.
     * @return string|false Privilege constant name or FALSE if no matching
     *      privilege constant.
     */
    public function getPrivilegeConstantName(int $PrivValue)
    {
        return $this->PrivilegeConstants[$PrivValue] ?? false;
    }


    # ---- PRIVATE INTERFACE -------------------------------------------------

    private $PrivilegeConstants = [];
}
