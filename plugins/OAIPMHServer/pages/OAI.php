<?PHP
#
#   FILE:  OAI.php (OAI-PMH Server plugin)
#
#   Part of the Metavus digital collections platform
#   Copyright 2023 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#

use Metavus\File;
use Metavus\Plugins\OAIPMHServer\OAIServer;
use ScoutLib\PluginManager;

header("Content-type: text/xml");

$Plugin = PluginManager::getInstance()
    ->getPlugin("OAIPMHServer");
$Server = new OAIServer(
    $Plugin->ConfigSetting("RepositoryDescr"),
    $Plugin->ConfigSetting("Formats"),
    null,
    $Plugin->ConfigSetting("SQEnabled")
);

# find query data (see OAIServer::LoadArguments)
$QueryData = isset($_POST["verb"]) ? $_POST : $_GET;

# if P= was set, strip it out
if (isset($QueryData["P"])) {
    unset($QueryData["P"]);
}

# log the OAI request
$AF->SignalEvent(
    "EVENT_OAIPMH_REQUEST",
    [
        "RequesterIP" => $_SERVER["REMOTE_ADDR"],
        "QueryString" => http_build_query($QueryData)
    ]
);

$ServerResponse = $Server->GetResponse();

if (isset($_GET["metadataPrefix"]) || isset($_POST["metadataPrefix"])) {
    $SelectedFormat = isset($_GET["metadataPrefix"]) ?
        $_GET["metadataPrefix"] :
        $_POST["metadataPrefix"] ;
} elseif (isset($_GET["resumptionToken"]) || isset($_POST["resumptionToken"])) {
    $ResumptionToken = isset($_GET["resumptionToken"]) ?
        $_GET["resumptionToken"] :
        $_POST["resumptionToken"] ;
    $Pieces = preg_split("/-_-/", $ResumptionToken);
    if (count($Pieces) == 5 && strlen($Pieces[2]) > 0) {
        $SelectedFormat = $Pieces[2];
    }
}

$Formats = $Plugin->ConfigSetting("Formats");

if (isset($SelectedFormat)
        && isset($Formats[$SelectedFormat])
        && isset($Formats[$SelectedFormat]["XsltFileId"])) {
    $xml = new DOMDocument();
    $xml->loadXML($ServerResponse);

    $XslFile = new File(intval($Formats[$SelectedFormat]["XsltFileId"]));

    $xsl = new DOMDocument();
    $xsl->load($XslFile->GetNameOfStoredFile());

    $proc = new XSLTProcessor();
    $proc->importStyleSheet($xsl);

    print ($proc->transformToXML($xml) );
} else {
    print ($ServerResponse);
}

# suppress any HTML output
$AF->SuppressHTMLOutput();
