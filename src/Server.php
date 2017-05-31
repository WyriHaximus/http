<?php

namespace React\Http;

use Evenement\EventEmitter;
use React\Socket\ServerInterface as SocketServerInterface;
use React\Socket\ConnectionInterface;
use Psr\Http\Message\RequestInterface;

/**
 * The `Server` class is responsible for handling incoming connections and then
 * emit a `request` event for each incoming HTTP request.
 *
 * ```php
 * $socket = new React\Socket\Server(8080, $loop);
 *
 * $http = new React\Http\Server($socket);
 * ```
 *
 * For each incoming connection, it emits a `request` event with the respective
 * [`Request`](#request) and [`Response`](#response) objects:
 *
 * ```php
 * $http->on('request', function (Request $request, Response $response) {
 *     $response->writeHead(200, array('Content-Type' => 'text/plain'));
 *     $response->end("Hello World!\n");
 * });
 * ```
 *
 * See also [`Request`](#request) and [`Response`](#response) for more details.
 *
 * > Note that you SHOULD always listen for the `request` event.
 *   Failing to do so will result in the server parsing the incoming request,
 *   but never sending a response back to the client.
 *
 * The `Server` supports both HTTP/1.1 and HTTP/1.0 request messages.
 * If a client sends an invalid request message or uses an invalid HTTP protocol
 * version, it will emit an `error` event, send an HTTP error response to the
 * client and close the connection:
 *
 * ```php
 * $http->on('error', function (Exception $e) {
 *     echo 'Error: ' . $e->getMessage() . PHP_EOL;
 * });
 * ```
 *
 * @see Request
 * @see Response
 */
class Server extends EventEmitter
{
    /**
     * Creates a HTTP server that accepts connections from the given socket.
     *
     * It attaches itself to an instance of `React\Socket\ServerInterface` which
     * emits underlying streaming connections in order to then parse incoming data
     * as HTTP:
     *
     * ```php
     * $socket = new React\Socket\Server(8080, $loop);
     *
     * $http = new React\Http\Server($socket);
     * ```
     *
     * Similarly, you can also attach this to a
     * [`React\Socket\SecureServer`](https://github.com/reactphp/socket#secureserver)
     * in order to start a secure HTTPS server like this:
     *
     * ```php
     * $socket = new Server(8080, $loop);
     * $socket = new SecureServer($socket, $loop, array(
     *     'local_cert' => __DIR__ . '/localhost.pem'
     * ));
     *
     * $http = new React\Http\Server($socket);
     * ```
     *
     * @param \React\Socket\ServerInterface $io
     */
    public function __construct(SocketServerInterface $io)
    {
        $io->on('connection', array($this, 'handleConnection'));
    }

    /** @internal */
    public function handleConnection(ConnectionInterface $conn)
    {
        $that = $this;
        $parser = new RequestHeaderParser();
        $listener = array($parser, 'feed');
        $parser->on('headers', function (RequestInterface $request, $bodyBuffer) use ($conn, $listener, $parser, $that) {
            // parsing request completed => stop feeding parser
            $conn->removeListener('data', $listener);

            $that->handleRequest($conn, $request);

            if ($bodyBuffer !== '') {
                $conn->emit('data', array($bodyBuffer));
            }
        });

        $conn->on('data', $listener);
        $parser->on('error', function(\Exception $e) use ($conn, $listener, $that) {
            $conn->removeListener('data', $listener);
            $that->emit('error', array($e));

            $that->writeError(
                $conn,
                ($e instanceof \OverflowException) ? 431 : 400
            );
        });
    }

    /** @internal */
    public function handleRequest(ConnectionInterface $conn, RequestInterface $request)
    {
        // only support HTTP/1.1 and HTTP/1.0 requests
        if ($request->getProtocolVersion() !== '1.1' && $request->getProtocolVersion() !== '1.0') {
            $this->emit('error', array(new \InvalidArgumentException('Received request with invalid protocol version')));
            return $this->writeError($conn, 505);
        }

        // HTTP/1.1 requests MUST include a valid host header (host and optional port)
        // https://tools.ietf.org/html/rfc7230#section-5.4
        if ($request->getProtocolVersion() === '1.1') {
            $parts = parse_url('http://' . $request->getHeaderLine('Host'));

            // make sure value contains valid host component (IP or hostname)
            if (!$parts || !isset($parts['scheme'], $parts['host'])) {
                $parts = false;
            }

            // make sure value does not contain any other URI component
            unset($parts['scheme'], $parts['host'], $parts['port']);
            if ($parts === false || $parts) {
                $this->emit('error', array(new \InvalidArgumentException('Invalid Host header for HTTP/1.1 request')));
                return $this->writeError($conn, 400);
            }
        }

        $response = new Response($conn, $request->getProtocolVersion());

        $stream = $conn;
        if ($request->hasHeader('Transfer-Encoding')) {
            $transferEncodingHeader = $request->getHeader('Transfer-Encoding');
            // 'chunked' must always be the final value of 'Transfer-Encoding' according to: https://tools.ietf.org/html/rfc7230#section-3.3.1
            if (strtolower(end($transferEncodingHeader)) === 'chunked') {
                $stream = new ChunkedDecoder($conn);
            }
        }

        $request = new Request($request);

        // attach remote ip to the request as metadata
        $request->remoteAddress = trim(
            parse_url('tcp://' . $conn->getRemoteAddress(), PHP_URL_HOST),
            '[]'
        );

        // forward pause/resume calls to underlying connection
        $request->on('pause', array($conn, 'pause'));
        $request->on('resume', array($conn, 'resume'));

        // request closed => stop reading from the stream by pausing it
        // stream closed => close request
        $request->on('close', array($stream, 'pause'));
        $stream->on('close', array($request, 'close'));

        // forward data and end events from body stream to request
        $stream->on('end', function() use ($request) {
            $request->emit('end');
        });
        $stream->on('data', function ($data) use ($request) {
            $request->emit('data', array($data));
        });

        $this->emit('request', array($request, $response));
    }

    /** @internal */
    public function writeError(ConnectionInterface $conn, $code)
    {
        $message = 'Error ' . $code;
        if (isset(ResponseCodes::$statusTexts[$code])) {
            $message .= ': ' . ResponseCodes::$statusTexts[$code];
        }

        $response = new Response($conn);
        $response->writeHead($code, array(
            'Content-Length' => strlen($message),
            'Content-Type' => 'text/plain'
        ));
        $response->end($message);
    }
}
