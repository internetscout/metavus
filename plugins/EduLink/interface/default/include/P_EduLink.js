/**
 * FILE:  P_EduLink.js
 *
 * Part of the Metavus digital collections platform
 * Copyright 2023 Edward Almasy and Internet Scout Research Group
 * http://metavus.net
 *
 * Supporting JS functions for the LTI Deep Linking resource selection
 * interface.
 */

/**
 * Move a given facet to the top of the list of facets. No-op if the
 * requested facet does not exist.
 * @param string facetName Facet to move.
 */
function promoteFacet(facetName){
    if ($("b:contains('"+facetName+"')", "div.mv-search-facets").length == 0) {
        return;
    }

    var facetHeader = $("b:contains('"+facetName+"')", "div.mv-search-facets").parent(),
        facetItems = facetHeader.next();

    facetHeader.detach();
    facetItems.detach();

    facetItems.prependTo(".mv-p-edulink-search-facet-container");
    facetHeader.prependTo(".mv-p-edulink-search-facet-container");
}

$(document).ready(function(){
    /**
     * Get username/pass from login dialog form elements and perform
     * an AJAX login.
     */
    function doLogin() {
        var data = {
            F_UserName: $("#F_UserName", popup).val(),
            F_Password: $("#F_Password", popup).val()
        };

        $.ajax({
            url: $("#mv-p-edulink-login-button").attr("href"),
            headers: {'X-Requested-With': 'XMLHttpRequest'},
            xhrFields: { withCredentials: true },
            dataType: "json",
            jsonp: false, // do not convert cross-domain posts to jsonp
            type: "POST",
            data: data,
            success: function(response) {
                if (response.Status == "Success") {
                    location.reload();
                } else {
                    $(".alert.alert-danger", popup).show();
                }
            },
            error: function(jqXHR, textStatus, errorThrown) {
                console.log(textStatus, errorThrown, jqXHR.responseText);
            }
        });
    }

    // set up the jquery-ui dialog
    var popup = $("#mv-p-edulink-login-dialog");
    $(popup).dialog({
        autoOpen: false,
        modal: true,
        height: "250",
        width: "300",
        buttons: {
            Login: doLogin,
            Cancel: function() {
                $(".alert.alert-danger", popup).hide();
                $(this).dialog("close");
            }
        }
    });

    // use dialog for login button clicks
    $("#mv-p-edulink-login-button").click(function(event){
        event.preventDefault();
        $(popup).dialog("open");
    });

    // and if the user hits enter in the password field, do a login
    $("input[name='F_Password']").on('keydown', function(event){
        if (event.key == "Enter") {
            doLogin();
        }
    });
});
