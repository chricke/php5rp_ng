<?php

if (!@include __DIR__ . '/../ProxyHandler.class.php') {
    die('Could not load proxy');
}

$proxy = new ProxyHandler(
	'http://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'],
	'http://internal.example.com'
);

$proxy->execute();
//print_r($proxy->getCurlInfo()); // Uncomment to see request info
