<?php

namespace Academe\Flickr;

/**
 * The session interface that the Flickr API will use.
 * A SymfonySession concrete class is provided.
 */

Interface SessionInterface
{
    public function has($name);
    public function get($name, $default = null);
    public function set($name, $value);
    public function remove($name);
}


