<?php

namespace tests\Http\Message\MultipartStream;

use Http\Message\MultipartStream\MultipartStreamBuilder;
use Zend\Diactoros\Stream;

/**
 * @author Tobias Nyholm <tobias.nyholm@gmail.com>
 */
class FunctionTest extends \PHPUnit_Framework_TestCase
{
    public function testSupportStreams()
    {
        $body = 'stream contents';

        $builder = new MultipartStreamBuilder();
        $builder->addResource('foobar', $this->createStream($body));

        $multipartStream = (string) $builder->build();
        $this->assertTrue(false !== strpos($multipartStream, $body));
    }

    public function testSupportResources()
    {
        $resource = fopen(__DIR__.'/Resources/httplug.png', 'r');

        $builder = new MultipartStreamBuilder();
        $builder->addResource('image', $resource);

        $multipartStream = (string) $builder->build();
        $this->assertTrue(false !== strpos($multipartStream, 'Content-Disposition: form-data; name="image"; filename="httplug.png"'));
        $this->assertTrue(false !== strpos($multipartStream, 'Content-Type: image/png'));
    }

    public function testResourcesWithStrangeNames()
    {
        // Get current locale
        $originalLocale = setlocale(LC_ALL, "0");

        // Set locale to something strange.
        //setlocale(LC_ALL, 'C');

        $resource = fopen(__DIR__.'/Resources/httplug.png', 'r');
        $builder = new MultipartStreamBuilder();
        $builder->addResource('image', $resource, ['filename'=> 'äa.png']);

        $multipartStream = (string) $builder->build();
        $this->assertTrue(0 < preg_match('|filename="([^"]*?)"|si', $multipartStream, $matches), 'Could not find any filename in output.');
        $this->assertEquals('äa.png', $matches[1]);
        $this->assertTrue(false !== mb_strpos($multipartStream, 'Content-Disposition: form-data; name="image"; filename="äa.png"'));

        // Reset the locale
        setlocale(LC_ALL, $originalLocale);
    }

    public function testHeaders()
    {
        $builder = new MultipartStreamBuilder();
        $builder->addResource('foobar', 'stream contents', ['headers' => ['Content-Type' => 'html/image', 'content-length' => '4711', 'CONTENT-DISPOSITION' => 'none']]);

        $multipartStream = (string) $builder->build();
        $this->assertTrue(false !== strpos($multipartStream, 'Content-Type: html/image'));
        $this->assertTrue(false !== strpos($multipartStream, 'content-length: 4711'));
        $this->assertTrue(false !== strpos($multipartStream, 'CONTENT-DISPOSITION: none'));

        // Make sure we do not add extra headers with a different case
        $this->assertTrue(false === strpos($multipartStream, 'Content-Disposition:'));
    }

    public function testContentLength()
    {
        $builder = new MultipartStreamBuilder();
        $builder->addResource('foobar', 'stream contents');

        $multipartStream = (string) $builder->build();
        $this->assertTrue(false !== strpos($multipartStream, 'Content-Length: 15'));
    }

    public function testFormName()
    {
        $builder = new MultipartStreamBuilder();
        $builder->addResource('a-formname', 'string');

        $multipartStream = (string) $builder->build();
        $this->assertTrue(false !== strpos($multipartStream, 'Content-Disposition: form-data; name="a-formname"'));
    }

    public function testBoundary()
    {
        $boundary = 'SpecialBoundary';
        $builder = new MultipartStreamBuilder();
        $builder->addResource('content0', 'string');
        $builder->setBoundary($boundary);

        $multipartStream = (string) $builder->build();
        $this->assertEquals(2, substr_count($multipartStream, $boundary));

        $builder->addResource('content1', 'string');
        $builder->addResource('content2', 'string');
        $builder->addResource('content3', 'string');

        $multipartStream = (string) $builder->build();
        $this->assertEquals(5, substr_count($multipartStream, $boundary));
    }

    /**
     * @param string $body
     *
     * @return Stream
     */
    private function createStream($body)
    {
        $stream = new Stream('php://memory', 'rw');
        $stream->write($body);
        $stream->rewind();

        return $stream;
    }
}
