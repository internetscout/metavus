<?PHP
#
#   FILE:  BrowserCapabilities
#
#   A plugin for the Metavus digital collections platform
#   Copyright 2012-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins;
use Metavus\Plugin;
use ScoutLib\ApplicationFramework;
use ScoutLib\StdLib;

/**
 * Plugin to wrap PHP's get_browser() function.
 */
class BrowserCapabilities extends Plugin
{
    /**
     * Register information about this plugin.
     * @return void
     */
    public function register(): void
    {
        $this->Name = "Browser Capabilities";
        $this->Version = "1.1.0";
        $this->Description = "Provides information about "
             ."the user's browser and its capabilities.";
        $this->Author = "Internet Scout Research Group";
        $this->Url = "https://metavus.net";
        $this->Email = "support@metavus.net";
        $this->Requires = ["MetavusCore" => "1.2.0"];
        $this->EnabledByDefault = true;

        $this->CfgSetup["EnableDeveloper"] = [
            "Type" => "Flag",
            "Label" => "Enable Developer Interface",
            "Help" => "Enable an additional developer interface to aid in debugging.",
            "Default" => false,
            "OnLabel" => "Yes",
            "OffLabel" => "No"
        ];

        $this->addAdminMenuEntry(
            "Developer",
            "Developer Support",
            [ PRIV_SYSADMIN ]
        );
    }

    /**
     * Declare the events this plugin provides to the application framework.
     * @return array Events this plugin provides.
     */
    public function declareEvents(): array
    {
        return [
            "BROWSCAP_GET_BROWSER" => ApplicationFramework::EVENTTYPE_FIRST,
            "BROWSCAP_BROWSER_CHECK" => ApplicationFramework::EVENTTYPE_FIRST
        ];
    }

    /**
     * Return event hooks to the application framework.
     * @return array Events to be hooked into the application framework.
     */
    public function hookEvents(): array
    {
        $Events = [
            "BROWSCAP_GET_BROWSER" => "GetBrowser",
            "BROWSCAP_BROWSER_CHECK" => "BrowserCheck"
        ];
        return $Events;
    }

    /**
     * Inject a callback into the application framework.
     */
    public function initialize(): ?string
    {
        ApplicationFramework::getInstance()->setBrowserDetectionFunc([
            $this,
            "BrowserDetectionFunc"
        ]);
        return null;
    }

    /**
     * Inject browser names into the application framework.
     * @param string $UserAgent Custom user agent string to use
     * @return array Browser names.
     */
    public function browserDetectionFunc($UserAgent = null): array
    {
        $Browsers = [];
        $Capabilities = $this->getBrowser($UserAgent);

        # add browser name
        if (isset($Capabilities["browser"])) {
            $Name = $Capabilities["browser"];
            $Browsers[] = $Name;

            # add version-specific name too
            if (isset($Capabilities["majorver"])) {
                $VersionedName = $Name . $Capabilities["majorver"];
                $Browsers[] = $VersionedName;
            }
        }

        return $Browsers;
    }

    /**
     * Get the user agent's name only.
     * @param string|null $UserAgent Custom user agent string to use
     * @return string|null String giving browser name, or NULL on failure.
     */
    public function getBrowserName($UserAgent = null): ?string
    {
        $Capabilities = $this->getBrowser($UserAgent);

        return isset($Capabilities["browser"]) ? $Capabilities["browser"] : null;
    }

    /**
     * Get the user agent's capabilities. Updates the cache after 30 days.
     * @param string|null $UserAgent Custom user agent string to use
     * @return array Capabilities.
     */
    public function getBrowser($UserAgent = null): array
    {
        if (strlen((string)ini_get('browscap')) == 0) {
            if (is_null($UserAgent)) {
                $UserAgent = isset($_SERVER["HTTP_USER_AGENT"]) ?
                           $_SERVER["HTTP_USER_AGENT"] : "";
            }

            if (strpos($UserAgent, 'Opera') || strpos($UserAgent, 'OPR/')) {
                $Name = 'Opera';
            } elseif (strpos($UserAgent, 'Edge')) {
                $Name = 'Edge';
            } elseif (strpos($UserAgent, 'Chrome')) {
                $Name = "Chrome";
            } elseif (strpos($UserAgent, 'Safari')) {
                $Name = "Safari";
            } elseif (strpos($UserAgent, 'Firefox')) {
                $Name = "Firefox";
            } elseif (strpos($UserAgent, 'MSIE') || strpos($UserAgent, 'Trident/7')) {
                $Name = 'Internet Explorer';
            } else {
                $Name = 'Other';
            }

            return ["browser" => $Name];
        } else {
            return (array) get_browser($UserAgent, true);
        }
    }

    /**
     * Check if the given constraints are true for the user agent.
     * @param array $Constraints Constraints to test against.
     *    For example ["Browser" => "Firefox"].
     * @param string $UserAgent UserAgent to use.
     * @return bool TRUE if all constraints are satisfied, FALSE otherwise.
     */
    public function browserCheck(array $Constraints, $UserAgent = null): bool
    {
        static $CapabilityMap;

        if (!is_array($CapabilityMap) || !array_key_exists($UserAgent, $CapabilityMap)) {
            $CapabilityMap[$UserAgent] = $this->getBrowser($UserAgent);
        }

        $Capabilities = $CapabilityMap[$UserAgent];

        if (count($Capabilities) == 0) {
            return false;
        }

        foreach ($Constraints as $Key => $Value) {
            if (!isset($Capabilities[$Key]) || $Capabilities[$Key] != $Value) {
                return false;
            }
        }

        return true;
    }
}
