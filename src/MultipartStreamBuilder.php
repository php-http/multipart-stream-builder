<?php

namespace Http\Message\MultipartStream;

use Http\Discovery\StreamFactoryDiscovery;
use Http\Message\StreamFactory;
use Psr\Http\Message\StreamInterface;

/**
 * Build your own Multipart stream. A Multipart stream is a collection of streams separated with a $bounary. This
 * class helps you to create a Multipart stream with stream implementations from any PSR7 library.
 *
 * @author Michael Dowling and contributors to guzzlehttp/psr7
 * @author Tobias Nyholm <tobias.nyholm@gmail.com>
 */
class MultipartStreamBuilder
{
    /**
     * @var StreamFactory
     */
    private $streamFactory;

    /**
     * @var MimetypeHelper
     */
    private $mimetypeHelper;

    /**
     * @var string
     */
    private $boundary;

    /**
     * @var array Element where each Element is an array with keys ['contents', 'headers', 'filename']
     */
    private $data;

    /**
     * @param StreamFactory|null $streamFactory
     */
    public function __construct(StreamFactory $streamFactory = null)
    {
        $this->streamFactory = $streamFactory ?: StreamFactoryDiscovery::find();
    }

    /**
     * Add a resource to the Multipart Stream. If the same $name is used twice the first resource will
     * be overwritten.
     *
     * @param string                          $name     the formpost name
     * @param string|resource|StreamInterface $resource
     * @param array                           $options  {
     *
     *     @var array $headers additional headers ['header-name' => 'header-value']
     *     @var string $filename
     * }
     *
     * @return MultipartStreamBuilder
     */
    public function addResource($name, $resource, array $options = [])
    {
        $stream = $this->streamFactory->createStream($resource);

        // validate options['headers'] exists
        if (!isset($options['headers'])) {
            $options['headers'] = [];
        }

        // Try to add filename if it is missing
        if (empty($options['filename'])) {
            $options['filename'] = null;
            $uri = $stream->getMetadata('uri');
            if (substr($uri, 0, 6) !== 'php://') {
                $options['filename'] = $uri;
            }
        }

        $this->prepareHeaders($name, $stream, $options['filename'], $options['headers']);
        $this->data[$name] = ['contents' => $stream, 'headers' => $options['headers'], 'filename' => $options['filename']];

        return $this;
    }

    /**
     * Build the stream.
     *
     * @return StreamInterface
     */
    public function build()
    {
        $streams = '';
        foreach ($this->data as $data) {

            // Add start and headers
            $streams .= "--{$this->getBoundary()}\r\n".
                $this->getHeaders($data['headers'])."\r\n";

            // Convert the stream to string
            $streams .= (string) $data['contents'];
            $streams .= "\r\n";
        }

        // Append end
        $streams .= "--{$this->getBoundary()}--\r\n";

        return $this->streamFactory->createStream($streams);
    }

    /**
     * Add extra headers if they are missing.
     *
     * @param string          $name
     * @param StreamInterface $stream
     * @param string          $filename
     * @param array           &$headers
     */
    private function prepareHeaders($name, StreamInterface $stream, $filename, array &$headers)
    {
        $hasFilename = $filename === '0' || $filename;

        // Set a default content-disposition header if one was not provided
        if (!$this->hasHeader($headers, 'content-disposition')) {
            $headers['Content-Disposition'] = sprintf('form-data; name="%s"', $name);
            if ($hasFilename) {
                $headers['Content-Disposition'] .= sprintf('; filename="%s"', basename($filename));
            }
        }

        // Set a default content-length header if one was not provided
        if (!$this->hasHeader($headers, 'content-length')) {
            if ($length = $stream->getSize()) {
                $headers['Content-Length'] = (string) $length;
            }
        }

        // Set a default Content-Type if one was not provided
        if (!$this->hasHeader($headers, 'content-type') && $hasFilename) {
            if ($type = $this->getMimetypeHelper()->getMimetypeFromFilename($filename)) {
                $headers['Content-Type'] = $type;
            }
        }
    }

    /**
     * Get the headers formatted for the HTTP message.
     *
     * @param array $headers
     *
     * @return string
     */
    private function getHeaders(array $headers)
    {
        $str = '';
        foreach ($headers as $key => $value) {
            $str .= sprintf("%s: %s\r\n", $key, $value);
        }

        return $str;
    }

    /**
     * Check if header exist.
     *
     * @param array  $headers
     * @param string $key     case insensitive
     *
     * @return bool
     */
    private function hasHeader(array $headers, $key)
    {
        $lowercaseHeader = strtolower($key);
        foreach ($headers as $k => $v) {
            if (strtolower($k) === $lowercaseHeader) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get the boundary that separates the streams.
     *
     * @return string
     */
    public function getBoundary()
    {
        if ($this->boundary === null) {
            $this->boundary = uniqid();
        }

        return $this->boundary;
    }

    /**
     * @param string $boundary
     *
     * @return MultipartStreamBuilder
     */
    public function setBoundary($boundary)
    {
        $this->boundary = $boundary;

        return $this;
    }

    /**
     * @return MimetypeHelper
     */
    private function getMimetypeHelper()
    {
        if ($this->mimetypeHelper === null) {
            $this->mimetypeHelper = new ApacheMimetypeHelper();
        }

        return $this->mimetypeHelper;
    }

    /**
     * If you have custom file extension you may overwrite the default MimetypeHelper with your own.
     *
     * @param MimetypeHelper $mimetypeHelper
     *
     * @return MultipartStreamBuilder
     */
    public function setMimetypeHelper(MimetypeHelper $mimetypeHelper)
    {
        $this->mimetypeHelper = $mimetypeHelper;

        return $this;
    }
}
