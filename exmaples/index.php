<?php

if (!@include __DIR__ . '/../ProxyHandler.class.php') {
    die('Could not load proxy');
}

$proxy = new ProxyHandler('http://internal.example.org');
$proxy->execute();
//print_r($proxy->getCurlInfo()); // Uncomment to see request info
$proxy->close();
