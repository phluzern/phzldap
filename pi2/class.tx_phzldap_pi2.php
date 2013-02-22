<?php
/***************************************************************
*  Copyright notice
*
*  (c) 2012-2013 Lorenz Ulrich <lorenz.ulrich@phz.ch>
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
		if (!isset($conf['shibbolethLoginHandler'])) return 'TypoScript: shibbolethLoginHandler not set';
		if (!isset($conf['shibbolethLogoutHandler'])) return 'TypoScript: shibbolethLogoutHandler not set';
		if (!isset($conf['shibbolethLoginHandler'])) return 'TypoScript: shibbolethLogoutHandler not set';
		if (!isset($conf['logoutTargetPid'])) return 'TypoScript: logoutTargetPid not set';

		// Flexform value has priority, after that the TS value is beeing used
		$templateFile = $this->pi_getFFvalue($this->cObj->data['pi_flexform'], 'templateFile', 'general');
		if (empty($templateFile)) {
			$this->template = $this->cObj->fileResource($this->conf['templateFile']);
		} else {
			$this->template = t3lib_div::getURL($templateFile);
		}
		if (empty($this->template)) return 'no template set';

		// get target URL for login
		$this->setTargetUrl($conf);

		// URL parameters
		$params = t3lib_div::_GET();

		// is the user logged in?
		$this->userIsLoggedIn = $GLOBALS['TSFE']->loginUser;

		// display login link or login success depending on the login status
		if (!$this->userIsLoggedIn) {
			// user is not logged in, display login link
			$content = $this->renderLoginLink('');
		} elseif (isset($params['tx_phzldap_pi2']['arPid']) && t3lib_utility_Math::canBeInterpretedAsInteger($params['tx_phzldap_pi2']['arPid'])) {
			// user is logged in, but we have a link to an access restricted page --> redirect
			$redirectLink = $this->cObj->getTypoLink_URL($params['tx_phzldap_pi2']['arPid']);
			unset($params['tx_phzldap_pi2']['arPid']);
			t3lib_utility_Http::redirect($redirectLink);
		} else {
			// user is logged in and we don't need to redirect
			$content = $this->renderLoginSuccess('');
		}

		return $content;
	}

	/**
	 * Determines whether to redirect the user after successful login and sends header information
	 *
	 * @param array $conf
	 * @return string
	 */
	public function setTargetUrl($conf) {

		// success uid from FlexForm or TypoScript
		// ev. TODO allow settings successUid from FlexForm and/or TypoScript
		/*$successUid = (int) $this->pi_getFFvalue($this->cObj->data['pi_flexform'], 'successUid', 'general');
		if (!isset($this->successUid) or !t3lib_utility_Math::canBeInterpretedAsInteger($this->successUid)) {
			$successUid = (int) $conf['success_uid'];
		}*/

		// all GET parameters (not only the namespaced ones)
		$params = t3lib_div::_GET();

		if (isset($params['redirectUid']) && t3lib_utility_Math::canBeInterpretedAsInteger($params['redirectUid'])) {
			// A redirect UID is set, so we must redirect to this page
			$redirectUid = $params['redirectUid'];
			unset($params['logintype']);
			unset($params[$this->prefixId]);
			unset($params['id']);
			unset($params['redirectUid']);
			$this->successRedirectUrl = $this->pi_linkTP_keepPIvars_url($params, $cache=0, $clearAnyway=0, $redirectUid);
		} else {
			// we need to reload the current page
			unset($params['logintype']);
			unset($params[$this->prefixId]);
			$this->successRedirectUrl = $this->pi_linkTP_keepPIvars_url($params, $cache=0, $clearAnyway=0, $altPageId=0);
		}

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

		$this->conf['linkConfig.']['parameter'] = $this->conf['logoutTargetPid'];
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

}

if (defined('TYPO3_MODE') && $TYPO3_CONF_VARS[TYPO3_MODE]['XCLASS']['ext/phzldap/pi2/class.tx_phzldap_pi2.php'])	{
	include_once($TYPO3_CONF_VARS[TYPO3_MODE]['XCLASS']['ext/phzldap/pi2/class.tx_phzldap_pi2.php']);
}

?>