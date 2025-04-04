<?PHP
#
#   FILE:  SearchFacetUI.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2015-2024 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus;
use ScoutLib\ApplicationFramework;

/**
* SearchFacetUI supports the generation of a user interface for faceted
* search, by taking the search parameters and search results and generating
* the data needed to lay out the HTML.
*/
class SearchFacetUI extends SearchFacetUI_Base
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Generate and return HTML for search facet user interface.
     * @return string Generated HTML.
     */
    public function getHtml(): string
    {
        $this->loadFacets();

        ob_start();
        $this->addSupportingJavascript();
        $this->printFacetGroup($this->SuggestionsByFieldName);
        $this->printFacetGroup($this->TreeSuggestionsByFieldName);
        return (string)ob_get_clean();
    }


    # ---- PRIVATE INTERFACE -------------------------------------------------

    /**
     * Process an array of search facets, generating the necessary HTML for
     * each.
     * @param array $Facets Search facets to display. Keys give the field
     *   names and values follow the 'nested array format' documented in
     *   SearchFacetUI_Base.
     */
    private function printFacetGroup(
        array $Facets
    ): void {
        if (count($Facets) == 0) {
            return;
        }

        static $ShrinkCounter = 0;

        # get schema in order to retrieve fields in loop
        $Schema = new MetadataSchema($this->SchemaId);
        # iterate over each
        foreach ($Facets as $Key => $Values) {
            # get field in order to retrieve display name for facet
            $Field = $Schema->getField($Key);

            # check cookie and currently open fields to see if this one should
            # be open
            $CookieKey = md5($Key);
            $CookieName = ApplicationFramework::getInstance()->getPageName()."_Facet_".$CookieKey;
            $Show = isset($this->FieldsOpenByDefault[$Key]) ||
                ($_COOKIE[$CookieName] ?? $this->OpenFieldsByDefault);

            # store the open/closed state of this facet
            $_COOKIE[$CookieName] = $Show;

            # if this facet should be open, display it as such, otherwise
            #       display a closed facet (the HTML below differs in which
            #       elements get the "display: none" initially applied)
            $ToggleClass = "DD_Toggle".$ShrinkCounter;

            print "<div class='mv-search-facets' "
                ."onclick=\"toggleFacet(".$ShrinkCounter.",'".$CookieKey."');\">"
                ."<b>".$Field->getDisplayName()
                ."<span class='float-end'>";

            if ($Show) {
                print "<span style='display: none;' class='".$ToggleClass."'>v</span>"
                    ."<span class='".$ToggleClass."'>&#652;</span>";
            } else {
                print "<span class='".$ToggleClass."'>v</span>"
                    ."<span style='display: none;' class='".$ToggleClass."'>&#652;</span>";
            }
            print "</span></b></div>\n";

            $this->printFacetList(
                $Values,
                $Field->searchGroupLogic(),
                $ToggleClass,
                $Show
            );

            $ShrinkCounter++;
        }
    }

    /**
     * Print the list for a given facet.
     * @param array $Facets Facets in this list in the 'nested array format'
     *  documented in SearchFacetUI_Base.
     * @param int $Logic Search logic applied to these facets.
     * @param string $ToggleClass CSS class to add to the <ul> that javascript will use
     *   to toggle facet display.
     * @param bool $Show TRUE for facets that should be initially open, FALSE otherwise.
     */
    private function printFacetList(
        array $Facets,
        int $Logic,
        string $ToggleClass = "",
        bool $Show = true
    ): void {
        print "<ul class='list-group list-group-flush mv-search-facets "
            .$ToggleClass."' ".(!$Show ? "style='display: none;'" : "").">\n";

        foreach ($Facets as $FacetData) {
            # if this element is a sublist rather than a term, move on to the
            # next item
            if (!isset($FacetData["TermInfo"])) {
                continue;
            }

            # extract and remove our term info so that we can iterate over
            # child terms with a `foreach` later on
            $Info = $FacetData["TermInfo"];
            unset($FacetData["TermInfo"]);

            # if this is a removal item
            if (isset($Info["IsSelected"])) {
                $Item = '<li class="list-group-item">'
                    .'<a href="'.defaulthtmlspecialchars($Info["RemoveLink"]).'" rel="nofollow">'
                    .($Logic == SearchEngine::LOGIC_OR ?
                          '<span class="mv-facet-checkbox">&#9746;</span>&nbsp;' : '')
                    .'<b>'.$Info["Name"].'</b></a></li>';
            } else {
                # otherwise, must be an addition item
                $Item = '<li class="list-group-item">'
                    .'<a href="'.defaulthtmlspecialchars($Info["AddLink"]) .'" rel="nofollow">'
                    .($Logic == SearchEngine::LOGIC_OR ?
                      '<span class="mv-facet-checkbox">&#9744;</span>&nbsp;' : '' )
                    .$Info["Name"];

                if ($this->ShowCounts && $Logic == SearchEngine::LOGIC_AND &&
                    isset($Info["Count"])) {
                    $Item .= '&nbsp;('.$Info["Count"].')';
                }
                $Item .= '</a></li>';
            }

            print $Item."\n";

            # if we have child items, print a sublist for them
            if (count($FacetData)) {
                $this->printFacetList($FacetData, $Logic);
            }
        }

        print "</ul>\n";
    }

    /**
     * Output JavaScript code (with surrounding <script> tags) needed to
     * support search facet UI.  If this method is called multiple times,
     * the code is only written out once, by the first call.
     */
    private function addSupportingJavascript(): void
    {
        static $SupportJsDisplayed = false;
        if ($SupportJsDisplayed) {
            return;
        }
        (ApplicationFramework::getInstance())->requireUIFile(
            'jquery.cookie.js',
            ApplicationFramework::ORDER_FIRST
        );

        ?>
        <script type='text/javascript'>
        function toggleFacet(FacetNumber, CookieKey){
            var CookieName = 'SearchResults_Facet_' + CookieKey;
            $.cookie(CookieName, 1 - $.cookie(CookieName));

            $('.DD_Toggle'+FacetNumber).each(function(Index, Element){
                if ($(Element).is("ul")) {
                    $(Element).slideToggle();
                } else {
                    $(Element).toggle();
                }
            });
        }
        </script>
        <?PHP
    }
}
