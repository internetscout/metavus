/**
 * FILE: ChatPDF_Main.js (ChatPDF plugin)
 *
 * Part of the Metavus digital collections platform
 * Copyright 2024-2025 Edward Almasy and Internet Scout Research Group
 * http://metavus.net
 *
 * Contains main Javascript functionality for ChatPDF plugin's
 * manual upload button
 *
 * @scout:eslint
 */

/* global RecordId, CKEDITOR, ChatPDF_ConfiguredFields, ChatPDF_AllowedFileTypes */

/* Sources of globals:
 * EditResource.html (Metavus): RecordId
 */

class ChatPDF {
    /**
     * Set up the jquery-ui dialog used to report results back to the
     * user. The HTML for the dialog contains placeholders for
     * messages about four kinds of things:
     *
     * 1) global errors that prevented any questions from being asked
     *    at all (.mv-p-chatpdf-global-error),
     *
     * 2) fields where the corresponding question was successfully asked
     *    and the destination field has been populated with the answer
     *    (.mv-p-chatpdf-populated)
     *
     * 3) fields where the corresponding question was successfully
     *    asked but the destination field already contained data
     *    (.mv-p-chatpdf-skipped)
     *
     * 4) errors pertaining to specific fields / questions
     *   (.mv-p-chatpdf-field-error)
     *
     * These placeholders also all bear the .mv-p-chatpdf-message
     *  class. In 2-4, there's a <ul> in the container into which
     *  messages about individual fields should be inserted as <li>
     *  elements.
     */
    static setUpDialog() {
        $("#mv-p-chatpdf-dialog").dialog( {
            title: 'ChatPDF',
            autoOpen: false
        });
    }

    /**
     * Handle ChatPDF button press
     * @param Event The event for clicking this button.
     */
    // eslint-disable-next-line no-unused-vars
    static handleManualButton(event) {
        // disable button while processing
        var Button = $(event.target);
        Button.attr('disabled', true);

        // indicate on frontend that we're processing
        var LoadingGif = Button.siblings('span');
        LoadingGif.show();

        // get all valid file IDs from current form data
        var FileIds = ChatPDF.getValidFileIdsByFieldName();
        // if there were no files, error out
        if (Object.keys(FileIds).length === 0) {
            ChatPDF.reportGlobalError(
                'There are no new files found for this record.'
            );
            Button.attr('disabled', false);
            return;
        }

        // POST to PHP page with the record ID and file IDs
        $.ajax({
            method: "POST",
            url: "index.php?P=P_ChatPDF_ManualUpload",
            data: { RecordId: RecordId, FileIds: FileIds },
            error: ChatPDF.ajaxErrorLogger,
            success: function (Data) {
                // indicate on frontend that we're done processing
                LoadingGif.hide();

                // re-enable the button
                Button.attr('disabled', false);

                // abort if we have an error message
                Data = JSON.parse(Data);
                if (Data['status'] === 'error') {
                    // display error message in dialog
                    let ErrorMessage = Data['message'];
                    ChatPDF.reportGlobalError(ErrorMessage);

                    // disable upload button if quota will be exceeded
                    if (ErrorMessage.includes('quota')) {
                        Button.attr('disabled', false);
                    }
                    return;
                }

                ChatPDF.handleSuccessResponse(RecordId, Data);
            },
        });
    }

    /**
     * Set visibility of the ChatPDF upload button based on the presence
     *   of files in configured fields.
     */
    static toggleChatPDFButtonVisibility() {
        let FileIds = ChatPDF.getValidFileIdsByFieldName();
        for (let FieldName of ChatPDF_ConfiguredFields) {
            if (FieldName in FileIds) {
                $(".mv-p-chatpdf-upload-button").show();
                return;
            }
        }

        $(".mv-p-chatpdf-upload-button").hide();
    }

    /**
     * Update ChatPDF dialog to report a global error that prevented
     *   any questions from being issued to the service. Will hide the
     *   other kinds of messages inside the dialog.
     * @param string Message Error message.
     */
    static reportGlobalError(Message) {
        $(".mv-p-chatpdf-message", "#mv-p-chatpdf-dialog").hide();
        $(".mv-p-chatpdf-global-error", "#mv-p-chatpdf-dialog").show();
        $(".mv-p-chatpdf-global-error", "#mv-p-chatpdf-dialog").html(
            "<p>" + Message + "</p>"
        );
        $("#mv-p-chatpdf-dialog").dialog("open");
    }

    /**
     * Update ChatPDF dialog to report the status of a series of
     * questions. Clears messages from previous button presses by
     * deleting their <li>s, hides the global error container, shows
     * any of the other containers that have messages in them.
     * @param array PopulatedFields Names of fields that were populated.
     * @param array SkippedFields Names of fields that were skipped.
     * @param array FieldErrors Error messages.
     */
    static reportStatus(PopulatedFields, SkippedFields, FieldErrors) {
        $(".mv-p-chatpdf-message", "#mv-p-chatpdf-dialog").hide();
        $("li", "#mv-p-chatpdf-dialog").remove();

        let MessageMap = {
            ".mv-p-chatpdf-populated" : PopulatedFields,
            ".mv-p-chatpdf-skipped" : SkippedFields,
            ".mv-p-chatpdf-field-error" : FieldErrors
        };
        $.each(MessageMap, function(Selector, Messages) {
            $(Selector, "#mv-p-chatpdf-dialog").hide();
            for (let Message of Messages) {
                $(Selector + " ul", "#mv-p-chatpdf-dialog").append(
                    $("<li>" + Message + "</li>")
                );
                $(Selector, "#mv-p-chatpdf-dialog").show();
            }
        });
        $("#mv-p-chatpdf-dialog").dialog("open");
    }

    /**
     * Parse the success JSON from an AJAX call and update the UI
     * accordingly.
     * @param int RecordID Record being processed
     * @param array Data JSON response data.
     */
    static handleSuccessResponse(RecordID, Data) {
        // only populate empty fields
        var PopulatedFields = [];
        var SkippedFields = [];
        var Errors = [];

        // iterate over the results we got back for each field
        for (let FieldName in Data) {
            let HtmlId = `F_RecEditingUI_${RecordId}_${FieldName}`;
            let FieldLabel = $(`label[for="${HtmlId}"]`).text();
            var FieldValue = "";

            // iterate over the results for each file
            for (let FileId in Data[FieldName]) {
                if (Data[FieldName][FileId]["status"] == "success") {
                    // combine the successful responses
                    if (FieldValue.length > 0) {
                        FieldValue += "\n\n";
                    }
                    FieldValue += Data[FieldName][FileId]["response"];
                } else {
                    // and collect the errors
                    Errors.push(
                        FieldLabel + " (" + FileId + "): " +
                            Data[FieldName][FileId]["message"]
                    );
                }
            }

            // if we have any successful responses for this field
            if (FieldValue.length > 0){
                // if this field is a CKEditor instance
                if (HtmlId in CKEDITOR.instances) {
                    // use CKE's methods to update the content
                    var Editor = CKEDITOR.instances[HtmlId];
                    if (Editor.getData().length !== 0) {
                        SkippedFields.push(FieldLabel);
                    } else {
                        Editor.insertHtml(FieldValue);
                        PopulatedFields.push(FieldLabel);
                    }
                } else {
                    // otherwise regular jquery methods will suffice
                    if ($(`#${HtmlId}`).val().length !== 0) {
                        SkippedFields.push(FieldLabel);
                    } else {
                        $(`#${HtmlId}`).val(FieldValue);
                        PopulatedFields.push(FieldLabel);
                    }
                }
            }
        }

        // tell the user what we did
        ChatPDF.reportStatus(PopulatedFields, SkippedFields, Errors);
    }

    /**
     * Reads the editing form for the current page and extracts
     * all file IDs from all file fields.
     * @return array keyed by field name where values are arrays of file
     *   IDs associated with that field.
     */
    static getValidFileIdsByFieldName() {
        var FileIds = {};
        // iterate over all the files contained in a RecordEditingUI form on this page
        $(".mv-form-filefield-value", '.mv-reui-form').each(function(Index, Element){
            let FieldName = $(Element).parents('.mv-form-filefield-table').first().data("fieldname");
            let FileId = $(Element).data("fileid");
            let FileType = $(Element).data("filetype");
            if (ChatPDF_AllowedFileTypes.includes(FileType)) {
                if (!(FieldName in FileIds)) {
                    FileIds[FieldName] = [];
                }
                FileIds[FieldName].push(FileId);
            }
        });

        return FileIds;
    }

    /**
     * Generic logger for AJAX errors.
     */
    static ajaxErrorLogger(JqXHR, TextStatus, ErrorThrown) {
        console.log(
            "Error sending Ajax request at " +
                (new Error()).stack +
                TextStatus + " " + ErrorThrown + " " + JqXHR.responseText);
    }
}

$(document).ready(function() {
    ChatPDF.toggleChatPDFButtonVisibility();
    ChatPDF.setUpDialog();
});
