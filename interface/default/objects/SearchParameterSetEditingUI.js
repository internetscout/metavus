/**
 * FILE:  SearchParameterSetEditingUI.js
 *
 * Part of the Metavus digital collections platform
 * Copyright 2016-2024 Edward Almasy and Internet Scout Research Group
 * http://metavus.net
 *
 * Javascript routines for the SearchParameterSetEditingUI.
 * @scout:eslint
 */

(function(){
    /**
     * Toggle UI elements to show only appropriate editing elements
     *   for a selected field.
     * @param mv-speui-field-row <tr> containing the elements to consider.
     */
    function showAppropriateEditElements(FieldRow) {
        var SubjectField = $('.mv-speui-field-subject', FieldRow),
            ValueSelectField = $('.mv-speui-field-value-select', FieldRow),
            ValueEditField = $('.mv-speui-field-value-edit', FieldRow),
            ValueQSField = $('.mv-quicksearch', FieldRow),
            FieldId = $(":selected", SubjectField).attr("value");

        // get the field type from the currently selected field
        // (looks for a css class with a `field-type-` prefix, then
        //  strips the prefix so that we just have the lowercased name)
        var FieldType = $(":selected", SubjectField).attr("class").match(
            /field-type-[a-z]+/)[0].replace('field-type-', '');

        // hide all the editing elements
        ValueSelectField.hide();
        ValueEditField.hide();
        ValueQSField.hide();
        $('.mv-speui-operator', FieldRow).hide();

        // then reveal the ones appropriate for our field type
        if (FieldType == "flag" || FieldType == "option") {
            ValueSelectField.show();

            // stash a copy of the default options to use whenever the
            // selection is changed
            if (!ValueSelectField.data("default-options")) {
                ValueSelectField.data("default-options", ValueSelectField.html());
            }

            // reset option values to the defaults
            ValueSelectField.html("").append(ValueSelectField.data("default-options"));

            // then remove any option values not appropriate for this field
            $("option:not(.field-id-"+FieldId+")", ValueSelectField).remove();
        } else if (FieldType == "controlledname" || FieldType == "tree" ||
                   FieldType == "reference" || FieldType == "user") {
            ValueQSField.show();
            ValueQSField.attr("data-fieldid", FieldId);

            // if the current field has a quicksearch setup, remove it
            //  otherwise, create one
            if (ValueQSField.hasClass('mv-quicksearch-template')) {
                ValueQSField.removeClass('mv-quicksearch-template');
            } else {
                $('.mv-quicksearch-display', ValueQSField).autocomplete("destroy");
            }

            // and set up a new quicksearch widget using the right field
            QuickSearch(ValueQSField); // eslint-disable-line no-undef
        } else {
            ValueEditField.show();
        }

        // show operator indicator
        $('.mv-speui-operator-' + FieldType, FieldRow).show();
    }

    /**
     * Handle clicks on the 'Add Field' button.
     */
    function handleAddFieldClick() {
        // find and clone our template row
        var TemplateRow = $(this).parent().parent().prev(),
            NewRow = TemplateRow.clone(true);

        // insert cloned row before the template
        TemplateRow.before(NewRow);

        // adjust css class and visiblity
        NewRow.removeClass('mv-speui-template-row');
        NewRow.show();
    }

    /**
     * Handle clicks on the 'Add Subgroup' button.
     */
    function handleAddSubgroupClick() {
        var TemplateRow = $(this).parent().parent().prev(),
            FormName = $(".mv-speui-field-subject", NewRow).attr("name");

        // construct the HTML for a new subgroup table
        var NewTable = $(
            '<tr><td colspan=2 style="padding-left: 2em;">' +
            '<input type="hidden" name="'+FormName+'" value="X-BEGIN-SUBGROUP-X"/>' +
            '<table class="mv-speui-subgroup">' +
            '<tr class="mv-speui-logic-row ">' +
            '<td colspan="3">Subgroup with ' +
            '<select name="'+FormName+'" class="logic">' +
            '<option value="AND">AND</option>' +
            '<option value="OR" selected>OR</option>' +
            '</select> Logic</td></tr>' +
            '<tr class="mv-jq-placeholder-1"></tr>' +
            '<tr class="mv-jq-placeholder-2"></tr>' +
            '<tr><td colspan="2">' +
            '<span class="btn btn-primary btn-sm ' +
            'mv-speui-add-field">Add Field</span>' +
            '<span class="btn btn-primary btn-sm ' +
            'mv-speui-add-subgroup">Add Subgroup</span>'+
            '</td></tr></table>' +
            '<input type="hidden" name="'+FormName+'" value="X-END-SUBGROUP-X"/>' +
            '</td></tr>');

        // copy our template row, adjust css, set it visible, insert into the table
        var NewRow = TemplateRow.clone(true);
        NewRow.show();
        NewRow.removeClass('mv-speui-template-row');
        $(".mv-jq-placeholder-1", NewTable).replaceWith(NewRow);

        // also insert a verbatim copy of our templaete row
        $(".mv-jq-placeholder-2", NewTable).replaceWith(TemplateRow.clone(true));

        // hook up the add button handlers
        $(".mv-speui-add-field", NewTable).click(handleAddFieldClick);
        $(".mv-speui-add-subgroup", NewTable).click(handleAddSubgroupClick);

        // insert the new table before our template row
        TemplateRow.before(NewTable);
    }

    /**
     * Handle clicks on the 'delete' buttons.
     */
    function handleDeleteClick() {
        var TargetRow = $(this).parent().parent(),
            ParentTable = $(TargetRow).parentsUntil("table").last().parent();

        // remove our target row
        $(TargetRow).remove();

        // if this was a subgroup, and it is now empty, then remove it
        while (ParentTable.hasClass("mv-speui-subgroup")  &&
               $('.mv-speui-field-row:not(.mv-speui-template-row)', ParentTable).length == 0) {
            // save our current target
            var Cur = ParentTable;

            // look up the table that contains this target
            ParentTable = $(ParentTable).parentsUntil("table").last().parent();

            // nuke the targeted subgroup
            $(Cur).parent().parent().remove();
        }
    }

    /**
     * Handle selecteion of new subject fields within a field row.
     */
    function handleSubjectFieldChange() {
        // grab our table row
        var Row = $(this).parent().parent();

        // clear edit values
        $('.mv-speui-field-value-edit', Row).attr('value','');
        $('.mv-quicksearch-display', Row).val('');
        $('.mv-quicksearch-value', Row).attr('value','');

        // rearrange visiable fields as needed
        showAppropriateEditElements( Row );
    }

    /**
     * Handle form submissions.
     */
    function handleFormSubmission(Event) {
        // remove the template rows before submission
        $(".mv-speui-template-row").remove();

        // make sure that every select contains something to avoid parse problems
        $(".mv-speui-field-value-select:empty").append(
            '<option value="0">--</option>');

        // prepend operators for tree fields to the term ids
        $(".mv-speui-operator-tree:visible").each(function(index, element) {
            var Operator = $("option:selected", element).val(),
                ValueElement = $("input.mv-quicksearch-value", $(element).next());
            ValueElement.val(Operator + ValueElement.val());
        });

        // prevent submission if an invalid value is selected
        var hasInvalid = false;
        $(".mv-speui-field-value-select :selected").each(function() {
            if ($(this).val().includes("INVALID")) {
                hasInvalid = true;
            }
        });

        if (hasInvalid) {
            alert("An invalid value is selected for the Search Parameters. Please select a different value.");
            Event.preventDefault();
        }
    }

    // do UI setup
    $(document).ready(function(){
        // adjust initial field visiblity
        var SubjectFields = $(".mv-speui-field-subject");
        SubjectFields.each(function(){
            showAppropriateEditElements($(this).parent().parent());
        });
        $(".mv-speui-template-row").hide();

        // set up event handlers
        SubjectFields.change(handleSubjectFieldChange);
        $(".mv-speui-add-field").click(handleAddFieldClick);
        $(".mv-speui-add-subgroup").click(handleAddSubgroupClick);
        $(".mv-speui-delete").click(handleDeleteClick);
        $("form").submit(handleFormSubmission);
    });
}());
