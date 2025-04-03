<?PHP
#
#   FILE:  NewSavedSearch.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2011-2020 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

use Metavus\FormUI;
use Metavus\Plugins\SavedSearchMailings;
use Metavus\SavedSearch;
use Metavus\SearchParameterSet;
use Metavus\User;
use ScoutLib\ApplicationFramework;
use ScoutLib\StdLib;

# ----- MAIN -----------------------------------------------------------------

# retrieve user currently logged in
$User = User::getCurrentUser();

# check if user is logged in to avoid errors if user gets here by link
if (!CheckAuthorization()) {
    return;
}

# retrieve search parameters
$SearchParams = new SearchParameterSet();
$SearchParams->UrlParameters($_GET);

# if search parameters aren't found get from form value
if ($SearchParams->ParameterCount() == 0) {
    $SearchParams = new SearchParameterSet(StdLib::getFormValue("SearchParams"));
}

# set form fields with search parameters
$FormFields = [
    "SearchName" => [
        "Type" => FormUI::FTYPE_TEXT,
        "Label" => "Search Name",
        "Placeholder" => "Search Name",
        "Required" => true,
    ],
    "SearchCriteria" => [
        "Type" => FormUI::FTYPE_CUSTOMCONTENT,
        "Label" => "Search Criteria",
        "ReadOnly" => true,
        "Content" => $SearchParams->TextDescription(),
    ],
];

if ($GLOBALS["G_PluginManager"]->PluginEnabled("SavedSearchMailings")) {
    $FormFields["Email"] = [
        "Type" => FormUI::FTYPE_OPTION,
        "Label" => "Email",
        "Options" => SavedSearchMailings::GetFrequencyOptions($User),
        "Value" => SavedSearch::SEARCHFREQ_NEVER,
    ];
}

# instantiate FormUI using defined fields
$H_FormUI = new FormUI($FormFields);

# add search parameter data as a hidden field
$H_FormUI->AddHiddenField("SearchParams", $SearchParams->Data());

# act on any button press
$ButtonPushed = StdLib::getFormValue("Submit");
$AF = ApplicationFramework::getInstance();
switch ($ButtonPushed) {
    case "Save":
        #check values and bail out if any are invalid
        if ($H_FormUI->ValidateFieldInput()) {
            return;
        }

        # get search parameters from form
        $SearchParams = new SearchParameterSet(StdLib::getFormValue("SearchParams"));

        # get updated values from form
        $SearchValues = $H_FormUI->GetNewValuesFromForm();

        # set email frequency
        $Frequency = $GLOBALS["G_PluginManager"]->PluginEnabled("SavedSearchMailings") ?
            $SearchValues["Email"] : SavedSearch::SEARCHFREQ_NEVER;

        # save search
        $NewSavedSearch = new SavedSearch(
            null,
            $SearchValues["SearchName"],
            $User->Id(),
            $Frequency,
            $SearchParams
        );

        # jump to list saved searches
        $AF->SetJumpToPage("index.php?P=ListSavedSearches");
        break;
    case "Cancel":
        # jump to list saved searches
        $AF->SetJumpToPage("index.php?P=ListSavedSearches");
        break;
}
