<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /** Each simulated HTTP request gets a fresh tenant context, as in production (one request per PHP lifecycle). */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $this->app->forgetScopedInstances();
        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }
}
