<?PHP
#
#   FILE:  OAI.php (OAI-PMH Server plugin)
#
#   Part of the Metavus digital collections platform
#   Copyright 2024 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

use Metavus\File;
use Metavus\Plugins\OAIPMHServer;
use Metavus\Plugins\OAIPMHServer\OAIServer;
use ScoutLib\ApplicationFramework;

header("Content-type: text/xml");

$AF = ApplicationFramework::getInstance();
$OAIPMHServerPlugin = OAIPMHServer::getInstance();
$Server = new OAIServer(
    $OAIPMHServerPlugin->getConfigSetting("RepositoryDescr"),
    $OAIPMHServerPlugin->getConfigSetting("Formats"),
    null,
    $OAIPMHServerPlugin->getConfigSetting("SQEnabled")
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
    if ($Pieces !== false && count($Pieces) == 5 && strlen($Pieces[2]) > 0) {
        $SelectedFormat = $Pieces[2];
    }
}

$Formats = $OAIPMHServerPlugin->getConfigSetting("Formats");

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
