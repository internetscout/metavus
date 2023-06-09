<?PHP
#
#   FILE: StatusLine.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2011-2020 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins\UrlChecker;

class StatusLine
{
    /**
     * Constructor: Parse the status line.
     * @param string $StatusLine an HTTP status line
     */
    public function __construct($StatusLine)
    {
        $StatusLine = trim($StatusLine);

        # rejects most invalid status lines: the expression for the reason
        # phrase isn't technically correct
        if (preg_match('/(HTTP\/(0|[1-9][0-9]*)\.(0|[1-9][0-9]*))\s'
            .'([1-5][0-9]{2})\s([^\r\n]+)/', $StatusLine, $Matches)) {
            list(, $Version,,, $Code, $Phrase) = $Matches;
            $this->StatusCode = intval($Code);
            $this->ReasonPhrase = $Phrase;
        }
    }

    /**
     * Get the status code.
     * @return int status code
     */
    public function getStatusCode()
    {
        return $this->StatusCode;
    }

    /**
     * Get the reason phrase.
     * @return string reason phrase
     */
    public function getReasonPhrase()
    {
        return $this->ReasonPhrase;
    }

    private $StatusCode;
    private $ReasonPhrase;
}
