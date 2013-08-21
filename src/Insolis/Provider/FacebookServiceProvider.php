<?php

namespace Insolis\Provider;

use Insolis\Service\FacebookService;
use Silex\Application;
use Silex\ServiceProviderInterface;
use Symfony\Component\EventDispatcher\GenericEvent;
use Symfony\Component\HttpFoundation\Request;

class FacebookServiceProvider implements ServiceProviderInterface
{
    public function register(Application $app)
    {
        $app["fb"] = $app->share(function () use ($app) {
            return new FacebookService($app["fb.options"], $app["request"], $app["url_generator"], $app["session"], $app["dispatcher"]);
        });
    }

    public function boot(Application $app)
    {
        $app->before(function (Request $request) use ($app) {
            if ($request->request->has("signed_request") && $app["fb"]->isSignedRequestValid()) {
                $data = $app["fb"]->decodeSignedRequest();

                $app["dispatcher"]->dispatch("fb.user_info", new GenericEvent($data));
                $request->request->set("fb.data", $data);

                $app["fb"]->saveDataFromSignedRequestToSession();
            }

            if ($request->get("_route") === $app["fb.options"]["redirect_route"] && $request->query->has("code")) {
                $data = $app["fb"]->getUserData();

                $app["dispatcher"]->dispatch("fb.user_info", new GenericEvent($data));
                $request->request->set("fb.data", $data);
            }
        }, 1);

    }
}
