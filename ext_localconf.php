<?php
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

if (!defined ('TYPO3_MODE')) {
 	die ('Access denied.');
}

ExtensionManagementUtility::addPItoST43($_EXTKEY, 'pi1/class.tx_phzldap_pi1.php', '_pi1', 'list_type', 0);
ExtensionManagementUtility::addPItoST43($_EXTKEY, 'pi2/class.tx_phzldap_pi2.php', '_pi2', 'list_type', 0);
ExtensionManagementUtility::addPItoST43($_EXTKEY, 'pi3/class.tx_phzldap_pi3.php', '_pi3', 'list_type', 0);

$GLOBALS['TYPO3_CONF_VARS']['SVCONF']['auth']['setup']['FE_fetchUserIfNoSession'] = TRUE;;

ExtensionManagementUtility::addService($_EXTKEY, 'auth',  'tx_phzldap_sv1',
	array(
		'title' => 'Shibboleth-Authentication',
		'description' => 'Authentication service for Shibboleth (FE)',

		'subtype' => 'getUserFE,authUserFE',

		'available' => TRUE,
		'priority' => 100,
		'quality' => 50,

		'os' => '',
		'exec' => '',

		'classFile' => ExtensionManagementUtility::extPath($_EXTKEY) . 'sv1/class.tx_phzldap_sv1.php',
		'className' => 'tx_phzldap_sv1',
	)
);

?>