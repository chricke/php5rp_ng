<?php

if (!@include __DIR__ . '/../ProxyHandler.class.php') {
    die('Could not load proxy');
}

$proxy = new ProxyHandler('http://internal.example.org');

// Check for a success
if ($proxy->execute()) {
    //print_r($proxy->getCurlInfo()); // Uncomment to see request info
} else {
    echo $proxy->getCurlError();
}

$proxy->close();
