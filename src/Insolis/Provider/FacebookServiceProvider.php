<?php

namespace Insolis\Provider;

use Insolis\Service\FacebookService;
use Silex\Application;
use Silex\ServiceProviderInterface;

class FacebookServiceProvider implements ServiceProviderInterface
{
    public function register(Application $app) {
        $app["fb"] = $app->share(function() use ($app) {
            return new FacebookService($app["fb.options"], $app);
        });
    }

    public function boot(Application $app) {}
}
