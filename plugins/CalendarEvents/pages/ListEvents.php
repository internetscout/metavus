<?PHP
#
#   FILE:  ListEvents.php (CalendarEvents plugin)
#
#   Part of the Metavus digital collections platform
#   Copyright 2013-2022 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#

# ----- MAIN -----------------------------------------------------------------
use Metavus\Plugins\CalendarEvents;
use Metavus\Plugins\CalendarEvents\Event;
use Metavus\Plugins\CalendarEvents\EventFactory;
use Metavus\User;
use ScoutLib\StdLib;

/**
* Helper function to generate SQL to query fields from the database.
* @param string $FieldName Field name without the schema ID
* @param string $Condition Condition describing the test
* @return SQL fragment for the requested field
*/
function GetSqlForField($FieldName, $Condition)
{
    $Plugin = CalendarEvents::getInstance();

    if (($Condition == "contains") && preg_match("/Date/", $FieldName)) {
        # when we do a Contains on a date, translate the contents of
        # the field to a catenation of common formats, so that a
        # substring search can match against these.
        $Field = "DATE_FORMAT(".$FieldName.$Plugin->GetSchemaId().", "
            ."'%M %d %Y %M %d, %Y %Y-%m-%d %m/%d/%Y')";
    } else {
        # otherwise just tack on the SchemaId
        $Field = $FieldName.$Plugin->GetSchemaId();
    }

    return $Field;
}

PageTitle("Calendar Events");

# get the plugin
$Plugin = CalendarEvents::getInstance();

# don't allow unauthorized access
if (!$Plugin->UserCanEditEvents(User::getCurrentUser())) {
    CheckAuthorization(false);
    return;
}

# set up some basic values
$ModificationField = "Events: Date Last Modified";
$SortField = StdLib::getFormValue("SF", $ModificationField);
$ReverseSort = StdLib::getFormValue("RS", 0);
$H_StartingIndex = StdLib::getFormValue("SI", 0);
$H_EventsPerPage = 50;
$H_SchemaId = $Plugin->GetSchemaId();

$EFactory = new EventFactory();
$EFactory->limitRetrievedEventsToOwnerId(false);

$H_EventCountsByTense = $EFactory->GetEventCountsByTense();

# load IDs of users that meet specified search criteria
if (StdLib::getFormValue("F_Field") && StdLib::getFormValue("F_Condition") &&
    strlen(StdLib::getFormValue("F_SearchText"))) {
    $TgtField = StdLib::getFormValue("F_Field");

    # Figure out the condition part of the query:
    $ConditionMap = [
        "contains" => "contains",
        "equals" => "=",
        "is before" => "<",
        "is after" => ">",
    ];
    $Condition = $ConditionMap[StdLib::getFormValue("F_Condition")];
    $SearchText = StdLib::getFormValue("F_SearchText");
    if ($Condition == "contains") {
        $Target = "LIKE '%".addslashes($SearchText)."%'";
    } else {
        # if we're dealing with a date field, try to interpret
        # the search text as a date:
        if (preg_match("/Date/", $TgtField)) {
            $SearchText = date('Y-m-d', strtotime($SearchText));
        }

        # otherwise use the string as typed
        $Target = $Condition." '".addslashes($SearchText)."'";
    }

    # determine which fields to search
    if ($TgtField == "ALL") {
        $FieldsToProcess = [
            "Title",
            "ShortTitle",
            "Description",
            "ContactEmail",
            "URL",
            "Venue",
            "Locality",
            "Region",
            "StartDate",
            "EndDate",
        ];
    } else {
        $FieldsToProcess = [$TgtField];
    }

    # iterate over the requested fields, gluing together a query
    # that searches across all of them:
    foreach ($FieldsToProcess as $Field) {
        $Field = GetSqlForField($Field, $Condition);

        if (isset($WhereClause)) {
            $WhereClause .= " OR ".$Field." ".$Target;
        } else {
            $WhereClause = " ".$Field." ".$Target;
        }
    }
} else {
    $WhereClause = null;
}

# fetch the events
$EventIds = array_intersect(
    $EFactory->getRecordIdsSortedBy($SortField, $ReverseSort),
    $EFactory->getItemIds($WhereClause)
);

$H_EventCount = count($EventIds);

# calculate ID array checksum and reset paging if list has changed
$H_ListChecksum = md5(serialize($EventIds));
if ($H_ListChecksum != StdLib::getFormValue("CK")) {
    $H_StartingIndex = 0;
}

# crop the events to the currently-selected page
$EventIds = array_slice($EventIds, $H_StartingIndex, $H_EventsPerPage);

$H_Events = [];
foreach ($EventIds as $Id) {
    $H_Events[$Id] = new Event($Id);
}
