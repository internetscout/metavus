/**
 * FILE:  IIIFImageViewer.js
 *
 * Part of the Metavus digital collections platform
 * Copyright 2024 Edward Almasy and Internet Scout Research Group
 * http://metavus.net
 *
 * @scout:eslint
 *
 * IIIF viewer support.
 */

/* global OpenSeadragon, CWIS_BASE_URL */

$(document).ready(function(){
    $(".mv-p-iiif-viewer").each(function(Index, Element) {
        OpenSeadragon({
            id: $(Element).attr('id'),
            prefixUrl: CWIS_BASE_URL + "plugins/IIIFImageViewer/lib/openseadragon/images/",
            tileSources: [
                CWIS_BASE_URL + "iiif/" + $(Element).data('imageid')+ "/info.json"
            ]
        });
    });
});
