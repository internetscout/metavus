<?PHP
#
#   FILE:  Home-Compat.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2022 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#

# In some older CWIS versions, files in pages/ defined a number of UI helper
# functions for code in the .html files to use. These are no longer present in
# Metavus. This file contains implementations of those functions that can be
# used by custom .html files that were written based on these old versions to
# allow them to function under Metavus.
#
# To do this, add following at the top of the custom .html files:
#
#   use ScoutLib\ApplicationFramework;
#   $AF = ApplicationFramework::getInstance();
#   require_once($AF->gUIFile("Home-Compat.php"));

namespace Metavus;

use ScoutLib\PluginManager;

/**
 * Determine if announcements can be displayed.
 */
function AnnouncementsEnabled(): bool
{
    $PluginMgr = PluginManager::getInstance();

    if (!$PluginMgr->pluginReady("Blog")) {
        return false;
    }

    $BlogPlugin = $PluginMgr->getPlugin("Blog");
    if ($BlogPlugin->getBlogIdByName("News") === false) {
        return false;
    }

    return true;
}

/**
 * Print rows of new resource.
 */
function PrintNewResourceTableRows()
{
    global $MoreResources;
    global $ResourceOffset;

    $IntConfig = InterfaceConfiguration::getInstance();

    $MaxDescriptionLength = 250;
    $MaxUrlLength = 60;

    if (is_null($ResourceOffset)) {
        $ResourceOffset = 0;
    }

    # default values
    $MaxNumberOfResourcesToDisplay = $IntConfig->getInt("NumResourcesOnHomePage");
    $MaxNumberOfDaysToGoBackForResources = 7300;

    # retrieve resources using factory
    # NOTE:  Retrieves one more resource than is needed because we need
    #       to know if there are more resources.)
    $ResourceFact = new RecordFactory();
    $Resources = $ResourceFact->GetRecentlyReleasedRecords(
        ($MaxNumberOfResourcesToDisplay + 1),
        $ResourceOffset,
        $MaxNumberOfDaysToGoBackForResources
    );

    # determine whether "Previous" link should be displayed
    $ResourceCount = count($Resources);
    $MoreResources = ($ResourceCount <= $MaxNumberOfResourcesToDisplay) ? false : true;

    # if resources found
    if ($ResourceCount) {
        # drop last resource if we have one more than is needed
        if ($ResourceCount > $MaxNumberOfResourcesToDisplay) {
            array_pop($Resources);
        }

        $User = User::getCurrentUser();

        # for each resource
        foreach ($Resources as $Resource) {
            # display resource
            PrintNewResourceRow(
                $Resource,
                $Resource->GetViewPageUrl(),
                $Resource->UserCanEdit($User),
                $Resource->GetEditPageUrl(),
                $Resource->ScaledCumulativeRating()
            );
        }
    } else {
        # print row with "no resources" notice
        print "<p><i>No new resources</i></p>";
    }
}

/**
 * Print the row for a record in the resource summary table.
 */
function PrintNewResourceRow(
    $Resource,
    $FullRecordLink,
    $EditOkay,
    $EditLink,
    $CumulativeRating
): void {
    $Summary = ResourceSummary::create($Resource->id());
    $Summary->editable($EditOkay);
    $Summary->display();
}

/**
 * Check if next reources are available.
 * @return bool false (pagination on Home is no longer supported)
 */
function NextResourcesAvailable()
{
    return false;
}

/**
 * Check if previouss are available.
 * @return bool false (pagination on Home is no longer supported)
 */
function PreviousResourcesAvailable()
{
    return false;
}

/**
 * Print link for the previous resource.
 */
function PrintPrevResourceLink()
{
    return "";
}

/**
 * Print the link for the next resource.
 */
function PrintNextResourceLink()
{
    return "";
}
