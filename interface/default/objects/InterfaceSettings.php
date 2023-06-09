<?PHP
#
#   FILE:  InterfaceSettings.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2023 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus;

/**
 * Base class for interface setting definitions.
 */
abstract class InterfaceSettings
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Retrieve setting definitions for this interface, in a form suitable
     * for use with the Metavus\Configuration class.
     * @return array Setting definitions.
     */
    public function getSettingDefinitions(): array
    {
        return $this->SettingDefinitions;
    }


    # ---- PRIVATE INTERFACE -------------------------------------------------

    protected $SettingDefinitions;
}
