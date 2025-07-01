<?PHP
#
#   FILE:  MySearchesUI.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2019-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins\MySearches;
use Exception;
use Metavus\HtmlButton;
use ScoutLib\ApplicationFramework;

class MySearchesUI
{
    /**
     * Get HTML for saved search list block -- called by MySearches plugin
     * @param array $Searches array of search arrays to display; Each search
     *      array contains a string SearchURL which is the URL to reach the
     *      search, a string SearchTitle for use in the title field of the a
     *      tag linking to the search and a SearchName for use in the a tag for
     *      linking to the search.
     * @return string Generated HTML.
     * @throws Exception
     */
    public static function getHtmlForSavedSearchesBlock(array $Searches): string
    {
        $AF = ApplicationFramework::getInstance();
        $EditSearchesButton = new HtmlButton("Edit");
        $EditSearchesButton->setIcon("Pencil.svg");
        $EditSearchesButton->setSize(HtmlButton::SIZE_SMALL);
        $EditSearchesButton->addClass("float-end");
        $EditSearchesButton->setLink($AF->baseUrl() . "index.php?P=ListSavedSearches");

        ob_start();
        ?><!-- BEGIN SAVED SEARCHES BLOCK -->
        <div class="mv-section mv-section-simple mv-html5-section cw-mysearches-sidebar-saved">
            <div class="mv-section-header mv-html5-header">
                <?= $EditSearchesButton->getHtml(); ?>
                <img src="<?= $AF->gUIFile("MagnifyingGlass.svg") ?>" alt="">
                My Searches
            </div>
            <div class="mv-section-body">
                <ul class="mv-bullet-list">
                <?PHP foreach ($Searches as $Search) { ?>
                    <li><a href="<?= $Search["SearchURL"];  ?>" title="Search Parameters:
                    <?= "\n".htmlspecialchars($Search["SearchTitle"]); ?>">
                    <?= $Search["SearchName"]; ?></a>
                    </li>
                <?PHP } ?>
                </ul>
            </div>
        </div>
        <!-- END SAVED SEARCHES BLOCK --><?PHP
        return (string)ob_get_clean();
    }

    /**
     * Get HTML for recent search list block -- called by MySearches plugin
     * @param array $Searches array of search arrays to display; Each search
     *      array contains a string SearchURL which is the URL to reach the
     *      search and a SearchName for use in the a tag for linking to the search.
     * @return string Generated HTML.
     */
    public static function getHtmlForRecentSearchesBlock($Searches): string
    {
        $AF = ApplicationFramework::getInstance();

        ob_start();
        ?><!-- BEGIN RECENT SEARCHES DISPLAY -->
        <div class="mv-section mv-section-simple mv-html5-section cw-mysearches-sidebar-recent">
            <div class="mv-section-header mv-html5-header">
                <img src="<?= $AF->gUIFile("MagnifyingGlass.svg") ?>" alt="">
                Recent Searches
            </div>
            <div class="mv-section-body">
                <ul class="mv-bullet-list">
                <?PHP  foreach ($Searches as $Search) {  ?>
                    <li><a href="<?= $Search["SearchURL"] ?>">
                            <?= $Search["SearchName"] ?></a></li>
                <?PHP  }  ?>
                </ul>
            </div>
        </div>
        <!-- END RECENT SEARCHES DISPLAY --><?PHP
        return (string)ob_get_clean();
    }
}
