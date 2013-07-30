<?php

if (!@include __DIR__ . '/../ProxyHandler.class.php') {
    die('Could not load proxy');
}

class CloudFlareSafeProxy extends ProxyHandler
{
    public function setClientHeader($header) {
        if (substr($header, 0, 3) !== 'Cf-') {
            parent::setClientHeader($header);
        }
    }
}

$proxy = new CloudFlareSafeProxy(
    'http://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'],
    'http://internal.example.com'
);

$proxy->execute();
//print_r($proxy->getCurlInfo()); // Uncomment to see request info
$proxy->close();
