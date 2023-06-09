<?PHP
#
#   FILE:  WebAnalyticsPlugin.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2021 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus;

use ScoutLib\ApplicationFramework;

/**
 * This class extends the Metavus Plugin class with functionality needed by
 * the various web analytics plugins.
 */
abstract class WebAnalyticsPlugin extends Plugin
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Get plugin setting for the current domain, falling back to a default value
     * if no domain-specific version is available.
     * @param string $SettingName Setting to retrieve.
     * @return mixed Requested value.
     */
    final public function getSettingForCurrentDomain(string $SettingName)
    {
        $Domain = ApplicationFramework::getCurrentDomain();

        return strlen($this->configSetting($SettingName."_".$Domain) ?? "") ?
            $this->configSetting($SettingName."_".$Domain) :
            $this->configSetting($SettingName);
    }
}
