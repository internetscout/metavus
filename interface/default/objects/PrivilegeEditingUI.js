/**
 * FILE:  PrivilegeEditingUI.js
 *
 * Part of the Metavus digital collections platform
 * Copyright 2015-2021 Edward Almasy and Internet Scout Research Group
 * http://metavus.net
 *
 * Common javascript routines for privilege editing.  Pages that use
 * the privilege editor should add a <script> element that pulls in
 * this file after their edit elements are displayed.
 *
 * @scout:eslint
 */

(function(){
    var subjectFields = jQuery(".priv-field.priv-field-subject"),
        addButtons = jQuery(".priv-js-add"),
        form = jQuery(".priv-form");

    // remove extra rows used when JavaScript is disabled
    jQuery(".priv-extra").remove();

    // remove the not-set option (--) from each subject field
    jQuery("option:not([value])", subjectFields).remove();

    // all indents based on the conditions
    updateAllIndents();

    // set up the UI
    subjectFields.each(function(){
        var subjectField = jQuery(this),
            parent = subjectField.parent(),
            deleteButton = jQuery("<button>X</button>");

        modifySiblingFields(subjectField);

        deleteButton.addClass("btn btn-primary btn-sm priv-js-delete");
        deleteButton.click(deleteButtonClick);

        parent.prepend(deleteButton);
    });

    // modify the UI when the subject field changes
    subjectFields.change(function(){
        modifySiblingFields(jQuery(this), true);
    });

    // tweak the form before it's submitted
    form.submit(function(){
        // remove clone targets
        jQuery(".priv-js-clone_target").remove();

        // add the not-set options back in
        jQuery(".priv-field.priv-select:not(:has(option))").append("<option>--</option>");
    });

    // show the add buttons
    addButtons.show();

    // add a new condition row when clicking an add button
    addButtons.click(function(){
        var target = jQuery(".priv-js-clone_target", jQuery(this).parent()),
            clone = target.clone(),
            subjectField = jQuery(".priv-field.priv-field-subject", clone),
            operatorField = subjectField.siblings(".priv-field.priv-field-operator"),
            valueSelectorField = subjectField.siblings(".priv-field-value.priv-select"),
            deleteButton = subjectField.siblings(".priv-js-delete");

        // remove the class that is used to tag the clone target
        clone.removeClass("priv-js-clone_target");

        // randomly select a subject, but not a set one
        var Subjects = jQuery("option:not(.priv-type-set_entry, .priv-type-set_exit)", subjectField),
            RandomIndex = Math.floor(Math.random() * Subjects.length);
        Subjects.eq(RandomIndex).attr("selected", "selected");

        // copy the original options over from the clone target
        operatorField.data(
            "priv-default_options",
            jQuery(".priv-field.priv-field-operator", target).data("priv-default_options"));
        valueSelectorField.data(
            "priv-default_options",
            jQuery(".priv-field.priv-field-value.priv-select", target).data("priv-default_options"));

        // set the subject field to automatically modify its siblings when its
        // changed
        subjectField.change(function(){
            modifySiblingFields(jQuery(this), true);
        });

        // trigger a change immediately to update the siblings
        subjectField.change();

        // insert the clone into the DOM
        clone.insertBefore(target);

        // add the button to delete the row
        deleteButton.click(deleteButtonClick);

        // update all indents for the conditions
        updateAllIndents();

        // don't let the button submit a form or otherwise go somewhere
        return false;
    });

    // capture Enter keypress inside our form, stop it from doing things
    jQuery(".priv-form").bind('keypress', function(e){
        if (e.target.tagName != 'TEXTAREA') {
            if (e.keyCode == 13) {
                e.preventDefault();
                return;
            }
        }
    });

    /**
    * Handle delete button click, calling removeCondition() to erase
    * the appropriate html elements.
    */
    function deleteButtonClick() {
        removeCondition(jQuery(this).closest(".priv-fieldset"));
        return false;
    }

    /**
    * Delete a privilege condition from the UI.
    * @param fieldset html fieldset element containing the row to be
    * deleted
    */
    function removeCondition(fieldset) {
        var direction, sibling, level;
        var subjectFieldValue = fieldset.children(".priv-field.priv-field-subject").val();

        // remove the matching set entry or exit fieldset if removing one
        if (subjectFieldValue == "set_entry" || subjectFieldValue == "set_exit") {
            direction = subjectFieldValue == "set_entry" ? "next" : "prev";
            level = 1;

            // the first sibling is the sibling of the fieldset
            sibling = fieldset[direction]();

            // while there is a sibling to get
            do {
                // change the nesting level if moving into or out of a set
                if (sibling.is(":has(.priv-field.priv-field-subject option:selected[value='set_entry'])")) {
                    level += direction == "next" ? 1 : -1;
                } if (sibling.is(":has(.priv-field.priv-field-subject option:selected[value='set_exit'])")) {
                    level += direction == "prev" ? 1 : -1;
                }

                // remove this sibiling if we've reached the top level again
                if (level === 0) {
                    sibling.remove();
                    break;
                }

                // get the next fieldset
                sibling = sibling[direction]();
            } while (sibling.length > 0);
        }

        // finally, remove the condition specified to be removed
        fieldset.remove();

        // update all indents for the conditions
        updateAllIndents();
    }


    /**
    * Iterate over all privilege sets, fixing their indentation after
    * addition/removal of a subgroup.
    */
    function updateAllIndents() {
        var sets = jQuery(".priv-set");

        // remove messages stating that a privilege set is unbalanced. these
        // messages will be re-added later if necessary
        jQuery(".priv-js-unbalanced").remove();

        sets.each(function(){
            var level = updateIndent(jQuery(".priv-fieldset:not(.priv-logic, .priv-extra, .priv-js-clone_target)", this));

            // add a warning if the set is unbalanced
            if (level !== 0) {
                jQuery(this).append(getUnbalancedMessage());
            }
        });
    }

    /**
    * Get message to display when the number of parens is not
    * balanced.
    */
    function getUnbalancedMessage() {
        return "<p class='mv-form-error priv-js-unbalanced'>" +
               "<strong>Warning</strong>: This set of privileges is unbalanced, " +
               "i.e., the number of opening parentheses does not match the number " +
               "of closing parentheses." +
            "</p>";
    }

    /**
    * Fix indentation for a single privilege set (e.g. EditingPrivileges).
    * @param filedsetsInOrder html elements for this privilege set
    */
    function updateIndent(fieldsetsInOrder) {
        var level = 0,
            indentPerLevel = 22;

        fieldsetsInOrder.each(function(){
            var subjectField = jQuery(this).children(".priv-field.priv-field-subject"),
                subjectFieldValue = subjectField.val();

            // go out a level
            if (subjectFieldValue == "set_exit") {
                level--;
            }

            subjectField.css("margin-left", (Math.max(0, level*indentPerLevel))+"px");

            // go in a level
            if (subjectFieldValue == "set_entry") {
                level++;
            }
        });

        return level;
    }

    /**
    * Hide/show UI elements as appropriate based on the selected
    * field to use for a privilege condition.
    * @param subjectField html element for the subject field
    * @param resetValues bool TRUE to remove existing selections and
    * reset to defaults
    */
    function modifySiblingFields(subjectField, resetValues) {
        var conditionClass = jQuery(":selected", subjectField).attr("class").match(/priv-type-[a-z0-9_]+/),
            operatorField = subjectField.siblings(".priv-field.priv-field-operator"),
            valueSelectorField = subjectField.siblings(".priv-field.priv-field-value.priv-select"),
            valueInputField = subjectField.siblings(".priv-field.priv-field-value.priv-input");

        // set the data to hold the original options if not already set
        if (!operatorField.data("priv-default_options")) {
            operatorField.data("priv-default_options", operatorField.html());
        }

        // set the data to hold the original options if not already set
        if (!valueSelectorField.data("priv-default_options")) {
            valueSelectorField.data("priv-default_options", valueSelectorField.html());
        }

        // reset the sibling fields
        operatorField.html("").append(operatorField.data("priv-default_options"));
        valueSelectorField.html("").append(valueSelectorField.data("priv-default_options"));

        // remove existing selections and values if requested
        if (resetValues) {
            jQuery(":selected", operatorField).removeAttr("selected");
            jQuery(":selected", valueSelectorField).removeAttr("selected");
            valueInputField.val("");
        }

        // for option fields
        if (conditionClass == "priv-type-option_field") {
            // hide option values that are not associated with this field
            var field_id = jQuery(":selected", subjectField).val();
            jQuery("option[data-field-id!='"+field_id+"']", valueSelectorField).remove();
        }

        // remove options that don't apply, including the not-set options (--)
        jQuery("option:not(."+conditionClass+"), option:not([value])", operatorField).remove();
        jQuery("option:not(."+conditionClass+"), option:not([value])", valueSelectorField).remove();

        // make sure the sibling fields are visible. they might get hidden below
        operatorField.show();
        valueSelectorField.show();
        valueInputField.show();

        // tweak the fields and option labels based on the condition class
        if (conditionClass == "priv-type-user_field") {
            jQuery("option[value='==']", operatorField).html("is current user");
            jQuery("option[value='!=']", operatorField).html("is not current user");
            valueSelectorField.hide();
            valueInputField.hide();
        } else if (conditionClass == "priv-type-flag_field") {
            jQuery("option[value='==']", operatorField).html("is true");
            jQuery("option[value='!=']", operatorField).html("is false");
            valueSelectorField.hide();
            valueInputField.hide();
        } else if (conditionClass == "priv-type-have_resource") {
            jQuery("option[value='==']", operatorField).html("is true");
            jQuery("option[value='!=']", operatorField).html("is false");
            valueSelectorField.hide();
            valueInputField.hide();
        } else if (conditionClass == "priv-type-option_field") {
            jQuery("option[value='==']", operatorField).html("contains");
            jQuery("option[value='!=']", operatorField).html("does not contain");
            valueInputField.hide();
        } else if (conditionClass == "priv-type-timestamp_field") {
            jQuery("option[value='<']", operatorField).html("is before");
            jQuery("option[value='>']", operatorField).html("is after");
            valueSelectorField.hide();
        } else if (conditionClass == "priv-type-date_field") {
            jQuery("option[value='<']", operatorField).html("is before");
            jQuery("option[value='>']", operatorField).html("is after");
            valueSelectorField.hide();
        } else if (conditionClass == "priv-type-number_field") {
            jQuery("option[value='==']", operatorField).html("equals");
            jQuery("option[value='!=']", operatorField).html("does not equal");
            jQuery("option[value='<']", operatorField).html("is less than");
            jQuery("option[value='>']", operatorField).html("is greater than");
            valueSelectorField.hide();
        } else if (conditionClass == "priv-type-privilege") {
            jQuery("option."+conditionClass, valueSelectorField).prepend("has ");
            operatorField.hide();
            valueInputField.hide();
        } else if (conditionClass == "priv-type-set_entry") {
            jQuery("option[value='AND']", valueSelectorField).html("uses AND logic");
            jQuery("option[value='OR']", valueSelectorField).html("uses OR logic");
            operatorField.hide();
            valueInputField.hide();
        } else if (conditionClass == "priv-type-set_exit") {
            operatorField.hide();
            valueSelectorField.hide();
            valueInputField.hide();
        }

        // update all indents for the conditions
        updateAllIndents();
    }
}());
