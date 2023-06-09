/**
 * FILE:  CW-Tooltips.js
 *
 * Part of the Metavus digital collections platform
 * Copyright 2021 Edward Almasy and Internet Scout Research Group
 * http://scout.wisc.edu
 *
 * JavaScript to support tooltip and jquery-ui dialogs for metadata field instructions
 *
 * @scout:eslint
 */
$(document).ready(function(){
    $(".tooltip-dialog").dialog({
        autoOpen: false,
        width: 600,
    });
});

$(".mv-form-instructions").click(function(){
    var fieldId = $(this).data('fieldid');
    var tgtElement = $("#mv-dialog-"+fieldId);
    $(tgtElement).dialog("option", "position", { my: "left top", at: "right bottom", of: $(this) });
    if ($(tgtElement).dialog("isOpen")){
        $(tgtElement).dialog("close");
    } else {
        $(tgtElement).dialog("open");
    }
});
