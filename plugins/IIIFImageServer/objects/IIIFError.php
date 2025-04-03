<?PHP
#
#   FILE:  IIIFError.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2024 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins\IIIFImageServer;

/**
 * Object representing an error resulting from an IIIF operation.
 */
class IIIFError
{
    /**
     * Class constructor.
     * @param int $ErrorCode HTTP response code for this error.
     * @param string $ErrorDetail Text describing the specific error.
     */
    public function __construct(int $ErrorCode, string $ErrorDetail)
    {
        $this->ErrorCode = $ErrorCode;
        $this->ErrorDetail = $ErrorDetail;
    }

    /**
     * Set HTTP headers and output the error string for the user.
     * @return void
     */
    public function reportError() : void
    {
        $HttpReply = $this->ErrorCode." ".$this->reasonPhrase();
        header($_SERVER["SERVER_PROTOCOL"]." ".$HttpReply);

        print $HttpReply." - ".$this->ErrorDetail;
    }


    # ---- PRIVATE INTERFACE -------------------------------------------------

    /**
     * Get the HTTP 'Reason Phrase' for a given response code. (Only needs to
     * cover the response codes used in the IIIF spec).
     * @return string Reason phrase.
     */
    private function reasonPhrase() : string
    {
        switch ($this->ErrorCode) {
            case 400:
                return "Bad Request";

            case 500:
                return "Internal Server Error";

            default:
                return "";
        }
    }

    private $ErrorCode;
    private $ErrorDetail;
}
