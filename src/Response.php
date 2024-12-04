<?php

namespace SwallowPHP\Framework;

class Response
{
    private $content;
    private $status;
    private $headers;

    private const VALID_STATUS_CODES = [
        200 => 'OK',
        201 => 'Created',
        204 => 'No Content',
        400 => 'Bad Request',
        401 => 'Unauthorized',
        403 => 'Forbidden',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        422 => 'Unprocessable Entity',
        500 => 'Internal Server Error'
    ];

    /**
     * Constructor for the class that sets the body, status, and headers of the response.
     *
     * @param array $content An array of response content.
     * @param int $status The HTTP status code of the response.
     * @param array $headers An array of headers to be sent with the response.
     */
    public function __construct(array $content = [], $status = 200, $headers = ['Content-Type' => 'application/json'])
    {
        if (!isset(self::VALID_STATUS_CODES[$status])) {
            throw new \InvalidArgumentException('Invalid HTTP status code: ' . $status);
        }

        $this->content = $content;
        $this->status = $status;

        // Ensure security headers
        $defaultHeaders = [
            'Content-Type' => 'application/json',
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'X-XSS-Protection' => '1; mode=block',
            'Strict-Transport-Security' => 'max-age=31536000; includeSubDomains',
            'Content-Security-Policy' => "default-src 'self'",
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0'
        ];

        $this->headers = array_merge($defaultHeaders, $headers);
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
        try {
            http_response_code($this->status);
            
            foreach ($this->headers as $name => $value) {
                header(sprintf('%s: %s', $name, $value));
            }
            
            $json = json_encode($this->content, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            echo $json;
        } catch (\JsonException $e) {
            error_log('Response encoding error: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Internal Server Error']);
        } catch (\Exception $e) {
            error_log('Response error: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Internal Server Error']);
        }
        exit;
    }

    /**
     * Returns the body of this object.
     *
     * @return string The body of this object.
     */
    public function getContent()
    {
        return $this->content;
    }


    /**
     * Returns the body of this object.
     *
     * @return string The body of this object.
     */
    public function setBody(array $body)
    {
        $this->content = $body;
    }
}
