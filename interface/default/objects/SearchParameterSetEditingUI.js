/**
 * FILE:  SearchParameterSetEditingUI.js
 *
 * Part of the Metavus digital collections platform
 * Copyright 2016-2025 Edward Almasy and Internet Scout Research Group
 * http://metavus.net
 *
 * Javascript routines for the SearchParameterSetEditingUI.
 * @scout:eslint
 */

class SPSEditor {
    /**
     * Toggle UI elements to show only appropriate editing elements
     *         for a selected field.
     * @param jQueryElement FieldRow tr.mv-speui-field-row
     *         containing the elements to consider.
     */
    static showAppropriateEditElements(FieldRow) {
        var SubjectField = $('.mv-speui-field-subject', FieldRow);
        var ValueSelectField = $('.mv-speui-field-value-select', FieldRow);
        var ValueEditField = $('.mv-speui-field-value-edit', FieldRow);
        var ValueQSField = $('.mv-quicksearch', FieldRow);
        var FieldId = $(":selected", SubjectField).attr("value");

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
        $('.mv-speui-operator-' + FieldType, FieldRow).show();

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
    }

    /**
     * Handle clicks on the 'Add Field' button.
     */
    static handleAddFieldClick() {
        // find and clone our template row
        var TemplateRow = $(this).parent().parent().prev();
        var NewRow = TemplateRow.clone(true);

        // insert cloned row before the template
        TemplateRow.before(NewRow);

        // adjust css class and visiblity
        NewRow.removeClass('mv-speui-template-row');
        NewRow.show();
    }

    /**
     * Handle clicks on the 'Add Subgroup' button.
     */
    static handleAddSubgroupClick() {
        var TemplateRow = $(this).parent().parent().prev();
        var FormName = $(".mv-speui-field-subject", NewRow).attr("name");

        // construct the HTML for a new subgroup table
        var NewTable = $(
            '<tr class="mv-speui-subgroup-row"><td >' +
            '<input type="hidden" name="'+FormName+'" value="X-BEGIN-SUBGROUP-X"/>' +
            '<table class="mv-speui-subgroup">' +
            '<tr class="mv-speui-logic-row ">' +
            '<td>Subgroup with ' +
            '<select name="'+FormName+'" class="logic">' +
            '<option value="AND">AND</option>' +
            '<option value="OR" selected>OR</option>' +
            '</select> Logic</td></tr>' +
            '<tr class="mv-jq-placeholder-1"></tr>' +
            '<tr class="mv-jq-placeholder-2"></tr>' +
            '<tr><td>' +
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
        $(".mv-speui-add-field", NewTable).click(SPSEditor.handleAddFieldClick);
        $(".mv-speui-add-subgroup", NewTable).click(SPSEditor.handleAddSubgroupClick);

        // insert the new table before our template row
        TemplateRow.before(NewTable);
    }

    /**
     * Handle clicks on the 'delete' buttons.
     */
    static handleDeleteClick() {
        var TargetRow = $(this).parent().parent();
        var ParentTable = $(TargetRow).parents("table").first();
        var Container = $(TargetRow).parents("table.mv-speui-container").first();

        // remove our target row
        $(TargetRow).remove();

        // if this was a subgroup, and it is now empty, then remove it
        while (ParentTable.hasClass("mv-speui-subgroup")  &&
               $('.mv-speui-field-row:not(.mv-speui-template-row)', ParentTable).length == 0) {
            // save our current target
            var Cur = ParentTable;

            // look up the table that contains this target
            ParentTable = $(ParentTable).parents("table").first();

            // nuke the targeted subgroup
            $(Cur).parent().parent().remove();
        }

        SPSEditor.setSortControlVisibility(Container);
    }

    /**
     * Handle selection of new subject fields within a field row.
     */
    static handleSubjectFieldChange() {
        var Row = $(this).parent().parent();
        var Container = $(Row).parents("table.mv-speui-container").first();
        // clear edit values
        $('.mv-speui-field-value-edit', Row).attr('value','');
        $('.mv-quicksearch-display', Row).val('');
        $('.mv-quicksearch-value', Row).attr('value','');

        // rearrange visiable fields as needed
        SPSEditor.showAppropriateEditElements(Row);
        SPSEditor.setSortControlVisibility(Container);
    }

    /**
     * Toggle visibility of sort field UI elements to only show the
     *         ones corresponding to schemas we are searching.
     * @param jQueryElement Container SPSEUI container to modify.
     */
    static setSortControlVisibility(Container) {
        var SelectedSchemas = [];
        $(".mv-speui-field-subject:visible", Container).each(function(Index, Element) {
            var SchemaId = $("option:selected", Element).data('schema-id');
            if (!SelectedSchemas.includes(SchemaId)) {
                SelectedSchemas.push(SchemaId);
            }
        });
        $(".mv-speui-sort-by", Container).hide();

        SelectedSchemas.forEach(function(SchemaId){
            $(".mv-speui-sort-by-schema-"+SchemaId, Container).show();
        });

        if (SelectedSchemas.length >= 2) {
            $(".mv-speui-schema-name", Container).show();
        } else {
            $(".mv-speui-schema-name", Container).hide();
        }
    }

    /**
     * Set value of sort direction dropdown based on newly selected
     *         sort field.
     */
    static handleSortFieldChange() {
        var Row = $(this).parent().parent();
        var Direction = $("option:selected", $(this)).data("sort-dir");
        $(".mv-speui-sort-dir", Row).val(Direction);
    }

    /**
     * Handle form submissions.
     */
    static handleFormSubmission(Event) {
        // remove the template rows before submission
        $(".mv-speui-template-row").remove();

        // make sure that every select contains something to avoid parse problems
        $(".mv-speui-field-value-select:empty").append(
            '<option value="0">--</option>');

        // prepend operators for tree fields to the term ids
        $(".mv-speui-operator-tree:visible").each(function(Index, Element) {
            var Operator = $("option:selected", Element).val();
            var ValueElement = $("input.mv-quicksearch-value", $(Element).next());
            ValueElement.val(Operator + ValueElement.val());
        });

        // prevent submission if an invalid value is selected
        var HasInvalid = false;
        $(".mv-speui-field-value-select :selected").each(function(Index, Element) {
            if ($(Element).val().includes("INVALID")) {
                HasInvalid = true;
            }
        });

        if (HasInvalid) {
            alert("An invalid value is selected for the Search Parameters. Please select a different value.");
            Event.preventDefault();
        }
    }

    /**
     * Set up SPS Editing UI.
     */
    static setUp() {
        // adjust initial field visiblity
        var SubjectFields = $(".mv-speui-field-subject");
        SubjectFields.each(function(Index, Element){
            SPSEditor.showAppropriateEditElements($(Element).parent().parent());
        });
        $(".mv-speui-container").each(function(Index, Element) {
            SPSEditor.setSortControlVisibility(Element);
        });

        // turn sort direction dropdowns into buttons that toggle
        $("select.mv-speui-sort-dir option[value='0']").text("\u2191");
        $("select.mv-speui-sort-dir option[value='1']").text("\u2193");
        $("select.mv-speui-sort-dir").on("mousedown", function() {
            $(this).val( 1 - $(this).val());
            return false;
        });

        // set up event handlers
        SubjectFields.change(SPSEditor.handleSubjectFieldChange);
        $(".mv-speui-add-field").click(SPSEditor.handleAddFieldClick);
        $(".mv-speui-add-subgroup").click(SPSEditor.handleAddSubgroupClick);
        $(".mv-speui-delete").click(SPSEditor.handleDeleteClick);
        $(".mv-speui-sort-field").change(SPSEditor.handleSortFieldChange);
        $("form").submit(SPSEditor.handleFormSubmission);
    }
}

$(document).ready(function(){
    SPSEditor.setUp();
});
