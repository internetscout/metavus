<?PHP
#
#   FILE: Record.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2011-2020 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins\UrlChecker;

use ScoutLib\Database;

class Record extends \Metavus\Record
{

    /**
     * Constructor: save the check date if given and pass the resource ID
     * parameter onto the parent class.
     * @param int $ResourceId Record ID
     * @param string $CheckDate the last time the resource was checked
     */
    public function __construct($ResourceId, $CheckDate = null)
    {
        if (!is_null($CheckDate)) {
            $this->CheckDate = strval($CheckDate);
        }

        parent::__construct($ResourceId);
    }

    /**
     * Get the last time the resource was checked.
     * @return string the last time the resource was checked, or "N/A" if never checked
     */
    public function getCheckDate()
    {
        if (!isset($this->CheckDate)) {
            $DB = new Database();
            $DB->query(
                "SELECT * FROM UrlChecker_RecordHistory "
                ."WHERE RecordId = '".intval($this->id())."'"
            );
            $Row = $DB->fetchRow();

            $this->CheckDate = is_array($Row) ?
                $Row["CheckDate"] : "N/A";
        }

        return $this->CheckDate;
    }

    /**
     * @var ?string $CheckDate holds when the resource was checked last
     */
    private $CheckDate;
}
