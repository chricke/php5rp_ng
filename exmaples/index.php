<?php
	include 'ProxyHandler.class.php';
	$proxy = new ProxyHandler('http://external.example.com','http://internal.example.com');
	$proxy->execute();
	//print_r($proxy->getCurlInfo()); // Uncomment to see request info
?>
