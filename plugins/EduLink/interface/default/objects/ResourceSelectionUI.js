/**
 * FILE:  ResourceSelectionUI.js
 *
 * Part of the Metavus digital collections platform
 * Copyright 2024 Edward Almasy and Internet Scout Research Group
 * http://metavus.net
 *
 * JavaScript support for EduLink ResourceSelectionUI
 *
 * @scout:eslint
 */

/**
 * JS support for interactive selection of resources in the LTI
 * selection UI.
 */
class ResourceSelectionUI {
    /**
     * Set the visibility of elements in the 'Selected Resources'
     * section based on what has been selected.
     */
    static setSelectionVisibility() {
        var HaveVisibleSelections = false,
            SelectedRecordIds = [],
            SelectedRecordHtml = [];

        $(".row", ".mv-p-edulink-selected-records").each(function(index, element){
            var Checkbox = $(".mv-p-edulink-record-select input[type='checkbox']", element),
                RecordId = Checkbox.data('recordid');

            if (Checkbox.is(":checked")){
                HaveVisibleSelections = true;
                SelectedRecordIds.push(RecordId);

                $(element).show();
                $(".mv-p-edulink-record-select", "#mv-p-edulink-record-container-" + RecordId).hide();
                $(".mv-p-edulink-record-remove", "#mv-p-edulink-record-container-" + RecordId).show();
                $(element).detach();
                SelectedRecordHtml.unshift(element);
            } else {
                $(element).hide();
                $(".mv-p-edulink-record-select", "#mv-p-edulink-record-container-" + RecordId).show();
                $(".mv-p-edulink-record-remove", "#mv-p-edulink-record-container-" + RecordId).hide();
            }
        });

        SelectedRecordHtml.forEach(function(element) {
            $(element).prependTo(".mv-p-edulink-selected-records");
        });

        $.cookie('LTISelections', SelectedRecordIds.join("-"));
        if (HaveVisibleSelections) {
            $(".mv-p-edulink-selected-container").show();
        } else {
            $(".mv-p-edulink-selected-container").hide();
        }
    }

    /**
     * Set up event handlers that support resource selection
     */
    static setUpEventHandlers() {
        // handle click on 'select' button in the record list
        $(".mv-p-edulink-record-select", ".mv-search-results").on('click', function() {
            $("input[name='F_Select_"+$(this).attr('data-recordid')+"']").prop('checked', true);
            ResourceSelectionUI.setSelectionVisibility();
        });
        // handle keypress for same
        $(".mv-p-edulink-record-select", ".mv-search-results").on('keypress', function(event) {
            // on enter (12) or space (32), simulate click
            if (event.which == 13 || event.which == 32) {
                $(this).trigger("click");
            }
        });

        // handle click on 'remove' button in the record list
        $(".mv-p-edulink-record-remove", ".mv-search-results").on('click', function() {
            $("input[name='F_Select_"+$(this).attr('data-recordid')+"']").prop('checked', false);
            ResourceSelectionUI.setSelectionVisibility();
        });
        // handle keypress for same
        $(".mv-p-edulink-record-remove", ".mv-search-results").on('keypress', function(event) {
            // on enter (12) or space (32), simulate click
            if (event.which == 13 || event.which == 32) {
                $(this).trigger("click");
            }
        });

        // handle click on the 'X' button at right of the selected
        // record list at the top of the page
        $(".mv-p-edulink-record-select input[type='checkbox']", ".mv-p-edulink-selected-records").on(
            'change',
            function(){
                ResourceSelectionUI.setSelectionVisibility();
            });
        // handle keypress for same
        $("label.mv-p-edulink-record-select", ".mv-p-edulink-selected-records").on(
            'keypress',
            function(event) {
                // on enter (12) or space (32), simulate click
                if (event.which == 13 || event.which == 32) {
                    $("input[type='checkbox']", this).trigger('click');
                }
            });


        // when a final selection is made, clear our list of interim selections
        $('#mv-selection-form').on('submit', function() {
            $.cookie('LTISelections', null);
        });
    }
}

$(document).ready(function(){
    ResourceSelectionUI.setUpEventHandlers();
});
