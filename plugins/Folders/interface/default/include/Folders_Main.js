/**
 * FILE:  Folders_Main.js (Folders plugin)
 *
 * Part of the Metavus digital collections platform
 * Copyright 2021-2025 Edward Almasy and Internet Scout Research Group
 * http://metavus.net
 *
 * Contains main Javascript functionality for Folders plugin
 *
 * @scout:eslint
 */

/* global cw, Folders */

/*
 * Sources of globals:
 * interface/default/include/CW-Base.js: cw
 * plugins/Folders/interface/default/include/Folders_Support.js: Folders
 */

(function($){
    let RestUrl = cw.getRouterUrl() + "?P=P_Folders_PerformItemAction";

    function responseHandler(data) {
        if (data["Status"] == "Error") {
            alert(data["Message"]);
            return;
        }

        $("#page-p_folders_viewfolder select#SF").val([-1]);
        Folders.updateSidebarContent();
    }

    /**
     * Take an item that has been moved and update its position in the database.
     * @param jQuery Item jQuery object wrapping the DOM item
     * @return void
     */
    function itemUpdate(Item) {
        if (Item.prev().length) {
            moveItem(
                Item.attr("data-parentfolderid"),
                Item.prev().attr("data-itemid"),
                Item.attr("data-itemid"));
        } else if (Item.next().length) {
            // first move it after the first item...
            prependItem(
                Item.attr("data-parentfolderid"),
                Item.attr("data-itemid"));
        }
    }

    /**
     * Do an AJAX callback to move the item.
     * @param int FolderId ID of the folder the items are in
     * @param int TargetItemId ID of the target item
     * @param int ItemId ID of the item
     * @return void
     */
    function moveItem(FolderId, TargetItemId, ItemId) {
        $.get(
            RestUrl,
            {
                "Action": "move",
                "FolderId": FolderId,
                "ItemId": ItemId,
                "AfterItemId": TargetItemId
            },
            responseHandler
        );
    }

    /**
     * Do an AJAX callback to move the item to the beginning of the list.
     * @param int FolderId ID of the folder the items are in
     * @param int ItemId ID of the item
     * @return void
     */
    function prependItem(FolderId, ItemId) {
        $.get(
            RestUrl,
            {
                "Action": "prepend",
                "FolderId": FolderId,
                "ItemId": ItemId
            },
            responseHandler
        );
    }

    /**
     * Do an AJAX callback to move the item to a new folder.
     * @param int FolderId ID of the old folder the item was in
     * @param int NewFolderId ID of the folder the item is now in
     * @param int ItemId ID of the item
     * @param Callback OnSucces Callback to run on success
     * @return void
     */
    function moveItemToNewFolder(FolderId, NewFolderId, ItemId, OnSuccess) {
        $.get(
            RestUrl,
            {
                "Action": "move-folder",
                "FolderId": FolderId,
                "ItemId": ItemId,
                "NewFolderId": NewFolderId
            },
            OnSuccess
        );
    }

    $(document).ready(function(){
        // add move cursor to certain items
        $(".mv-section.mv-folders-folder .mv-section-header,\
       .mv-folders-folder ul.mv-folders-items li,\
       .mv-folders-items:not(.mv-folders-nojs) > .mv-folders-resource ").css({
            "cursor": "move"});

        function itemOver(event) {
            var $lists = $(".mv-folders-items");

            $lists.each(function(){
                var $list = $(this);

                if ($list.children(":visible").length == 0) {
                    $list.prepend('<li class="mv-folders-noitems">There are no items in this folder.</li>');
                }
            });

            var $list = $(".mv-folders-items .ui-sortable-helper").parent();

            if ($list.children().length == 1) {
                $list.prepend('<li class="mv-folders-noitems">There are no items in this folder.</li>');
            }

            $(event.target).children("li.mv-folders-noitems").remove();
        }

        function miniItemUpdate(event, ui) {
            var $item = $(ui.item),
                $list = $item.parent(),
                itemId = $item.attr("data-itemid"),
                currentFolderId = $list.attr("data-folderId"),
                lastFolderId = $item.attr("data-parentfolderid");

            if (currentFolderId == lastFolderId) {
                itemUpdate($item);
            } else {
                // update parent folder ID data
                $item.attr("data-parentfolderid", currentFolderId);

                // remove siblings that are the same
                $item.siblings("[data-itemid='"+itemId+"']").remove();

                // move the item to the new folder at the beginning
                moveItemToNewFolder(lastFolderId, currentFolderId, itemId, function(data){
                    if (data["Status"] == "Error") {
                        alert(data["Message"]);
                        return;
                    }

                    // only need to consider position if not at the beginning
                    if ($item.prev().length) {
                        moveItem(
                            $item.attr("data-parentfolderid"),
                            $item.prev().attr("data-itemid"),
                            $item.attr("data-itemid"));
                    }
                });
            }
        }

        // disable text selection on the folders
        $(".mv-folders-folders").disableSelection();

        $(".mv-folders-folders").sortable({
            "axis": "y",
            "containment": "document",
            "handle": ".mv-section-header",
            "update": function(e, ui) {
                itemUpdate($(ui.item));
            }
        });

        $(".mv-folders-items:not(.mv-folders-nojs)").sortable({
            "axis": "y",
            "containment": "document",
            "update": function(e, ui) {
                itemUpdate($(ui.item));
            }
        });

        $(".mv-folders-items.mv-folders-items-mini").sortable({
            "axis": "y",
            "containment": "document",
            "update": miniItemUpdate,
            "cancel": ".mv-folders-noitems",
            "connectWith": ".mv-folders-items",
            "over": itemOver
        });

        $("input[type='checkbox'][name='Share']").change(function(){
            var folderId = $(this).attr("data-folderid");

            if ($(this).is(":checked")) {
                Folders.performFolderAction("share", folderId);
            } else {
                Folders.performFolderAction("withdraw", folderId);
            }
        });
    });
}(jQuery));

// toggle view folder sorting order
$("#mv-folders-sort-order-button").click(function() {
    $('input[type="radio"]').not(':checked').prop("checked", true);
});
