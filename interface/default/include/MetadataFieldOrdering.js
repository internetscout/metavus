/**
 * FILE:  MetadataFieldOrdering.js
 *
 * Part of the Metavus digital collections platform
 * Copyright 2020 Edward Almasy and Internet Scout Research Group
 * http://metavus.net
 *
 * @scout:eslint
 *
 * Sorting functionality for MetadataFieldOrdering
 */

/**
* Set up interactive MetadataFieldOrder editing for a specified order.
* @param string type of order to generate for.
*/
// eslint-disable-next-line no-unused-vars
function MFOrderEditor(type){
    function saveData() {
        $('input:hidden[name='+type+'Order]').val(
            $('.sortable-'+type+' > ul').nestedSortable('serialize') );
    }

    $('.sortable-'+type+' > ul').nestedSortable({
        handle: 'div',
        listType:'ul',
        items: 'li',
        toleranceElement: '> div',
        maxLevels: 2,
        stop: saveData
    });

    saveData();
}
