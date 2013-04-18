<?php
/***************************************************************
*  Copyright notice
*
*  (c) 2009 Frederik Schaller <frederik.schaller@educo.ch>
*  All rights reserved
*
*  This script is part of the TYPO3 project. The TYPO3 project is
*  free software; you can redistribute it and/or modify
*  it under the terms of the GNU General Public License as published by
*  the Free Software Foundation; either version 2 of the License, or
*  (at your option) any later version.
*
*  The GNU General Public License can be found at
*  http://www.gnu.org/copyleft/gpl.html.
*
*  This script is distributed in the hope that it will be useful,
*  but WITHOUT ANY WARRANTY; without even the implied warranty of
*  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
*  GNU General Public License for more details.
*
*  This copyright notice MUST APPEAR in all copies of the script!
***************************************************************/

require_once(PATH_tslib.'class.tslib_pibase.php');
require_once(t3lib_extMgm::extPath('phzldap') . 'includes/class.tx_phzldap_helper.php');
require_once(t3lib_extMgm::extPath('t3evento') . 'pi5/class.tx_t3evento_pi5.php');


/**
 * Plugin 'FE Login Box' for the 'phzldap' extension.
 *
 * @author	Frederik Schaller <frederik.schaller@educo.ch>
 * @package	TYPO3
 * @subpackage	tx_phzldap
 */
class tx_phzldap_pi1 extends tx_t3evento_pi5 {
	var $prefixId      = 'tx_phzldap_pi1';		// Same as class name
	var $scriptRelPath = 'pi1/class.tx_phzldap_pi1.php';	// Path to this script relative to the extension dir.
	var $extKey        = 'phzldap';	// The extension key.
	var $template 	   = null;

	/**
	 * The main method of the PlugIn
	 *
	 * @param	string		$content: The PlugIn content
	 * @param	array		$conf: The PlugIn configuration
	 * @return	The content that is displayed on the website
	 */
	function main($content, $conf) {
		$this->conf = $conf;
		$this->pi_setPiVarDefaults();
		$this->pi_loadLL();
		$this->pi_USER_INT_obj = 1;	// Configuring so caching is not expected. This value means that no cHash params are ever set. We do this, because it's a USER_INT object!
		$this->pi_initPIflexForm();
		$content = '';

		// Get all GET parameters
		$params = t3lib_div::_GET();

		// Check configuration
		if (!isset($conf['hostname'])) return $this->pi_getLL('no_hostname_set');
		if (!isset($conf['basedn'])) return $this->pi_getLL('no_basedn_set');
		if (!isset($conf['folder_uid'])) return $this->pi_getLL('no_folder_uid_set');
		if (!isset($conf['cruser_id'])) return $this->pi_getLL('no_cruser_id_set');
		if (!isset($conf['default_groupid'])) return $this->pi_getLL('no_default_groupid_set');
		if (!isset($conf['webserviceUrl'])) return $this->pi_getLL('no_webservice_url_set');
		if (!isset($conf['webservicePwd'])) return $this->pi_getLL('no_webservice_pwd_set');

		// Get page uid for redirection
		// Flexform value has priority, after that the TS value is beeing used
		$templateFile = $this->pi_getFFvalue($this->cObj->data['pi_flexform'], 'templateFile', 'general');
		if (empty($templateFile)) {		
			$this->template = $this->cObj->fileResource($this->conf['templateFile']);
		} else {
			// TODO: No idea why fileResource does not work in this context
			// $this->template = $this->cObj->fileResource($templateFile);
			$this->template = t3lib_div::getURL($templateFile);
		}
		if (empty($this->template)) return $this->pi_getLL('no_template_set');
		
		
		$this->successUid = (int) $this->pi_getFFvalue($this->cObj->data['pi_flexform'], 'successUid', 'general');
		if (!isset($this->successUid) or !t3lib_div::testInt($this->successUid)) {
			$this->successUid = (int) $conf['success_uid'];
		}
		if (!isset($this->successUid) or !t3lib_div::testInt($this->successUid)) return $this->pi_getLL('no_success_uid_set');

		$submit = t3lib_div::_GP('tx_phzldap_submit');
		$username = t3lib_div::_GP('tx_phzldap_username');
		$password = t3lib_div::_GP('tx_phzldap_password');

		/*
		 * Evento-Login with login link
		 * As long as the user doesn't have a password, he can log in with a link sent to him
		 */
		$encodedEventoId = t3lib_div::_GET('evt');
		$eventoVerificationCode = t3lib_div::_GET('evc');

		if (empty($submit) && !isset($encodedEventoId) && !isset($GLOBALS['TSFE']->fe_user->user['uid'])) { // If form not posted and no evc parameter
			$content = $this->renderLoginForm(null);
		} elseif (empty($submit) && isset($encodedEventoId) && isset($eventoVerificationCode)) {

			//$userLoggedOutParameter = ($GLOBALS['TSFE']->fe_user->user['uid'] > 0) ? '&userLoggedOut=1' : '';

			$eventoId = (int) tx_t3evento_helper::decodeString($encodedEventoId);
				// early exit if evt was malformed
			if(!eventoId) { exit; }
			$eventoVerificationCode = mysql_real_escape_string($eventoVerificationCode);

			$checkAndGetEventoUserInformation = $this->checkAndGetEventoUserInformation($eventoId, $eventoVerificationCode);
			if($checkAndGetEventoUserInformation !== false) {

				$userGroupArray = t3lib_div::trimExplode(',',$checkAndGetEventoUserInformation['PersonenCode']);
				$userGroupArray['count'] = count($userGroupArray);

				$attributes = array (
					'username' => $checkAndGetEventoUserInformation['IDPerson'],
					'eventoId' => $checkAndGetEventoUserInformation['IDPerson'],
					'password' => t3lib_div::md5int($checkAndGetEventoUserInformation['VerificationCode']),
					'cn' => $checkAndGetEventoUserInformation['PersonVorname'] . ' ' . $checkAndGetEventoUserInformation['PersonNachname'],
					'first_name' => $checkAndGetEventoUserInformation['PersonVorname'],
					'last_name' => $checkAndGetEventoUserInformation['PersonNachname'],
					'email' => $checkAndGetEventoUserInformation['PersoneMail'],
					'pid'         => $conf['folder_uid'],
					'cruser_id'   => $conf['cruser_id'],
					'eventoCodes'  => $userGroupArray,
				);

				if (tx_phzldap_helper::createOrUpdateFeUser($checkAndGetEventoUserInformation['VerificationCode'], $attributes, $conf)) {
					if ($this->loginUser($checkAndGetEventoUserInformation['IDPerson'], $checkAndGetEventoUserInformation['VerificationCode'])) {

						if (isset($params['arPid']) && t3lib_utility_Math::canBeInterpretedAsInteger($params['arPid'])) {
							// user is logged in, but we have a link to an access restricted page --> redirect
							$redirectLink = $this->cObj->getTypoLink_URL($params['arPid']);
							unset($params['arPid']);
							t3lib_utility_Http::redirect($redirectLink);
						}

					} else {
						$content = $this->renderLoginForm($this->pi_getLL('message_typo3_login_failed'));
					}
				} else {
					$content = $this->renderLoginForm($this->pi_getLL('message_create_or_update_user_failed'));
				}
			} else {
				$content = $this->renderLoginForm($this->pi_getLL('message_create_or_update_user_from_evento_failed'));
			}

		}
		/*
		 * Normal login by form submitting with username and password
		 */
		else {
			if (empty($username) or empty($password)) {
				$content = $this->renderLoginForm($this->pi_getLL('message_no_username_or_password'));
			} else {
				try {
					$connection = tx_phzldap_helper::connect($conf['hostname'], $conf['port']);
					$dn = tx_phzldap_helper::getDn($connection, $conf['basedn'], $username, $conf['authUser'], $conf['authPwd']);
					if ($dn) {
						$attrs = tx_phzldap_helper::getAttributes($connection, $dn, $username, $password);
						if ($attrs) {

								// get eventoCodes directly from the Evento database (LDAP only has daily data)
							$attrs['eventoCodes'] = $this->getEventoCodes($attrs['eventoId']);
							$attrs['eventoCodes']['count'] = count($attrs['eventoCodes']);

							if (tx_phzldap_helper::createOrUpdateFeUser($password, $attrs, $conf)) {
								if ($this->loginUser($attrs['eventoId'], $password)) {
										$redirect_url = $this->getRedirectAfterLoginUrl($conf);
										t3lib_utility_Http::redirect($redirect_url);
										exit;
								} else {
									$content = $this->renderLoginForm($this->pi_getLL('message_typo3_login_failed'));
								}
							} else {
								$content = $this->renderLoginForm($this->pi_getLL('message_create_or_update_user_failed'));
							}						
						} else {
							$content = $this->renderLoginForm($this->pi_getLL('message_login_failed'));
						}
					} else {
						$content = $this->renderLoginForm($this->pi_getLL('message_login_failed'));
						//$content .= ldap_error($connection);
					}
				} catch(Exception $e) {
					$content = $this->renderLoginForm($this->pi_getLL('message_exception'));
				}
			}
		}

		return $this->pi_wrapInBaseClass($content);
	}

	/**
	 * Determines whether to redirect the user after successful login and sends header information
	 * @param array $conf
	 * @return string
	 */
	public function getRedirectAfterLoginUrl($conf) {

		// Get all GET parameters to keep them for the new, redirected URL
		$params = t3lib_div::_GET($this->prefixId);

		if (isset($params['arPid']) && t3lib_utility_Math::canBeInterpretedAsInteger($params['arPid'])) {
			$redirectLink = $this->cObj->getTypoLink_URL($params['arPid']);
			unset($params['arPid']);
		}

		// Generate the new URL and do a redirect with the help of HTTP
		// Location directive, redirect_url from GET has priority over FlexForm/TS
		if (!empty($redirectLink)) {
			$redirectUrlResult = $redirectLink;
		} else {
			unset($params['id']);
			unset($params[$this->prefixId]['id']);
			$redirectUrlResult = $this->pi_getPageLink($this->successUid, null, $params);
		}

		return $redirectUrlResult;
	}



	/**
	 * TYPO3 user login
	 * @param string $username
	 * @param string $password
	 * @return boolean
	 */
	public function loginUser($username, $password) {
		$loginData = array();
		$loginData['uname'] = $username;
			// TYPO3 4.5
		$loginData['uident'] = t3lib_div::md5int($password);
			// TYPO3 4.7
		$loginData['uident_text'] = t3lib_div::md5int($password);
		$loginData['status'] = 'login';
		
		$GLOBALS['TSFE']->fe_user->checkPid = 0;
		$info = $GLOBALS['TSFE']->fe_user->getAuthInfoArray();
		$user = $GLOBALS['TSFE']->fe_user->fetchUserRecord($info['db_user'], $loginData['uname']);
		$result = $GLOBALS['TSFE']->fe_user->compareUident($user, $loginData);
		if ($result) {
			$GLOBALS['TSFE']->fe_user->createUserSession($user);
			$GLOBALS['TSFE']->fe_user->loginSessionStarted = true;
			$GLOBALS['TSFE']->fe_user->user = $GLOBALS["TSFE"]->fe_user->fetchUserSession();			
			return true;
		} else {
			return false;
		}
	}
	
	
	/**
	 * Renders login form from template
	 * @param string $message
	 * @return string
	 */
	public function renderLoginForm($message) {
		$this->addHeaderParts();
		$form = $this->cObj->getSubpart($this->template, '###LOGIN_FORM###');
		$params = t3lib_div::_GET();
		unset($params[$this->prefixId]);
		unset($params['logintype']);
		$marker['###URL###'] = $this->pi_linkTP_keepPIvars_url($params, $cache=0, $clearAnyway=0, $altPageId=0);
		$marker['###USERNAME###'] = $this->pi_getLL('label_username');
		$marker['###PASSWORD###'] = $this->pi_getLL('label_password');
		$marker['###SUBMIT###'] = $this->pi_getLL('label_submit');
		$marker['###MESSAGE###'] = $this->cObj->stdWrap($message, $this->conf['message.']);
		return $this->cObj->substituteMarkerArray($form, $marker);		
	}
	
	/**
	 * Add header parts for template
	 */
	protected function addHeaderParts() {
		$key = 'EXT:' . $this->extKey . md5($this->template);
		if (!isset($GLOBALS['TSFE']->additionalHeaderData[$key])) {
			$headerParts = $this->cObj->getSubpart($this->template, '###HEADER_PARTS###');
			if ($headerParts) {
				$headerParts = $this->cObj->substituteMarker(
					$headerParts, '###SITE_REL_PATH###',
					t3lib_extMgm::siteRelPath($this->extKey));
				$GLOBALS['TSFE']->additionalHeaderData[$key] = $headerParts;
			}
		}
	}

	/**
	 * Checks an Evento verification code
	 * @param	int		$eventoId
	 * @param	string	$eventoVerificationCode
	 * @return	mixed
	 */
	public function checkAndGetEventoUserInformation($eventoId, $eventoVerificationCode) {

		$params = array();
		$params['sqlSelectStatement'] = 'SELECT * FROM dbo.qryCstPHZ_1900_TempLogins WHERE IDPerson = ' . $eventoId . ' AND VerificationCode = \'' . $eventoVerificationCode . '\'';

		$this->webservice = t3lib_div::makeInstance('tx_t3evento_webserviceClient',$this->conf['webserviceUrl'],$this->conf['webservicePwd']);
		$data = $this->webservice->getData('Read', $params);
		$readResult = $data->ReadResult;

		if (count((array)$readResult->Records) === 0) {
				// Return if there are no results
			return false;
		} else {
				// Return User information if login is valid
			$columns = $readResult->Columns;
			$record = $readResult->Records->Record;
			$eventoUserInformation = tx_t3evento_helper::getColumnValueArray($record,$columns,$readResult);
			return $eventoUserInformation;
		}

	}

	/**
	 * Gets evento web codes of a user
	 * @param	int		$eventoId
	 * @return	mixed
	 */
	protected function getEventoCodes($eventoId) {

		$params = array();
		$params['sqlSelectStatement'] = 'SELECT * FROM dbo.qryCSTPHZ_1900_PersonenWebRollen WHERE IDPerson = ' . (int)$eventoId;

		$this->webservice = t3lib_div::makeInstance('tx_t3evento_webserviceClient', $this->conf['webserviceUrl'], $this->conf['webservicePwd']);
		$data = $this->webservice->getData('Read', $params);
		$readResult = $data->ReadResult;

		if (count((array)$readResult->Records) === 0) {
				// Return false if there are no results
			return false;
		} else {
				// Return all eventoCodes of a user as a one-dimensional array
			$columns = array();
			foreach ($readResult->Columns->Column as $key => $val) {
				$columns[] = $val;
			}

			$eventoCodes = array();
			foreach ($readResult->Records->Record as $recordId => $record) {
				foreach ($columns as $colId => $col) {
						// we only need the IDPersonenTyp in our $eventoCodes array
					if ($col->Name === 'IDCode') {
						$eventoCodes[$recordId] = tx_t3evento_helper::getRecordValueByColumnId($record, $readResult->Columns, $readResult, $colId);
					}
				}
			}

			return $eventoCodes;
		}

	}


}

if (defined('TYPO3_MODE') && $TYPO3_CONF_VARS[TYPO3_MODE]['XCLASS']['ext/phzldap/pi1/class.tx_phzldap_pi1.php'])	{
	include_once($TYPO3_CONF_VARS[TYPO3_MODE]['XCLASS']['ext/phzldap/pi1/class.tx_phzldap_pi1.php']);
}

?>