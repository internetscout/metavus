/**
* Browser fixes for style.css. This file depends on jQuery.
*/

jQuery(document).ready(function($){
  var majorVersion = parseInt($.browser.version, 10);
  var usa = ["usa", "us", "united states", "united states of america"];

  // show the country if it's outside of the USA
  $(".calendar_events-event.calendar_events-summary .calendar_events-country").each(function(){
    if (jQuery.inArray(jQuery.trim(this.innerHTML).toLowerCase(), usa) === -1) {
      jQuery(this).css("display", "inline").prev().after(",");
    }
  });

  $(".calendar_events-event.calendar_events-summary .calendar_events-region").each(function(){
      jQuery(this).prev().after(", ");
  });

  $(".calendar_events-event.calendar_events-summary .calendar_events-end_date").each(function(){
    if (/^[0-9]+$/.test(jQuery.trim(this.innerHTML))) {
      this.innerHTML = jQuery.trim(this.innerHTML);
      this.parentNode.removeChild(this.previousSibling);
    }
  });

});
