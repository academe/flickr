<?php

namespace Academe\Flickr;

use Symfony\Component\HttpFoundation\Session\Session as Session;

class SymfonySession implements SessionInterface
{
    // The Symfony session.
    protected $session;

    // Pass a Symfony sesssion in when instantiating.
    public function __construct(Session $session)
    {
        $this->session = $session;
    }

    // We could probably just replace all these with a single __call() as
    // we are just passing what we are given on to the Symfony session
    // without any changes.
    // Except, the interface insists the methods are concrete. I'll leave
    // __call() in just in case there are other session methods we would
    // like to access.

    public function __call($method, $args) {
        return call_user_func_array(array($this->session, $method), $args);
    }

    public function has($name)
    {
        return $this->session->has($name);
    }

    public function get($name, $default = null)
    {
        return $this->session->get($name, $default);
    }

    public function set($name, $value)
    {
        return $this->session->set($name, $value);
    }

    public function remove($name)
    {
        return $this->session->remove($name);
    }
}
