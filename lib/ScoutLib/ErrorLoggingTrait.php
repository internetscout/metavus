<?PHP
#
#   FILE:  ErrorLoggingTrait.php
#
#   Part of the ScoutLib application support library
#   Copyright 2023 Edward Almasy and Internet Scout Research Group
#   http://scout.wisc.edu
#
# @scout:phpstan

namespace ScoutLib;

use Exception;

/**
 * Adds support for logging non-persistent errors, retrievable by other code
 * within the same invocation.  This is intended primarily for situations
 * where errors need to be recorded without immediately aborting the current
 * function (e.g. when importing data).
 */
trait ErrorLoggingTrait
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Get error messages (if any) from recent calls.
     * @param string $Method Name of method (without class name).
     * @return array Array of error messages strings.
     */
    public function getErrorMessages(string $Method): array
    {
        if (!method_exists($this, $Method)) {
            throw new Exception("Error messages requested for non-existent"
                    ." method (".$Method.").");
        }

        # force supplied method name to all lower case to make method
        #       name usage case-insensitive (as it is in PHP)
        $Method = strtolower($Method);

        # (qualified method name is also checked, to support legacy usage)
        $QualifiedMethod = get_class($this)."::".$Method;
        return $this->ErrorMsgs[$Method]
                ?? $this->ErrorMsgs[$QualifiedMethod]
                ?? [];
    }

    /**
     * Get error messages (if any) from recent calls to all methods in this
     * class that log error messages.
     * @return array Array of arrays of error message strings, with
     *      method names for the outer index.
     */
    public function getAllErrorMessages(): array
    {
        return $this->ErrorMsgs;
    }


    # ---- PRIVATE INTERFACE -------------------------------------------------

    protected $ErrorMsgs = [];

    /**
     * Log error message for current method.
     * @param string $ErrMsg Error message.
     */
    protected function logErrorMessage(string $ErrMsg)
    {
        $this->ErrorMsgs[self::getCallingMethod()][] = $ErrMsg;
    }

    /**
     * Report whether any errors are currently logged for current method.
     * @return bool TRUE if errors are logged, otherwise FALSE.
     */
    protected function thereAreErrorMessages(): bool
    {
        return isset($this->ErrorMsgs[self::getCallingMethod()]);
    }

    /**
     * Clear any existing error messages for current method.
     */
    protected function clearErrorMessages()
    {
        $Method = self::getCallingMethod();
        if (isset($this->ErrorMsgs[$Method])) {
            unset($this->ErrorMsgs[$Method]);
        }
    }

    /**
     * Clear any existing error messages for all method in this class.
     */
    protected function clearAllErrorMessages()
    {
        $this->ErrorMsgs = [];
    }

    /**
     * Get name of calling method.
     * @return string Name of calling method, or "(unknown)" if unable to determine name.
     */
    private static function getCallingMethod(): string
    {
        # (force method name to all lower case to make method name usage
        #       case-insensitive (as it is in PHP))
        $Trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3);
        return strtolower($Trace[2]["function"] ?? "(unknown)");
    }
}
