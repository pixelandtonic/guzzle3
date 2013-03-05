<?php

namespace Guzzle\Stream;

use Guzzle\Common\Exception\RuntimeException;
use Guzzle\Http\Message\EntityEnclosingRequestInterface;
use Guzzle\Http\Message\RequestInterface;
use Guzzle\Http\Url;

/**
 * Factory used to create fopen streams using PHP's http and https stream wrappers
 */
class PhpStreamRequestFactory implements StreamRequestFactoryInterface
{
    /**
     * @var array Stream context options
     */
    protected $options;

    /**
     * @var array Stream context params
     */
    protected $params;

    /**
     * @var Url Stream URL
     */
    protected $url;

    /**
     * @var array Last response headers received by the HTTP request
     */
    protected $lastResponseHeaders;

    /**
     * {@inheritdoc}
     */
    public function fromRequest(RequestInterface $request, array $options = null, array $params = array())
    {
        // Use the new contextParams
        $this->params = $params;

        // Dispatch the before send event
        $request->dispatch('request.before_send', array('request' => $request));

        // Add default HTTP context options
        $this->createDefaultContext($request);

        // Use the request's URL
        $this->setUrl($request);

        // Add SSL options if needed
        if ($request->getScheme() == 'https') {
            $this->addSslOptions($request);
        }

        // Add the content for the request if needed
        if ($request instanceof EntityEnclosingRequestInterface) {
            $this->addBodyOptions($request);
        }

        // Add proxy params if needed
        $this->addProxyOptions($request);

        // Merge in custom context options
        if ($options) {
            $this->mergeContextOptions($options);
        }

        // Create the file handle but silence errors
        $stream = new Stream($this->createStream());

        return $stream->setCustomData('request', $request)
            ->setCustomData('response_headers', $this->getLastResponseHeaders());
    }

    /**
     * Get the last response headers received by the HTTP request
     *
     * @return array
     */
    public function getLastResponseHeaders()
    {
        return $this->lastResponseHeaders;
    }

    /**
     * Adds the default context options to the stream context options
     *
     * @param RequestInterface $request Request
     */
    protected function createDefaultContext(RequestInterface $request)
    {
        $this->options = array(
            'http' => array(
                'method'           => $request->getMethod(),
                'header'           => $request->getHeaderLines(),
                // Force 1.0 for now until PHP fully support chunked transfer-encoding decoding
                'protocol_version' => '1.0',
                'ignore_errors'    => true
            )
        );
    }

    /**
     * Set the URL to use with the factory
     *
     * @param RequestInterface $request Request that owns the URL
     */
    protected function setUrl(RequestInterface $request)
    {
        $this->url = $request->getUrl(true);

        // Check for basic Auth username
        if ($request->getUsername()) {
            $this->url->setUsername($request->getUsername());
        }

        // Check for basic Auth password
        if ($request->getPassword()) {
            $this->url->setPassword($request->getPassword());
        }
    }

    /**
     * Add SSL options to the stream context
     *
     * @param RequestInterface $request Request
     */
    protected function addSslOptions(RequestInterface $request)
    {
        if ($verify = $request->getCurlOptions()->get(CURLOPT_SSL_VERIFYPEER)) {
            $this->options['ssl']['verify_peer'] = true;
        }

        if ($cafile = $request->getCurlOptions()->get(CURLOPT_CAINFO)) {
            $this->options['ssl']['cafile'] = $cafile;
        }
    }

    /**
     * Add body (content) specific options to the context options
     *
     * @param EntityEnclosingRequestInterface $request
     */
    protected function addBodyOptions(EntityEnclosingRequestInterface $request)
    {
        if ($request->getPostFields()) {
            $this->options['http']['content'] = (string) $request->getPostFields();
        } elseif ($request->getBody()) {
            $this->options['http']['content'] = (string) $request->getBody();
        }
        if ($this->options['http']['content']) {
            $this->options['http']['header'][] = 'Content-Length: ' . strlen($this->options['http']['content']);
        }
    }

    /**
     * Merge custom context options into the default context options
     *
     * @param array $contextOptions Context options
     */
    protected function mergeContextOptions(array $contextOptions)
    {
        foreach ($contextOptions as $wrapper => $options) {
            if (!isset($this->options[$wrapper])) {
                $this->options[$wrapper] = array();
            }
            if (is_array($options)) {
                foreach ($options as $optionName => $optionValue) {
                    $this->options[$wrapper][$optionName] = $optionValue;
                }
            }
        }
    }

    /**
     * Add proxy parameters to the context if needed
     *
     * @param RequestInterface $request Request
     */
    protected function addProxyOptions(RequestInterface $request)
    {
        if ($proxy = $request->getCurlOptions()->get(CURLOPT_PROXY)) {
            $this->options['proxy'] = $proxy;
        }
    }

    /**
     * Create the stream for the request with the context options
     *
     * @return resource
     * @throws RuntimeException If an error occurs
     */
    protected function createStream()
    {
        // Turn off error reporting while we try to initiate the request
        $level = error_reporting(0);
        $fp = fopen((string) $this->url, 'r', false, stream_context_create($this->options, $this->params));
        error_reporting($level);

        // If the file could not be created, then grab the last error and throw an exception
        if (false === $fp) {
            $error = error_get_last();
            throw new RuntimeException($error['message']);
        }

        // Track the response headers of the request
        if (isset($http_response_header)) {
            $this->lastResponseHeaders = $http_response_header;
        }

        return $fp;
    }
}