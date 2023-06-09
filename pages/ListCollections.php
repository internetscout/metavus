<?PHP
#
#   FILE:  ListCollections.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2021 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# VALUES PROVIDED to INTERFACE (REQUIRED):
#   $H_Collections - Collections visible to the current user (array of
#       Records, with Record IDs for the index).
#
# @scout:phpstan

namespace Metavus;

# ----- MAIN -----------------------------------------------------------------

# load visible collections
$CFactory = new CollectionFactory();
$AllCollections = $CFactory->getItems();
$H_Collections = [];
$User = User::getCurrentUser();
foreach ($AllCollections as $CollectionId => $Collection) {
    if ($Collection->userCanView($User)) {
        $H_Collections[$CollectionId] = $Collection;
    }
}
