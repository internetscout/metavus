<?PHP
#
#   FILE:  ObserverSupportTrait.php
#
#   Part of the ScoutLib application support library
#   Copyright 2022-2025 Edward Almasy and Internet Scout Research Group
#   http://scout.wisc.edu
#
# @scout:phpstan

namespace ScoutLib;

/**
 * Trait to add support for notifying observers of events.
 *
 * Exhibiting classes should provide a notifyObservers() public method, that
 * accepts a prescribed set of arguments appropriate for the notification use,
 * and then calls notifyObserversWithArgs() from this trait.
 */
trait ObserverSupportTrait
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Add observer function, to be notified when events defined by the
     * exhibiting class occur.  Observers will be called using a callback
     * signature defined by the exhibiting class.
     * @param int $Events What events to notify about (one or more EVENT_
     *      values ORed together).
     * @param callable $ObserverFunc Function to call with notifications.
     * @param int $ItemId Only notify for items with this ID.  (OPTIONAL)
     * @see notifyObservers()
     */
    public static function registerObserver(
        int $Events,
        callable $ObserverFunc,
        ?int $ItemId = null
    ): void {
        static::$Observers[] = [
            "Events" => $Events,
            "Callback" => $ObserverFunc,
            "Item ID" => $ItemId,
        ];
    }


    # ---- PRIVATE INTERFACE -------------------------------------------------

    protected static $Observers = [];

    /**
     * Notify any registered observers about the specified event.
     * @param int $Event Event to notify about (EVENT_ constant).
     * @param array $Args Arguments to pass to observer callbacks.
     * @param int $ItemId ID of item associated with event.  (OPTIONAL)
     */
    protected function notifyObserversWithArgs(
        int $Event,
        array $Args,
        ?int $ItemId = null
    ): void {
        foreach (self::$Observers as $Observer) {
            if ($Event & $Observer["Events"]) {
                if (($ItemId === null)
                        || ($Observer["Item ID"] === null)
                        || ($ItemId === $Observer["Item ID"])) {
                    call_user_func_array($Observer["Callback"], $Args);
                }
            }
        }
    }
}
