<?php if (!defined('APPLICATION')) exit();

/**
 * ProxyConnect Plugin
 * 
 * Enables SingleSignOn (SSO) between forums and other authorized consumers on 
 * the same domain, via cookie sharing.
 *
 * @author Tim Gunter <tim@vanillaforums.com>
 * @copyright 2003 Vanilla Forums, Inc
 * @license http://www.opensource.org/licenses/gpl-2.0.php GPL
 * @package Addons
 * @since 1.0
 */

// Define the plugin:
$PluginInfo['ProxyConnect'] = array(
	'Name' => 'Vanilla Proxyconnect',
   'Description' => 'This plugin enables SingleSignOn (SSO) between your forum and other authorized consumers on the same domain, via cookie sharing.',
   'Version' => '1.9.9',
   'MobileFriendly' => TRUE,
   'RequiredApplications' => array('Vanilla' => '2.0.18'),
   'RequiredTheme' => FALSE, 
   'RequiredPlugins' => FALSE,
   'SettingsUrl' => '/dashboard/authentication/proxy',
   'SettingsPermission' => 'Garden.Settings.Manage',
   'HasLocale' => TRUE,
   'RegisterPermissions' => FALSE,
   'Author' => "Tim Gunter",
   'AuthorEmail' => 'tim@vanillaforums.com',
   'AuthorUrl' => 'http://www.vanillaforums.com'
);

class ProxyConnectPlugin extends Gdn_Plugin {
   
   public function __construct() {
      parent::__construct();
      
      // 2.0.18+
      // Ensure that when ProxyConnect is turned on, we always have its SearchPath indexed
      try {
         $ProxyConnectSearchPathName = 'ProxyConnect RIMs';
         $CustomSearchPaths = Gdn::PluginManager()->SearchPaths(TRUE);

         if (!in_array($ProxyConnectSearchPathName, $CustomSearchPaths)) {
            $InternalPluginFolder = $this->GetResource('internal');
            Gdn::PluginManager()->AddSearchPath($InternalPluginFolder, 'ProxyConnect RIMs');
         }
      } catch (Exception $e) {}
   }

   public function SettingsController_ProxyConnect_Create($Sender) {
      $Sender->Permission('Garden.Settings.Manage');
      $Sender->Title('Proxy Connect SSO');
		$Sender->Form = new Gdn_Form();
      
      $this->Provider = $this->LoadProviderData($Sender);
		
      // Load internal Integration Manager list
      $this->IntegrationManagers = array();
      
      $InternalPath = $this->GetResource('internal');
      
      try {
         
         // 2.0.18+
         // New PluginManager Code
         
         $IntegrationManagers = Gdn::PluginManager()->AvailablePluginFolders($InternalPath);
         $IntegrationList = array();
         foreach ($IntegrationManagers as $Integration)
            $this->IntegrationManagers[$Integration] = Gdn::PluginManager()->GetPluginInfo($Integration);
      } catch (Exception $e) {
      
         // 2.0.17.x and below
         // Old PluginManager Code
         
         if ($FolderHandle = opendir($InternalPath)) {
            // Loop through subfolders (ie. the actual plugin folders)
            while ($FolderHandle !== FALSE && ($Item = readdir($FolderHandle)) !== FALSE) {
               if (in_array($Item, array('.', '..')))
                  continue;

               $PluginPaths = SafeGlob($InternalPath . DS . $Item . DS . '*plugin.php');
               $PluginPaths[] = $InternalPath . DS . $Item . DS . 'default.php';

               foreach ($PluginPaths as $i => $PluginFile) {
                  if (file_exists($PluginFile)) {

                     $PluginInfo = Gdn::PluginManager()->ScanPluginFile($PluginFile);

                     if (!is_null($PluginInfo)) {
                        Gdn_LibraryMap::SafeCache('plugin',$PluginInfo['ClassName'],$PluginInfo['PluginFilePath']);
                        $Index = strtolower($PluginInfo['Index']);
                        $this->IntegrationManagers[$Index] = $PluginInfo;
                     }
                  }
               }
            }
            closedir($FolderHandle);
         }
         
      }
      
      $this->IntegrationManager = C('Plugin.ProxyConnect.IntegrationManager', NULL);
      if (is_null($this->IntegrationManager)) 
         $this->SetIntegrationManager('proxyconnectmanual');
      
		$this->EnableSlicing($Sender);
		$this->Dispatch($Sender, $Sender->RequestArgs);
   }
   
   /**
   *  Handle request for configure URL
   * 
   * When the user loads Dashboard/Authentication, the list of currently enabled authenticators is polled for 
   * each of their configuration URLs. This handles that polling request and responds with the subcontroller
   * URL that loads ProxyConnect's config window.
   * 
   * @param mixed $Sender
   */
   public function AuthenticationController_AuthenticatorConfigurationProxy_Handler($Sender) {
      $Sender->AuthenticatorConfigure = '/dashboard/settings/proxyconnect';
   }
   
   public function Controller_Index($Sender) {
      $this->AddSliceAsset($this->GetResource('proxyconnect.css', FALSE,FALSE));
      
      foreach ($this->IntegrationManagers as $Index => $Manager)
         $IntegrationList[$Index] = $Manager['Name'];
      
      $Sender->SetData('IntegrationChooserList', $IntegrationList);
      $Sender->SetData('PreFocusIntegration', $this->IntegrationManager);
      
      $Sender->SliceConfig = $this->RenderSliceConfig();
      $Sender->Render('proxyconnect','','plugins/ProxyConnect');
   }
   
   public function Controller_Integrate($Sender) {
      $IntegrationManager = (sizeof($Sender->RequestArgs) > 1) ? $Sender->RequestArgs[1] : NULL;
      
      if (!is_null($IntegrationManager)) {
         if (!array_key_exists($IntegrationManager, $this->IntegrationManagers))
            throw new Exception('No such Integration Manager - '.$IntegrationManager);
         
         $this->SetIntegrationManager($IntegrationManager);
      }
      
      $this->Controller_Integration($Sender);
   }
   
   public function Controller_Integration($Sender) {
      $this->IntegrationConfigurationPath = $this->GetView('integration.php');
      
      $Manager = C('Plugin.ProxyConnect.IntegrationManager', FALSE);
      if ($Manager) {
         $Sender->EnableSlicing($Sender);
         $this->Controller = $Sender;
         $this->SubController = (sizeof($Sender->RequestArgs) > 2) ? $Sender->RequestArgs[2] : 'index';
         
         $this->FireEvent('ConfigureIntegrationManager');
      }

      $Sender->Render($this->IntegrationConfigurationPath);
   }
   
   public function Controller_Test($Sender) {
      $Sender->AddSideMenu('dashboard/authentication');
      $Sender->Form = new Gdn_Form();
      
      // Load Provider
      $Authenticator = Gdn::Authenticator()->GetAuthenticator('proxy');
      $Provider = $Authenticator->GetProvider();
      if (!$Provider) {
         $Sender->SetData("Provider", FALSE);
         $Sender->Form->AddError("Authentication Provider information has not been configured");
      } else { $Sender->SetData("Provider", $Provider); }
      
      // No response by default
      $Sender->SetData('ConnectResponse', NULL);
      $Sender->SetData('Connected', FALSE);
      $Sender->SetData('ConnectedAs', FALSE);
      $Sender->SetData('Attempt', FALSE);
      
      if ($Sender->Form->IsPostBack()) {
         
         if (!$Sender->Form->ErrorCount()) {
            
            $Sender->SetData('Attempt', TRUE);
            
            // Do remote ping
            $Connect = new ProxyRequest();
            $AuthenticateURL = GetValue('AuthenticateUrl', $Provider);
            $Response = $Connect->Request(array(
               'URL'       => $AuthenticateURL,
               'Cookies'   => TRUE
            ));
            $Response = trim($Response);

            if ($Response) {
               
               // Store serialized struct
               $Sender->SetData('RawResponse', $Response);
               $Sender->SetData('ConnectResponse', $Response);
               
               $ReadMode = strtolower(C("Garden.Authenticators.proxy.RemoteFormat", "ini"));
               $Sender->SetData('ReadMode', $ReadMode);
               switch ($ReadMode) {
                  case 'ini':
                     $IniResult = array();
                     $RawIni = explode("\n", $Response);
                     foreach ($RawIni as $ResponeLine) {
                        $ResponeLine = trim($ResponeLine);
                        if (stristr($ResponeLine, '=') === FALSE) continue;
                        
                        $ResponseParts = explode("=", $ResponeLine);
                        $ResponseKey = array_shift($ResponseParts);
                        $ResponseValue = implode("=",$ResponseParts);
                        $IniResult[$ResponseKey] = $ResponseValue;
                     }
                     if (sizeof($IniResult))
                        $Result = $IniResult;
                     break;

                  case 'json':
                     $Result = @json_decode($Response);
                     break;

                  default:
                     throw new Exception("Unexpected value '$ReadMode' for 'Garden.Authenticators.proxy.RemoteFormat'");
               }
               
               if ($Result) {
                  
                  // Store parsed struct
                  $Sender->SetData('ConnectResponse', $Result);
                  
                  $UniqueID = GetValue('UniqueID', $Result, NULL);
                  $Email = GetValue('Email', $Result, NULL);
                  if (is_null($Email) || is_null($UniqueID)) return FALSE;

                  $ReturnArray = array(
                     'Email'        => $Email,
                     'UniqueID'     => $UniqueID,
                     'Name'         => GetValue('Name', $Result, NULL),
                     'TransientKey' => GetValue('TransientKey', $Result, NULL)
                  );
                  
                  $Sender->SetData('Connected', $ReturnArray);
                  $Sender->SetData('ConnectedAs', GetValue('Name', $ReturnArray));
                  
               } else {
                  $Sender->SetData('NoParse', TRUE);
               }

            }

         }
      }
      
      $Sender->Render('test','','plugins/ProxyConnect');
   }
   
   protected function SetIntegrationManager($ManagerName) {
      $Manager = $this->GetIntegrationManager($ManagerName);
      $OldManager = C('Plugin.ProxyConnect.IntegrationManager', FALSE);
      
      if ($OldManager !== FALSE) {
         $OldManagerData = $this->GetIntegrationManager($OldManager);
         if (Gdn::PluginManager()->CheckPlugin($OldManagerData['Index'])) {
            Gdn::PluginManager()->DisablePlugin($OldManagerData['Index']);
         }
      }
      
      $AlreadyEnabled = Gdn::PluginManager()->CheckPlugin($Manager['Index']);
      if (!$AlreadyEnabled) {
         // 2.0.18+ vs 2.0.17.9-
         if (version_compare(APPLICATION_VERSION, '2.0.17.10', ">"))
            Gdn::PluginManager()->EnablePlugin($Manager['Index'], FALSE, TRUE);
         else
            Gdn::PluginManager()->EnablePlugin($Manager['ClassName'], FALSE, TRUE, 'ClassName');
      }
      SaveToConfig('Plugin.ProxyConnect.IntegrationManager', $ManagerName);
      $this->IntegrationManager = $ManagerName;
   }
   
   protected function GetIntegrationManager($ManagerName) {
      if (array_key_exists($ManagerName, $this->IntegrationManagers)) return GetValue($ManagerName, $this->IntegrationManagers);
      
      $LoweredList = array();
      foreach ($this->IntegrationManagers as $MName => $MVal)
         $LoweredList[strtolower ($MName)] = $MName;
      
      $ManagerName = strtolower($ManagerName);
      $ManagerName = GetValue($ManagerName, $LoweredList, NULL);
      
      return GetValue($ManagerName, $this->IntegrationManagers, FALSE);
   }
   
   public function LoadProviderData($Sender) {
      $Authenticator = Gdn::Authenticator()->GetAuthenticator('proxy');
      $Provider = $Authenticator->GetProvider();
      
      if (!$Provider) {
         $Provider = $this->CreateProviderModel();
      }
      
      $Sender->SetData('Provider', $Provider);
      return ($Provider) ? $Provider : NULL;
   }
   
   public function EntryController_SignIn_Handler(&$Sender) {
      if (!Gdn::Authenticator()->IsPrimary('proxy')) return;
      $AllowCallout = !Gdn::Request()->GetValue('Landing', FALSE);
      $this->SigninLoopback($Sender, $AllowCallout);
   }
   
   /**
    * Perform proxyconnect loop
    * 
    * @param EntryController $Sender 
    * @param boolean $AllowCallout Whether to allow redirection to the remote login
    * @return type 
    */
   protected function SigninLoopback($Sender, $AllowCallout = TRUE) {
      if (!Gdn::Authenticator()->IsPrimary('proxy')) return;
      $Redirect = Gdn::Request()->GetValue('HTTP_REFERER');
      
      $SigninURL = Gdn::Authenticator()->GetURL(Gdn_Authenticator::URL_REMOTE_SIGNIN, $Redirect);
      $SignoutURL = Gdn::Authenticator()->GetURL(Gdn_Authenticator::URL_SIGNOUT, NULL);
      $RealUserID = Gdn::Authenticator()->GetRealIdentity();
      $Authenticator = Gdn::Authenticator()->GetAuthenticator('proxy');
      
      // Shortcircuit loopback if we have a Sync failure
      $Payload = $Authenticator->GetHandshake();
      
      if ($Payload !== FALSE) {
         
         // If Payload was some weird thing, fix it so we can read it safely
         if (!is_array($Payload))
               $Payload = array('Sync' => 'Failed');

         if (array_key_exists('Sync',$Payload) && $Payload['Sync'] == 'Failed') {
         
            // Force user to be logged out of Vanilla
            $Authenticator->SetIdentity(NULL);
            
            // Forget that this happened (user can start fresh)
            $Authenticator->DeleteCookie();
            
            // Send the user to the signout URL
            Redirect($SignoutURL,302);
         }   
      }
      
      if ($RealUserID == -1) {
         // The cookie says we're banned from auto remote pinging in right now, but the user has specifically clicked
         // 'sign in', so first try to sign them in using their current cookies:
         $AuthResponse = $Authenticator->Authenticate();
         
         // @TODO
//         $UserInfo = array();
//         $UserEventData = array_merge(array(
//            'UserID'    => Gdn::Session()->UserID,
//            'Payload'   => GetValue('HandshakeResponse', $Authenticator, FALSE)
//         ),$UserInfo);
//         Gdn::Authenticator()->Trigger($AuthResponse,$UserEventData);
         
         if (Gdn::Authenticator()->GetIdentity()) {
            
            // That worked, so redirect to the default page. The user is now signed in.
            Redirect(Gdn::Router()->GetDestination('DefaultController'), 302);
            
         } else {
            
            // Partial. Send user to Handshake
            if ($AuthResponse == Gdn_Authenticator::AUTH_PARTIAL) {
               return Redirect(Url('/entry/handshake/proxy',TRUE),302);
            }
            
            // The user really isnt signed in. Delete their cookie and send them to the remote login page.
            $Authenticator->SetIdentity(NULL);
            $Authenticator->DeleteCookie();
            if ($AllowCallout)
               Redirect($SigninURL,302);
            else
               Redirect(Gdn::Router()->GetDestination('DefaultController'), 302);
            
         }
      } else {
         if ($RealUserID) {
            // The user is already signed in. Send them to the default page.
            Redirect(Gdn::Router()->GetDestination('DefaultController'), 302);
         } else {
            // We have no cookie for this user. Send them to the remote login page.
            $Authenticator->SetIdentity(NULL);
            if ($AllowCallout)
               Redirect($SigninURL,302);
            else
               Redirect(Gdn::Router()->GetDestination('DefaultController'), 302);
         }
      }
      exit();
   }
   
   public function EntryController_Land_Create($Sender) {
      if (!Gdn::Authenticator()->IsPrimary('proxy')) return;
      $LandingRequest = Gdn_Request::Create()->FromImport(Gdn::Request())
         ->WithURI("/dashboard/entry/signin")
         ->WithCustomArgs(array(
            'Landing'   => TRUE
         ));
      return Gdn::Dispatcher()->Dispatch($LandingRequest);
   }
   
   public function EntryController_BeforeSignOut_Handler(&$Sender) {
      if (!Gdn::Authenticator()->IsPrimary('proxy')) return;
      $SessionAuthenticator = Gdn::Session()->GetPreference('Authenticator');
      if ($SessionAuthenticator != 'proxy') return;
      
      $Redirect = Gdn::Request()->GetValue('HTTP_REFERER');
      $SignoutURL = Gdn::Authenticator()->RemoteSignOutUrl($Redirect);
      Gdn::Session()->End();
      Redirect($SignoutURL,302);
   }
   
   public function EntryController_Register_Handler(&$Sender) {
      if (!Gdn::Authenticator()->IsPrimary('proxy')) return;
      
      $Redirect = Gdn::Request()->GetValue('HTTP_REFERER');
      $RegisterURL = Gdn::Authenticator()->GetURL(Gdn_Authenticator::URL_REMOTE_REGISTER, $Redirect);
      $RealUserID = Gdn::Authenticator()->GetRealIdentity();
      $Authenticator = Gdn::Authenticator()->GetAuthenticator('proxy');
      
      if ($RealUserID > 0) {
         // The user is already signed in. Send them to the default page.
         Redirect(Gdn::Router()->GetDestination('DefaultController'), 302);
      } else {
         // We have no cookie for this user. Send them to the remote registration page.
         $Authenticator->SetIdentity(NULL);
         Redirect($RegisterURL,302);
      }
   }
   
   public function Setup() {
		$NumLookupMethods = 0;
		
		if (function_exists('fsockopen')) $NumLookupMethods++;
		if (function_exists('curl_init')) $NumLookupMethods++;

		if (!$NumLookupMethods)
		   throw new Exception(T("Unable to initialize plugin: required connectivity libraries not found, need either 'fsockopen' or 'curl'."));
      
      $this->_Enable(FALSE);
   }
   
   public function OnDisable() {
		$this->_Disable();
		
		Gdn::Authenticator()->DisableAuthenticationScheme('proxy');
		
		RemoveFromConfig('Garden.Authenticators.proxy.Name');
      RemoveFromConfig('Garden.Authenticators.proxy.CookieName');
   }
   
   public function CreateProviderModel() {
      $Key = 'k'.sha1(implode('.',array(
         'proxyconnect',
         'key',
         microtime(true),
         RandomString(16),
         Gdn::Session()->User->Name
      )));
      
      $Secret = 's'.sha1(implode('.',array(
         'proxyconnect',
         'secret',
         md5(microtime(true)),
         RandomString(16),
         Gdn::Session()->User->Name
      )));
      
      $ProviderModel = new Gdn_AuthenticationProviderModel();
      $Inserted = $ProviderModel->Insert($Provider = array(
         'AuthenticationKey'           => $Key,
         'AuthenticationSchemeAlias'   => 'proxy',
         'AssociationSecret'           => $Secret,
         'AssociationHashMethod'       => 'HMAC-SHA1'
      ));
      
      return ($Inserted !== FALSE) ? $Provider : FALSE;
   }
   
   public function AuthenticationController_DisableAuthenticatorProxy_Handler(&$Sender) {
      $this->_Disable();
   }
   
   private function _Disable() {
      RemoveFromConfig('Plugins.ProxyConnect.Enabled');
		
		$WasEnabled = Gdn::Authenticator()->UnsetDefaultAuthenticator('proxy');
      if ($WasEnabled)
         RemoveFromConfig('Garden.SignIn.Popup');
         
      $InternalPluginFolder = $this->GetResource('internal');
      // 2.0.18+
      try {
         Gdn::PluginManager()->RemoveSearchPath($InternalPluginFolder);
      } catch (Exception $e) {}
   }
	
   public function AuthenticationController_EnableAuthenticatorProxy_Handler(&$Sender) {
      $this->_Enable();
   }
	
	private function _Enable($FullEnable = TRUE) {
		SaveToConfig('Garden.Authenticators.proxy.Name', 'ProxyConnect');
      SaveToConfig('Garden.Authenticators.proxy.CookieName', 'VanillaProxy');
      
      $InternalPluginFolder = $this->GetResource('internal');
      // 2.0.18+
      try {
         Gdn::PluginManager()->AddSearchPath($InternalPluginFolder, 'ProxyConnect RIMs');
      } catch (Exception $e) {}
      
      if ($FullEnable) {
         SaveToConfig('Garden.SignIn.Popup', FALSE);
         SaveToConfig('Plugins.ProxyConnect.Enabled', TRUE);
      }
      Gdn::Authenticator()->EnableAuthenticationScheme('proxy', $FullEnable);
      
      // Create a provider key/secret pair if needed
      $SQL = Gdn::Database()->SQL();
      $Provider = $SQL->Select('uap.*')
         ->From('UserAuthenticationProvider uap')
         ->Where('uap.AuthenticationSchemeAlias', 'proxy')
         ->Get()
         ->FirstRow(DATASET_TYPE_ARRAY);
         
      if (!$Provider)
         $this->CreateProviderModel();
	}  
}
