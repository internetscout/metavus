<?PHP
#
#   FILE:  PluginUpgrade_1_3_1.php (Mailer plugin)
#
#   A plugin upgrade file for the Metavus digital collections platform
#   Copyright 2024 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins\Mailer;

use Metavus\Plugins\Mailer;
use Metavus\Plugins\Rules\Rule;
use Metavus\Plugins\Rules\RuleFactory;
use ScoutLib\Database;
use ScoutLib\PluginManager;
use ScoutLib\PluginUpgrade;

/**
 * Class for upgrading the Mailer plugin to version 1.3.1.
 */
class PluginUpgrade_1_3_1 extends PluginUpgrade
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Perform actions necessary to upgrade plugin to version 1.3.1.
     * @return null|string Return NULL if upgrade succeeeded, or string
     *      containing error message if upgrade failed.
     */
    public function performUpgrade()
    {
        $Plugin = Mailer::getInstance(true);

        $Templates = $Plugin->getConfigSetting("Templates");
        $DB = new Database();

        # if Rules was enabled and is new enough that it had the
        # Rules_Rules table
        if (PluginManager::getInstance()->pluginEnabled("Rules") &&
            $DB->tableExists("Rules_Rules")) {
            # pull in objects we need from Rules
            # (these won't be autoloaded because Rules isn't initialized yet)
            require_once("plugins/Rules/objects/Rule.php");
            require_once("plugins/Rules/objects/RuleFactory.php");

            # build a mapping of templates to the rules that use them
            $TemplateRuleMap = [];
            $RFactory = new RuleFactory();
            foreach ($RFactory->getItems() as $RuleId => $Rule) {
                if ($Rule->action() == Rule::ACTION_SENDEMAIL) {
                    $ActionParams = $Rule->actionParameters();
                    $TemplateRuleMap[$ActionParams["Template"]][] = $RuleId;
                }
            }

            # iterate over all the templates, moving the
            # ConfirmMode from the template into the corresponding
            # rule if necessary
            foreach ($Templates as $TId => $Template) {
                if (array_key_exists($TId, $TemplateRuleMap) &&
                    array_key_exists("ConfirmMode", $Template) &&
                    $Template["ConfirmMode"]) {
                    foreach ($TemplateRuleMap[$TId] as $RuleId) {
                        $Rule = new Rule($RuleId);
                        $ActionParams = $Rule->actionParameters();
                        $ActionParams["ConfirmBeforeSending"] = true;
                        $Rule->actionParameters($ActionParams);
                    }
                }
            }
        }

        # remove ConfirmMode setting from templates
        foreach ($Templates as &$Template) {
            if (array_key_exists("ConfirmMode", $Template)) {
                unset($Template["ConfirmMode"]);
            }
        }
        $Plugin->setConfigSetting("Templates", $Templates);

        return null;
    }

    # ---- PRIVATE INTERFACE -------------------------------------------------
}
