<?php

namespace React\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use React\Promise;
use React\Promise\PromiseInterface;

final class MiddlewareRunner
{
    /**
     * @var callable[]
     * @internal
     */
    public $middleware = array();

    /**
     * @param callable[] $middleware
     */
    public function __construct(array $middleware)
    {
        $this->middleware = array_values($middleware);
    }

    /**
     * @param ServerRequestInterface $request
     * @return PromiseInterface<ResponseInterface>
     */
    public function __invoke(ServerRequestInterface $request)
    {
        if (count($this->middleware) === 0) {
            return Promise\reject(new \RuntimeException('No middleware to run'));
        }

        $position = 0;

        $that = $this;
        $func = function (ServerRequestInterface $request) use (&$func, &$position, &$that) {
            $middleware = $that->middleware[$position];
            $response = null;
            return new Promise\Promise(function ($resolve, $reject) use ($middleware, $request, $func, &$response, &$position) {
                $position++;
                try {
                    $response = $middleware(
                        $request,
                        $func
                    );

                    if (!($response instanceof PromiseInterface)) {
                        $response = Promise\resolve($response);
                    }

                    $response->then($resolve, function ($error) use (&$position, $reject) {
                        $position--;
                        $reject($error);
                    });
                } catch (\Exception $error) {
                    $position--;
                    $reject($error);
                } catch (\Throwable $error) {
                    $position--;
                    $reject($error);
                }
            }, function () use (&$response) {
                if ($response instanceof Promise\CancellablePromiseInterface) {
                    $response->cancel();
                }
            });
        };

        return $func($request);
    }
}