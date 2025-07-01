<?PHP
#
#   FILE:  StoredEmail.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2011-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins\Mailer;
use Metavus\Plugins\Mailer;
use ScoutLib\ApplicationFramework;
use ScoutLib\Email;
use ScoutLib\Item;
use ScoutLib\StdLib;

/**
 * Class representing stored Email messages that are waiting for user
 * confirmation prior to being sent.
 */
class StoredEmail extends Item
{
    /**
     * Create a new StoredEmail.
     * @param Email $Email Email object to store.
     * @param array $Resources Array of Resource objects used to generate this Email.
     * @param int $TemplateId Template used to generate this Email.
     * @return StoredEmail Newly created StoredEmail.
     */
    public static function create($Email, $Resources, $TemplateId): StoredEmail
    {
        $ResourceIds = [];
        foreach ($Resources as $Resource) {
            $ResourceIds[] = $Resource->Id();
        }

        $Item = static::createWithValues([
            "Mailer_StoredEmailName" => $Email->subject(),
            "FromAddr" => $Email->from(),
            "ToAddr" => implode(', ', $Email->to()),
            "TemplateId" => $TemplateId,
            "NumResources" => count($Resources),
            "ResourceIds" => implode(',', $ResourceIds),
            "DateCreated" => date('Y-m-d H:i:s'),
        ]);

        $Item->setEmail($Email);

        return $Item;
    }

    /**
     * Retrieve the Email object stored with this message.
     * @return Email object.
     */
    public function getEmail(): Email
    {
        if ($this->Email === null) {
            $this->Email = unserialize($this->DB->UpdateValue("Email"));
        }
        return $this->Email;
    }

    /**
     * Send the saved Email and destroy this object.
     */
    public function send(): void
    {
        $Msg = $this->getEmail();
        $Result = $Msg->send();
        if ($Result) {
            $this->destroy();
        } else {
            $MailerPlugin = Mailer::getInstance();

            $TemplateId = $this->DB->UpdateValue("TemplateId");
            $Templates = $MailerPlugin->getTemplateList();
            $TemplateName = $Templates[$TemplateId];

            ApplicationFramework::getInstance()->logMessage(
                ApplicationFramework::LOGLVL_ERROR,
                "Mailer:  Unable to send email to "
                .implode(", ", $Msg->to())
                ." using template \"".$TemplateName."\" ("
                .$TemplateId.")."
                ." Email with ID ".$this->id()." retained in queue."
            );
        }
    }

    /**
     * Set the Email object to be stored.
     * @param Email $Email Email to store.
     */
    private function setEmail($Email): void
    {
        $this->Email = $Email;
        $this->DB->UpdateValue("Email", serialize($Email));
    }

    private $Email = null;

    /**
     * Set the database access values (table name, ID column name, name column
     * name) for specified class.  This may be overridden in a child class, if
     * different values are needed, Overrides Item::setDatabaseAccessValues()
     * due to issues with Class and database table names not lining up.
     * @param string $Index Class to set values for.
     */
    protected static function setDatabaseAccessValues(string $Index): void
    {
        $ClassName = "Mailer_StoredEmail";
        if (!isset(self::$ItemIdColumnNames[$Index])) {
            self::$ItemIdColumnNames[$Index] = $ClassName . "Id";
            self::$ItemNameColumnNames[$Index] = $ClassName . "Name";
            self::$ItemTableNames[$Index] = StdLib::pluralize($ClassName);
        }
    }
}
