<?php

namespace App\Core;

class Response
{
    /**
     * @var array
     */
    protected $headers = [];

    /**
     * @var mixed 
     */
    protected $content;
  

    /**
     * @param string $content
     */
    public function setContent($content = '')
    {
        $this->content = $content;
    }    
    
    /**
     * @return mixed
     */
    public function getContent()
    {
        return $this->content;
    }
    
    /**
     * @param string $value
     */    
    public function setHeader($value)
    {
        $this->headers[] = (string)$value;
    }  

    /**
     * @return array
     */
    public function getHeaders()
    {
        return $this->headers;
    }

    /**
     * @return void
     */
    public function sendHeaders()
    {
        foreach ($this->headers as $header) {
            header($header);
        }
    }      

    /**
     * @param string $url
     */
    public static function redirect($url) 
    {
        $responce = new self();
        $responce->setHeader('Location: ' . $url);
        $responce->sendHeaders();
    }     

    public function output() 
    {
        $this->sendHeaders();
        echo $this->getContent();
    }
    
    public function outputJson() 
    {
        $this->setHeader('Content-Type: application/json');
        $this->sendHeaders();
        echo json_encode($this->getContent());
    }
}