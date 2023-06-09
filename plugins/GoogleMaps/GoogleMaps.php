<?PHP
#
#   FILE:  GoogleMaps.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2002-2023 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#

namespace Metavus\Plugins;

use DirectoryIterator;
use Exception;
use InvalidArgumentException;
use Metavus\FormUI;
use Metavus\MetadataField;
use Metavus\MetadataSchema;
use Metavus\Plugin;
use Metavus\Plugins\GoogleMaps\CallbackManager;
use Metavus\Record;
use Metavus\RecordFactory;
use Metavus\UserFactory;
use ScoutLib\ApplicationFramework;
use ScoutLib\Database;
use ScoutLib\PluginManager;
use ScoutLib\StdLib;

/**
 * Adds the ability to insert a Google Maps window into an interface.
 */
class GoogleMaps extends Plugin
{
    # map style constants
    #   These constants can be used to generate a googlemaps.MapOptions configuration.
    #   Below, the effect of each constant is briefly described, and the MapOptions
    #   elements corresponding to the contant are listed.

    # See https://developers.google.com/maps/documentation/javascript/reference#MapOptions
    #   for additional detail on the MapOptions.

    ##
    # options that disable certain features

    # disable all UI elements, showing just the map
    const NO_DEFAULT_UI         =    1; # disableDefaultUI = true

    # disable 'double click to zoom'
    const NO_DOUBLE_CLICK_ZOOM  =    2; # disableDoubleClickZoom = true

    # disable dragging of the map
    const NO_DRAGGABLE          =    4; # draggable = false

    # disable keyboard shortcuts
    const NO_KEYBOARD_SHORTCUTS =    8; # keybardShortcuts = false

    # do not clear the map <div> before adding map tiles to it
    const NO_CLEAR              =   16; # noClear = true

    # disable the overview map control
    const NO_OVERVIEW           =   32; # overviewMapControl = false

    # disable the pan control
    const NO_PAN                =   64; # panControl = false

    # disable the map rotation control
    const NO_ROTATE             =  128; # rotateControl = false

    # disable scroll wheel zoom
    const NO_WHEEL_ZOOM         =  256; # scroolwheel = false

    # disable zooming entirely
    const NO_ZOOM               =  512; # zoomControl = false

    ##
    # options that set initial values for certain controls

    # set the map type control to be initially hidden
    const MAP_TYPE_START_OFF    = 1024; # mapTypeControl = false

    # set the map scale control to be initially hidden
    const SCALE_START_OFF       = 2048; # scaleControl = false

    # set the street view control (the "pegman") to be initially hidden
    const STREET_VIEW_START_OFF = 4096; # streetViewControl = false

    # use MapMaker tiles instead of regular tiles
    #   see http://www.google.com/mapmaker
    const USE_MAP_MAKER         = 8192; # mapMaker = true


    # constants for getting values out of point fields
    const POINT_LATITUDE = "X";
    const POINT_LONGITUDE = "Y";

    const MAILER_TEMPLATE_NAME = "GoogleMaps Geocode Error";

    /**
     * Register information about this plugin.
     */
    public function register()
    {
        $this->Name = "Google Maps";
        $this->Version = "1.3.5";
        $this->Description = "This plugin adds the ability to add a Google"
                ." Maps window to a page or interface.";
        $this->Author = "Internet Scout Research Group";
        $this->Url = "https://metavus.net";
        $this->Email = "support@metavus.net";
        $this->Requires = [
            "MetavusCore" => "1.0.0",
            "Mailer" => "1.3.1"
        ];

        $this->addAdminMenuEntry(
            "ErrorLog",
            "Geocode Error Log",
            [ PRIV_SYSADMIN ]
        );
    }

    /**
     * Set up configuration options.
     * @return NULL on success or a string on failure.
     */
    public function setUpConfigOptions()
    {
        $this->CfgSetup["APIKey"] = [
            "Type" => FormUI::FTYPE_TEXT,
            "Default" => "",
            "Label" => "Maps Javascript API Key",
            "Help" => "Google Cloud Platform API Key for the Maps Javascript API "
                ."and the Static Maps API. "
                ."Keys can be obtained from: "
                ."<a href='https://developers.google.com/maps/documentation/"
                ."javascript/get-api-key'>"
                ."https://developers.google.com/maps/documentation/"
                ."javascript/get-api-key</a>",
            "ValidateFunction" => '\Metavus\Plugins\GoogleMaps::validateConfigSettings',
        ];

        $this->CfgSetup["GeocodingAPIKey"] = [
            "Type" => FormUI::FTYPE_TEXT,
            "Default" => "",
            "Label" => "Geocoding API Key",
            "Help" => "Google Cloud Platform API Key for the Geocoding API. "
                ."Required if you will be geocoding addresses. "
                ."This key must not have any referer restrictions, as the Geocoding "
                ."API forbids them.",
            "ValidateFunction" => '\Metavus\Plugins\GoogleMaps::validateConfigSettings',
        ];

        # maximum cache lifetimes are set arbitrarily to one year
        # (365 * 24 * 60 * 60 = 31536000)
        $this->CfgSetup["KmlCacheLifetime"] = [
            "Type" => "Number",
            "MinVal" => 60,
            "MaxVal" => 31536000,
            "Default" => 3600,
            "Label" => "KML Cache Lifetime",
            "Help" => "How long KML data should be cached by Google."
                ." KML data specifies map marker locations and the"
                ." content of marker pop-ups.",
            "Units" => "seconds",
        ];

        $this->CfgSetup["ExpTime"] = [
            "Type" => "Number",
            "MaxVal" => 31536000,
            "Label" => "Geocode Cache Expiration Time",
            "Help" => "Specifies how long to cache geocode results, in seconds.",
            "Units" => "seconds",
        ];

        $this->CfgSetup["GeocodeFailureCacheExpirationTime"] = [
            "Type" => "Number",
            "MaxVal" => 31536000,
            "Default" => 1800,
            "Label" => "Geocode Failure Cache Expiration Time",
            "Help" => "Specifies how long to cache a failure to geocode an address, in seconds. "
                ."Until this time elapses, repeated requests for the same address will also fail. "
                ."After that, geocoding will be retried.",
            "Units" => "seconds",
        ];

        $this->CfgSetup["GeocodeErrorEmailTemplate"] = [
            "Type" => "Option",
            "Label" => "Geocode Error Mailer Template",
            "Help" => "Template to use when sending email reports about errors "
                ."geocoding an address",
            "Options" => PluginManager::getInstance()
                ->getPlugin("Mailer")
                ->getTemplateList(),
        ];

        $this->CfgSetup["GeocodeErrorEmailRecipients"] = [
            "Type" => "Privileges",
            "Label" => "Geocode Error Email Recipients",
            "AllowMultiple" => true,
            "Help" => "Users with any of the selected privilege flags "
                ."will receive an email when an address cannot be geocoded.",
            "Default" => [
                PRIV_COLLECTIONADMIN,
            ],
        ];

        $this->CfgSetup["DefaultSqlQuery"] = [
            "Type" => "Paragraph",
            "Label" => "SQL to generate map locations",
            "Help" =>
                "An SQL select statement which returns your geodata in columns named: ".
                "Latitude, Longitude, RecordId, MarkerColor, MarkerLabel, LabelColor. ".
                "Column order is not significant, but column names are. ".
                "Latitude, Longitude, and RecordId are required, the rest are optional. ".
                "NOTE: if you specify both an SQL query and a metadata field, the metadata ".
                "field will be used."
        ];

        $this->CfgSetup["DefaultPointField"] = [
            "Type" => "MetadataField",
            "FieldTypes" => MetadataSchema::MDFTYPE_PARAGRAPH
                    | MetadataSchema::MDFTYPE_TEXT
                    | MetadataSchema::MDFTYPE_POINT,
            "Label" => "Default field for map locations",
            "Help" =>
                    "Text or Paragraph field containing address data ".
                    "or a Point field containing a Lat/Lng. ".
                    "Must be a Text or Paragraph field to use autopopulation. ".
                    "If enabled, autopopulation will be based on this field. ".
                    "When GoogleMaps_EVENT_HTML_TAGS_SIMPLE is signaled, ".
                    "markers locations will come from this field."
        ];

        $this->CfgSetup["AutoPopulateEnable"] = [
            "Type" => "Flag",
            "Label" => "Enable autopopulation of metadata fields",
            "Help" => "This determines if GoogleMaps should update metadata fields ".
                "based on geocoded address information.  When enabled, the default ".
                "field for map locations must be a text or paragraph field.",
            "Default" => false,
            "OnLabel" => "Yes",
            "OffLabel" => "No"
        ];

        $this->CfgSetup["AutoPopulateInterval"] = [
            "Type" => "Number",
            "Label" => "Auto-Popluate Interval",
            "Default" => 5,
            "Units" => "minutes",
            "Size" => 4,
            "Help" => "How often to check for fields that need autopopulation.",
        ];

        # set up field mappings
        foreach ($this->AutoPopulateData as $Key => $Data) {
            $this->CfgSetup["FieldMapping-".$Key] = [
                "Type" => "MetadataField",
                "FieldTypes" => $Data["FieldType"],
                "Label" => "Autopopulate ".$Data["Display"],
                "Help" => "Field to auto-populate with "
                    .$Data["Display"]." based on geocoding of of the "
                ."selected map location field"
            ];
        }

        return null;
    }

    /**
     * Validation function for config settings.
     * @param string $Name Setting name.
     * @param mixed $Value Setting value.
     * @param array $Values All setting values.
     * @return string|null NULL on successful validation, error string otherwise.
     */
    public static function validateConfigSettings($Name, $Value, $Values)
    {
        $APIKeys = [
            "APIKey",
            "GeocodingAPIKey",
        ];

        if (in_array($Name, $APIKeys) &&
            strlen($Value) &&
            !preg_match("/[A-Za-z0-9_-]{39}/", $Value)) {
            return $Name." appears to be invalid.";
        }

        return null;
    }

    /**
     * Initialize default settings.
     * @return string|null NULL on success, an error string otherwise
     */
    public function install()
    {
        $this->configSetting(
            "GeocodeErrorEmailTemplate",
            $this->getMailerTemplateId()
        );

        $Result = $this->createTables(
            CallbackManager::$SqlTables
        );
        if ($Result !== null) {
            return $Result;
        }

        return $this->createTables($this->SqlTables);
    }

    /**
     * Uninstall the plugin.
     * @return string|null Returns  NULL if successful or an error message otherwise.
     */
    public function uninstall()
    {
        $Result = $this->dropTables(CallbackManager::$SqlTables);
        if ($Result !== null) {
            return $Result;
        }

        $Result = $this->dropTables($this->SqlTables);
        if ($Result !== null) {
            return $Result;
        }
        # remove the cache directory
        $CachePath = $this->getCachePath();
        if (file_exists($CachePath) &&
            !RemoveFromFilesystem($CachePath)) {
            return "Could not delete the cache directory.";
        }

        return null;
    }

    /**
     * Upgrade from a previous version.
     * @param string $PreviousVersion Previous version number
     */
    public function upgrade(string $PreviousVersion)
    {
        $DB = new Database();

        switch ($PreviousVersion) {
            case "1.0.1":
                $DB->query(
                    "CREATE TABLE GoogleMapsCallbacks ("
                    ."Id VARCHAR(32) UNIQUE, "
                    ."Payload BLOB,"
                    ."LastUsed TIMESTAMP,"
                    ." INDEX (Id))"
                );
                // fall through
            case "1.0.2":
                $DB->query(
                    "CREATE TABLE GoogleMapsGeocodes ("
                    ."Id VARCHAR(32) UNIQUE, "
                    ."Lat DOUBLE, "
                    ."Lng DOUBLE, "
                    ."LastUpdate TIMESTAMP, "
                    ."LastUsed TIMESTAMP, "
                    ."INDEX (Id))"
                );
                $this->configSetting("ExpTime", 604800);
                // fall through
            case "1.0.3":
                $this->configSetting("DefaultGridSize", 10);
                $this->configSetting("DesiredPointCount", 100);
                $this->configSetting("MaxIterationCount", 10);
                // fall through
            case "1.0.4":
                $DB->query(
                    "ALTER TABLE GoogleMapsCallbacks "
                    ."ADD COLUMN Params BLOB"
                );
                // fall through
            case "1.1.0":
                $this->checkCacheDirectory();
                // fall through
            case "1.2.1":
                // fall through
            case "1.2.2":
                $DB->query(
                    "ALTER TABLE GoogleMapsCallbacks "
                    ."RENAME TO GoogleMaps_Callbacks"
                );
                $DB->query(
                    "ALTER TABLE GoogleMapsGeocodes "
                    ."RENAME TO GoogleMaps_Geocodes"
                );
                // fall through
            case "1.2.7":
                $this->configSetting("ResourcesLastModified", time());
                // fall through
            case "1.2.8":
                $DB->query(
                    "ALTER TABLE GoogleMaps_Geocodes "
                    ."ADD COLUMN AddrData MEDIUMBLOB"
                );
                $DB->query("DELETE FROM GoogleMaps_Geocodes");
                // fall through
            case "1.3.0":
                $DB->query(
                    "ALTER TABLE GoogleMaps_Geocodes "
                    ."ADD COLUMN LastUsed TIMESTAMP"
                );

                $DB->query(
                    "DELETE FROM GoogleMaps_Geocodes WHERE Lat IS NULL"
                );

                $DB->query(
                    "UPDATE GoogleMaps_Geocodes SET LastUsed=NOW()"
                );

                $this->configSetting("GeocodeLastSuccessful", time());
                $this->createMissingTables($this->SqlTables);

                $this->configSetting(
                    "GeocodeErrorEmailTemplate",
                    $this->getMailerTemplateId()
                );
                // fall through

            case "1.3.1":
                $this->createMissingTables($this->SqlTables);
                $this->createMissingTables(
                    CallbackManager::$SqlTables
                );
                // fall through

            case "1.3.2":
                $this->checkCacheDirectory();
                // fall through

            case "1.3.3":
                foreach (["Params", "Payload"] as $Col) {
                    $DB->query(
                        "ALTER TABLE GoogleMaps_Callbacks "
                        ."CHANGE COLUMN ".$Col." ".$Col." BLOB"
                    );
                }
                // fall through

            default:
        }

        return null;
    }

    /**
     * Declare the events this plugin provides to the application framework.
     * @return array Returns an array of the events this plugin provides to the
     *      application framework.
     */
    public function declareEvents()
    {
        return [
            "GoogleMaps_EVENT_HTML_TAGS" => ApplicationFramework::EVENTTYPE_DEFAULT,
            "GoogleMaps_EVENT_HTML_TAGS_SIMPLE" => ApplicationFramework::EVENTTYPE_DEFAULT,
            "GoogleMaps_EVENT_STATIC_MAP" => ApplicationFramework::EVENTTYPE_DEFAULT,
            "GoogleMaps_EVENT_CHANGE_POINT_PROVIDER" => ApplicationFramework::EVENTTYPE_DEFAULT,
            "GoogleMaps_EVENT_GEOCODE" => ApplicationFramework::EVENTTYPE_FIRST,
            "GoogleMaps_EVENT_DISTANCE" => ApplicationFramework::EVENTTYPE_FIRST,
            "GoogleMaps_EVENT_BEARING" => ApplicationFramework::EVENTTYPE_FIRST,
            "GoogleMaps_EVENT_GET_KML" => ApplicationFramework::EVENTTYPE_FIRST
        ];
    }

    /**
     * Hook the events into the application framework.
     * @return array Returns an array of events to be hooked into the application
     *      framework.
     */
    public function hookEvents()
    {
        $Events = [
            "EVENT_HOURLY" => "cleanCaches",
            "EVENT_RESOURCE_ADD" => "updateResourceTimestamp",
            "EVENT_RESOURCE_DELETE" => "updateResourceTimestamp",
            "GoogleMaps_EVENT_HTML_TAGS" => "generateHTMLTags",
            "GoogleMaps_EVENT_HTML_TAGS_SIMPLE" => "generateHTMLTagsSimple",
            "GoogleMaps_EVENT_STATIC_MAP"   => "staticMap",
            "GoogleMaps_EVENT_GEOCODE" => "geocode",
            "GoogleMaps_EVENT_DISTANCE" => "computeDistance",
            "GoogleMaps_EVENT_BEARING" => "computeBearing",
            "GoogleMaps_EVENT_GET_KML" => "getKml",
            "Mailer_EVENT_IS_TEMPLATE_IN_USE" => "claimTemplate",
        ];

        if ($this->configSetting("AutoPopulateEnable")) {
            $Events["EVENT_RESOURCE_MODIFY"] = [
                "UpdateResourceTimestamp",
                "BlankAutoPopulatedFields"
            ];
            $Events["EVENT_PERIODIC"] = "UpdateAutoPopulatedFields";
        } else {
            $Events["EVENT_RESOURCE_MODIFY"] = "UpdateResourceTimestamp";
        }

        return $Events;
    }

    /**
     * Startup initialization for the plugin.
     * @return string|null NULL on success, otherwise an error string.
     */
    public function initialize()
    {
        if ($this->configSetting("AutoPopulateEnable")) {
            $SrcFieldId = $this->configSetting("DefaultPointField");
            $SrcField = new MetadataField($SrcFieldId);

            if ($SrcField->type() == MetadataSchema::MDFTYPE_POINT) {
                return "Autopopulation cannot be used with a Point field. "
                    ."Please select a different field or disable Autopopulation.";
            }
        }

        # explicitly add our include directories because our code is
        #       sometimes called on pages that do not belong to the plugin
        $BaseName = $this->getBaseName();
        ApplicationFramework::getInstance()->addIncludeDirectories([
            "plugins/".$BaseName."/interface/default/include/",
            "plugins/".$BaseName."/interface/%ACTIVEUI%/include/",
            "local/plugins/".$BaseName."/interface/default/include/",
            "local/plugins/".$BaseName."/interface/%ACTIVEUI%/include/",
        ], true);

        $this->CallbackManager = new CallbackManager();

        return null;
    }

    /**
     * Periodically check for resources that need their location
     * information set.
     * @return int The minimum number of minutes to wait before calling
     *     this method again.
     */
    public function updateAutoPopulatedFields()
    {
        $SrcFieldId = $this->configSetting("DefaultPointField");

        # if no field configured, come back later
        if ($SrcFieldId == null) {
            return $this->configSetting("AutoPopulateInterval");
        }

        # pull out the list of configured fields to autopopulate
        $AutoPopulateFields = [];
        foreach ($this->AutoPopulateData as $Key => $Data) {
            $TgtField = $this->configSetting("FieldMapping-".$Key);

            # if this field isn't mapped, check the next one
            if ($TgtField == null) {
                continue;
            }

            $AutoPopulateFields[$Key] = $Data;
            $AutoPopulateFields[$Key]["FieldId"] = $TgtField;
        }

        # assemble a list of resources that should be autopopulated, but are not
        $ValuesToMatch = [];
        foreach ($AutoPopulateFields as $Key => $Data) {
            $ValuesToMatch[$Data["FieldId"]] = "NULL";
        }

        # get resources that want an autopopulation
        $RFactory = new RecordFactory();
        $Resources = $RFactory->getIdsOfMatchingRecords(
            $ValuesToMatch,
            false
        );

        # get the resources that have no address
        $ExcludeResources = $RFactory->getIdsOfMatchingRecords(
            [$SrcFieldId => "NULL"]
        );

        # remove those from the list we'll consider
        $Resources = array_diff($Resources, $ExcludeResources);

        foreach ($Resources as $ResourceId) {
            $Resource = new Record($ResourceId);

            # pull out the address
            $Address = $Resource->get($SrcFieldId);

            # geocode it
            $GeoData = $this->geocode($Address, true);

            # if there was no geodata available for this address,
            if ($GeoData === null) {
                continue;
            }

            foreach ($AutoPopulateFields as $Key => $Data) {
                if ($Key == "LatLng") {
                    $Resource->set(
                        $Data["FieldId"],
                        [
                            "X" => $GeoData["Lat"],
                            "Y" => $GeoData["Lng"]
                        ]
                    );
                } else {
                    if (array_key_exists($Data["GoogleElement"], $GeoData["AddrData"])) {
                        $Resource->set(
                            $Data["FieldId"],
                            current($GeoData["AddrData"][$Data["GoogleElement"]])
                        );
                    }
                }
            }

            # make sure there's time for another query
            #   forground queries take ca 60s, so make sure we've got
            #   1.5x that in case of a particularly slow one
            if (ApplicationFramework::getInstance()->getSecondsBeforeTimeout() < 90) {
                break;
            }
        }

        return $this->configSetting("AutoPopulateInterval");
    }

    // phpcs:disable
    /**
     * Generates the HTML tags to make the Google Maps widget.
     *
     * Takes two parameters, a PointProvider and a DetailProvider.
     * Both are the PHP callbacks, the former takes a user-provided
     * array, and is expected to return all of the points with GPS
     * coordinates. The latter should take an id number (usually a
     * ResourceId) and a user-provided array and print a fragment of
     * HTML to display in the info window that pops up over the map
     * marker for that resource.  Anything that can be a php callback
     * is fair game.
     *
     * If you're using functions, they need to be part of the
     * environment when the helper pages for the plugin are loaded.
     * To accomplish this, put them in files called
     * F-(FUNCTION_NAME).html or F-(FUNCTION_NAME).php in your
     * 'interface', 'local/pages' or inside your interface directory.
     *
     * If you're using object methods, the objects will need to be
     * somewhere that the ApplicationFramework's object loading will
     * look, 'local/objects' is likely best.
     *
     * @param array $PointProvider Callback that provides point information
     * @param array $PointProviderParams Parameters passed to the point
     *      provider when it's called
     * @param array $DetailProvider Callback that provides detailed
     *      information about a point
     * @param array $DetailProviderParams Parameters passed to the detail
     *      provider when it's called
     * @param int $DefaultLat Latitude of initial center of the map
     * @param int $DefaultLon Longitude of the initial center of the map
     * @param int $DefaultZoom Zoom level of the initial map
     * @param string $InfoDisplayEvent Event that should trigger showing detailed
     *      point information.
     * @param string $KmlPage Page that generates KML for Google
     * @param int|array $MapsOptions Either a bitmask of style constants from
     *      this object, or an array specifying additional options for this map
     *      based on https://developers.google.com/maps/documentation/javascript/reference#MapOptions
     * @see GenerateHTMLTagsSimple()
     */
    // phpcs:enable
    public function generateHTMLTags(
        $PointProvider,
        $PointProviderParams,
        $DetailProvider,
        $DetailProviderParams,
        $DefaultLat,
        $DefaultLon,
        $DefaultZoom,
        $InfoDisplayEvent,
        $KmlPage = "P_GoogleMaps_GetKML",
        $MapsOptions = []
    ) {
        $AF = ApplicationFramework::getInstance();
        $UseSsl = isset($_SERVER["HTTPS"]);

        # Spit out the html tags required for the map
        print('<div class="GoogleMap"><br/><br/><br/><br/><center>'
                .'<span style="color: #DDDDDD;">[JavaScript Required]'
                .'</span></center></div>');

        $ApiKey = $this->configSetting("APIKey");

        if (strlen($ApiKey) == 0) {
            $ApiUrl = ($UseSsl) ?
                    'https://maps-api-ssl.google.com/maps/api/js?sensor=false' :
                    'http://maps.google.com/maps/api/js?sensor=false' ;
        } else {
            $ApiUrl = 'https://maps.googleapis.com/maps/api/js?key='.$ApiKey;
        }
        print('<script type="text/javascript" src="'.$ApiUrl.'"></script>');

        foreach (["jquery.cookie.js", "GoogleMapsExtensions.js"] as $Tgt) {
            print('<script type="text/javascript" src="'.
                  $AF->gUIFile($Tgt).'"></script>');
        }

        $PPHash = $this->CallbackManager
                ->registerCallback($PointProvider, $PointProviderParams);
        $DPHash = $this->CallbackManager
                ->registerCallback($DetailProvider, $DetailProviderParams);

        # process options provided by the user
        if (is_numeric($MapsOptions)) {
            $OptionsArray = [];

            if ($MapsOptions & self::NO_DEFAULT_UI) {
                $OptionsArray["disableDefaultUI"] = true;
            }
            if ($MapsOptions & self::NO_DOUBLE_CLICK_ZOOM) {
                $OptionsArray["disableDoubleClickZoom"] = true;
            }
            if ($MapsOptions & self::NO_DRAGGABLE) {
                $OptionsArray["draggable"] = false;
            }
            if ($MapsOptions & self::NO_KEYBOARD_SHORTCUTS) {
                $OptionsArray["keyboardShortcusts"] = false;
            }
            if ($MapsOptions & self::NO_CLEAR) {
                $OptionsArray["noClear"] = true;
            }
            if ($MapsOptions & self::NO_OVERVIEW) {
                $OptionsArray["overviewMapControl"] = false;
            }
            if ($MapsOptions & self::NO_PAN) {
                $OptionsArray["panControl"] = false;
            }
            if ($MapsOptions & self::NO_ROTATE) {
                $OptionsArray["rotateControl"] = false;
            }
            if ($MapsOptions & self::NO_WHEEL_ZOOM) {
                $OptionsArray["scrollwheel"] = false;
            }
            if ($MapsOptions & self::NO_ZOOM) {
                $OptionsArray["zoomControl"] = false;
            }

            if ($MapsOptions & self::MAP_TYPE_START_OFF) {
                $OptionsArray["mapTypeControl"] = false;
            }
            if ($MapsOptions & self::SCALE_START_OFF) {
                $OptionsArray["scaleControl"] = false;
            }
            if ($MapsOptions & self::STREET_VIEW_START_OFF) {
                $OptionsArray["streetViewControl"] = false;
            }

            if ($MapsOptions & self::USE_MAP_MAKER) {
                $OptionsArray["mapMaker"] = true;
            }

            $MapsOptions = $OptionsArray;
        }

        $Replacements = [
            "X-POINT-PROVIDER-X" => $PPHash,
            "X-DETAIL-PROVIDER-X" => $DPHash,
            "X-DEFAULT-LAT-X" => $DefaultLat,
            "X-DEFAULT-LON-X" => $DefaultLon,
            "X-DEFAULT-ZOOM-X" => $DefaultZoom,
            "X-DESIRED-POINT-COUNT-X" => $this->configSetting("DesiredPointCount"),
            "X-INFO-DISPLAY-EVENT-X" => $InfoDisplayEvent,
            "X-BASE-URL-X" => $AF->baseUrl(),
            "X-KML-PAGE-X" => $KmlPage,
            "X-KML-CACHE-INTERVAL-X" => $this->configSetting("KmlCacheLifetime") ?? 3600,
            "X-MAP-OPTIONS-X" => json_encode($MapsOptions),
        ];

        $MapApplication = str_replace(
            array_keys($Replacements),
            array_values($Replacements),
            file_get_contents("./plugins/GoogleMaps/GoogleMapsDisplay.js")
        );

        print '<style type="text/css">';
        print file_get_contents("plugins/GoogleMaps/GoogleMapsDisplay.css");
        print '</style>';

        print('<script type="text/javascript">'.$MapApplication.'</script>');
    }

    /**
     * Generates the HTML tags to make the Google Maps widget using the default
     * point and detail provider.
     * @param int $DefaultLat Latitude of initial center of the map.
     * @param int $DefaultLon Longitude of the initial center of the map.
     * @param int $DefaultZoom Zoom level to use initially.
     * @param string $InfoDisplayEvent Event that should trigger showing detailed
     *      point information.
     * @see GenerateHTMLTags()
     */
    public function generateHTMLTagsSimple(
        $DefaultLat,
        $DefaultLon,
        $DefaultZoom,
        $InfoDisplayEvent
    ) {
        $PointProvider = ["GoogleMaps", "DefaultPointProvider"];
        $PointProviderParams = [];
        $DetailProvider = ["GoogleMaps", "DefaultDetailProvider"];
        $DetailProviderParams = [];

        $this->generateHTMLTags(
            $PointProvider,
            $PointProviderParams,
            $DetailProvider,
            $DetailProviderParams,
            $DefaultLat,
            $DefaultLon,
            $DefaultZoom,
            $InfoDisplayEvent
        );
    }

    /**
     * Register a point provider callback. To use this provider on a
     *   currently-displayed map, you'll need something like:
     * $Hash = $MapsPlugin->ChangePointProvider(...);
     * <script type="text/javascript">
     *   $('#cw-some-button-id').click(change_point_provider(<?= $Hash ?>));
     *  </script>
     * @param array $PointProvider Callback used to provide points.
     * @param array $Params Parameters to pass to the point provider callback.
     * @return string Hash of registered point provider.
     * @see DefaultPointProvider()
     */
    public function registerPointProvider($PointProvider, $Params)
    {
        return $this->CallbackManager
            ->registerCallback($PointProvider, $Params);
    }

    /**
     * Generate a URL for a google map centered on a specific location.
     * @param string $Location Address (in some form Google can
     *   understand) on which to center the map.
     * @return string Requested URL.
     */
    public function googleMapsUrl(string $Location)
    {
        return "https://www.google.com/maps?q=".urlencode($Location);
    }

    /**
     * Generates and prints a static map image. This makes use of the Google
     * Static Maps API.
     *
     * Google's docs are here:
     * http://code.google.com/apis/maps/documentation/staticmaps/
     *
     * @param int $Lat Latitude of the center of the map image.
     * @param int $Long Longitude of the center of the map image.
     * @param int $Width Width of the map image.
     * @param int $Height Height of the map image.
     * @param int $Zoom Zoom level of the maps image.
     */
    public function staticMap($Lat, $Long, $Width, $Height, $Zoom = 14)
    {
        $ApiKey = $this->configSetting("APIKey");

        if (strlen($ApiKey) == 0) {
            $UseSsl = isset($_SERVER["HTTPS"]);
            $Host = $UseSsl ? "https://maps.googleapis.com" : "http://maps.google.com";

            $Url = $Host."/maps/api/staticmap?maptype=roadmap"
                ."&size=".$Width."x".$Height
                ."&zoom=".$Zoom
                ."&markers=".$Lat.",".$Long
                ."&sensor=false";
        } else {
            $Url = "https://maps.googleapis.com/maps/api/staticmap?"
                 ."size=".$Width."x".$Height
                 ."&zoom=".$Zoom
                 ."&markers=".$Lat.",".$Long
                 ."&key=".$ApiKey;
        }

        print('<img src="'.$Url.'" alt="Google Map">');
    }

    /**
     * Given an address, get the latitude and longitude of its coordinates.
     *
     * Details on Google's Geocoding API are here:
     * https://developers.google.com/maps/documentation/geocoding/start
     *
     * NB: Geocoding is rate and quantity limited (see the "Limits"
     * section in Google's docs). As of this writing, they allow only
     * 2500 requests per day. Geocode requests sent from servers
     * (rather than via Firefox or IE) appear to be answered slowly,
     * taking about one minute per reply.  Furthermore, google
     * explicitly states that they will block access to their service
     * which is "abusive".
     *
     * To avoid potentials with the rate/quantity issue, this geocoder
     * caches results for up to a week.  If an address is requested
     * which is not in the cache, NULL will be returned and the
     * geocoding request will take place in the background.
     *
     * @param string $Address Address of a location.
     * @param bool $Foreground TRUE to wait for an address, rather than
     *   spawning a background task to fetch it.
     * @return string|null Address string when available, null otherwise.
     */
    public function geocode($Address, $Foreground = false)
    {
        # if we cannot geocode because no API key was provided, return null
        $ApiKey = $this->configSetting("GeocodingAPIKey");
        if (strlen($ApiKey) == 0) {
            return null;
        }

        $DB = new Database();

        $Key = md5($Address);

        # Then look for the desired address
        $DB->query(
            "SELECT Lat, Lng, AddrData FROM GoogleMaps_Geocodes "
            ."WHERE Id='".$Key."'"
        );

        # If we couldn't find the desired address:
        if ($DB->numRowsSelected() == 0) {
            # If we're running in the foreground, fetch it now,
            #  otherwise, use a background task to fetch it.
            if ($Foreground) {
                $this->geocodeRequest($Address);
                $DB->query("SELECT Lat,Lng,AddrData FROM GoogleMaps_Geocodes "
                           ."WHERE Id='".md5($Address)."'");
                $Row = $DB->fetchRow();
            } else {
                # if we can't find it, set up a Geocoding request and return NULL
                $this->queueUniqueTask(
                    "geocodeRequest",
                    [$Address],
                    ApplicationFramework::PRIORITY_HIGH
                );
                return null;
            }
        } else {
            $Row = $DB->fetchRow();
            $DB->query(
                "UPDATE GoogleMaps_Geocodes "
                ."SET LastUsed=NOW() "
                ."WHERE Id='".$Key."'"
            );
        }

        # if there was no value, return null
        #  otherwise, unpack the serialized array and return the result
        if ($Row["Lat"] == null) {
            return null;
        } else {
            $Row["AddrData"] = unserialize($Row["AddrData"]);
            return $Row;
        }
    }

    /**
     * Perform the request necessary to geocode an address.
     * @param string $Address Address of a location.
     */
    public function geocodeRequest($Address)
    {
        $ApiKey = $this->configSetting("GeocodingAPIKey");
        if (strlen($ApiKey) == 0) {
            throw new Exception(
                "Google's geocoding API requires an API Key."
            );
        }

        # compute database id *before* doing any normalization
        $Id = md5($Address);

        # normalize line endings
        $Address = str_replace("\r\n", "\n", $Address);

        $TargetUrl = "https://maps.google.com/maps/api/geocode/xml?address="
            .urlencode($Address)
            ."&key=".$ApiKey;

        $UserAgent = "GoogleMaps/".$this->Version
            ." Metavus/".METAVUS_VERSION." PHP/".PHP_VERSION;

        $Data = file_get_contents(
            $TargetUrl,
            false,
            stream_context_create([
                'http' => [
                    'method' => "GET",
                    'header' => "User-Agent: ".$UserAgent."\r\n",
                ]
            ])
        );

        $ParsedData = simplexml_load_string($Data);
        if ($ParsedData->status == "OK") {
            # extract lat and lng
            $Lat = floatval($ParsedData->result->geometry->location->lat);
            $Lng = floatval($ParsedData->result->geometry->location->lng);

            # extract more detailed address data
            $AddrData = [];
            foreach ($ParsedData->result->address_component as $Item) {
                foreach ($Item->type as $ItemType) {
                    # skip the 'political' elements, we don't want those
                    if ((string)$ItemType == "political") {
                        continue;
                    }

                    # snag the value
                    $AddrData[(string)$ItemType]["Name"] = (string)$Item->long_name;

                    # if there was a distinct appreviation, get that as well
                    if ((string)$Item->long_name != (string)$Item->short_name) {
                        $AddrData[(string)$ItemType]["ShortName"] =
                            (string)$Item->short_name;
                    }
                }
            }

            $this->cacheGeocodingResult(
                $Id,
                $Lat,
                $Lng,
                $AddrData
            );
        } else {
            $this->cacheGeocodingFailure(
                $Id,
                $Address,
                $Data
            );

            ApplicationFramework::getInstance()->logMessage(
                ApplicationFramework::LOGLVL_INFO,
                "[GoogleMaps] Geocoding Error for "
                .$Address.": ".$Data
                ."TargetUrl was: ".$TargetUrl
            );

            $this->sendGeocodeFailureEmail(
                $Address,
                $Data
            );
        }
    }

    /**
     * Computes the distance in kilometers between two points, assuming a
     * spherical earth.
     * @param int $LatSrc Latitude of the source coordinate.
     * @param int $LonSrc Longitude of the source coordinate.
     * @param int $LatDst Latitude of the destination coordinate.
     * @param int $LonDst Longitude of the destination coordinate.
     * @return float distance in miles between the two points.
     */
    public function computeDistance(
        $LatSrc,
        $LonSrc,
        $LatDst,
        $LonDst
    ) {
        return StdLib::computeGreatCircleDistance(
            $LatSrc,
            $LonSrc,
            $LatDst,
            $LonDst
        );
    }

    /**
     * Computes the initial angle on a course connecting two points, assuming a
     * spherical earth.
     * @param int $LatSrc Latitude of the source coordinate.
     * @param int $LonSrc Longitude of the source coordinate.
     * @param int $LatDst Latitude of the destination coordinate.
     * @param int $LonDst Longitude of the destination coordinate.
     * @return float initial angle on a course connecting two points.
     */
    public function computeBearing(
        $LatSrc,
        $LonSrc,
        $LatDst,
        $LonDst
    ) {
        return StdLib::computeBearing(
            $LatSrc,
            $LonSrc,
            $LatDst,
            $LonDst
        );
    }

    /**
     * Periodic function to clean old data from DB cache tables.
     */
    public function cleanCaches()
    {
        $DB = new Database();

        # clean the cache of entries older than the configured expiration time
        $DB->query(
            "DELETE FROM GoogleMaps_Geocodes WHERE "
            ."TIMESTAMPDIFF(SECOND, LastUsed, NOW()) >"
            .$this->configSetting("ExpTime")
        );

        # clean error info for expired geocodes
        $DB->query(
            "DELETE FROM GoogleMaps_GeocodeErrors WHERE "
            ."Id NOT IN (SELECT Id FROM GoogleMaps_Geocodes)"
        );

        $DB->query(
            "SELECT Id FROM GoogleMaps_Geocodes "
            ."WHERE Lat IS NULL AND "
            ."TIMESTAMPDIFF(SECOND, LastUpdate, NOW()) >"
            .$this->configSetting("GeocodeFailureCacheExpirationTime")
        );
        $FailedIds = $DB->fetchColumn("Id");

        # if we had any ids that failed
        if (count($FailedIds)) {
            $this->clearFailureCacheAndRetryGeocoding($FailedIds);
        }

        # clean out expired callbacks
        $this->CallbackManager->expireCallbacks();

        # delete js error data older than 2 hours
        $DB->query(
            "DELETE FROM GoogleMaps_JavascriptErrors WHERE "
            ."TIMESTAMPDIFF(SECOND, ErrorTime, NOW()) > 7200"
        );

        # clean out old KML files
        $this->cleanCacheDirectory(
            $this->getKmlCachePath(),
            '/^[0-9a-f]+_[0-9a-f]+\.kml$/'
        );

        # clean out old map markers
        $this->cleanCacheDirectory(
            $this->getMarkerCachePath(),
            '/^[A-Za-z0-9_]+\.png$/'
        );
    }

    /**
     * Update the timestamp storing the last change to any resource.
     */
    public function updateResourceTimestamp()
    {
        $this->configSetting("ResourcesLastModified", time());
    }

    /**
     * When a resource is modified, clear the autopopulated fields so
     * that they can be updated.
     * @param Record $Resource Resource to blank.
     */
    public function blankAutoPopulatedFields($Resource)
    {
        # pull out the list of configured fields to autopopulate
        foreach ($this->AutoPopulateData as $Key => $Data) {
            $TgtField = $this->configSetting("FieldMapping-".$Key);

            # if this field isn't mapped, check the next one
            if ($TgtField == null) {
                continue;
            }

            $Resource->clear($TgtField);
        }
    }

    /**
     * Get the file path to the KML file named by the given point and detail
     * provider hashes. This will get the cached KML file if it exists or will
     * generate it first if it does not exist.
     * @param string $PointProviderHash Point provider hash.
     * @param string $DetailProviderHash Detail provider hash.
     * @return string Returns the path to the cached KML file.
     */
    public function getKml($PointProviderHash, $DetailProviderHash)
    {
        $Kml = $this->getKmlFilePath($PointProviderHash, $DetailProviderHash);

        # the file hasn't been generated yet
        if (!file_exists($Kml)) {
            $this->generateKml($PointProviderHash, $DetailProviderHash);
        } else {
            # the file has been already generated so don't generate it now, but
            # update it in the background

            # update LastUsed time on our providers
            $DB = new Database();
            $DB->query(
                "UPDATE GoogleMaps_Callbacks SET LastUsed=NOW() WHERE "
                ."Id IN ('".addslashes($PointProviderHash)."',"
                ."'".addslashes($DetailProviderHash)."')"
            );

            $MTime = filemtime($Kml);
            if ($MTime < $this->configSetting("ResourcesLastModified") ||
                $MTime < $this->configSetting("GeocodeLastSuccessful")) {
                $this->queueUniqueTask(
                    "generateKml",
                    [$PointProviderHash, $DetailProviderHash],
                    ApplicationFramework::PRIORITY_BACKGROUND,
                    "Update a KML cache file."
                );
            }
        }

        return $Kml;
    }

    /**
     * Generate the cached KML file named by the given point and detail provider
     * hashes.
     * @param string $PointProviderHash Point provider hash.
     * @param string $DetailProviderHash Detail provider hash.
     * @see GetKmlFilePath()
     */
    public function generateKml($PointProviderHash, $DetailProviderHash)
    {
        $AF = ApplicationFramework::getInstance();

        # make sure the cache directories exist and are usable before attempting
        # to generate the KML
        if (strlen($this->checkCacheDirectory())) {
            $AF->LogMessage(
                ApplicationFramework::LOGLVL_ERROR,
                "[GoogleMaps] KML Cache directory is not writable."
            );
            return;
        }

        $DB = new Database();
        $Path = $this->getKmlFilePath($PointProviderHash, $DetailProviderHash);

        # attempt to load our callbacks from the database
        $CallbackTypes = [
            "D" => "Detail",
            "P" => "Point",
        ];
        $CallbackErrors = false;
        foreach ($CallbackTypes as $Abbr => $Type) {
            $Row = $DB->query(
                "SELECT Payload,Params FROM GoogleMaps_Callbacks "
                ."WHERE Id='".addslashes(${$Type."ProviderHash"})."'"
            );
            if ($DB->numRowsSelected() == 0) {
                $AF->LogMessage(
                    ApplicationFramework::LOGLVL_ERROR,
                    "[GoogleMaps] Callback for ".$Type." Provider Hash "
                    .${$Type."ProviderHash"}." not found. Either the hash "
                    ."is actually invalid or aggressive caching is preventing "
                    ."GoogleMaps from seeing that this callback is "
                    ."still in use and it was prematurely expired. "
                    ."In the latter case, increasing the Callback Expiration Time "
                    ."in the GoogleMaps plugin settings may resolve this."
                );
                $CallbackErrors = true;
                continue;
            }

            $Row = $DB->fetchRow();
            ${$Abbr."PCallback"} = unserialize($Row["Payload"]);
            ${$Abbr."PParams"} = unserialize($Row["Params"]);
            if (!is_callable(${$Abbr."PCallback"})) {
                $AF->LogMessage(
                    ApplicationFramework::LOGLVL_ERROR,
                    "[GoogleMaps] Registered ".$Type." Provider could not be "
                    ."loaded. This probably indicates an error in user interface code. "
                    ."Callback was ".var_export(${$Abbr."PCallback"}, true)
                );
                $CallbackErrors = true;
            }
        }

        if ($CallbackErrors) {
            return;
        }

        # update LastUsed time on our providers
        $DB->query(
            "UPDATE GoogleMaps_Callbacks SET LastUsed=NOW() WHERE "
            ."Id IN ('".addslashes($PointProviderHash)."',"
            ."'".addslashes($DetailProviderHash)."')"
        );

        # call the supplied detail provider, expecting an Array
        $Points = call_user_func_array($PPCallback, [$PPParams]);

        # add log message if no points where found
        if (count($Points) < 1) {
            $Message = "[GoogleMaps] No points found for parameters: ";
            $Message .= var_export(
                ["Parameters" => $PPParams],
                true
            );

            $AF->LogMessage(ApplicationFramework::LOGLVL_INFO, $Message);
        }

        # initialize the KML file
        $Kml = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
        $Kml .= '<kml xmlns="http://www.opengis.net/kml/2.2">'."\n";
            $Kml .= "<Document>\n";

        # Enumerate the different marker types that we're using:
        $MarkerTypes = [];
        foreach ($Points as $Point) {
            $BgColor = str_replace('#', '', $Point[3]);
            $Label   = defaulthtmlentities($Point[4]);
            $FgColor = str_replace('#', '', $Point[5]);

            $MarkerTypes[$Label.$BgColor.$FgColor] =
                ApplicationFramework::getInstance()->baseUrl()
                ."index.php?P=P_GoogleMaps_GetMarker"
                ."&T=".$Label
                ."&BG=".$BgColor
                ."&FG=".$FgColor ;
        }

        # Style elements to define markers:
        foreach ($MarkerTypes as $Key => $Value) {
            $Kml .= '<Style id="_'.defaulthtmlentities($Key).'">';
            $Kml .= '<IconStyle>';
            $Kml .= '<Icon><href>'.defaulthtmlentities($Value).'</href></Icon>';

            # an offset of x=9 and y=2 puts the hotspot inside our marker graphic
            $Kml .= '<hotSpot x="9" y="2" xunits="pixels" yunits="pixels" />';

            $Kml .= '</IconStyle>';
            $Kml .= '</Style>';
        }

        # Keep track of how many markers are placed at each point:
        $MarkersAtPoint = [];

        # Point elements:
        foreach ($Points as $Point) {
            $Lat = $Point[0];
            $Lon = $Point[1];
            $Id  = $Point[2];
            $BgColor = $Point[3];
            $Label   = $Point[4];
            $FgColor = $Point[5];

            $ix =  "X-".$Lat.$Lon."-X";
            if (!isset($MarkersAtPoint[$ix])) {
                $MarkersAtPoint[$ix] = 1;
            } else {
                $Lat += 0.005 * $MarkersAtPoint[$ix];
                $Lon += 0.005 * $MarkersAtPoint[$ix];

                $MarkersAtPoint[$ix]++;
            }

            $Kml .= '<Placemark id="_'.defaulthtmlentities($Id).'">';
            $Kml .= '<name></name>';
            $Kml .= '<description><![CDATA[';

            # add the description text/HTML
            ob_start();
            call_user_func_array(
                $DPCallback,
                [$Id, $DPParams]
            );
            $Output = ob_get_contents();
            ob_end_clean();

            $Kml .= $this->cleanMarkupForXml($Output);

            $Kml .= ']]></description>';

            $Kml .= '<styleUrl>#_'.$Label.$BgColor.$FgColor.'</styleUrl>';
            $Kml .= '<Point><coordinates>'.$Lat.','.$Lon.'</coordinates></Point>';
            $Kml .= '</Placemark>';
        }

        # complete the KML document
        $Kml .= "</Document></kml>";
        file_put_contents($Path, $Kml);
    }

    /**
     * Provides a default point provider that retrieves all points from the
     * default point field.
     * @param array $Params Parameters to the point provider. The default point
     *      provider doesn't use them.
     * @return array Returns an array of points and associated data.
     */
    public static function defaultPointProvider($Params)
    {
        global $DB;

        $rc = [];

        $MyPlugin = PluginManager::getInstance()->getPlugin("GoogleMaps");

        $MetadataField = $MyPlugin->configSetting("DefaultPointField");

        if ($MetadataField != "") {
            $Schema = new MetadataSchema();
            $Field = $Schema->getField($MetadataField);
            $DBFieldName = $Field->dBFieldName();

            if ($Field->type() == MetadataSchema::MDFTYPE_POINT) {
                # data is lat/long encoded in a point field
                $DB->query(
                    "SELECT RecordId, "
                    ."`".$DBFieldName."X` AS Y,"
                    ."`".$DBFieldName."Y` AS X FROM Records "
                    ."WHERE "
                    ."`".$DBFieldName."Y` IS NOT NULL AND "
                    ."`".$DBFieldName."X` IS NOT NULL"
                );
                $Points = $DB->FetchRows();

                foreach ($Points as $Point) {
                    $rc[] = [
                        $Point["X"],
                        $Point["Y"],
                        $Point["RecordId"],
                        "8F00FF", "", "000000"
                    ];
                }
            } else {
                # data is an address which needs to be geocoded
                $DB->query(
                    "SELECT RecordId, `".$DBFieldName."` AS Addr FROM Records "
                    ."WHERE `".$DBFieldName."` IS NOT NULL"
                );
                $Addresses = $DB->FetchRows();

                foreach ($Addresses as $Address) {
                    $GeoData = $MyPlugin->geocode($Address["Addr"]);
                    if ($GeoData !== null) {
                        $rc[] = [
                            $GeoData["Lng"],
                            $GeoData["Lat"],
                            $Address["RecordId"],
                            "8F00FF","","000000"
                        ];
                    }
                }
            }
        } else {
            $SqlQuery =  $MyPlugin->configSetting("DefaultSqlQuery");

            if ($SqlQuery != null && !self::containsDangerousSQL($SqlQuery)) {
                $DB->query($SqlQuery);
                while ($Row = $DB->FetchRow()) {
                    $rc[] = [
                        $Row["Longitude"],
                        $Row["Latitude"],
                        $Row["RecordId"],
                        isset($Row["MarkerColor"]) ? $Row["MarkerColor"] : "8F00FF" ,
                        isset($Row["MarkerLabel"]) ? $Row["MarkerLabel"] : "",
                        isset($Row["LabelColor"]) ? $Row["LabelColor"]  : "000000"
                    ];
                }
            }
        }

        return $rc;
    }

    /**
     * Provides a default detail provider containing the title, description, and
     * a link to the full record of a resource.
     * @param int $ResourceId ID of the resource for which to provide details.
     */
    public static function defaultDetailProvider($ResourceId)
    {
        $AF = ApplicationFramework::getInstance();

        if (!Record::itemExists($ResourceId)) {
            print("ERROR: Invalid ResourceId\n");
        } else {
            $Resource = new Record($ResourceId);
            print(
                '<h1>'.$Resource->getMapped("Title").'</h1>'
                .'<p>'.$Resource->getMapped("Description").'</p>'
                .'<p><a href="'.$AF->baseUrl()
                .'index.php?P=FullRecord&amp;ID='.$Resource->id().'">'
                .'(more information)</a></p>'
                );
        }
    }

    /**
     * Claim our mailer template so that it won't be deleted.
     * @param int $TemplateId Template being checked.
     * @param array $TemplateUsers Users of the given template.
     * @return array parameters for next event in the chain.
     */
    public function claimTemplate(int $TemplateId, array $TemplateUsers)
    {
        if ($this->configSetting("GeocodeErrorEmailTemplate") == $TemplateId) {
            $TemplateUsers[] = $this->Name;
        }

        return ["TemplateId" => $TemplateId, "TemplateUsers" => $TemplateUsers];
    }

    /**
     * Log an error message sent by client-side javascript.
     * @param string $Message Error message.
     */
    public function logJavascriptError(string $Message)
    {
        $DB = new Database();

        # our javascript will retry 5 times every 1500 seconds, in the worst case
        # getting 5 errors over 7.5 seconds. if we assume that the user will take
        # at least 2.5 seconds to hit the 'reload' button, then we shouldn't get
        # more than 5 errors from a given ip in a ten second window
        $ErrorCount = $DB->queryValue(
            "SELECT Count(*) AS N FROM GoogleMaps_JavascriptErrors "
            ."WHERE IPAddress=INET_ATON('".addslashes($_SERVER["REMOTE_ADDR"])."')"
            ." AND TIMESTAMPDIFF(SECOND, ErrorTime, NOW()) < 10",
            "N"
        );
        if ($ErrorCount > 5) {
            return;
        }

        # if we've already recorded this exact message from a given ip
        # in the last hour, don't record it again
        $ErrHash = hash("sha256", $Message);
        $RepeatCount = $DB->queryValue(
            "SELECT Count(*) AS N FROM GoogleMaps_JavascriptErrors "
            ."WHERE IPAddress=INET_ATON('".addslashes($_SERVER["REMOTE_ADDR"])."')"
            ." AND ErrorData='".addslashes($ErrHash)."'"
            ." AND TIMESTAMPDIFF(SECOND, ErrorTime, NOW()) < 3600",
            "N"
        );
        if ($RepeatCount > 0) {
            return;
        }

        $DB->query(
            "INSERT INTO GoogleMaps_JavascriptErrors "
            ."(IPAddress, ErrorTime, ErrorData) VALUES ("
            ." INET_ATON('".addslashes($_SERVER["REMOTE_ADDR"])."'),"
            ." NOW(),"
            ." '".addslashes($ErrHash)."'"
            .")"
        );

        ApplicationFramework::getInstance()->logMessage(
            ApplicationFramework::LOGLVL_ERROR,
            "[GoogleMaps] JS error from "
            .$_SERVER["REMOTE_ADDR"]
            ." ".$Message
        );
    }

    /**
    * Generate the path for a specified map marker.
    * @param string $Label Marker label.
    * @param string $BgColor Hex background color as six lowercase hex digits
    *   with no leading hashmark.
    * @param string $FgColor Hex foreground color (same format as $BgColor).
    * @throws InvalidArgumentException on invalid colors.
    * @return string Path to the generated marker.
    */
    public function getMarkerFilePath(
        string $Label,
        string $BgColor,
        string $FgColor
    ) {
        if (!preg_match('/^[0-9a-f]{6}$/', $BgColor)) {
            throw new InvalidArgumentException("Invalid BgColor: ".$BgColor);
        }

        if (!preg_match('/^[0-9a-f]{6}$/', $FgColor)) {
            throw new InvalidArgumentException("Invalid FgColor: ".$FgColor);
        }

        # limit label to at most 1 alphanumeric character
        $Label = preg_replace("%[^A-Za-z0-9]%", "", $Label);
        if (strlen($Label) > 1) {
            $Label = substr($Label, 0, 1);
        }

        return $this->getMarkerCachePath()."/"
            .implode("_", [$Label, $BgColor, $FgColor])
            .".png";
    }

    /**
     * Check that a GoogleMaps Geocode Error template exists, creating one if
     * it does not.
     * @return int TemplateId of the GoogleMaps Geocode Error template.
     */
    protected function getMailerTemplateId()
    {
        $Mailer = PluginManager::getInstance()->getPlugin("Mailer");
        $MailerTemplates = $Mailer->GetTemplateList();

        # set up a template if one doesn't yet exist
        if (!in_array(self::MAILER_TEMPLATE_NAME, $MailerTemplates)) {
            $Mailer->AddTemplate(
                self::MAILER_TEMPLATE_NAME,
                "X-PORTALNAME-X <X-ADMINEMAIL-X>",
                "GoogleMaps: Geocoding Error",
                "<p>Could not geocode X-GOOGLEMAPS:ADDRESS-X</p>"
                ."<p>Error message:</p>"
                ."<pre>X-GOOGLEMAPS:ERROR-X</pre>",
                "",
                "Could not geocode X-GOOGLEMAPS:ADDRESS-X\n"
                ."Error message:\n"
                ."X-GOOGLEMAPS:ERROR-X",
                "",
                false
            );

            # need to get the template again if we just added the
            # template so we have up-to-date config vaules
            $MailerTemplates = $Mailer->GetTemplateList();
        }

        return array_search(
            self::MAILER_TEMPLATE_NAME,
            $MailerTemplates
        );
    }

    /**
    * Generate the file path string for the cached KML file given the point and
    * detail provider hashes.
    * @param string $PointProviderHash Point provider hash.
    * @param string $DetailProviderHash Detail provider hash
    * @return string Path to the cached KML file.
    */
    protected function getKmlFilePath($PointProviderHash, $DetailProviderHash)
    {
        $CachePath = $this->getKmlCachePath();
        $FileName = $PointProviderHash . "_" .$DetailProviderHash . ".xml";
        $FilePath = $CachePath . "/" . $FileName;

        return $FilePath;
    }

    /**
     * Get the path of the KML cache directory.
     * @return string Path of KML Cache directory, with no trailing slash.
     */
    protected function getKmlCachePath()
    {
        return $this->getCachePath() . "/kml";
    }

    /**
     * Get the path of the map marker cache directory.
     * @return string Path of Marker Cache directory, with no trailing slash.
     */
    protected function getMarkerCachePath()
    {
        return $this->getCachePath() . "/markers";
    }

    /**
     * Get the path of the cache directory.
     * @return string Returns the path of the cache directory.
     */
    protected function getCachePath()
    {
        return getcwd() . "/local/data/caches/GoogleMaps";
    }

    /**
     * Make sure the cache directories exist and are usable, creating them if
     * necessary.
     * @return null|string Error message or NULL if no issues detected.
     */
    protected function checkCacheDirectory()
    {
        # string path => bool recursive
        $Paths = [
            $this->getCachePath() => true,
            $this->getKmlCachePath() => false,
            $this->getMarkerCachePath() => false,
        ];

        foreach ($Paths as $Path => $Recursive) {
            # the cache directory doesn't exist, try to create it
            if (!file_exists($Path)) {
                $Result = @mkdir($Path, 0777, $Recursive);

                if ($Result === false) {
                    return "Cache directory ".$Path." could not be created.";
                }
            }

            # exists, but is not a directory
            if (!is_dir($Path)) {
                return "(".$Path.") is not a directory.";
            }

            # exists and is a directory, but is not writeable
            if (!is_writeable($Path)) {
                return "Cache directory ".$Path." is not writeable.";
            }
        }

        return null;
    }

    /**
     * Update database with location information from a successful geocode request.
     * @param string $Id Address identifier.
     * @param float $Lat Latitude.
     * @param float $Lng Longitude.
     * @param array $AddrData Decoded XML response from Google.
     */
    private function cacheGeocodingResult(
        string $Id,
        float $Lat,
        float $Lng,
        array $AddrData
    ) {
        $DB = new Database();

        $DB->query(
            "LOCK TABLES GoogleMaps_Geocodes WRITE, GoogleMaps_GeocodeErrors WRITE"
        );
        $DB->query(
            "INSERT INTO GoogleMaps_Geocodes "
            ."(Id,Lat,Lng,AddrData,LastUpdate,LastUsed) VALUES ("
            ."'".$Id."',"
            .$Lat.","
            .$Lng.","
            ."'".$DB->escapeString(serialize($AddrData))."',"
            ."NOW(),"
            ."NOW()"
            .")"
        );
        $DB->query(
            "DELETE FROM GoogleMaps_GeocodeErrors "
            ."WHERE Id='".$Id."'"
        );
        $DB->query(
            "UNLOCK TABLES"
        );

        $this->configSetting("GeocodeLastSuccessful", time());
    }

    /**
     * Update database to indicate that an address could not be geocoded.
     * @param string $Id Address identifier.
     * @param string $Address Address.
     * @param string $Data Full XML response from Google.
     */
    private function cacheGeocodingFailure(
        $Id,
        $Address,
        $Data
    ) {
        $DB = new Database();

        $DB->query(
            "LOCK TABLES GoogleMaps_Geocodes, GoogleMaps_GeocodeErrors WRITE"
        );
        $DB->query(
            "INSERT INTO GoogleMaps_Geocodes "
            ."(Id,Lat,Lng,AddrData,LastUpdate,LastUsed) VALUES "
            ."('".$Id."',NULL,NULL,NULL,NOW(),NOW())"
        );
        $DB->query(
            "DELETE FROM GoogleMaps_GeocodeErrors "
            ."WHERE Id='".$Id."'"
        );
        $DB->query(
            "INSERT INTO GoogleMaps_GeocodeErrors "
            ."(Id, Address, ErrorData) VALUES "
            ."('".$Id."',"
            ."'".$DB->escapeString($Address)."',"
            ."'".$DB->escapeString($Data)."')"
        );
        $DB->query(
            "UNLOCK TABLES"
        );
    }

    /**
     * Clear geocode failure cache and retry geocoding for failing addresses.
     * @param array $FailedIds IDs corresponding to failing addresses.
     */
    private function clearFailureCacheAndRetryGeocoding($FailedIds)
    {
        $DB = new Database();

        # break them up into chunks of less than a thousand
        foreach (array_chunk($FailedIds, 1000) as $IdChunk) {
            # construct the ... part of a WHERE Id IN (...) clause
            $InText = implode(
                ",",
                array_map(
                    function ($x) {
                        return "'".addslashes($x)."'";
                    },
                    $IdChunk
                )
            );

            # get the corresponding addresses that failed
            $DB->query(
                "SELECT Address FROM GoogleMaps_GeocodeErrors "
                ."WHERE Id IN (".$InText.")"
            );
            $Addresses = $DB->fetchColumn("Address");

            # delete cached failures
            $DB->query(
                "DELETE FROM GoogleMaps_Geocodes "
                ."WHERE Id IN (".$InText.")"
            );

            # queue updates for all the failed addresses
            foreach ($Addresses as $Address) {
                $this->geocode($Address);
            }
        }
    }

    /**
     * Send email notifications about geocoding failures.
     * @param string $Address Address that could not be geocoded.
     * @param string $Data XML error string from Google.
     */
    private function sendGeocodeFailureEmail(
        string $Address,
        string $Data
    ) {
        $ErrorsToSkip = [
            "OVER_QUERY_LIMIT",
            "REQUEST_DENIED",
        ];

        $ParsedError = simplexml_load_string($Data);
        if (($ParsedError === false)
                || in_array((string)$ParsedError->status, $ErrorsToSkip)) {
            return;
        }

        $Mailer = PluginManager::getInstance()->getPlugin("Mailer");

        $Recipients = array_keys(
            (new UserFactory())->getUsersWithPrivileges(
                $this->configSetting("GeocodeErrorEmailRecipients")
                ->GetPrivilegeList()
            )
        );

        $Tokens = [
            "GOOGLEMAPS:ADDRESS" => $Address,
            "GOOGLEMAPS:ERROR" => $Data,
        ];

        $Mailer->SendEmail(
            $this->configSetting("GeocodeErrorEmailTemplate"),
            $Recipients,
            null,
            $Tokens
        );
    }

    /**
     * Perform necessary HTML cleanup before it can be embedded in an XML
     * document. Currently this searches for un-escaped ampersands and
     * replaces them with &amp;
     * @param string $Data Markup to clean.
     * @return string Cleaned data
     */
    private function cleanMarkupForXml($Data)
    {
        $Output = preg_replace_callback(
            '/&[A-Za-z]{0,15}[; ]/',
            function ($Matches) {
                # get the list of html entities that PHP knows about
                static $Entities = null;
                if ($Entities === null) {
                    $Entities = array_values(
                        get_html_translation_table(HTML_ENTITIES)
                    );
                }

                # if this match is one of them, return it unchanged
                if (in_array($Matches[0], $Entities)) {
                    return $Matches[0];
                }

                # otherwise, perform html encoding
                return htmlentities($Matches[0]);
            },
            $Data
        );

        return $Output;
    }

    /**
     * Clean matching files that are older than the callback expiration time
     * (from the CallbackExpTime ConfigSetting) from the specified cache
     * directory.
     * @param string $Path Directory to clean.
     * @param string $FilePattern Regex matching files that should be removed.
     */
    private function cleanCacheDirectory($Path, $FilePattern)
    {
        if (is_dir($Path)) {
            # determine when files should expire
            $ExpiredTime = strtotime(
                "-". $this->configSetting("CallbackExpTime")." days"
            );

            $DI = new DirectoryIterator($Path);
            while ($DI->valid()) {
                if ($DI->isFile() && $DI->getCTime() < $ExpiredTime &&
                    preg_match($FilePattern, $DI->getFilename())) {
                    unlink($DI->getPathname());
                }
                $DI->next();
            }
            unset($DI);
        }
    }

    /**
     * Checks an SQL statement for potentially destructive keywords.
     * @param string $SqlStatement An SQL statement
     * @return bool FALSE for safe-looking statements and TRUE otherwise
     */
    private static function containsDangerousSQL($SqlStatement)
    {
        $EvilKeywords = [
            "ALTER","RENAME","TRUNCATE","CREATE","START","COMMIT",
            "ROLLBACK", "SET", "BACKUP", "OPTIMIZE", "REPAIR", "RESTORE",
            "SET", "GRANT", "USE", "UPDATE", "INSERT", "DELETE", "DROP"
        ];

        return preg_match(
            "/(^|\() *(".implode("|", $EvilKeywords).") /i",
            $SqlStatement
        ) === 1;
    }


    private $CallbackManager;

    # array defining which of Google's elements we autopopulate
    # data from
    private $AutoPopulateData = [
        "City" => [
            "Display" => "City",
            "FieldType" => MetadataSchema::MDFTYPE_TEXT,
            "GoogleElement" => "locality"
        ],
        "State" => [
            "Display" => "State/Province",
            "FieldType" => MetadataSchema::MDFTYPE_TEXT,
            "GoogleElement" => "administrative_area_level_1"
        ],
        "Country" => [
            "Display" => "Country",
            "FieldType" => MetadataSchema::MDFTYPE_TEXT,
            "GoogleElement" => "country"
        ],
        "PostCode" => [
            "Display" => "Postal Code",
            "FieldType" => MetadataSchema::MDFTYPE_TEXT,
            "GoogleElement" => "postal_code"
        ],
        "LatLng" => [
            "Display" => "Latitude/Longitude",
            "FieldType" => MetadataSchema::MDFTYPE_POINT,
            "GoogleElement" => null
        ]
    ];

    private $SqlTables = [
        "Geocodes" =>
            "CREATE TABLE GoogleMaps_Geocodes (
            Id VARCHAR(32),
            Lat DOUBLE,
            Lng DOUBLE,
            LastUpdate TIMESTAMP,
            LastUsed TIMESTAMP,
            AddrData MEDIUMBLOB,
            UNIQUE UIndex_I (Id))",
        "GeocodeErrors" =>
            "CREATE TABLE GoogleMaps_GeocodeErrors (
            Id VARCHAR(32),
            Address MEDIUMBLOB,
            ErrorData MEDIUMBLOB,
            UNIQUE UIndex_I (Id))",
        "JavascriptErrors" =>
            "CREATE TABLE GoogleMaps_JavascriptErrors (
            IPAddress INT UNSIGNED,
            ErrorTime TIMESTAMP,
            ErrorData TINYBLOB )",
    ];
}
