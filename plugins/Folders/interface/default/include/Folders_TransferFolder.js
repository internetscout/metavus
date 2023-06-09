/**
 * Folders_TransferFolder.js (Folders plugin)
 *
 * Part of the Metavus digital collections platform
 * Copyright 2015-2021 Edward Almasy and Internet Scout Research Group
 * http://metavus.net
 *
 * Javascript for folder transfer popup, submission.
 *
 * @scout:eslint
 */

/* global CWIS_BASE_URL */

/*
 * Sources of globals:
 * interface/default/include/StdPageStart.html: CWIS_BASE_URL
 */

$(document).ready(function () {
    $("#mv-folders-transfer-folder-popup").css('display', '');

    $("#mv-folders-transfer-folder-popup").dialog({
        buttons: {
            Transfer: function () {
                var folderID = $("#mv-folders-transfer-folder-popup").attr('data-folderid');
                var tgtUser = $.trim($("#mv-folders-transfer-folder-popup-uname-field").val());

                if (tgtUser.length == 0) {
                    return;
                }

                // send out ajax request
                $.ajax({
                    type: "POST",
                    url: CWIS_BASE_URL + "index.php?P=P_Folders_TransferFolders&FID=" + folderID,
                    data: { username: tgtUser },
                    dataType: "html",
                    success: function (data) {
                        var result = JSON.parse(data);

                        if (result.status.state != "OK") {
                            // display error message
                            $("#mv-folders-transfer-folder-popup-success").hide();
                            $("#mv-folders-transfer-folder-popup-error").text(
                                "Error: " + result.status.message);
                            $("#mv-folders-transfer-folder-popup-error").show();
                        } else {
                            // display success message and reload page
                            $("#mv-folders-transfer-folder-popup-error").hide();
                            $("#mv-folders-transfer-folder-popup-success").text(
                                result.status.message);
                            $("#mv-folders-transfer-folder-popup-success").show();
                            setTimeout(function () {
                                window.location.reload();
                            }, 1500);
                        }
                    },
                    error: function (jqXHR, textStatus, errorThrown) {
                        console.log(textStatus, errorThrown);
                    }
                });
            },
            Cancel: function () {
                $(this).dialog("close");
            }
        },
        autoOpen: false
    });

    // set up autocomplete for username search
    $("#mv-folders-transfer-folder-popup-uname-field").autocomplete({
        appendTo: "#mv-folders-transfer-folder-popup-body",
        source: function (request, response) {
            $.get(CWIS_BASE_URL + "index.php?P=P_Folders_UserNameSearchCallback&SS=" + request.term, {
            }, function (data) {
                var result = data;
                response(result);
            });
        },
        select: function (event, ui) {
            $("#mv-folders-transfer-folder-popup-uname-field").val(ui.item.Value);
            return false;
        },
        focus: function (event, ui) {
            $("#mv-folders-transfer-folder-popup-uname-field").val(ui.item.Value);
            return false;
        },
    }).data("ui-autocomplete")._renderItem = function (ul, item) {
        return $("<li></li>")
            .data("item.autocomplete", item)
            .append(item.Label)
            .appendTo(ul);
    };

    // set up click handler for transfer button
    $('.cw-folder-transfer-folder-button').click(function (ev) {
        ev.preventDefault();

        var folderId = $(ev.target).attr('data-folderid'),
            folderName = $(ev.target).attr('data-foldername');

        // pass folder name in to popup
        $("#mv-folders-transfer-folder-popup").attr(
            'data-folderid', folderId);

        // set folder name on popup
        $("#mv-folders-transfer-folder-popup").dialog(
            "option", "title", "Transfer " + folderName);
        $("span.mv-folders-folder-name", "#mv-folders-transfer-folder-popup")
            .text(folderName);

        $("#mv-folders-transfer-folder-popup").dialog("open");
    });
});