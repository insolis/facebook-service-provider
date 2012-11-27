<?php

namespace Insolis\Service;

use Silex\Application;
use Symfony\Component\HttpFoundation\Request;

class FacebookService
{
    protected $app;
    protected $app_id;
    protected $app_secret;
    protected $app_url;
    protected $canvas_url;
    protected $permissions;
    protected $redirect_route;

    function __construct($config, Application $app) {
        $this->app            = $app;
        $this->app_id         = $config["app_id"];
        $this->app_secret     = $config["app_secret"];
        $this->app_url        = $config["app_url"];
        $this->canvas_url     = $config["canvas_url"];
        $this->permissions    = $config["permissions"];
        $this->redirect_route = $config["redirect_route"];
    }

    /**
    * Verifies a signature of a signed request
    *
    * @return boolean
    *
    * @throws BadFunctionCallException when there's no signed_request parameter
    * @throws UnexpectedValueException when the algorithm is not hmac-sha256
    */
    public function isSignedRequestValid() {
        if (!($signed_request = $this->app["request"]->get("signed_request"))) {
            throw new \BadFunctionCallException("Not a signed request");
        }
        list($encoded_sig, $payload) = explode('.', $signed_request, 2);

        $sig = self::base64_url_decode($encoded_sig);
        $data = json_decode(self::base64_url_decode($payload), true);

        if (strtoupper($data['algorithm']) !== 'HMAC-SHA256') {
            throw new \UnexpectedValueException("Unknown algorithm. Expected HMAC-SHA256");
        }

        $expected_sig = hash_hmac('sha256', $payload, $this->app_secret, $raw = true);

        return $sig == $expected_sig;
    }

    /**
    * Decodes the data in the signed_request and returns it
    *
    * @return array
    *
    * @throws \BadFunctionCallException when there's no signed_request parameter
    */
    public function decodeSignedRequest() {
        if (!($signed_request = $this->app["request"]->get("signed_request"))) {
            throw new \BadFunctionCallException("Not a signed request");
        }
        list(, $payload) = explode('.', $signed_request, 2);

        $data = json_decode(self::base64_url_decode($payload), true);

        unset($data["algorithm"]);
        return $data;
    }

    /**
    * Returns the authorization url neccessary for the required permissions
    *
    * @return string
    */
    public function getAuthorizationUrl() {
        return "https://www.facebook.com/dialog/oauth?" . http_build_query(array(
            "client_id"     =>  $this->app_id,
            "redirect_uri"  =>  $this->app["url_generator"]->generate($this->redirect_route, array(), true),
            "scope"         =>  implode(",", $this->permissions),
        ));
    }

    /**
    * Returns the url that can be used to get an access token from a code (right after auth)
    *
    * @return string
    */
    public function getTokenUrl() {
        return "https://graph.facebook.com/oauth/access_token?" . http_build_query(array(
            "client_id"     =>  $this->app_id,
            "redirect_uri"  =>  $this->app["url_generator"]->generate($this->redirect_route, array(), true),
            "client_secret" =>  $this->app_secret,
            "code"          =>  $this->app["request"]->get("code"),
        ));
    }

    /**
     * Fetches user data from facebook.com using the code query parameter
     *
     * @return array user data
     */
    public function getUserData() {
        $token_url = $this->getTokenUrl();
        $params = array();
        parse_str(file_get_contents($token_url), $params);
        $this->app["session"]->set("access_token", $params["access_token"]);

        $graph_url = "https://graph.facebook.com/me?access_token=" . $params["access_token"];
        return json_decode(file_get_contents($graph_url), true);
    }

    /**
     * Gets the user's album ID that has the given name
     *
     * @param   string  the album's name
     *
     * @return  mixed   bool(false) if not found, ID otherwise
     */
    public function getAlbumId($album_name) {
        $access_token = $this->app["session"]->get("access_token");

        $albums = json_decode(file_get_contents("https://graph.facebook.com/me/albums?access_token=" . $access_token), true);

        foreach($albums["data"] as $album) {
            if ($album["name"] === $album_name) {
                return $album["id"];
            }
        }

        return false;
    }

    /**
     * Creates a new photo album
     *
     * @param   string  the album's name
     *
     * @return  id      the album's id
     */
    public function createAlbum($album_name) {
        $access_token = $this->app["session"]->get("access_token");

        $context = stream_context_create(array(
            "http" => array(
                "method" => "POST",
                "content" => http_build_query(array(
                    "name" => $album_name,
                )),
            ),
        ));
        $data = json_decode(@file_get_contents("https://graph.facebook.com/me/albums?access_token=" . $access_token, false, $context), true);

        return $data["id"];
    }

    /**
     *
     * @param type $album_id
     * @param type $filename
     * @param type $message
     */
    public function uploadPicture($album_id, $filename, $message = null) {
        $access_token = $this->app["session"]->get("access_token");

        $ch = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_POST            =>  true,
            CURLOPT_RETURNTRANSFER  =>  true,
            CURLOPT_POSTFIELDS      => array(
                "source"    =>  "@{$filename}",
                "message"   =>  $message,
            ),
            CURLOPT_URL             =>  "https://graph.facebook.com/{$album_id}/photos?access_token={$access_token}",
        ));
        $data = curl_exec($ch);

        return json_decode($data, true);
    }

    /**
     *
     */
    public function getOrCreateAlbum($album_name) {
        $aid = $this->getAlbumId($album_name);

        if (false === $aid) {
            $aid = $this->createAlbum($album_name);
        }

        return $aid;
    }

    /**
     *
     */
    public function isPageLiked() {
        $session = $this->app["session"]; /* @var $session \Symfony\Component\HttpFoundation\Session\Session */

        try {
            $data = $this->decodeSignedRequest();
            $liked = isset($data["page"]["liked"]) && $data["page"]["liked"];
            $session->set("page_liked", $liked);

            return $liked;
        }
        catch (\Exception $e) {
            if ($session->has("page_liked")) {
                return $session->get("page_liked");
            }

            return null;
        }
    }

    /**
     * @param string $url
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

        return $items->item(0)->nodeValue;
    }
    
    /**
    *
    */
    public function getLongLivedAccessToken($existing_token)
    {
        $url = sprintf("https://graph.facebook.com/oauth/access_token?client_id=%s&client_secret=%s&"
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
    protected static function base64_url_decode($input) {
        return base64_decode(strtr($input, '-_', '+/'));
    }
}
