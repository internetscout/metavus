/**
 * This file contains extensions to the Google Maps API classes. These require
 * the jQuery JavaScript framework.
 *
 * Google Maps API v3 Reference:
 * http://code.google.com/apis/maps/documentation/v3/reference.html
 */

/**
 * This allows us to inherit a parent class' private and privileged methods and
 * data members. Kudos to Rein Petersen for the work around:
 * http://blog.reinpetersen.com/2008/12/work-around-for-common-inheritance.html
 * @param base:Object_reference parent class to extend
 */
Function.prototype.Extends = function(base){
    function Closure(){};
    Closure.prototype = base.prototype;
    this.prototype = new Closure();
    this.prototype.constructor = this.constructor;
    this.base = base;
};

/**
 * Google Maps application for CWIS. The constructor automatically sets
 * up unloading the Google Maps API functions when the document object is
 * unloaded to avoid memory leaks.
 */
function GoogleMapApp(){
    // automatically unload the Google Maps API
    $(document).unload(google.maps.Unload);
}

/**
 * Generate and return the reference to a GoogleMapMap object.
 * @param mapDiv:Node HTML container in which the map will be created
 * @param lat:number latitude of the center of the map
 * @param lng:number longitude of the center of the map
 * @param opts:Map_options additional map options
 * @return reference reference to the newly created GoogleMap object
 */
GoogleMapApp.prototype.generateMap = function(mapDiv, lat, lng, opts) {
    // default options
    opts = $.extend({
        "zoom": 11,
        "mapTypeId": google.maps.MapTypeId.HYBRID
    }, opts);

    // always want to force this option
    opts.center = new google.maps.LatLng(lat, lng);

    // return a new GoogleMap object
    return new GoogleMap(mapDiv, opts);
};

/**
 * An extension to the google.maps.Map class.
 * @param mapDiv:Node HTML container in which the map will be created
 * @param opts:Map_options additional map options
 */
function GoogleMap(mapDiv, opts){
    // call the constructor of the parent class (google.maps.Map)
    GoogleMap.base.call(this, mapDiv, opts);
}

/**
 * Inherit methods and data members from google.maps.Map.
 */
GoogleMap.Extends(google.maps.Map);

/**
 * Add a google.maps.Marker object to the map.
 * @param marker:Marker marker object to add to the map
 */
GoogleMap.prototype.addMarker = function(marker) {
    marker.setMap(this);
};

/**
 * Generate and add a google.maps.Marker object on the map, and then return the
 * reference to the marker object.
 * @param lat:number marker latitude (center)
 * @param lng:number marker longitude (center)
 * @param opts:Marker_options additional marker options
 * @return reference reference to the newly created marker object
 */
GoogleMap.prototype.generateMarker = function(lat, lng, opts) {
    // default options
    opts = $.extend({}, opts);

    // always want to force these options
    opts.position = new google.maps.LatLng(lat, lng);
    opts.map = this;

    // return a reference to the newly created point
    return new google.maps.Marker(opts);
};

/**
 * An extension to the google.maps.InfoWindow class.
 * @param opts:InfoWindow_options additional info window options
 */
function GoogleInfoWindow(opts) {
    // call the constructor of the parent class (google.maps.InfoWindow)
    GoogleInfoWindow.base.call(this, opts);

    // info window node content
    var $node = $('<div class="strat-info-window">'+
                '<div class="head"></div>'+
                '<div class="message"></div>'+
                '<div class="foot"></div>');

    // add to info window
    this.setContent($node.get(0));

    /**
     * Set the given element of the info window to the given string, DOM node or jQuery object.
     * @param content:string|Node|jQuery content to set the element to
     * @param element:string element of the info window to change
     */
    this.setElementContent = function(content, element) {
        // set the content and make sure the node is still part of the info window
        $(element, $node).html(content);
        this.setContent($node.get(0));
    };
}

/**
 * Inherit methods and data members from google.maps.InfoWindow
 */
GoogleInfoWindow.Extends(google.maps.InfoWindow);

/**
 * Clear all of the elements of the info window.
 */
GoogleInfoWindow.prototype.clearElements = function() {
    this.setHeader("");
    this.setMessage("");
    this.setFooter("");
};

/**
 * Set the header of the info window to the given string, DOM node or jQuery object.
 * @param content:string|Node|jQuery content to change the header to
 */
GoogleInfoWindow.prototype.setHeader = function(content) {
    this.setElementContent(content, ".head");
};

/**
 * Set the message of the info window to the given string, DOM node or jQuery object.
 * @param content:string|Node|jQuery content to change the message to
 */
GoogleInfoWindow.prototype.setMessage = function(content) {
    this.setElementContent(content, ".message");
};

/**
 * Set the footer layer of the info window to the given string, DOM node or jQuery object.
 * @param content:string|Node|jQuery content to change the footer to
 */
GoogleInfoWindow.prototype.setFooter = function(content) {
    this.setElementContent(content, ".foot");
};

/**
 * An extension to the google.maps.LatLng class.
 * @param lat:number latitude
 * @param lng:number longitude
 * @param noWrap:boolean if true, then the numbers will be used as passed (see google.maps.LatLng)
 * @param opts:InfoWindow_options additional info window options
 */
function GoogleLatLng(lat, lng, noWrap) {
    // call the constructor of the parent class (google.maps.LatLng)
    GoogleLatLng.base.call(this, lat, lng, noWrap);
}

/**
 * Inherit methods and data members from google.maps.LatLng
 */
GoogleLatLng.Extends(google.maps.LatLng);

/**
 * Return the latitude in DMS format.
 * Modified from scripts by Chris Veness
 * http://www.movable-type.co.uk/scripts/latlong.html
 * @return string the latitude in DMS format
 */
GoogleLatLng.prototype.latInDms = function() {
    // add 1/2 second for rounding
    var d = Math.abs(this.lat()) + 1/7200;

    // get deg, min, sec, and direction
    var deg = Math.floor(d);
    var min = Math.floor((d-deg)*60);
    var sec = Math.floor((d-deg-min/60)*3600);
    var dir = (this.lat() < 0) ? "S" : "N";

    // return DMS string
    return deg + "&deg; " + min + "' " + sec + '" ' + dir;
};

/**
 * Return the longitude in DMS format.
 * Modified from scripts by Chris Veness
 * http://www.movable-type.co.uk/scripts/latlong.html
 * @return string the longitude in DMS format
 */
GoogleLatLng.prototype.lngInDms = function() {
    // add 1/2 second for rounding
    var d = Math.abs(this.lng()) + 1/7200;

    // get deg, min, sec, and direction
    var deg = Math.floor(d);
    var min = Math.floor((d-deg)*60);
    var sec = Math.floor((d-deg-min/60)*3600);
    var dir = (this.lng() < 0) ? "W" : "E";

    // return DMS string
    return deg + "&deg; " + min + "' " + sec + '" ' + dir;
};

/**
 * Return the latitude and longitude together in DMS format.
 * @return string the latitude/longitude in DMS format
 */
GoogleLatLng.prototype.toDmsString = function() {
    return this.latInDms() + ", " + this.lngInDms();
};

/**
 * CWIS extension to the google.maps.Marker class.
 * @param position:LatLng position of the marker
 * @param opts:MarkerOptions/Object additional marker options
 */
function CwisMarker(opts) {
    // defaults
    opts = $.extend({}, opts);

    // check for non-standard color option
    if ("undefined" != typeof opts.color) {
        opts.icon = CwisMarker.IMAGE_BASE+opts.color;
        opts.shadow = CwisMarker.IMAGE_BASE+"shadow";
        delete opts.color;
    }

    // call the constructor of the parent class (google.maps.Marker)
    CwisMarker.base.call(this, opts);
} CwisMarker.Extends(google.maps.Marker);

/**
 * @const IMAGE_BASE base of the image URL value
 */
CwisMarker.IMAGE_BASE = "local/plugins/GoogleMaps/images/marker-";

/**
 * Change the color of the marker. An invalid color value sets the color to the
 * default.
 * @param color:string new marker color
 */
CwisMarker.prototype.changeColor = function(color) {
    this.setIcon(CwisMarker.IMAGE_BASE+color+".png");
    this.setShadow(CwisMarker.IMAGE_BASE+"shadow.png");
};
