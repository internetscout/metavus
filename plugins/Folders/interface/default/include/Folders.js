/**
 * FILE:  Folders_Support.js (Folders plugin)
 *
 * Part of the Metavus digital collections platform
 * Copyright 2021-2026 Edward Almasy and Internet Scout Research Group
 * http://metavus.net
 *
 * Javascript classes for the Folders plugin.
 */

/* global cw, CWIS_BASE_URL */

/*
 * Sources of globals:
 * interface/default/include/CW-Base.js: cw
 * interface/default/include/StdPageStart.html: CWIS_BASE_URL
 */

class Folders {
    static RestUrl = cw.getRouterUrl() + "?P=P_Folders_PerformItemAction";

    /**
     * Handle clicks on action buttons for individual resources.
     */
    static handleResourceActionButtonClick() {
        // get the button that was clicked
        let Target = $(event.currentTarget);

        // extract data from the button
        let Action = Target.data('action');
        let ItemId = Target.data('itemid');
        let FolderId = Target.data('folderid');
        let ButtonLocation = Target.offset();
        let ButtonHeight = Target.height();

        // construct the URL we'll need for our AJAX requests
        let AjaxUrl = CWIS_BASE_URL +
            "index.php?P=P_Folders_PerformItemAction" +
            "&Action=" + Action +
            "&ItemId=" + ItemId +
            "&FolderId=" + FolderId;

        // make the Ajax call
        $.ajax({
            type: "POST",
            url: AjaxUrl,
            success: function (data) {
                if (data["Status"] === "Error") {
                    // if we have an error, show it
                    $("#Folders_ResourceResponse" + ItemId).html(
                        "<br/>" + data["Message"] + "<br/>"
                    );
                    $("#Folders_ResourceResponse" + ItemId).css("color", "red");
                    return;
                }

                // toggle visibility of buttons
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
                Folders.getSidebarHtml();
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
        let Target = $(event.currentTarget);
        let AjaxUrl = CWIS_BASE_URL +
            "index.php?P=P_Folders_PerformSearchAction" +
            "&Action=" + Target.data("action") +
            "&" + Target.data("searchparams");

        // make the Ajax call
        $.ajax({
            type: "POST",
            url: AjaxUrl,
            success: function (data) {
                let Response = data["Message"];
                let ButtonLocation = Target.offset();
                let ButtonHeight = Target.height();

                // display response
                Folders.insertConfirmationPopup(
                    Response,
                    "all",
                    ButtonLocation.left,
                    ButtonLocation.top,
                    ButtonHeight);


                if (data["Status"] === "Error") {
                    $(".mv-folders-confirmation-popup").css("color", "red");
                    return;
                }

                // remove all previous resource responses on the page
                $(".mv-folders-resourceresponse").html("");

                // toggle button visibility
                if (Target.data("action") === "add") {
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
                Folders.getSidebarHtml();
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
        let Target = $(event.currentTarget);
        let FolderId = Target.attr("data-folderid");
        Folders.performFolderAction("select", FolderId);
    }

    /**
     * Perform an action for a given folder.
     * @param {string} Action The action to perform - one of share, withdraw, or select.
     * @param {int} FolderId Id of the folder.
     */
    static performFolderAction(Action, FolderId) {
        let TargetUrl = CWIS_BASE_URL +
            "index.php?P=P_Folders_PerformFolderAction" +
            "&Action=" + Action +
            "&FolderId=" + FolderId;
        // make the Ajax call
        $.ajax({
            type: "GET",
            url:  TargetUrl,
            success: function (data) {
                if (data["Status"] === "Error") {
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
                    Folders.getSidebarHtml();
                    break;
                }
            },
            error: function (jqXHR, TextStatus, ErrorThrown) {
                console.log(TextStatus, ErrorThrown);
            }
        });
    }

    /**
     * Updates sidebar content following a folder action. Calls out to GetSidebarHtml and
     * replaces the current sidebar with what that function returns.
     */
    static getSidebarHtml() {
        $.ajax({
            type: "POST",
            url: CWIS_BASE_URL + "index.php?P=P_Folders_GetSidebarHtml",
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
     * @param {string} Message Message to insert
     * @param {int|string} ItemID ID of item associated with the message
     *         (e.g., a RecordID), or "all" for all items
     * @param {int} LocX X Coordinate of the folder button triggering this message
     * @param {int} LocY Y Coordinate of the folder button triggering this message
     * @param {int} ButtonHeight Height of the button triggering this message
     */
    static insertConfirmationPopup(Message, ItemID, LocX, LocY, ButtonHeight) {
        const DEFAULT_SHOW_TIME = 2000;
        const POPUP_RIGHT_PADDING = 20;
        const POPUP_TIMER_KEY = "cw.folder.confirmation.pop.timer.key";
        const POPUP_ID = "#mv-folders-confirmation-popup-" + ItemID;

        let TimerCallback = function () {
            if ($(POPUP_ID).length) {
                $(POPUP_ID).remove();
            }
        };

        // if there is a pop that's currently showing, just update the content
        if ($(POPUP_ID).length) {
            $(POPUP_ID).html("<p>" + Message + "</p>");
            // we need to reset the timer here
            let currentTimer = $(POPUP_ID).data(POPUP_TIMER_KEY);
            if (currentTimer != null) {
                clearTimeout(currentTimer);
            }

            let newTimer = setTimeout(TimerCallback, DEFAULT_SHOW_TIME);
            $(POPUP_ID).data(POPUP_TIMER_KEY, newTimer);
            return;
        }

        // create and append popup
        let popup = document.createElement("div");
        popup.id = "mv-folders-confirmation-popup-" + ItemID;
        popup.className = "mv-folders-confirmation-popup";
        $("body").append(popup);
        $(popup).html("<p>" + Message + "</p>");

        // calculate popup location
        let PopupX = LocX - POPUP_RIGHT_PADDING - $(popup).width();
        let PopupY = (LocY + 0.5 * ButtonHeight) - (0.5 * $(popup).height());
        popup.style.left = (PopupX + "px");
        popup.style.top = (PopupY + "px");

        // set up timer to remove popup
        let timerHandle = setTimeout(TimerCallback, DEFAULT_SHOW_TIME);
        $(POPUP_ID).data(POPUP_TIMER_KEY, timerHandle);
    }

    /**
     * Set up confirmation popup that opens after pressing the 'Clear'
     *         button for a folder.
     * @param {int} FolderId the id of the folder
     */
    static createClearClickCallback(FolderId) {
        let DivId = "#mv-folders-folderclear" + FolderId;
        let LinkId = "#mv-folders-folderlink" + FolderId;

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

    /**
     * Handle response from Folders AJAX calls to move items.
     * @param data
     */
    static responseHandler(data) {
        if (data["Status"] === "Error") {
            alert(data["Message"]);
            return;
        }

        $("#page-p_folders_viewfolder select#SF").val([-1]);
        Folders.getSidebarHtml();
    }

    /**
     * Take an item that has been moved and update its position in the database.
     * @param {jQuery} Item jQuery object wrapping the DOM item
     * @return {void}
     */
    static itemUpdate(Item) {
        if (Item.prev().length) {
            Folders.moveItem(
                Item.attr("data-parentfolderid"),
                Item.prev().attr("data-itemid"),
                Item.attr("data-itemid"));
        } else if (Item.next().length) {
            // first move it after the first item...
            Folders.prependItem(
                Item.attr("data-parentfolderid"),
                Item.attr("data-itemid"));
        }
    }

    /**
     * Do an AJAX callback to move the item.
     * @param {int} FolderId ID of the folder the items are in
     * @param {int} TargetItemId ID of the target item
     * @param {int} ItemId ID of the item
     * @return {void}
     */
    static moveItem(FolderId, TargetItemId, ItemId) {
        $.get(
            Folders.RestUrl,
            {
                "Action": "move",
                "FolderId": FolderId,
                "ItemId": ItemId,
                "AfterItemId": TargetItemId
            },
            Folders.responseHandler
        );
    }

    /**
     * Do an AJAX callback to reorder folders.
     * @param {int} ItemId ID of the folder to move.
     * @param {int} AfterItemId ID of the folder to move the folder after, or
     *         NULL to move it to the start of the list.
     */
    static reorderFolder(ItemId, AfterItemId) {
        $.get(
            Folders.RestUrl,
            {
                "Action": "reorder-folder",
                "ItemId": ItemId,
                "AfterItemId": AfterItemId
            },
            function(data) {
                if (data["Status"] === "Error") {
                    alert(data["Message"]);
                }
            }
        );
    }

    /**
     * Do an AJAX callback to move the item to the beginning of the list.
     * @param {int} FolderId ID of the folder the items are in
     * @param {int} ItemId ID of the item
     * @return {void}
     */
    static prependItem(FolderId, ItemId) {
        $.get(
            Folders.RestUrl,
            {
                "Action": "prepend",
                "FolderId": FolderId,
                "ItemId": ItemId
            },
            Folders.responseHandler
        );
    }

    /**
     * Do an AJAX callback to move the item to a new folder.
     * @param {int} FolderId ID of the old folder the item was in
     * @param {int} NewFolderId ID of the folder the item is now in
     * @param {int} ItemId ID of the item
     * @param {callback} OnSuccess Callback to run on success
     * @return {void}
     */
    static moveItemToNewFolder(FolderId, NewFolderId, ItemId, OnSuccess) {
        $.get(
            Folders.RestUrl,
            {
                "Action": "move-folder",
                "FolderId": FolderId,
                "ItemId": ItemId,
                "NewFolderId": NewFolderId
            },
            OnSuccess
        );
    }

    /**
     * Function for event triggered when a resource in a folder is moved.
     * @param {Event} event The event itself.
     */
    static itemOver(event) {
        let $lists = $(".mv-folders-items");

        $lists.each(function(){
            let $list = $(this);

            if ($list.children(":visible").length === 0) {
                $list.prepend('<li class="mv-folders-noitems">There are no items in this folder.</li>');
            }
        });

        let $list = $(".mv-folders-items .ui-sortable-helper").parent();

        if ($list.children().length === 1) {
            $list.prepend('<li class="mv-folders-noitems">There are no items in this folder.</li>');
        }

        $(event.target).children("li.mv-folders-noitems").remove();
    }

    /**
     * Function for event triggered when user stops sorting a resource in a
     * folder and the DOM position has changed.
     * @param {Event} event The event itself.
     * @param {Object} ui An object containing, among other things, the dragged
     *         element item.
     */
    static miniItemUpdate(event, ui) {
        let $item = $(ui.item),
            $list = $item.parent(),
            itemId = $item.attr("data-itemid"),
            currentFolderId = $list.attr("data-folderId"),
            lastFolderId = $item.attr("data-parentfolderid");

        if (currentFolderId === lastFolderId) {
            Folders.itemUpdate($item);
        } else {
            // update parent folder ID data
            $item.attr("data-parentfolderid", currentFolderId);

            // remove siblings that are the same
            $item.siblings("[data-itemid='"+itemId+"']").remove();

            // move the item to the new folder at the beginning
            Folders.moveItemToNewFolder(lastFolderId, currentFolderId, itemId, function(data){
                if (data["Status"] === "Error") {
                    alert(data["Message"]);
                    return;
                }

                // only need to consider position if not at the beginning
                if ($item.prev().length) {
                    Folders.moveItem(
                        $item.attr("data-parentfolderid"),
                        $item.prev().attr("data-itemid"),
                        $item.attr("data-itemid"));
                }
            });
        }
    }

    /**
     * Set up Folders UI.
     */
    static setUp() {
        // set up callback on clicking clear folder contents button
        $(".mv-folders-clear-confirmlink").each(function (Index, Element) {
            Folders.createClearClickCallback(
                Element.getAttribute('data-folderid'));
        });

        // align tags
        let width = 0;
        $(".mv-resourcesummary-resourcetype-tag").each(function () {
            width = Math.max($(this).width(), width);
        });
        $(".mv-resourcesummary-resourcetype-tag").css("width", width + "px");

        Folders.setUpSortableFolders();
        Folders.setUpSortableResources();
        Folders.setUpShareableFolders();
        Folders.setUpFolderTransferPopup();
    }

    /**
     * Set up folders to be sortable.
     */
    static setUpSortableFolders() {
        let FolderElements = $(".mv-folders-folders");

        // add move cursor to certain items
        $(".mv-section.mv-folders-folder .mv-section-header,\
           .mv-folders-folder ul.mv-folders-items li,\
           .mv-folders-items:not(.mv-folders-nojs) > .mv-folders-resource ").css({
            "cursor": "move"});

        // disable text selection on the folders
        FolderElements.disableSelection();

        // make folders sortable
        FolderElements.sortable({
            "axis": "y",
            "containment": "document",
            "handle": ".mv-section-header",
            "update": function(e, ui) {
                let Item = $(ui.item);
                Folders.reorderFolder(
                    Item.attr("data-itemid"),
                    Item.prev().attr("data-itemid")
                );
            }
        });
    }

    /**
     * Set up resources to be sortable.
     */
    static setUpSortableResources() {
        // make resources sortable
        $(".mv-folders-items:not(.mv-folders-nojs)").sortable({
            "axis": "y",
            "containment": "document",
            "update": function(e, ui) {
                Folders.itemUpdate($(ui.item));
            }
        });

        // make resources in folders sortable on Manage Folders page
        $(".mv-folders-items.mv-folders-items-mini").sortable({
            "axis": "y",
            "containment": "document",
            "update": Folders.miniItemUpdate,
            "cancel": ".mv-folders-noitems",
            "connectWith": ".mv-folders-items",
            "over": Folders.itemOver
        });
    }

    /**
     * Set up folder share checkbox.
     */
    static setUpShareableFolders() {
        // set up action for change to Share checkbox
        $("input[type='checkbox'][name='Share']").change(function(){
            let folderId = $(this).attr("data-folderid");

            if ($(this).is(":checked")) {
                Folders.performFolderAction("share", folderId);
            } else {
                Folders.performFolderAction("withdraw", folderId);
            }
        });
    }

    /**
     * Set up popup to allow transferring folders to other users.
     */
    static setUpFolderTransferPopup() {
        let FolderTransferPopup = $("#mv-folders-transfer-folder-popup");
        let FolderTransferPopupUnameField = $("#mv-folders-transfer-folder-popup-uname-field");

        if (FolderTransferPopup.length > 0 && FolderTransferPopupUnameField.length > 0) {
            FolderTransferPopup.css('display', '');
            let CurrentUserName = FolderTransferPopup.attr("data-currentusername");

            // set up transfer folder popup
            FolderTransferPopup.dialog({
                buttons: {
                    Transfer: function () {
                        let folderID = FolderTransferPopup.attr('data-folderid');
                        let tgtUser = $.trim(FolderTransferPopupUnameField.val());

                        if (tgtUser.length === 0) {
                            return;
                        }

                        // send out ajax request
                        $.ajax({
                            type: "POST",
                            url: CWIS_BASE_URL + "index.php?P=P_Folders_TransferFolders&FID=" + folderID,
                            data: { username: tgtUser },
                            dataType: "html",
                            success: function (data) {
                                let result = JSON.parse(data);
                                let SuccessPopup = $("#mv-folders-transfer-folder-popup-success");
                                let ErrorPopup = $("#mv-folders-transfer-folder-popup-error");

                                if (result.status.state !== "OK") {
                                    // display error message
                                    SuccessPopup.hide();
                                    ErrorPopup.text("Error: " + result.status.message);
                                    ErrorPopup.show();
                                } else {
                                    // display success message and reload page
                                    ErrorPopup.hide();
                                    SuccessPopup.text(result.status.message);
                                    SuccessPopup.show();
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
            FolderTransferPopupUnameField.autocomplete({
                appendTo: "#mv-folders-transfer-folder-popup-body",
                source: function (request, response) {
                    let SearchString = request.term.replace(/[^a-z0-9]/gi, "");
                    $.get(CWIS_BASE_URL + "index.php?P=UserQuickSearchCallback&SS=" + SearchString, {
                    }, function (data) {
                        // remove current user from results
                        let FilteredResults = data.filter(function (item) {
                            return CurrentUserName === "" || item.value !== CurrentUserName;
                        });
                        response(FilteredResults);
                    });
                },
                select: function (event, ui) {
                    FolderTransferPopupUnameField.val(ui.item.value);
                    return false;
                },
                focus: function (event, ui) {
                    FolderTransferPopupUnameField.val(ui.item.value);
                    return false;
                },
            }).data("ui-autocomplete")._renderItem = function (ul, item) {
                return $("<li></li>")
                    .data("item.autocomplete", item)
                    .append("<div>"+item.label+"</div>")
                    .appendTo(ul);
            };

            // set up click handler for transfer button
            $('.cw-folder-transfer-folder-button').click(function (ev) {
                ev.preventDefault();

                let folderId = $(ev.target).attr('data-folderid'),
                    folderName = $(ev.target).attr('data-foldername');

                // pass folder name in to popup
                FolderTransferPopup.attr('data-folderid', folderId);

                // set folder name on popup
                FolderTransferPopup.dialog(
                    "option", "title", "Transfer " + folderName);
                $("span.mv-folders-folder-name", "#mv-folders-transfer-folder-popup")
                    .text(folderName);

                FolderTransferPopup.dialog("open");
            });
        }
    }
}


$(document).ready(function () {
    Folders.setUp();
});
