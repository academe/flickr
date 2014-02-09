<?php

namespace Academe\Flickr;

use Silex\Application;
use Silex\ServiceProviderInterface;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Request;

class SilexServiceProvider implements ServiceProviderInterface
{
    public function register(Application $app)
    {
        // TODO: the default redirect should be the base URL, not the root folder.
        $app['flickrapi.default_options'] = array(
            'api_key' => '',
            'api_secret' => '',
            'default_redirect' => '/',
            'permissions' => 'read',
        );

        // TODO: the options passed in should have their gaps filled in with the defaults.
        $app['flickrapi'] = $app->share(function () use ($app) {
            $session = new SymfonySession($app['session']);

            return new Api(
                $session,
                $app['flickrapi.options']['api_key'],
                $app['flickrapi.options']['api_secret']
            );
        });

    }

    public function boot(Application $app)
    {
    }
}

