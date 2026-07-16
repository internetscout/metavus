/**
 * FILE:  SearchFacetUI.js
 *
 * Part of the Metavus digital collections platform
 * Copyright 2025 Edward Almasy and Internet Scout Research Group
 * http://metavus.net
 *
 * Javascript routines for the SearchFacetUI.
 * @scout:eslint
 */

// eslint-disable-next-line no-unused-vars
class SearchFacetUI {
    /**
     * Toggle display of a facet that was just clicked.
     * @param JsEvent event Event corresponding to the click.
     */
    static handleClick(event) {
        var target = $(event.target);
        if (!target.is("div")) {
            target = $(target).parents("div").first();
        }

        var CookieName = 'SearchResults_Facet_' + target.data('cookie-key');
        $.cookie(CookieName, 1 - $.cookie(CookieName));

        $('.mv-search-facets-toggleable', target).toggle();
        target.next().slideToggle();
    }

    /**
     * Handle keypresses on facets.
     * @param JsEvent event Event corresponding to the click.
     */
    static handleKeydown(event) {
        if (event.keyCode == 13) {
            $(event.target).click();
        }
    }
}
