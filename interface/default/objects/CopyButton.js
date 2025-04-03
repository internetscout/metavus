/**
 * FILE:  CopyButton.js
 *
 * Part of the Metavus digital collections platform
 * Copyright 2024 Edward Almasy and Internet Scout Research Group
 * http://metavus.net
 *
 * @scout:eslint
 */

/**
 * Click handler for copy to clipboard buttons. Expects the button to
 *     be contained in an element with a data-target attribute that will
 *     specify the ID of an element containing the data to be
 *     copied. Displays a 'success' message for 1.5 sec after copying
 *     the specified data, then restores the original button.
 * @param Event Event JS event for this button click.
 */
// eslint-disable-next-line no-unused-vars
function mv_handleCopyButtonClick(Event) {
    var Target = $(Event.target),
        Button = Target.is("img") ? Target.parent() : Target,
        Container = Button.parent(),
        Data = $("#" + Container.data('target')).text();

    navigator.clipboard.writeText(Data)
        .then(function(){
            Button.detach();
            Container.append('<p class="btn btn-success">Copied!</p>');
            setTimeout(
                function() {
                    Container.empty().append(Button);
                },
                1500
            );
        });
}
