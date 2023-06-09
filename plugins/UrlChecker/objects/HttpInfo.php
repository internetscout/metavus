<?PHP
#
#   FILE: HttpInfo.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2011-2020 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins\UrlChecker;

class HttpInfo
{
    public $Url = "";
    public $StatusCode = -1;
    public $ReasonPhrase = "";
    public $FinalUrl = "";
    public $FinalStatusCode = -1;
    public $FinalReasonPhrase = "";
    public $UsesCookies = false;
}
