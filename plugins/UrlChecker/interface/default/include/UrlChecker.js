$(document).ready(function(){
    // hide the submit button and submit the form when an input changes
    $(".Limits select").change(function(){
        $(".Limits form").submit();
    });
});
