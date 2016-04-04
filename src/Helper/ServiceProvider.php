<?php

namespace Grendizer\MicroFramework\Helper;

use Grendizer\MicroFramework\Application;

abstract class ServiceProvider
{
    /**
     * The application instance.
     *
     * @var \Grendizer\MicroFramework\Application
     */
    protected $app;

    /**
     * Indicates if loading of the provider is deferred.
     *
     * @var bool
     */
    protected $defer = false;

    /**
     * Create a new service provider instance.
     *
     * @param  \Grendizer\MicroFramework\Application
     */
    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    /**
     * Register the service provider.
     *
     * @param  array  $options
     * @return void
     */
    abstract public function register(array $options = array());

    /**
     * Get the events that trigger this service provider to register.
     *
     * @return array
     */
    public function when()
    {
        return array();
    }

    /**
     * Determine if the provider is deferred.
     *
     * @return bool
     */
    public function isDeferred()
    {
        return $this->defer;
    }

    /**
     * Dynamically handle missing method calls.
     *
     * @param  string  $method
     * @param  array  $parameters
     * @return mixed
     *
     * @throws \BadMethodCallException
     */
    public function __call($method, $parameters)
    {
        if ($method == 'boot') {
            return;
        }

        throw new \BadMethodCallException("Call to undefined method [{$method}]");
    }
}
