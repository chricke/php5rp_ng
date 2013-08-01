<?php

class ProxyHandler
{
    const RN = "\r\n";

    private $_chunked = false;
    private $_curlHandle;
    private $_cacheControl = false;
    private $_pragma = false;
    private $_clientHeaders = array();

    function __construct($proxy_url, $base_uri = null)
    {
        // Strip the trailing '/' from the URL so they are the same.
        $translated_url = rtrim($proxy_url, '/');

        if ($base_uri === null && isset($_SERVER['REDIRECT_URL'])) {
            $base_uri = dirname($_SERVER['REDIRECT_URL']);
        }

        // Parse all the parameters for the URL
        if (isset($_SERVER['REQUEST_URI'])) {
            $request_uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
            if ($base_uri && strpos($request_uri, $base_uri) === 0) {
                $request_uri = substr($request_uri, strlen($base_uri));
            }
            $translated_url .= $request_uri;
        }
        else {
            // Add the '/' at the end
            $translated_url .= '/';
        }

        if ($_SERVER['QUERY_STRING'] !== '') {
            $translated_url .= "?{$_SERVER['QUERY_STRING']}";
        }

        $this->_curlHandle = curl_init($translated_url);

        // Set various options
        $this->setCurlOption(CURLOPT_FOLLOWLOCATION, true);
        $this->setCurlOption(CURLOPT_RETURNTRANSFER, true);
        $this->setCurlOption(CURLOPT_BINARYTRANSFER, true); // For images, etc.
        $this->setCurlOption(CURLOPT_USERAGENT, $_SERVER['HTTP_USER_AGENT']);
        $this->setCurlOption(CURLOPT_WRITEFUNCTION, array($this, 'readResponse'));
        $this->setCurlOption(CURLOPT_HEADERFUNCTION, array($this, 'readHeaders'));

        $method = $_SERVER['REQUEST_METHOD'];
        if ($method !== 'GET') { // Default curl request method is 'GET'
            // Set the request method
            $this->setCurlOption(CURLOPT_CUSTOMREQUEST, $method);

            switch($method) {
                case 'POST':
                    // Encode and form the post data
                    if (!isset($HTTP_RAW_POST_DATA)) {
                        $HTTP_RAW_POST_DATA = file_get_contents('php://input');
                    }
                    $this->setCurlOption(CURLOPT_POSTFIELDS, $HTTP_RAW_POST_DATA);
                    break;
                case 'PUT':
                    // Set the request method.
                    $this->setCurlOption(CURLOPT_UPLOAD, 1);
                    // PUT data comes in on the stdin stream.
                    $putdata = fopen('php://input', 'r');
                    $this->setCurlOption(CURLOPT_READDATA, $putdata);
                    // TODO: set CURLOPT_INFILESIZE to the value of Content-Length.
                    break;
            }
        }

        // Handle the client headers.
        $this->handleClientHeaders();
    }

    public function setClientHeader($header)
    {
        $this->_clientHeaders[] = $header;
    }

    // Executes the proxy.
    public function execute()
    {
        $this->setCurlOption(CURLOPT_HTTPHEADER, $this->_clientHeaders);
        curl_exec($this->_curlHandle);
    }

    public function close()
    {
        if ($this->_chunked) {
            echo '0' . self::RN . self::RN;
        }
        curl_close($this->_curlHandle);
    }

    // Get the information about the request.
    // Should not be called before exec.
    public function getCurlInfo()
    {
        return curl_getinfo($this->_curlHandle);
    }

    // Sets a curl option.
    public function setCurlOption($option, $value)
    {
        curl_setopt($this->_curlHandle, $option, $value);
    }

    protected function readHeaders(&$cu, $string)
    {
        $length = strlen($string);

        if (preg_match(',^Cache-Control:,', $string)) {
            $this->_cacheControl = true;
        }
        elseif (preg_match(',^Pragma:,', $string)) {
            $this->_pragma = true;
        }
        elseif (preg_match(',^Transfer-Encoding:,', $string)) {
            $this->_chunked = strpos($string, 'chunked') !== false;
        }

        if ($string !== self::RN) {
            header(rtrim($string));
        }

        return $length;
    }

    protected function handleClientHeaders()
    {
        $headers = $this->_getRequestHeaders();
        $xForwardedFor = array();

        foreach ($headers as $header => $value) {
            switch($header) {
                case 'Host':
                case 'X-Real-IP':
                    break;
                case 'X-Forwarded-For':
                    $xForwardedFor[] = $value;
                    break;
                default:
                    $this->setClientHeader(sprintf('%s: %s', $header, $value));
                    break;
            }
        }

        $xForwardedFor[] = $_SERVER['REMOTE_ADDR'];
        $this->setClientHeader('X-Forwarded-For: ' . implode(',', $xForwardedFor));
        $this->setClientHeader('X-Real-IP: ' . $xForwardedFor[0]);
    }

    protected function readResponse(&$cu, $string)
    {
        static $headersParsed = false;

        // Clear the Cache-Control and Pragma headers
        // if they aren't passed from the proxy application.
        if ($headersParsed === false) {
            if (!$this->_cacheControl) {
                $this->_removeHeader('Cache-Control');
            }
            if (!$this->_pragma) {
                $this->_removeHeader('Pragma');
            }
            $headersParsed = true;
        }

        $length = strlen($string);
        if ($this->_chunked) {
            echo dechex($length) . self::RN . $string . self::RN;
        } else {
            echo $string;
        }
        return $length;
    }

    private function _getRequestHeaders()
    {
        if (function_exists('apache_request_headers')) { // If apache_request_headers() exists
            if ($headers = apache_request_headers()) { // And works...
                return $headers; // Use it
            }
        }

        $headers = array();
        foreach (array_keys($_SERVER) as $skey) {
            if (substr($skey, 0, 5) == 'HTTP_') {
                $headername = strtolower(substr($skey, 5, strlen($skey)));
                $headername = str_replace(' ', '-', ucwords(str_replace('_', ' ', $headername)));
                $headers[$headername] = $_SERVER[$skey];
            }
        }
        return $headers;
    }

    private function _removeHeader($headerName)
    {
        if (function_exists('header_remove')) {
            header_remove($headerName);
        } else {
            header($headerName . ': ');
        }
    }
}
