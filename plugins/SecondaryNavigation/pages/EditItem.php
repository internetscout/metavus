<?PHP
#
#   FILE:  EditItem.php (SecondaryNavigation plugin)
#
#   Part of the Metavus digital collections platform
#   Copyright 2020 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#

use ScoutLib\StdLib;
use Metavus\FormUI;
use Metavus\Plugins\SecondaryNavigation;
use Metavus\Plugins\SecondaryNavigation\NavItem;
use Metavus\Plugins\SecondaryNavigation\NavMenu;
use Metavus\User;

# retrieve user currently logged in
$User = User::getCurrentUser();

# if user isn't logged in, go home so we don't get errors with user ID
if (!$User->isLoggedIn()) {
    $GLOBALS["AF"]->setJumpToPage("Home");
    return;
}

# get info from form values/set default values
$NavItemId = StdLib::getFormValue("NI");
$ReadOnly = true;
$Link = urldecode(StdLib::getFormValue("AL", ""));
$Label = urldecode(StdLib::getFormValue("PT", ""));
$H_EditPage = false;

# determine if we're adding or editing a NavItem
if (!is_null($NavItemId)) {
    $H_EditPage = true;
    # get current link/label
    $NavItem = new NavItem($NavItemId);
    $Label = $NavItem->label();
    $Link = $NavItem->link();
    $ReadOnly = !$NavItem->createdByUser();
} else {
    # get link for display and save relative URL (to be added to the NavItem)
    $CleanUrl = $Link;
    $Link = $_SERVER["HTTP_REFERER"];
}

# fields to display in FormUI
$Fields = [
    "Label" => [
        "Required" => true,
        "Label" => "Label",
        "Type" => FormUI::FTYPE_TEXT,
        "Value" => $Label,
        "ValidateFunction" => ["Metavus\\Plugins\\SecondaryNavigation", "validateLabel"]
    ],
    "Link" => [
        "Label" => "Link",
        "Type" => FormUI::FTYPE_TEXT,
        "ReadOnly" => $ReadOnly,
        "Value" => $Link,
        "MaxLength" => 2083,
        "ValidateFunction" => function ($FieldName, $Link) {
            return SecondaryNavigation::urlLooksValid($Link) ? null :
            "Link must be valid relative or absolute URL.";
        }
    ]
];

# create FormUI and add AddLink
$H_FormUI = new FormUI($Fields);
if ($H_EditPage) {
    $H_FormUI->addHiddenField("NI", $NavItemId);
} else {
    $H_FormUI->addHiddenField("AL", $CleanUrl);
}

# handle form submission
$ButtonPushed = StdLib::getFormValue("Submit", false);
if ($ButtonPushed) {
    switch ($ButtonPushed) {
        case "Add Link":
            # validate form input
            if ($H_FormUI->validateFieldInput()) {
                return;
            }

            # get owner ID
            $OwnerId = $User->id();

            # get label
            $Label = ($H_FormUI->getNewValuesFromForm())["Label"];

            # add link without prefix (HTTP_REFERER)
            $Link = $CleanUrl;

            # create NavItem and add to NavMenu order
            $NavItem = NavItem::create($OwnerId, $Label, $Link, false);
            $NavMenu = new NavMenu($OwnerId);
            $NavMenu->append($NavItem);

            # return to added page
            $GLOBALS["AF"]->setJumpToPage($Link);
            return;
        case "Save Changes":
            # validate form input
            if ($H_FormUI->validateFieldInput()) {
                return;
            }

            # get user input
            $FormValues = $H_FormUI->getNewValuesFromForm();

            # use previously generated NavItem and save label
            $NavItem->label($FormValues["Label"]);
            $NavItem->link($FormValues["Link"]);

            # return to EditNav
            $GLOBALS["AF"]->setJumpToPage("P_SecondaryNavigation_EditNav");
            return;
        case "Cancel":
        default:
            $GLOBALS["AF"]->setJumpToPage(($H_EditPage) ? "P_SecondaryNavigation_EditNav" :
             $CleanUrl);
            return;
    }
}
