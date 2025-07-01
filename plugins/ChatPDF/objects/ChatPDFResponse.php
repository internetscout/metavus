<?PHP
#
#   FILE: ChatPDFResponse.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins\ChatPDF;

/**
 * Class to representing results from ChatPDF, including both successful
 * queries and errors.
 */
class ChatPDFResponse implements \JsonSerializable
{
    /**
     * Get text associated with this response.
     * @return string Response data.
     */
    public function getData() : string
    {
        return $this->Data;
    }

    /**
     * Determine if this response was an error.
     * @return bool TRUE for errors, FALSE otherwise.
     */
    public function isError(): bool
    {
        return $this->IsError;
    }

    /**
     * Convert to JASON.
     * @return mixed data formatted for json_encode()
     */
    public function jsonSerialize(): mixed
    {
        if ($this->IsError) {
            return [
                "status" => "error",
                "message" => $this->Data,
            ];
        }

        return [
            "status" => "success",
            "response" => $this->Data,
        ];
    }

    /**
     * Create an object representing a successful reply.
     * @param string $Data Text of the reply.
     */
    public static function createAnswer(string $Data): self
    {
        return new self($Data, false);
    }

    /**
     * Create an object representing an error.
     * @param string $Data Error message.
     */
    public static function createError(string $Data): self
    {
        return new self($Data, true);
    }

    /**
     * Constructor.
     * @param string $Value Text associated with this response.
     * @param bool $IsError TRUE for errors, FALSE otherwise.
     */
    private function __construct(string $Value, bool $IsError)
    {
        $this->Data = $Value;
        $this->IsError = $IsError;
    }

    private string $Data = "";
    private bool $IsError = false;
}
