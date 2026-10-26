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

    /** Website trial / buy: post the form, then type the e-mailed 6-digit code (as a person would). Returns the verify response. */
    protected function postWithEmailCode(string $uri, array $form): \Illuminate\Testing\TestResponse
    {
        $page = $this->post($uri, $form)->assertOk()->assertSee('Check your e-mail');
        preg_match('/name="token" value="([a-f0-9]{32})"/', $page->getContent(), $m);
        $this->assertNotEmpty($m, 'no code token on the page');
        return $this->post('/verify-email', ['token' => $m[1], 'code' => $this->lastEmailCode()]);
    }

    protected function lastEmailCode(): ?string
    {
        $code = null;
        foreach (app('mailer')->getSymfonyTransport()->messages() as $msg) {
            preg_match('/(\d{6}) is your ROVEXY code/', $msg->getOriginalMessage()->getSubject(), $c) && $code = $c[1];
        }
        return $code;
    }
}
