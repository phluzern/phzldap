<?php
/***************************************************************
*  Copyright notice
*
*  (c) 2011 Tamer Erdoğan <tamer.erdogan@univie.ac.at>
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
	
	protected $extConf;
	protected $remoteUser;
	
	/**
	 * Inits some variables
	 *
	 * @return	void
	 */
	function init() {
		$this->extConf = unserialize($GLOBALS['TYPO3_CONF_VARS']['EXT']['extConf'][$this->extKey]);
		if (empty($this->extConf['remoteUser'])) $this->extConf['remoteUser'] = 'REMOTE_USER';
		$this->remoteUser = $_SERVER[$this->extConf['remoteUser']];

		return parent::init();
	}
	
	/**
	 * Initialize authentication service
	 *
	 * @param	string		Subtype of the service which is used to call the service.
	 * @param	array		Submitted login form data
	 * @param	array		Information array. Holds submitted form data etc.
	 * @param	object		Parent object
	 * @return	mixed
	 */
	function initAuth($mode, $loginData, $authInfo, $pObj) {
		if (defined('TYPO3_cliMode')) {
			return parent::initAuth($mode, $loginData, $authInfo, $pObj);
		}
		//t3lib_utility_Debug::debug($authInfo,'authInfo');

		$this->login = $loginData;
		if (empty($this->login['uname'])) {
			$loginData['status'] = 'login';
			parent::initAuth($mode, $loginData, $authInfo, $pObj);
		}
	}
	
	function getUser() {
		$user = false;
		if ($this->login['status']=='login' && $this->isShibbolethLogin() && empty($this->login['uname'])) {
			$user = $this->fetchUserRecord($this->remoteUser);
//t3lib_utility_Debug::debug($user);
			if(!is_array($user) || empty($user)) {
				$this->importFEUser();
			} else {
				$this->updateFEUser();
			}
			$user = $this->fetchUserRecord($this->remoteUser);
		}
		//t3lib_utility_Debug::debug($user, 'userRecord');

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
	function authUser($user) {
		$OK = 100;

		if (($this->authInfo['loginType'] == 'FE') && !empty($this->login['uname'])) {
			$OK = 100;
		} else if ($this->isShibbolethLogin() && !empty($user) && ($this->remoteUser == $user[$this->authInfo['db_user']['username_column']])) {
			$OK = 200;
		}
		
		return $OK;
	}
	
	protected function importFEUser() {
		$this->writelog(255,3,3,2, "Importing user %s!", array($this->remoteUser));

		$user = array('crdate' => time(),
			'tstamp' => time(),
			'pid' => $this->extConf['storagePid'],
			'username' => $this->remoteUser,
			'password' => md5(t3lib_div::shortMD5(uniqid(rand(), true))),
			'email' => $this->getServerVar($this->extConf['mail']),
			'name' => $this->getServerVar($this->extConf['firstName']) . ' ' . $this->getServerVar($this->extConf['lastName']),
			'usergroup' => $this->getFEUserGroups($this->remoteUser),
			);
		$GLOBALS['TYPO3_DB']->exec_INSERTquery($this->authInfo['db_user']['table'], $user);
	}
	
	/**
	 * @return	boolean
	 */
	protected function updateFEUser() {
		$this->writelog(255,3,3,2,	"Updating user %s!", array($this->remoteUser));
		
		$where = "username = '" . $this->remoteUser . "' AND pid = " . $this->extConf['storagePid'];
		$user = array('tstamp' => time(),
			'username' => $this->remoteUser,
			'password' => t3lib_div::shortMD5(uniqid(rand(), true)),
			'email' => $this->getServerVar($this->extConf['mail']),
			'name' => $this->getServerVar($this->extConf['firstName']) . ' ' . $this->getServerVar($this->extConf['lastName']),
			'usergroup' => $this->getFEUserGroups($this->remoteUser),
			);
		$GLOBALS['TYPO3_DB']->exec_UPDATEquery($this->authInfo['db_user']['table'], $where, $user);
	}

	/**
	 * @param string $remoteUser The Remote username (eventoId@phz.ch)
	 *
	 * @return string CSV list of usergroups based on Evento codes
	 */
	protected function getFEUserGroups($remoteUser) {

			// $remoteUser is of Syntax eventoId@phz.ch
		$remoteUserParts = t3lib_div::trimExplode('@', $remoteUser);
		$eventoId = $remoteUserParts[0];

		$eventoCodeArray = $this->getEventoCodes($eventoId);

		$conversionList = $this->extConf['conversionList'];
		$conversionList = t3lib_div::trimExplode(',', $conversionList);
		$conversionArray = array();
		foreach ($conversionList as $conversion) {
			$conversionParts = t3lib_div::trimExplode('=', $conversion);
			$conversionArray[$conversionParts[0]] = $conversionParts[1];
		}

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
			return $this->extConf['defaultGroupId'];
		}

	}
	
	/**
	 * @return	boolean
	 */
	protected function isShibbolethLogin() {
		return isset($_SERVER['AUTH_TYPE']) && ($_SERVER['AUTH_TYPE'] == 'shibboleth') && !empty($this->remoteUser);
	}
	
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
		return null;
	}


	/**
	 * Gets evento web codes of a user
	 * @param	int		$eventoId
	 * @return	mixed
	 */
	protected function getEventoCodes($eventoId) {

		$params = array();
		$params['sqlSelectStatement'] = 'SELECT * FROM dbo.qryCSTPHZ_1900_PersonenWebRollen WHERE IDPerson = ' . (int)$eventoId;

		$this->webservice = t3lib_div::makeInstance('tx_t3evento_webserviceClient', $this->extConf['webserviceUrl'], $this->extConf['webservicePwd']);
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
							print_r($col->Name.' id:'.$colId.' rec:'.$record."\n");
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
