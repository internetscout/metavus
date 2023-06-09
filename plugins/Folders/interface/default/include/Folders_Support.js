/**
 * FILE:  Folders_Support.js (Folders plugin)
 *
 * Part of the Metavus digital collections platform
 * Copyright 2021 Edward Almasy and Internet Scout Research Group
 * http://metavus.net
 *
 * Javascript classes for the Folders plugin.
 * @scout:eslint
 */

/* global CWIS_BASE_URL, Folders_RemoveIcon, Folders_AddIcon */

/*
 * Sources of globals:
 * interface/default/include/StdPageStart.html: CWIS_BASE_URL
 */

// Ensure all of the folders and their items have loaded.
$(document).ready(function () {
    // Folder Clearing Popup
    $(".mv-folders-clear-confirmlink").each(function (ix, val) {
        createClearClickCallback(
            val.getAttribute('data-folderid'));
    });

    // Function to set up the click callbacks to avoid issues with closures in loops.
    // @param folderid the id of the folder
    // @param a the link object
    function createClearClickCallback(folderid) {
        var div_id = "#mv-folders-folderclear" + folderid,
            link_id = "#mv-folders-folderlink" + folderid;

        // set up dialog
        $(div_id).dialog({
            buttons: {
                "Confirm": function () {
                    //Confirm clicks lead to a folder clear
                    window.location.href = $(link_id).attr('href');
                },
                "Cancel": function () {
                    $(this).dialog("close");
                }
            },
            modal: true,
            autoOpen: false
        });

        $(link_id).click(function (e) {
            e.preventDefault();

            // open the dialog box when the link is clicked
            $(div_id).dialog("open");
        });
    }

    // Name Editing Popup
    $(".mv-folders-editnamelink").each(function (ix, val) {
        createNameClickCallback(
            val.getAttribute('data-folderid'));
    });

    // Function to set up the click callbacks to avoid issues with closures in loops.
    // @param folderid the id of the folder
    // @param a the link object
    function createNameClickCallback(folderid) {
        //Set set up dialog
        $("#mv-folders-namechange" + folderid).dialog({
            buttons: {
                "Change Name": function () {
                    //Confirm clicks lead to a name edit
                    //click callback function handles this
                    Folders_FoldersRenameFolderClickCallback(folderid);
                },
                "Cancel": function () {
                    $(this).dialog("close");
                }
            },
            modal: true,
            autoOpen: false
        });

        var foldername = $("#mv-folders-namechange" + folderid).attr("data-foldername");
        $("span.ui-dialog-title", $("#mv-folders-namechange" + folderid).parent())
            .append("<i>" + foldername + "</i>");

        $("#mv-folders-foldernamelink" + folderid).click(function (e) {
            e.preventDefault();
            // open the dialog when the link is clicked
            $("#mv-folders-namechange" + folderid).dialog("open");
        });
    }

    $(".mv-folders-addresource").click(Folders_ResourceButtonClickCallback);
    $(".mv-folders-removeresource").click(Folders_ResourceButtonClickCallback);

    $(".mv-folders-addallsearch").click(Folders_AddAllSearchButtonClickCallback);
    $(".mv-folders-removeallsearch").click(Folders_RemoveAllSearchButtonClickCallback);

    // align tags
    var width = 0;
    $(".mv-resourcesummary-resourcetype-tag").each(function () {
        width = Math.max($(this).width(), width);
    });
    $(".mv-resourcesummary-resourcetype-tag").css("width", width + "px");
});

/**
 * perform AJAX request to rename a given folder.
 */
function Folders_FoldersRenameFolderClickCallback(FolderId) {
    var FolderName = $("input", "#mv-folders-namechange" + FolderId).val();
    var ChangeURL = CWIS_BASE_URL + "index.php?P=P_Folders_UpdateFolderName" +
        "&FolderId=" + FolderId +
        "&FolderName=" + encodeURIComponent(FolderName);

    $.ajax({
        type: "POST",
        url: String(ChangeURL),
        data: { folderid: FolderId, foldername: FolderName },
        dataType: 'text',
        success: function (data) {
            var dataobject = data.replace('1', '');
            dataobject = jQuery.parseJSON(dataobject);
            var response = dataobject["status"]["message"];
            if (response.length > 0) {
                // if we have an error, show it
                $("#Folders_Errors" + FolderId).html(
                    "<br/>" + dataobject["status"]["message"] + "<br/><br/>");
                $("#Folders_Errors" + FolderId).css("color", "red");
            } else {
                //display the success message and update page content
                $("#mv-folders-namechange" + FolderId).dialog("close");
                FoldersUpdateSidebarContent();
                $("#mv-folders-nametitle" + FolderId).text(FolderName);
                $("span.ui-dialog-title > i", $("#mv-folders-namechange" + FolderId).parent())
                    .text(FolderName);
            }
        },
        error: function (jqXHR, textStatus, errorThrown) {
            console.log(textStatus, errorThrown);
        }
    });
}

// respond to a click on a resource add button. Makes a call to the AddItem page, adds the
//item to the current folder, and updates the page with success or rejection message
function Folders_ResourceButtonClickCallback(event) {
    // ensure that we don't go straight to the href - we interupt and process via AJAX if
    // javascript is available
    event.preventDefault();

    // get the button that was clicked
    var target = $(event.currentTarget);

    // extract data from the button
    var verb = target.data('verb'),
        itemId = target.data('itemid'),
        folderId = target.data('folderid'),
        buttonLocation = target.offset(),
        buttonHeight = target.height();

    // construct the URL we'll need for our AJAX requests
    var ajaxUrl = CWIS_BASE_URL +
        "index.php?P=P_Folders_" + verb + "Item" +
        "&ItemId=" + itemId +
        "&FolderId=" + folderId;

    // make the Ajax call
    $.ajax({
        type: "POST",
        url: ajaxUrl,
        data: { itemid: itemId },
        success: function (data) {
            var response = data["status"]["message"];
            if (response.length > 0) {
                // if we have an error, show it
                $("#Folders_ResourceResponse" + itemId).html(
                    "<br/>" + data["status"]["message"] + "<br/>"
                );
                $("#Folders_ResourceResponse" + itemId).css("color", "red");
                return;
            }

            // update the button
            if (verb == "Add") {
                $(target).removeClass("mv-folders-addresiurce")
                    .addClass("mv-folders-removeresource")
                    .data('verb', "Remove")
                    .prop(
                        'title',
                        "Remove this resource from the currently selected folder."
                    );
                $(target).html(
                    $(target).html().replace("Add", "Remove")
                );
                $(".mv-button-icon", target).attr('src', Folders_RemoveIcon);
                $(".mv-folders-removeallsearch").show();
            } else {
                $(target).removeClass("mv-folders-removeresource")
                    .addClass("mv-folders-addresource")
                    .data('verb', "Add")
                    .prop(
                        'title',
                        "Add this resource to the currently selected folder."
                    );
                $(target).html(
                    $(target).html().replace("Remove", "Add")
                );
                $(".mv-button-icon", target).attr('src', Folders_AddIcon);
            }

            // display confirmation message
            FoldersInsertConfirmationPopup(
                verb.toLowerCase(),
                "Item successfully " + verb.toLowerCase()+ "ed.",
                itemId,
                buttonLocation.left,
                buttonLocation.top,
                buttonHeight
            );

            // update sidebar content
            FoldersUpdateSidebarContent();
        },
        error: function (jqXHR, textStatus, errorThrown) {
            console.log(textStatus, errorThrown);
        }
    });
}

//process a call from the remove all results button from the search page. Makes a call
//to the RemoveSearchResults page and returns a response
function Folders_RemoveAllSearchButtonClickCallback(event) {
    //ensure that we don't go straight to the href - we interupt and process via AJAX if
    //javascript is available
    event.preventDefault();

    $.ajax({
        type: "POST",
        url: String(event.currentTarget.href),
        data: {},
        success: function (data) {
            var buttonLocation = $(event.currentTarget).offset();
            var buttonHeight = $(event.currentTarget).height();
            var response = data["status"]["message"];
            if (response.length > 0) {
                //display the error if there is one
                FoldersInsertConfirmationPopup(
                    "add",
                    response,
                    "all",
                    buttonLocation.left,
                    buttonLocation.top,
                    buttonHeight
                );
                $(".mv-folders-confirmation-popup").css("color", "red");
            } else {
                //display success message
                FoldersInsertConfirmationPopup(
                    "add",
                    "Removed Resources",
                    "all",
                    buttonLocation.left,
                    buttonLocation.top,
                    buttonHeight
                );

                //remove all previous resource responses on the page
                $(".mv-folders-resourceresponse").html("");

                // update the buttons for all the resources on the page
                $(".mv-folders-removeresource").each(function(index, element) {
                    $(element).removeClass("mv-folders-removeresource")
                        .addClass("mv-folders-addresource")
                        .data('verb', "Add")
                        .prop(
                            'title',
                            "Add this resource to the currently selected folder."
                        );
                    $(element).html(
                        $(element).html().replace("Remove", "Add")
                    );
                    $(".mv-button-icon", element).attr('src', Folders_AddIcon);
                });
                $(event.currentTarget).hide();

                //update the sidebar content
                FoldersUpdateSidebarContent();
            }
        },
        error: function (jqXHR, textStatus, errorThrown) {
            console.log(textStatus, errorThrown);
        }
    });
}

// process a call from the add all results button from the search page. Makes a call
// to the AddSearchResults page and returns a response
function Folders_AddAllSearchButtonClickCallback(event) {
    //ensure that we don't go straight to the href - we interupt and process via AJAX if
    //javascript is available
    event.preventDefault();

    //make the Ajax call
    $.ajax({
        type: "POST",
        url: String(event.currentTarget.href),
        data: {},
        success: function (data) {
            var dataobject = data;
            var response = dataobject["status"]["message"];
            var buttonLocation = $(event.currentTarget).offset();
            var buttonHeight = $(event.currentTarget).height();
            if (response.length > 0) {
                //display the error if there is one
                FoldersInsertConfirmationPopup(
                    "add", response, "all", buttonLocation.left, buttonLocation.top, buttonHeight);
                $(".mv-folders-confirmation-popup").css("color", "red");
            } else {
                //display the success message
                FoldersInsertConfirmationPopup(
                    "add",
                    "Resources successfully added",
                    "all",
                    buttonLocation.left,
                    buttonLocation.top,
                    buttonHeight
                );

                // remove all previous resource responses on the page
                $(".mv-folders-resourceresponse").html("");

                // update the buttons for all the resources on the page
                $(".mv-folders-addresource").each(function(index, element) {
                    $(element).removeClass("mv-folders-addresource")
                        .addClass("mv-folders-removeresource")
                        .data('verb', "Remove")
                        .prop(
                            'title',
                            "Remove this resource from the currently selected folder."
                        );
                    $(element).html(
                        $(element).html().replace("Add", "Remove")
                    );
                    $(".mv-button-icon", element).attr('src', Folders_RemoveIcon);
                });
                $(".mv-folders-removeallsearch").show();
                //update the sidebar content
                FoldersUpdateSidebarContent();
            }
        },
        error: function (jqXHR, textStatus, errorThrown) {
            console.log(textStatus, errorThrown);
        }
    });
}

//updates the sidebar content following a folder action. Calls out to UpdateSidebarContent and
//replaces the current sidebar with what that function returns
function FoldersUpdateSidebarContent() {
    //make the Ajax call
    $.ajax({
        type: "POST",
        url: CWIS_BASE_URL + "index.php?P=P_Folders_UpdateSidebarContent",
        data: {},
        dataType: "html",
        success: function (data) {
            if (data.length <= 0) {
                //display an error message if we don't get any sidebar data back
                $("#AddAllResourcesResponse").html(
                    "<br/>We encountered an error loading the sidebar, please refresh the page.<br/>");
                $("#AddAllResourcesResponse").css("color", "red");
            } else {
                //replace the current sidebar with what the call returns
                $(".mv-folders-sidebar").replaceWith(data);
            }
        },
        error: function (jqXHR, textStatus, errorThrown) {
            console.log(textStatus, errorThrown);
        }
    });
}

function FoldersInsertConfirmationPopup(Action, Message, ItemID, LocX, LocY, buttonHeight) {
    const DEFAULT_SHOW_TIME = 2000;
    const POPUP_RIGHT_PADDING = 20;
    const POPUP_TIMER_KEY = "cw.folder.confirmation.pop.timer.key";
    const POPUP_ID = "#mv-folders-confirmation-popup-" + ItemID;

    // create a dummy node to reterive height and width
    var dummy = document.createElement("div");
    dummy.className = "mv-folders-confirmation-popup";
    dummy.id = "mv-folders-confirmation-popup-dummy";
    dummy.style.display = "none";
    $("body").append(dummy);
    /* eslint-disable no-useless-escape */
    var popupWidth = $("#mv-folders-confirmation-popup-dummy").css("width").replace(/[^-\d\.]/g, '');
    var popupHeight = $("#mv-folders-confirmation-popup-dummy").css("height").replace(/[^-\d\.]/g, '');
    /* eslint-enable no-useless-escape */
    $("#mv-folders-confirmation-popup-dummy").remove();

    // calculate popup location
    var PopupX = LocX - POPUP_RIGHT_PADDING - popupWidth;
    var PopupY = (LocY + 0.5 * buttonHeight) - (0.5 * popupHeight);

    // if there is a pop that's currently showing, just update the content
    if ($(POPUP_ID).length) {
        $(POPUP_ID).html("<p>" + Message + "</p>");
        // we need to reset the timer here
        var currentTimer = $(POPUP_ID).data(POPUP_TIMER_KEY);
        if (currentTimer != null) {
            clearTimeout(currentTimer);
        }

        var newTimer = setTimeout(function () {
            if ($(POPUP_ID).length) {
                $(POPUP_ID).remove();
            }
            if (Action == "remove") {
                // remove item on screen
                $(".mv-folders-resource[data-itemid=" + ItemID + "]").remove();
            }
        }, DEFAULT_SHOW_TIME);

        $(POPUP_ID).data(POPUP_TIMER_KEY, newTimer);
        return;
    }

    // create and append popup
    var popup = document.createElement("div");
    popup.id = "mv-folders-confirmation-popup-" + ItemID;
    popup.className = "mv-folders-confirmation-popup";
    $("body").append(popup);
    $(popup).html("<p>" + Message + "</p>");
    popup.style.left = (PopupX + "px");
    popup.style.top = (PopupY + "px");

    // setup timer to remove popup
    var timerHandle = setTimeout(function () {
        if ($(POPUP_ID).length) {
            $(POPUP_ID).remove();
        }
        if (Action == "remove") {
            // remove item on screen
            $(".mv-folders-resource[data-itemid=" + ItemID + "]").remove();
        }
    }, DEFAULT_SHOW_TIME);
    $(POPUP_ID).data(POPUP_TIMER_KEY, timerHandle);
}
