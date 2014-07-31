<?php
/***************************************************************
*  Copyright notice
*
*  (c) 2009 Frederik Schaller <frederik.schaller@educo.ch>
*  (c) 2010 Lorenz Ulrich <lorenz.ulrich@phz.ch> - StartTLS modifications
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

class tx_phzldap_helper {
	
	/**
	 * Connects to the LDAP server
	 * @param string 	$hostname
	 * @param string	$port
	 * @return resource or false if no connection was possible
	 */
	public static function connect($hostname, $port) {
		return ldap_connect($hostname, $port);
	}

	/**
	 * Gets DN (Distinguished Name) by a username and an authenticated connection
	 * @param resource	$connection
	 * @param string 	$basedn
	 * @param string	$username
	 * @return string or false if username was not found
	 */
	public static function getDn($connection, $basedn, $username, $authUser, $authPwd) {
		if ($connection != null and !empty($basedn) and !empty($username)) {
			ldap_start_tls($connection);
			ldap_set_option($connection, LDAP_OPT_PROTOCOL_VERSION, 3);
			ldap_set_option($connection, LDAP_OPT_REFERRALS, 0);

			$bind = ldap_bind($connection, $authUser, $authPwd);
			if ($bind) {
				$attrs = array('dn'); 
				$result = ldap_search($connection, $basedn, '(uid=' . $username . ')', $attrs);
				if ($result) {
					$values = ldap_get_entries($connection, $result);
					if ($values != null) return $values[0]['dn'];
				}
			}
		}
		return false;
	}
	
	/**
	 * Returns user LDAP attributes
	 * @param resource	$connection
	 * @param string	$dn
	 * @param string	$username
	 * @param string 	$password
	 * @return array or false, if password is not correct
	 */
	public static function getAttributes($connection, $dn, $username, $password) {
		if ($connection != null and !empty($dn) and !empty($username) and !empty($password)) {
			ldap_start_tls($connection);
			ldap_set_option($connection, LDAP_OPT_PROTOCOL_VERSION, 3);
			ldap_set_option($connection, LDAP_OPT_REFERRALS, 0);

			$bind = ldap_bind($connection, $dn, $password);
			if ($bind) {
				$attrs = array('dn', 'uid', 'mail', 'givenName', 'cn', 'sn', 'givenname', 'eventoID', 'eventoCodes', 'eventoHomeAddressStreet1', 'eventoHomeAddressStreet2', 'eventoHomeAddressPostalCode', 'eventoHomeAddressCity');
				$result = ldap_search($connection, $dn, '(uid=' . $username . ')', $attrs);
				if ($result) {
					$values = ldap_get_entries($connection, $result);
					if ($values != null) {
						$attributes = array();
						$attributes['cn'] = $values[0]['cn'][0];
						$attributes['mail'] = $values[0]['mail'][0];
						$attributes['first_name'] = $values[0]['givenname'][0];
						$attributes['last_name'] = $values[0]['sn'][0];
						$attributes['address1'] = $values[0]['eventohomeaddressstreet1'][0];
						$attributes['address2'] = $values[0]['eventohomeaddressstreet2'][0];
						$attributes['zip'] = $values[0]['eventohomeaddresspostalcode'][0];
						$attributes['city'] = $values[0]['eventohomeaddresscity'][0];
						$attributes['eventoId'] = $values[0]['eventoid'][0];
						//$attributes['eventoCodes'] = $values[0]['eventocodes'];
						return $attributes;
					}
				}				
			}
		}
		return false;
	}
	
	/**
	 * Create or updates the fe_users record based on the LDAP values
	 * @param string	$password
	 * @param array		$attributes
	 * @param array		$conf
	 * @return ressource or false if update or creation failed
	 */
	public static function createOrUpdateFeUser($password, $attributes, $conf) {
		$username = $attributes['eventoId'];
		
		// Check if frontend user exists in T3
		$sql = $GLOBALS['TYPO3_DB']->exec_SELECTquery('*', 'fe_users', 'disable = 0 AND deleted = 0 AND username = ' . $GLOBALS['TYPO3_DB']->fullQuoteStr($username, 'fe_users'));
		if ($GLOBALS['TYPO3_DB']->sql_fetch_assoc($sql) == true)
        {
			$values = array(
	     		'password'    => \TYPO3\CMS\Core\Utility\GeneralUtility::md5int($password),
				'name'        => $attributes['cn'],
				'first_name'        => $attributes['first_name'],
				'last_name'        => $attributes['last_name'],
				'email'       => $attributes['mail'],
				'zip'        => $attributes['zip'],
				'city'        => $attributes['city'],
				'usergroup'	  => tx_phzldap_helper::mapUserGroups($attributes,  $conf['conversion.'], $conf['default_groupid'])
			);
			isset($attributes['address2']) ? $values['address'] .= $attributes['address1'] . "\n" . $attributes['address2'] : $values['address'] = $attributes['address1'];
        	return $GLOBALS['TYPO3_DB']->exec_UPDATEquery('fe_users', 'username = ' . $GLOBALS['TYPO3_DB']->fullQuoteStr($username, 'fe_users'), $values);
        } else {
			$values = array(
				'username'    => $username,
	     		'password'    => \TYPO3\CMS\Core\Utility\GeneralUtility::md5int($password),
				'name'        => $attributes['cn'],
				'first_name'        => $attributes['first_name'],
				'last_name'        => $attributes['last_name'],
				'address'        => $attributes['address'],
				'zip'        => $attributes['zip'],
				'city'        => $attributes['city'],
				'email'       => $attributes['mail'],
				'pid'         => $conf['folder_uid'],
				'cruser_id'   => $conf['cruser_id'],
				'usergroup'	  => tx_phzldap_helper::mapUserGroups($attributes, $conf['conversion.'], $conf['default_groupid']),
				'tstamp'      => time(),
				'crdate'      => time()
			);
			isset($attributes['address2']) ? $values['address'] .= $attributes['address1'] . "\n" . $attributes['address2'] : $values['address'] = $attributes['address1'];
        	return $GLOBALS['TYPO3_DB']->exec_INSERTquery('fe_users', $values);
        }		
       	return false;
	}
	
	/**
	 * Generates a comma seperated list for the fe_users usergroup field
	 * @param array		$attributes
	 * @param array		$conversion	TypoScript conversion array, mapping Evento codes to FE user groups
	 * @return string
	 */
	public static function mapUserGroups($attributes, $conversion, $defaultId) {
		$groups = array();
		$count = $attributes['eventoCodes']['count'];
		
		for ($i = 0; $i < $count; $i++) {
			if (isset($conversion[$attributes['eventoCodes'][$i]])) {
				$value = $conversion[$attributes['eventoCodes'][$i]];
				if (!in_array($value, $groups)) {
					$groups[] = $value; 
				}
			}
		}
		if (count($groups) > 0) {
			return implode(",", $groups);
		} else {
			return $defaultId;
		}
	}
	
	/**
	 * Closes LDAP connection
	 */
	public static function close() {
		ldap_close();
	}
}

?>