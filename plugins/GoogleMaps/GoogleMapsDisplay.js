// we need to expose the map
var G_Map;
var change_point_provider;
var GoogleMaps_IsMapFullSize;
var GoogleMaps_ToggleFullSizeMap;

(function(){
    const MAX_RETRIES = 5;
    const RETRY_DELAY = 1500;

    var map;
    var point_provider = "X-POINT-PROVIDER-X";
    var app = new GoogleMapApp();
    var gm = google.maps;
    var kml_layer = null;

    $(document).ready(function(){
        var init_lat  = parseFloat($.cookie("GoogleMapLat")) || X-DEFAULT-LAT-X;
        var init_lon  = parseFloat($.cookie("GoogleMapLon")) || X-DEFAULT-LON-X;
        var init_zoom = parseFloat($.cookie("GoogleMapZoom")) || X-DEFAULT-ZOOM-X;
        var init_type = $.cookie("GoogleMapMapTypeId") || google.maps.MapTypeId.ROADMAP;

        map = app.generateMap($(".GoogleMap").get(0), init_lat, init_lon,
                              $.extend({"zoom": init_zoom,
                                        "mapTypeId": init_type }, X-MAP-OPTIONS-X) );

        // Save stuff when the user changes things:
        gm.event.addListener( map, 'center_changed', function(){
            $.cookie("GoogleMapLat", map.getCenter().lat(), {expires: 7});
            $.cookie("GoogleMapLon", map.getCenter().lng(), {expires: 7});
        });
        gm.event.addListener( map, 'zoom_changed', function(){
            $.cookie("GoogleMapZoom", map.getZoom(), {expires: 7});
        });
        google.maps.event.addListener(map, "maptypeid_changed", function(){
            $.cookie("GoogleMapMapTypeId", map.getMapTypeId(), {"expires": 7});
        });

        // expose the map
        G_Map = map;

        // after the map has loaded, add the KML
        var ListenerHandle = gm.event.addListener(
            map, 'tilesloaded', function() {
                draw_kml();
                gm.event.removeListener(ListenerHandle);
            });
    });

    function draw_kml(){
        var url =
            "X-BASE-URL-X" +
            "index.php?P=X-KML-PAGE-X" +
            "&PP="+point_provider +
            "&DP=X-DETAIL-PROVIDER-X" ;

        var err_url =
            "X-BASE-URL-X" +
            "index.php?P=P_GoogleMaps_ReportError";

        var to_retry = [
            gm.KmlLayerStatus.FETCH_ERROR,
            gm.KmlLayerStatus.INVALID_DOCUMENT,
            gm.KmlLayerStatus.TIMED_OUT,
            gm.KmlLayerStatus.UNKNOWN ];

        function try_load(iter_count) {
            // get a timestamp that updates once an hour
            // (Date.now() is miliseconds since the epoch)
            var ts = Math.floor((Date.now() / 1000) / X-KML-CACHE-INTERVAL-X);
            // construct KML object
            var myKml = new gm.KmlLayer(
                url + "&IT="+iter_count + "&TS="+ts,
                {map: map, preserveViewport: true });

            // add callback to check status after load
            gm.event.addListener(
                myKml, 'status_changed', function() {

                    // if load should be retried and iter count is not exceeded
                    if (iter_count <= MAX_RETRIES &&
                        to_retry.indexOf(myKml.getStatus()) != -1) {
                        // retry the load
                        setTimeout(
                            function(){ try_load(iter_count+1); }, RETRY_DELAY);
                    }

                    // if load was successful
                    if (myKml.getStatus() == gm.KmlLayerStatus.OK) {
                        // remove any previous kml layers
                        if (kml_layer !== null) {
                            kml_layer.setMap(null);
                        }
                        kml_layer = myKml;
                    } else {
                        // otherwise, log an error
                        var msg = "Unable to load KML layer from " + url +
                            " Status was " + myKml.getStatus() +
                            " (IT=" + iter_count + ")";
                        console.log(msg);
                        $.ajax({
                            url: err_url,
                            method: "POST",
                            data: {
                                msg: msg
                            }
                        });
                    }
                });
        }

        try_load(0);
    }

    change_point_provider = function(new_pp){
        point_provider = new_pp;
        draw_kml();
    }

    GoogleMaps_IsMapFullSize = function() {
      return jQuery(".GoogleMap").hasClass("GoogleMaps-FullSize");
    };

    GoogleMaps_ToggleFullSizeMap = function() {
      var mapWrapper = jQuery(".GoogleMap"),
          center = map.getCenter();

      // preserve the center when resizing
      google.maps.event.addListenerOnce(map, "resize", function(){
        map.setCenter(center);
      });

      if (mapWrapper.hasClass("GoogleMaps-FullSize")) {
        mapWrapper.removeClass("GoogleMaps-FullSize");
      } else {
        mapWrapper.addClass("GoogleMaps-FullSize");
      }

      // trigger a resize so that the tiles update
      google.maps.event.trigger(map, "resize");
    };
})();
