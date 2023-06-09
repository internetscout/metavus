<?PHP
#
#   FILE:  MetavusCore.php
#
#   A plugin for the Metavus digital collections platform
#   Copyright 2020-2022 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins;

use ScoutLib\Plugin;

/**
 * Permanent Metavus "core" plugin.  May be used by other plugins in their Requires
 * list to ensure compatibility with a specific minimum Metavus version.
 */
final class MetavusCore extends Plugin
{

    # ---- STANDARD PLUGIN INTERFACE -----------------------------------------

    /**
     * Set plugin attributes.
     */
    public function register()
    {
        $this->Name = "Metavus Core";
        $this->Version = METAVUS_VERSION;
        $this->Description = "Permanent core plugin for Metavus.";
        $this->Author = "Internet Scout Research Group";
        $this->Url = "https://metavus.net";
        $this->Email = "support@metavus.net";
        $this->EnabledByDefault = true;
    }
}
