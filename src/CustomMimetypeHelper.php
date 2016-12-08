<?php

namespace Http\Message\MultipartStream;

/**
 * Let you add your own mimetypes.
 *
 * @author Tobias Nyholm <tobias.nyholm@gmail.com>
 */
class CustomMimetypeHelper extends ApacheMimetypeHelper
{
    /**
     * @var array
     */
    private $mimetypes = [];

    /**
     * @param array $mimetypes
     */
    public function __construct(array $mimetypes = [])
    {
        $this->mimetypes = $mimetypes;
    }

    /**
     * @param string $extension
     * @param string $mimetype
     *
     * @return $this
     */
    public function addMimetype($extension, $mimetype)
    {
        $this->mimetypes[$extension] = $mimetype;

        return $this;
    }

    /**
     * {@inheritdoc}
     *
     * Check if we have any defined mimetypes and of not fallback to ApacheMimetypeHelper
     */
    public function getMimetypeFromExtension($extension)
    {
        $extension = strtolower($extension);

        return isset($this->mimetypes[$extension])
            ? $this->mimetypes[$extension]
            : parent::getMimetypeFromExtension($extension);
    }
}
