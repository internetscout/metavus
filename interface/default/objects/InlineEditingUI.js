/**
 * FILE:  InlineEditingUI.js
 *
 * Part of the Metavus digital collections platform
 * Copyright 2020 Edward Almasy and Internet Scout Research Group
 * http://metavus.net
 *
 * JavaScript support for InilneEditingUI
 * @scout:eslint
 */

$(document).ready(function(){
    'use strict';

    // store old content so that we can restore it when edits are discarded
    var oldContent = [], hasChanges = [];

    /**
     * Call user-defined handlers for inline editing events.
     * @param HtmlElement controlsContainer Editing controls for the active editor.
     * @param string eventName Name of the event to handle (one of
     *   edit, change, save, discard, cancel)
     */
    function handleEvent(controlsContainer, eventName) {
        var editorNo = $(controlsContainer).data('editor'),
            controlsSelectors = inlineEditControlsSelectors[editorNo]; // eslint-disable-line no-undef

        // hide the controls for other events
        $.each(controlsSelectors, function(index, selectors) {
            if (index != eventName) {
                selectors.forEach(function(selector) {
                    $(selector).hide();
                });
            }
        });

        // show the selectors for this event
        controlsSelectors[eventName].forEach(function(selector){
            $(selector).show();
        });
    }

    /**
     * Discard changes made in this editing session, restoring the
     *   original content and exiting editing mode.
     * @param HtmlElement controlsContainer Editing controls for the active editor.
     */
    function discardChanges(controlsContainer) {
        var editorNo = $(controlsContainer).data('editor');

        // if content has changed, restore old content
        if (hasChanges[editorNo]) {
            var editorInstance = CKEDITOR.instances['mv-inline-'+editorNo] ; //eslint-disable-line no-undef

            editorInstance.setData(oldContent[editorNo]);
            oldContent[editorNo] = null;
            hasChanges[editorNo] = false;
        }

        $(".mv-inline-edit-display[data-editor='"+editorNo+"']").show();
        $(".mv-inline-edit-edit[data-editor='"+editorNo+"']").hide();

        $(".mv-inline-edit-btn-edit", controlsContainer).show();
        $(".mv-inline-edit-btn-save", controlsContainer).hide();
        $(".mv-inline-edit-btn-cancel", controlsContainer).hide();
        $(".mv-inline-edit-btn-discard", controlsContainer).hide();
    }

    /**
     * Update page HTML after an AJAX save request completes
     * @param int editorNo Editor serial number to update.
     * @param array data Ajax response.
     */
    function postSaveCallback(editorNo, data) {
        var controlsContainer = $(".mv-inline-edit-controls[data-editor='"+editorNo+"']"),
            editorContainer = $(".mv-inline-edit-edit[data-editor='"+editorNo+"']"),
            displayContainer = $(".mv-inline-edit-display[data-editor='"+editorNo+"']"),
            errElement = $(".mv-inline-edit-error", editorContainer);

        // on success
        if (data.status == "OK") {
            // update the displayed html
            $(".mv-inline-edit-content", displayContainer).html(data.content);

            // perform any additional updates
            if ("updates" in data) {
                data.updates.forEach(function(update){
                    $(update.selector).html(update.html);
                });
            }

            // clear old content
            oldContent[editorNo] = null;
            hasChanges[editorNo] = false;

            // hide edit, show display
            editorContainer.hide();
            displayContainer.show();
            $(".mv-inline-edit-btn-edit", controlsContainer).show();

            handleEvent(controlsContainer, 'onsave');
        } else {
            // on error, display the provided error message
            $(errElement).show();
            $(errElement).html(
                "Could not save changes: " + data.message
            );
        }
    }

    /**
     * If the user has unsaved changes on the page and tries to
     * navigate away, warn them that their changes may be lost.
     * @see https://developer.mozilla.org/en-US/docs/Web/API/Window/beforeunload_event
     */
    $(window).on('beforeunload', function(event) {
        var unsavedChanges = false;

        // iterate over all our editors looking to see if any have changes
        hasChanges.forEach(function(value){
            if (value) {
                unsavedChanges = true;
            }
        });

        // if any had changes, warn the user about leaving the page
        if (unsavedChanges) {
            event.preventDefault();
            // attempt to display a custom message for the user, but
            // per the above-linked MDN article "Some browsers used to
            // display the returned string in the confirmation dialog,
            // enabling the event handler to display a custom message
            // to the user. However, this is deprecated and no longer
            // supported in most browsers."
            event.returnValue = "Page content has been modified. "+
                "Leaving the page will discard changes.";
        }
    });

    /**
     * Set up a confirmation dialog for when the user pushes the
     * "Discard" button while editing
     */
    $("#mv-inline-edit-dialog-confirm").dialog({
        resizable: false,
        modal: true,
        autoOpen: false,
        buttons: [
            {
                text: "Discard",
                icon: "ui-icon-close",
                click: function() {
                    var controlsContainer = $("#mv-inline-edit-dialog-confirm").data('controls');
                    discardChanges(controlsContainer);
                    $("#mv-inline-edit-dialog-confirm").removeData('controls');
                    $("#mv-inline-edit-dialog-confirm").dialog('close');

                    handleEvent(controlsContainer, 'ondiscard');
                }
            },
            {
                text: "Cancel",
                click: function() {
                    $("#mv-inline-edit-dialog-confirm").dialog('close');
                }
            }
        ]
    });

    /**
     * Set up the 'Edit Inline' button so that it will activate and
     * focus the editing interface.
     */
    $(".mv-inline-edit-btn-edit").click(function(){
        var controlsContainer = $(this).parent(),
            editorNo = $(controlsContainer).data('editor'),
            editorInstance = CKEDITOR.instances['mv-inline-'+editorNo]; //eslint-disable-line no-undef

        oldContent[editorNo] = editorInstance.getData();
        hasChanges[editorNo] = false;

        $(".mv-inline-edit-display[data-editor='"+editorNo+"']").hide();
        $(".mv-inline-edit-edit[data-editor='"+editorNo+"']").show();
        $(".mv-inline-edit-edit[data-editor='"+editorNo+"'] .cke_editable_inline").focus();

        $(".mv-inline-edit-btn-edit", controlsContainer).hide();
        $(".mv-inline-edit-btn-cancel", controlsContainer).show();

        var showSaveBtn = function() {
            hasChanges[editorNo] = true;

            $(".mv-inline-edit-btn-cancel", controlsContainer).hide();
            $(".mv-inline-edit-btn-save", controlsContainer).show();
            $(".mv-inline-edit-btn-discard", controlsContainer).show();

            editorInstance.removeListener(
                'change',
                showSaveBtn
            );

            handleEvent(controlsContainer, 'onchange');
        };

        editorInstance.on('change', showSaveBtn);

        handleEvent(controlsContainer, 'onedit');
    });

    /**
     * Handle clicks on the 'Cancel' button.
     */
    $(".mv-inline-edit-btn-cancel").click(function() {
        var controlsContainer = $(this).parent();
        discardChanges(controlsContainer);
        handleEvent(controlsContainer, 'oncancel');
    });

    /**
     * Handle clicks on the 'Discard' button.
     */
    $(".mv-inline-edit-btn-discard").click(function() {
        var controlsContainer = $(this).parent();
        $("#mv-inline-edit-dialog-confirm").data('controls', controlsContainer);
        $("#mv-inline-edit-dialog-confirm").dialog("open");
    });

    /**
     * Handle clicks on the 'Save' button.
     */
    $(".mv-inline-edit-btn-save").click(function(){
        var controlsContainer = $(this).parent(),
            editorNo = $(controlsContainer).data('editor'),
            editorContainer = $(".mv-inline-edit-edit[data-editor='"+editorNo+"']"),
            updateUrl = $(editorContainer).data('updateurl'),
            errElement = $(".mv-inline-edit-error", editorContainer),
            newContent = CKEDITOR.instances['mv-inline-'+editorNo].getData(); //eslint-disable-line no-undef

        // hide any previous errors and buttons
        $(errElement).hide();
        $("button", controlsContainer).hide();
        $(".mv-loading", controlsContainer).show();

        // post our updated content to the provided update URL
        $.ajax({
            url: updateUrl,
            method: "POST",
            data: { Content: newContent },
            success: function(data) {
                // hide loading indicators
                $(".mv-loading", controlsContainer).hide();
                postSaveCallback(editorNo, data);
            },
            error: function(jqXHR, textStatus, errorThrown) {
                console.log(
                    "Error sending AJAX request at " +
                        (new Error()).stack +
                        textStatus + " " + errorThrown + " " + jqXHR.responseText);
            }
        });
    });
});
