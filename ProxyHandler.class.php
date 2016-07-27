<?php
/**
 * php5rp_ng - PHP5 Reverse Proxy Next Generation
 *
 * @link      https://github.com/chricke/php5rp_ng
 * @copyright Copyright (c) 2010, 2013 Christian "chricke" Beckmann < mail@christian-beckmann.net >.
 * @license   https://github.com/chricke/php5rp_ng/blob/master/README.md BSD license.
 */

class ProxyHandler
{
    /**
     * @type string
     */
    const RN = "\r\n";

    /**
     * @type string
     */
    private $_translatedUri;
    /**
     * @type boolean
     */
    private $_cacheControl = false;
    /**
     * @type boolean
     */
    private $_chunked = false;
    /**
     * @type boolean
     */
    private $_buffered = false;
    /**
     * @type array
     */
    private $_bufferedContentTypes = array();
    /**
     * @type string
     */
    private $_contentType;
    /**
     * @type string
     */
    private $_buffer = '';
    /**
     * @type boolean
     */
    private $_follow_location = false;
    /**
     * @type string
     */
    private $_location = null;
    /**
     * @type array
     */
    private $_clientHeaders = array();
    /**
     * @type resource
     */
    private $_curlHandle;
    /**
     * @type boolean
     */
    private $_pragma = false;

    /**
     * Create a new ProxyHandler
     *
     * @param array|string $options
     */
    function __construct($options)
    {
        if (is_string($options)) {
            $options = array('proxyUri' => $options);
        }
        if (isset($options['bufferedContentTypes']))
            $this->_bufferedContentTypes = $options['bufferedContentTypes'];

        // Trim slashes, we will append what is needed later
        $this->_translatedUri = rtrim($options['proxyUri'], '/');

        // Get all parameters from options

        $baseUri = '';
        if (isset($options['baseUri'])) {
            $baseUri = $options['baseUri'];
        }
        elseif (!empty($options['proxyUri'])) {
            $baseUri = parse_url($options['proxyUri'], PHP_URL_PATH);
            $baseUri = dirname($baseUri);
        }

        $requestUri = '';
        if (isset($options['requestUri'])) {
            $requestUri = $options['requestUri'];
        }
        else {
            if (!empty($_SERVER['REQUEST_URI'])) {
                $requestUri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
            }
            if (!empty($_SERVER['QUERY_STRING'])) {
                $requestUri .= '?' . $_SERVER['QUERY_STRING'];
            }
        }

        if (!empty($requestUri)) {
            if (!empty($baseUri)) {
                $baseUriLength = strlen($baseUri);
                if (substr($requestUri, 0, $baseUriLength) === $baseUri) {
                    $requestUri = substr($requestUri, $baseUriLength);
                }
            }
            $this->_translatedUri .= $requestUri;
        }
        else {
            $this->_translatedUri .= '/';
        }

        $this->_curlHandle = curl_init($this->_translatedUri);

        // Set various cURL options
        if (!isset($options['noFollowLocation'])) {
            $this->_follow_location = true;
            $this->setCurlOption(CURLOPT_FOLLOWLOCATION, true);
        }
        $this->setCurlOption(CURLOPT_RETURNTRANSFER, true);
        // For images, etc.
        $this->setCurlOption(CURLOPT_BINARYTRANSFER, true);
        $this->setCurlOption(CURLOPT_WRITEFUNCTION, array($this, 'readResponse'));
        $this->setCurlOption(CURLOPT_HEADERFUNCTION, array($this, 'readHeaders'));

        $requestMethod = '';
        if (isset($options['requestMethod'])) {
            $requestMethod = $options['requestMethod'];
        }
        elseif (!empty($_SERVER['REQUEST_METHOD'])) {
            $requestMethod = $_SERVER['REQUEST_METHOD'];
        }

        // Default cURL request method is 'GET'
        if ($requestMethod !== 'GET') {
            $this->setCurlOption(CURLOPT_CUSTOMREQUEST, $requestMethod);

            $inputStream = isset($options['inputStream']) ? $options['inputStream'] : 'php://input';

            switch($requestMethod) {
                case 'POST':
                case 'PATCH':
                    $data = '';
                    if (isset($options['data'])) {
                        $data = $options['data'];
                    }
                    else {
                        if (!isset($HTTP_RAW_POST_DATA)) {
                            $HTTP_RAW_POST_DATA = file_get_contents($inputStream);
                        }
                        $data = $HTTP_RAW_POST_DATA;
                    }
                    $this->setCurlOption(CURLOPT_POSTFIELDS, $data);
                    break;
                    
                case 'PUT':
                    // Set the request method.
                    $this->setCurlOption(CURLOPT_UPLOAD, 1);
                    // PUT data comes in on the stdin stream.
                    $putData = fopen($inputStream, 'r');
                    $this->setCurlOption(CURLOPT_READDATA, $putData);
                    // TODO: set CURLOPT_INFILESIZE to the value of Content-Length.
                    break;
            }
        }

        // Handle the client headers.
        $this->handleClientHeaders();
    }

    /**
     * @return array
     */
    private function _getRequestHeaders()
    {
        if (function_exists('apache_request_headers')) {
            if ($headers = apache_request_headers()) {
                return $headers;
            }
        }

        $headers = array();
        foreach ($_SERVER as $key => $value) {
            if (substr($key, 0, 5) == 'HTTP_' && !empty($value)) {
                $headerName = strtolower(substr($key, 5, strlen($key)));
                $headerName = str_replace(' ', '-', ucwords(str_replace('_', ' ', $headerName)));
                $headers[$headerName] = $value;
            }
        }
        return $headers;
    }

    /**
     * @param string $headerName
     * @return void
     */
    protected function _removeHeader($headerName)
    {
        if (function_exists('header_remove')) {
            header_remove($headerName);
        } else {
            header($headerName . ': ');
        }
    }

    /**
     * Adds the remote servers address to the 'X-Forwarded-For' headers,
     * sets the 'X-Real-IP' header to the first address forwarded to and
     * removes some headers we shouldn't pass through.
     *
     * @return void
     */
    protected function handleClientHeaders()
    {
        $headers = $this->_getRequestHeaders();
        $xForwardedFor = array();

        foreach ($headers as $headerName => $value) {
            switch($headerName) {
                case 'Host':
                case 'X-Real-IP':
                case 'Accept-Encoding':
                    break;
                    
                case 'X-Forwarded-For':
                    $xForwardedFor[] = $value;
                    break;
                    
                default:
                    $this->setClientHeader($headerName, $value);
                    break;
            }
        }

        $xForwardedFor[] = $_SERVER['REMOTE_ADDR'];
        $this->setClientHeader('X-Forwarded-For', implode(',', $xForwardedFor));
        $this->setClientHeader('X-Real-IP', $xForwardedFor[0]);
    }

    /**
     * Our handler for cURL option CURLOPT_HEADERFUNCTION
     *
     * @param resource $cu
     * @param string $string
     * @return int
     */
    protected function readHeaders(&$cu, $header)
    {
        $length = strlen($header);

        $matches = array();
        if (preg_match(',^Cache-Control:,', $header)) {
            $this->_cacheControl = true;
        }
        elseif (preg_match(',^Pragma:,', $header)) {
            $this->_pragma = true;
        }
        elseif (preg_match(',^Transfer-Encoding:,', $header)) {
            $this->_chunked = strpos($header, 'chunked') !== false;
        }
        elseif (preg_match(',^Location:\s*(.*),', $header, $matches)) {
            $this->_location = trim($matches[1]);
        }

        if ($header !== self::RN) {
            header(rtrim($header), false);
        }

        return $length;
    }

    /**
     * Our handler for cURL option CURLOPT_WRITEFUNCTION
     *
     * @param resource $cu
     * @param string $body
     * @return int
     */
    protected function readResponse(&$cu, $body)
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
            $info = $this->getCurlInfo();
            if (isset($info['content_type'])) {
                $this->_contentType = preg_replace('/;.*/', '', $info['content_type']);
                if (is_array($this->_bufferedContentTypes)
                    && in_array($this->_contentType, $this->_bufferedContentTypes)
                ) {
                    $this->_buffered = true;
                }
            }
            if (!$this->_follow_location && $this->getLocation())
                $this->_buffered = true;
            if ($this->_buffered) {
                $this->_removeHeader('Content-Length');
                $this->_removeHeader('Transfer-Encoding');
                $this->_removeHeader('Content-Encoding');
            } elseif ($this->_chunked) {
                flush();
            }
            $headersParsed = true;
        }

        $length = strlen($body);
        if ($this->_buffered) {
            $this->_buffer .= $body;
        } elseif ($this->_chunked) {
            echo dechex($length) . self::RN . $body . self::RN;
        } else {
            echo $body;
        }
        return $length;
    }

    /**
     * Close the cURL handle and a possible chunked response
     *
     * @return void
     */
    public function close()
    {
        if (!$this->_buffered && $this->_chunked) {
            echo '0' . self::RN . self::RN;
        }
        curl_close($this->_curlHandle);
    }

    /**
     * Executes the cURL handler, making the proxy request.
     * Returns true if request is successful, false if there was an error.
     * By checking this return, you may output the return from getCurlError
     * Or output your own bad gateway page.
     *
     * @return boolean
     */
    public function execute()
    {
        $this->setCurlOption(CURLOPT_HTTPHEADER, $this->_clientHeaders);
        return curl_exec($this->_curlHandle) !== false;
    }

    /**
     * Get possible cURL error.
     * Should NOT be called before exec.
     *
     * @return string
     */
    public function getCurlError()
    {
        return curl_error($this->_curlHandle);
    }

    /**
     * Get information about the request.
     * Should NOT be called before exec.
     *
     * @return array
     */
    public function getCurlInfo()
    {
        return curl_getinfo($this->_curlHandle);
    }

    /**
     * Sets a new header that will be sent with the proxy request
     *
     * @param string $headerName
     * @param string $value
     * @return void
     */
    public function setClientHeader($headerName, $value)
    {
        $this->_clientHeaders[] = $headerName . ': ' . $value;
    }

    /**
     * Sets a cURL option.
     *
     * @param string $option
     * @param string $value
     * @return void
     */
    public function setCurlOption($option, $value)
    {
        curl_setopt($this->_curlHandle, $option, $value);
    }

    /**
     * Returns translated uri
     */
    public function getTranslatedUri()
    {
        return $this->_translatedUri;
    }

    /**
     * Returns true if request is buffered
     */
    public function isBuffered()
    {
        return $this->_buffered;
    }

    /**
     * Returns buffer
     */
    public function getBuffer()
    {
        return $this->_buffer;
    }

    /**
     * Returns content type
     */
    public function getContentType()
    {
        return $this->_contentType;
    }

    /**
     * Returns redirect location (if any)
     */
    public function getLocation()
    {
        return $this->_location;
    }
}

// vi: ts=4:sw=4:et:
?>
