/**
 * FILE:  AdvancedSearch.js
 *
 * Part of the Metavus digital collections platform
 * Copyright 2010-2020 Edward Almasy and Internet Scout Research Group
 * http://scout.wisc.edu
 *
 * JavaScript to submit on sort form changes/hide submit button on AdvancedSearch
 *
 * @scout:eslint
 */

$(document).ready(function(){
    // submit the sorting form whenever an input changes
    $(".SortForm input, .SortForm select").change(function(){
        $(".SortForm input[type=submit]").click();
    });

    // hide submit button and shrink the containing td
    $(".SortForm input[type=submit]").hide();
    $(".SortOrderTable col:nth-child(2)").attr("width", 225);
});
