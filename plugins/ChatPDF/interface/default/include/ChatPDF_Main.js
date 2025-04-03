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

/**
 * Reads the editing form for the current page and extracts
 * all file IDs from all file fields.
 * @return array keyed by field name where values are arrays of file
 *   IDs associated with that field.
 */
function getValidFileIdsByFieldName() {
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
 * Appends messages for fields that were populated and skipped
 * to the container for a dialog. This is called after the manual
 * button press.
 * @param HtmlElement DialogContainer The container element to append to.
 * @param array Fields The list of fields to display, stored as strings
 * of their labels. May be empty if no fields were populated or skipped.
 * @param string Message The message to put above the list.
 */
function addMessagesToDialog(DialogContainer, Fields, Message) {
    if (Fields.length !== 0) {
        $(Message).appendTo(DialogContainer);
        var FieldList = $(document.createElement('ul'));
        for (let Field of Fields) {
            var FieldNode = document.createElement('li');
            FieldNode.appendChild(document.createTextNode(Field));
            FieldList.append($(FieldNode));
        }
        DialogContainer.append(FieldList);
    }
}

/**
 * Process the record whose editing page the button is on.
 * @param Event The event for clicking this button.
 */
// eslint-disable-next-line no-unused-vars
function handleManualButton(event) {
    // disable button while processing
    var Button = $(event.target);
    Button.attr('disabled', true);

    // indicate on frontend that we're processing
    var LoadingGif = Button.siblings('span');
    LoadingGif.show();

    // get all valid file IDs from current form data
    var FileIds = getValidFileIdsByFieldName();
    if (Object.keys(FileIds).length === 0) {
        // tell user that there's no files uploaded to this record,
        // regardless of belonging to a configured file field
        $('<p>Error: There are no new files found for this record.</p>').dialog({
            title: 'ChatPDF',
        });
        return;
    }

    // POST to PHP page with the record ID and file IDs
    $.ajax({
        method: "POST",
        url: "index.php?P=P_ChatPDF_ManualUpload",
        data: { RecordId: RecordId, FileIds: FileIds },
        error: ajaxErrorLogger,
        success: function (Data) {
            // indicate on frontend that we're done processing
            LoadingGif.hide();

            // re-enable the button
            Button.attr('disabled', false);

            // create dialog container for success and error messages
            var DialogContainer = $(document.createElement('div'));
            var DialogOptions = {
                title: 'ChatPDF',
                create: function(event) {
                    $(event.target).parent().css('position', 'fixed');
                },
            };

            // abort if we have an error message
            Data = JSON.parse(Data);
            if (Data['status'] === 'Error') {
                // display error message in dialog
                var ErrorMessage = Data['message'];
                DialogContainer.html(`<p>Error: ${ErrorMessage}</p>`);
                DialogContainer.dialog(DialogOptions);

                // disable upload button if quota will be exceeded
                if (ErrorMessage.includes('quota')) {
                    Button.attr('disabled', false);
                }
                return;
            }

            // only populate empty fields
            var PopulatedFields = [];
            var SkippedFields = [];
            for (let FieldName in Data) {
                // differentiate between CKEDITOR and regular textarea fields
                const HtmlId = `F_RecEditingUI_${RecordId}_${FieldName}`;
                var FieldLabel = $(`label[for="${HtmlId}"]`).text();
                if (HtmlId in CKEDITOR.instances) {
                    var Editor = CKEDITOR.instances[HtmlId];
                    if (Editor.getData().length !== 0) {
                        SkippedFields.push(FieldLabel);
                    } else {
                        Editor.insertHtml(Data[FieldName]);
                        PopulatedFields.push(FieldLabel);
                    }
                } else {
                    if ($(`#${HtmlId}`).val().length !== 0) {
                        SkippedFields.push(FieldLabel);
                    } else {
                        $(`#${HtmlId}`).val(Data[FieldName]);
                        PopulatedFields.push(FieldLabel);
                    }
                }
            }

            // show the user the fields that were populated or skipped
            addMessagesToDialog(
                DialogContainer,
                PopulatedFields,
                '<p>The following fields were populated by ChatPDF:</p>'
            );
            addMessagesToDialog(
                DialogContainer,
                SkippedFields,
                '<p>The following non-empty fields were skipped by ChatPDF:</p>'
            );
            DialogContainer.dialog(DialogOptions);
        },
    });
}

/**
 * Generic logger for AJAX errors.
 */
function ajaxErrorLogger(JqXHR, TextStatus, ErrorThrown) {
    console.log(
        "Error sending Ajax request at " +
        (new Error()).stack +
        TextStatus + " " + ErrorThrown + " " + JqXHR.responseText);
}

/**
 * Set visibility of the ChatPDF upload button based on the presence
 * of files in configured fields.
 */
function toggleChatPDFButtonVisibility() {
    let FileIds = getValidFileIdsByFieldName();
    for (let FieldName of ChatPDF_ConfiguredFields) {
        if (FieldName in FileIds) {
            $(".mv-p-chatpdf-upload-button").show();
            return;
        }
    }

    $(".mv-p-chatpdf-upload-button").hide();
}

$(document).ready(function() {
    toggleChatPDFButtonVisibility();
});
