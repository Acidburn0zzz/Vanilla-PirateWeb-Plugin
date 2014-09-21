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

    /**
     * @return LightOpenID
     */
    public function GetOpenID() {
        if (get_magic_quotes_gpc()) {
            foreach ($_GET as $Name => $Value) {
                $_GET[$Name] = stripslashes($Value);
            }
        }

        $OpenID = new LightOpenID();

        $OpenID->identity = 'http://login.piratpartiet.se/openid/xrds';

        $Url = Url('/entry/connect/PirateWeb', TRUE);
        $UrlParts = explode('?', $Url);
        parse_str(GetValue(1, $UrlParts, ''), $Query);
        $Query = array_merge($Query, ArrayTranslate($_GET, array('display', 'Target')));

        $OpenID->returnUrl = $UrlParts[0].'?'.http_build_query($Query);
        $OpenID->required = array('contact/email', 'namePerson/first', 'namePerson/last');

        $this->EventArguments['PirateWeb'] = $OpenID;
        $this->FireEvent('GetOpenID');

        return $OpenID;
    }

    public function Setup() {
        if (!ini_get('allow_url_fopen')) {
            throw new Gdn_UserException('This plugin requires the allow_url_fopen php.ini setting.');
        }
    }

   /// Plugin Event Handlers ///

    public function Base_ConnectData_Handler($Sender, $Args) {
        if (GetValue(0, $Args) != 'PirateWeb')
            return;

        $Mode = $Sender->Request->Get('openid_mode');
        if ($Mode != 'id_res')
            return; // this will error out

        $this->EventArguments = $Args;

        // Check session before retrieving
        $Session = Gdn::Session();
        $OpenID = $Session->Stash('OpenID', '', FALSE);
        if (!$OpenID)
            $OpenID = $this->GetOpenID();

        if ($Session->Stash('OpenID', '', FALSE) || $OpenID->validate()) {
            $Attr = $OpenID->getAttributes();

            $firstName = GetValue('namePerson/first', $Attr);
            $lastName = GetValue('namePerson/last', $Attr);

            if (!$firstName && isset($_GET['openid_ax_value_namePerson_first_1'])) {
                $firstName = $_GET['openid_ax_value_namePerson_first_1'];
            }
            if (!$lastName && isset($_GET['openid_ax_value_namePerson_last_1'])) {
                $lastName = $_GET['openid_ax_value_namePerson_last_1'];
            }


            $Form = $Sender->Form; //new Gdn_Form();
            $ID = $OpenID->identity;
            $Form->SetFormValue('UniqueID', $ID);
            $Form->SetFormValue('Provider', self::$ProviderKey);
            $Form->SetFormValue('ProviderName', 'PirateWeb');
            $Form->SetFormValue('FullName', $firstName.' '.$lastName);
            $Form->SetFormValue('ConnectName', $firstName.'_'.$lastName);

            if ($Email = GetValue('contact/email', $Attr)) {
                $Form->SetFormValue('Email', $Email);
            }

            $Form->SetData($Form->FormValues());

            $Sender->SetData('Verified', TRUE);
            $Session->Stash('OpenID', $OpenID);
        }
    }

    /**
     *
     * @param EntryController $Sender
     * @param array $Args
     */
    public function EntryController_PirateWeb_Create($Sender, $Args) {
        $this->EventArguments = $Args;
        $Sender->Form->InputPrefix = '';
        $OpenID = $this->GetOpenID();

        $Mode = $Sender->Request->Get('openid_mode');
        switch($Mode) {
            case 'cancel':
                $Sender->Render('Cancel', '', 'plugins/PirateWeb');
                break;
            case 'id_res':
                if ($OpenID->validate()) {
                    $Attributes = $OpenID->getAttributes();
                    print_r($_GET);
                }

                break;
            default:
                if (!$OpenID->identity) {
                    $Sender->CssClass = 'Dashboard Entry connect';
                    $Sender->SetData('Title', T('Sign In with OpenID'));
                    $Sender->Render('Url', '', 'plugins/PirateWeb');
                } else {
                    try {
                        $Url = $OpenID->authUrl();
                        Redirect($Url);
                    } catch (Exception $Ex) {
                        $Sender->Form->AddError($Ex);
                        $Sender->Render('Url', '', 'plugins/PirateWeb');
                    }
                }
                break;
        }
    }

   /**
    *
    * @param Gdn_Controller $Sender
    */
   public function EntryController_SignIn_Handler($Sender, $Args) {

      if (isset($Sender->Data['Methods'])) {
         $ImgSrc = Asset('/plugins/PirateWeb/design/pirateweb-signin.png');
         $ImgAlt = T('Sign In with PirateWeb');
         $SigninHref = $this->_AuthorizeHref();
         $PopupSigninHref = $this->_AuthorizeHref(TRUE);

         // Add the PirateWeb method to the controller.
         $Method = array(
            'Name' => 'PirateWeb',
            'SignInHtml' => "<a id=\"PirateWebAuth\" href=\"$SigninHref\" class=\"PopupWindow\" popupHref=\"$PopupSigninHref\" popupHeight=\"600\" popupWidth=\"800\" rel=\"nofollow\" ><img src=\"$ImgSrc\" alt=\"$ImgAlt\" /></a>"
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
      $ImgSrc = Asset('/plugins/PirateWeb/design/pirateweb-icon.png');
      $ImgAlt = T('Sign In with PirateWeb');
      $SigninHref = $this->_AuthorizeHref();
      $PopupSigninHref = $this->_AuthorizeHref(TRUE);
      return "<a id=\"PirateWebAuth\" href=\"$SigninHref\" class=\"PopupWindow\" title=\"$ImgAlt\" popupHref=\"$PopupSigninHref\" popupHeight=\"600\" popupWidth=\"800\" rel=\"nofollow\" ><img src=\"$ImgSrc\" alt=\"$ImgAlt\" /></a>";
   }

	public function Base_BeforeSignInLink_Handler($Sender) {

		if (!Gdn::Session()->IsValid())
			echo "\n".Wrap($this->_GetButton(), 'li', array('class' => 'Connect PirateWebConnect'));
	}
}
