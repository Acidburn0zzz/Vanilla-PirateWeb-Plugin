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

    // Upgrades the saved user info to point to the member number instead of the openidhandle
    public function UpgradeToMemberNumber($userinfo) {
        $model = new Gdn_Model('User');

        $auth = $model->SQL
            ->GetWhere('UserAuthentication', array(
                    'ForeignUserKey' => $userinfo['openidHandle'],
                    'ProviderKey' => self::$ProviderKey
                ))
            ->FirstRow(DATASET_TYPE_ARRAY);

        if ($auth) {
            $model->SQL->Put('UserAuthentication', array(
                    'ForeignUserKey' => $userinfo['memberNumber']
                ), array(
                    'ForeignUserKey' => $userinfo['openidHandle'],
                    'ProviderKey' => self::$ProviderKey
                ), 1
            );
        }
    }

    /// Plugin Event Handlers ///

    // Validate that the necessary config is set
    public function Setup() {
        if (!ini_get('allow_url_fopen')) {
            throw new Gdn_UserException('This plugin requires the allow_url_fopen php.ini setting.');
        }
    }

    public function Gdn_Smarty_init_handler($sender) {
        $sender->register_function('pw_link', 'PirateAuthorizeHref');
    }

    // Handle the response from PirateWeb
    public function Base_ConnectData_Handler($Sender, $Args) {
        if (GetValue(0, $Args) != 'PirateWeb')
            return;

        $this->EventArguments = $Args;

        // Check session before retrieving
        $Session = Gdn::Session();

        if (!$Session->Stash('UserInfo', '', FALSE) and isset($_GET['result']) and
            $_GET['result'] === 'success' and isset($_GET['ticket'])) {
            $ticket = $_GET['ticket'];
            unset($_GET['ticket']);

            $pirateWebValidateUrl = 'https://pirateweb.net/Pages/Security/ValidateTicket.aspx?ticket=';
            $pirateWebValidateUrl .= $ticket;

            $xml = simplexml_load_file($pirateWebValidateUrl);

            $memberNumber = (string) $xml->USER->ID;
            $firstName = (string) $xml->USER->GIVENNAME;
            $lastName = (string) $xml->USER->SN;
            $email = (string) $xml->USER->EMAIL;
            $displayName = (string) $xml->USER->OPENIDHANDLE;
            $openidHandle = 'http://login.piratpartiet.se/openid/'.$displayName.'/';

            $memberInPiratpartietSweden = false;
            $memberships = $xml->USER->MEMBERSHIPS;
            foreach ($memberships as $memberships2) {
                // I don't know why this is necessary, there are no nested arrays in the XML.
                foreach ($memberships2 as $membership) {
                    $attributes = $membership->attributes();
                    $orgid = (string) $attributes['orgid'];
                    // PPSE have orgid 1
                    if ($orgid === '1') {
                        $memberInPiratpartietSweden = true;
                        break;
                    }
                }
            }
            if (!$memberInPiratpartietSweden) {
                throw new Gdn_UserException('Du verkar inte vara medlem i Piratpartiet Sverige');
            }

            $invalidUsernameChars = '/[^'.C("Garden.User.ValidationRegex",'\d\w_').']/';
            $displayName = preg_replace($invalidUsernameChars, '_', $displayName);


            $userInfo = array(
                'memberNumber' => $memberNumber,
                'displayName' => $displayName,
                'firstName' => $firstName,
                'lastName' => $lastName,
                'email' => $email,
                'openidHandle' => $openidHandle
            );
            $Session->Stash('UserInfo', $userInfo, FALSE);
        }

        if ($Session->Stash('UserInfo', '', FALSE)) {
            $userInfo = $Session->Stash('UserInfo', '', FALSE);

            $this->UpgradeToMemberNumber($userInfo);

            $Form = $Sender->Form; //new Gdn_Form();
            $Form->SetFormValue('UniqueID', $userInfo['memberNumber']);
            $Form->SetFormValue('Provider', self::$ProviderKey);
            $Form->SetFormValue('ProviderName', 'PirateWeb');
            $Form->SetFormValue('FullName', $userInfo['firstName'].' '.$userInfo['lastName']);
            $Form->SetFormValue('Email', $userInfo['email']);

            // If the user have not entered a name (when we creates the form for the first time)
            if (!$Form->GetFormValue('ConnectName')) {
                // Suggest the displayName from PW (nickname in the old forum)
                $Form->SetFormValue('ConnectName', $userInfo['displayName']);
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
         $SigninHref = PirateAuthorizeHref(null, $ignored);
         $PopupSigninHref = PirateAuthorizeHref(null, $ignored, TRUE);

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
      $SigninHref = PirateAuthorizeHref(null, $ignored);
      $PopupSigninHref = PirateAuthorizeHref(null, $ignored, TRUE);
      return "<a id=\"PirateWebAuth\" href=\"$SigninHref\" class=\"PopupWindow Button Primary\" popupHref=\"$PopupSigninHref\" popupHeight=\"600\" popupWidth=\"800\" rel=\"nofollow\" >Logga in via PirateWeb</a>";
   }

	public function Base_BeforeSignInLink_Handler($Sender) {

		if (!Gdn::Session()->IsValid())
			echo "\n".Wrap($this->_GetButton(), 'li', array('class' => 'Connect PirateWebConnect'));
	}
}

function PirateAuthorizeHref($params, &$smarty, $Popup = FALSE) {
    decho($params);
    decho($smarty);
    decho($Popup);

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
