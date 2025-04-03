<?PHP
#
#   FILE:  BrowseResources.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2011-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus;

use ScoutLib\ApplicationFramework;
use ScoutLib\Database;

# ----- EXPORTED FUNCTIONS ---------------------------------------------------

/**
* Retrieve a sorted list of links for browsing resources
* @return array An alphabetical array of links indexed by field name
*/
function GetBrowseLinks()
{
    $Schema = new MetadataSchema();
    $BrowsingFieldId = GetBrowsingFieldId();

    $Links = array();

    foreach ($Schema->getFields(MetadataSchema::MDFTYPE_TREE) as $Field) {
        # skip the fields that shouldn't be displayed
        if (!CanDisplayField($Field)) {
            continue;
        }

        # skip displaying the current field
        if ($Field->Id() == $BrowsingFieldId) {
            continue;
        }

        $Links[$Field->Name()] =
            "index.php?P=BrowseResources&amp;FieldId=".$Field->Id();
    }

    # sort the links in alphabetical order
    ksort($Links);

    return $Links;
}

/**
 * Retrieve string link to view classifications in alphabetical order,
 * based on $ParentId if isset
 * @return string Link string.
 * @deprecated Write function in HTML instead
 */
function PrintAlphabeticClassificationLinks(): string
{
    global $ParentId;

    # if classification ID passed in
    if ($ParentId > 0) {
        # retrieve link string for classification
        $Class = new Classification($ParentId);
        $LinkString = $Class->linkString();

        # if link string has not yet been set
        if ($LinkString == "") {
            # create and save new link string
            $LinkString = BuildClassificationLinkString(
                $ParentId
            );
            $Class->linkString($LinkString);
        }
    } else {
        $LinkString = BuildClassificationLinkString(0);

        global $StartingLetter;
        global $EndingLetter;

        if (preg_match(
            "%StartingLetter=([0-9A-Za-z\"]+)\&amp;EndingLetter=([0-9A-Za-z\"]+)%",
            $LinkString,
            $Matches
        )
            && !strlen($StartingLetter ?? "")
            && !strlen($EndingLetter ?? "")) {
            # extract and save new default to ?? ""p-level begin and end letters
            $StartingLetter = $Matches[1];
            $EndingLetter = $Matches[2];
        }
    }

    # if link string is placeholder
    if ($LinkString == "X") {
        # clear link string
        $LinkString = "";
    } else {
        # if link string is not empty
        if ($LinkString != "") {
            # insert target browse page name into link string
            $LinkString = preg_replace(
                "/BROWSEPAGE/",
                "index.php?P=BrowseResources",
                $LinkString
            );

            # insert editing flag value into link string
            $LinkString = preg_replace(
                "/EDITFLAG/",
                (EditingEnabled() ? "1" : "0"),
                $LinkString
            );
        }
    }

    # return link string to caller
    return $LinkString;
}

/**
* Retrieve the name of the field the user is currently browsing
* @return string FieldName The name of the field the user is browsing.
*/
function GetTreeName()
{
    $Field = MetadataField::getField(GetBrowsingFieldId());
    return $Field->getDisplayName();
}

/**
* Print the tree name of the field the user is browsing.
*/
function PrintTreeName(): void
{
    print GetTreeName();
}

/**
* Retrieve the count of classifications to be displayed based on
* what the user is currently browsing
* @return int the number of entries to display
*/
function GetClassificationCount()
{
    global $ParentId;
    global $StartingLetter;
    global $EndingLetter;

    $ClassDB = new Database();

    $ClassDB->query(GetClassificationDBQuery(
        $ParentId,
        $StartingLetter,
        $EndingLetter
    ));

    # retrieve count of entries to be displayed
    return $ClassDB->numRowsSelected();
}

/**
* Display the list of classifications based on the
* global variables about what the user is currently viewing
* @deprecated
*/
function DisplayClassificationList(): void
{
    global $H_NumberOfColumns;
    global $MinEntriesPerColumn;
    global $ParentId;
    global $StartingLetter;
    global $EndingLetter;

    $ClassDB = new Database();

    # retrieve entries to be displayed
    $ClassDB->query(GetClassificationDBQuery(
        $ParentId,
        $StartingLetter,
        $EndingLetter
    ));

    # retrieve count of entries to be displayed
    $RecordCount = $ClassDB->numRowsSelected();

    # for each entry
    $ClassCount = 0;
    while ($Class = $ClassDB->fetchRow()) {
        $ClassId = $Class["ClassificationId"];

        # if filter function defined
        if (function_exists("FilterClassificationBrowseList")) {
            # call filter function to find out if okay to display entry
            $DoNotDisplay = FilterClassificationBrowseList($ClassId);
        # assume okay to display entry
        } else {
            $DoNotDisplay = false;
        }

        # if okay to display entry
        if ($DoNotDisplay == false) {
            # if entries per column limit reached
            $ClassCount++;
            if (($ClassCount > intval($RecordCount / intval($H_NumberOfColumns)))
                && ($ClassCount > $MinEntriesPerColumn)) {
                # move to next column
                MoveToNextClassificationColumn(); /* @phpstan-ignore function.notFound */
                $ClassCount = 0;
            }

            # construct link address
            $LinkUrl = sprintf(
                "index.php?P=BrowseResources&amp;ID=%d",
                $Class["ClassificationId"]
            );
            if (EditingEnabled()) {
                $LinkUrl .= "&amp;Editing=1";
            }

            # construct link address for editing
            $EditLinkUrl = sprintf(
                "index.php?P=EditClassification"
                                   ."&amp;ClassificationId=%d",
                $Class["ClassificationId"]
            );

            # get the correct count for the context
            $Count = EditingEnabled()
                ? $Class["FullResourceCount"] : $Class["ResourceCount"];

            # print entry
            PrintClassificationEntry(   /* @phpstan-ignore function.notFound */
                $Class["SegmentName"],
                $LinkUrl,
                $Count,
                $EditLinkUrl
            );
        }
    }
}

/**
 * Print the root classification
 * @param string $LinkStyle Optional paramter for adding a class
 * to the generated link
 * @deprecated
 */
function PrintRootClassification($LinkStyle = ""): void
{
    global $ParentId;

    # print root classification HTML string
    print(GetRootClassification($ParentId, $LinkStyle));
}

/**
* Discern if the global user has editing privileges
* @return bool EditingEnabled if the user has editing privileges
*/
function EditingEnabled(): bool
{
    global $Editing;

    return $Editing == 1 && User::getCurrentUser()->hasPriv(PRIV_CLASSADMIN);
}

/**
* Print the link to add a classification
*/
function PrintAddClassificationLink(): void
{
    global $ParentId;

    print("index.php?P=AddClassification&amp;ParentId=".$ParentId
          ."&amp;FieldId=".GetBrowsingFieldId());
}

/**
* Retrieve the count of resources available for browsing
* @return int $ResourceCount the count of resources
*/
function GetResourceCount()
{
    static $ResourceCount;

    # if we have not already calculated count of resources
    if (!isset($ResourceCount)) {
        # total up resources from each schema
        $Resources = GetVisibleResources();
        $ResourceCount = 0;
        foreach ($Resources as $SchemaId => $ResourceIds) {
            $ResourceCount += count($ResourceIds);
        }
    }

    # return count to caller
    return $ResourceCount;
}

/**
 * Discern if there are previous resources available
 * @return bool PreviousResourcesAvailable If there are
 * previous resources available
 * @deprecated
*/
function PreviousResourcesAvailable()
{
    global $H_StartingResourceIndex;
    return ($H_StartingResourceIndex > 0) ? true : false;
}

/**
* Discern if there are more resources available to display on an
* additional page
* @return bool NextResourcesAvailable whether there are more resources
* to display
* @deprecated
*/
function NextResourcesAvailable()
{
    /* @var int $H_StartingResourceIndex */
    global $H_StartingResourceIndex;
    /* @var int $H_MaxResourcesPerPage */
    global $H_MaxResourcesPerPage;

    if (($H_StartingResourceIndex + $H_MaxResourcesPerPage) /* @phpstan-ignore smaller.invalid */
            < GetResourceCount()) {
        return true;
    } else {
        return false;
    }
}

/**
* Print the link to the previous page of resources
* @deprecated
*/
function PrintPreviousResourcesLink(): void
{
    global $H_StartingResourceIndex;
    global $H_MaxResourcesPerPage;
    global $ParentId;

    $Url = "index.php?P=BrowseResources&amp;ID=".$ParentId
        ."&amp;StartingResourceIndex="
        .($H_StartingResourceIndex - $H_MaxResourcesPerPage);
    if (EditingEnabled()) {
        $Url .= "&amp;Editing=1";
    }
    print($Url);
}

/**
* Print the link to the next page of resources
* @deprecated
*/
function PrintNextResourcesLink(): void
{
    global $H_StartingResourceIndex;
    global $H_MaxResourcesPerPage;
    global $ParentId;

    $Url = "index.php?P=BrowseResources&amp;ID=".$ParentId
        ."&amp;StartingResourceIndex="
        .($H_StartingResourceIndex + $H_MaxResourcesPerPage);
    if (EditingEnabled()) {
        $Url .= "&amp;Editing=1";
    }
    print($Url);
}

/**
* Print the number of resources on the previous page
* @deprecated
*/
function PrintNumberOfPreviousResources(): void
{
    global $H_MaxResourcesPerPage;
    print($H_MaxResourcesPerPage);
}

/**
* Print the number of resources on the next page
* @deprecated
*/
function PrintNumberOfNextResources(): void
{
    global $H_MaxResourcesPerPage;
    global $H_StartingResourceIndex;
    print(min(
        $H_MaxResourcesPerPage,
        (GetResourceCount() - ($H_StartingResourceIndex +
        $H_MaxResourcesPerPage))
    ));
}

/**
* Print out the list of resources requested by other functions and
* set via global variables
*/
function DisplayResourceList(): void
{
    /** @var int $H_StartingResourceIndex */
    global $H_StartingResourceIndex;
    /** @var int $H_MaxResourcesPerPage */
    global $H_MaxResourcesPerPage;

    $Resources = GetVisibleResources();

    $AllResourceIds = [];
    foreach ($Resources as $SchemaId => $ResourceIds) {
        $AllResourceIds = array_merge($AllResourceIds, $ResourceIds);
    }

    $ShowScreenshots = RecordFactory::recordIdListHasAnyScreenshots(
        $AllResourceIds
    );

    # for each entry
    $ResourceIndex = 0;
    foreach ($AllResourceIds as $ResourceId) {
        # if within resource range for this page
        if (($ResourceIndex >= $H_StartingResourceIndex)
            && ($ResourceIndex < ($H_StartingResourceIndex + $H_MaxResourcesPerPage))) {
            # print entry
            $Resource = new Record($ResourceId);
            PrintResourceEntry( /* @phpstan-ignore function.notFound */
                $Resource,
                "index.php?P=FullRecord&amp;ID=".$ResourceId,
                $Resource->scaledCumulativeRating(),
                $ShowScreenshots
            );
        }

        # increment count of resources displayed
        $ResourceIndex++;
    }
}

/**
* THIS FUNCTION IS DEPRECATED
* @deprecated
*/
function PrintBrowsingLinks(): void
{
    $Links = GetBrowsingLinks();
    foreach ($Links as $Name => $Link) {
        print("<a href=\"".$Link."\">Browse by ".$Name."</a><br>");
    }
}

/**
* THIS FUNCTION IS DEPRECATED
* @deprecated
*/
function GetBrowsingLinks(): array
{
    $Schema = new MetadataSchema();
    $Fields = $Schema->getFields(MetadataSchema::MDFTYPE_TREE);
    $BrowsingFieldId = GetBrowsingFieldId();

    # retrieve user currently logged in
    $User = User::getCurrentUser();

    $Links = array();
    foreach ($Fields as $Field) {
        if ($Field->userCanView($User) && $Field->Id() != $BrowsingFieldId) {
            $Links[$Field->Name()] =
                "index.php?P=BrowseResources&amp;FieldId=".$Field->Id();
        }
    }
    return $Links;
}

/**
* Discern if a field can be displayed to User
* @param MetadataField $Field The Field to discern visibility
* @return bool CanDisplayField whether the field can
* be displayed for the logged in user (or anon user)
*/
function CanDisplayField(MetadataField $Field)
{
    # do not display fields with a bad status
    if ($Field->status() != MetadataSchema::MDFSTAT_OK) {
        return false;
    }

    # do not display disabled fields
    if (!$Field->enabled()) {
        return false;
    }

    # field that the user shouldn't view
    if (!(($Field->viewingPrivileges())->meetsRequirements(User::getCurrentUser()))) {
        return false;
    }

    return true;
}

/**
* Discern if the current field is the tree root
* @return bool AtTreeFieldRoot whether the currently viewed
* field is the tree's root
* @deprecated
*/
function AtTreeFieldRoot()
{
    global $ParentId;

    return $ParentId < 1;
}

# ----- LOCAL FUNCTIONS ------------------------------------------------------

/**
 * Return a list of resources visible to the current user.
 * @return array($SchemaId => array($VisibleResourceIds))
 */
function GetVisibleResources(): array
{
    global $ParentId, $Schema;
    $IntConfig = InterfaceConfiguration::getInstance();

    # retrieve user currently logged in
    $User = User::getCurrentUser();

    $DB = new Database();
    $SortFieldId = $IntConfig->getInt("BrowsingSortingFieldId");
    $SortFieldName = $Schema->getField($SortFieldId)->dBFieldName();
    $SortDirection = $IntConfig->getString("BrowsingSortingDirection");
    $Query = "SELECT Records.RecordId AS RecordId, SchemaId"
    ." FROM RecordClassInts, Records"
    ." WHERE ClassificationId = ".$ParentId
    ." AND RecordClassInts.RecordId = Records.RecordId"
    ." AND Records.RecordId > 0"
    ." ORDER BY ".$SortFieldName." ".$SortDirection;
    $DB->query($Query);

    # pull out resources and bin them by schema
    $Resources = [];
    while ($Row = $DB->fetchRow()) {
        $SchemaId = intval($Row["SchemaId"]);
        $Resources[$SchemaId][] = $Row["RecordId"];
    }

    # filter out non-viewable resources from each schema
    foreach ($Resources as $SchemaId => $ResourceIds) {
        $RFactory = new RecordFactory($SchemaId);

        $Resources[$SchemaId] = $RFactory->filterOutUnviewableRecords(
            $ResourceIds,
            $User
        );
    }

    return $Resources;
}

/**
 * Returns the ID of the field currently browsed
 * @return int $FieldId The ID of the field currently browsed
 */
function GetBrowsingFieldId(): int
{
    global $BrowsingFieldId;
    global $ParentId;

    $IntConfig = InterfaceConfiguration::getInstance();

    # retrieve user currently logged in
    $User = User::getCurrentUser();

    if (isset($ParentId) && ($ParentId >= 0)
            && Classification::itemExists($ParentId)) {
        $Parent = new Classification($ParentId);
        $FieldId = $Parent->fieldId();
    } elseif (isset($BrowsingFieldId)) {
        $FieldId = $BrowsingFieldId;
    } elseif ($User->isLoggedIn()) {
        $FieldId = $User->get("BrowsingFieldId");
        if (empty($FieldId)) {
            $FieldId = $IntConfig->getInt("BrowsingFieldId");
        }
    } else {
        $FieldId = $IntConfig->getInt("BrowsingFieldId");
    }

    return $FieldId;
}

/**
 * Retrieve a link to all of the children classifications based on a
 * parent ID
 * @param int $ParentId The ID of the parent classification where
 * we want to generate a link
 * @return string $LinkString The string link requested
 */
function BuildClassificationLinkString(int $ParentId): string
{
    $DB = new Database();

    # Disable caching, as we're just going to run one query that returns a
    # single large result.
    $DB->caching(false);

    $MaxClassesPerPage = InterfaceConfiguration::getInstance()->getInt("NumClassesPerBrowsePage");
    if ($MaxClassesPerPage < 1) {
        $MaxClassesPerPage = 1;
    }

    # load classification names from database
    $ClassNames = array();
    $DB->query("SELECT SegmentName FROM Classifications "
               ."WHERE FieldId="
               .GetBrowsingFieldId()." "
               ."AND ".(($ParentId > 0) ? "ParentId="
                        .intval($ParentId)." " : "Depth=0 "
              ."AND ResourceCount != 0 "));
    while ($Row = $DB->fetchRow()) {
        $ClassName = trim($Row["SegmentName"]);
        if ($ClassName != "") {
            # Normalize the names, so that they will sort in a sane ordering.
            $ClassName = preg_replace("/[^0-9A-Za-z\"]/", "", $ClassName);
            $ClassNames[] = ucfirst(strtolower($ClassName));
        }
    }

    # sort the normalized class names
    sort($ClassNames);

     # if all classes will fit on a single page
    if (count($ClassNames) <= $MaxClassesPerPage) {
        # set link string to null value
        $LinkString = "X";
    } else {
        # Otherwise, we have some work to do:

        # Divide the classes that we have into bins.
        #  We'll want to have approximately $MaxClassesPerPage in each bin.
        #  After each bin gets 80% full, start looking for a place to break the bin
        #  where the first 2 characters will be unique between bins.  This
        #  means that some bins might overflow a bit, but has the advantage of
        #  iterating through all the classifications only once.
        $Cur = 0;
        $BinnedClasses = array();
        $BinnedClasses[$Cur] = array();
        $Prefix = "   ";

        foreach ($ClassNames as $ClassName) {
            if (count($BinnedClasses[$Cur]) > 0.8 * $MaxClassesPerPage &&
                 substr($ClassName, 0, 2) != $Prefix) {
                $Cur++;
            }
            $Prefix = substr($ClassName, 0, 2);
            $BinnedClasses[$Cur][] = $ClassName;
        }

        # Now, based on the bins that we've constructed, determine the link strings.
        #  We'll be comparing the last word in the previous bin to the first word in ours,
        #  And then the last word in our bin to the first word in the next bin.
        $LinkString = "";
        $BinnedClassesCount = count($BinnedClasses);
        for ($i = 0; $i < $BinnedClassesCount; $i++) {
            $PreviousEnd = ($i == 0) ? "     " :
                $BinnedClasses[$i - 1][count($BinnedClasses[$i - 1]) - 1];

            $CurStart    = $BinnedClasses[$i][0];
            $CurEnd      = $BinnedClasses[$i][count($BinnedClasses[$i]) - 1] ;


            $NextStart = ($i == count($BinnedClasses) - 1 ) ? "ZZZZZ" :
                $BinnedClasses[$i + 1][0];

            # determine the shortest prefix that is unique between us
            # and the bin to our left
            $StartLen = 0;
            do {
                $StartLen++;
            } while (substr($PreviousEnd, 0, $StartLen) ==
                      substr($CurStart, 0, $StartLen));
            $Begin = substr($CurStart, 0, $StartLen);

            # determine the shortest prefix that is unique between us
            # and the bin to our right
            $EndLen = 0;
            do {
                $EndLen++;
            } while (substr($CurEnd, 0, $EndLen) == substr($NextStart, 0, $EndLen));
            $End   = substr($CurEnd, 0, $EndLen);

            # Then construct the link string :
            $LinkString .= " <a href='BROWSEPAGE"
                ."&amp;StartingLetter=".$Begin
                ."&amp;EndingLetter=".$End
                .(($ParentId > 0) ? "&amp;ID=".$ParentId : "")
                ."&amp;Editing=EDITFLAG'>".$Begin
                .(($Begin == $End) ? "" : "-".$End)
                ."</a>";
        }
    }
    return $LinkString;
}

/**
 * Return the query to retrieve the classifications with the passed in parent id
 * from the database
 * @param int $ParentId The ID of the parent classification
 * @param ?string $StartingLetter The beginning letter of the classifications to find
 * @param ?string $EndingLetter The ending letter of the classifications to find
 * @return string $QueryString The SQL query to obtain the classifications sought
 */
function GetClassificationDBQuery(
    int $ParentId,
    ?string $StartingLetter,
    ?string $EndingLetter
): string {
    if ($ParentId > 0) {
        $QueryString =
            "SELECT * FROM Classifications "
            ."WHERE ParentId=".intval($ParentId)." AND FieldId="
            .GetBrowsingFieldId()." "
            .(EditingEnabled() ? "" : "AND ResourceCount != 0 ")
            ."ORDER BY ClassificationName";
    } else {
        $QueryString = "
            SELECT * FROM Classifications
            WHERE Depth = 0
            AND FieldId = '".intval(GetBrowsingFieldId())."'
            ".($StartingLetter ? "AND UPPER(ClassificationName) >= '"
                   .addslashes($StartingLetter)."'" : "")."
            ".($EndingLetter ? "AND UPPER(ClassificationName) <= '"
                   .addslashes($EndingLetter)."ZZZZZZZZZZ'" : "")."
            ".(EditingEnabled() ? "" : "AND ResourceCount != 0 ")."
            ORDER BY ClassificationName";
    }

    return $QueryString;
}

/**
 * Starting with the parent id, step upwards through parents, generating a
 * string containing a series of tree (classification) nodes, each formatted
 * with a link.
 * @param int $ParentId The ID of the parent classification
 * @param string $LinkStyle Any classes to add to the returned link
 * @return string $RootClassString The formatted link to the root classification
 * @deprecated Write function in HTML instead
 */
function GetRootClassification(int $ParentId, string $LinkStyle = ""): string
{
    # start with empty string
    $RootClassString = "";

    # if top of classification tree specified
    if ($ParentId > 0) {
        # do while classification segments left to add
        do {
            # if not first segment in string
            if ($RootClassString != "") {
                # add segment separator to string
                $RootClassString = " -- ".$RootClassString;
            }

            # get current segment name
            $Class = new Classification($ParentId);

            # add current segment to string
            $RootClassString =
                "<a href='index.php?P=BrowseResources&amp;ID="
                .$ParentId
                .(EditingEnabled() ? "&amp;Editing=1" : "")
                ."' class='".$LinkStyle."'>"
                .$Class->segmentName()."</a>"
                .$RootClassString;

            # move to next segment
            $ParentId = $Class->parentId();
        } while ($ParentId > 0);
    }

    # return root classification HTML string to caller
    return $RootClassString;
}

/**
 * Get the maximum number of resources to display on the page. This is the user
 * preference or the system default if the user isn't logged in.
 * @return int Returns the maximum number of resources to display.
 */
function GetMaxResourcesPerPage(): int
{
    # retrieve user currently logged in
    $User = User::getCurrentUser();

    # use the user preference if logged in
    if ($User->isLoggedIn()) {
        $RP = $User->get("RecordsPerPage");

        if (!is_null($RP)) {
            return $RP;
        }
    }

    # otherwise use the system default
    return InterfaceConfiguration::getInstance()->getInt("DefaultRecordsPerPage");
}

# ----- MAIN -----------------------------------------------------------------

# non-standard global variables
global $BrowsingFieldId;
global $Editing;
global $EndingLetter;
global $H_MaxResourcesPerPage;
global $H_NumberOfColumns;
global $H_StartingResourceIndex;
global $MinEntriesPerColumn;
global $ParentId;
global $Schema;
global $StartingLetter;

$AF = ApplicationFramework::getInstance();

# set up default display parameters
$H_NumberOfColumns = InterfaceConfiguration::getInstance()->getInt("NumColumnsPerBrowsePage");
$MinEntriesPerColumn = 3;
$H_MaxResourcesPerPage = GetMaxResourcesPerPage();

if (isset($_GET["Editing"])) {
    $Editing = intval($_GET["Editing"]);
} else {
    $Editing = 0;
}

$H_EditingEnabled = EditingEnabled();

PageTitle($H_EditingEnabled ? "Add/Edit Classifications" : "Browse Resources");

$Schema = new MetadataSchema();
$User = User::getCurrentUser();

if (isset($_POST["F_BrowsingFieldId"])) {
    $BrowsingFieldId = intval($_POST["F_BrowsingFieldId"]);
    $User->set("BrowsingFieldId", $BrowsingFieldId);
} elseif (isset($_GET["FieldId"])) {
    $BrowsingFieldId = intval($_GET["FieldId"]);
    if ($User->isLoggedIn()) {
        $User->set("BrowsingFieldId", $BrowsingFieldId);
    }
}

$Field = $Schema->fieldExists(GetBrowsingFieldId())
    ? $Schema->getField(GetBrowsingFieldId()) : null;

# if the requested field shouldn't be displayed to the user, try to get one
# that can be
if (!$Editing && ($Field === null || !CanDisplayField($Field))) {
    $DisplayableField = null;

    # try to get a field that can be displayed for the user
    foreach ($Schema->getFields(MetadataSchema::MDFTYPE_TREE) as $Field) {
        if (CanDisplayField($Field)) {
            $DisplayableField = $Field;
            break;
        }
    }

    # change to the displayable field
    if (!is_null($DisplayableField)) {
        $AF->setJumpToPage(
            "index.php?P=BrowseResources&FieldId=".$DisplayableField->Id()
        );
    # go to the home page instead
    } else {
        $AF->setJumpToPage("index.php?P=Home");
    }
}

if (isset($_GET["StartingResourceIndex"])) {
    $H_StartingResourceIndex = intval($_GET["StartingResourceIndex"]);
} elseif (isset($_GET["SI"])) {
    $H_StartingResourceIndex = intval($_GET["SI"]);
} else {
    $H_StartingResourceIndex = 0;
}

$ParentId = isset($_GET["ID"]) ? intval($_GET["ID"])
        : (isset($_GET["ParentId"]) ? intval($_GET["ParentId"]) : -1);

# make sure specified ID is valid if supplied
if (($ParentId != -1) && !Classification::itemExists($ParentId)) {
    $AF->setJumpToPage("Home");
}

# set to stored system default browse range
if (isset($_GET["StartingLetter"])) {
    $StartingLetter = substr($_GET["StartingLetter"], 0, 2);
} else {
    $StartingLetter = null;
}

if (isset($_GET["EndingLetter"])) {
    $EndingLetter = substr($_GET["EndingLetter"], 0, 2);
} else {
    $EndingLetter = null;
}

if (is_null($StartingLetter) || !strlen($StartingLetter)) {
    $StartingLetter = null;
}
if (is_null($EndingLetter) || !strlen($EndingLetter)) {
    $EndingLetter = null;
}

$H_ResourceCount = GetResourceCount();
$H_ClassificationCount = GetClassificationCount();
$H_BrowseLinks = GetBrowseLinks();
