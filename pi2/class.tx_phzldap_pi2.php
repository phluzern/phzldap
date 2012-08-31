<?php
/***************************************************************
*  Copyright notice
*
*  (c) 2012 Lorenz Ulrich <lorenz.ulrich@phz.ch>
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

require_once(PATH_tslib . 'class.tslib_pibase.php');
require_once(t3lib_extMgm::extPath('phzldap') . 'includes/class.tx_phzldap_helper.php');
require_once(t3lib_extMgm::extPath('t3evento') . 'pi5/class.tx_t3evento_pi5.php');


/**
 * Plugin 'Shibboleth Login' for the 'phzldap' extension.
 *
 * @author	Lorenz Ulrich <lorenz.ulrich@phz.ch>
 * @package	TYPO3
 * @subpackage	tx_phzldap
 */
class tx_phzldap_pi2 extends tx_t3evento_pi5 {
	var $prefixId      = 'tx_phzldap_pi2';		// Same as class name
	var $scriptRelPath = 'pi2/class.tx_phzldap_pi2.php';	// Path to this script relative to the extension dir.
	var $extKey        = 'phzldap';	// The extension key.
	var $template 	   = null;

	protected $userIsLoggedIn = NULL;
	protected $successRedirectUrl = '';

	/**
	 * The main method of the PlugIn
	 *
	 * @param	string		$content: The PlugIn content
	 * @param	array		$conf: The PlugIn configuration
	 * @return	string		The content that is displayed on the website
	 */
	function main($content, $conf) {
		$this->conf = $conf;
		$this->pi_setPiVarDefaults();
		$this->pi_loadLL();
		$this->pi_USER_INT_obj = 1;	// Configuring so caching is not expected. This value means that no cHash params are ever set. We do this, because it's a USER_INT object!
		$this->pi_initPIflexForm();
		$content = '';

		// Check configuration
		if (!isset($conf['folder_uid'])) return $this->pi_getLL('no_folder_uid_set');
		// TODO check for shibboleth typoscript
		// shibbolethLoginHandler = /Shibboleth.sso/Login?entityID=https%3A%2F%2Faai-demo-idp.switch.ch%2Fidp%2Fshibboleth
		// shibbolethLogoutHandler = /Shibboleth.sso/Logout

			// Get page uid for redirection
			// Flexform value has priority, after that the TS value is beeing used
		$templateFile = $this->pi_getFFvalue($this->cObj->data['pi_flexform'], 'templateFile', 'general');
		if (empty($templateFile)) {
			$this->template = $this->cObj->fileResource($this->conf['templateFile']);
		} else {
			$this->template = t3lib_div::getURL($templateFile);
		}
		if (empty($this->template)) return 'no template set';

			// success uid from FlexForm or TypoScript
		$successUid = (int) $this->pi_getFFvalue($this->cObj->data['pi_flexform'], 'successUid', 'general');
		if (!isset($this->successUid) or !t3lib_utility_Math::canBeInterpretedAsInteger($this->successUid)) {
			$successUid = (int) $conf['success_uid'];
		}

			// generate redirect url
		if (!empty($successUid)) {
			// we need to redirect to a defined page
			// TODO
		} else {
				// we need to reload the current page
			$params = t3lib_div::_GET();
			unset($params['logintype']);
			unset($params[$this->prefixId]);
			$this->successRedirectUrl = $this->pi_linkTP_keepPIvars_url($params, $cache=0, $clearAnyway=0, $altPageId=0);
		}

		//t3lib_utility_Debug::debug($conf, 'conf');
		//t3lib_utility_Debug::debug($_SERVER, 'SERVER');

			// is the user logged in?
		$this->userIsLoggedIn = $GLOBALS['TSFE']->loginUser;

		//t3lib_utility_Debug::debug($this->userIsLoggedIn, 'userIsLoggedIn');


		if (!$this->userIsLoggedIn) {
			$content = $this->renderLoginLink('');
		} else {
			$content = $this->renderLoginSuccess('');
		}

		return $content;
	}

	/**
	 * Renders login link from template
	 * @param string $message
	 * @return string
	 */
	public function renderLoginLink($message) {
		$this->addHeaderParts();
		$form = $this->cObj->getSubpart($this->template, '###LOGIN_LINK###');

		$feUserPid = '';
		$feUserPid .= !stristr($this->successRedirectUrl, '?') ? '?' : '&';
		$feUserPid .= 'pid=' . (int)$this->conf['folder_uid'];
		$loginHandlerUrl = $this->conf['sslHost'] . $this->conf['shibbolethLoginHandler'] . '&target=' . urlencode($this->successRedirectUrl . $feUserPid);
		//t3lib_utility_Debug::debug($loginHandlerUrl,'loginHandlerUrl');
		$marker['###LOGINHANDLERURL###'] = $loginHandlerUrl;

		return $this->cObj->substituteMarkerArray($form, $marker);		
	}

	/**
	 * Renders login link from template
	 * @param string $message
	 * @return string
	 */
	public function renderLoginSuccess($message) {
		$this->addHeaderParts();
		$form = $this->cObj->getSubpart($this->template, '###LOGIN_SUCCESS###');

		$displayName = $GLOBALS['TSFE']->fe_user->user['name'];
		$marker['###DISPLAY_NAME###'] = $displayName;

		$this->conf['linkConfig.']['parameter'] = $GLOBALS['TSFE']->id;
		$this->conf['linkConfig.']['additionalParams'] = '&logintype=logout';
		$marker['###LOGOUT_URL###'] = htmlspecialchars($this->cObj->typolink_url($this->conf['linkConfig.']));;

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

}

if (defined('TYPO3_MODE') && $TYPO3_CONF_VARS[TYPO3_MODE]['XCLASS']['ext/phzldap/pi2/class.tx_phzldap_pi2.php'])	{
	include_once($TYPO3_CONF_VARS[TYPO3_MODE]['XCLASS']['ext/phzldap/pi2/class.tx_phzldap_pi2.php']);
}

?>