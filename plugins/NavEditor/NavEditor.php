<?PHP
#
#   FILE:  NavEditor.php  (NavEditor plugin)
#
#   A plugin for the Metavus digital collections platform
#   Copyright 2016-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins;
use Metavus\Plugin;
use Metavus\Plugins\NavEditor\Link;
use Metavus\PrivilegeFactory;
use Metavus\User;
use ScoutLib\ApplicationFramework;

/**
 * Plugin for dynamically modifying the navigation in the standard interface.
 */
class NavEditor extends Plugin
{
    /**
     * Register information about this plugin.
     * @return void
     */
    public function register(): void
    {
        $this->Name = "Navigation Editor";
        $this->Version = "1.1.0";
        $this->Description = "Editor for the primary navigation.";
        $this->Author = "Internet Scout Research Group";
        $this->Url = "https://metavus.net";
        $this->Email = "support@metavus.net";
        $this->Requires = ["MetavusCore" => "1.2.0"];
        $this->EnabledByDefault = true;

        $this->addAdminMenuEntry(
            "EditNavigation",
            "Edit Navigation",
            [ PRIV_SYSADMIN ]
        );
    }

    /**
     * Register default configuration settings.
     * @return NULL on success, error message on error
     */
    public function install(): ?string
    {
        $this->setConfigSetting(
            "PrimaryNav",
            "Home=home\n"
            ."Browse=browse\n"
            ."About=about\n"
        );
        $this->setConfigSetting("ModifyPrimaryNav", false);

        return null;
    }

    /**
     * Declare the events this plugin provides to the application framework.
     * @return array an array of the events this plugin provides
     */
    public function declareEvents(): array
    {
        return [
            "NAVEDITOR_GET_CONFIGURATION" => ApplicationFramework::EVENTTYPE_FIRST,
            "NAVEDITOR_SET_CONFIGURATION" => ApplicationFramework::EVENTTYPE_DEFAULT
        ];
    }

    /**
     * Return event hooks to the application framework.
     * @return array an array of events to be hooked into the application framework
     */
    public function hookEvents(): array
    {
        return [
            "EVENT_MODIFY_PRIMARY_NAV" => "ModifyPrimaryNav",
            "NAVEDITOR_GET_CONFIGURATION" => "GetConfiguration",
            "NAVEDITOR_SET_CONFIGURATION" => "SetConfiguration"
        ];
    }

    /**
     * Get configuration values.
     * @return array an array of configuration values
     */
    public function getConfiguration(): array
    {
        $ModifyPrimaryNav = $this->getConfigSetting("ModifyPrimaryNav");
        $PrimaryNav = $this->getConfigSetting("PrimaryNav");

        $Configuration = [
            "ModifyPrimaryNav" => $ModifyPrimaryNav,
            "PrimaryNav" => $PrimaryNav,
        ];

        return $Configuration;
    }

    /**
     * Set a configuration value if it is valid.
     * @param string $Key Configuration key.
     * @param mixed $Value New configuration value.
     * @return void
     */
    public function setConfiguration($Key, $Value): void
    {
        if ($Key == "ModifyPrimaryNav") {
            $SaneValue = (bool)$Value;
            $this->setConfigSetting($Key, $SaneValue);
        } elseif ($Key == "PrimaryNav") {
            $this->setConfigSetting($Key, $Value);
        }
    }

    /**
     * Potentially modify the primary nav items.
     * @param array $NavItems Current primary nav items.
     * @return array The (potentially) updated primary nav items.
     */
    public function modifyPrimaryNav(array $NavItems): array
    {
        $ModifyPrimaryNav = $this->getConfigSetting("ModifyPrimaryNav");
        $PrimaryNav = $this->getConfigSetting("PrimaryNav");
        $OriginalParameters = ["NavItems" => $NavItems];

        if (!$ModifyPrimaryNav) {
            return $OriginalParameters;
        }

        $Links = $this->getLinks($PrimaryNav);

        if (is_null($Links) || count($Links) < 1) {
            return $OriginalParameters;
        }

        $NewNavItems = $this->getNavItems($Links);
        $NewParameters = ["NavItems" => $NewNavItems];

        return $NewParameters;
    }

    /**
     * Transform a CSV string to an array of Links.
     * @param string $Csv CSV string.
     * @return array|null Array of Links or NULL if there is an error.
     */
    private function getLinks($Csv): ?array
    {
        $Records = $this->parseCsv($Csv);

        if (is_null($Records)) {
            return null;
        }

        $Links = $this->transformCsv($Records);

        return $Links;
    }

    /**
     * Transform an array of Links into an array of nav items, where
     * the key is the label and the value is the page.Does not include links
     * that require the user to be logged in when the user isn't or links that
     * require some privileges the current user doesn't have.
     * @param array $Links An array of Links.
     * @return array An array of pages, with the label as the key.
     */
    private function getNavItems(array $Links): array
    {
        # retrieve user currently logged in
        $User = User::getCurrentUser();
        $NavItems = [];

        foreach ($Links as $Link) {
            $Label = $Link->Label;
            $Page = $Link->Page;
            $DisplayOnlyIfLoggedIn = $Link->DisplayOnlyIfLoggedIn;
            $RequiredPrivileges = $Link->RequiredPrivileges;

            # the user needs to be logged in and isn't
            if ($DisplayOnlyIfLoggedIn && !$User->isLoggedIn()) {
                continue;
            }

            # user doesn't have the necessary privileges
            if (!is_null($RequiredPrivileges)) {
                # an user that isn't logged in won't have the necessary privs
                if (!$User->isLoggedIn()) {
                    continue;
                }

                $HasPrivs = $User->hasPriv($RequiredPrivileges);

                # user doesn't have the required privileges
                if (!$HasPrivs) {
                    continue;
                }
            }

            $NavItems[$Label] = $Page;
        }

        return $NavItems;
    }

    /**
     * Parse the given CSV string into an array.
     * @param string $Csv CSV string.
     * @return array|null Array of CSV records or NULL on error.
     */
    private function parseCsv($Csv): ?array
    {
        # since str_parsecsv() and php://memory aren't always available, a temp
        # file containing the CSV needs to be created so that fgetcsv() can be
        # used instead

        $Handle = @tmpfile();

        if ($Handle === false) {
            return null;
        }

        $Result = @fwrite($Handle, $Csv);

        if ($Result === false) {
            @fclose($Handle);
            return null;
        }

        $Result = @fseek($Handle, 0);

        if ($Result !== 0) {
            @fclose($Handle);
            return null;
        }

        $Records = [];

        while (false !== ($Record = fgetcsv($Handle, 0, "=", "\"", "\\"))) {
            $Records[] = $Record;
        }

        @fclose($Handle);

        return $Records;
    }

    /**
     * Transform an array of CSV records to an array of Link objects,
     * but only those records that are valid.
     * @param array $Records CSV records.
     * @return array Link objects containing valid links.
     */
    private function transformCsv(array $Records): array
    {
        $Links = [];

        foreach ($Records as $Record) {
            # two fields, label and page, are necesary for all valid links
            if (count($Record) < 2) {
                continue;
            }

            # required values
            $Label = $Record[0];
            $Page = $Record[1];

            # optional values
            $DisplayOnlyIfLoggedIn = (count($Record) > 2) ? $Record[2] : null;
            $RequiredPrivileges = (count($Record) > 3) ? $Record[3] : null;

            # sanitize
            $DisplayOnlyIfLoggedIn = (bool)$DisplayOnlyIfLoggedIn;

            # sanitize
            if (!is_null($RequiredPrivileges)) {
                $RequiredPrivileges = $this->parsePrivileges($RequiredPrivileges);

                if (!is_null($RequiredPrivileges)) {
                    $RequiredPrivileges =
                        $this->transformPrivileges($RequiredPrivileges);
                }
            }

            $Link = new Link();
            $Link->Label = $Label;
            $Link->Page = $Page;
            $Link->DisplayOnlyIfLoggedIn = $DisplayOnlyIfLoggedIn;
            $Link->RequiredPrivileges = $RequiredPrivileges;

            $Links[] = $Link;
        }

        return $Links;
    }

    /**
     * Parse a string of privilege names in the following format, where
     * everything except the vertical bar is a privilege name: ([^|]*|)*
     * @param string $PrivilegeString Privilege names in the specified format.
     * @return array|null An array of the privilege names or NULL on error.
     */
    private function parsePrivileges($PrivilegeString): ?array
    {
        $PrivilegeString = trim($PrivilegeString);

        if (strlen($PrivilegeString) < 1) {
            return null;
        }

        $Privileges = explode("|", $PrivilegeString);
        $Privileges = array_map("trim", $Privileges);

        return $Privileges;
    }

    /**
     * Transform a list of privilege names in various formats to a list of only
     * those privilege names that are valid along with their values.
     * @param array $Privileges An array of privilege names.
     * @return array An array of valid privileges, with the name as the key.
     */
    private function transformPrivileges(array $Privileges): array
    {
        $PrivilegeFactory = new PrivilegeFactory();
        $AllPrivileges = $PrivilegeFactory->getPrivileges(true, false);
        $PrivilegeConstants = $PrivilegeFactory->getPredefinedPrivilegeConstants();
        $ValidPrivileges = [];

        foreach ($Privileges as $Privilege) {
            # predefined privilege name
            if (in_array($Privilege, $PrivilegeConstants)) {
                $Key = $Privilege;
                $Value = array_search($Key, $PrivilegeConstants);

                $ValidPrivileges[$Key] = $Value;
            # predefined privilege name without the PRIV_ prefix
            } elseif (in_array("PRIV_".$Privilege, $PrivilegeConstants)) {
                $Key = "PRIV_".$Privilege;
                $Value = array_search($Key, $PrivilegeConstants);

                $ValidPrivileges[$Key] = $Value;
            # predefined privilege description or custom privilege name
            } elseif (in_array($Privilege, $AllPrivileges)) {
                $Key = $Privilege;
                $Value = array_search($Key, $AllPrivileges);

                $ValidPrivileges[$Key] = $Value;
            } elseif (array_key_exists($Privilege, $AllPrivileges)) {
                $Key = $AllPrivileges[$Privilege];
                $Value = $Privilege;

                $ValidPrivileges[$Key] = $Value;
            }
        }

        return $ValidPrivileges;
    }
}
