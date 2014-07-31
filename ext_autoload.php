<?php

$extensionPath = \TYPO3\CMS\Core\Utility\ExtensionManagementUtility::extPath('phzldap');
$t3eventoExtensionPath = \TYPO3\CMS\Core\Utility\ExtensionManagementUtility::extPath('t3evento');
return array(
	'tx_phzldap_helper' => $extensionPath . 'includes/class.tx_phzldap_helper.php',
	'tx_t3evento_webserviceClient' => $t3eventoExtensionPath . 'includes/class.tx_t3evento_webservice.php',
	'tx_t3evento_pi5' => $t3eventoExtensionPath . 'pi5/class.tx_t3evento_pi5.php',
);
?>