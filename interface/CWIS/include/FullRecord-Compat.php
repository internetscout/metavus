<?PHP
#
#   FILE:  FullRecord-Compat.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2022 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#

# In some older CWIS versions, files in pages/ defined a number of UI helper
# functions for code in the .html files to use. These are no longer present in
# Metavus. This file contains implementations of those functions that can be
# used by custom .html files that were written based on these old versions to
# allow them to function under Metavus.
#
# To do this, add following at the top of the custom .html files:
#
#   use ScoutLib\ApplicationFramework;
#   $AF = ApplicationFramework::getInstance();
#   require_once($AF->gUIFile("Home-Compat.php"));

namespace Metavus;

/**
 * Determine if a provided variable represents a valid metadata field.
 * @param mixed $Field Variable to check
 * @return bool TRUE for valid fields, FALSE otherwise.
 */
function IsValidMetadataField($Field)
{
    return $Field instanceof MetadataField;
}

/**
 * Get the value of a metadata field for a resource. The value might be modified
 * by one or more plugins.
 * @param $Resource Resource object
 * @param $Field MetadataField object
 * @return the value of a metadata field for a resource
 */
function GetResourceFieldValue(Record $Resource, MetadataField $Field = null)
{
    global $AF;

    # invalid field
    if (is_null($Field) || $Field->Status() !== MetadataSchema::MDFSTAT_OK) {
        return null;
    }

    $Value = $Resource->Get($Field, true);

    # allow plugins to modify the value
    $SignalResult = $AF->SignalEvent(
        "EVENT_FIELD_DISPLAY_FILTER",
        array(
            "Field" => $Field,
            "Resource" => $Resource,
            "Value" => $Value
        )
    );
    $Value = $SignalResult["Value"];

    return $Value;
}

/**
* Determine if ratings are enabled.
* @return bool TRUE when ratings are enabled
*/
function CumulativeRatingEnabled()
{
    $Schema = new MetadataSchema();
    return $Schema->GetField("Cumulative Rating")->Enabled();
}

/**
 * Display the metadata field values of a resource.
 * @param Resource $Resource Resource object
 * @param callback $Filter Optional filter callback that returns TRUE if a
 *                resource/field pair should be filtered out
 */
function DisplayResourceFields(Record $Resource, $Filter = null)
{
    $Schema = new MetadataSchema();
    $Fields = $Schema->GetFields(null, MetadataSchema::MDFORDER_DISPLAY);

    $HasFilter = is_callable($Filter);

    foreach ($Fields as $Field) {
        # filter out fields if requested
        if ($HasFilter && call_user_func($Filter, $Resource, $Field)) {
            continue;
        }

        $Type = MetadataField::$FieldTypeDBEnums[$Field->Type()];

        $DisplayFunction = __NAMESPACE__."\\Display" . str_replace(" ", "", $Type) . "Field";
        $DisplayFunction($Resource, $Field);
    }
}

/**
 * Get the qualifier of a metadata field for a resource.
 * @param Resource $Resource Resource object
 * @param MetadataField $Field MetadataField object
 * @param int $Id ID used for a specific value if the field value has multiple
 * @return Qualifier|null a Qualifier object or NULL if a qualifier is not set
 */
function GetFieldQualifier(Record $Resource, MetadataField $Field, $Id = null)
{
    if (!$Field->UsesQualifiers()) {
        return null;
    }

    $Qualifier = $Resource->GetQualifierByField($Field, true);

    # if the field allows multiple values, get the one for a specific value of
    # the group if it's set and not null
    if (!is_null($Id) && is_array($Qualifier) && isset($Id, $Qualifier)) {
        $Qualifier = $Qualifier[$Id];
    }

    return ($Qualifier instanceof Qualifier) ? $Qualifier : null;
}


/**
 * Print graphic for the cumulative rating.
 */
function PrintCumulativeRatingGraphic()
{
    global $Resource;
    PrintRatingGraphic($Resource->CumulativeRating());
}


/**
 * Print Rating Graphic.
 * @param int $Rating Rating for this resource.
 */
function PrintRatingGraphic($Rating)
{
    global $Resource;

    if (is_null($Rating) || $Resource->NumberOfRatings() < 1) {
        PrintRatingGraphicNoRating();
    } else {
        $Function = __NAMESPACE__."\\PrintRatingGraphic" . intval(($Rating + 5) / 10);

        if (function_exists($Function)) {
            $Function();
        }
    }
}

/**
* Determine if a resource has been rated.
* @return bool TRUE for resources with ratings
*/
function ResourceHasBeenRated()
{
    global $Resource;
    return ($Resource->NumberOfRatings() > 0) ? true : false;
}

/**
* Print number of reosurce ratings.
*/
function PrintNumberOfRatings()
{
    global $Resource;
    print($Resource->NumberOfRatings());
}

/**
* Emit an 's' when we have more than one resource rating.
*/
function PrintNumberOfRatingsPlural()
{
    global $Resource;
    if ($Resource->NumberOfRatings() > 1) {
        print("s");
    }
}

/**
* Print link to resource rating page.
*/
function PrintRateResourceLink()
{
    global $Resource;
    print("index.php?P=RateResource&amp;F_ResourceId=".$Resource->id());
}



/**
* Determine if the user has rated this resource.
* @return bool TRUE if the user has
*/
function UserAlreadyRatedResource()
{
    global $Resource;
    return ($Resource->Rating() == null) ? false : true;
}

/**
* Print graphic for this user's rating.
*/
function PrintUserRatingGraphic()
{
    global $Resource;
    PrintRatingGraphic($Resource->Rating());
}

/**
* Print resource comments.
*/
function PrintResourceComments()
{
    global $Resource;

    $User = User::getCurrentUser();

    # retrieve comments
    $Comments = $Resource->Comments();

    # for each comment
    foreach ($Comments as $Comment) {
        $EditOkay = CheckForEdit($Comment->PosterId());
        $MessageId = $Comment->MessageId();
        $EditLink = "index.php?P=AddResourceComment"
            ."&amp;RI=".$Resource->id()."&amp;MI=".$MessageId;
        $DeleteLink = "index.php?P=AddResourceComment"
            ."&amp;RI=".$Resource->id()."&amp;MI=".$MessageId;
        $SpamLink = $User->HasPriv(PRIV_FORUMADMIN, PRIV_USERADMIN) &&
            $Comment->PosterId() != $User->id() ?
            "index.php?P=CleanSpam"
            ."&amp;PI=".$Comment->PosterId()."&amp;RI=".$Resource->id() :
            "";

        # print comment
        PrintForumMessage(
            $Comment,
            $EditOkay,
            $EditLink,
            $DeleteLink,
            null,
            true,
            $SpamLink
        );
    }
}

/**
* Determine if a specified User can edit comments.
* @param int $PosterId User to check
* @return bool TRUE for users who can edit, FALSE otherwise
*/
function CheckForEdit($PosterId)
{
    # users cannot edit if not logged in
    if (!$GLOBALS["G_User"]->IsLoggedIn()) {
        return false;
    }

    if (($GLOBALS["G_User"]->Get("UserId") == $PosterId &&
        $GLOBALS["G_User"]->HasPriv(PRIV_POSTCOMMENTS)) ||
        $GLOBALS["G_User"]->HasPriv(PRIV_FORUMADMIN)) {
        return true;
    } else {
        return false;
    }
}

/**
 * Print a forum message. If a Message object is given, the Subject, Body,
 * DatePosted, PosterName, and PosterEmail parameters should not be given.
 * @param $Subject message subject or Message object
 * @param $Body message body
 * @param $DatePosted date the message was posted
 * @param $PosterName the user name of the user that posted the message
 * @param $PosterEmail the e-mail of the user that posted the message
 * @param $EditOkay TRUE if the message can be edited by the current user
 * @param $EditLink where the user should go to edit the message
 * @param $DeleteLink where the user should go to delete the message
 * @param $DeleteLink where the user should go to remove the poster's privilege
 * @param $MessageIsComment TRUE if the message is a resource comment
 * @param $SpammerLink where the user should go to mark the message as spam
 */
function PrintForumMessage(
    $Subject,
    $Body,
    $DatePosted,
    $PosterName,
    $PosterEmail,
    $EditOkay,
    $EditLink = null,
    $DeleteLink = null,
    $RemovePostPrivLink = null,
    $MessageIsComment = false,
    $SpammerLink = null
) {
    $G_User = User::getCurrentUser();

    # if handed a message instead of its values, use the auxiliary function
    if ($Subject instanceof Message) {
        PrintForumMessageWithMessage(
            $Subject,
            $Body,
            $DatePosted,
            $PosterName,
            $PosterEmail,
            $EditOkay,
            $EditLink
        );
        return;
    }

    if ( $G_User->IsLoggedIn() &&
         (!$GLOBALS["G_PluginManager"]->PluginEnabled("BotDetector") ||
          !$GLOBALS["G_PluginManager"]->GetPlugin("BotDetector")->CheckForSpamBot()) ) {
        $PEmail = strlen($PosterEmail) > 0 ? "(".MungeEmailAddress($PosterEmail).")" : "";
    } else {
        $PEmail = "";
    }

    // phpcs:disable Generic.Files.LineLength.MaxExceeded
    ?>
<div class="cw-section cw-section-elegant cw-content-forummessage">
    <div class="cw-section-header">
        <div class="cw-table cw-table-fullsize cw-table-fauxtable">
        <div class="cw-table-fauxrow">
            <div class="cw-table-fauxcell">
                <b>Subject:</b> <?PHP print defaulthtmlentities($Subject); ?>
            </div>
            <div class="cw-table-fauxcell">
                <b>Posted By:</b> <?PHP print $PosterName; ?> <?PHP print $PEmail; ?>
            </div>
            <div class="cw-table-fauxcell">
                <b>Date Posted:</b> <?PHP print($DatePosted); ?>
            </div>
        </div>
        </div>
    </div>
    <div class="cw-section-body">
        <?PHP print nl2br(defaulthtmlentities($Body)); ?>
    </div>
    <?PHP if ($EditOkay) { ?>
    <div class="cw-section-footer">
        <?PHP if ($G_User->HasPriv(PRIV_SYSADMIN, PRIV_POSTCOMMENTS)) { ?>
        <a class="cw-button cw-button-elegant" href="<?PHP print($EditLink); ?>">Edit Message</a>
        <a class="cw-button cw-button-elegant" href="<?PHP print($DeleteLink); ?>">Delete Message</a>
        <?PHP } ?>
        <?PHP if (strlen($RemovePostPrivLink)) {  ?>
        <a class="cw-button cw-button-elegant" href="<?PHP print($RemovePostPrivLink); ?>">Remove Post Privilege</a>
        <?PHP } ?>
        <?PHP if (strlen($SpammerLink) && $G_User->HasPriv(PRIV_SYSADMIN, PRIV_USERADMIN)) { ?>
        <a class="cw-button cw-button-elegant" href="<?PHP print($SpammerLink);?>">Spammer</a>
        <?PHP } ?>
    </div>
    <?PHP } ?>
</div>
    <?PHP
      // phpcs:enable
}

/**
* Print a forum message.
* @param Message $Message Message object.
* @param bool $EditOkay TRUE if the message can be edited by the current user.
* @param string $EditLink where the user should go to edit the message.
* @param string $DeleteLink where the user should go to delete the message.
* @param string $DeleteLink where the user should go to remove the poster's
*       privilege.
* @param bool $MessageIsComment TRUE if the message is a resource comment.
* @param string $SpammerLink where the user should go to mark the message as
*       spam.
* @param bool $IncludeReplyButton Whether or not to include a reply button.
*/
function PrintForumMessageWithMessage(
    $Message,
    $EditOkay,
    $EditLink,
    $DeleteLink,
    $RemovePostPrivLink,
    $MessageIsComment = false,
    $SpammerLink = null,
    $IncludeReplyButton = false
) {
    $G_User = User::getCurrentUser();

    $DatePosted = $Message->DatePosted();
    $DateEdited = $Message->DateEdited();
    $PosterEmail = $Message->PosterEmail();
    $Edited = strtotime($DateEdited) >= strtotime($DatePosted);

    if ( $G_User->IsLoggedIn() &&
         (!$GLOBALS["G_PluginManager"]->PluginEnabled("BotDetector") ||
          !$GLOBALS["G_PluginManager"]->GetPlugin("BotDetector")->CheckForSpamBot()) ) {
        $PEmail = strlen($PosterEmail) > 0 ? "(".MungeEmailAddress($PosterEmail).")" : "";
    } else {
        $PEmail = "";
    }

    if ($Edited) {
        $Editor = new User(intval($Message->EditorId()));
        $SafeEditorName = defaulthtmlentities($Editor->Name());
        $SafeDateEdited = defaulthtmlentities(date(
            "F j, Y \a\\t g:i a",
            strtotime($DateEdited)
        ));
    }

    $CanPostComments = $G_User->HasPriv(PRIV_POSTCOMMENTS);

    # escape variables for HTML
    $SafeTopicId = defaulthtmlentities($Message->ParentId());
    $SafeMessageId = defaulthtmlentities($Message->MessageId());
    $SafeSubject = defaulthtmlentities($Message->Subject());


    $SafeBody =
        StripXSSThreats(
            $Message->Body(),
            array("Tags" => "p b i a u h1 h2 h3 h4 h5 h6 pre strike "
            ."sup sub ol ul blockquote hr"
            )
        );

    $SafePosterName = defaulthtmlentities($Message->PosterName());
    if (strlen($SafePosterName) == 0) {
        $SafePosterName = "[deleted&nbsp;account]";
    }
    $SafeDatePosted = defaulthtmlentities(date("F j, Y \a\\t g:i a", strtotime($DatePosted)));

    // phpcs:disable Generic.Files.LineLength.MaxExceeded
    ?>
<div class="cw-section cw-section-elegant cw-content-forummessage">
    <div class="cw-section-header">
        <div class="cw-table cw-table-fullsize cw-table-fauxtable">
        <div class="cw-table-fauxrow">
            <div class="cw-table-fauxcell">
                <b>Subject:</b> <?PHP print $SafeSubject; ?>
            </div>
            <div class="cw-table-fauxcell">
                <b>Posted By:</b> <?PHP print $SafePosterName; ?> <?PHP print $PEmail; ?>
            </div>
            <div class="cw-table-fauxcell">
                <b>Date Posted:</b> <?PHP print($DatePosted); ?>
            </div>
        </div>
        </div>
    </div>
    <div class="cw-section-body">
        <?PHP print $SafeBody; ?>
        <?PHP if ($Edited) { ?>
          <p class="cw-content-editedby">
            This message was edited by <?PHP print $SafeEditorName; ?> on
            <?PHP print $SafeDateEdited; ?>.
          </p>
        <?PHP } ?>
    </div>
    <?PHP if ($EditOkay || ($IncludeReplyButton && $CanPostComments)) { ?>
      <div class="cw-section-footer">
        <?PHP if ($IncludeReplyButton && $CanPostComments) { ?>
          <a class="cw-button cw-button-elegant"
             title="Reply directly to this message"
             href="index.php?P=PostMessage&amp;TI=<?PHP print $SafeTopicId; ?>&amp;ReplyTo=<?PHP print $SafeMessageId; ?>"
             >Reply</a>
        <?PHP } ?>
        <?PHP if ($EditOkay) { ?>
            <?PHP if ($G_User->HasPriv(PRIV_SYSADMIN, PRIV_POSTCOMMENTS)) { ?>
            <a class="cw-button cw-button-elegant" href="<?PHP print($EditLink); ?>">Edit Message</a>
            <a class="cw-button cw-button-elegant" href="<?PHP print($DeleteLink); ?>">Delete Message</a>
            <?PHP } ?>
            <?PHP if (strlen((string)$RemovePostPrivLink)) {  ?>
            <a class="cw-button cw-button-elegant" href="<?PHP print($RemovePostPrivLink); ?>">Remove Post Privilege</a>
            <?PHP } ?>
            <?PHP if (strlen((string)$SpammerLink) && $G_User->HasPriv(PRIV_SYSADMIN, PRIV_USERADMIN)) { ?>
            <a class="cw-button cw-button-elegant" href="<?PHP print($SpammerLink);?>">Spammer</a>
            <?PHP } ?>
        <?PHP } ?>
      </div>
    <?PHP } ?>
</div>
    <?PHP
      //phpcs:enable
}

/**
 * Munges an email address to try to fool web-scraping robots. Inserts two
 * strings of random characters into the email address, wrapped in spans which
 * will hide them from users whose browsers properly implement CSS.  Also wraps
 * the whole thing in a span.EMungeAddr so that some javascript in
 * SPT--EmailMunge.js can convert these fuzzed addresses back into clickable
 * mailtos.
 * @param string $String An email address to obfuscate
 * @return string the obfuscated email address
 */
function MungeEmailAddress($String)
{
    $FuzzOne = substr(md5(mt_rand()), 0, rand(8, 32));
    $FuzzTwo = substr(md5(mt_rand()), 0, rand(8, 32));
    return '<span class="EMungeAddr">'.preg_replace(
        '/@/',
        '<span style="display:none;"> '.htmlentities($FuzzOne).' </span>'
        .'&#64;'
        .'<span style="display:none;"> '.htmlentities($FuzzTwo).' </span>',
        $String
    ).'</span>';
}

/**
 * Determine if a user is logged in.
 * @return bool TRUE when user is logged in, FALSE otherwise.
 */
function UserIsLoggedIn()
{
    return User::getCurrentUser()->isLoggedIn();
}

global $Resource;
$Resource = $H_Record;

define("PRIV_FORUMADMIN", 4);
