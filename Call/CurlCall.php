<?php
namespace Lsw\ApiCallerBundle\Call;

use Lsw\ApiCallerBundle\Helper\Curl;

/**
 * cURL based API Call
 *
 * @author Maurits van der Schee <m.vanderschee@leaseweb.com>
 */
abstract class CurlCall implements ApiCallInterface
{
    protected $url;
    protected $name;
    protected $requestData;
    protected $requestObject;
    protected $responseData;
    protected $responseObject;
    protected $responseHeaderData;
    protected $responseHeaderObject;
    protected $status;
    protected $asAssociativeArray;
    protected $options = array();
    protected $dirtyWay =  false;

    /**
     * Class constructor
     *
     * @param string $url                API url
     * @param object $requestObject      Request
     * @param bool   $asAssociativeArray Return associative array
     * @param array  $options            Additional options for the cURL engine
     */
    public function __construct($url,$requestObject,$asAssociativeArray=false,$options = array())
    {
        $this->url = $url;
        $this->requestObject = $requestObject;
        $this->options = $options;
        $this->asAssociativeArray = $asAssociativeArray;
        $this->generateRequestData();
    }

    /**
     * Set to perform GET request in dirty way.
     * If true, then it can perform GET request with url like: foo=x&foo=y&foo=z&status=new&status=on%20hold&s??tatus=open
     *
     * @param bool $dirtyWay
     */
    public function setDirtyWay($dirtyWay)
    {
        $this->dirtyWay = $dirtyWay;
        if($dirtyWay){
            $this->generateRequestData();
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getUrl()
    {
        return $this->url;
    }

    /**
     * {@inheritdoc}
     */
    public function getName()
    {
        return get_class($this);
    }

    /**
     * {@inheritdoc}
     */
    public function getRequestData()
    {
        return $this->requestData;
    }

    /**
     * {@inheritdoc}
     */
    public function getRequestObject()
    {
        return $this->requestObject;
    }

    /**
     * {@inheritdoc}
     */
    public function getRequestObjectRepresentation()
    {
        $dumper = new \Symfony\Component\Yaml\Dumper();

        return $dumper->dump(json_decode(json_encode($this->requestObject), true), 100);
    }

    /**
     * {@inheritdoc}
     */
    public function getResponseHeaderObject()
    {
        return $this->responseHeaderObject;
    }

    /**
     * {@inheritdoc}
     */
    public function getResponseData()
    {
        return $this->responseData;
    }

    /**
     * {@inheritdoc}
     */
    public function getResponseObject()
    {
        return $this->responseObject;
    }

    /**
     * {@inheritdoc}
     */
    public function getResponseObjectRepresentation()
    {
        $dumper = new \Symfony\Component\Yaml\Dumper();

        return $dumper->dump(json_decode(json_encode($this->responseObject), true), 100);
    }

    /**
     * {@inheritdoc}
     */
    public function getStatusCode()
    {
        return $this->status;
    }

    /**
     * Get the HTTP status message by HTTP status code
     *
     * @return mixed HTTP status message (string) or the status code (integer) if the message can't be found
     */
    public function getStatus()
    {
        $code = $this->getStatusCode();
        $codes = array(
                0   => 'Connection failed',
                100 => 'Continue',
                101 => 'Switching Protocols',
                200 => 'OK',
                201 => 'Created',
                202 => 'Accepted',
                203 => 'Non-Authoritative Information',
                204 => 'No Content',
                205 => 'Reset Content',
                206 => 'Partial Content',
                300 => 'Multiple Choices',
                301 => 'Moved Permanently',
                302 => 'Found',
                303 => 'See Other',
                304 => 'Not Modified',
                305 => 'Use Proxy',
                307 => 'Temporary Redirect',
                400 => 'Bad Request',
                401 => 'Unauthorized',
                403 => 'Forbidden',
                404 => 'Not Found',
                405 => 'Method Not Allowed',
                406 => 'Not Acceptable',
                407 => 'Proxy Authentication Required',
                408 => 'Request Timeout',
                409 => 'Conflict',
                410 => 'Gone',
                411 => 'Length Required',
                412 => 'Precondition Failed',
                413 => 'Request Entity Too Large',
                414 => 'Request URI Too Long',
                415 => 'Unsupported Media Type',
                416 => 'Requested Range Not Satisfiable',
                417 => 'Expectation Failed',
                500 => 'Internal Server Error',
                501 => 'Not Implemented',
                502 => 'Bad Gateway',
                503 => 'Service Unavailable',
                504 => 'Gateway Timeout',
                505 => 'HTTP Version Not Supported',
        );

        if (isset($codes[$code])) {
            return "$code $codes[$code]";
        }

        return $code;
    }

    /**
     * Execute the call
     *
     * @param array  $options      Array of options
     * @param object $engine       Calling engine
     * @param bool   $freshConnect Make a fresh connection every call
     *
     * @return mixed Response
     */
    public function execute($options, $engine, $freshConnect = false)
    {
        $options['returntransfer']=true;
        $options = $this->parseCurlOptions(array_merge($options, $this->options));
        $this->makeRequest($engine, $options);
        $this->parseResponseData();
        $this->parseResponseHeader();
        $this->status = $engine->getinfo(CURLINFO_HTTP_CODE);
        $result = $this->getResponseObject();

        return $result;
    }

    /**
     * Private method to parse cURL options from the bundle config.
     * If some option is not defined an exception will be thrown.
     *
     * @param array $config ApiCallerBundle configuration
     *
     * @throws \Exception Specified cURL option can't be found
     *
     * @return array
     */
    protected function parseCurlOptions($config)
    {
        $options = array();
        $prefix = 'CURLOPT_';
        foreach ($config as $key => $value) {
            $constantName = $prefix . strtoupper($key);
            if (!defined($constantName)) {
                $messageTemplate  = "Invalid option '%s' in apicaller.config parameter. ";
                $messageTemplate .= "Use options (from the cURL section in the PHP manual) without prefix '%s'";
                $message = sprintf($messageTemplate, $key, $prefix);
                throw new \Exception($message);
            }
            $options[constant($constantName)] = $value;
        }

        return $options;
    }

    /**
     * Protected method to parse HTTP headers if the exist in the response object
     *
     * @param $raw_headers
     *
     * @return array
     *
     */
    protected function parseHeader($raw_headers)
    {
        $headers = array();
        $key = '';

        foreach(explode("\n", $raw_headers) as $i => $h) {
            $h = explode(':', $h, 2);

            if (isset($h[1])) {
                if (!isset($headers[$h[0]]))
                    $headers[$h[0]] = trim($h[1]);
                elseif (is_array($headers[$h[0]])) {
                    $headers[$h[0]] = array_merge($headers[$h[0]], array(trim($h[1])));
                } else {
                    $headers[$h[0]] = array_merge(array($headers[$h[0]]), array(trim($h[1])));
                }

                $key = $h[0];
            } else {
                if (substr($h[0], 0, 1) == "\t")
                    $headers[$key] .= "\r\n\t".trim($h[0]);
                elseif (!$key)
                    $headers['Status'] = trim($h[0]);
            }
        }

        return $headers;
    }

    /**
     * {@inheritdoc}
     */
    public function generateRequestData()
    {
        $class = get_class($this);
        throw new \Exception("Class $class must implement method 'generateRequestData'. Hint:

        public function generateRequestData()
        {
        \$this->requestData = http_build_query(\$this->requestObject);
        }

        ");
    }

    /**
     * {@inheritdoc}
     */
    public function parseResponseData()
    {
        $class = get_class($this);
        throw new \Exception("Class $class must implement method 'parseResponseData'. Hint:

        public function parseResponseData()
        {
        \$this->responseObject = json_decode(\$this->responseData,\$this->asAssociativeArray);
        }

        ");
    }

    public function parseResponseHeader()
    {
        if( $this->responseHeaderData )  {
            if($this->asAssociativeArray) {
                $this->responseHeaderObject = $this->parseHeader($this->responseHeaderData);
            } else {
                $this->responseHeaderObject = $this->responseHeaderData;
            }
        } else {
            $this->responseHeaderObject = NULL;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function makeRequest($curl, $options)
    {
        $class = get_class($this);
        throw new \Exception("Class $class must implement method 'makeRequest'. Hint:

        public function makeRequest(\$curl, \$options)
        {
        \$curl->setopt(CURLOPT_URL, \$this->url.'?'.\$this->requestData);
        \$curl->setoptArray(\$options);
        \$this->responseData = \$curl->exec();
        }

        ");
    }

    /**
     * @param $curl
     */
    public function curlExec($curl)
    {
        $data = $curl->exec();
        if( preg_match("/^HTTP\/\d\.\d/", $data) ) {
            $tmp = explode( "\r\n\r\n", $data);
            $this->responseHeaderData = $tmp[0];
            $this->responseData = $tmp[1];
        } else {
            $this->responseData = $data;
        }
    }
}
