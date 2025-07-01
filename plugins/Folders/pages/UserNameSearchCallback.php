<?PHP
#
#   FILE:  UserNameSearchCallback.php (Folders plugin)
#
#   Part of the Metavus digital collections platform
#   Copyright 2015-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

use Metavus\User;
use ScoutLib\ApplicationFramework;
use ScoutLib\UserFactory;

# ----- LOCAL FUNCTIONS ------------------------------------------------------

/**
 * Highlight (insert <b></b> tags) the search string in a term.
 * @param string $SS The search string to highlight
 * @param string $Term The term which to insert <b> tags to
 * @return string The term with the highlighted search string
 */
function HighlightSearchString($SS, $Term)
{
    $Result = "";
    # used to track which letter in the $SS are we currently matching
    $CurrentMatchingIndex = 0;
    # used to track whether current matching letter is the first in a chunk
    $ContinueMatching = false;
    $TermLen = strlen($Term);
    for ($i = 0; $i < $TermLen; $i++) {
        # if the entire $SS has been matched, finish highlight with </b>
        # and append the rest of the $Term
        if ($CurrentMatchingIndex >= strlen($SS)) {
            $Result .= "</b>".substr($Term, $i);
            break;
        }


        $CurrentMatching = substr($SS, $CurrentMatchingIndex, 1);
        $CurrentTermLetter = substr($Term, $i, 1);

        # compare current matching letter with the current term letter
        if (strcasecmp($CurrentMatching, $CurrentTermLetter) == 0) {
            # if we are not continuing the match, this current matching letter
            # must be the first in the chunk, therefore insert the opening
            # <b> in front of the term
            if (!$ContinueMatching) {
                // insert <b> in front
                $Result .= "<b>";
                $ContinueMatching = true;
            }

            $Result .= $CurrentTermLetter;
            $CurrentMatchingIndex++;
        } else {
            # if the current matching letter and the current term letter are not
            # the same, and we are still matching, the last matched letter must
            # be the end of the chunk.Therefore insert the closing </b> before
            # we append the current term letter
            if ($ContinueMatching) {
                $Result .= "</b>";
                $ContinueMatching = false;
            }

            $Result .= $CurrentTermLetter;
        }
    }

    return $Result;
}


# ----- MAIN -----------------------------------------------------------------
ApplicationFramework::getInstance()->beginAjaxResponse();

# retrieve user currently logged in
$LoggedInUser = User::getCurrentUser();

$SearchString = $_GET["SS"] ?? null;
$UserFactory = new UserFactory();
$RawResult = $UserFactory->getMatchingUsers($SearchString, "UserName", "UserName", 0, 10);
$UserNames = [];

foreach ($RawResult as $Id => $User) {
    # filter out current user name
    if ($User["UserName"] == $LoggedInUser->name()) {
        continue;
    }

    $UserNames[$Id] = [
        "Label" => HighlightSearchString($SearchString, $User["UserName"]),
        "Value" => $User["UserName"]
    ];
}

print json_encode($UserNames);
