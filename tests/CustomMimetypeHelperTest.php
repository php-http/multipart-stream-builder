<?php

namespace tests\Http\Message\MultipartStream;

use Http\Message\MultipartStream\CustomMimetypeHelper;

class CustomMimetypeHelperTest extends \PHPUnit_Framework_TestCase
{
    public function testGetMimetypeFromExtension()
    {
        $helper = new CustomMimetypeHelper(['foo'=>'foo/bar']);
        $this->assertEquals('foo/bar', $helper->getMimetypeFromExtension('foo'));

        $this->assertEquals('application/x-rar-compressed', $helper->getMimetypeFromExtension('rar'));
        $helper->addMimetype('rar', 'test/test');
        $this->assertEquals('test/test', $helper->getMimetypeFromExtension('rar'));
    }
}
