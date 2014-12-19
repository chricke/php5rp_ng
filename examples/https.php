<?php

if (!@include __DIR__ . '/../ProxyHandler.class.php') {
    die('Could not load proxy');
}

$proxy = new ProxyHandler('https://raw.github.com/chricke/php5rp_ng/master/README.md');

// Prevents cURL from hanging on errors
$proxy->setCurlOption(CURLOPT_CONNECTTIMEOUT, 1);
$proxy->setCurlOption(CURLOPT_TIMEOUT, 5);

// This ignores HTTPS certificate verification, libcurl decided not to bundle ca certs anymore.
// Alternatively, specify CURLOPT_CAINFO, or CURLOPT_CAPATH if you have access to your cert(s)
$proxy->setCurlOption(CURLOPT_SSL_VERIFYPEER, false);

// Check for a success
if ($proxy->execute()) {
    //print_r($proxy->getCurlInfo()); // Uncomment to see request info
} else {
    echo $proxy->getCurlError();
}

$proxy->close();
