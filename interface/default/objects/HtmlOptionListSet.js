/**
 * FILE:  HtmlOptionListSet.js
 *
 * Part of the Metavus digital collections platform
 * Copyright 2019-2024 Edward Almasy and Internet Scout Research Group
 * http://metavus.net
 *
 * JavaScript support for HtmlOptionListSet
 *
 * @scout:eslint
 */

class HtmlOptionListSet {
    /**
     * Adjust the avaiable options in an OptionListSet so that the
     * same option cannot be selected multiple times.
     * @param jQuery Container A jQuery collection for the containing
     *     the HtmlOptionListSet
     */
    static updateWhichOptionsAreDisabled(Container) {
        // iterate over all the <select> elements to build a list of
        // selected values
        var SetValues = [];
        $("select", Container).each(function(SelIndex, SelElement){
            var Value = $(SelElement).val();
            if (Value !== '') {
                SetValues.push(Value);
            }
        });

        // enable everything
        $("option", Container).removeAttr('disabled');
        $(".mv-button-delete", Container).show();

        // then disable options that are selected in other lists
        SetValues.forEach(function(Value){
            $("option[value='" + Value + "']:not(:selected)", Container).attr('disabled', true);
        });

        // hide the 'delete' buttons for lists where '--' is the current selection
        $("select", Container).each(function(SelIndex, SelElement){
            if ($(SelElement).val() === '') {
                $(".mv-button-delete", $(SelElement).parent()).hide();
            }
        });
    }

    /**
     * When a selection is made in an option lists, add new lists and
     * adjust disabled values as necessary.
     */
    static listChange() {
        var Container = $(this).parents(".mv-optionlistset");

        // if this list is dynamic, add/remove elements as needed
        if (Container.data('dynamic')) {
            // number of lists currently displayed
            var NumLists = $("select", Container).length;

            // number of options in each list, not counting '--'
            var NumOptions = $("option", $("select", Container).first()).length - 1;

            // maximum number of lists to display
            var MaxLists = Container.data('maxlists');

            // minimum number of lists to display
            var MinLists = Container.data('minlists');

            if ($(this).val() === '') {
                // if we have enough lists and this one is not the last one,
                // delete this one
                if (NumLists > MinLists && !$(this).parent().is(':last-child') ) {
                    $(this).parent().remove();
                    NumLists--;
                }
            }

            // if the number of lists is fewer than the number of options
            // and also less than the max number of lists allowed
            if (NumLists < NumOptions && NumLists < MaxLists) {
                // get the last select element
                var LastSelect = $("select", Container).last();

                // if it was just set to a value rather than the placeholder '--'
                if (LastSelect.val() !== '') {
                    // add a new placeholder at the end of our select list
                    var NewRow = $(LastSelect).parent().clone(true);
                    $("select", NewRow).val('');
                    NewRow.insertAfter(LastSelect.parent());
                }
            }
        }

        HtmlOptionListSet.updateWhichOptionsAreDisabled(Container);
    }

    /**
     * Remove the corresponding row when a delete button is clicked.
     * @param BtnElement JS Object for the button element that was clicked.
     */
    static handleDeleteButtonClick(BtnElement) {
        var Row = $(BtnElement).parent();
        $("select", Row).val('');
        $("select", Row).trigger("change");
    }

    /**
     * Get the current list of selections from an option list set.
     * @return Array Current selections. Each element is a JS object
     *     having 'tid' and 'name' keys, where 'tid' is the option
     *     value and 'name' is the displayed text.
     */
    static getSelections() {
        var Selections = [];
        $("select", $(this)).each(function(Index, Element) {
            if ($(Element).val() !== "") {
                Selections.push({
                    tid: $(Element).val(),
                    name: $(":selected", Element).text()
                });
            }
        });

        return Selections;
    }

    /**
     * Set the selections in an option list set.
     * @param Event Event JS Event object (required by jQuery.on).
     * @param Array Selections Selections to set. Each element in the
     *     array should be a JS object having 'tid' and 'name'
     *     keys. 'tid' will be used for the option value attributes
     *     and 'name' will be the text displayed.
     */
    static setSelections(Event, Selections) {
        // determine how many lists we need
        var TgtNumLists = Selections.length;
        if ($(this).data('dynamic')) {
            TgtNumLists++;
        }

        // delete all but one row
        while ($(".mv-optionlistset-row", $(this)).length > 1) {
            $(".mv-optionlistset-row", $(this)).last().remove();
        }

        // ensure that we have an <option> for each provided selection
        var SelectEl = $("select", $(this));
        Selections.forEach(function(Term) {
            // if we already have an option for this term, nothing to do
            if ($("option[value='" + Term.tid + "']", SelectEl).length > 0) {
                return;
            }

            // otherwise, get the list of <option>s that exist and
            // create an HTML element for our new one
            var Options = $("option", SelectEl),
                Element = $("<option value='" + Term.tid + "'>" + Term.name + "</option>");

            // if there is nothing in the <select>, we can add our new
            // element to it and be done
            if (Options.length == 0) {
                $(SelectEl).append(Element);
                return;
            }

            // otherwise, iterate over the <options> in our <select>,
            // looking for the first one that that our new option
            // should not come before
            var CurRow = null;

            for (var Index = 0; Index < Options.length; Index++) {
                CurRow = $(Options.get(Index));

                // if the new term should be before the current term,
                // then put it there and exit
                if (Term.name.localeCompare(CurRow.text()) < 0) {
                    Element.insertBefore(CurRow);
                    return;
                }
            }

            // othrewise, add the new one at the end
            Element.insertAfter(CurRow);
        });

        // ensure that we have the right number of select elements
        while ($(".mv-optionlistset-row", $(this)).length < TgtNumLists) {
            $(".mv-optionlistset-row", $(this)).last().clone(true).appendTo($(this));
        }

        $("option", $(this)).removeAttr('disabled');
        $("option", $(this)).removeAttr('selected');
        $("select", $(this)).each(function(Index, Element) {
            var TgtVal = '';
            if (Index < Selections.length) {
                TgtVal = Selections[Index].tid;
            }
            $(Element).val(TgtVal);
        });

        // toggle disabled options appropriately
        HtmlOptionListSet.updateWhichOptionsAreDisabled($(this));
    }

    /**
     * Remove the empty values from an option list set. Should be
     * invoked prior to form submission.
     */
    static removeEmptyValues() {
        $(".mv-optionlistset select").each(function(Index, Element) {
            if ($(Element).val() === '') {
                $(Element).removeAttr('name');
            }
        });
    }

    /**
     * Set up the event handlers neded for HtmlOptionListSets
     */
    static setUpEventHandlers() {
        $("select", ".mv-optionlistset").on("change", HtmlOptionListSet.listChange);
        $(".mv-optionlistset").closest("form").on("submit", HtmlOptionListSet.removeEmptyValues);

        // custom event to get the current list of selections
        // (see https://api.jquery.com/on/ )
        $(".mv-optionlistset").on("mv:getselections", HtmlOptionListSet.getSelections);

        // custom event to set the current list of selections
        $(".mv-optionlistset").on("mv:setselections", HtmlOptionListSet.setSelections);
    }
}

$(document).ready(function(){
    // adjust each optionlistset when the page is first loaded
    $(".mv-optionlistset").each(function(){
        HtmlOptionListSet.updateWhichOptionsAreDisabled($(this));
    });

    // and set up event handlers
    HtmlOptionListSet.setUpEventHandlers();
});
