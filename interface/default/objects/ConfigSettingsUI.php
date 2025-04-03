<?PHP
#
#   FILE:  ConfigSettingsUI.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2014-2024 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus;

/**
* Class supplying a standard user interface for viewing and setting
* configuration parameters.
*/
class ConfigSettingsUI extends FormUI
{

    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
    * Display HTML table with settings parameters.
    * @param string $TableId CSS ID for table element.  (OPTIONAL)
    * @param string $TableStyle CSS styles for table element.  (OPTIONAL)
    */
    public function displaySettingsTable($TableId = null, $TableStyle = null): void
    {
        $this->displayFormTable($TableId, $TableStyle);
    }

    /**
    * Retrieve values set by form.
    * @return array Array of configuration settings, with setting names
    *       for the index, and new setting values for the values.
    */
    public function getNewSettingsFromForm(): array
    {
        return $this->getNewValuesFromForm();
    }


    # ---- PRIVATE INTERFACE -------------------------------------------------
}
