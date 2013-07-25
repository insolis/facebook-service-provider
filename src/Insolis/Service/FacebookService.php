<?php

namespace Insolis\Service;

use Silex\Application;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\Routing\Generator\UrlGenerator;

class FacebookService
{
    protected $app_id;
    protected $app_secret;
    protected $permissions;
    protected $redirect_route;

    /** @var Request */
    protected $request;

    /** @var UrlGenerator */
    protected $url_generator;

    /** @var Session */
    protected $session;

    /** @var EventDispatcher */
    protected $dispatcher;

    function __construct($config, Request $request, UrlGenerator $url_generator, Session $session, EventDispatcher $dispatcher)
    {
        $this->app_id         = $config["app_id"];
        $this->app_secret     = $config["app_secret"];
        $this->permissions    = $config["permissions"];
        $this->redirect_route = $config["redirect_route"];

        $this->request       = $request;
        $this->url_generator = $url_generator;
        $this->session       = $session;
        $this->dispatcher    = $dispatcher;
    }

    /**
     * Verifies the signature of the signed_request
     *
     * @return boolean
     *
     * @throws \BadFunctionCallException when there's no signed_request parameter
     * @throws \UnexpectedValueException when the algorithm is not hmac-sha256
     */
    public function isSignedRequestValid()
    {
        if (!($signed_request = $this->request->request->get("signed_request"))) {
            throw new \BadFunctionCallException("Not a signed request");
        }
        list($encoded_sig, $payload) = explode('.', $signed_request, 2);

        $sig  = $this->base64_url_decode($encoded_sig);
        $data = json_decode($this->base64_url_decode($payload), true);

        if (strtoupper($data['algorithm']) !== 'HMAC-SHA256') {
            throw new \UnexpectedValueException("Unknown algorithm. Expected HMAC-SHA256");
        }

        $expected_sig = hash_hmac('sha256', $payload, $this->app_secret, $raw = true);

        return $sig === $expected_sig;
    }

    /**
     * Decodes the data in the signed_request and returns it
     *
     * @return array
     *
     * @throws \BadFunctionCallException when there's no signed_request parameter
     */
    public function decodeSignedRequest()
    {
        if (!($signed_request = $this->request->request->get("signed_request"))) {
            throw new \BadFunctionCallException("Not a signed request");
        }
        list(, $payload) = explode('.', $signed_request, 2);

        $data = json_decode($this->base64_url_decode($payload), true);

        unset($data["algorithm"]);

        return $data;
    }

    /**
     * Returns the authorization url neccessary for the required permissions
     *
     * @return string
     */
    public function getAuthorizationUrl()
    {
        return "https://www.facebook.com/dialog/oauth?" . http_build_query(array(
            "client_id"    => $this->app_id,
            "redirect_uri" => $this->url_generator->generate($this->redirect_route, array(), true),
            "scope"        => implode(",", $this->permissions),
        ));
    }

    /**
     * Returns the url that can be used to get an access token from a code (durig user authorization)
     *
     * @return string
     */
    public function getTokenUrl()
    {
        return "https://graph.facebook.com/oauth/access_token?" . http_build_query(array(
            "client_id"     => $this->app_id,
            "redirect_uri"  => $this->url_generator->generate($this->redirect_route, array(), true),
            "client_secret" => $this->app_secret,
            "code"          => $this->request->get("code"),
        ));
    }

    /**
     * Fetches user data from facebook.com using the code query parameter
     *
     * @return array user data
     */
    public function getUserData()
    {
        $token_url = $this->getTokenUrl();

        $params = array();
        parse_str(file_get_contents($token_url), $params);
        $this->session->set("fb.access_token", $params["access_token"]);

        $graph_url = "https://graph.facebook.com/me?access_token=" . $params["access_token"];

        return json_decode(file_get_contents($graph_url), true);
    }

    /**
     * Gets the user's album ID that has the given name
     *
     * @param string $album_name the album's name
     *
     * @return mixed false if not found, ID otherwise
     */
    public function getAlbumId($album_name)
    {
        $access_token = $this->session->get("fb.access_token");

        $albums = json_decode(file_get_contents("https://graph.facebook.com/me/albums?access_token=" . $access_token), true);

        foreach ($albums["data"] as $album) {
            if ($album["name"] === $album_name) {
                return $album["id"];
            }
        }

        return false;
    }

    /**
     * Creates a new photo album
     *
     * @param string $album_name the album's name
     *
     * @return int the album's id
     */
    public function createAlbum($album_name)
    {
        $access_token = $this->session->get("fb.access_token");

        $context = stream_context_create(array(
            "http" => array(
                "method"  => "POST",
                "content" => http_build_query(array(
                    "name" => $album_name,
                )),
            ),
        ));
        $data = json_decode(@file_get_contents("https://graph.facebook.com/me/albums?access_token=" . $access_token, false, $context), true);

        return $data["id"];
    }

    /**
     * Uploads a picture to the given album
     *
     * @param int $album_id
     * @param string $filename
     * @param string $message
     *
     * @return mixed the data returned by facebook
     */
    public function uploadPicture($album_id, $filename, $message = null)
    {
        $access_token = $this->session->get("fb.access_token");

        $ch = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS     => array(
                "source"  => "@{$filename}",
                "message" => $message,
            ),
            CURLOPT_URL            => "https://graph.facebook.com/{$album_id}/photos?access_token={$access_token}",
        ));
        $data = curl_exec($ch);

        return json_decode($data, true);
    }

    /**
     * Returns the ID of the needed album, creates it if it's neccessary
     *
     * @param string $album_name
     *
     * @return int
     */
    public function getOrCreateAlbum($album_name)
    {
        $aid = $this->getAlbumId($album_name);

        if (false === $aid) {
            $aid = $this->createAlbum($album_name);
        }

        return $aid;
    }

    /**
     * Returns if the current page is liked. Also saves it to the session for later retrieval.
     *
     * @return bool
     */
    public function isPageLiked()
    {
        try {
            $data  = $this->decodeSignedRequest();
            $liked = isset($data["page"]["liked"]) && $data["page"]["liked"];

            // if we get a liked value and it's not the same as the one in the session
            if (isset($data["page"]["liked"]) && $this->session->has("fb.page_liked") && $this->session->get("fb.page_liked") != $data["page"]["liked"]) {
                if ($data["page"]["liked"]) {
                    $this->dispatcher->dispatch("fb.like");
                } else {
                    $this->dispatcher->dispatch("fb.unlike");
                }
            }

            $this->session->set("fb.page_liked", $liked);

            return $liked;
        } catch (\Exception $e) {
            return $this->session->get("fb.page_liked");
        }
    }

    /**
     * Gets the like count for a given url
     *
     * @param string $url
     *
     * @return int
     */
    public function getLikeCount($url)
    {
        $link = "https://api.facebook.com/method/fql.query?" . http_build_query(array(
                "query" => "SELECT url, normalized_url, share_count, like_count, comment_count, total_count, commentsbox_count, comments_fbid, click_count FROM link_stat WHERE url=\"" . urlencode($url) . "\"",
            ));

        $response = file_get_contents($link);

        $domd = new \DOMDocument();
        $domd->loadXML($response);

        $items = $domd->getElementsByTagName("total_count");

        if (!$items->length) return 0;

        return (int)$items->item(0)->nodeValue;
    }

    /**
     * Gets a long-loved access token from the current short-lived one
     *
     * @param string $existing_token
     *
     * @return string
     */
    public function getLongLivedAccessToken($existing_token)
    {
        $url      = sprintf("https://graph.facebook.com/oauth/access_token?client_id=%s&client_secret=%s&"
            . "grant_type=fb_exchange_token&fb_exchange_token=%s",
            $this->app_id,
            $this->app_secret,
            $existing_token
        );
        $contents = file_get_contents($url);
        parse_str($contents, $output);

        return $output["access_token"];
    }

    /**
     * base64_url decodes a string
     *
     * @param string $input
     * @return string
     */
    protected function base64_url_decode($input)
    {
        return base64_decode(strtr($input, '-_', '+/'));
    }
}
