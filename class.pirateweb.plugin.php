<?php if (!defined('APPLICATION')) exit();
/*
Copyright 2014 Rasmus Eneman
Copyright 2008, 2009 Vanilla Forums Inc.
This file is part of Garden.
Garden is free software: you can redistribute it and/or modify it under the terms of the GNU General Public License as published by the Free Software Foundation, either version 3 of the License, or (at your option) any later version.
Garden is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for more details.
You should have received a copy of the GNU General Public License along with Garden.  If not, see <http://www.gnu.org/licenses/>.
Contact Vanilla Forums Inc. at support [at] vanillaforums [dot] com
*/

// Define the plugin:
$PluginInfo['PirateWeb'] = array(
	'Name' => 'PirateWeb',
   'Description' => 'Allows users to sign in with their PirateWeb accounts.',
   'Version' => '1.0',
   'RequiredApplications' => array('Vanilla' => '2.0.14'),
   'RequiredPlugins' => FALSE,
   'RequiredTheme' => FALSE,
   'MobileFriendly' => TRUE,
   'SettingsPermission' => 'Garden.Settings.Manage',
   'HasLocale' => TRUE,
   'RegisterPermissions' => FALSE,
   'Author' => 'Rasmus Eneman',
   'AuthorEmail' => 'rasmus@eneman.eu',
   'AuthorUrl' => 'http://rasmus.eneman.eu'
);

class PirateWebPlugin extends Gdn_Plugin {
    public static $ProviderKey = 'PirateWeb';

    /// Properties ///

    protected function _AuthorizeHref($Popup = FALSE) {
        $Url = Url('/entry/PirateWeb', TRUE);
        $UrlParts = explode('?', $Url);
        parse_str(GetValue(1, $UrlParts, ''), $Query);

        $Path = '/'.Gdn::Request()->Path();
        $Query['Target'] = GetValue('Target', $_GET, $Path ? $Path : '/');
        if (isset($_GET['Target']))
            $Query['Target'] = $_GET['Target'];
        if ($Popup)
            $Query['display'] = 'popup';

        $Result = $UrlParts[0].'?'.http_build_query($Query);
        return $Result;
    }

    /// Plugin Event Handlers ///

    // Validate that the necessary config is set
    public function Setup() {
        if (!ini_get('allow_url_fopen')) {
            throw new Gdn_UserException('This plugin requires the allow_url_fopen php.ini setting.');
        }
    }

    // Handle the response from PirateWeb
    public function Base_ConnectData_Handler($Sender, $Args) {
        if (GetValue(0, $Args) != 'PirateWeb')
            return;

        $this->EventArguments = $Args;

        // Check session before retrieving
        $Session = Gdn::Session();

        if (isset($_GET['result']) and $_GET['result'] === 'success' and isset($_GET['ticket'])) {
            $ticket = $_GET['ticket'];
            unset($_GET['ticket']);

            $pirateWebValidateUrl = 'https://pirateweb.net/Pages/Security/ValidateTicket.aspx?ticket=';
            $pirateWebValidateUrl .= $ticket;

            $xml = simplexml_load_file($pirateWebValidateUrl);

            $firstName = (string) $xml->USER->GIVENNAME;
            $lastName = (string) $xml->USER->SN;
            $email = (string) $xml->USER->EMAIL;
            $openidHandle = (string) $xml->USER->OPENIDHANDLE;

            $memberInPiratpartietSweden = false;
            $memberships = $xml->USER->MEMBERSHIPS->MEMBERSHIP;
            //throw new Gdn_UserException(var_export($memberships, true));
            if (is_array($memberships)) {
                foreach ($memberships as $membership) {
                    // I don't know why this is necessary, there are no nested arrays in the XML.
                    //foreach ($memberships2 as $membership) {
                        $attributes = $membership->attributes();
                        $orgid = (string) $attributes['orgid'];
                        // PPSE have orgid 1
                        if ($orgid === '1') {
                            $memberInPiratpartietSweden = true;
                            break;
                        }
                    //}
                }
            }
            if (!$memberInPiratpartietSweden) {
                throw new Gdn_UserException('Du verkar inte vara medlem i Piratpartiet Sverige');
            }

            $userInfo = array(
                'firstName' => $firstName,
                'lastName' => $lastName,
                'email' => $email,
                'openidHandle' => $openidHandle
            );
            $Session->Stash('UserInfo', $userInfo, FALSE);
        }

        if ($Session->Stash('UserInfo', '', FALSE)) {
            $userInfo = $Session->Stash('UserInfo', '', FALSE);

            $Form = $Sender->Form; //new Gdn_Form();
            $Form->SetFormValue('UniqueID', $userInfo['openidHandle']);
            $Form->SetFormValue('Provider', self::$ProviderKey);
            $Form->SetFormValue('ProviderName', 'PirateWeb');
            $Form->SetFormValue('FullName', $userInfo['firstName'].' '.$userInfo['lastName']);
            $Form->SetFormValue('Email', $userInfo['email']);

            if (!$Form->GetFormValue('ConnectName')) {
                $Form->SetFormValue('ConnectName', strtolower($userInfo['firstName'].'_'.$userInfo['lastName']));
            }

            $Form->SetData($Form->FormValues());
        }


        $Sender->SetData('Verified', TRUE);
    }

    /**
     * Redirect the user to the PirateWeb login page and tell it to send the user back to us later
     *
     * @param EntryController $Sender
     * @param array $Args
     */
    public function EntryController_PirateWeb_Create($Sender, $Args) {
        $this->EventArguments = $Args;
        $Sender->Form->InputPrefix = '';
        $pirateWebLoginUrl = 'https://pirateweb.net/Pages/Security/Login.aspx?openid=1&orgid=1&redirect=';

        $Url = Url('/entry/connect/PirateWeb', TRUE);
        $UrlParts = explode('?', $Url);
        parse_str(GetValue(1, $UrlParts, ''), $Query);
        $Query = array_merge($Query, ArrayTranslate($_GET, array('display', 'Target')));

        $pirateWebLoginUrl .= urlencode($UrlParts[0].'?'.http_build_query($Query));

        Redirect($pirateWebLoginUrl);
    }

   /**
    *
    * @param Gdn_Controller $Sender
    */
   public function EntryController_SignIn_Handler($Sender, $Args) {

      if (isset($Sender->Data['Methods'])) {
         $SigninHref = $this->_AuthorizeHref();
         $PopupSigninHref = $this->_AuthorizeHref(TRUE);

         // Add the PirateWeb method to the controller.
         $Method = array(
            'Name' => 'PirateWeb',
            'SignInHtml' => "<a id=\"PirateWebAuthBig\" href=\"$SigninHref\" class=\"PopupWindow Button Primary\" popupHref=\"$PopupSigninHref\" popupHeight=\"600\" popupWidth=\"800\" rel=\"nofollow\" >Logga in via PirateWeb</a>"
         );

         $Sender->Data['Methods'][] = $Method;
      }
   }

   public function Base_SignInIcons_Handler($Sender, $Args) {
		echo "\n".$this->_GetButton();
	}

   public function Base_BeforeSignInButton_Handler($Sender, $Args) {
		echo "\n".$this->_GetButton();
	}

	private function _GetButton() {
      $SigninHref = $this->_AuthorizeHref();
      $PopupSigninHref = $this->_AuthorizeHref(TRUE);
      return "<a id=\"PirateWebAuth\" href=\"$SigninHref\" class=\"PopupWindow Button Primary\" popupHref=\"$PopupSigninHref\" popupHeight=\"600\" popupWidth=\"800\" rel=\"nofollow\" >Logga in via PirateWeb</a>";
   }

	public function Base_BeforeSignInLink_Handler($Sender) {

		if (!Gdn::Session()->IsValid())
			echo "\n".Wrap($this->_GetButton(), 'li', array('class' => 'Connect PirateWebConnect'));
	}
}
