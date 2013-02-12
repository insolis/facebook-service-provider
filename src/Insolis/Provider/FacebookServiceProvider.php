<?php

namespace Insolis\Provider;

use Insolis\Service\FacebookService;
use Silex\Application;
use Silex\ServiceProviderInterface;
use Symfony\Component\HttpFoundation\Request;

class FacebookServiceProvider implements ServiceProviderInterface
{
    public function register(Application $app) {
        $app["fb"] = $app->share(function() use ($app) {
            return new FacebookService($app["fb.options"], $app);
        });
    }

    public function boot(Application $app) {
        $app->before(function (Request $request) use ($app) {
            if ($request->request->has("signed_request") && $app["fb"]->isSignedRequestValid()) {
                $data = $app["fb"]->decodeSignedRequest();

                $app["monolog"]->addInfo("ezt kaptuk a fbtol", $data);
                $request->request->set("fbdata", $data);

                $app["fb"]->isPageLiked(); //sessionbe mentes

                if (isset($data["oauth_token"])) {
                    $app["session"]->set("access_token", $data["oauth_token"]);
                }

            }

            if ($request->get("_route") === $app["fb.options"]["redirect_route"] && $request->query->has("code")) {
                $data = $app["fb"]->getUserData();

                $app["monolog"]->addInfo("ezt kaptuk a fbtol", $data);
                $request->request->set("fbdata", $data);
            }
        }, 1);

    }
}
