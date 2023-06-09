/**
 * FILE:  HtmlOptionListSet.js
 *
 * Part of the Metavus digital collections platform
 * Copyright 2019-2020 Edward Almasy and Internet Scout Research Group
 * http://metavus.net
 *
 * JavaScript support for HtmlOptionListSet
 *
 * @scout:eslint
 */

$(document).ready(function(){
    /**
     * Adjust the avaiable options in an OptionListSet so that the
     * same option cannot be selected multiple times.
     * @param container div containing the HtmlOptionListSet
     */
    function toggleDisabledOptions(container) {
        // iterate over all the <select> elements to build a list of
        // selected values
        var setValues = [];
        $("select", container).each(function(selIndex, selElement){
            var value = $(selElement).val();
            if (value != '') {
                setValues.push(value);
            }
        });

        // enable everything
        $("option", container).removeAttr('disabled');

        // then disable options that are selected in other lists
        setValues.forEach(function(value){
            $("option[value='"+value+"']:not(:selected)", container).attr('disabled', true);
        });

    }

    // adjust each optionlistset when the page is first loaded
    $(".mv-optionlistset").each(function(){
        toggleDisabledOptions($(this));
    });

    // clear 'name' from empty items so they won't be submitted with
    // form data
    $(".mv-optionlistset").closest("form").submit(function() {
        $(".mv-optionlistset select").each(function(index, element) {
            if ($(element).val() == '') {
                $(element).removeAttr('name');
            }
        });
    });

    // handle changes to selections
    $("select", ".mv-optionlistset").change(function(){
        var container = $(this).parent();

        // if this list is dynamic, add/remove elements as needed
        if (container.data('dynamic')) {
            var numLists = $("select", container).length,
                maxLists = container.data('maxlists'),
                minLists = container.data('minlists');

            if ($(this).is(':last-child') && numLists < maxLists) {
                $(this).clone(true).insertAfter($(this));
            } else if ($(this).val() == '' && numLists > minLists) {
                $(this).remove();
            }
        }

        toggleDisabledOptions(container);
    });

    // custom event to get the current list of selections
    // (see https://api.jquery.com/on/ )
    $(".mv-optionlistset").on("mv:getselections", function() {
        var selections = [];
        $("select", $(this)).each(function(index, element) {
            if ($(element).val() != "") {
                selections.push({
                    tid: $(element).val(),
                    name: $(":selected", element).text()
                });
            }
        });
        return selections;
    });

    // custom event to set the current list of selections
    $(".mv-optionlistset").on("mv:setselections", function(event, selections) {
        // determine how many lists we need
        var tgtNumLists = selections.length;
        if ($(this).data('dynamic')) {
            tgtNumLists++;
        }

        // delete all but one of the lists
        while ($("select", $(this)).length > 1) {
            $("select", $(this)).last().remove();
        }

        // ensure that we have an <option> for each provided selection
        var selectEl = $("select", $(this));
        selections.forEach(function(term) {
            // if we already have an option for this term, nothing to do
            if ($("option[value='"+term.tid+"']", selectEl).length > 0) {
                return;
            }

            // otherwise, get the list of <option>s that exist and
            // create an HTML element for our new one
            var options = $("option", selectEl),
                element = $("<option value='"+term.tid+"'>"+term.name+"</option>");

            // if there is nothing in the <select>, we can add our new
            // element to it and be done
            if (options.length == 0) {
                $(selectEl).append(element);
                return;
            }

            // otherwise, iterate over the <options> in our <select>,
            // looking for the first one that that our new option
            // should not come before
            var curRow = null;

            for (var index = 0; index < options.length; index++) {
                curRow = $(options.get(index));

                // if the new term should be before the current term,
                // then put it there and exit
                if (term.name.localeCompare(curRow.text()) < 0) {
                    element.insertBefore(curRow);
                    return;
                }
            }

            // othrewise, add the new one at the end
            element.insertAfter(curRow);
        });

        // ensure that we have the right number of select elements
        while ($("select", $(this)).length < tgtNumLists) {
            $("select", $(this)).last().clone(true).appendTo($(this));
        }

        $("option", $(this)).removeAttr('disabled');
        $("option", $(this)).removeAttr('selected');
        $("select", $(this)).each(function(index, element) {
            var tgtVal = '';
            if (index < selections.length) {
                tgtVal = selections[index].tid;
            }
            $(element).val(tgtVal);
        });

        // toggle disabled options appropriately
        toggleDisabledOptions($(this));
    });
});
