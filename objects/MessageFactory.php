<?PHP
#
#   FILE:  MessageFactory.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2011-2020 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus;

use ScoutLib\ItemFactory;

/**
 * Factory for forum messages / resource comments.
 * \nosubgrouping
 */
class MessageFactory extends ItemFactory
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /** @name Setup/Initialization */
    /*@{*/

    /**
     * Object constructor.
     */
    public function __construct()
    {
        parent::__construct("Metavus\\Message", "Messages", "MessageId", "Subject");
    }

    /*@}*/

    /** @name Accessors */
    /*@{*/

    /**
     * Get all messages posted by specified user, in reverse date order.
     * @param int $UserId ID of user.
     * @param int $Count Number of messages to retrieve.  (OPTIONAL)
     * @return array Array of Message objectss.
     */
    public function getMessagesPostedByUser(int $UserId, int $Count = null): array
    {
        # retrieve message IDs posted by specified user
        $Messages = [];
        if ($Count !== null && intval($Count) == 0) {
            return $Messages;
        }

        $this->DB->Query("SELECT MessageId FROM Messages"
                ." WHERE PosterId = ".intval($UserId)
                ." ORDER BY DatePosted DESC"
                .($Count ? " LIMIT ".intval($Count) : ""));
        $MessageIds = $this->DB->FetchColumn("MessageId");

        # load messages based on message IDs
        foreach ($MessageIds as $Id) {
            $Messages[$Id] = new Message($Id);
        }

        # return array of message IDs to caller
        return $Messages;
    }

    /*@}*/

    # ---- PRIVATE INTERFACE -------------------------------------------------
}
