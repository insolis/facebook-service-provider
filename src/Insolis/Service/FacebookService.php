<?php

namespace Insolis\Service;

use Silex\Application;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class FacebookService
{
    const ACCESS_TOKEN_KEY          = "fb.access_token";
    const ACCESS_TOKEN_EXPIRES_KEY  = "fb.access_token_expires";

    const DATA_KEY                  = "fb.data";

    const PAGE_ADMIN_KEY            = "fb.page_admin";
    const PAGE_ID_KEY               = "fb.page_id";
    const PAGE_LIKED_KEY            = "fb.page_liked";

    const EVENT_LIKE                = "fb.like";
    const EVENT_UNLIKE              = "fb.unlike";
    const EVENT_USER_INFO           = "fb.user_info";

    protected $app_id;
    protected $app_secret;
    protected $permissions;
    protected $redirect_route;

    /** @var Request */
    protected $request = null;

    /** @var UrlGeneratorInterface */
    protected $url_generator;

    /** @var SessionInterface */
    protected $session;

    /** @var EventDispatcherInterface */
    protected $dispatcher;

    /** @var Application */
    protected $app;

    function __construct($config, UrlGeneratorInterface $url_generator, SessionInterface $session, EventDispatcherInterface $dispatcher, Application $app)
    {
        $this->app_id         = $config["app_id"];
        $this->app_secret     = $config["app_secret"];
        $this->permissions    = $config["permissions"];
        $this->redirect_route = $config["redirect_route"];

        $this->app           = $app;
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
        if (!($signed_request = $this->getRequest()->request->get("signed_request"))) {
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
        if (!($signed_request = $this->getRequest()->request->get("signed_request"))) {
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
            "code"          => $this->getRequest()->get("code"),
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
        $this->session->set(static::ACCESS_TOKEN_KEY, $params["access_token"]);

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
        $access_token = $this->getAccessToken();

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
        $access_token = $this->getAccessToken();

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
        $access_token = $this->getAccessToken();

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
     * Returns if the current page is liked.
     *
     * @return bool
     */
    public function isPageLiked()
    {
        return $this->session->get(static::PAGE_LIKED_KEY, false);
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
     * Saves data to session from the signed request for later retrieval
     */
    public function saveDataFromSignedRequestToSession()
    {
        $data = $this->getRequest()->get("fb.data");

        if (isset($data["oauth_token"])) {
            $this->session->set(static::ACCESS_TOKEN_KEY, $data["oauth_token"]);
            $this->session->set(static::ACCESS_TOKEN_EXPIRES_KEY, $data["expires"]);
        }

        if (isset($data["page"])) {
            if ($this->session->has(static::PAGE_LIKED_KEY) && $this->session->get(static::PAGE_LIKED_KEY) != $data["page"]["liked"]) {
                if ($data["page"]["liked"]) {
                    $this->dispatcher->dispatch(static::EVENT_LIKE);
                } else {
                    $this->dispatcher->dispatch(static::EVENT_UNLIKE);
                }
            }

            $this->session->set(static::PAGE_LIKED_KEY, $data["page"]["liked"]);
            $this->session->set(static::PAGE_ID_KEY,    $data["page"]["id"]);
            $this->session->set(static::PAGE_ADMIN_KEY, $data["page"]["admin"]);
        }
    }

    /**
     * Sends a Facebook notification to the user
     *
     * @param int $user_id the user's id
     * @param string $text the text to be sent
     * @param string|null $href optional href to be appended to the URL when the user clicks on the notif
     * @param string|null $ref optional ref so you can distinguish between notifications in insights
     *
     * @throws \InvalidArgumentException when the text is too long
     * @return array response of the Graph API
     *
     * @see http://developers.facebook.com/docs/games/notifications/
     */
    public function sendNotification($user_id, $text, $href = null, $ref = null)
    {
        if (mb_strlen($text) > 180) {
            throw new \InvalidArgumentException("The message cannot be longer than 180 characters.");
        }

        $query_data = array(
            "template"      =>  $text,
            "access_token"  =>  $this->getAppAccessToken(),
            "method"        => "post",
        );

        if ($href) {
            $query_data["href"] = $href;
        }

        if ($ref) {
            $query_data["ref"] = $ref;
        }

        $apprequest_url ="https://graph.facebook.com/" . $user_id .
            "/notifications?" . http_build_query($query_data);

        return json_decode(@file_get_contents($apprequest_url), true);
    }

    /**
     * Gets the application access token from Facebook
     *
     * @return mixed
     */
    public function getAppAccessToken()
    {
        static $token = null;

        if (!$token) {
            $token_url = "https://graph.facebook.com/oauth/access_token?" .
                "client_id=" . $this->app_id .
                "&client_secret=" . $this->app_secret .
                "&grant_type=client_credentials";

            $token = str_replace("access_token=", "", file_get_contents($token_url));
        }

        return $token;
    }

    /**
     * Gets the current users friends
     *
     * @param $limit int
     * @param $offset int
     *
     * @return array
     * @throws \BadMethodCallException when there's no access_token saved in the session
     */
    public function getFriends($limit = 5000, $offset = 0)
    {
        if (!$this->getAccessToken()) {
            throw new \BadMethodCallException("getFriends() needs an access token");
        }

        $url = "https://graph.facebook.com/me/friends?" . http_build_query([
                "limit"         =>  $limit,
                "offset"        =>  $offset,
                "access_token"  =>  $this->getAccessToken(),
        ]);

        $data = json_decode(file_get_contents($url), true);

        return $data["data"];
    }

    /**
     * Returns the current access token
     *
     * @return null|string
     */
    public function getAccessToken()
    {
        return $this->session->get(static::ACCESS_TOKEN_KEY);
    }

    public function deleteAppRequest($full_id)
    {
        $access_token = $this->getAppAccessToken();

        $c = curl_init();
        curl_setopt_array($c, array(
            CURLOPT_URL             =>  sprintf("https://graph.facebook.com/%s?access_token=%s", $full_id, $access_token),
            CURLOPT_RETURNTRANSFER  =>  true,
            CURLOPT_CUSTOMREQUEST   =>  "DELETE",
        ));

        curl_exec($c);
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

    protected function getRequest()
    {
        if (!$this->request) {
            $this->request = $this->app["request"];
        }

        return $this->request;
    }
}
