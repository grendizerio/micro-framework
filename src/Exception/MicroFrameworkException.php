<?php

namespace Grendizer\MicroFramework\Exception;

use Grendizer\HttpMessage\ResponseInterface;
use Grendizer\HttpMessage\ServerRequestInterface;


/**
 * Stop Exception
 *
 * This Exception is thrown when the Micro application needs to abort
 * processing and return control flow to the outer PHP script.
 */
class MicroFrameworkException extends \Exception
{
    /**
     * A request object
     *
     * @var \Grendizer\HttpMessage\ServerRequestInterface
     */
    protected $request;

    /**
     * A response object to send to the HTTP client
     *
     * @var \Grendizer\HttpMessage\ResponseInterface
     */
    protected $response;

    /**
     * Create new exception
     *
     * @param  \Grendizer\HttpMessage\ServerRequestInterface  $request
     * @param  \Grendizer\HttpMessage\ResponseInterface  $response
     */
    public function __construct(ServerRequestInterface $request, ResponseInterface $response)
    {
        $this->request = $request;
        $this->response = $response;

        parent::__construct();
    }

    /**
     * Get request
     *
     * @return \Grendizer\MicroFramework\Interfaces\Http\ServerRequestInterface
     */
    public function getRequest()
    {
        return $this->request;
    }

    /**
     * Get response
     *
     * @return \Grendizer\MicroFramework\Interfaces\Http\ResponseInterface
     */
    public function getResponse()
    {
        return $this->response;
    }
}
