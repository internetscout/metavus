/**
 * FILE:  Folders_Support.js (Folders plugin)
 *
 * Part of the Metavus digital collections platform
 * Copyright 2021-2025 Edward Almasy and Internet Scout Research Group
 * http://metavus.net
 *
 * Javascript classes for the Folders plugin.
 * @scout:eslint
 */

/* global CWIS_BASE_URL */

/*
 * Sources of globals:
 * interface/default/include/StdPageStart.html: CWIS_BASE_URL
 */

class Folders {
    /**
     * Handle clicks on action buttons for individual resources.
     */
    static handleResourceActionButtonClick() {
        // get the button that was clicked
        var Target = $(event.currentTarget);

        // extract data from the button
        var Action = Target.data('action');
        var ItemId = Target.data('itemid');
        var FolderId = Target.data('folderid');
        var ButtonLocation = Target.offset();
        var ButtonHeight = Target.height();

        // construct the URL we'll need for our AJAX requests
        var AjaxUrl = CWIS_BASE_URL +
            "index.php?P=P_Folders_PerformItemAction" +
            "&Action=" + Action +
            "&ItemId=" + ItemId +
            "&FolderId=" + FolderId;

        // make the Ajax call
        $.ajax({
            type: "POST",
            url: AjaxUrl,
            success: function (data) {
                if (data["Status"] == "Error") {
                    // if we have an error, show it
                    $("#Folders_ResourceResponse" + ItemId).html(
                        "<br/>" + data["Message"] + "<br/>"
                    );
                    $("#Folders_ResourceResponse" + ItemId).css("color", "red");
                    return;
                }

                // toggle visiblity of buttons
                $("button", $(Target).parent()).toggle();

                if ($(".mv-folders-addresource:visible").length > 0) {
                    $(".mv-folders-addallsearch").show();
                } else {
                    $(".mv-folders-addallsearch").hide();
                }

                if ($(".mv-folders-removeresource:visible").length > 0) {
                    $(".mv-folders-removeallsearch").show();
                } else {
                    $(".mv-folders-removeallsearch").hide();
                }

                // display confirmation message
                Folders.insertConfirmationPopup(
                    data["Message"],
                    ItemId,
                    ButtonLocation.left,
                    ButtonLocation.top,
                    ButtonHeight
                );

                // update sidebar content
                Folders.updateSidebarContent();
            },
            error: function (jqXHR, TextStatus, ErrorThrown) {
                console.log(TextStatus, ErrorThrown);
            }
        });
    }

    /**
     * Handle clicks on action buttons for sets of search results.
     */
    static handleSearchResultsActionButtonClick () {
        var Target = $(event.currentTarget);
        var AjaxUrl = CWIS_BASE_URL +
            "index.php?P=P_Folders_PerformSearchAction" +
            "&Action=" + Target.data("action") +
            "&" + Target.data("searchparams");

        // make the Ajax call
        $.ajax({
            type: "POST",
            url: AjaxUrl,
            success: function (data) {
                var Response = data["Message"];
                var ButtonLocation = Target.offset();
                var ButtonHeight = Target.height();

                // display response
                Folders.insertConfirmationPopup(
                    Response,
                    "all",
                    ButtonLocation.left,
                    ButtonLocation.top,
                    ButtonHeight);


                if (data["Status"] == "Error") {
                    $(".mv-folders-confirmation-popup").css("color", "red");
                    return;
                }

                // remove all previous resource responses on the page
                $(".mv-folders-resourceresponse").html("");

                // toggle button visibility
                if (Target.data("action") == "add") {
                    $(".mv-folders-addresource").hide();
                    $(".mv-folders-removeresource").show();
                    $(".mv-folders-addallsearch").hide();
                    $(".mv-folders-removeallsearch").show();
                } else {
                    $(".mv-folders-addresource").show();
                    $(".mv-folders-removeresource").hide();
                    $(".mv-folders-addallsearch").show();
                    $(".mv-folders-removeallsearch").hide();
                }

                //update the sidebar content
                Folders.updateSidebarContent();
            },
            error: function (jqXHR, TextStatus, ErrorThrown) {
                console.log(TextStatus, ErrorThrown);
            }
        });
    }

    /**
     * Handle click on the 'select folder' button.
     */
    static handleSelectButtonClick() {
        var Target = $(event.currentTarget);
        var FolderId = Target.attr("data-folderid");
        Folders.performFolderAction("select", FolderId);
    }

    /**
     * Perform an action for a given folder.
     * @param string Action The action to perform - one of share,
     *         withdraw, or select.
     * @param int FoldeId Id of the folder.
     */
    static performFolderAction(Action, FolderId) {
        var TargetUrl = CWIS_BASE_URL +
            "index.php?P=P_Folders_PerformFolderAction" +
            "&Action=" + Action +
            "&FolderId=" + FolderId;
        // make the Ajax call
        $.ajax({
            type: "GET",
            url:  TargetUrl,
            success: function (data) {
                if (data["Status"] == "Error") {
                    console.log(
                        "Error from " + TargetUrl + " - " + data["Message"]
                    );
                    return;
                }

                switch (Action) {
                case "share":
                    $(".mv-folders-folder[data-folderid="+FolderId+"]").removeClass('mv-notpublic');
                    break;

                case "withdraw":
                    $(".mv-folders-folder[data-folderid="+FolderId+"]").addClass('mv-notpublic');
                    break;

                case "select":
                    $(".mv-folders-folder").removeClass("mv-folders-selected");
                    $(".mv-folders-folder[data-folderid="+FolderId+"]").addClass("mv-folders-selected");
                    Folders.updateSidebarContent();
                    break;
                }
            },
            error: function (jqXHR, TextStatus, ErrorThrown) {
                console.log(TextStatus, ErrorThrown);
            }
        });
    }

    /**
     * Updates sidebar content following a folder action. Calls out to UpdateSidebarContent and
     * replaces the current sidebar with what that function returns.
     */
    static updateSidebarContent() {
        $.ajax({
            type: "POST",
            url: CWIS_BASE_URL + "index.php?P=P_Folders_UpdateSidebarContent",
            dataType: "html",
            success: function (data) {
                if (data.length <= 0) {
                    // display an error message if we don't get any sidebar data back
                    $("#AddAllResourcesResponse").html(
                        "<br/>We encountered an error loading the sidebar, please refresh the page.<br/>");
                    $("#AddAllResourcesResponse").css("color", "red");
                } else {
                    // replace the current sidebar with what the call returns
                    $(".mv-folders-sidebar").replaceWith(data);
                }
            },
            error: function (jqXHR, TextStatus, ErrorThrown) {
                console.log(TextStatus, ErrorThrown);
            }
        });
    }

    /**
     * Insert confirmation popup after a folder action completes
     * @param string Message Message to insert
     * @param int ItemID ID of item associated with the message (e.g., a RecordID)
     * @param int LocX X Coordinate of the folder button triggering this message
     * @param int LocY Y Coordinate of the folder button triggering this message
     * @param int ButtonHeight Height of the button triggering this message
     */
    static insertConfirmationPopup(Message, ItemID, LocX, LocY, ButtonHeight) {
        const DEFAULT_SHOW_TIME = 2000;
        const POPUP_RIGHT_PADDING = 20;
        const POPUP_TIMER_KEY = "cw.folder.confirmation.pop.timer.key";
        const POPUP_ID = "#mv-folders-confirmation-popup-" + ItemID;

        var TimerCallback = function () {
            if ($(POPUP_ID).length) {
                $(POPUP_ID).remove();
            }
        };

        // if there is a pop that's currently showing, just update the content
        if ($(POPUP_ID).length) {
            $(POPUP_ID).html("<p>" + Message + "</p>");
            // we need to reset the timer here
            var currentTimer = $(POPUP_ID).data(POPUP_TIMER_KEY);
            if (currentTimer != null) {
                clearTimeout(currentTimer);
            }

            var newTimer = setTimeout(TimerCallback, DEFAULT_SHOW_TIME);
            $(POPUP_ID).data(POPUP_TIMER_KEY, newTimer);
            return;
        }

        // create and append popup
        var popup = document.createElement("div");
        popup.id = "mv-folders-confirmation-popup-" + ItemID;
        popup.className = "mv-folders-confirmation-popup";
        $("body").append(popup);
        $(popup).html("<p>" + Message + "</p>");

        // calculate popup location
        var PopupX = LocX - POPUP_RIGHT_PADDING - $(popup).width();
        var PopupY = (LocY + 0.5 * ButtonHeight) - (0.5 * $(popup).height());
        popup.style.left = (PopupX + "px");
        popup.style.top = (PopupY + "px");

        // setup timer to remove popup
        var timerHandle = setTimeout(TimerCallback, DEFAULT_SHOW_TIME);
        $(POPUP_ID).data(POPUP_TIMER_KEY, timerHandle);
    }

    /**
     * Set up confiration popup that opens after pressing the 'Clear'
     *         button for a folder.
     * @param int FolderId the id of the folder
     */
    static createClearClickCallback(FolderId) {
        var DivId = "#mv-folders-folderclear" + FolderId;
        var LinkId = "#mv-folders-folderlink" + FolderId;

        // set up dialog
        $(DivId).dialog({
            buttons: {
                "Confirm": function () {
                    // Confirm clicks lead to a folder clear
                    window.location.href = $(LinkId).attr('href');
                },
                "Cancel": function () {
                    $(this).dialog("close");
                }
            },
            modal: true,
            autoOpen: false
        });

        // set up click handler on link
        $(LinkId).click(function(Event) {
            Event.preventDefault();
            $(DivId).dialog("open");
        });
    }


}


$(document).ready(function () {
    $(".mv-folders-clear-confirmlink").each(function (Index, Element) {
        Folders.createClearClickCallback(
            Element.getAttribute('data-folderid'));
    });

    // align tags
    var width = 0;
    $(".mv-resourcesummary-resourcetype-tag").each(function () {
        width = Math.max($(this).width(), width);
    });
    $(".mv-resourcesummary-resourcetype-tag").css("width", width + "px");
});
