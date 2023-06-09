<?PHP
#
#   FILE:  Privilege.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2007-2020 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus;

use ScoutLib\Database;

/**
 * User rights management framework allowing custom privege definition
 * \nosubgrouping
 */
class Privilege
{

    # ---- PUBLIC INTERFACE --------------------------------------------------

    /** @name Constants */
    /*@{*/

    # (these values must correspond to PRIV_ definitions)
    const STD_PRIV_DESCRIPTIONS = [
        1  => "System Administrator",           # PRIV_SYSADMIN
        2  => "News Administrator",             # PRIV_NEWSADMIN
        3  => "Master Resource Administrator",  # PRIV_RESOURCEADMIN
        5  => "Classification Administrator",   # PRIV_CLASSADMIN
        6  => "Controlled Name Administrator",  # PRIV_NAMEADMIN
        7  => "Release Flag Administrator",     # PRIV_RELEASEADMIN
        8  => "User Account Administrator",     # PRIV_USERADMIN
        13 => "Collection Administrator",       # PRIV_COLLECTIONADMIN

        # following are user permissions, not admin privileges
        10 => "Can Post Resource Comments",     # PRIV_POSTCOMMENTS
        11 => "User Account Disabled",          # PRIV_USERDISABLED

        # following are pseudo-privileges, not admin privileges
        75 => "Is Logged In",                   # PRIV_ISLOGGEDIN

        # deprecated privileges maintained (for now) for backward compatibility
        4  => "Forum Administrator (Deprecated)",
        9  => "Can Post To Forums (Deprecated)",
        12 => "Personal Resource Administrator (Deprecated)",
    ];

    /*@}*/

    /** @name Setup/Initialization/Destruction */
    /*@{*/

    /**
     * Object Constructor
     * Pass in a value for the name and a NULL id to make a new privilege
     * @param int|null $Id Privilege ID number or NULL to create new entry.
     * @param string $Name Privilege name
     */
    public function __construct($Id, string $Name = null)
    {
        # if caller requested creation of new entry
        if ($Id === null) {
            # get highest current ID
            $DB = new Database();
            $HighestId = (int)$DB->queryValue("SELECT Id FROM CustomPrivileges"
                    ." ORDER BY Id DESC LIMIT 1", "Id");

            # select new ID
            $this->Id = max(100, ($HighestId + 1));

            # add new entry to database
            $DB->query("INSERT INTO CustomPrivileges (Id, Name)"
                    ." VALUES (".$this->Id.", '".addslashes($Name)."')");
            $this->Name = $Name;
        } else {
            # save ID
            $this->Id = intval($Id);

            # if ID indicates predefined privilege
            if ($this->isPredefined()) {
                # load privilege info from predefined priv array
                $this->Name = self::STD_PRIV_DESCRIPTIONS[$this->Id] ??
                    "Unidentified Privilege (Id=".$this->Id.")";
            } else {
                # load privilege info from database
                $DB = new Database();
                $this->Name = $DB->queryValue("SELECT Name FROM CustomPrivileges"
                        ." WHERE Id = ".$this->Id, "Name");
            }
        }
    }

    /**
     * Delete this privelege from the DB
     * NOTE: the object should not be used after calling this
     */
    public function delete()
    {
        if (!$this->isPredefined()) {
            $DB = new Database();
            $DB->query("DELETE FROM CustomPrivileges"." WHERE Id = ".$this->Id);
        }
    }

    /*@}*/

    /** @name Accessors */
    /*@{*/

    /**
     * Get ID.
     * @return int ID
     */
    public function id(): int
    {
        return $this->Id;
    }

    /**
     * Get or set Name
     * @param string $NewValue New value (OPTIONAL)
     * @return string Current setting of the name
     */
    public function name(string $NewValue = null): string
    {
        if (($NewValue !== null) && !$this->isPredefined()) {
            $DB = new Database();
            $DB->query("UPDATE CustomPrivileges"
                    ." SET Name = '".addslashes($NewValue)."'"
                    ." WHERE Id = ".$this->Id);
            $this->Name = $NewValue;
        }
        return $this->Name;
    }

    /**
     * Report whether privilege is predefined or custom
     * Can be called as Privilege::IsPredefind(ID)
     * @param int $Id Privilege ID (OPTIONAL)
     * @return bool TRUE for predefined values, FALSE otherwise
     */
    public function isPredefined(int $Id = null): bool
    {
        if ($Id === null) {
            $Id = $this->Id;
        }
        return (($Id > 0) && ($Id < 100)) ? true : false;
    }

    /*@}*/

    # ---- PRIVATE INTERFACE -------------------------------------------------

    private $Id;
    private $Name;
}
