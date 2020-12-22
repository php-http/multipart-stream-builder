<?php

namespace Http\Message\MultipartStream;

use Http\Discovery\Exception\NotFoundException;
use Http\Discovery\Psr17FactoryDiscovery;
use Http\Discovery\StreamFactoryDiscovery;
use Http\Message\StreamFactory as HttplugStreamFactory;
use Psr\Http\Message\StreamFactoryInterface;
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
     * @var HttplugStreamFactory|StreamFactoryInterface
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
     * @var array Element where each Element is an array with keys ['contents', 'headers']
     */
    private $data = [];

    /**
     * @var int Bytes of unallocated memory to use for stream buffer.
     *          To have MultipartStreamBuilder manage it automatically, set to -1.
     *          Default: -1
     */
    private $bufferMaxMemory = -1;

    /**
     * @param HttplugStreamFactory|StreamFactoryInterface|null $streamFactory
     */
    public function __construct($streamFactory = null)
    {
        if ($streamFactory instanceof StreamFactoryInterface || $streamFactory instanceof HttplugStreamFactory) {
            $this->streamFactory = $streamFactory;

            return;
        }

        if (null !== $streamFactory) {
            throw new \LogicException(sprintf(
                'First arguemnt to the constructor of "%s" must be of type "%s", "%s" or null. Got %s',
                __CLASS__,
                StreamFactoryInterface::class,
                HttplugStreamFactory::class,
                \is_object($streamFactory) ? \get_class($streamFactory) : \gettype($streamFactory)
            ));
        }

        // Try to find a stream factory.
        try {
            $this->streamFactory = Psr17FactoryDiscovery::findStreamFactory();
        } catch (NotFoundException $psr17Exception) {
            try {
                $this->streamFactory = StreamFactoryDiscovery::find();
            } catch (NotFoundException $httplugException) {
                // we could not find any factory.
                throw $psr17Exception;
            }
        }
    }

    /**
     * Add a resource to the Multipart Stream.
     *
     * @param string|resource|\Psr\Http\Message\StreamInterface $resource the filepath, resource or StreamInterface of the data
     * @param array                                             $headers  additional headers array: ['header-name' => 'header-value']
     *
     * @return MultipartStreamBuilder
     */
    public function addData($resource, array $headers = [])
    {
        $stream = $this->createStream($resource);
        $this->data[] = ['contents' => $stream, 'headers' => $headers];

        return $this;
    }

    /**
     * Add a resource to the Multipart Stream.
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
        $stream = $this->createStream($resource);

        // validate options['headers'] exists
        if (!isset($options['headers'])) {
            $options['headers'] = [];
        }

        // Try to add filename if it is missing
        if (empty($options['filename'])) {
            $options['filename'] = null;
            $uri = $stream->getMetadata('uri');
            if ('php://' !== substr($uri, 0, 6)) {
                $options['filename'] = $uri;
            }
        }

        $this->prepareHeaders($name, $stream, $options['filename'], $options['headers']);

        return $this->addData($stream, $options['headers']);
    }

    /**
     * Build the stream.
     *
     * @return StreamInterface
     */
    public function build()
    {
        // Assign maximimum 1/4 php's available memory
        // to attempt buffering the stream content.
        // If the stream content exceed this, will fallback
        // to use temporary file.
        $maxmemory = ($this->bufferMaxMemory < 0)
            ? \floor(static::getAvailableMemory() / 4)
            : $this->bufferMaxMemory;

        // Open a temporary read-write stream as buffer.
        // If the size is less than predefined limit, things will stay in memory.
        // If the size is more than that, things will be stored in temp file.
        $buffer = fopen('php://temp/maxmemory:'.$maxmemory, 'r+');
        foreach ($this->data as $data) {
            // Add start and headers
            fwrite($buffer, "--{$this->getBoundary()}\r\n".
                $this->getHeaders($data['headers'])."\r\n");

            /**
             * @var \Psr\Http\Message\StreamInterface
             */
            $contentStream = $data['contents'];

            // Read stream into buffer
            if ($contentStream->isSeekable()) {
                $contentStream->rewind(); // rewind to beginning.
            }
            if ($contentStream->isReadable()) {
                while (!$contentStream->eof()) {
                    // read 8KB chunk into buffer until reached EOF.
                    fwrite($buffer, $contentStream->read(8192));
                }
            } else {
                // Try to getContents for non-readable stream.
                // Less controllable chunk size (thus memory usage).
                fwrite($buffer, $contentStream->getContents());
            }
            fwrite($buffer, "\r\n");
        }

        // Append end
        fwrite($buffer, "--{$this->getBoundary()}--\r\n");

        // Rewind to starting position for reading.
        fseek($buffer, 0);

        return $this->createStream($buffer);
    }

    /**
     * Add extra headers if they are missing.
     *
     * @param string $name
     * @param string $filename
     * @param array  &$headers
     */
    private function prepareHeaders($name, StreamInterface $stream, $filename, array &$headers)
    {
        $hasFilename = '0' === $filename || $filename;

        // Set a default content-disposition header if one was not provided
        if (!$this->hasHeader($headers, 'content-disposition')) {
            $headers['Content-Disposition'] = sprintf('form-data; name="%s"', $name);
            if ($hasFilename) {
                $headers['Content-Disposition'] .= sprintf('; filename="%s"', $this->basename($filename));
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
     * @param string $key case insensitive
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
        if (null === $this->boundary) {
            $this->boundary = uniqid('', true);
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
        if (null === $this->mimetypeHelper) {
            $this->mimetypeHelper = new ApacheMimetypeHelper();
        }

        return $this->mimetypeHelper;
    }

    /**
     * If you have custom file extension you may overwrite the default MimetypeHelper with your own.
     *
     * @return MultipartStreamBuilder
     */
    public function setMimetypeHelper(MimetypeHelper $mimetypeHelper)
    {
        $this->mimetypeHelper = $mimetypeHelper;

        return $this;
    }

    /**
     * Reset and clear all stored data. This allows you to use builder for a subsequent request.
     *
     * @return MultipartStreamBuilder
     */
    public function reset()
    {
        $this->data = [];
        $this->boundary = null;

        return $this;
    }

    /**
     * Gets the filename from a given path.
     *
     * PHP's basename() does not properly support streams or filenames beginning with a non-US-ASCII character.
     *
     * @author Drupal 8.2
     *
     * @param string $path
     *
     * @return string
     */
    private function basename($path)
    {
        $separators = '/';
        if (DIRECTORY_SEPARATOR != '/') {
            // For Windows OS add special separator.
            $separators .= DIRECTORY_SEPARATOR;
        }

        // Remove right-most slashes when $path points to directory.
        $path = rtrim($path, $separators);

        // Returns the trailing part of the $path starting after one of the directory separators.
        $filename = preg_match('@[^'.preg_quote($separators, '@').']+$@', $path, $matches) ? $matches[0] : '';

        return $filename;
    }

    /**
     * @param string|resource|StreamInterface $resource
     *
     * @return StreamInterface
     */
    private function createStream($resource)
    {
        if ($resource instanceof StreamInterface) {
            return $resource;
        }

        if ($this->streamFactory instanceof HttplugStreamFactory) {
            return $this->streamFactory->createStream($resource);
        }

        // Assert: We are using a PSR17 stream factory.
        if (\is_string($resource)) {
            return $this->streamFactory->createStream($resource);
        }

        if (\is_resource($resource)) {
            return $this->streamFactory->createStreamFromResource($resource);
        }

        throw new \InvalidArgumentException(sprintf('First argument to "%s::createStream()" must be a string, resource or StreamInterface.', __CLASS__));
    }

    /**
     * Setup the stream buffer size limit. PHP will allocate buffer
     * in memory if the size of the stream is smaller than this size.
     * Otherwise, PHP will store the stream data in a temporary file.
     *
     * @param int $size size of stream data buffered (in bytes)
     *                  until using temporary file to buffer
     */
    public function setBufferMaxMemory(int $size): MultipartStreamBuilder
    {
        $this->bufferMaxMemory = $size;

        return $this;
    }

    /**
     * Estimate the available memory in the system by php.ini memory_limit
     * and memory_get_usage(). If memory_limit is "-1", the default estimation
     * would be 100MB.
     *
     * @throws \Exception if the ini format does not match expectation
     */
    protected static function getAvailableMemory(): int
    {
        $memory_limit = ini_get('memory_limit');
        if ('-1' === $memory_limit) {
            // If there is no memory limit, return 100MB by default.
            return 100 * 1024 * 1024;
        }
        if (!preg_match('/^(\d+)(G|M|K|)$/', $memory_limit, $matches)) {
            throw new \Exception("Unknown memory_limit format: {$memory_limit}");
        }
        if ('G' === $matches[2]) {
            $memory_limit = $matches[1] * 1024 * 1024 * 1024; // nnnG -> nnn GB
        } elseif ('M' === $matches[2]) {
            $memory_limit = $matches[1] * 1024 * 1024; // nnnM -> nnn MB
        } elseif ('K' === $matches[2]) {
            $memory_limit = $matches[1] * 1024; // nnnK -> nnn KB
        }

        return (int) $memory_limit - \memory_get_usage();
    }
}
