/**
 * FILE:  CW-Base.js
 *
 * Part of the Metavus digital collections platform
 * Copyright 2011-2022 Edward Almasy and Internet Scout Research Group
 * http://metavus.net
 *
 * @scout:eslint
 *
 * Support functions for core Metavus.
 */

var cw = {};
/**
 * Extend a subclass to inherit another class' private methods, privileged
 * methods and data members.
 * http://blog.reinpetersen.com/2008/12/work-around-for-common-inheritance.html
 * @param subclass:Object_reference subclass class
 * @param base:Object_reference parent class
 */
cw.extend = function(subclass, base) {
    function Closure(){}
    Closure.prototype = base.prototype;
    subclass.prototype = new Closure();
    subclass.prototype.constructor = subclass;
    subclass.base = base;
};

/**
* Get the base URL for the page.
* @return Returns the base URL for the page.
*/
cw.getBaseUrl = function() {
    var url;

    // if the base URL global is set
    if (typeof CWIS_BASE_URL != "undefined") {
        return CWIS_BASE_URL; // eslint-disable-line no-undef
    }

    // otherwise try to get the base URL from the current URL
    url = window.location.pathname;
    url = url.substring(0, url.lastIndexOf("/")+1);

    return url;
};

/**
* Get the URL for the CWIS router page.
* @return Returns the URL for the CWIS router page.
*/
cw.getRouterUrl = function() {
    return cw.getBaseUrl() + "index.php";
};

/**
* Detect when a user starts tabbing to use keyboard navigation, add a
* focus outline to indicate the focused element.
*/
$(document).on('keydown', function(e) {
    const tabKeyCode = 9;
    if (e.keyCode == tabKeyCode) {
        // add a <style> to the head of the document
        var styleEl = document.createElement('style');
        document.head.appendChild(styleEl);

        // turn on the focus outline in that element
        styleEl.sheet.insertRule(':focus { outline-style: dotted; }');

        // disable keydown handler
        $(document).off('keydown');
    }
});

// support for inserting images into CKEditor instances
/**
 * Insert an image into a CKEditor instance.
 * @param CKEditor editorInstance Editor to insert into
 * @param string side Side to insert on, one of 'left' or 'right'
 * @param string imageUrl Url to the image
 * @param string altText Alt text for the image
 * @param bool addCaption TRUE to include captions below the image,
 *   FALSE to omit them
 */
// eslint-disable-next-line no-unused-vars
function mv_insertImage(editorInstance, side, imageUrl, altText, addCaption) {
    var html = '<div class="mv-form-image-' + side + '">' +
        '<img src="' + imageUrl + '" ' +
        'alt="' + altText.replaceAll('"', '&quot;') + '"/>';

    if (addCaption) {
        html = html +
            '<div class="mv-form-image-caption" aria-hidden="true">' +
            altText + '</div>';
    }
    html = html + '</div>';

    var selection = editorInstance.getSelection(),
        range = selection.getRanges()[0],
        element = null;

    // in Chrome, when nothing is selected the range will be undefined
    // whereas in other browsers it will refer to the beginning of the
    // editable area
    if (range === undefined) {
        // eslint-disable-next-line no-undef
        range = new CKEDITOR.dom.range(editorInstance.document);
        element = editorInstance.editable();
    } else {
        element = selection.getStartElement();
    }

    if (element.getName() == "body") {
        // if the top-level editor is selected rather than sth within it,
        // use the first element within the editor
        element = element.getChild(0);
    } else {
        // otherwise, work upward from the currently selected element
        // to the containing p, div, ul, ol, hX, or (failing that),
        // the body that wraps the editor itself
        var iterationCount = 0,
            stopTags = [
                "p", "div", "ul", "ol",
                "h1", "h2", "h3", "h4", "h5", "h6",
                "body"
            ];
        while (iterationCount++ < 10 && !stopTags.includes(element.getName())) {
            element = element.getParent();
        }
    }

    // eslint-disable-next-line no-undef
    range.moveToPosition(element, CKEDITOR.POSITION_BEFORE_START);
    editorInstance.insertHtml(html, 'unfiltered_html', range);
}

/**
 * Remove all instances of the divs containing a specified image
 * (added with an Insert button) from content being edited in a
 * CKEditor instance.
 * @param CKEditor editorInstance Editor to remove from
 * @param string imageUrl Url to the image
 */
// eslint-disable-next-line no-unused-vars
function mv_removeImage(editorInstance, imageUrl) {
    var Images = editorInstance.editable().find('img[src="'+imageUrl+'"]').toArray();
    Images.forEach(function(Element) {
        var Container = Element.getParent();
        if (Container.getName() == "div" &&
            (Container.hasClass("mv-form-image-left") ||
             Container.hasClass("mv-form-image-right"))) {
            Container.remove();
        }
    });
}

// JS code to add responsivness to FormUI that cannot be implemented
// in CSS alone (typically because we need to know the width of a
// containing element). Wrap these features in an Immediately Invoked
// Function Expression (IIFE) to keep the global scope clean.
// See https://developer.mozilla.org/en-US/docs/Glossary/IIFE
(function() {
    /**
     * Set the height of an element based on the space required for
     * its contents.
     * @param el Element to modify
     */
    function adjustElementHeight(el) {
        $(el).height(0);
        $(el).height($(el).prop('scrollHeight'));
    }

    /**
     * Save the values provided in `size` attributes on input elements
     * into .data() so that these initial sizes will not be lost when
     * we adjust elements later.
     * @see fixSizes()
     */
    function saveInitialSizeAttrs(){
        // save initial sizes for text inputs
        $('input[type="text"]', ".mv-form-table").each(function(index, element) {
            if ($(element).attr('size') === undefined) {
                return;
            }
            $(element).data('initial-size', $(element).attr('size'));
        });
    }

    /**
     * Set the width of the form labels to the widest text in any form
     * label.
     */
    function setSizeOfFormLabels() {
        var maxWidth = 0;
        $("label", ".mv-form-table .mv-form-group th").each(function(index, element){
            maxWidth = Math.max(maxWidth, $(element).width());
        });

        $("th", ".mv-form-table .mv-form-group").css(
            'width', (maxWidth + 20) + "px");
    }

    /**
     * Increase the width of option lists that do not have a
     * Search/Clear button to fill the available space.
     */
    function setSizeOfOptlists() {
        $(".mv-form-optlist", ".mv-form-table").each(function(index, element) {
            var btnPanel = $(".mv-form-additional-html", $(element).parent());
            if ($(btnPanel).children().length == 0) {
                $(element).css("width", "100%");
            }
        });

    }

    /**
     * Adjust `size` attributes on text inputs and widths of labels
     * for checkbox sets inside FormUI-generated forms to prevent them
     * from forcing their containing elements to be unnecessarily wide.
     * @see saveInitialSizeAttrs()
     */
    function fixSizes() {
        // (note that browsers don't re-display the page till JS
        // threads are idle; they re-compute the widths/heights that
        // would be displayed but wait for the JS to finish before
        // sending any of that to the screen)

        // get the list of elements whose sizes we need to examine
        var inputElements = $('input[type="text"]:visible', ".mv-form-table"),
            optLists = $('.mv-form-optlist', ".mv-form-table"),
            paragraphs = $(".mv-form-fieldtype-paragraph textarea:visible", ".mv-form-table"),
            helpCells = $(".mv-content-help-cell", ".mv-form-table"),
            margin = 30;

        // reset word-break on all the checkboxes so that we'll
        // compute our initial widths with breaks applied on spaces
        $(".mv-checkboxset-item", ".mv-form-table .mv-checkboxset").css('word-break', '');

        // reset sizes on all the elements that may have been modified in previous calls
        inputElements.each(function(index, element){
            if ($(element).attr('size') === undefined) {
                return;
            }
            $(element).attr('size', $(element).data('initial-size'));
        });
        optLists.css('max-width', '');
        paragraphs.css('max-width', '');

        // hide elements that shouldn't grow to fill the available space
        helpCells.hide();

        // hide the elements that we might resize so they will not be
        // included in our calculations of available widths
        inputElements.hide();
        optLists.hide();
        paragraphs.hide();

        // see what's even left
        var elementsStillVisible = $("th + td", ".mv-form-table"),
            maxVisibleWidth = 0;

        elementsStillVisible.each(function(index, element) {
            maxVisibleWidth = Math.max(maxVisibleWidth, $(element).width() );
        });

        // if all that's left is *very* narrow, bail rather than smush everything
        if (maxVisibleWidth < 150) {
            inputElements.show();
            optLists.show();
            paragraphs.show();
            return;
        }

        // set the size attribute on input elements, limiting it so that
        // it won't overflow our container
        inputElements.each(function(index, element){
            // if this element doesn't specify a size, nothing to do
            if ($(element).attr('size') === undefined) {
                return;
            }

            var parentWidth = $(element).parent().width(),
                maxWidth = parentWidth - margin,
                tgtSize = $(element).data('initial-size');

            // shrink till it fits
            while (tgtSize > 10 && $(element).width() > maxWidth) {
                tgtSize = tgtSize - 1;
                $(element).attr('size', tgtSize);
            }
        });

        // set the max widths on option lists and paragraphs
        optLists.each(function(index, element) {
            var parentWidth = $(element).parent().width(),
                tgtWidth = parentWidth - margin;

            $(element).css('max-width', tgtWidth + 'px');

            // on screens wide enough for the button panel to appar to
            // the right of the widgets, if the button panel isn't empty,
            // leave space
            if (window.innerWidth > 768) {
                var btnPanel = $(".mv-form-additional-html", $(element).parent());
                if ($(btnPanel).children().length > 0) {
                    tgtWidth = tgtWidth - $(btnPanel).width();
                }
            }
            $("select", element).css('max-width', tgtWidth + "px");
            $(".mv-checkboxset", element).css('max-width', tgtWidth + "px");
        });
        paragraphs.each(function(index, element) {
            var parentWidth = $(element).parent().width();
            $(element).css('max-width', (parentWidth - margin) + 'px');
        });

        // set everything we've adjusted to display
        inputElements.show();
        optLists.show();
        paragraphs.show();
        helpCells.show();

        // adjust heights for checkbox sets
        $(".mv-checkboxset-item", ".mv-form-table .mv-checkboxset").each(function(index, element){
            adjustElementHeight(element);
        });
        $(".mv-form-table .mv-checkboxset").each(function(index, element) {
            adjustElementHeight($(element).parent());
        });

        // look for checkbox sets where our contents push us too wide
        // because breaking on spaces was not aggressive enough
        // (happens most often for longish terms without any embedded
        // spaces like InteractiveResource, MovingImage,
        // PhysicalObject, etc)
        var needsAdjustment = [];
        $(".mv-form-table .mv-checkboxset").each(function(index, element) {
            var totWidth = 0,
                numRows = $(element).data('num-rows'),
                offset = numRows - 1,
                selExpr = numRows + "n - " + offset;

            // (selector finds the first checkbox container in each row)
            $(".mv-checkboxset-item:nth-child(" + selExpr + ")", element).each(function(index2, element2){
                totWidth += Math.floor($(element2).width());
            });

            if (totWidth > $(element).width()) {
                needsAdjustment.push(element);
            }
        });

        // change word-break on the checkbox sets that need to be adjusted,
        // then re-adjust their heights and the height of their containers
        $.each(needsAdjustment, function(index, element) {
            $(".mv-checkboxset-item", element).css('word-break', 'break-all');
            $(".mv-checkboxset-item", element).each(function(index2, element2){
                adjustElementHeight(element2);
            });
            adjustElementHeight($(element).parent());
        });
    }

    // fix element sizes in FormUIs after the page is loaded
    $(window).on("load", function(){
        saveInitialSizeAttrs();
        setSizeOfFormLabels();
        setSizeOfOptlists();

        // delay the first size adjustment 500ms to let all the other
        // page-setup JS (ckeditor, quicksearch dropdown, etc) run
        // first
        setTimeout(fixSizes, 500);
    });

    // also fix them after the window is resized
    $(window).resize(function(){
        fixSizes();
    });
})();
