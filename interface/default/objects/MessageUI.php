<?PHP
#
#   FILE:  MessageUI.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2020-2024 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus;

use ScoutLib\StdLib;
use ScoutLib\ApplicationFramework;

/**
 * User interface to print resource comments.
 */
class MessageUI
{
    # ---- PUBLIC INTERFACE --------------------------------------------------
    /**
     * Print a resource comment.
     * @param Message $Message Message to display.
     * @param Record $Resource Associated resource.
     * @return void
     */
    public static function printForumMessage(Message $Message, Record $Resource): void
    {
        $AF = ApplicationFramework::getInstance();
        $EditOkay = false;

        # retrieve user currently logged in
        $User = User::getCurrentUser();

        if ($User->isLoggedIn()) {
            if ($User->hasPriv(PRIV_COLLECTIONADMIN)) {
                $EditOkay = true;
            } elseif ($User->get("UserId") == $Message->posterId() &&
                $User->hasPriv(PRIV_POSTCOMMENTS)) {
                $EditOkay = true;
            }
        }

        $EditLink = "index.php?P=AddResourceComment&amp;RI="
            .$Resource->id()."&amp;MI=".$Message->id();
        $DeleteLink = $EditLink;
        $SpammerLink = "index.php?P=CleanSpam&amp;PI=".$Message->posterId()
            ."&amp;RI=".$Resource->id();

        $DatePosted = strtotime($Message->datePosted());
        $PosterEmail = $Message->posterEmail();
        $DateEdited = strtotime($Message->dateEdited());

        $MsgHasBeenEdited = $DateEdited >= $DatePosted;
        if ($MsgHasBeenEdited) {
            if (User::itemExists($Message->editorId())) {
                $Editor = new User($Message->editorId());
                $SafeEditorName = defaulthtmlentities($Editor->name());
            } else {
                $SafeEditorName = "[user deleted]";
            }
            $SafeDateEdited = StdLib::getPrettyTimestamp($DateEdited);
        } else {
            $SafeEditorName = "";
            $SafeDateEdited = "";
        }

        $CanMarkSpammers = $User->hasPriv(PRIV_SYSADMIN, PRIV_USERADMIN);
        $DisplayEmail = strlen($PosterEmail)
            && $User->isLoggedIn()
            && (!$GLOBALS["G_PluginManager"]->pluginEnabled("BotDetector")
                || !$GLOBALS["G_PluginManager"]->getPlugin("BotDetector")->checkForSpamBot());

        $SafeSubject = defaulthtmlentities($Message->subject());
        $SafeBody = SystemConfiguration::getInstance()->getBool("CommentsAllowHTML")
                ? StripXSSThreats($Message->body())
                : nl2br(defaulthtmlentities($Message->body()));
        $SafePosterName = strlen($Message->posterName())
                ? defaulthtmlentities($Message->posterName())
                : "[deleted&nbsp;account]";
        $SafeDatePosted = StdLib::getPrettyTimestamp($DatePosted);
        $SafePosterEmail = $DisplayEmail ?
            "(".self::obfuscateEmailAddress($PosterEmail).")" :
            "";

        $SafeSubject = $AF->escapeInsertionKeywords($SafeSubject);
        $SafeBody = $AF->escapeInsertionKeywords($SafeBody);

        self::printJavascriptIfNeeded();
        ?>
        <div class="mv-section mv-section-elegant mv-content-forummessage">
          <div class="mv-section-header">
            <div class="container-fluid">
              <div class="row">
                <div class="col">
                  <b>Subject:</b> <?= $SafeSubject ?>
                </div>
                <div class="col">
                  <b>Posted By:</b> <?= $SafePosterName ?> <?= $SafePosterEmail ?>
                </div>
                <div class="col">
                  <b>Date Posted:</b> <?= $SafeDatePosted ?>
                </div>
              </div>
            </div>
          </div>
          <div class="mv-section-body">
            <div class="container-fluid">
              <div class="row">
                <div class="col">
                  <?= $SafeBody ?>
                  <?PHP if ($MsgHasBeenEdited) { ?>
                  <p class="mv-content-editedby">
                    This message was edited by <?= $SafeEditorName ?>
                            on <?= $SafeDateEdited ?>.
                  </p>
                  <?PHP } ?>
                </div>
              </div>
            </div>
          </div>
          <?PHP if ($EditOkay) { ?>
          <div class="mv-section-footer">
            <div class="container-fluid">
              <div class="row">
                <div class="col">
                  <a class="btn btn-primary btn-sm mv-button-iconed" href="<?= $EditLink ?>">
                    <img class="mv-button-icon"
                        src="<?= $AF->gUIFile('Pencil.svg') ?>"/> Edit Message</a>
                  <a class="btn btn-danger btn-sm mv-button-iconed" href="<?= $DeleteLink ?>"><img
                        src="<?= $AF->gUIFile('Delete.svg'); ?>" alt=""
                        class="mv-button-icon" /> Delete Message</a>
                  <?PHP if ($CanMarkSpammers) {  ?>
                  <a class="btn btn-danger btn-sm" href="<?= $SpammerLink ?>"><img
                        src="<?= $AF->gUIFile('Flag.svg'); ?>" alt=""
                        class="mv-button-icon" /> Spammer</a>
                  <?PHP } ?>
                </div>
              </div>
            </div>
          </div>
          <?PHP } ?>
        </div>
        <?PHP
    }

    /**
     * Obfuscates an email address to try to fool web-scraping robots.Inserts
     * two strings of random characters into the email address, wrapped in
     * spans which will hide them from users whose browsers properly implement
     * CSS.Also wraps the whole thing in a span.EMungeAddr that will be used
     * by the front-end JS to find and remove the obfuscation for real humans.
     * @param string $String An email address to obfuscate
     * @return string the obfuscated email address
     */
    private static function obfuscateEmailAddress(string $String): string
    {
        $FuzzOne = substr(md5((string)mt_rand()), 0, rand(8, 32));
        $FuzzTwo = substr(md5((string)mt_rand()), 0, rand(8, 32));
        return '<span class="EMungeAddr">'.preg_replace(
            '/@/',
            '<span style="display:none;"> '.htmlentities($FuzzOne).' </span>'
            .'&#64;'
            .'<span style="display:none;"> '.htmlentities($FuzzTwo).' </span>',
            $String
        ).'</span>';
    }

    /**
     * Output supporting javascript that de-obfuscates email addresses.
     * @return void
     */
    private static function printJavascriptIfNeeded(): void
    {
        if (self::$JavascriptPrinted) {
            return;
        }

        ?>
        <script type="text/javascript">
        $(document).ready(function(){
            $("span.EMungeAddr span").remove();
            $.each($('span.EMungeAddr'), function(ix,val){
                $(val).replaceWith(
                    '<a href="mailto:'+$(val).text()+'">'+
                    $(val).text()+'</a>');
            });
        });
        </script>
        <?PHP

        self::$JavascriptPrinted = true;
    }

    private static $JavascriptPrinted = false;
}
