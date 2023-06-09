// CWIS.js
// This file is intended to contain any javascript needed to support styles
// defined in CWIS.css.
// @scout:eslint

// add alternate row colors to any configuration tables
$(document).ready(function(){
    $('.ConfigTable tr:odd').addClass('LightRow');
    $('.ConfigTable tr:even').addClass('DarkRow');
    $('.ConfigTable td:first-child').css({
        "vertical-align": "top",
        "padding-top": "6px",
        "font-weight": "bold",
        "white-space": "nowrap"
    });
});
