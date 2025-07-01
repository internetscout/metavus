/**
 * FILE:  ChangeSetEditingUI.js
 *
 * Part of the Metavus digital collections platform
 * Copyright 2014-2020 Edward Almasy and Internet Scout Research Group
 * http://metavus.net
 *
 * @scout:eslint
 *
 * Javascript routines for the ChangeSetEditingUI.
 */

(function(){
    // must match the CHANGE_XX constants in Record
    /* eslint-disable no-unused-vars */
    const CHANGE_NOP = 0;
    const CHANGE_SET = 1; /* for setting a single, specified value */
    const CHANGE_CLEAR = 2; /* for clearing a single, specified value */
    const CHANGE_CLEARALL = 3; /* for clearing all values */
    const CHANGE_APPEND = 4;
    const CHANGE_PREPEND = 5;
    /* const CHANGE_REPLACE = 6; (legacy value; no longer used) */
    const CHANGE_FIND_REPLACE = 7;
    /* eslint-enable no-unused-vars */

    /**
     * Toggle UI elements such that only those appropriate for a
     * selected field are visible.
     * @param SubjectField HTML form field selecting which metadata
     *   field should be in use.
     */
    function modifySiblingFields( SubjectField ) {
        var FieldId = jQuery(":selected", SubjectField).attr("value"),
            FieldType = jQuery(":selected", SubjectField).attr("class").match(/field-type-[a-z]+/),
            TopRow = jQuery(SubjectField).closest(".field_row"),
            ValueSelectField = jQuery(".field-value-select", TopRow),
            OperatorField = jQuery(".field-operator", TopRow);

        showAppropriateOptions(OperatorField, "." + FieldType);

        // remove 'clear all' from required fields
        if (jQuery(":selected", SubjectField).hasClass("required")) {

            jQuery("option[value=" + CHANGE_CLEARALL + "]", OperatorField).remove();

            // the 'clear all values' operator is not available for required
            // fields, however, the 'clear specified value' *is* available for
            // required fields that can have multiple values
            // (if an edit would clear a value from a required field that can not
            // have multiple values, Record::applyListOfChanges() will not clear
            // the last value from a required multi-value field)
        }

        if (FieldType == "field-type-flag" || FieldType == "field-type-option" ||
            FieldType == "field-type-multoption" ) {
            showAppropriateOptions(ValueSelectField, ".field-id-"+FieldId);
        }

        showAppropriateEditFields( TopRow );
    }

    /**
     * (Re)sets default options on the specified <select> field.
     * Removes options from that field that do not match a provided selector.
     * @param FieldToSetOptionsOn HTML <select> element to adjust options on.
     * @param OptionsToKeep Selector that specifies which options to not remove
     *         from the form field.
     */
    function showAppropriateOptions(FieldToSetOptionsOn, OptionsToKeep) {
        // before inappropriate options are removed, stash a copy of all of
        // the options this field can have in the data attribute under
        // "default_options"
        if (!FieldToSetOptionsOn.data("default_options")) {
            FieldToSetOptionsOn.data(
                "default_options",
                FieldToSetOptionsOn.html()
            );
        } else {
            // options that should be available may have been removed;
            // restore all of the options (stored in "default_options")
            // before removing any
            FieldToSetOptionsOn.html("");
            FieldToSetOptionsOn.append(FieldToSetOptionsOn.data("default_options"));
        }

        // remove inappropriate options
        jQuery("option:not("+OptionsToKeep+")", FieldToSetOptionsOn).remove();
    }

    /**
     * Toggle UI elements to show only appropriate edit fields for a selected field.
     * @param FieldRow <tr> containing the elements to consider.
     */
    function showAppropriateEditFields(FieldRow) {
        var SubjectField = jQuery('.field-subject', FieldRow),
            ValueSelectField = jQuery('.field-value-select', FieldRow),
            ValueEditField = jQuery('.field-value-edit', FieldRow),
            ValueEdit2Field = jQuery('.field-value-repl', FieldRow),
            ValueQSField = jQuery('.mv-quicksearch', FieldRow),
            OperatorField = jQuery('.field-operator', FieldRow),
            OpType = jQuery(":selected", OperatorField).attr("value");

        // for Clear All, nobody gets edit boxes
        if (OpType == CHANGE_CLEARALL) {
            ValueSelectField.hide();
            ValueEditField.hide();
            ValueEdit2Field.hide();
            ValueQSField.hide();
            // for Find/Replace, we'll need the two text boxes
        } else if (OpType == CHANGE_FIND_REPLACE) {
            ValueSelectField.hide();
            ValueEditField.show();
            ValueEdit2Field.show();
            ValueQSField.hide();
            // otherwise, determine it by field type
        } else {
            if (jQuery(":selected",SubjectField).length==0) {
                return;
            }
            var FieldType = jQuery(":selected", SubjectField).attr("class").match(/field-type-[a-z]+/);
            if (FieldType == "field-type-flag" || FieldType == "field-type-option" ||
                FieldType == "field-type-multoption") {
                ValueSelectField.show();
                ValueEditField.hide();
                ValueEdit2Field.hide();
                ValueQSField.hide();
            } else if (FieldType == "field-type-controlledname" ||
                     FieldType == "field-type-tree" ||
                     FieldType == "field-type-reference") {
                ValueQSField.show();

                var field_id = jQuery(":selected", SubjectField).attr("value");
                ValueQSField.attr("data-fieldid", field_id);

                // if the current widget doesn't yet have a QS setup, fix that
                if (ValueQSField.hasClass('mv-quicksearch-template')) {
                    ValueQSField.removeClass('mv-quicksearch-template');
                    QuickSearch(ValueQSField); // eslint-disable-line no-undef
                }

                ValueEditField.hide();
                ValueEdit2Field.hide();
                ValueSelectField.hide();
            } else {
                ValueEditField.show();
                ValueEdit2Field.hide();
                ValueSelectField.hide();
                ValueQSField.hide();
            }
        }
    }

    /**
     * Check if any edit rows are visible, show the 'no fields' message if not.
     */
    function toggleEmptyMessageIfNeeded() {
        // iterate over the empty messages, collecting the section
        // names we need to look for
        var Sections = [];
        jQuery('.mv-feui-empty').each(function(){
            Sections.push($(this).data('formname'));
        });

        jQuery.each(Sections, function(Key, Val){
            if (jQuery('.' + Val + '.field_row:not(.template_row)').length == 0) {
                jQuery('.' + Val + '.mv-feui-empty').show();
            } else {
                jQuery('.' + Val + '.mv-feui-empty').hide();
            }
        });
    }

    /**
     * Handle changes to the selected 'subject' field.
     */
    function handleSubjectFieldChange(){
        // grab our table row
        var Row = jQuery(this).parent().parent();

        // clear any error fields and remove error messages
        jQuery('.field-value-edit', Row).removeClass('mv-form-error');
        jQuery('span.mv-feui-error', Row).remove();

        // clear edit/replace values
        jQuery('.field-value-edit', Row).attr('value','');
        jQuery('.field-value-repl', Row).attr('value','');
        jQuery('.mv-quicksearch-display', Row).val('');
        jQuery('.mv-quicksearch-value', Row).attr('value', '');

        // rearrange visiable fields as needed
        modifySiblingFields( jQuery(this) );
    }

    // do UI setup
    $(document).ready(function(){
        var SubjectFields = jQuery(".field-subject:visible:not(.field-static)");

        // hide elements that should be hidden
        SubjectFields.each(function(){
            modifySiblingFields( jQuery(this) );
        });

        toggleEmptyMessageIfNeeded();

        // watch for subject changes, alter field hiding as needed
        SubjectFields.change( handleSubjectFieldChange );

        // similarly, watch for operator fields changes and alter field hiding as needed
        var OpFields = jQuery(".field-operator");
        OpFields.change( function(){
            var TopRow = jQuery(this).parent().parent().children();
            showAppropriateEditFields( TopRow );
        });

        // handle clicking the add button
        jQuery(".mv-feui-add").click(function(){
            // grab the template row
            var TemplateRow = $(this).parent().parent().prev();

            // make a copy of it, set the copy visible
            var NewRow = TemplateRow.clone(true);
            NewRow.removeClass('template_row');
            TemplateRow.before(NewRow);
            NewRow.show();

            modifySiblingFields(NewRow);
            jQuery(".field-subject:not(.field-static)", NewRow).change(handleSubjectFieldChange);

            // remove any 'modified' messages
            jQuery('.mv-form-modified').remove();
            toggleEmptyMessageIfNeeded();
        });

        // handle clicking the delete button
        jQuery(".mv-feui-delete").click(function(){
            $(this).parent().parent().remove();
            toggleEmptyMessageIfNeeded();
        });

        jQuery(".mv-feui-form").submit(function() {
            // remove the 'modified' notice and past error messages
            jQuery('.mv-form-modified').remove();
            jQuery('.field-value-edit').removeClass('mv-form-error');
            jQuery('span.mv-feui-error').remove();

            // validate timestamp, date, and number fields
            var rc = true;

            jQuery('.field_row:not(.template_row)').each(function(){
                var FieldType,
                    FieldValue = jQuery('.field-value-edit', this).val(),
                    SubjectField = jQuery('.field-subject', this),
                    FieldOp = jQuery('.field-operator option:selected', this).attr('value');

                // extract the field id for the given row
                // if the value of the subject field starts with S_, then we're dealing with
                // a static field row and can pull the class from the input element directly.
                // otherwise, we're dealing with a selectable row, and
                // need to pull the class from the selected element of
                // the subject field.
                if (SubjectField.attr('value') !== undefined &&
                    SubjectField.attr('value').match(/S_/)) {
                    FieldType = jQuery(SubjectField).attr("class").match(
                        /field-type-[a-z]+/);
                } else {
                    FieldType = jQuery(":selected", SubjectField).attr("class").match(
                        /field-type-[a-z]+/);
                }

                // for set and clear on text, url, and paragraph
                // fields, a value is required
                if ((FieldOp == CHANGE_SET || FieldOp == CHANGE_CLEAR) &&
                    (FieldType == "field-type-text" ||
                     FieldType == "field-type-url" ||
                     FieldType == "field-type-paragraph") &&
                    (FieldValue.trim().length == 0)) {
                    jQuery('.field-value-edit', this).parent()
                        .append('<span style="position: absolute;"' +
                                'class="mv-feui-error mv-form-error">' +
                                'Value cannot be empty</span>');
                    rc = false;
                    // number fields should contain ints
                    // if operator is not 'clear all'
                } else if ((FieldOp == CHANGE_SET || FieldOp == CHANGE_CLEAR) &&
                        (FieldType == "field-type-tree" ||
                        FieldType == "field-type-controlledname")) {
                    // tree fields use a QuickSearch input, which keeps the ID
                    // of the selected value in a hidden input
                    FieldValue = jQuery('.mv-quicksearch-value', this).val();
                    if (FieldValue.length == 0) {
                        // blank value message has to be inside of the
                        // quicksearch div to appear inline with the input
                        jQuery('.mv-quicksearch', this)
                            .append('<span style="position: absolute;" ' +
                                    'class="mv-feui-error mv-form-error">'+
                                    'Value cannot be empty</span>');
                        rc = false;
                    }
                // number fields should contain ints
                } else if (FieldType == "field-type-number" &&
                    FieldOp != CHANGE_CLEARALL) {
                    if (!FieldValue.match(/^[0-9]+$/)) {
                        jQuery('.field-value-edit', this).addClass('mv-form-error');
                        jQuery('.field-value-edit', this).parent().append(
                            '<span style="position: absolute;" class="mv-feui-error mv-form-error"' +
                                '>Invalid value: not a whole number</span>');
                        rc = false;
                    }
                // date and timestamp fields should be parsable dates
                //  if operator is not 'clear all'
                }  else if ((FieldType == "field-type-date" ||
                             FieldType == "field-type-timestamp") &&
                            FieldOp != CHANGE_CLEARALL ) {

                    // trim times, they confuse javascript
                    FieldValue = FieldValue.replace(
                        / [0-9]{1,2}:[0-9]{2}:[0-9]{2}( AM| PM)?/,'');

                    // see if the date is parsable
                    if (isNaN(Date.parse(FieldValue)) &&
                        FieldValue.toLowerCase() != "now" &&
                        FieldValue != "X-DATE-X" &&
                        FieldValue != "X-DATE-X X-TIME-X") {
                        jQuery('.field-value-edit', this).addClass('mv-form-error');
                        jQuery('.field-value-edit', this).parent().append(
                            '<span style="position: absolute; z-index: 100; background: white;" ' +
                            'class="mv-feui-error mv-form-error">' +
                            'Invalid date format<br/>' +
                            'For a specific, fixed day use YYYY-MM-DD HH:MM:SS<br/>' +
                            'For the current date at midnight, use X-DATE-X<br/>' +
                            'For the current date and time, use either X-DATE-X X-TIME-X or now' +
                            '</span>');
                        rc = false;
                    }
                }
            });

            // if anything went wrong, block the submission
            if (!rc) {
                return false;
            }

            // if everything was okay, remove the template rows before submission
            jQuery(".template_row").remove();

            // make sure that every select contains something to avoid parse problems
            jQuery(".field-value-select:empty").append(
                '<option value="0">--</option>');

            return true;
        });
    });
}());
