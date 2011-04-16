<?php
	include 'ProxyHandler.class.php';
	$proxy = new ProxyHandler('http://external.domain.com'.$_SERVER["REQUEST_URI"],'http://internal.domain.com'.$_SERVER["REQUEST_URI"]);
	$proxy->execute();
	// print_r($proxy->getCurlInfo()); // Uncomment to see request info
?>
