<?PHP
#
#   FILE:  RestEndpoint.php
#
#   Part of the ScoutLib application support library
#   Copyright 2024 Edward Almasy and Internet Scout Research Group
#   http://scout.wisc.edu
#
# @scout:phpstan

# This file is intended to be used as an test REST API endpoint with
# the RestHelper_Test.php unit test class.

# process incoming values as expected and output them in JSON form
$Response = [
    "NumberToIncrement" => $_POST["NumberToIncrement"] + 1,
    "StringToReverse" => strrev($_POST["StringToReverse"]),
];
print json_encode($Response);
