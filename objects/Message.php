<?PHP
#
#   FILE:  Message.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2012-2022 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus;

use ScoutLib\Database;
use ScoutLib\Item;

/**
 * Abstraction for forum messages and resource comments.
 * \nosubgrouping
 */
class Message extends Item
{
    const PARENTTYPE_TOPIC = 1;
    const PARENTTYPE_RESOURCE = 2;

    # ---- PUBLIC INTERFACE --------------------------------------------------

    /** @name Setup/Initialization/Destruction */
    /*@{*/

    /**
     * Create an empty message object.
     * @return Message The message just created.
     */
    public static function create(): Message
    {
        $DB = new Database();

        # create a new empty message record
        $DB->query("INSERT INTO Messages (MessageId) VALUES (NULL)");

        # get its message id
        $MessageId = $DB->getLastInsertId();

        $Message = new Message($MessageId);
        return $Message;
    }

    /*@}*/

    /** @name Accessors */
    /*@{*/

    /**
     * Get this message's messageId.
     * @return int Message ID.
     */
    public function messageId(): int
    {
        return $this->id();
    }

    /**
     * Get the username of the most recent poster.
     * @return string|null User name of the most recent poster or NULL if
     *      no poster available.
     */
    public function posterName()
    {
        $PosterId = $this->posterId();
        if ((new UserFactory())->userExists($PosterId)) {
            $PosterName = new User($PosterId);
            return $PosterName->get("UserName");
        } else {
            return null;
        }
    }

    /**
     * Get the email address of the most recent poster
     * @return string|null Email address of the most recent poster or NULL if
     *      no poster available.
     */
    public function posterEmail()
    {
        $PosterId = $this->posterId();
        if ((new UserFactory())->userExists($PosterId)) {
            $PosterName = new User($PosterId);
            return $PosterName->get("EMail");
        } else {
            return null;
        }
    }

    /**
     * Get the user ID of the most recent editor.
     * @param int $NewValue New editor ID.  (OPTIONAL)
     * @return int User ID of the most recent editor.
     */
    public function editorId(int $NewValue = null): int
    {
        return $this->DB->updateIntValue("EditorId", $NewValue);
    }

    /**
     * Get or set the ParentId.
     * For resource comments, the ParentId is the ResourceId.
     * @param int $NewValue New value to set (OPTIONAL)
     * @return int Current parent ID.
     */
    public function parentId(int $NewValue = null): int
    {
        return $this->DB->updateIntValue("ParentId", $NewValue);
    }

    /**
     * Get or set the ParentType.
     * Parent Type = 1 for forum posts and
     * Parent Type = 2 for resource comments
     * @param int $NewValue New parent type.  (OPTIONAL)
     * @return int Current parent type.
     */
    public function parentType(int $NewValue = null): int
    {
        return $this->DB->updateIntValue("ParentType", $NewValue);
    }

    /**
     * Get or set the date posted.
     * @param string $NewValue New posting date.  (OPTIONAL)
     * @return string Posting date.
     */
    public function datePosted(string $NewValue = null): string
    {
        return $this->DB->updateValue("DatePosted", $NewValue);
    }

    /**
     * Get or set the date the message was last edited
     * @param string $NewValue New edit date.  (OPTIONAL)
     * @return string Date the message was last edited.
     */
    public function dateEdited(string $NewValue = null): string
    {
        return $this->DB->updateValue("DateEdited", $NewValue);
    }

    /**
     * Get or set the poster id (e.g., the author) for this message.
     * @param int $NewValue New poster ID.  (OPTIONAL)
     * @return int ID number of this message's author.
     */
    public function posterId(int $NewValue = null): int
    {
        return $this->DB->updateIntValue("PosterId", $NewValue);
    }

    /**
     * Get or set the message subject.
     * @param string $NewValue New subject text.  (OPTIONAL)
     * @return string Message subject.
     */
    public function subject($NewValue = null)
    {
        return $this->DB->updateValue("Subject", $NewValue);
    }

    /**
     * Get or set the message body.
     * @param string $NewValue New body text.  (OPTIONAL)
     * @return string Message body.
     */
    public function body($NewValue = null)
    {
        return $this->DB->updateValue("Body", $NewValue);
    }
    /*@}*/
}
