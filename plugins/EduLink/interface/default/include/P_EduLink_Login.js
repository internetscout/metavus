/**
 * FILE:  P_EduLink_Login.js
 *
 * Part of the Metavus digital collections platform
 * Copyright 2024 Edward Almasy and Internet Scout Research Group
 * http://metavus.net
 *
 * Supporting JS functions for the LTI Deep Linking login interface.
 *
 * For official docs on the Storage Access API being used below, see
 * https://developer.mozilla.org/en-US/docs/Web/API/Storage_Access_API
 *
 * However, when Safari's "prevent cross-site tracking" option is enabled, it
 * imposes additional restrictions that are not part of the standard API. In an
 * iframe, the cookie permission window that *should* appear when
 * requestStorageAccess() is called from a click handler will instead be
 * suppressed until after a requestStorageAccess() call is made from click
 * handler in a standalone window for the same origin. After that has been
 * done, a second click is required to repeat the requestStorageAccess() call
 * and have the cookie permission window appear.
 *
 * After the above dance is completed, Safari will allow storage
 * access for 30 days without additional user confirmation (this seems
 * to be hardcoded; see the 'Safari' section under 'Browser-specific
 * variations' in the MDN Storage Access API docs linked above). Using
 * the JS storage access API again will reset the timer and as long as
 * we still have access will not prompt the user for permission
 * again. Going the JS route every time is undesirable because it
 * introduces several additional pageloads. So, we set a cookie that
 * lives for 15 days (see CookieMaxAge constant) indicating that we've
 * got storage access. When that cookie expires, we go back on the JS
 * path to renew our access and reset the timer.
 */

/**
 * Get permissions to set cookies, set them, and perform a redirect.
 *     (wrapped in an async function because the APIs for manipulating
 *     cookie perms are themselves async)
 * @param string State Opaque string representing the LTI state.
 * @param string BaseUrl Base URL of our Metavus installation.
 * @param string RedirectUrl LTI Redirect Url to complete the LTI
 *     Login flow, or an empty string when invoked from a standalone
 *     first-party window that needs to be closed rather than
 *     redirecting.
 */
async function doRedirect(State, BaseUrl, RedirectUrl) {
    const CookieMaxAge = 15 * 86400;

    var InFrame = (RedirectUrl.length > 0) ? true : false,
        StateCookie = "lti1p3_" + State + "=" + State;

    /**
     * Redirect to provided url or close current window when no
     * redirect URL is available.
     */
    function redirectOrClose() {
        if (InFrame) {
            window.location = RedirectUrl;
        } else {
            window.close();
        }
    }

    /**
     * Attempt to set cookies needed for the LTI Login process.
     * @return bool TRUE when cookies were set
     */
    function setCookies() {
        var OldCookieLength = document.cookie.length
        document.cookie = "lti1p3_enabled=1; Secure; SameSite=none; Max-Age=" + CookieMaxAge;
        document.cookie = StateCookie + "; Secure; SameSite=none; Max-Age=60";

        return document.cookie.length > OldCookieLength;
    }

    // check if the state cookie we needed has already been set
    if (document.cookie.length > 0) {
        var CookieFound = document.cookie.split(";").some(function(Item) {
            return Item.trim() == StateCookie;
        });

        // if so, add our _enabled cookie that indicates future LTI
        // things can use a normal redirect instead of coming here,
        // then redirect
        if (CookieFound) {
            document.cookie = "lti1p3_enabled=1; Secure; SameSite=none; Max-Age=" + CookieMaxAge;
            window.location = RedirectUrl;
            return;
        }
    }

    // try to set a cookie, reirect on success
    if (InFrame && setCookies()) {
        window.location = RedirectUrl;
        return;
    }

    // see if we have permission to request storage access
    var PermissionGranted = false;
    try {
        const Permission = await navigator.permissions.query({
            name: "storage-access",
        });
        if (Permission.state === "granted") {
            PermissionGranted = true;
        }
    } catch (error) {
        console.log(`Could not access permission state. Error: ${error}`);
    }

    // if permission was granted, then we can request storage
    // access for this iframe without user interaction
    if (PermissionGranted) {
        await document.requestStorageAccess();
        if (setCookies()) {
            redirectOrClose();
            return;
        }
    }

    // otherwise, we need the user to manually click a button to
    // grant access
    var ButtonClicked = false,
        Button = document.getElementById("mv-embed-button");

    Button.style.display="unset";

    Button.addEventListener("click", async function() {
        try {
            await document.requestStorageAccess();
        } catch (error) {
            console.log(`Could not obtain storage access. Error: ${error}`);
        }

        if (setCookies()) {
            redirectOrClose();
            return;
        }

        // if we're in a standalone window, nothing else to do
        if (!InFrame) {
            window.close();
            return;
        }

        // if we're in an iframe and this is the second button click, then we
        // can (finally!) do the redirect and expect it to work
        if (ButtonClicked) {
            window.location = RedirectUrl;
            return;
        }

        // make a first-party visit to our origin to set up permission
        window.open(
            BaseUrl + "index.php?P=P_EduLink_CookiePermission&state=" + State,
            '_blank'
        );
        Button.innerHTML = 'Click to enable data sharing';
        ButtonClicked = true;
    });
}
