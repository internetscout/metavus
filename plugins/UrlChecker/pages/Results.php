<?PHP
#
#   FILE:  Results.php (UrlChecker plugin)
#
#   Part of the Metavus digital collections platform
#   Copyright 2011-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus;
use Metavus\Plugins\UrlChecker;
use Metavus\Plugins\UrlChecker\Constraint;
use Metavus\Plugins\UrlChecker\ConstraintList;
use ScoutLib\ApplicationFramework;

# ----- MAIN -----------------------------------------------------------------

# setup
PageTitle("URL Checker Results");
CheckAuthorization(PRIV_SYSADMIN, PRIV_COLLECTIONADMIN);

$AF = ApplicationFramework::getInstance();
$MyPlugin = UrlChecker::getInstance();
$Schema = new MetadataSchema(MetadataSchema::SCHEMAID_DEFAULT);

$H_TitleField = $Schema->getFieldByMappedName("Title");
$ReleaseDateField = $Schema->getField("Date Of Record Release");

# values that don't depend on URL checker info
$H_UrlFields = $Schema->getFields(MetadataSchema::MDFTYPE_URL);
$H_NumUrlFields = count($H_UrlFields);

# limits
$Constraints = [];

# get parameters
$H_OrderBy = $_GET["SF"] ?? "StatusCode";
$H_OrderDirection = $_GET["SD"] ?? "ASC";
$H_Limit = $_GET["N"] ?? 50;
$H_StatusCode = $_GET["S"] ?? "All";
$H_Hidden = $_GET["H"] ?? 0;
$H_SchemaId = $_GET["SC"] ?? "All";

$DefaultConstraintList = new ConstraintList([
    new Constraint("Hidden", $H_Hidden, "=")
]);

if ($H_SchemaId != "All") {
    $DefaultConstraintList->addConstraint(
        new Constraint("SchemaId", $H_SchemaId, "=")
    );
}

# reset the ordering if only within one type of invalid URLs and if ordering
# by status, since it would be useless
if ($H_StatusCode != "All" && $H_OrderBy == "StatusCode") {
    $H_OrderBy = "CheckDate";
}

$PrimaryConstraintList = clone $DefaultConstraintList;
$Constraints[] = $PrimaryConstraintList;

switch ($H_StatusCode) {
    case "Could Not Connect":
        $PrimaryConstraintList->addConstraint(
            new Constraint("StatusCode", 0, "=")
        );
        break;
    case "Information":
        $PrimaryConstraintList->addConstraints([
            new Constraint("StatusCode", 99, ">"),
            new Constraint("StatusCode", 200, "<")
        ]);
        break;
    case "Redirection":
        $PrimaryConstraintList->addConstraints([
            new Constraint("StatusCode", 299, ">"),
            new Constraint("StatusCode", 400, "<")
        ]);
        break;
    case "Client Error":
        $PrimaryConstraintList->addConstraints([
            new Constraint("StatusCode", 399, ">"),
            new Constraint("StatusCode", 500, "<"),
            new Constraint("StatusCode", 401, "!="),
            new Constraint("StatusCode", 403, "!="),
            new Constraint("StatusCode", 404, "!="),
        ]);
        break;
    case "Server Error":
        $PrimaryConstraintList->addConstraints([
            new Constraint("StatusCode", 499, ">"),
            new Constraint("StatusCode", 600, "<")
        ]);
        break;
    case "Permission Denied":
        $PrimaryConstraintList->addConstraint(
            new Constraint("StatusCode", 401, "=")
        );

        # also need to lump in 403 with permission denied
        $SecondaryConstraintList = clone $DefaultConstraintList;
        $Constraints[] = $SecondaryConstraintList;

        $SecondaryConstraintList->addConstraint(
            new Constraint("StatusCode", 403, "=")
        );
        break;
    case "Page Not Found":
        $PrimaryConstraintList->addConstraint(
            new Constraint("StatusCode", 404, "=")
        );

        # also need to lump in 200s with permission denied
        $SecondaryConstraintList = clone $DefaultConstraintList;
        $Constraints[] = $SecondaryConstraintList;

        $SecondaryConstraintList->addConstraints([
            new Constraint("StatusCode", 199, ">"),
            new Constraint("StatusCode", 300, "<"),
        ]);
        break;
}

$H_InvalidCount = $MyPlugin->getInvalidCount($Constraints);

# if there weren't any results and we were filtering by schema and status,
# redirect to remove the status filter
if ($H_InvalidCount == 0 && isset($_GET["SC"]) && isset($_GET["S"])) {
    $AF->setJumpToPage(
        "index.php?".http_build_query([
            "P" => "P_UrlChecker_Results",
            "H" => $H_Hidden,
            "N" => $H_Limit,
            "SC" => $H_SchemaId,
            "SF" => $H_OrderBy,
            "SD" => $H_OrderDirection,
        ])
    );
    return;
}

$BaseLink = "index.php?".http_build_query([
    "P" => "P_UrlChecker_Results",
    "H" => $H_Hidden,
    "N" => $H_Limit,
    "S" => $H_StatusCode,
    "SC" => $H_SchemaId,
    "SF" => $H_OrderBy,
    "SD" => $H_OrderDirection,
]);

$H_TransportUI = new TransportControlsUI();
$H_TransportUI->itemsPerPage($H_Limit);
$H_TransportUI->baseLink($BaseLink);

$H_InvalidUrls = $MyPlugin->getInvalidUrls(
    $Constraints,
    $H_OrderBy,
    $H_OrderDirection,
    $H_Limit,
    $H_TransportUI->startingIndex()
);

$H_TransportUI->itemCount($H_InvalidCount);

$H_Info = $MyPlugin->getInformation();
