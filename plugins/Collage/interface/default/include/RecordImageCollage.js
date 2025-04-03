/**
 * FILE:  RecordImageCollage.js (ResourceCollage plugin)
 *
 * Part of the Metavus digital collections platform
 * Copyright 2021-2023 Edward Almasy and Internet Scout Research Group
 * http://metavus.net
 *
 * Javascript to display resource collage dialogs and realign tiles
 */


$(document).ready(function(){
    const dialogWidth = $("#mv-rollover-dialog").data("dialog-width");
    const expectedVw = $("#mv-rollover-dialog").data("expected-vp-width");
    const tileWidth = $("#mv-rollover-dialog").data("tile-width");

    /**
     * Add more tiles if viewport is wider than expected, offset every other row
     */
    function fixTiles() {
        const vw = Math.max(document.documentElement.clientWidth || 0, window.innerWidth || 0);

        // add more tiles if the viewport is wider than we expected
        if (vw > expectedVw) {
            const numRows = $("#mv-rollover-dialog").data("num-rows");
            let tiles = $(".mv-p-collage-tile");
            const initialCount = tiles.length;
            const newCount = Math.ceil(vw/tileWidth) * numRows;
            const tilesToAdd = newCount - initialCount;
            for (let i = 0; i < tilesToAdd; i++) {
                $(tiles[i % initialCount]).clone(true).appendTo(".mv-p-collage");
            }
        }

        // offset every other row by half of a tile's width
        let rowCount = 0;
        // reset tile translations in case of resizing
        $(".mv-p-collage-tile").css('transform','');
        const leftEdgeOffset = $(".mv-p-collage-tile").first().offset().left;
        $(".mv-p-collage-tile").each(function(index, element){
            const thisOff = $(element).offset();
            // check if we're at the first tile in a new row
            if (thisOff.left <= leftEdgeOffset) {
                rowCount++;
            }

            if (rowCount % 2 == 0) {
                $(element).css('transform', 'translateX(-'+tileWidth/2+'px)');
            }
        });
    }

    /**
     * Update dialog data for display.
     * @param {jQuery} tileElement The jQuery object for the tile element that will be used to update the dialog data.
     */
    function loadDialogData(tileElement) {
        var tgtElement = $("#mv-rollover-dialog");

        $(tgtElement).dialog("option", "position", { my: "left top", at: "left bottom", of: $(tileElement) });

        $(tgtElement).dialog("option", "title", $(tileElement).data('tooltip-content'));
        $(".mv-description", tgtElement).html($(tileElement).data('description'));
        $(".mv-url", tgtElement).html($(tileElement).data('url'));
        $(".mv-url", tgtElement).attr('href', $(tileElement).data('goto'));
        $(".mv-fullrecord", tgtElement).attr('href', $(tileElement).data('fullrecord'));
    }

    /**
     * Setup functionality for tile-click dialog-popups
     */
    let setUpTileDialog = function() {
        // set up jquery-ui dialog
        $("#mv-rollover-dialog").dialog({
            autoOpen: false,
            draggable: false,
            resizable: false,
            width: dialogWidth,
            dialogClass: "mv-p-collage-rollover",
            open: function(event, ui) {
                $(".mv-p-collage-tile").tooltip("option","disabled", true);
            },
            close: function(event, ui) {
                $(".mv-p-collage-tile").tooltip("option","disabled", false);
            }
        });

        // open or close dialog, updating info with loadDialogData if closed
        $(".mv-p-collage-tile").click(function(){
            if ($("#mv-rollover-dialog").dialog("isOpen")) {
                $("#mv-rollover-dialog").dialog("close");
            } else {
                $("#mv-rollover-dialog").dialog("open");
                loadDialogData( $(this) );
            }
        });

        // update dialog data when hovering a tile
        $(".mv-p-collage-tile").mouseenter(function(){
            if (!$("#mv-rollover-dialog").dialog("isOpen")) {
                loadDialogData( $(this) );
            }
        });

        // close dialog on wandering mouse
        $("#mv-content-wrapper, #mv-content-mainnav, #mv-content-toolbox").mouseenter(function(){
            var tgtElement = $("#mv-rollover-dialog");
            $(tgtElement).dialog("close");
        });

        // initialize the tooltip for the tiles
        $(".mv-p-collage-tile").tooltip({
            // custom class for the tooltip element
            tooltipClass: 'mv-p-collage-tile-tooltip',
            // only elements with data-tooltip-content attribute will have tooltip
            items: ".mv-p-collage-tile[data-tooltip-content]",
            content: function() {
                // function to get the content of the tooltip
                // it gets the content from the data-tooltip-content attribute of the tile
                return $(this).data("tooltip-content");
            },
            // position of the tooltip with respect to the tile
            position: {my: "center top+10", at: "center" },
            // delay 0.5 seconds before showing the tooltip
            show: { delay: 500 },
        });
    }

    fixTiles();
    setUpTileDialog();
    $(window).resize(fixTiles);
});
