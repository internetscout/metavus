/**
 * FILE:  CW-Confirm.js
 *
 * Part of the Metavus digital collections platform
 * Copyright 2017-2020 Edward Almasy and Internet Scout Research Group
 * http://metavus.net
 *
 * @scout:eslint
 */

/**
 * Confirm a response when triggered. The "affirm" parameter is called when a yes response is
 * given and the "cancel" parameter is called when a no response is given. The "cancel"
 * function can be ommited if not needed (and options passed in its place).
 *
 * 1) The text is configurable with the "question", "yes", "or", and "no" options.
 * 2) The trigger (e.g., click, mousover, etc) can can be specified with the "trigger" option.
 * 3) The show action (e.g., fade in) can be configured via the "showType" option and its
 *    speed can be configured via the "showSpeed" option.
 * 4) A class can be applied to the confirmation element via the "className" option.
 * 5) There are two special options: "setup" is a function that is called after the confirmation
 *    element has been setup and is shown and "takedown" is a function that is called after the
 *    confirmation element has been removed and the caller element is shown again.
 *
 * @param function affirm function called when a "yes" response is selected
 * @param function cancel function called when a "no" response is selected
 * @param object options
 * @return jQuery jQuery object
 * @author Tim Baumgard
 */
function Confirm(affirm, cancel, options) {
    // in case we don't want to specify the cancel function
    if (!$.isFunction(cancel) && "object" == typeof cancel) {
        options = cancel;
    }

    // default options
    options = $.extend({
        "question": "Are you sure?",
        "yes": "Yes",
        "or": "or",
        "no": "No",
        "trigger": "click",
        "showType": "fadeIn",
        "showSpeed": 150,
        "className": null,
        "setup": null,
        "takedown": null
    }, options);

    var $this = this;

    // set up trigger actions
    $this[options.trigger](function(){
        var source = this,
            $parent = $(this),
            $container = $("<span/>"),
            okToRespond = false,
            action;

        $container.attr({"style": "padding-right: 10px;"});

        action = function(fn){
            if (okToRespond) {
                // remove the container, show the parent
                $container.remove();
                $parent.show();

                // call takedown function if given, then call the yes/no function
                if ($.isFunction(options.takedown)) {
                    options.takedown();
                }
                if ($.isFunction(fn)) {
                    fn.call(source, $parent);
                }
            }
            return false;
        };

        // set up "yes" responder
        var $yes = $(document.createElement("A")).attr({"style": "cursor: pointer;"});
        $yes.html(options.yes);
        $yes.click(function(){
            action(affirm);
        });

        // set up "no" responder"
        var $no = $(document.createElement("A")).attr({"style": "cursor: pointer;"});
        $no.html(options.no);
        $no.click(function(){
            action(cancel);
        });

        // set up container
        if ("string" == typeof options.className) {
            $container.addClass(options.className);
        }
        $container.css({"display": "none"});
        $container.html("<b>"+options.question+"</b>&nbsp;");
        $container.append($yes);
        $container.append(" "+options.or+" ");
        $container.append($no);

        // insert the container before the parent and show it
        $parent.hide();
        $parent.before($container);
        $container[options.showType](options.showSpeed);

        // to prevent any quick-click mistakes
        setTimeout(function(){
            okToRespond = true;
        }, 300);

        // call setup function
        if ($.isFunction(options.setup)) {
            options.setup();
        }

        // don't follow an href value
        return false;
    });

    // return jQuery object
    return $this;
}

/** Backward Compatibility: Add our confirm function to jQuery so it can be
* used as $(".things").confirm().
*/
(function(jQuery){
    jQuery.fn.confirm = Confirm;
})(jQuery);
