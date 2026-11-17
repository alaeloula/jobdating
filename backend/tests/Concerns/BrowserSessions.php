<?php

namespace Tests\Concerns;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Testing\TestResponse;

/**
 * Requêtes d'un « navigateur » qui garde ses cookies chiffrés, avec sessions en base : les
 * gardes et le stockage de session en mémoire sont recréés entre les requêtes, seuls le cookie
 * et la session MySQL authentifient (même principe que CompanyConcurrentSessionsTest).
 */
trait BrowserSessions
{
    /**
     * @param  array<string, string>  $cookies
     * @param  array<string, mixed>  $body
     * @return TestResponse<Response>
     */
    protected function browserRequest(array &$cookies, string $method, string $path, array $body = [], string $ip = '10.0.0.20'): TestResponse
    {
        $this->app['auth']->forgetGuards();
        $this->app->forgetInstance('auth.driver');
        $this->app['session']->forgetDrivers();
        $this->app->forgetInstance('session.store');

        $request = Request::create('http://localhost'.$path, $method, [], $cookies, [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_ORIGIN' => 'http://localhost',
            'REMOTE_ADDR' => $ip,
        ], json_encode($body, JSON_THROW_ON_ERROR));

        $kernel = $this->app->make(Kernel::class);
        $response = $kernel->handle($request);

        foreach ($response->headers->getCookies() as $cookie) {
            $value = $cookie->getValue();

            if ($value === null) {
                unset($cookies[$cookie->getName()]);
            } else {
                $cookies[$cookie->getName()] = $value;
            }
        }

        $kernel->terminate($request, $response);

        return TestResponse::fromBaseResponse($response);
    }
}
