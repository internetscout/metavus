<?PHP
#
#   FILE:  Comment.php (Blog plugin)
#
#   Part of the Metavus digital collections platform
#   Copyright 2013-2022 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#

use Metavus\Message;
use Metavus\Plugins\Blog;
use Metavus\Plugins\Blog\Entry;
use Metavus\Record;
use Metavus\User;
use ScoutLib\ApplicationFramework;
use ScoutLib\PluginManager;
use ScoutLib\StdLib;

# ----- LOCAL FUNCTIONS ------------------------------------------------------

/**
* Post a comment to a blog entry.Both the blog entry and comment body should be
* validated prior to calling this function.
* @param Entry $Entry Blog entry to which to add a comment.
* @param string $CommentBody The body text of the comment.
* @return Returns a Message object.
*/
function Blog_PostComment(Entry $Entry, $CommentBody)
{
    # create a new message
    $Comment = Message::Create();
    $Comment->ParentId($Entry->Id());
    $Comment->ParentType(Message::PARENTTYPE_RESOURCE);
    $Comment->PosterId(User::getCurrentUser()->Id());
    $Comment->DatePosted(date("YmdHis"));
    $Comment->Subject("Blog Entry Comment");
    $Comment->Body($CommentBody);

    return $Comment;
}

/**
* Edit the comment associated with a blog entry.The comment body should be
* validated prior to calling this function.
* @param Message $Comment The comment to edit.
* @param string $CommentBody The new comment body.
*/
function Blog_EditComment(Message $Comment, $CommentBody)
{
    $Comment->EditorId(User::getCurrentUser()->Id());
    $Comment->DateEdited(date("YmdHis"));
    $Comment->Body($CommentBody);
}

/**
* Jump to a CWIS page with the given GET parameters.
* @param array $GetParameters GET parameters to use when jumping.
* @param string $Fragment Optional fragment identifier to tack on.
*/
function Blog_JumpTo(array $GetParameters, $Fragment = null)
{
    $AF = ApplicationFramework::getInstance();
    $Url = "index.php";

    # if going to a blog entry
    if (isset($GetParameters["P"]) && isset($GetParameters["EntryId"])) {
        if ($GetParameters["P"] == "P_Blog_Entry") {
            # get the entry for the ID
            $Entry = new Entry($GetParameters["EntryId"]);

            # remove the parameters
            unset($GetParameters["P"]);
            unset($GetParameters["EntryId"]);

            $AF->SetJumpToPage($Entry->EntryUrl($GetParameters, $Fragment));
            return;
        }
    }

    # no parameters so just use the URL
    if (!count($GetParameters)) {
        # tack on the fragment identifier, if necessary
        if (!is_null($Fragment)) {
            $Url .= "#".urlencode($Fragment);
        }

        $AF->SetJumpToPage($Url);
        return;
    }

    # add the GET parametrs
    $Url .= "?".http_build_query($GetParameters);

    # tack on the fragment identifier, if necessary
    if (!is_null($Fragment)) {
        $Url .= "#".urlencode($Fragment);
    }

    # set the jump
    $AF->SetJumpToPage($Url);
}

# ----- MAIN -----------------------------------------------------------------

PageTitle("Edit Comment");

$AF = ApplicationFramework::getInstance();

# retrieve user currently logged in
$User = User::getCurrentUser();

# assume that a generic error will occur
$H_State = "Error";

# get parameters
$H_CommentId = StdLib::getFormValue("F_CommentId", StdLib::getFormValue("CommentId"));
$H_CommentSubject = "Blog Entry Comment";
$H_CommentBody = StdLib::getFormValue("F_CommentBody");
$H_Action = StdLib::getFormValue("F_Action");

# Get the requested EntryId
$H_EntryId = StdLib::getFormValue("F_EntryId", StdLib::getFormValue("ID"));

# get the blog plugin object
$H_Blog = PluginManager::getInstance()->getPluginForCurrentPage();

# if the entry ID looks invalid
if (!is_numeric($H_EntryId) || !Record::ItemExists($H_EntryId)) {
    $H_State = "Invalid Entry ID";
    return;
}

# pull out the current entry (which we need to get the current blog)
$H_Entry = new Entry($H_EntryId);

# if the entry is some other type of resource
if (!$H_Blog->IsBlogEntry($H_Entry)) {
    $H_State = "Not Blog Entry";
    return;
}

# select the current blog
$H_Blog->SetCurrentBlog($H_Entry->GetBlogId());

# go to the blog page if comments aren't enabled
if (!$H_Blog->EnableComments()) {
    $AF->SetJumpToPage("P_Blog_Entries");
    return;
}

# go to the blog page if the user can't post comments
if (!$H_Blog->UserCanPostComment($User)) {
    $AF->SetJumpToPage("P_Blog_Entries");
    return;
}

# if performing an action to a comment
if (!is_null($H_Action)) {
    # check for empty comment
    if ($H_Action == "Post" || $H_Action == "Edit") {
        if (!strlen(trim($H_CommentBody))) {
            $H_State = "Empty Comment";
            return;
        }
    }

    # if posting a new comment
    if ($H_Action == "Post") {
        $PublicationDate = $H_Entry->Get(Blog::PUBLICATION_DATE_FIELD_NAME);

        # if the user cannot view the entry
        if (!$H_Entry->UserCanView($User)) {
            $H_State = "Entry Not Viewable";
            return;
        }

        $H_Comment = Blog_PostComment($H_Entry, $H_CommentBody);

        # if the message couldn't be created
        if (is_null($H_Comment)) {
            $H_State = "Comment Creation Failed";
            return;
        }
    } elseif ($H_Action == "Edit" || $H_Action == "Delete" || $H_Action == "Cancel") {
        # if editing or canceling editing an existing comment
        if (Message::ItemExists($H_CommentId)) {
            $H_Comment = new Message($H_CommentId);
        } else {
            $H_State = "Invalid Comment ID";
            return;
        }

        # if the user isn't allowed to edit the comment
        if ($H_Action != "Cancel" && !$H_Blog->UserCanEditComment($User, $H_Comment)) {
            $H_State = "Not Allowed to Edit";
            return;
        }

        if ($H_Action == "Edit") {
            Blog_EditComment($H_Comment, $H_CommentBody);
        } elseif ($H_Action == "Delete") {
            $H_EntryId = $H_Comment->parentId();
            $H_Comment->destroy();

            # set up page jumping
            Blog_JumpTo(
                [
                    "P" => "P_Blog_Entry",
                    "EntryId" => $H_EntryId
                ],
                "comments"
            );
            return;
        }
    } else {
        # invalid action
        $H_State = "Invalid action";
        return;
    }

    # make sure the entry ID is set
    $H_CommentId = $H_Comment->MessageId();
    $H_EntryId = $H_Comment->ParentId();
    $H_Entry = new Entry($H_EntryId);

    # set up page jumping
    Blog_JumpTo(
        [
            "P" => "P_Blog_Entry",
            "EntryId" => $H_Entry->Id()
        ],
        "comment-".$H_Comment->MessageId()
    );
} else {
    # just load the comment for editing
    if (Message::ItemExists($H_CommentId)) {
        $H_Comment = new Message($H_CommentId);
    } else {
        $H_State = "Invalid Comment ID";
        return;
    }

    # the user is not allowed to edit the comment
    if (!$H_Blog->UserCanEditComment($User, $H_Comment)) {
        $H_State = "Not Allowed to Edit";
        return;
    }

    # make sure the entry ID is set
    $H_EntryId = $H_Comment->ParentId();
    $H_Entry = new Entry($H_EntryId);
}

# everything went okay
$H_State = "OK";
