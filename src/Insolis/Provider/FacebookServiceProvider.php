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
            if (!isset($app["session"])) {
                throw new \RuntimeException("FacebookService needs the SessionService to be registered.");
            }

            if (!isset($app["url_generator"])) {
                throw new \RuntimeException("FacebookService needs the UrlGeneratorService to be registered.");
            }

            return new FacebookService($app["fb.options"],$app["url_generator"], $app["session"], $app["dispatcher"], $app);
        });
    }

    public function boot(Application $app)
    {
        $app->before(function (Request $request) use ($app) {
            if ($request->request->has("signed_request") && $app["fb"]->isSignedRequestValid()) {
                $data = $app["fb"]->decodeSignedRequest();

                $app["dispatcher"]->dispatch(FacebookService::EVENT_USER_INFO, new GenericEvent($data));
                $request->request->set(FacebookService::DATA_KEY, $data);

                $app["fb"]->saveDataFromSignedRequestToSession();
            }

            if ($request->get("_route") === $app["fb.options"]["redirect_route"] && $request->query->has("code")) {
                $data = $app["fb"]->getUserData();

                $app["dispatcher"]->dispatch(FacebookService::EVENT_USER_INFO, new GenericEvent($data));
                $request->request->set(FacebookService::DATA_KEY, $data);
            }
        }, 1);
    }
}
