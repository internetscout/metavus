<?PHP
#
#   FILE:  PluginCaller.php
#
#   Part of the ScoutLib application support library
#   Copyright 2018-2021 Edward Almasy and Internet Scout Research Group
#   http://scout.wisc.edu
#
# @scout:phpstan

namespace ScoutLib;

use Exception;

/**
 * Helper class for internal use by PluginManager.  This class is used to
 * allow plugin methods to be triggered by events that only allow serialized
 * callbacks (e.g. periodic events).
 * The plugin name and the method to be called are set and then the
 * PluginCaller object is serialized out.  When the PluginCaller object is
 * unserialized, it retrieves the appropriate plugin object from the
 * PluginManager (pointer to PluginManager is set in PluginManager
 * constructor) and calls the specified method.
 */
class PluginCaller
{

    /**
     * Class constructor, which stores the plugin name and the name of the
     * method to be called.
     * @param string $PluginName Name of plugin.
     * @param string $MethodName Name of method to be called.
     */
    public function __construct(string $PluginName, string $MethodName)
    {
        $this->PluginName = $PluginName;
        $this->MethodName = $MethodName;
    }

    /**
     * Call the method that was specified in our constructor.  This method
     * accept whatever arguments are appropriate for the specified method
     * and returns values as appropriate for the specified method.
     * @param array $Args Method arguments.
     */
    public function callPluginMethod(...$Args)
    {
        $Plugin = self::$Manager->getPlugin($this->PluginName);
        $Callback = [$Plugin, $this->MethodName];
        # (check to ensure callback is valid added to make phpstan happy)
        if (!is_callable($Callback)) {
            throw new Exception("Specified plugin method is not callable ("
                    .$this->PluginName."::".$this->MethodName.").");
        }
        $MethodParamCount = StdLib::getReflectionForCallback(
            $Callback
        )->getNumberOfParameters();
        if ($MethodParamCount == 0) {
            return $Plugin->{$this->MethodName}();
        } else {
            return $Plugin->{$this->MethodName}(...$Args);
        }
    }

    /**
     * Get full method name as a text string.
     * @return string Method name, including plugin class name.
     */
    public function getCallbackAsText()
    {
        return $this->PluginName . "::" . $this->MethodName;
    }

    /**
     * Sleep method, specifying which values are to be saved when we are
     * serialized.
     * @return array Array of names of variables to be saved.
     */
    public function __sleep()
    {
        return array("PluginName", "MethodName");
    }

    /** PluginManager to use to retrieve appropriate plugins. */
    public static $Manager;

    private $PluginName;
    private $MethodName;
}
