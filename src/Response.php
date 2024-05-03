<?php

namespace Framework;

class Response
{
    private $body;
    private $status;
    private $headers;

    /**
     * Constructor for the class that sets the body, status, and headers of the response.
     *
     * @param array $content An array of response content.
     * @param int $status The HTTP status code of the response.
     * @param array $headers An array of headers to be sent with the response.
     */
    public function __construct(array $content = [], $status = 200, $headers = ['Content-Type' => 'application/json'])
    {
        $this->body = $content;
        $this->status = $status;
        $this->headers = $headers;
    }


    /**
     * Constructor for the class that sets the body, status, and headers of the response.
     *
     * @param array $content An array of response content.
     * @param int $status The HTTP status code of the response.
     * @param array $headers An array of headers to be sent with the response.
     */
    public function send()
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header("{$name}: {$value}");
        }
        echo json_encode($this->body);
        die;
    }

    /**
     * Returns the body of this object.
     *
     * @return string The body of this object.
     */
    public function getBody()
    {
        return $this->body;
    }


    /**
     * Returns the body of this object.
     *
     * @return string The body of this object.
     */
    public function setBody(array $body)
    {
        $this->body = $body;
    }
}
