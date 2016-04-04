<?php

namespace Grendizer\MicroFramework\Exception;

use Grendizer\HttpMessage\ServerRequestInterface;
use Grendizer\HttpMessage\ResponseInterface;

class MethodNotAllowedException extends MicroFrameworkException
{
    /**
     * HTTP methods allowed
     *
     * @var string[]
     */
    protected $allowedMethods;

    /**
     * Create new exception
     *
     * @param  \Grendizer\HttpMessage\ServerRequestInterface  $request
     * @param  \Grendizer\HttpMessage\ResponseInterface  $response
     * @param  string[]  $allowedMethods
     */
    public function __construct(ServerRequestInterface $request, ResponseInterface $response, array $allowedMethods)
    {
        parent::__construct($request, $response);
        $this->allowedMethods = $allowedMethods;
    }

    /**
     * Get allowed methods
     *
     * @return string[]
     */
    public function getAllowedMethods()
    {
        return $this->allowedMethods;
    }
}
