<?PHP
#
#   FILE:  PluginUpgrade_1_0_6.php (PhotoLibrary plugin)
#
#   A plugin upgrade file for the Metavus digital collections platform
#   Copyright 2026 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins\PhotoLibrary;
use Metavus\Plugins\PhotoLibrary;
use ScoutLib\PluginUpgrade;

/**
 * Class for upgrading the PhotoLibrary plugin to version 1.0.6.
 */
class PluginUpgrade_1_0_6 extends PluginUpgrade
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Perform actions necessary to upgrade plugin to version 1.0.6.
     * @return null|string Return NULL if upgrade succeeded, or string
     *      containing error message if upgrade failed.
     */
    public function performUpgrade()
    {
        $Plugin = PhotoLibrary::getInstance(true);
        $Prefix = $Plugin->getConfigSetting("DownloadImageFileNamePrefix");
        if (is_string($Prefix)) {
            $UpdatedPrefix = $this->removeFolderNameTokenFromPrefix($Prefix);
            if (strlen($UpdatedPrefix) === 0) {
                $UpdatedPrefix = PhotoLibrary::getDefaultDownloadImageFileNamePrefix();
            }
            $Plugin->setConfigSetting("DownloadImageFileNamePrefix", $UpdatedPrefix);
        }

        return null;
    }


    # ---- PRIVATE INTERFACE -------------------------------------------------

    /**
     * Remove folder name tokens and adjacent separators from a file prefix.
     * @param string $Prefix Prefix to clean up.
     * @return string Prefix with folder name tokens removed.
     */
    private function removeFolderNameTokenFromPrefix(string $Prefix): string
    {
        $Token = "%FOLDERNAME%";
        $Separators = [" ", "_", "-", "."];
        $TokenLength = strlen($Token);
        $UpdatedPrefix = $Prefix;

        # repeat until all folder name tokens have been removed
        while (($TokenPosition = strpos($UpdatedPrefix, $Token)) !== false) {
            $AfterTokenPosition = $TokenPosition + $TokenLength;

            # prefer removing one separator after the token, if present
            if ($AfterTokenPosition < strlen($UpdatedPrefix)
                    && in_array(
                        $UpdatedPrefix[$AfterTokenPosition],
                        $Separators,
                        true
                    )) {
                $UpdatedPrefix = substr($UpdatedPrefix, 0, $TokenPosition)
                        .substr($UpdatedPrefix, $AfterTokenPosition + 1);
                continue;
            }

            # otherwise remove one separator before the token, if present
            if ($TokenPosition > 0
                    && in_array($UpdatedPrefix[$TokenPosition - 1], $Separators, true)) {
                $UpdatedPrefix = substr($UpdatedPrefix, 0, $TokenPosition - 1)
                        .substr($UpdatedPrefix, $AfterTokenPosition);
                continue;
            }

            # fall back to removing only the token itself
            $UpdatedPrefix = substr($UpdatedPrefix, 0, $TokenPosition)
                    .substr($UpdatedPrefix, $AfterTokenPosition);
        }

        return $UpdatedPrefix;
    }
}
