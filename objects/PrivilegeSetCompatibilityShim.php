<?PHP
#
#   FILE:  PrivilegeSetCompatibilityShim.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2014-2021 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus;

/**
* Compatibility layer allowing interfaces built against the privilege
* system from CWIS 3.0.0 through 3.1.0 to continue working.  This
* should not be used in new code.
*/
class PrivilegeSetCompatibilityShim
{
    /**
    * Class constructor.
    * @param User $User to check privileges for.
    */
    public function __construct(User $User)
    {
        $this->User = $User;
    }

    /**
    * Check whether usser meets privilege requirements.
    * @param PrivilegeSet $Set Privilege set to check against.
    * @param mixed $Resource Resource to user in privilege check.
    * @return bool If TRUE, user meets requirements for set.
    */
    public function isGreaterThan(PrivilegeSet $Set, $Resource = PrivilegeSet::NO_RESOURCE): bool
    {
        return $Set->meetsRequirements($this->User, $Resource);
    }

    # ---- PRIVATE INTERFACE -------------------------------------------------

    private $User;
}
