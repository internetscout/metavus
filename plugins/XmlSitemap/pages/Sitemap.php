<?PHP
#
#   FILE:  Sitemap.php (XmlSitemap plugin)
#
#   Part of the Metavus digital collections platform
#   Copyright 2015-2020 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#

$GLOBALS["AF"]->SuppressHTMLOutput();

# output the XML sitemap
$MyPlugin = $GLOBALS["G_PluginManager"]->GetPluginForCurrentPage();
print $MyPlugin->GetSitemap();
