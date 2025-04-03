<?PHP
#
#   FILE:  PluginUpgrade_1_0_25.php (Blog plugin)
#
#   A plugin upgrade file for the Metavus digital collections platform
#   Copyright 2024 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins\Blog;

use Metavus\Plugins\Blog;
use ScoutLib\PluginUpgrade;

/**
 * Class for upgrading the Blog plugin to version 1.0.25.
 */
class PluginUpgrade_1_0_25 extends PluginUpgrade
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Perform actions necessary to upgrade plugin to version 1.0.25.
     * @return null|string Return NULL if upgrade succeeeded, or string
     *      containing error message if upgrade failed.
     */
    public function performUpgrade()
    {
        $Plugin = Blog::getInstance(true);
        foreach ($Plugin->getBlogEntries() as $Entry) {
            # replace blank line markers with '--' in existing entries
            $Body = $Entry->get($Plugin::BODY_FIELD_NAME);
            $BlankRegex = '(<p>(\s|\xC2\xA0|&nbsp;)*</p>)+';
            preg_match(
                '%'.$BlankRegex.'%',
                $Body,
                $Matches
            );
            if (count($Matches) > 0) {
                $Body = preg_replace(
                    '%'.$BlankRegex.'%',
                    Entry::EXPLICIT_MARKER,
                    $Body
                );
            }
            $Entry->set($Plugin::BODY_FIELD_NAME, $Body);
        }
        return null;
    }

    # ---- PRIVATE INTERFACE -------------------------------------------------
}
