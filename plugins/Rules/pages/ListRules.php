<?PHP
#
#   FILE:  ListRules.php (Rules plugin)
#
#   Part of the Metavus digital collections platform
#   Copyright 2016-2020 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#

use Metavus\Plugins\Rules\Rule;
use Metavus\Plugins\Rules\RuleFactory;
use Metavus\TransportControlsUI;
use ScoutLib\StdLib;

# check authorization to see rule list
CheckAuthorization(PRIV_COLLECTIONADMIN, PRIV_SYSADMIN);

# retrieve sort parameters from URL
$DefaultSortField = "Name";
$SortField = StdLib::getFormValue(TransportControlsUI::PNAME_SORTFIELD, $DefaultSortField);
$ReverseSort = StdLib::getFormValue(TransportControlsUI::PNAME_REVERSESORT, false);

# load rule IDs
$H_Items = [];
$RFactory = new RuleFactory();
foreach ($RFactory->getItemIds() as $ItemId) {
    $H_Items[$ItemId] = new Rule($ItemId);
}

# sort Rules
$FieldValueGetters = [
    "Name" => function ($Rule) {
        return $Rule->name();
    },
    "Criteria" => function ($Rule) {
        return $Rule->searchParameters()->textDescription();
    },
    "Frequency" => function ($Rule) {
        return $Rule->checkFrequency();
    },
    "Enabled" => function ($Rule) {
        return $Rule->enabled();
    }
];
if (!isset($FieldValueGetters[$SortField])) {
    throw new Exception("Invalid sort field: ".$SortField);
}
$FieldValueFn = $FieldValueGetters[$SortField];
$Comparator = function ($RuleA, $RuleB) use ($FieldValueFn, $ReverseSort) {
    $Comparison = $FieldValueFn($RuleA) <=> $FieldValueFn($RuleB);
    return ($ReverseSort ? -1 : 1) * $Comparison;
};
uasort($H_Items, $Comparator);

# get total number of items
$H_ItemCount = count($H_Items);

# get where we currently are in list
$H_StartingIndex = StdLib::getFormValue(TransportControlsUI::PNAME_STARTINGINDEX, 0);

# calculate ID array checksum and reset paging if list has changed
$H_ListChecksum = md5(serialize(array_keys($H_Items)));
if ($H_ListChecksum != StdLib::getFormValue("CK")) {
    $H_StartingIndex = 0;
}

# prune item array down to just currently-selected segment
$H_ItemsPerPage = 25;
$H_Items = array_slice($H_Items, $H_StartingIndex, $H_ItemsPerPage, true);
