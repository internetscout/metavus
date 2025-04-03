<?PHP
#
#   FILE:  BuildFeed.php
#   Part of the Metavus digital collections platform
#   Copyright 2023 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# VALUES PROVIDED to INTERFACE (REQUIRED):
#   $H_Form - FormUI form for entering parameters to create new feed.
#
# VALUES PROVIDED to INTERFACE (OPTIONAL):
#   $H_CreatedFeed - Set if a new feed was just created, this is an array
#           with indexes of "Title", "Description", and "Link", for the
#           feed title, feed description, and feed URL, respectively.
#   $H_UserPastFeeds - Set if a user is logged in and has created feeds
#           before.  Up to three entries, for the user's three most
#           recently-created feeds, with the feed URLs for the the array
#           index and an array with "Title" and "Description" elements for
#           the values.
#
# @scout:phpstan

namespace Metavus;
use Exception;
use Metavus\Plugins\CalendarFeed;
use ScoutLib\ApplicationFramework;
use ScoutLib\StdLib;

$FormFields = [
    "Keywords" => [ "Type"  =>  FormUI::FTYPE_PARAGRAPH,
        "Label" => "Keywords",
        "Help" => "Events that mention these keywords will be included in your"
                ." feed.<br/>Keywords on each line will be ANDed together"
                ." (for an event to match that line, it must contain all the"
                ." search terms that appear on that line)."
                ."<br/>The lines will be ORed together (events can match"
                ." any one line and be selected).",
    ],
    "FeedTitle" => [ "Type" => FormUI::FTYPE_TEXT,
        "Label" => "Feed Title",
        "Help" => "(May be displayed by some calendar apps.)  (OPTIONAL)",
        "Placeholder" => "(title)",
        "Required" => false,
    ],
];

# ----- LOCAL FUNCTIONS ------------------------------------------------------

/**
 * Construct Search Parameters from lines of text in a paragraph input.
 * @param string $BlockOfText Text to process into a SearchParameterSet.
 * @return SearchParameterSet Search parameters with the keywords from
 *         each line AND'ed together and those lines are OR'd together.
 */
function getSearchParamsForKeywords(string $BlockOfText): SearchParameterSet
{
    $SearchParams = new SearchParameterSet();

    $Lines = preg_split('/\n/', $BlockOfText);
    if ($Lines === false) {
        return $SearchParams;
    }

    $SearchParams->logic("OR");
    foreach ($Lines as $Line) {
        # don't add a search parameter for an empty line
        if (strlen(trim($Line)) > 0) {
            $SearchParams->addParameter($Line);
        }
    }

    return $SearchParams;
}


/**
 * Construct search parameters to restrict events in the feed to those which
 * are either upcoming or have occurred up to one year in the past.
 * @return SearchParameterSet   Search parameters that will match events that
 *                              are upcoming or a up to one year in the past.
 */
function getEventDateParameters(): SearchParameterSet
{
    $SchemaId = MetadataSchema::getSchemaIdForName("Events");
    $Schema = new MetadataSchema($SchemaId);
    $Field = $Schema->getField("End Date");

    $Params =  new SearchParameterSet();

    # include all future events
    $Params->addParameter(">= now", $Field);

    # include all events up to one year in the past
    $Params->addParameter("< one year ago", $Field);
    $Params->logic("OR");

    return $Params;
}


/**
 *  Construct search parameters from form inputs that specify matching events
 *  to be included in the feed.
 *  @param  array $FormValues   Input values from FormUI controls.
 *  @return SearchParameterSet  Search parameters to find matching events
 *                              to include in a feed.
*/
function buildSearchParameterSet(array $FormValues): SearchParameterSet
{
    $FeedSearchParams = new SearchParameterSet();

    $KeywordParams =  getSearchParamsForKeywords($FormValues["Keywords"]);

    $AlreadyProcessed = ["Keywords", "FeedTitle"];
    # remove already-processed keys from the form input values
    $ExtraFields = array_diff_key($FormValues, array_flip($AlreadyProcessed));

    $ExtraParams = getSearchParamsForAdditionalFields($ExtraFields);

    if ($KeywordParams->parameterCount() > 0) {
        $FeedSearchParams->addSet($KeywordParams);
    }

    if ($ExtraParams->parameterCount() > 0) {
        $FeedSearchParams->addSet($ExtraParams);
    }

    $FeedSearchParams->addSet(getEventDateParameters());

    return $FeedSearchParams;
}


/**
 *  Get search parameters from form input for up to 3 additional Option,
 *  Controlled Name, or Tree fields as specified in this plugin's configuration.
 *  @param array $AdditionalFields   Array of additional search fields
 *       and values to process into search parameters.
 *       The array's keys are formatted: ${MetadatFieldId}-${Value}.
 *  @return SearchParameterSet  Search Parameter set containing values of
 *                              parameters for additional search fields.
*/
function getSearchParamsForAdditionalFields(
    array $AdditionalFields
): SearchParameterSet {
    $ExtraParams = new SearchParameterSet();
    foreach ($AdditionalFields as $FieldKey => $FieldValue) {
        if (empty($FieldValue)) {
            continue;
        }
        [$MFieldId, $FieldName] = explode("-", $FieldKey);
        $MField = MetadataField::getField((int)$MFieldId);

        # multiple values are ORd together
        $FieldValues = is_array($FieldValue) ? $FieldValue : [$FieldValue];

        # omit search parameter set for this field if all of the
        # available options for the field are selected
        if (count($FieldValues) == $MField->getCountOfPossibleValues()) {
            continue;
        }

        $AdditionalFieldSearchParams = new SearchParameterSet();
        $AdditionalFieldSearchParams->logic("OR");

        foreach ($FieldValues as $FieldValue) {
            switch ($MField->type()) {
                case MetadataSchema::MDFTYPE_OPTION:
                case MetadataSchema::MDFTYPE_CONTROLLEDNAME:
                        $AdditionalFieldSearchParams->addParameter(
                            "=".$FieldValue,
                            $MField
                        );
                    break;
                case MetadataSchema::MDFTYPE_TREE:
                    $CFactory = new ClassificationFactory($MField->id());
                    if ($CFactory->itemExists($FieldValue)) {
                        $CName = new Classification($FieldValue);
                        # "^ItemId" is recognized by SearchEngine as a search
                        # for ItemId or descendants
                        $AdditionalFieldSearchParams->addParameter(
                            "^".$CName->id(),
                            $MField
                        );
                    }
                    break;
                default:
                    throw new Exception(
                        "Invalid metadata field type encountered."
                    );
            }
        }
        # add the OR'ed set of values for the field to the whole set
        # of additional search parameters
        $ExtraParams->addSet($AdditionalFieldSearchParams);
    }
    return $ExtraParams;
}


/**
 *  Set up FormUI form controls for up to 3 additional Option, Controlled Name,
 *  or Tree fields as specified in the plugin's configuration.
 *  @return array  Array with FormUI parameters for additional search fields.
*/
function getFormFieldsForAdditionalFeedParams(): array
{
    $CalendarFeedPlugin = CalendarFeed::getInstance();
    $ExtraFields = $CalendarFeedPlugin->getConfigSetting("BuildFeedAdditionalFields");
    $ExtraFields = is_null($ExtraFields) ? [] : $ExtraFields;

    # this many or fewer possible values for a field will be
    # displayed as as checkboxes, all selected by default
    $OptionThreshold = 15;

    $FormUIFieldsToAdd = [];
    foreach ($ExtraFields as $FieldId) {
        $MField  = MetadataField::getField($FieldId);
        $MDFType = $MField->type();

        # when the number of possible values is below the threshold,
        # all of them are selected by default
        $PossibleValues = $MField->getPossibleValues(100);
        $DefaultValues =
                count($PossibleValues) <= $OptionThreshold ?
                array_keys($PossibleValues) : [];

        $FieldToAdd = [
            "Label" => StdLib::pluralize($MField->getDisplayName()),
            "Type" => FormUI::FTYPE_OPTION,
            "Required" => false,
            "Options" => $PossibleValues,
            "Default" => $DefaultValues,
            "AllowMultiple" => true,
            "OptionThreshold" => $OptionThreshold
        ];

        $FormControlName = $MField->id() . "-" . $MField->name();
        $FormUIFieldsToAdd[$FormControlName] = $FieldToAdd;
    }

    return $FormUIFieldsToAdd;
}



# ----- MAIN -----------------------------------------------------------------

$FormFields = array_merge(
    getFormFieldsForAdditionalFeedParams(),
    $FormFields
);
$H_Form = new FormUI($FormFields);

$CalendarFeedPlugin = CalendarFeed::getInstance();

$UserId = (User::getCurrentUser())->id();

if ($UserId != null) {
    $H_UserPastFeeds = $CalendarFeedPlugin->getPastFeedsForUserId($UserId);
}

switch ($H_Form->getSubmitButtonValue()) {
    case "Create":
        $FormValues = $H_Form->getNewValuesFromForm();
        $ResultParams = buildSearchParameterSet($FormValues);
        $H_CreatedFeed["Title"] =
            isset($FormValues["FeedTitle"]) ? $FormValues["FeedTitle"] : null;
        $H_CreatedFeed["Link"] = CalendarFeed::getFeedUrl($ResultParams, $H_CreatedFeed["Title"]);
        $H_CreatedFeed["Description"] = $ResultParams->textDescription();
        if (($ResultParams->parameterCount() > 0) && ($UserId != null)) {
            $CalendarFeedPlugin->saveFeedForUserId($UserId, $ResultParams, $H_CreatedFeed["Title"]);
        }
        break;
}
