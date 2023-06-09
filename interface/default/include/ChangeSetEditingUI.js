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
     * @param subjectField HTML form field selecting which metadata
     *   field should be in use.
     */
    function modifySiblingFields( subjectField ) {
        var field_id = jQuery(":selected", subjectField).attr("value"),
            field_type = jQuery(":selected", subjectField).attr("class").match(/field-type-[a-z]+/),
            topRow = jQuery(subjectField).closest(".field_row"),
            valueSelectField = jQuery('.field-value-select', topRow),
            operatorField = jQuery('.field-operator', topRow);

        // if we don't already have one, snag a copy of the default operators list
        if (!operatorField.data("default_options")){
            operatorField.data("default_options", operatorField.html());
        }
        operatorField.html("").append(operatorField.data("default_options"));

        // remove operators not appropriate for this field type
        jQuery("option:not(."+field_type+")", operatorField).remove();

        // remove 'clear all' from required fields
        if (jQuery(":selected", subjectField).hasClass("required")) {
            jQuery("option[value=3]", operatorField).remove();
        }

        if (field_type == "field-type-flag" || field_type == "field-type-option" ||
            field_type == "field-type-multoption" ) {
            // if  we don't  already  have  one, snag  a  copy of  the
            // default select options list
            if (!valueSelectField.data("default_options")){
                valueSelectField.data("default_options", valueSelectField.html());
            }
            valueSelectField.html("").append(valueSelectField.data("default_options"));

            jQuery("option:not(.field-id-"+field_id+")", valueSelectField).remove();
        }

        showAppropriateEditFields( topRow );
    }

    /**
     * Toggle UI elements to show only appropriate edit fields for a selected field.
     * @param field_row <tr> containing the elements to consider.
     */
    function showAppropriateEditFields(field_row) {
        var subj_field = jQuery('.field-subject', field_row),
            valueSelectField = jQuery('.field-value-select', field_row),
            valueEditField = jQuery('.field-value-edit', field_row),
            valueEdit2Field = jQuery('.field-value-repl', field_row),
            valueQSField = jQuery('.mv-quicksearch', field_row),
            operatorField = jQuery('.field-operator', field_row),
            opType = jQuery(":selected", operatorField).attr("value");

        // for Clear All, nobody gets edit boxes
        if (opType == CHANGE_CLEARALL) {
            valueSelectField.hide();
            valueEditField.hide();
            valueEdit2Field.hide();
            valueQSField.hide();
            // for Find/Replace, we'll need the two text boxes
        } else if (opType == CHANGE_FIND_REPLACE) {
            valueSelectField.hide();
            valueEditField.show();
            valueEdit2Field.show();
            valueQSField.hide();
            // otherwise, determine it by field type
        } else {
            if (jQuery(":selected",subj_field).length==0) {
                return;
            }
            var field_type = jQuery(":selected", subj_field).attr("class").match(/field-type-[a-z]+/);
            if (field_type == "field-type-flag" || field_type == "field-type-option" ||
                field_type == "field-type-multoption") {
                valueSelectField.show();
                valueEditField.hide();
                valueEdit2Field.hide();
                valueQSField.hide();
            } else if (field_type == "field-type-controlledname" ||
                     field_type == "field-type-tree" ||
                     field_type == "field-type-reference") {
                valueQSField.show();

                var field_id = jQuery(":selected", subj_field).attr("value");
                valueQSField.attr("data-fieldid", field_id);

                // if the current widget doesn't yet have a QS setup, fix that
                if (valueQSField.hasClass('mv-quicksearch-template')) {
                    valueQSField.removeClass('mv-quicksearch-template');
                    QuickSearch(valueQSField); // eslint-disable-line no-undef
                }

                valueEditField.hide();
                valueEdit2Field.hide();
                valueSelectField.hide();
            } else {
                valueEditField.show();
                valueEdit2Field.hide();
                valueSelectField.hide();
                valueQSField.hide();
            }
        }
    }

    /**
     * Check if any edit rows are visible, show the 'no fields' message if not.
     */
    function toggleEmptyMessageIfNeeded() {
        // iterate over the empty messages, collecting the section
        // names we need to look for
        var sections = [];
        jQuery('.mv-feui-empty').each(function(){
            sections.push( $(this).data('formname'));
        });

        jQuery.each(sections, function(k, v){
            if (jQuery('.'+v+'.field_row:not(.template_row)').length == 0) {
                jQuery('.'+v+'.mv-feui-empty').show();
            } else {
                jQuery('.'+v+'.mv-feui-empty').hide();
            }
        });
    }

    /**
     * Handle changes to the selected 'subject' field.
     */
    function handleSubjectFieldChange(){
        // grab our table row
        var row = jQuery(this).parent().parent();

        // clear any error fields and remove error messages
        jQuery('.field-value-edit', row).removeClass('mv-form-error');
        jQuery('span.mv-feui-error', row).remove();

        // clear edit/replace values
        jQuery('.field-value-edit', row).attr('value','');
        jQuery('.field-value-repl', row).attr('value','');
        jQuery('.mv-quicksearch-display', row).val('');
        jQuery('.mv-quicksearch-value', row).attr('value', '');

        // rearrange visiable fields as needed
        modifySiblingFields( jQuery(this) );
    }

    // do UI setup
    $(document).ready(function(){
        var subjectFields = jQuery(".field-subject:not(.field-static)");

        // hide elements that should be hidden
        subjectFields.each(function(){
            modifySiblingFields( jQuery(this) );
        });

        toggleEmptyMessageIfNeeded();

        // watch for subject changes, alter field hiding as needed
        subjectFields.change( handleSubjectFieldChange );

        // similarly, watch for operator fields changes and alter field hiding as needed
        var opFields = jQuery(".field-operator");
        opFields.change( function(){
            var topRow = jQuery(this).parent().parent().children();
            showAppropriateEditFields( topRow );
        });

        // handle clicking the add button
        jQuery(".mv-feui-add").click(function(){
            // grab the template row
            var tpl_row = $(this).parent().parent().prev();

            // make a copy of it, set the copy visible
            var new_row = tpl_row.clone(true);
            new_row.removeClass('template_row');
            tpl_row.before(new_row);
            new_row.show();

            modifySiblingFields(new_row);
            jQuery(".field-subject:not(.field-static)", new_row).change(
                handleSubjectFieldChange);

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
                var field_type,
                    field_value = jQuery('.field-value-edit', this).val(),
                    subj_field = jQuery('.field-subject', this),
                    field_op = jQuery('.field-operator option:selected', this).attr('value');

                // extract the field id for the given row
                // if the value of the subject field starts with S_, then we're dealing with
                // a static field row and can pull the class from the input element directly.
                // otherwise, we're dealing with a selectable row, and
                // need to pull the class from the selected element of
                // the subject field.
                if (subj_field.attr('value') !== undefined &&
                    subj_field.attr('value').match(/S_/)) {
                    field_type = jQuery(subj_field).attr("class").match(
                        /field-type-[a-z]+/);
                } else {
                    field_type = jQuery(":selected", subj_field).attr("class").match(
                        /field-type-[a-z]+/);
                }

                // for set and clear on text, url, and paragraph
                // fields, a value is required
                if ((field_op == CHANGE_SET || field_op == CHANGE_CLEAR) &&
                    (field_type == "field-type-text" ||
                     field_type == "field-type-url" ||
                     field_type == "field-type-paragraph") &&
                    (field_value.trim().length == 0)) {
                    jQuery('.field-value-edit', this).parent().append(
                        '<span style="position: absolute;" class="mv-feui-error mv-form-error"' +
                            '>Value cannot be empty</span>');
                    rc = false;

                // number fields should contain ints
                //  if operator is not 'clear all'
                } else if (field_type == "field-type-number" &&
                    field_op != CHANGE_CLEARALL) {
                    if (!field_value.match(/^[0-9]+$/)) {
                        jQuery('.field-value-edit', this).addClass('mv-form-error');
                        jQuery('.field-value-edit', this).parent().append(
                            '<span style="position: absolute;" class="mv-feui-error mv-form-error"' +
                                '>Invalid value: not a whole number</span>');
                        rc = false;
                    }

                // date and timestamp fields should be parsable dates
                //  if operator is not 'clear all'
                }  else if ((field_type == "field-type-date" ||
                          field_type == "field-type-timestamp") &&
                         field_op != CHANGE_CLEARALL ) {

                    // trim times, they confuse javascript
                    field_value = field_value.replace(
                        / [0-9]{1,2}:[0-9]{2}:[0-9]{2}( AM| PM)?/,'');

                    // see if the date is parsable
                    if (isNaN(Date.parse(field_value)) &&
                        field_value.toLowerCase() != "now" &&
                        field_value != "X-DATE-X" &&
                        field_value != "X-DATE-X X-TIME-X") {
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
