<?php

namespace App\Core;

class Request
{
    const METHOD_GET = 'GET';
    const METHOD_POST = 'POST';
    const METHOD_PUT = 'PUT';
    const METHOD_PATCH = 'PATCH';
    const METHOD_DELETE = 'DELETE';

    /**
     * @var string 
     */
    public $requestMethod;

    /**
     * @var array 
     */
    public $queryData = [];

    /**
     * @var array 
     */
    public $formData = [];

    /**
     * @var array 
     */
    protected $headers = [];    

    public function __construct()
    {
        $parseUrl = parse_url($_SERVER['REQUEST_URI']);
        parse_str(($parseUrl['query'] ?? ''), $this->queryData);
        
        $this->requestMethod = $_SERVER['REQUEST_METHOD'];
        $this->formData = $this->getFormData();

        $this->setHeaders();
    }

    protected function setHeaders()
    {
        foreach (getallheaders() as $name => $value) {
            $this->headers[$name] = $value;
        }
    }

    public function getHeaders()
    {
        return $this->headers;
    }    

    /**
     * @return array
     */
    public function getFormData()
    {
        // GET, POST
        if ($this->requestMethod === self::METHOD_GET) {
            return $_GET;
        } elseif ($this->requestMethod === self::METHOD_POST) {
            return $_POST;
        }

        // PUT, PATCH, DELETE
        $formData = [];
        $exploded = explode('&', file_get_contents('php://input'));
        foreach($exploded as $pair) {
            $item = explode('=', $pair);
            if (count($item) == 2) {
                $formData[urldecode($item[0])] = urldecode($item[1]);
            }
        }

        return $formData;
    } 
}
