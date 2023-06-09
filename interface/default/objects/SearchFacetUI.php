<?PHP
#
#   FILE:  SearchFacetUI.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2015-2020 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#

namespace Metavus;

/**
* SearchFacetUI supports the generation of a user interface for faceted
* search, by taking the search parameters and search results and generating
* the data needed to lay out the HTML.
*/
class SearchFacetUI extends SearchFacetUI_Base
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    public function printSearchFacets()
    {
        $this->generateFacets();

        $this->printFacetGroup(
            $this->SuggestionsByFieldName
        );

        $this->printFacetGroup(
            $this->TreeSuggestionsByFieldName
        );
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
    ) {
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
            $CookieName = $GLOBALS["AF"]->getPageName()."_Facet_".$CookieKey;
            $Show = isset($this->FieldsOpenByDefault[$Key]) ||
                ($_COOKIE[$CookieName] ?? false);

            # store the open/closed state of this facet
            $_COOKIE[$CookieName] = $Show;

            # if this facet should be open, display it as such, otherwise
            #       display a closed facet (the HTML below differs in which
            #       elements get the "display: none" initially applied)
            $ToggleClass = "DD_Toggle".$ShrinkCounter;

            print "<div class='mv-search-facets' "
                ."onclick=\"toggle_facet(".$ShrinkCounter.",'".$CookieKey."');\">"
                ."<b>".$Field->GetDisplayName()
                ."<span class='float-right'>";

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
    ) {

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
}
