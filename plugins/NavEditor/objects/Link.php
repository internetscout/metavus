<?PHP
#
#   FILE:  Link.php (NavEditor plugin)
#
#   Part of the Metavus digital collections platform
#   Copyright 2016-2020 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins\NavEditor;

class Link
{
    public $Label;
    public $Page;
    public $DisplayOnlyIfLoggedIn;
    public $RequiredPrivileges;
}
