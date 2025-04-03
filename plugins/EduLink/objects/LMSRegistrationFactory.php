<?PHP
#
#   FILE:  RegistrationFactory.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2023 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins\EduLink;

use ScoutLib\ItemFactory;

/**
 * Factory for Registration objects that store information about LMSes
 * configured to talk to us.
 */
class LMSRegistrationFactory extends ItemFactory
{
    /**
     * Constructor.
     */
    public function __construct()
    {
        parent::__construct(
            "Metavus\\Plugins\\EduLink\\LMSRegistration",
            "EduLink_Registrations",
            "Id"
        );
    }
}
