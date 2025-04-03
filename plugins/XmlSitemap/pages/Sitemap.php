<?PHP
#
#   FILE:  Sitemap.php (XmlSitemap plugin)
#
#   Part of the Metavus digital collections platform
#   Copyright 2015-2020 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#

use Metavus\Plugins\XmlSitemap;
use ScoutLib\ApplicationFramework;

ApplicationFramework::getInstance()->SuppressHTMLOutput();

# output the XML sitemap
$MyPlugin = XmlSitemap::getInstance();
print $MyPlugin->GetSitemap();
