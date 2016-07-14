<?php

namespace Http\Message;

use Psr\Http\Message\StreamInterface;

interface MultipartStreamInterface extends StreamInterface
{
    /**
     * Return the boundary that separates streams. 
     * @return string
     */
    public function getBoundary();
}