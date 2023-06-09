/**
 * FILE:  Folders_Main.js (Folders plugin)
 *
 * Part of the Metavus digital collections platform
 * Copyright 2021 Edward Almasy and Internet Scout Research Group
 * http://metavus.net
 *
 * Contains main Javascript functionality for Folders plugin
 *
 * @scout:eslint
 */

/* global cw */

/*
 * Sources of globals:
 * interface/default/include/CW-Base.js: cw
 */

(function($){
    var RouterUrl = cw.getRouterUrl(),
        MoveItemUrl = RouterUrl+"?P=P_Folders_MoveItem&SuppressHtmlOutput=1",
        MoveItemToNewFolderUrl = RouterUrl+"?P=P_Folders_MoveItemToNewFolder&SuppressHtmlOutput=1",
        ShareFolderUrl = RouterUrl+"?P=P_Folders_ShareFolder&SuppressHtmlOutput=1",
        WithdrawFolderUrl = RouterUrl+"?P=P_Folders_WithdrawFolder&SuppressHtmlOutput=1";

    /**
   * Take an item that has been moved and update its position in the database.
   * @param $item jQuery object wrapping the DOM item
   * @return void
   */
    function itemUpdate($item) {
        if ($item.prev().length) {
            moveItem(
                $item.attr("data-parentfolderid"),
                $item.prev().attr("data-itemid"),
                $item.attr("data-itemid"));
        } else if ($item.next().length) {
            // first move it after the first item...
            prependItem(
                $item.attr("data-parentfolderid"),
                $item.attr("data-itemid"));
        }
    }

    /**
   * Do an AJAX callback to move the item.
   * @param folderId ID of the folder the items are in
   * @param targetItemId ID of the target item
   * @param itemId ID of the item
   * @return void
   */
    function moveItem(folderId, targetItemId, itemId) {
        $.get(MoveItemUrl, {
            "FolderId": folderId,
            "ItemId": itemId,
            "TargetItemId": targetItemId
        });
    }

    /**
   * Do an AJAX callback to move the item to the beginning of the list.
   * @param folderId ID of the folder the items are in
   * @param itemId ID of the item
   * @return void
   */
    function prependItem(folderId, itemId) {
        $.get(MoveItemUrl, {
            "FolderId": folderId,
            "ItemId": itemId
        });
    }

    /**
   * Do an AJAX callback to move the item to a new folder.
   * @param oldFolderId ID of the old folder the item was in
   * @param newFolderId ID of the folder the item is now in
   * @param itemId ID of the item
   * @return void
   */
    function moveItemToNewFolder(oldFolderId, newFolderId, itemId, onSuccess) {
        $.get(MoveItemToNewFolderUrl, {
            "OldFolderId": oldFolderId,
            "NewFolderId": newFolderId,
            "ItemId": itemId
        }, onSuccess);
    }

    /**
   * Do an AJAX callback to share the folder.
   * @param folderId folder ID
   * @return void
   */
    function shareFolder(folderId) {
        $.get(ShareFolderUrl, {
            "FolderId": folderId
        });
    }

    /**
   * Do an AJAX callback to withdraw the folder.
   * @param folderId folder ID
   * @return void
   */
    function withdrawFolder(folderId) {
        $.get(WithdrawFolderUrl, {
            "FolderId": folderId
        });
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
                moveItemToNewFolder(lastFolderId, currentFolderId, itemId, function(){
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
            var $this = $(this),
                folderId = $this.attr("data-folderid");

            if ($this.is(":checked")) {
                shareFolder(folderId);
            } else {
                withdrawFolder(folderId);
            }
        });
    });

}(jQuery));

// toggle view folder sorting order
$("#mv-folders-sort-order-button").click(function() {
    $('input[type="radio"]').not(':checked').prop("checked", true);
});
