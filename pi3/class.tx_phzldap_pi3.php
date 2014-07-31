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

use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Plugin 'Login Link Login' for the 'phzldap' extension.
 *
 * @author	Lorenz Ulrich <lorenz.ulrich@phz.ch>
 * @package	TYPO3
 * @subpackage	tx_phzldap
 */
class tx_phzldap_pi3 extends \tx_t3evento_pi5 {
	var $prefixId      = 'tx_phzldap_pi3';		// Same as class name
	var $scriptRelPath = 'pi3/class.tx_phzldap_pi3.php';	// Path to this script relative to the extension dir.
	var $extKey        = 'phzldap';	// The extension key.
	var $template 	   = NULL;

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
		$params = GeneralUtility::_GET();

		// Check configuration
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
			$this->template = GeneralUtility::getURL($templateFile);
		}
		if (empty($this->template)) return $this->pi_getLL('no_template_set');
		
		/*
		 * Evento-Login with login link
		 * As long as the user doesn't have a password, he can log in with a link sent to him
		 */
		$encodedEventoId = GeneralUtility::_GET('evt');
		$eventoVerificationCode = GeneralUtility::_GET('evc');

		if (empty($submit) && !empty($encodedEventoId) && !empty($eventoVerificationCode)) {

			$eventoId = (int)tx_t3evento_helper::decodeString($encodedEventoId);
				// early exit if evt was malformed
			if (empty($eventoId)) {
				$content = $this->renderLoginForm($this->pi_getLL('message_create_or_update_user_from_evento_failed'));
				return $this->pi_wrapInBaseClass($content);
			}
			$eventoVerificationCode = mysql_real_escape_string($eventoVerificationCode);

			$checkAndGetEventoUserInformation = $this->checkAndGetEventoUserInformation($eventoId, $eventoVerificationCode);
			if($checkAndGetEventoUserInformation !== false) {

				$userGroupArray = GeneralUtility::trimExplode(',',$checkAndGetEventoUserInformation['PersonenCode']);
				$userGroupArray['count'] = count($userGroupArray);

				$attributes = array (
					'username' => $checkAndGetEventoUserInformation['IDPerson'],
					'eventoId' => $checkAndGetEventoUserInformation['IDPerson'],
					'password' => GeneralUtility::md5int($checkAndGetEventoUserInformation['VerificationCode']),
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

						if (isset($params['arPid']) && \TYPO3\CMS\Core\Utility\MathUtility::canBeInterpretedAsInteger($params['arPid'])) {
							// user is logged in, but we have a link to an access restricted page --> redirect
							$redirectLink = $this->cObj->getTypoLink_URL($params['arPid']);
							unset($params['arPid']);
							\TYPO3\CMS\Core\Utility\HttpUtility::redirect($redirectLink);
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

		return $this->pi_wrapInBaseClass($content);
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
		$loginData['uident'] = GeneralUtility::md5int($password);
			// TYPO3 4.7
		$loginData['uident_text'] = GeneralUtility::md5int($password);
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
		$form = $this->cObj->getSubpart($this->template, '###LOGIN_FORM###');
		$marker['###MESSAGE###'] = $this->cObj->stdWrap($message, $this->conf['message.']);
		return $this->cObj->substituteMarkerArray($form, $marker);		
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

		$this->webservice = GeneralUtility::makeInstance('tx_t3evento_webserviceClient',$this->conf['webserviceUrl'],$this->conf['webservicePwd']);
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

}

?>