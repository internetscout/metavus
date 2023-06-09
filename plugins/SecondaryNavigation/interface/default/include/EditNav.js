/**
 * FILE:  EditNav.js
 *
 * Part of the Metavus digital collections platform
 * Copyright 2020 Edward Almasy and Internet Scout Research Group
 * http://scout.wisc.edu
 *
 * Sorting functionality for SecondaryNavigation's EditNav page
 */

// global variable used to stop submission without changes
var initialData;
/**
* Set up interactive NavMenu ordering
*/
function NavMenuOrder() {
    function saveData() {
        var newOrder = $('.sortable > ul').nestedSortable('serialize');

        if (newOrder == window.initialData) {
          return;
        }

        $.ajax({type: "POST",
            url: CWIS_BASE_URL + "index.php?P=P_SecondaryNavigation_EditNavComplete",
            data: {newOrder: newOrder},
            success: function(data){
              updateNavContent();
            },
            error: function(jqXHR, textStatus, errorThrown){
              console.log(textStatus, errorThrown);
            }
          });
    }

  $('.sortable > ul').nestedSortable({
    handle: 'div',
    listType:'ul',
    items: 'li',
    toleranceElement: '> div',
    maxLevels: 1,
      stop: saveData
  });

  window.initialData = $('.sortable > ul').nestedSortable('serialize');
}

/**
 * Replace sidebar content with updated html
 */
function updateNavContent(){
    //make the Ajax call
    $.ajax({type: "POST",
            url: CWIS_BASE_URL + "index.php?P=P_SecondaryNavigation_GetNavContent",
            data: {},
            dataType: "html",
            success: function(data) {
              $("#mv-secondary-navigation-menu").replaceWith(data);
            },
            error: function(jqXHR, textStatus, errorThrown) {
                console.log(textStatus, errorThrown);
            }
           });
          }