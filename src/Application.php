<?php

namespace Grendizer\MicroFramework;

use Grendizer\Container\Container;
use Grendizer\Container\ContainerInterface;
use Grendizer\HttpMessage\Request;
use Grendizer\HttpMessage\Response;
use Grendizer\HttpMessage\ResponseInterface;
use Grendizer\HttpMessage\ServerRequestInterface;
use Grendizer\MicroFramework\Exception\MethodNotAllowedException;
use Grendizer\MicroFramework\Exception\MicroFrameworkException;
use Grendizer\MicroFramework\Exception\NotFoundException;
use Grendizer\MicroFramework\Helper\ServiceProvider;

class Application
{
    /**
     * 应用程序目录
     *
     * @var string
     */
    protected $basePath;

    /**
     * 服务容器
     *
     * @var \Grendizer\Container\ContainerInterface
     */
    protected $container;

    /**
     * The loaded service providers.
     *
     * @var array
     */
    protected $loadedProviders;

    /********************************************************************************
     * Constructor
     *******************************************************************************/

    /**
     * Create new application
     *
     * @param string $basePath
     * @param \Grendizer\Container\ContainerInterface|null $container
     */
    public function __construct($basePath, ContainerInterface $container = null)
    {
        $this->loadedProviders = array();
        $this->basePath = $basePath;

        $this->bootstrapContainer($container);
        $this->registerBuildInContainer();
    }

    /**
     * 启动程序容器
     *
     * @param  \Grendizer\Container\ContainerInterface|null $container
     */
    protected function bootstrapContainer(ContainerInterface $container = null)
    {
        if (null === $container) {
            $container = new Container();
        }

        if (null === Container::getInstance()) {
            Container::setInstance($container);
        }

        $container->instance('Grendizer\MicroFramework\Application', $this);
        $container->instance('application', $this);
        $container->instance('app', $this);
        
        $this->container = $container;
    }

    /**
     * 注册内置的服务到容器
     */
    protected function registerBuildInContainer()
    {
        $this->container->singleton('request', function() {
            return Request::createFromGlobal();
        });

        $this->container->singleton('response', function() {
            return new Response();
        });
        
        $this->container->singleton('settings', function() {
            return new Repository(array(
                'displayErrorDetails' => true, // set to false in production
                'responseChunkSize' => 1,
            ));
        });

        $this->container->singleton('router', function(Container $container) {
            $file = $container->resolve('app')->basePath().'/routes.php';
            $router = new \ArrayIterator(is_file($file) ? require($file) : array());
            return $router;
        });
    }

    /**
     * Enable access to the DI container by consumers of $app
     *
     * @return \Grendizer\Container\ContainerInterface
     */
    public function getContainer()
    {
        return $this->container;
    }

    /**
     * Register a service provider with the application.
     *
     * @param  \Grendizer\MicroFramework\Helper\ServiceProvider|string  $provider
     * @param  array  $options
     * @return void
     */
    public function register($provider, $options = array())
    {
        if (! $provider instanceof ServiceProvider) {
            $provider = new $provider($this);
        }

        $providerName = get_class($provider);

        if (!array_key_exists($providerName, $this->loadedProviders)) {
            $this->loadedProviders[$providerName] = true;
            $provider->register($options);
            $provider->boot();
        }
    }

    /**
     * Get the base path for the application.
     *
     * @param  string|null  $path
     * @return string
     */
    public function basePath($path = null)
    {
        if (isset($this->basePath)) {
            return $this->basePath.($path ? '/'.$path : $path);
        }

        if ($this->runningInConsole()) {
            $this->basePath = getcwd();
        } else {
            $this->basePath = realpath(getcwd().'/../');
        }

        return $this->basePath($path);
    }

    /**
     * Determine if the application is running in the console.
     *
     * @return bool
     */
    public function runningInConsole()
    {
        return php_sapi_name() == 'cli';
    }

    /**
     * Calling a non-existant method on App checks to see if there's an item
     * in the container than is callable and if so, calls it.
     *
     * @param  string  $name
     * @param  array   $arguments
     * @return mixed
     */
    public function __call($name, $arguments)
    {
        if ($this->container->bound($name)) {
            return $this->container->resolve($name, $arguments);
        }

        throw new \BadMethodCallException("Method {$name} is not a valid method");
    }

    /********************************************************************************
     * Runner
     *******************************************************************************/

    /**
     * Run application
     *
     * This method traverses the application middleware stack and then sends the
     * resultant Response object to the HTTP client.
     *
     * @param  bool|false  $silent
     * @return \Grendizer\HttpMessage\ResponseInterface
     *
     * @throws \Exception
     * @throws \Grendizer\MicroFramework\Exception\MethodNotAllowedException
     * @throws \Grendizer\MicroFramework\Exception\NotFoundException
     */
    public function run($silent = false)
    {
        // todo 执行 `service` ，如：加载配置等

        $request = $this->container['request'];
        $response = $this->container['response'];

        try {
            $response = Route::resolve($request, $response, $this->container);
        } catch (\Exception $e) {
            $response = $this->handleException($e, $request, $response);
        }

        $response = $this->finalize($response);

        if (!$silent) {
            $this->respond($response);
        }

        return $response;
    }

    /**
     * Send the response the client
     *
     * @param \Grendizer\HttpMessage\ResponseInterface $response
     */
    public function respond(ResponseInterface $response)
    {
        // Send response
        if (!headers_sent()) {
            // Status
            header(sprintf(
                'HTTP/%s %s %s',
                $response->getProtocolVersion(),
                $response->getStatusCode(),
                $response->getReasonPhrase()
            ));

            // Headers
            foreach ($response->getHeaders() as $name => $values) {
                foreach ($values as $value) {
                    header(sprintf('%s: %s', $name, $value), false);
                }
            }
        }

        // Body
        if (!$this->isEmptyResponse($response)) {
            $body = $response->getBody();
            if ($body->isSeekable()) {
                $body->rewind();
            }
            $settings       = $this->container->resolve('settings');
            $chunkSize      = $settings['responseChunkSize'];
            $contentLength  = $response->getHeaderLine('Content-Length');
            if (!$contentLength) {
                $contentLength = $body->getSize();
            }
            $totalChunks    = ceil($contentLength / $chunkSize);
            $lastChunkSize  = $contentLength % $chunkSize;
            $currentChunk   = 0;
            
            while (!$body->eof() && $currentChunk < $totalChunks) {
                if (++$currentChunk == $totalChunks && $lastChunkSize > 0) {
                    $chunkSize = $lastChunkSize;
                }
                
                echo $body->read($chunkSize);
                
                if (connection_status() != CONNECTION_NORMAL) {
                    break;
                }
            }
        }
    }

    /**
     * Finalize response
     *
     * @param ResponseInterface $response
     * @return ResponseInterface
     */
    protected function finalize(ResponseInterface $response)
    {
        // stop PHP sending a Content-Type automatically
        ini_set('default_mimetype', '');

        if ($this->isEmptyResponse($response)) {
            return $response->withoutHeader('Content-Type')->withoutHeader('Content-Length');
        }

        $size = $response->getBody()->getSize();
        if ($size !== null && !$response->hasHeader('Content-Length')) {
            $response = $response->withHeader('Content-Length', (string) $size);
        }

        return $response;
    }

    /**
     * Helper method, which returns true if the provided response must not output a body and false
     * if the response could have a body.
     *
     * @see https://tools.ietf.org/html/rfc7231
     *
     * @param ResponseInterface $response
     * @return bool
     */
    protected function isEmptyResponse(ResponseInterface $response)
    {
        if (method_exists($response, 'isEmpty')) {
            return $response->isEmpty();
        }

        return in_array($response->getStatusCode(), array(204, 205, 304));
    }

    /**
     * Call relevant handler from the Container if needed. If it doesn't exist,
     * then just re-throw.
     *
     * @param  \Exception $e
     * @param  \Grendizer\HttpMessage\ServerRequestInterface $request
     * @param  \Grendizer\HttpMessage\ResponseInterface $response
     *
     * @return ResponseInterface
     * @throws \Exception if a handler is needed and not found
     */
    protected function handleException(\Exception $e, ServerRequestInterface $request, ResponseInterface $response)
    {
        if ($e instanceof MethodNotAllowedException) {
            $handler = 'notAllowedHandler';
            $params = array($e->getRequest(), $e->getResponse(), $e->getAllowedMethods());
        } elseif ($e instanceof NotFoundException) {
            $handler = 'notFoundHandler';
            $params = array($e->getRequest(), $e->getResponse());
        } elseif ($e instanceof MicroFrameworkException) {
            // This is a Stop exception and contains the response
            return $e->getResponse();
        } else {
            // Other exception, use $request and $response params
            $handler = 'errorHandler';
            $params = array($request, $response, $e);
        }

        if ($this->container->bound($handler)) {
            // Call the registered handler
            return $this->container->resolve($handler, $params);
        }

        // No handlers found, so just throw the exception
        throw $e;
    }
}
