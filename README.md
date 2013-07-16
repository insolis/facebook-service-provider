facebook-service-provider
=========================

It's a lightweight [Silex](http://silex.sensiolabs.org/) service to help creating Facebook applications.

## Dependencies

It depends on two other providers:

- [Session](http://silex.sensiolabs.org/doc/providers/session.html)
- [URL Generator](http://silex.sensiolabs.org/doc/providers/url_generator.html)

It also uses the current request.

## Configuration

It's done via the `fb.options` key. It's supposed to be an array with the following keys:

- `app_id`: your application's id
- `app_secret`: your application's secret token
- `permissions`: an array with the needed permissions
- `redirect_route`: name of the route which is responsible for handling new user authentications

## Usage

The service sets the following session keys:

- `fb.access_token`: the access token you can use to make requests against the graph API
- `fb.page_liked`: whether the current page is liked or not

A `before()` middleware is registered to be run before yours. It handles two cases:

- it's a post request and we have a `signed_request` parameter: decoding is done automatically and set among the post values
  with the `fb.data` key
- it's a request for the aforementioned redirect route, and we have a `code` query parameter: fetching a token, then the user details
  is done automatically, set among the post values with the `fb.data` key

In both cases the event `fb.user_info` is dispatched, with the user data as the subject.

### Samples

A sample application-level `before` middleware to log a user in automatically:

    $app->before(function (Request $request) use ($app) {
        if ($request->request->has("fbdata")) {
            $data = $request->request->get("fbdata");

            if (isset($data["user_id"])) {
                $app["session"]->set("user_id", $data["user_id"]);
            }
        }

        $route = $request->get("_route");
        if (
            !$app["session"]->has("user_id") &&
            !in_array($request->get("_route"), array("homepage", "fb_addhandler"))
        ) {
            return new Response("<script type='text/javascript'>top.location = '" . $app["fb"]->getAuthorizationUrl() . "';</script>");
        }
    });

A sample redirect route to handle new user authentications:

    $app->post("/fb_addhandler", function (Request $request) use ($app) {
        if (!$request->request->has("fbdata")) {
            return $app->redirect($app["url_generator"]->generate("homepage"));
        }

        $data = $request->request->get("fbdata");

        try {
            $app["db.user"]->insert(array(
                "id"    =>  $data["id"],
            ));
        }
        catch (\Exception $e) {
            //this is not the user's first time enabling the app
        }

        $app["session"]->set("user_id", $data["id"]);

        return $app->redirect($app["url_generator"]->generate("homepage"));
    })->bind("fb_addhandler");
