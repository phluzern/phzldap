<?php
if (!defined ('TYPO3_MODE')) {
 	die ('Access denied.');
}

t3lib_extMgm::addPItoST43($_EXTKEY, 'pi1/class.tx_phzldap_pi1.php', '_pi1', 'list_type', 0);
t3lib_extMgm::addPItoST43($_EXTKEY, 'pi2/class.tx_phzldap_pi2.php', '_pi2', 'list_type', 0);

$GLOBALS['TYPO3_CONF_VARS']['SVCONF']['auth']['setup']['FE_fetchUserIfNoSession'] = TRUE;;

t3lib_extMgm::addService($_EXTKEY, 'auth',  'tx_phzldap_sv1',
	array(
		'title' => 'Shibboleth-Authentication',
		'description' => 'Authentication service for Shibboleth (FE)',

		'subtype' => 'getUserFE,authUserFE',

		'available' => TRUE,
		'priority' => 100,
		'quality' => 50,

		'os' => '',
		'exec' => '',

		'classFile' => t3lib_extMgm::extPath($_EXTKEY) . 'sv1/class.tx_phzldap_sv1.php',
		'className' => 'tx_phzldap_sv1',
	)
);

?>