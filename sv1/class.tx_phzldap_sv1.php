<?php
/***************************************************************
*  Copyright notice
*
*  (c) 2011 Tamer Erdoğan <tamer.erdogan@univie.ac.at>
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

require_once(t3lib_extMgm::extPath('t3evento') . 'pi5/class.tx_t3evento_pi5.php');

/**
 * Service "Shibboleth-Authentication" for the "tx_phzldap" extension.
 *
 * @author	Tamer Erdoğan <tamer.erdogan@univie.ac.at>
 * @author	Lorenz Ulrich <lorenz.ulrich@phz.ch>
 * @package	TYPO3
 * @subpackage	tx_phzldap
 */
class tx_phzldap_sv1 extends tx_sv_authbase {
	public $prefixId = 'tx_phzldap_sv1';		// Same as class name
	public $scriptRelPath = 'sv1/class.tx_phzldap_sv1.php';	// Path to this script relative to the extension dir.
	public $extKey = 'phzldap';	// The extension key.
	public $pObj;

	/** @var array TypoScript settings */
	protected $settings;
	/** @var string User name identifier in the Shibboleth session */
	protected $remoteUser;
	
	/**
	 * Inits some variables
	 *
	 * @return	void
	 */
	public function init() {
		return parent::init();
	}
	
	/**
	 * Initialize authentication service
	 *
	 * @param	string		$mode Subtype of the service which is used to call the service.
	 * @param	array		$loginData Submitted login form data
	 * @param	array		$authInfo Information array. Holds submitted form data etc.
	 * @param	object		$pObj Parent object
	 * @return	mixed
	 */
	public function initAuth($mode, $loginData, $authInfo, $pObj) {
		if (defined('TYPO3_cliMode')) {
			return parent::initAuth($mode, $loginData, $authInfo, $pObj);
		}

		$this->login = $loginData;

		// if no PID is set, this is not a login attempt
		$pid = t3lib_div::_GP('pid');
		if (empty($this->login['uname']) && !empty($pid)) {

			/* Initialize TypoScript setup in TSFE */
			$GLOBALS['TSFE']->determineId();
			$GLOBALS['TSFE']->getCompressedTCarray();
			$GLOBALS['TSFE']->initTemplate();
			$GLOBALS['TSFE']->getConfigArray();

			/** @var $objectManager Tx_Extbase_Object_ObjectManager */
			$objectManager = t3lib_div::makeInstance('Tx_Extbase_Object_ObjectManager');
			/** @var $configurationManager Tx_Extbase_Configuration_ConfigurationManagerInterface */
			$configurationManager = $objectManager->get('Tx_Extbase_Configuration_ConfigurationManagerInterface');
			$this->settings = $configurationManager->getConfiguration(
				Tx_Extbase_Configuration_ConfigurationManagerInterface::CONFIGURATION_TYPE_SETTINGS,
				'phzldap',
				'pi2'
			);


			/* If remote user identifier is not defined, switch to standard */
			if (empty($this->settings['remoteUser'])) {
				$this->settings['remoteUser'] = 'REMOTE_USER';
			}
			$this->remoteUser = $_SERVER[$this->settings['remoteUser']];

			$loginData['status'] = 'login';
			parent::initAuth($mode, $loginData, $authInfo, $pObj);
		}
	}

	/**
	 * @return bool|mixed
	 */
	public function getUser() {

		$user = FALSE;

		if ($this->login['status']=='login' && $this->isShibbolethLogin() && empty($this->login['uname'])) {

			// $remoteUser is of Syntax eventoId@phz.ch
			$remoteUserParts = t3lib_div::trimExplode('@', $this->remoteUser);
			$eventoId = $remoteUserParts[0];

			$user = $this->fetchUserRecord($eventoId);
			if (!is_array($user) || empty($user)) {
				$this->importFEUser($eventoId);
			} else {
				$this->updateFEUser($user['uid'], $eventoId);
			}
			$user = $this->fetchUserRecord($eventoId);

		}

		return $user;
	}
	
	/**
	 * Authenticate a user (Check various conditions for the user that might invalidate its authentication, eg. password match, domain, IP, etc.)
	 *
	 * Will return one of following authentication status codes:
	 *  - 0 - authentication failure
	 *  - 100 - just go on. User is not authenticated but there is still no reason to stop
	 *  - 200 - the service was able to authenticate the user
	 *
	 * @param	array		Array containing FE user data of the logged user.
	 * @return	integer		authentication statuscode, one of 0,100 and 200
	 */
	public function authUser($user) {
		$OK = 100;

		// $remoteUser is of Syntax eventoId@phz.ch
		$remoteUserParts = t3lib_div::trimExplode('@', $this->remoteUser);
		$eventoId = $remoteUserParts[0];

		if (($this->authInfo['loginType'] == 'FE') && !empty($this->login['uname'])) {
			$OK = 100;
		} else if ($this->isShibbolethLogin() && !empty($user) && ($eventoId == $user[$this->authInfo['db_user']['username_column']])) {
			$OK = 200;
		}
		
		return $OK;
	}

	/**
	 *
	 */
	protected function importFEUser($eventoId) {

		$this->writelog(255,3,3,2, "Importing user %s!", array($eventoId));

		$user = array('crdate' => time(),
			'tstamp' => time(),
			'pid' => $this->settings['storagePid'],
			'username' => $eventoId,
			'password' => md5(t3lib_div::shortMD5(uniqid(rand(), true))),
			'email' => $this->getServerVar($this->settings['mail']),
			'usergroup' => $this->getFEUserGroups($eventoId),
			'first_name' => $this->getServerVar($this->settings['firstName']),
			'last_name' => $this->getServerVar($this->settings['lastName']),
			'name' => $this->getServerVar($this->settings['firstName']) . ' ' . $this->getServerVar($this->settings['lastName']),
			);
		$GLOBALS['TYPO3_DB']->exec_INSERTquery($this->authInfo['db_user']['table'], $user);
	}
	
	/**
	 * Update an existing FE user
	 *
	 * @param string $userId
	 * @param string $eventoId
	 * @return void
	 */
	protected function updateFEUser($userId, $eventoId) {
		$this->writelog(255,3,3,2,	"Updating user %s!", array($eventoId));

		$where = "uid = " . $userId;
		$user = array(
			'tstamp' => time(),
			'username' => $eventoId,
			'password' => t3lib_div::shortMD5(uniqid(rand(), true)),
			'email' => $this->getServerVar($this->settings['mail']),
			'first_name' => $this->getServerVar($this->settings['firstName']),
			'last_name' => $this->getServerVar($this->settings['lastName']),
			'name' => $this->getServerVar($this->settings['firstName']) . ' ' . $this->getServerVar($this->settings['lastName']),
			'usergroup' => $this->getFEUserGroups($eventoId),
		);
		$GLOBALS['TYPO3_DB']->exec_UPDATEquery($this->authInfo['db_user']['table'], $where, $user);
	}

	/**
	 * @param string $remoteUser The Remote username (eventoId@phz.ch)
	 *
	 * @return string CSV list of usergroups based on Evento codes
	 */
	protected function getFEUserGroups($eventoId) {

		$eventoCodeArray = $this->getEventoCodes($eventoId);

		// array matching Evento codes to FE user groups, in format eventoCode = feUserGroup
		$conversionArray = $this->settings['conversion'];

		$groups = array();
		for ($i = 0; $i < count($eventoCodeArray); $i++) {
			if (isset($conversionArray[$eventoCodeArray[$i]])) {
				$value = $conversionArray[$eventoCodeArray[$i]];
				if (!in_array($value, $groups)) {
					$groups[] = $value;
				}
			}
		}

		if (count($groups) > 0) {
			return implode(",", $groups);
		} else {
			return $this->settings['defaultGroupId'];
		}

	}

	/**
	 * Check if the authentication type is Shibboleth
	 *
	 * @return	boolean
	 */
	protected function isShibbolethLogin() {
		return isset($_SERVER['AUTH_TYPE']) && ($_SERVER['AUTH_TYPE'] == 'shibboleth') && !empty($this->remoteUser);
	}

	/**
	 * @param $key
	 * @param string $prefix
	 * @return null
	 */
	protected function getServerVar($key, $prefix='REDIRECT_') {
		if (isset($_SERVER[$key])) {
			return $_SERVER[$key];
		} else if (isset($_SERVER[$prefix.$key])) {
			return $_SERVER[$prefix.$key];
		} else {
			foreach($_SERVER as $k=>$v) {
				if ($key == str_replace($prefix, '', $k)) {
					return $v;
				}
			}
		}
		return NULL;
	}

	/**
	 * Gets Evento web codes of a user
	 *
	 * @param	int		$eventoId
	 * @return	mixed
	 */
	protected function getEventoCodes($eventoId) {

		$params = array();
		$params['sqlSelectStatement'] = 'SELECT * FROM dbo.qryCSTPHZ_1900_PersonenWebRollen WHERE IDPerson = ' . (int)$eventoId;

		/** @var $webservice tx_t3evento_webserviceClient */
		$this->webservice = t3lib_div::makeInstance('tx_t3evento_webserviceClient', $this->settings['webserviceUrl'], $this->settings['webservicePwd']);
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
			if(is_array($readResult->Records->Record)){
				foreach ($readResult->Records->Record as $recordId => $record) {
					foreach ($columns as $colId => $col) {
							// we only need the IDPersonenTyp in our $eventoCodes array
							//print_r($col->Name.' id:'.$colId.' rec:'.$record."\n");
						if ($col->Name === 'IDCode') {
							$eventoCodes[$recordId] = tx_t3evento_helper::getRecordValueByColumnId($record, $readResult->Columns, $readResult, $colId);
						}
					}
				}
			}
			else {
				// records enthält nur einen eintrag (ist kein array)
				$record = $readResult->Records->Record;

				foreach ($columns as $colId => $col) {
					// we only need the IDPersonenTyp in our $eventoCodes array
					if ($col->Name === 'IDCode') {
						$eventoCodes[0] = tx_t3evento_helper::getRecordValueByColumnId($record, $readResult->Columns, $readResult, $colId);
					}
				}
			}
			return $eventoCodes;
		}

	}

}

if (defined('TYPO3_MODE') && $TYPO3_CONF_VARS[TYPO3_MODE]['XCLASS']['ext/phzldap/sv1/class.tx_phzldap_sv1.php'])	{
	include_once($TYPO3_CONF_VARS[TYPO3_MODE]['XCLASS']['ext/phzldap/sv1/class.tx_phzldap_sv1.php']);
}

?>
