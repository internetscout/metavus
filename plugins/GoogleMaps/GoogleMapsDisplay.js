/**
 * FILE:  GoogleMapsDisplay.js
 *
 * Part of the Metavus digital collections platform
 * Copyright 2023 Edward Almasy and Internet Scout Research Group
 * http://metavus.net
 *
 * Display a Google Map in the UI and configure all the necessary
 * supporting javascript.
 */

// public global to hold JS object representing the map
var G_Map;

// public functions that will be defined in our IIFE
var googleMapsChangePointProvider;
var googleMapsIsMapFullSize;
var googleMapsToggleFullSize;

// backward compatibility shims for legacy names
function change_point_provider(NewPointProvider) {
    console.log(
        "Legacy function change_point_provider() called."
        + " Should be googleMapsChangePointProvider."
    );
    googleMapsChangePointProvider(NewPointProvider);
}
function GoogleMaps_IsMapFullSize() {
    console.log(
        "Legacy function GoogleMaps_IsMapFullSize() called."
        + " Should be googleMapsIsMapFullSize."
    );
    return googleMapsIsMapFullSize();
}
function GoogleMaps_ToggleFullSizeMap() {
    console.log(
        "Legacy function GoogleMaps_ToggleFullSizeMap() called."
            + " Should be googleMapsTOggleFullSize."
    );
    return googleMapsToggleFullSize();
}

(function(){

    // ---- PUBLIC INTERFACE -------------------------------------------------

    /**
     * Update the hash used to look up the point provider function
     * that will generate map locations in the KML layer used to
     * display map markers.
     * @param string NewPointProvider Hash of desired point provider.
     */
    googleMapsChangePointProvider = function(NewPointProvider){
        PointProvider = NewPointProvider;
        LoadKml(0);
    };

    /**
     * Determine if the current google map is full size (i.e. filling
     * 100% of both the width and height of the browser window).
     * @return bool TRUE for full screen maps.
     */
    googleMapsIsMapFullSize = function() {
        return $(".GoogleMap").hasClass("GoogleMaps-FullSize");
    };

    /**
     * Toggle full size status for the Google Map on the current page.
     */
    googleMapsToggleFullSize = function() {
        var mapWrapper = $(".GoogleMap"),
            center = G_Map.getCenter();

        // preserve the center when resizing
        google.maps.event.addListenerOnce(G_Map, "resize", function(){
            G_Map.setCenter(center);
        });

        if (mapWrapper.hasClass("GoogleMaps-FullSize")) {
            mapWrapper.removeClass("GoogleMaps-FullSize");
        } else {
            mapWrapper.addClass("GoogleMaps-FullSize");
        }

        // trigger a resize so that the tiles update
        google.maps.event.trigger(G_Map, "resize");
    };


    // ---- PRIVATE INTERFACE ------------------------------------------------

    const MAX_RETRIES = 5;
    const RETRY_DELAY = 1500;

    var KmlLayer = null,
        PointProvider = "X-POINT-PROVIDER-X",
        ErrorReportUrl = "X-BASE-URL-X" +
            "index.php?P=P_GoogleMaps_ReportError",
        ErrorsToRetry = [
            google.maps.KmlLayerStatus.FETCH_ERROR,
            google.maps.KmlLayerStatus.INVALID_DOCUMENT,
            google.maps.KmlLayerStatus.TIMED_OUT,
            google.maps.KmlLayerStatus.UNKNOWN
        ];

    /**
     * Create a Google Map, set up event handlers for our custom
     * functionality, and load a KML layer.
     * (The HTML that the GoogleMaps API has us output starts off as
     * an empty div. The GMaps API calls in here transform it from an
     * empty and non-functional HTML element to a functioning Google
     * Map with all the associated supporting Javascript objects,
     * events, etc.)
     */
    function initializeMap() {
        // get default values
        var DefaultLat  = parseFloat($.cookie("GoogleMapLat")) || X-DEFAULT-LAT-X,
            DefaultLong  = parseFloat($.cookie("GoogleMapLng")) || X-DEFAULT-LON-X,
            DefaultZoom = parseFloat($.cookie("GoogleMapZoom")) || X-DEFAULT-ZOOM-X,
            DefaultType = $.cookie("GoogleMapMapTypeId") || google.maps.MapTypeId.ROADMAP,
            CustomStyles = X-MAP-STYLES-X,
            MapOptions = $.extend(
                { "zoom": DefaultZoom, "mapTypeId": DefaultType },
                X-MAP-OPTIONS-X
            );

        // set the center for our new map
        MapOptions.center = new google.maps.LatLng(DefaultLat, DefaultLong);

        // if there were custom styles, add them
        if (!$.isEmptyObject(CustomStyles)) {
            MapOptions.styles = CustomStyles;
        }

        // construct the map
        G_Map = new google.maps.Map( $(".GoogleMap").get(0), MapOptions );

        // set up event listeners to save map paramters when the user changes things
        google.maps.event.addListener( G_Map, 'center_changed', function(){
            $.cookie("GoogleMapLat", G_Map.getCenter().lat(), {expires: 7});
            $.cookie("GoogleMapLng", G_Map.getCenter().lng(), {expires: 7});
        });
        google.maps.event.addListener( G_Map, 'zoom_changed', function(){
            $.cookie("GoogleMapZoom", G_Map.getZoom(), {expires: 7});
        });
        google.maps.event.addListener(G_Map, "maptypeid_changed", function(){
            $.cookie("GoogleMapMapTypeId", G_Map.getMapTypeId(), {"expires": 7});
        });
        // maptypeid_changed fires when the type of the map is changed
        // (e.g., from topographic to satellite, etc)

        // after the map has loaded, add the KML
        var ListenerHandle = google.maps.event.addListener(
            G_Map, 'tilesloaded', function() {
                google.maps.event.removeListener(ListenerHandle);
                LoadKml(0);
            });
    }

    /**
     * Attempt to load a new KML layer.
     * @param int RetryCount Number of times we've tried to use this
     *   layer so far. External callers should pass 0.
     */
    function LoadKml(RetryCount) {
        var Url = "X-BASE-URL-X"
            + "index.php?P=X-KML-PAGE-X"
            + "&PP=" + PointProvider
            + "&DP=X-DETAIL-PROVIDER-X",
            TS = Math.floor((Date.now() / 1000) / X-KML-CACHE-INTERVAL-X);
        // TS: a timestamp that updates once every KML Cache Intervals
        // (= number of cache intervals since the epoch, since
        // Date.now() / 1000 is seconds since the epoch)

        // construct KML object
        var NewKmlLayer = new google.maps.KmlLayer(
            Url + "&IT=" + RetryCount + "&TS=" + TS,
            {map: G_Map, preserveViewport: true });

        // add callback to check status after load
        google.maps.event.addListener(
            NewKmlLayer, 'status_changed', function() {
                handleKmlStatusChange(NewKmlLayer, RetryCount);
            }
        );
    }

    /**
     * Handle changes to the status of a Google Maps KML object after
     *   the GET to fetch data has finished.
     * @param google.maps.KmlLayre NewKmlLayer KML Layer whose status has changed.
     * @param int RetryCount Number of retires so far when loading
     *   data for this layer. Used for retry logic.
     */
    function handleKmlStatusChange(NewKmlLayer, RetryCount) {
        // if load was successful
        if (NewKmlLayer.getStatus() == google.maps.KmlLayerStatus.OK) {
            // clear previous kml layers
            if (KmlLayer !== null) {
                KmlLayer.setMap(null);
            }
            KmlLayer = NewKmlLayer;
            return;
        }

        // if load failed but should be retried and max iteration count is not exceeded
        if (RetryCount <= MAX_RETRIES &&
            ErrorsToRetry.indexOf(NewKmlLayer.getStatus()) != -1) {
            setTimeout(
                function(){
                    LoadKml(RetryCount + 1);
                },
                RETRY_DELAY
            );
            return;
        }

        // otherwise, log an error to the console and POST it to our
        // error report URL
        var msg = "Unable to load KML layer from " + url +
            " Status was " + NewKmlLayer.getStatus() +
            " (IT=" + RetryCount + ")";
        console.log(msg);
        $.ajax({
            url: ErrorReportUrl,
            method: "POST",
            data: {
                msg: msg
            }
        });
    }


    // ---- PAGE SETUP -------------------------------------------------------

    // initialize our map after the document is ready
    $(document).ready(function(){
        initializeMap();
    });
})();
