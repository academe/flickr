<?php

namespace Academe\Flickr;

/**
 * Flickr API, based on http://phpflickr.com/ (see comment block below).
 * Make PSR-4 by:
 * Author: Jason Judge <judgej@github.com>
 */

/*  phpFlickr Class 3.1
 *  Written by Dan Coulter (dan@dancoulter.com)
 *  Project Home Page: http://phpflickr.com/
 *  Released under GNU Lesser General Public License (http://www.gnu.org/copyleft/lgpl.html)
 *  For more information about the class and upcoming tools and toys using it,
 *  visit http://www.phpflickr.com/
 *
 *  For installation instructions, open the README.txt file packaged with this
 *  class. If you don't have a copy, you can see it at:
 *  http://www.phpflickr.com/README.txt
 *
 *  Please submit all problems or questions to the Help Forum on my Google Code project page:
 *      http://code.google.com/p/phpflickr/issues/list
 *
 */

use Academe\Flickr\SessionInterface;

class Api
{
    // The name of the token session variable.
    public $token_session_var = 'phpFlickr_auth_token';

    // The name of the redirect URL session variable.
    public $redirect_session_var = 'phpFlickr_auth_redirect';

    public $api_key;
    public $secret;

    protected $session;

    public $rest_endpoint = 'http://api.flickr.com/services/rest/';
    public $upload_endpoint = 'http://api.flickr.com/services/upload/';
    public $replace_endpoint = 'http://api.flickr.com/services/replace/';
    public $req;
    public $response;
    public $parsed_response;
    public $cache = false;
    public $cache_db = null;
    public $cache_table = null;
    public $cache_dir = null;
    public $cache_expire = null;
    public $cache_key = null;
    public $last_request = null;
    public $die_on_error;
    public $error_code;
    public $error_msg;
    public $token;
    public $php_version;
    public $custom_post = null, $custom_cache_get = null, $custom_cache_set = null;

    /*
     * When your database cache table hits this many rows, a cleanup
     * will occur to get rid of all of the old rows and cleanup the
     * garbage in the table.  For most personal apps, 1000 rows should
     * be more than enough.  If your site gets hit by a lot of traffic
     * or you have a lot of disk space to spare, bump this number up.
     * You should try to set it high enough that the cleanup only
     * happens every once in a while, so this will depend on the growth
     * of your table.
     */
    public $max_cache_rows = 1000;

    public function __construct(SessionInterface $session, $api_key, $secret = NULL, $die_on_error = false) {
        $this->session = $session;

        // The API Key must be set before any calls can be made.  You can
        // get your own at http://www.flickr.com/services/api/misc.api_keys.html
        $this->api_key = $api_key;
        $this->secret = $secret;
        $this->die_on_error = $die_on_error;
        $this->service = "flickr";

        //Find the PHP version and store it for future reference
        $this->php_version = explode("-", phpversion());
        $this->php_version = explode(".", $this->php_version[0]);
    }

    public function enableCache ($type, $connection, $cache_expire = 600, $table = 'flickr_cache') {
        // Turns on caching.  $type must be either "db" (for database caching) or "fs" (for filesystem).
        // When using db, $connection must be a PEAR::DB connection string. Example:
        //	  "mysql://user:password@server/database"
        // If the $table, doesn't exist, it will attempt to create it.
        // When using file system, caching, the $connection is the folder that the web server has write
        // access to. Use absolute paths for best results.  Relative paths may have unexpected behavior
        // when you include this.  They'll usually work, you'll just want to test them.
        if ($type == 'db') {
            if ( preg_match('|mysql://([^:]*):([^@]*)@([^/]*)/(.*)|', $connection, $matches) ) {
                //Array ( [0] => mysql://user:password@server/database [1] => user [2] => password [3] => server [4] => database ) 
                $db = mysql_connect($matches[3], $matches[1], $matches[2]);
                mysql_select_db($matches[4], $db);

                /*
                 * If high performance is crucial, you can easily comment
                 * out this query once you've created your database table.
                 */
                mysql_query("
                    CREATE TABLE IF NOT EXISTS `$table` (
                        `request` CHAR( 35 ) NOT NULL ,
                        `response` MEDIUMTEXT NOT NULL ,
                        `expiration` DATETIME NOT NULL ,
                        INDEX ( `request` )
                    ) TYPE = MYISAM
                ", $db);

                $result = mysql_query("SELECT COUNT(*) FROM $table", $db);
                $result = mysql_fetch_row($result);
                if ( $result[0] > $this->max_cache_rows ) {
                    mysql_query("DELETE FROM $table WHERE expiration < DATE_SUB(NOW(), INTERVAL $cache_expire second)", $db);
                    mysql_query('OPTIMIZE TABLE ' . $this->cache_table, $db);
                }
                $this->cache = 'db';
                $this->cache_db = $db;
                $this->cache_table = $table;
            }
        } elseif ($type == 'fs') {
            $this->cache = 'fs';
            $connection = realpath($connection);
            $this->cache_dir = $connection;
            if ($dir = opendir($this->cache_dir)) {
                while ($file = readdir($dir)) {
                    if (substr($file, -6) == '.cache' && ((filemtime($this->cache_dir . '/' . $file) + $cache_expire) < time()) ) {
                        unlink($this->cache_dir . '/' . $file);
                    }
                }
            }
        } elseif ( $type == 'custom' ) {
            $this->cache = "custom";
            $this->custom_cache_get = $connection[0];
            $this->custom_cache_set = $connection[1];
        }
        $this->cache_expire = $cache_expire;
    }

    public function getCached ($request)
    {
        //Checks the database or filesystem for a cached result to the request.
        //If there is no cache result, it returns a value of false. If it finds one,
        //it returns the unparsed XML.
        foreach ( $request as $key => $value ) {
            if ( empty($value) ) unset($request[$key]);
            else $request[$key] = (string) $request[$key];
        }
        //if ( is_user_logged_in() ) print_r($request);
        $reqhash = md5(serialize($request));
        $this->cache_key = $reqhash;
        $this->cache_request = $request;
        if ($this->cache == 'db') {
            $result = mysql_query("SELECT response FROM " . $this->cache_table . " WHERE request = '" . $reqhash . "' AND DATE_SUB(NOW(), INTERVAL " . (int) $this->cache_expire . " SECOND) < expiration", $this->cache_db);
            if ( mysql_num_rows($result) ) {
                $result = mysql_fetch_assoc($result);
                return $result['response'];
            } else {
                return false;
            }
        } elseif ($this->cache == 'fs') {
            $file = $this->cache_dir . '/' . $reqhash . '.cache';
            if (file_exists($file)) {
                if ($this->php_version[0] > 4 || ($this->php_version[0] == 4 && $this->php_version[1] >= 3)) {
                    return file_get_contents($file);
                } else {
                    return implode('', file($file));
                }
            }
        } elseif ( $this->cache == 'custom' ) {
            return call_user_func_array($this->custom_cache_get, array($reqhash));
        }
        return false;
    }

    public function cache ($request, $response)
    {
        //Caches the unparsed response of a request.
        unset($request['api_sig']);
        foreach ( $request as $key => $value ) {
            if ( empty($value) ) unset($request[$key]);
            else $request[$key] = (string) $request[$key];
        }
        $reqhash = md5(serialize($request));
        if ($this->cache == 'db') {
            //$this->cache_db->query("DELETE FROM $this->cache_table WHERE request = '$reqhash'");
            $result = mysql_query("SELECT COUNT(*) FROM " . $this->cache_table . " WHERE request = '" . $reqhash . "'", $this->cache_db);
            $result = mysql_fetch_row($result);
            if ( $result[0] ) {
                $sql = "UPDATE " . $this->cache_table . " SET response = '" . str_replace("'", "''", $response) . "', expiration = '" . strftime("%Y-%m-%d %H:%M:%S") . "' WHERE request = '" . $reqhash . "'";
                mysql_query($sql, $this->cache_db);
            } else {
                $sql = "INSERT INTO " . $this->cache_table . " (request, response, expiration) VALUES ('$reqhash', '" . str_replace("'", "''", $response) . "', '" . strftime("%Y-%m-%d %H:%M:%S") . "')";
                mysql_query($sql, $this->cache_db);
            }
        } elseif ($this->cache == "fs") {
            $file = $this->cache_dir . "/" . $reqhash . ".cache";
            $fstream = fopen($file, "w");
            $result = fwrite($fstream,$response);
            fclose($fstream);
            return $result;
        } elseif ( $this->cache == "custom" ) {
            return call_user_func_array($this->custom_cache_set, array($reqhash, $response, $this->cache_expire));
        }
        return false;
    }

    public function setCustomPost ( $function ) {
        $this->custom_post = $function;
    }

    public function post ($data, $type = null) {
        if ( is_null($type) ) {
            $url = $this->rest_endpoint;
        }

        if ( !is_null($this->custom_post) ) {
            return call_user_func($this->custom_post, $url, $data);
        }

        if ( !preg_match("|http://(.*?)(/.*)|", $url, $matches) ) {
            die('There was some problem figuring out your endpoint');
        }

        if ( function_exists('curl_init') ) {
            // Has curl. Use it!
            $curl = curl_init($this->rest_endpoint);
            curl_setopt($curl, CURLOPT_POST, true);
            curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            $response = curl_exec($curl);
            curl_close($curl);
        } else {
            // Use sockets.
            foreach ( $data as $key => $value ) {
                $data[$key] = $key . '=' . urlencode($value);
            }
            $data = implode('&', $data);

            $fp = @pfsockopen($matches[1], 80);
            if (!$fp) {
                die('Could not connect to the web service');
            }
            fputs ($fp,'POST ' . $matches[2] . " HTTP/1.1\n");
            fputs ($fp,'Host: ' . $matches[1] . "\n");
            fputs ($fp,"Content-type: application/x-www-form-urlencoded\n");
            fputs ($fp,"Content-length: ".strlen($data)."\n");
            fputs ($fp,"Connection: close\r\n\r\n");
            fputs ($fp,$data . "\n\n");
            $response = "";
            while(!feof($fp)) {
                $response .= fgets($fp, 1024);
            }
            fclose ($fp);
            $chunked = false;
            $http_status = trim(substr($response, 0, strpos($response, "\n")));
            if ( $http_status != 'HTTP/1.1 200 OK' ) {
                die('The web service endpoint returned a "' . $http_status . '" response');
            }
            if ( strpos($response, 'Transfer-Encoding: chunked') !== false ) {
                $temp = trim(strstr($response, "\r\n\r\n"));
                $response = '';
                $length = trim(substr($temp, 0, strpos($temp, "\r")));
                while ( trim($temp) != "0" && ($length = trim(substr($temp, 0, strpos($temp, "\r")))) != "0" ) {
                    $response .= trim(substr($temp, strlen($length)+2, hexdec($length)));
                    $temp = trim(substr($temp, strlen($length) + 2 + hexdec($length)));
                }
            } elseif ( strpos($response, 'HTTP/1.1 200 OK') !== false ) {
                $response = trim(strstr($response, "\r\n\r\n"));
            }
        }
        return $response;
    }

    public function request ($command, $args = array(), $nocache = false)
    {
        //Sends a request to Flickr's REST endpoint via POST.
        if (substr($command,0,7) != "flickr.") {
            $command = "flickr." . $command;
        }

        //Process arguments, including method and login data.
        $args = array_merge(array("method" => $command, "format" => "php_serial", "api_key" => $this->api_key), $args);
        if (!empty($this->token)) {
            $args = array_merge($args, array("auth_token" => $this->token));
        } elseif ($this->session->has($this->token_session_var)) {
            $args = array_merge($args, array("auth_token" => $this->session->get($this->token_session_var)));
        }
        ksort($args);
        $auth_sig = "";
        $this->last_request = $args;
        if (!($this->response = $this->getCached($args)) || $nocache) {
            foreach ($args as $key => $data) {
                if ( is_null($data) ) {
                    unset($args[$key]);
                    continue;
                }
                $auth_sig .= $key . $data;
            }
            if (!empty($this->secret)) {
                $api_sig = md5($this->secret . $auth_sig);
                $args['api_sig'] = $api_sig;
            }
            $this->response = $this->post($args);
            $this->cache($args, $this->response);
        }

        /*
         * Uncomment this line (and comment out the next one) if you're doing large queries
         * and you're concerned about time.  This will, however, change the structure of
         * the result, so be sure that you look at the results.
         */
        //$this->parsed_response = unserialize($this->response);
        $this->parsed_response = $this->clean_text_nodes(unserialize($this->response));
        if ($this->parsed_response['stat'] == 'fail') {
            if ($this->die_on_error) die("The Flickr API returned the following error: #{$this->parsed_response['code']} - {$this->parsed_response['message']}");
            else {
                $this->error_code = $this->parsed_response['code'];
                $this->error_msg = $this->parsed_response['message'];
                $this->parsed_response = false;
            }
        } else {
            $this->error_code = false;
            $this->error_msg = false;
        }
        return $this->response;
    }

    public function clean_text_nodes ($arr) {
        if (!is_array($arr)) {
            return $arr;
        } elseif (count($arr) == 0) {
            return $arr;
        } elseif (count($arr) == 1 && array_key_exists('_content', $arr)) {
            return $arr['_content'];
        } else {
            foreach ($arr as $key => $element) {
                $arr[$key] = $this->clean_text_nodes($element);
            }
            return($arr);
        }
    }

    public function setToken ($token) {
        // Sets an authentication token to use instead of the session variable
        $this->token = $token;
    }

    public function setProxy ($server, $port) {
        // Sets the proxy for all phpFlickr calls.
        $this->req->setProxy($server, $port);
    }

    public function getErrorCode () {
        // Returns the error code of the last call.  If the last call did not
        // return an error. This will return a false boolean.
        return $this->error_code;
    }

    public function getErrorMsg () {
        // Returns the error message of the last call.  If the last call did not
        // return an error. This will return a false boolean.
        return $this->error_msg;
    }

    /* These functions are front ends for the flickr calls */

    public function buildPhotoURL ($photo, $size = "Medium") {
        //receives an array (can use the individual photo data returned
        //from an API call) and returns a URL (doesn't mean that the
        //file size exists)
        $sizes = array(
            "square" => "_s",
            "thumbnail" => "_t",
            "small" => "_m",
            "medium" => "",
            "medium_640" => "_z",
            "large" => "_b",
            "original" => "_o"
        );

        $size = strtolower($size);
        if (!array_key_exists($size, $sizes)) {
            $size = "medium";
        }

        if ($size == "original") {
            $url = "http://farm" . $photo['farm'] . ".static.flickr.com/" . $photo['server'] . "/" . $photo['id'] . "_" . $photo['originalsecret'] . "_o" . "." . $photo['originalformat'];
        } else {
            $url = "http://farm" . $photo['farm'] . ".static.flickr.com/" . $photo['server'] . "/" . $photo['id'] . "_" . $photo['secret'] . $sizes[$size] . ".jpg";
        }
        return $url;
    }

    public function getFriendlyGeodata ($lat, $lon) {
        /* I've added this method to get the friendly geodata (i.e. 'in New York, NY') that the
         * website provides, but isn't available in the API. I'm providing this service as long
         * as it doesn't flood my server with requests and crash it all the time.
         */
        return unserialize(file_get_contents('http://phpflickr.com/geodata/?format=php&lat=' . $lat . '&lon=' . $lon));
    }

    public function sync_upload ($photo, $title = null, $description = null, $tags = null, $is_public = null, $is_friend = null, $is_family = null) {
        if ( function_exists('curl_init') ) {
            // Has curl. Use it!

            //Process arguments, including method and login data.
            $args = array("api_key" => $this->api_key, "title" => $title, "description" => $description, "tags" => $tags, "is_public" => $is_public, "is_friend" => $is_friend, "is_family" => $is_family);
            if (!empty($this->token)) {
                $args = array_merge($args, array("auth_token" => $this->token));
            } elseif ($this->session->has($this->token_session_var)) {
                $args = array_merge($args, array("auth_token" => $this->session->get($this->token_session_var)));
            }

            ksort($args);
            $auth_sig = "";
            foreach ($args as $key => $data) {
                if ( is_null($data) ) {
                    unset($args[$key]);
                } else {
                    $auth_sig .= $key . $data;
                }
            }
            if (!empty($this->secret)) {
                $api_sig = md5($this->secret . $auth_sig);
                $args["api_sig"] = $api_sig;
            }

            $photo = realpath($photo);
            $args['photo'] = '@' . $photo;


            $curl = curl_init($this->upload_endpoint);
            curl_setopt($curl, CURLOPT_POST, true);
            curl_setopt($curl, CURLOPT_POSTFIELDS, $args);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            $response = curl_exec($curl);
            $this->response = $response;
            curl_close($curl);

            $rsp = explode("\n", $response);
            foreach ($rsp as $line) {
                if (preg_match('|<err code="([0-9]+)" msg="(.*)"|', $line, $match)) {
                    if ($this->die_on_error)
                        die("The Flickr API returned the following error: #{$match[1]} - {$match[2]}");
                    else {
                        $this->error_code = $match[1];
                        $this->error_msg = $match[2];
                        $this->parsed_response = false;
                        return false;
                    }
                } elseif (preg_match("|<photoid>(.*)</photoid>|", $line, $match)) {
                    $this->error_code = false;
                    $this->error_msg = false;
                    return $match[1];
                }
            }

        } else {
            die("Sorry, your server must support CURL in order to upload files");
        }

    }

    public function async_upload ($photo, $title = null, $description = null, $tags = null, $is_public = null, $is_friend = null, $is_family = null) {
        if ( function_exists('curl_init') ) {
            // Has curl. Use it!

            //Process arguments, including method and login data.
            $args = array("async" => 1, "api_key" => $this->api_key, "title" => $title, "description" => $description, "tags" => $tags, "is_public" => $is_public, "is_friend" => $is_friend, "is_family" => $is_family);
            if (!empty($this->token)) {
                $args = array_merge($args, array("auth_token" => $this->token));
            } elseif ($this->session->has($this->token_session_var)) {
                $args = array_merge($args, array("auth_token" => $this->session->get($this->token_session_var)));
            }

            ksort($args);
            $auth_sig = "";
            foreach ($args as $key => $data) {
                if ( is_null($data) ) {
                    unset($args[$key]);
                } else {
                    $auth_sig .= $key . $data;
                }
            }
            if (!empty($this->secret)) {
                $api_sig = md5($this->secret . $auth_sig);
                $args["api_sig"] = $api_sig;
            }

            $photo = realpath($photo);
            $args['photo'] = '@' . $photo;


            $curl = curl_init($this->upload_endpoint);
            curl_setopt($curl, CURLOPT_POST, true);
            curl_setopt($curl, CURLOPT_POSTFIELDS, $args);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            $response = curl_exec($curl);
            $this->response = $response;
            curl_close($curl);

            $rsp = explode("\n", $response);
            foreach ($rsp as $line) {
                if (ereg('<err code="([0-9]+)" msg="(.*)"', $line, $match)) {
                    if ($this->die_on_error)
                        die("The Flickr API returned the following error: #{$match[1]} - {$match[2]}");
                    else {
                        $this->error_code = $match[1];
                        $this->error_msg = $match[2];
                        $this->parsed_response = false;
                        return false;
                    }
                } elseif (ereg("<ticketid>(.*)</", $line, $match)) {
                    $this->error_code = false;
                    $this->error_msg = false;
                    return $match[1];
                }
            }
        } else {
            die("Sorry, your server must support CURL in order to upload files");
        }
    }

    // Interface for new replace API method.
    public function replace ($photo, $photo_id, $async = null) {
        if ( function_exists('curl_init') ) {
            // Has curl. Use it!

            //Process arguments, including method and login data.
            $args = array("api_key" => $this->api_key, "photo_id" => $photo_id, "async" => $async);
            if (!empty($this->token)) {
                $args = array_merge($args, array("auth_token" => $this->token));
            } elseif ($this->session->has($this->token_session_var)) {
                $args = array_merge($args, array("auth_token" => $this->session->get($this->token_session_var)));
            }

            ksort($args);
            $auth_sig = "";
            foreach ($args as $key => $data) {
                if ( is_null($data) ) {
                    unset($args[$key]);
                } else {
                    $auth_sig .= $key . $data;
                }
            }
            if (!empty($this->secret)) {
                $api_sig = md5($this->secret . $auth_sig);
                $args["api_sig"] = $api_sig;
            }

            $photo = realpath($photo);
            $args['photo'] = '@' . $photo;


            $curl = curl_init($this->replace_endpoint);
            curl_setopt($curl, CURLOPT_POST, true);
            curl_setopt($curl, CURLOPT_POSTFIELDS, $args);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            $response = curl_exec($curl);
            $this->response = $response;
            curl_close($curl);

            if ($async == 1)
                $find = 'ticketid';
             else
                $find = 'photoid';

            $rsp = explode("\n", $response);
            foreach ($rsp as $line) {
                if (preg_match('|<err code="([0-9]+)" msg="(.*)"|', $line, $match)) {
                    if ($this->die_on_error)
                        die("The Flickr API returned the following error: #{$match[1]} - {$match[2]}");
                    else {
                        $this->error_code = $match[1];
                        $this->error_msg = $match[2];
                        $this->parsed_response = false;
                        return false;
                    }
                } elseif (preg_match("|<" . $find . ">(.*)</|", $line, $match)) {
                    $this->error_code = false;
                    $this->error_msg = false;
                    return $match[1];
                }
            }
        } else {
            die("Sorry, your server must support CURL in order to upload files");
        }
    }

    public function auth ($perms = "read", $remember_uri = true) {
        // Redirects to Flickr's authentication piece if there is no valid token.
        // If remember_uri is set to false, the callback script (included) will
        // redirect to its default page.

        if ( ! $this->session->has($this->token_session_var) && empty($this->token)) {
            if ( $remember_uri === true ) {
                $this->session->set($this->redirect_session_var, $_SERVER['REQUEST_URI']);
            } elseif ( $remember_uri !== false ) {
                $this->session->set($this->redirect_session_var, $remember_uri);
            }
            $api_sig = md5($this->secret . "api_key" . $this->api_key . "perms" . $perms);

            if ($this->service == "23") {
                header("Location: http://www.23hq.com/services/auth/?api_key=" . $this->api_key . "&perms=" . $perms . "&api_sig=". $api_sig);
            } else {
                header("Location: http://www.flickr.com/services/auth/?api_key=" . $this->api_key . "&perms=" . $perms . "&api_sig=". $api_sig);
            }
            exit;
        } else {
            $tmp = $this->die_on_error;
            $this->die_on_error = false;
            $rsp = $this->auth_checkToken();
            if ($this->error_code !== false) {
                $this->session->remove($this->token_session_var);
                $this->auth($perms, $remember_uri);
            }
            $this->die_on_error = $tmp;
            return $rsp['perms'];
        }
    }

    /*******************************

    To use the phpFlickr::call method, pass a string containing the API method you want
    to use and an associative array of arguments.  For example:
        $result = $f->call("flickr.photos.comments.getList", array("photo_id"=>'34952612'));
    This method will allow you to make calls to arbitrary methods that haven't been
    implemented in phpFlickr yet.

    *******************************/

    public function call ($method, $arguments) {
        foreach ( $arguments as $key => $value ) {
            if ( is_null($value) ) unset($arguments[$key]);
        }
        $this->request($method, $arguments);
        return $this->parsed_response ? $this->parsed_response : false;
    }

    /*
        These functions are the direct implementations of flickr calls.
        For method documentation, including arguments, visit the address
        included in a comment in the function.
    */

    /* Activity methods */
    public function activity_userComments ($per_page = NULL, $page = NULL) {
        /* http://www.flickr.com/services/api/flickr.activity.userComments.html */
        $this->request('flickr.activity.userComments', array("per_page" => $per_page, "page" => $page));
        return $this->parsed_response ? $this->parsed_response['items']['item'] : false;
    }

    public function activity_userPhotos ($timeframe = NULL, $per_page = NULL, $page = NULL) {
        /* http://www.flickr.com/services/api/flickr.activity.userPhotos.html */
        $this->request('flickr.activity.userPhotos', array("timeframe" => $timeframe, "per_page" => $per_page, "page" => $page));
        return $this->parsed_response ? $this->parsed_response['items']['item'] : false;
    }

    /* Authentication methods */
    public function auth_checkToken () {
        /* http://www.flickr.com/services/api/flickr.auth.checkToken.html */
        $this->request('flickr.auth.checkToken');
        return $this->parsed_response ? $this->parsed_response['auth'] : false;
    }

    public function auth_getFrob () {
        /* http://www.flickr.com/services/api/flickr.auth.getFrob.html */
        $this->request('flickr.auth.getFrob');
        return $this->parsed_response ? $this->parsed_response['frob'] : false;
    }

    public function auth_getFullToken ($mini_token) {
        /* http://www.flickr.com/services/api/flickr.auth.getFullToken.html */
        $this->request('flickr.auth.getFullToken', array('mini_token'=>$mini_token));
        return $this->parsed_response ? $this->parsed_response['auth'] : false;
    }

    public function auth_getToken ($frob) {
        /* http://www.flickr.com/services/api/flickr.auth.getToken.html */
        $this->request('flickr.auth.getToken', array('frob'=>$frob));
        $this->session->set($this->token_session_var, $this->parsed_response['auth']['token']);
        return $this->parsed_response ? $this->parsed_response['auth'] : false;
    }

    /* Blogs methods */
    public function blogs_getList ($service = NULL) {
        /* http://www.flickr.com/services/api/flickr.blogs.getList.html */
        $rsp = $this->call('flickr.blogs.getList', array('service' => $service));
        return $rsp['blogs']['blog'];
    }

    public function blogs_getServices () {
        /* http://www.flickr.com/services/api/flickr.blogs.getServices.html */
        return $this->call('flickr.blogs.getServices', array());
    }

    public function blogs_postPhoto ($blog_id = NULL, $photo_id, $title, $description, $blog_password = NULL, $service = NULL) {
        /* http://www.flickr.com/services/api/flickr.blogs.postPhoto.html */
        return $this->call('flickr.blogs.postPhoto', array('blog_id' => $blog_id, 'photo_id' => $photo_id, 'title' => $title, 'description' => $description, 'blog_password' => $blog_password, 'service' => $service));
    }

    /* Collections Methods */
    public function collections_getInfo ($collection_id) {
        /* http://www.flickr.com/services/api/flickr.collections.getInfo.html */
        return $this->call('flickr.collections.getInfo', array('collection_id' => $collection_id));
    }

    public function collections_getTree ($collection_id = NULL, $user_id = NULL) {
        /* http://www.flickr.com/services/api/flickr.collections.getTree.html */
        return $this->call('flickr.collections.getTree', array('collection_id' => $collection_id, 'user_id' => $user_id));
    }

    /* Commons Methods */
    public function commons_getInstitutions () {
        /* http://www.flickr.com/services/api/flickr.commons.getInstitutions.html */
        return $this->call('flickr.commons.getInstitutions', array());
    }

    /* Contacts Methods */
    public function contacts_getList ($filter = NULL, $page = NULL, $per_page = NULL) {
        /* http://www.flickr.com/services/api/flickr.contacts.getList.html */
        $this->request('flickr.contacts.getList', array('filter'=>$filter, 'page'=>$page, 'per_page'=>$per_page));
        return $this->parsed_response ? $this->parsed_response['contacts'] : false;
    }

    public function contacts_getPublicList ($user_id, $page = NULL, $per_page = NULL) {
        /* http://www.flickr.com/services/api/flickr.contacts.getPublicList.html */
        $this->request('flickr.contacts.getPublicList', array('user_id'=>$user_id, 'page'=>$page, 'per_page'=>$per_page));
        return $this->parsed_response ? $this->parsed_response['contacts'] : false;
    }

    public function contacts_getListRecentlyUploaded ($date_lastupload = NULL, $filter = NULL) {
        /* http://www.flickr.com/services/api/flickr.contacts.getListRecentlyUploaded.html */
        return $this->call('flickr.contacts.getListRecentlyUploaded', array('date_lastupload' => $date_lastupload, 'filter' => $filter));
    }

    /* Favorites Methods */
    public function favorites_add ($photo_id) {
        /* http://www.flickr.com/services/api/flickr.favorites.add.html */
        $this->request('flickr.favorites.add', array('photo_id'=>$photo_id), TRUE);
        return $this->parsed_response ? true : false;
    }

    public function favorites_getList ($user_id = NULL, $jump_to = NULL, $min_fave_date = NULL, $max_fave_date = NULL, $extras = NULL, $per_page = NULL, $page = NULL) {
        /* http://www.flickr.com/services/api/flickr.favorites.getList.html */
        return $this->call('flickr.favorites.getList', array('user_id' => $user_id, 'jump_to' => $jump_to, 'min_fave_date' => $min_fave_date, 'max_fave_date' => $max_fave_date, 'extras' => $extras, 'per_page' => $per_page, 'page' => $page));
    }

    public function favorites_getPublicList ($user_id, $jump_to = NULL, $min_fave_date = NULL, $max_fave_date = NULL, $extras = NULL, $per_page = NULL, $page = NULL) {
        /* http://www.flickr.com/services/api/flickr.favorites.getPublicList.html */
        return $this->call('flickr.favorites.getPublicList', array('user_id' => $user_id, 'jump_to' => $jump_to, 'min_fave_date' => $min_fave_date, 'max_fave_date' => $max_fave_date, 'extras' => $extras, 'per_page' => $per_page, 'page' => $page));
    }

    public function favorites_remove ($photo_id, $user_id = NULL) {
        /* http://www.flickr.com/services/api/flickr.favorites.remove.html */
        $this->request("flickr.favorites.remove", array('photo_id' => $photo_id, 'user_id' => $user_id), TRUE);
        return $this->parsed_response ? true : false;
    }

    /* Galleries Methods */
    public function galleries_addPhoto ($gallery_id, $photo_id, $comment = NULL) {
        /* http://www.flickr.com/services/api/flickr.galleries.addPhoto.html */
        return $this->call('flickr.galleries.addPhoto', array('gallery_id' => $gallery_id, 'photo_id' => $photo_id, 'comment' => $comment));
    }

    public function galleries_create ($title, $description, $primary_photo_id = NULL) {
        /* http://www.flickr.com/services/api/flickr.galleries.create.html */
        return $this->call('flickr.galleries.create', array('title' => $title, 'description' => $description, 'primary_photo_id' => $primary_photo_id));
    }

    public function galleries_editMeta ($gallery_id, $title, $description = NULL) {
        /* http://www.flickr.com/services/api/flickr.galleries.editMeta.html */
        return $this->call('flickr.galleries.editMeta', array('gallery_id' => $gallery_id, 'title' => $title, 'description' => $description));
    }

    public function galleries_editPhoto ($gallery_id, $photo_id, $comment) {
        /* http://www.flickr.com/services/api/flickr.galleries.editPhoto.html */
        return $this->call('flickr.galleries.editPhoto', array('gallery_id' => $gallery_id, 'photo_id' => $photo_id, 'comment' => $comment));
    }

    public function galleries_editPhotos ($gallery_id, $primary_photo_id, $photo_ids) {
        /* http://www.flickr.com/services/api/flickr.galleries.editPhotos.html */
        return $this->call('flickr.galleries.editPhotos', array('gallery_id' => $gallery_id, 'primary_photo_id' => $primary_photo_id, 'photo_ids' => $photo_ids));
    }

    public function galleries_getInfo ($gallery_id) {
        /* http://www.flickr.com/services/api/flickr.galleries.getInfo.html */
        return $this->call('flickr.galleries.getInfo', array('gallery_id' => $gallery_id));
    }

    public function galleries_getList ($user_id, $per_page = NULL, $page = NULL) {
        /* http://www.flickr.com/services/api/flickr.galleries.getList.html */
        return $this->call('flickr.galleries.getList', array('user_id' => $user_id, 'per_page' => $per_page, 'page' => $page));
    }

    public function galleries_getListForPhoto ($photo_id, $per_page = NULL, $page = NULL) {
        /* http://www.flickr.com/services/api/flickr.galleries.getListForPhoto.html */
        return $this->call('flickr.galleries.getListForPhoto', array('photo_id' => $photo_id, 'per_page' => $per_page, 'page' => $page));
    }

    public function galleries_getPhotos ($gallery_id, $extras = NULL, $per_page = NULL, $page = NULL) {
        /* http://www.flickr.com/services/api/flickr.galleries.getPhotos.html */
        return $this->call('flickr.galleries.getPhotos', array('gallery_id' => $gallery_id, 'extras' => $extras, 'per_page' => $per_page, 'page' => $page));
    }

    /* Groups Methods */
    public function groups_browse ($cat_id = NULL) {
        /* http://www.flickr.com/services/api/flickr.groups.browse.html */
        $this->request("flickr.groups.browse", array("cat_id"=>$cat_id));
        return $this->parsed_response ? $this->parsed_response['category'] : false;
    }

    public function groups_getInfo ($group_id, $lang = NULL) {
        /* http://www.flickr.com/services/api/flickr.groups.getInfo.html */
        return $this->call('flickr.groups.getInfo', array('group_id' => $group_id, 'lang' => $lang));
    }

    public function groups_search ($text, $per_page = NULL, $page = NULL) {
        /* http://www.flickr.com/services/api/flickr.groups.search.html */
        $this->request("flickr.groups.search", array("text"=>$text,"per_page"=>$per_page,"page"=>$page));
        return $this->parsed_response ? $this->parsed_response['groups'] : false;
    }

    /* Groups Members Methods */
    public function groups_members_getList ($group_id, $membertypes = NULL, $per_page = NULL, $page = NULL) {
        /* http://www.flickr.com/services/api/flickr.groups.members.getList.html */
        return $this->call('flickr.groups.members.getList', array('group_id' => $group_id, 'membertypes' => $membertypes, 'per_page' => $per_page, 'page' => $page));
    }

    /* Groups Pools Methods */
    public function groups_pools_add ($photo_id, $group_id) {
        /* http://www.flickr.com/services/api/flickr.groups.pools.add.html */
        $this->request("flickr.groups.pools.add", array("photo_id"=>$photo_id, "group_id"=>$group_id), TRUE);
        return $this->parsed_response ? true : false;
    }

    public function groups_pools_getContext ($photo_id, $group_id, $num_prev = NULL, $num_next = NULL) {
        /* http://www.flickr.com/services/api/flickr.groups.pools.getContext.html */
        return $this->call('flickr.groups.pools.getContext', array('photo_id' => $photo_id, 'group_id' => $group_id, 'num_prev' => $num_prev, 'num_next' => $num_next));
    }

    public function groups_pools_getGroups ($page = NULL, $per_page = NULL) {
        /* http://www.flickr.com/services/api/flickr.groups.pools.getGroups.html */
        $this->request("flickr.groups.pools.getGroups", array('page'=>$page, 'per_page'=>$per_page));
        return $this->parsed_response ? $this->parsed_response['groups'] : false;
    }

    public function groups_pools_getPhotos ($group_id, $tags = NULL, $user_id = NULL, $jump_to = NULL, $extras = NULL, $per_page = NULL, $page = NULL) {
        /* http://www.flickr.com/services/api/flickr.groups.pools.getPhotos.html */
        if (is_array($extras)) {
            $extras = implode(",", $extras);
        }
        return $this->call('flickr.groups.pools.getPhotos', array('group_id' => $group_id, 'tags' => $tags, 'user_id' => $user_id, 'jump_to' => $jump_to, 'extras' => $extras, 'per_page' => $per_page, 'page' => $page));
    }

    public function groups_pools_remove ($photo_id, $group_id) {
        /* http://www.flickr.com/services/api/flickr.groups.pools.remove.html */
        $this->request("flickr.groups.pools.remove", array("photo_id"=>$photo_id, "group_id"=>$group_id), TRUE);
        return $this->parsed_response ? true : false;
    }

    /* Interestingness methods */
    public function interestingness_getList ($date = NULL, $use_panda = NULL, $extras = NULL, $per_page = NULL, $page = NULL) {
        /* http://www.flickr.com/services/api/flickr.interestingness.getList.html */
        if (is_array($extras)) {
            $extras = implode(",", $extras);
        }

        return $this->call('flickr.interestingness.getList', array('date' => $date, 'use_panda' => $use_panda, 'extras' => $extras, 'per_page' => $per_page, 'page' => $page));
    }

    /* Machine Tag methods */
    public function machinetags_getNamespaces ($predicate = NULL, $per_page = NULL, $page = NULL) {
        /* http://www.flickr.com/services/api/flickr.machinetags.getNamespaces.html */
        return $this->call('flickr.machinetags.getNamespaces', array('predicate' => $predicate, 'per_page' => $per_page, 'page' => $page));
    }

    public function machinetags_getPairs ($namespace = NULL, $predicate = NULL, $per_page = NULL, $page = NULL) {
        /* http://www.flickr.com/services/api/flickr.machinetags.getPairs.html */
        return $this->call('flickr.machinetags.getPairs', array('namespace' => $namespace, 'predicate' => $predicate, 'per_page' => $per_page, 'page' => $page));
    }

    public function machinetags_getPredicates ($namespace = NULL, $per_page = NULL, $page = NULL) {
        /* http://www.flickr.com/services/api/flickr.machinetags.getPredicates.html */
        return $this->call('flickr.machinetags.getPredicates', array('namespace' => $namespace, 'per_page' => $per_page, 'page' => $page));
    }

    public function machinetags_getRecentValues ($namespace = NULL, $predicate = NULL, $added_since = NULL) {
        /* http://www.flickr.com/services/api/flickr.machinetags.getRecentValues.html */
        return $this->call('flickr.machinetags.getRecentValues', array('namespace' => $namespace, 'predicate' => $predicate, 'added_since' => $added_since));
    }

    public function machinetags_getValues ($namespace, $predicate, $per_page = NULL, $page = NULL, $usage = NULL) {
        /* http://www.flickr.com/services/api/flickr.machinetags.getValues.html */
        return $this->call('flickr.machinetags.getValues', array('namespace' => $namespace, 'predicate' => $predicate, 'per_page' => $per_page, 'page' => $page, 'usage' => $usage));
    }

    /* Panda methods */
    public function panda_getList () {
        /* http://www.flickr.com/services/api/flickr.panda.getList.html */
        return $this->call('flickr.panda.getList', array());
    }

    public function panda_getPhotos ($panda_name, $extras = NULL, $per_page = NULL, $page = NULL) {
        /* http://www.flickr.com/services/api/flickr.panda.getPhotos.html */
        return $this->call('flickr.panda.getPhotos', array('panda_name' => $panda_name, 'extras' => $extras, 'per_page' => $per_page, 'page' => $page));
    }

    /* People methods */
    public function people_findByEmail ($find_email) {
        /* http://www.flickr.com/services/api/flickr.people.findByEmail.html */
        $this->request("flickr.people.findByEmail", array("find_email"=>$find_email));
        return $this->parsed_response ? $this->parsed_response['user'] : false;
    }

    public function people_findByUsername ($username) {
        /* http://www.flickr.com/services/api/flickr.people.findByUsername.html */
        $this->request("flickr.people.findByUsername", array("username"=>$username));
        return $this->parsed_response ? $this->parsed_response['user'] : false;
    }

    public function people_getInfo ($user_id) {
        /* http://www.flickr.com/services/api/flickr.people.getInfo.html */
        $this->request("flickr.people.getInfo", array("user_id"=>$user_id));
        return $this->parsed_response ? $this->parsed_response['person'] : false;
    }

    public function people_getPhotos ($user_id, $args = array()) {
        /* This function strays from the method of arguments that I've
         * used in the other functions for the fact that there are just
         * so many arguments to this API method. What you'll need to do
         * is pass an associative array to the function containing the
         * arguments you want to pass to the API.  For example:
         *   $photos = $f->photos_search(array("tags"=>"brown,cow", "tag_mode"=>"any"));
         * This will return photos tagged with either "brown" or "cow"
         * or both. See the API documentation (link below) for a full
         * list of arguments.
         */

         /* http://www.flickr.com/services/api/flickr.people.getPhotos.html */
        return $this->call('flickr.people.getPhotos', array_merge(array('user_id' => $user_id), $args));
    }

    public function people_getPhotosOf ($user_id, $extras = NULL, $per_page = NULL, $page = NULL) {
        /* http://www.flickr.com/services/api/flickr.people.getPhotosOf.html */
        return $this->call('flickr.people.getPhotosOf', array('user_id' => $user_id, 'extras' => $extras, 'per_page' => $per_page, 'page' => $page));
    }

    public function people_getPublicGroups ($user_id) {
        /* http://www.flickr.com/services/api/flickr.people.getPublicGroups.html */
        $this->request("flickr.people.getPublicGroups", array("user_id"=>$user_id));
        return $this->parsed_response ? $this->parsed_response['groups']['group'] : false;
    }

    public function people_getPublicPhotos ($user_id, $safe_search = NULL, $extras = NULL, $per_page = NULL, $page = NULL) {
        /* http://www.flickr.com/services/api/flickr.people.getPublicPhotos.html */
        return $this->call('flickr.people.getPublicPhotos', array('user_id' => $user_id, 'safe_search' => $safe_search, 'extras' => $extras, 'per_page' => $per_page, 'page' => $page));
    }

    public function people_getUploadStatus () {
        /* http://www.flickr.com/services/api/flickr.people.getUploadStatus.html */
        /* Requires Authentication */
        $this->request("flickr.people.getUploadStatus");
        return $this->parsed_response ? $this->parsed_response['user'] : false;
    }


    /* Photos Methods */
    public function photos_addTags ($photo_id, $tags) {
        /* http://www.flickr.com/services/api/flickr.photos.addTags.html */
        $this->request("flickr.photos.addTags", array("photo_id"=>$photo_id, "tags"=>$tags), TRUE);
        return $this->parsed_response ? true : false;
    }

    public function photos_delete ($photo_id) {
        /* http://www.flickr.com/services/api/flickr.photos.delete.html */
        $this->request("flickr.photos.delete", array("photo_id"=>$photo_id), TRUE);
        return $this->parsed_response ? true : false;
    }

    public function photos_getAllContexts ($photo_id) {
        /* http://www.flickr.com/services/api/flickr.photos.getAllContexts.html */
        $this->request("flickr.photos.getAllContexts", array("photo_id"=>$photo_id));
        return $this->parsed_response ? $this->parsed_response : false;
    }

    public function photos_getContactsPhotos ($count = NULL, $just_friends = NULL, $single_photo = NULL, $include_self = NULL, $extras = NULL) {
        /* http://www.flickr.com/services/api/flickr.photos.getContactsPhotos.html */
        $this->request("flickr.photos.getContactsPhotos", array("count"=>$count, "just_friends"=>$just_friends, "single_photo"=>$single_photo, "include_self"=>$include_self, "extras"=>$extras));
        return $this->parsed_response ? $this->parsed_response['photos']['photo'] : false;
    }

    public function photos_getContactsPublicPhotos ($user_id, $count = NULL, $just_friends = NULL, $single_photo = NULL, $include_self = NULL, $extras = NULL) {
        /* http://www.flickr.com/services/api/flickr.photos.getContactsPublicPhotos.html */
        $this->request("flickr.photos.getContactsPublicPhotos", array("user_id"=>$user_id, "count"=>$count, "just_friends"=>$just_friends, "single_photo"=>$single_photo, "include_self"=>$include_self, "extras"=>$extras));
        return $this->parsed_response ? $this->parsed_response['photos']['photo'] : false;
    }

    public function photos_getContext ($photo_id, $num_prev = NULL, $num_next = NULL, $extras = NULL, $order_by = NULL) {
        /* http://www.flickr.com/services/api/flickr.photos.getContext.html */
        return $this->call('flickr.photos.getContext', array('photo_id' => $photo_id, 'num_prev' => $num_prev, 'num_next' => $num_next, 'extras' => $extras, 'order_by' => $order_by));
    }

    public function photos_getCounts ($dates = NULL, $taken_dates = NULL) {
        /* http://www.flickr.com/services/api/flickr.photos.getCounts.html */
        $this->request("flickr.photos.getCounts", array("dates"=>$dates, "taken_dates"=>$taken_dates));
        return $this->parsed_response ? $this->parsed_response['photocounts']['photocount'] : false;
    }

    public function photos_getExif ($photo_id, $secret = NULL) {
        /* http://www.flickr.com/services/api/flickr.photos.getExif.html */
        $this->request("flickr.photos.getExif", array("photo_id"=>$photo_id, "secret"=>$secret));
        return $this->parsed_response ? $this->parsed_response['photo'] : false;
    }

    public function photos_getFavorites ($photo_id, $page = NULL, $per_page = NULL) {
        /* http://www.flickr.com/services/api/flickr.photos.getFavorites.html */
        $this->request("flickr.photos.getFavorites", array("photo_id"=>$photo_id, "page"=>$page, "per_page"=>$per_page));
        return $this->parsed_response ? $this->parsed_response['photo'] : false;
    }

    public function photos_getInfo ($photo_id, $secret = NULL, $humandates = NULL, $privacy_filter = NULL, $get_contexts = NULL) {
        /* http://www.flickr.com/services/api/flickr.photos.getInfo.html */
        return $this->call('flickr.photos.getInfo', array('photo_id' => $photo_id, 'secret' => $secret, 'humandates' => $humandates, 'privacy_filter' => $privacy_filter, 'get_contexts' => $get_contexts));
    }

    public function photos_getNotInSet ($max_upload_date = NULL, $min_taken_date = NULL, $max_taken_date = NULL, $privacy_filter = NULL, $media = NULL, $min_upload_date = NULL, $extras = NULL, $per_page = NULL, $page = NULL) {
        /* http://www.flickr.com/services/api/flickr.photos.getNotInSet.html */
        return $this->call('flickr.photos.getNotInSet', array('max_upload_date' => $max_upload_date, 'min_taken_date' => $min_taken_date, 'max_taken_date' => $max_taken_date, 'privacy_filter' => $privacy_filter, 'media' => $media, 'min_upload_date' => $min_upload_date, 'extras' => $extras, 'per_page' => $per_page, 'page' => $page));
    }

    public function photos_getPerms ($photo_id) {
        /* http://www.flickr.com/services/api/flickr.photos.getPerms.html */
        $this->request("flickr.photos.getPerms", array("photo_id"=>$photo_id));
        return $this->parsed_response ? $this->parsed_response['perms'] : false;
    }

    public function photos_getRecent ($jump_to = NULL, $extras = NULL, $per_page = NULL, $page = NULL) {
        /* http://www.flickr.com/services/api/flickr.photos.getRecent.html */
        if (is_array($extras)) {
            $extras = implode(",", $extras);
        }
        return $this->call('flickr.photos.getRecent', array('jump_to' => $jump_to, 'extras' => $extras, 'per_page' => $per_page, 'page' => $page));
    }

    public function photos_getSizes ($photo_id) {
        /* http://www.flickr.com/services/api/flickr.photos.getSizes.html */
        $this->request("flickr.photos.getSizes", array("photo_id"=>$photo_id));
        return $this->parsed_response ? $this->parsed_response['sizes']['size'] : false;
    }

    public function photos_getUntagged ($min_upload_date = NULL, $max_upload_date = NULL, $min_taken_date = NULL, $max_taken_date = NULL, $privacy_filter = NULL, $media = NULL, $extras = NULL, $per_page = NULL, $page = NULL) {
        /* http://www.flickr.com/services/api/flickr.photos.getUntagged.html */
        return $this->call('flickr.photos.getUntagged', array('min_upload_date' => $min_upload_date, 'max_upload_date' => $max_upload_date, 'min_taken_date' => $min_taken_date, 'max_taken_date' => $max_taken_date, 'privacy_filter' => $privacy_filter, 'media' => $media, 'extras' => $extras, 'per_page' => $per_page, 'page' => $page));
    }

    public function photos_getWithGeoData ($args = array()) {
        /* See the documentation included with the photos_search() function.
         * I'm using the same style of arguments for this function. The only
         * difference here is that this doesn't require any arguments. The
         * flickr.photos.search method requires at least one search parameter.
         */
        /* http://www.flickr.com/services/api/flickr.photos.getWithGeoData.html */
        $this->request("flickr.photos.getWithGeoData", $args);
        return $this->parsed_response ? $this->parsed_response['photos'] : false;
    }

    public function photos_getWithoutGeoData ($args = array()) {
        /* See the documentation included with the photos_search() function.
         * I'm using the same style of arguments for this function. The only
         * difference here is that this doesn't require any arguments. The
         * flickr.photos.search method requires at least one search parameter.
         */
        /* http://www.flickr.com/services/api/flickr.photos.getWithoutGeoData.html */
        $this->request("flickr.photos.getWithoutGeoData", $args);
        return $this->parsed_response ? $this->parsed_response['photos'] : false;
    }

    public function photos_recentlyUpdated ($min_date, $extras = NULL, $per_page = NULL, $page = NULL) {
        /* http://www.flickr.com/services/api/flickr.photos.recentlyUpdated.html */
        return $this->call('flickr.photos.recentlyUpdated', array('min_date' => $min_date, 'extras' => $extras, 'per_page' => $per_page, 'page' => $page));
    }

    public function photos_removeTag ($tag_id) {
        /* http://www.flickr.com/services/api/flickr.photos.removeTag.html */
        $this->request("flickr.photos.removeTag", array("tag_id"=>$tag_id), TRUE);
        return $this->parsed_response ? true : false;
    }

    public function photos_search ($args = array()) {
        /* This function strays from the method of arguments that I've
         * used in the other functions for the fact that there are just
         * so many arguments to this API method. What you'll need to do
         * is pass an associative array to the function containing the
         * arguments you want to pass to the API.  For example:
         *   $photos = $f->photos_search(array("tags"=>"brown,cow", "tag_mode"=>"any"));
         * This will return photos tagged with either "brown" or "cow"
         * or both. See the API documentation (link below) for a full
         * list of arguments.
         */

        /* http://www.flickr.com/services/api/flickr.photos.search.html */
        $this->request("flickr.photos.search", $args);
        return $this->parsed_response ? $this->parsed_response['photos'] : false;
    }

    public function photos_setContentType ($photo_id, $content_type) {
        /* http://www.flickr.com/services/api/flickr.photos.setContentType.html */
        return $this->call('flickr.photos.setContentType', array('photo_id' => $photo_id, 'content_type' => $content_type));
    }

    public function photos_setDates ($photo_id, $date_posted = NULL, $date_taken = NULL, $date_taken_granularity = NULL) {
        /* http://www.flickr.com/services/api/flickr.photos.setDates.html */
        $this->request("flickr.photos.setDates", array("photo_id"=>$photo_id, "date_posted"=>$date_posted, "date_taken"=>$date_taken, "date_taken_granularity"=>$date_taken_granularity), TRUE);
        return $this->parsed_response ? true : false;
    }

    public function photos_setMeta ($photo_id, $title, $description) {
        /* http://www.flickr.com/services/api/flickr.photos.setMeta.html */
        $this->request("flickr.photos.setMeta", array("photo_id"=>$photo_id, "title"=>$title, "description"=>$description), TRUE);
        return $this->parsed_response ? true : false;
    }

    public function photos_setPerms ($photo_id, $is_public, $is_friend, $is_family, $perm_comment, $perm_addmeta) {
        /* http://www.flickr.com/services/api/flickr.photos.setPerms.html */
        $this->request("flickr.photos.setPerms", array("photo_id"=>$photo_id, "is_public"=>$is_public, "is_friend"=>$is_friend, "is_family"=>$is_family, "perm_comment"=>$perm_comment, "perm_addmeta"=>$perm_addmeta), TRUE);
        return $this->parsed_response ? true : false;
    }

    public function photos_setSafetyLevel ($photo_id, $safety_level = NULL, $hidden = NULL) {
        /* http://www.flickr.com/services/api/flickr.photos.setSafetyLevel.html */
        return $this->call('flickr.photos.setSafetyLevel', array('photo_id' => $photo_id, 'safety_level' => $safety_level, 'hidden' => $hidden));
    }

    public function photos_setTags ($photo_id, $tags) {
        /* http://www.flickr.com/services/api/flickr.photos.setTags.html */
        $this->request("flickr.photos.setTags", array("photo_id"=>$photo_id, "tags"=>$tags), TRUE);
        return $this->parsed_response ? true : false;
    }

    /* Photos - Comments Methods */
    public function photos_comments_addComment ($photo_id, $comment_text) {
        /* http://www.flickr.com/services/api/flickr.photos.comments.addComment.html */
        $this->request("flickr.photos.comments.addComment", array("photo_id" => $photo_id, "comment_text"=>$comment_text), TRUE);
        return $this->parsed_response ? $this->parsed_response['comment'] : false;
    }

    public function photos_comments_deleteComment ($comment_id) {
        /* http://www.flickr.com/services/api/flickr.photos.comments.deleteComment.html */
        $this->request("flickr.photos.comments.deleteComment", array("comment_id" => $comment_id), TRUE);
        return $this->parsed_response ? true : false;
    }

    public function photos_comments_editComment ($comment_id, $comment_text) {
        /* http://www.flickr.com/services/api/flickr.photos.comments.editComment.html */
        $this->request("flickr.photos.comments.editComment", array("comment_id" => $comment_id, "comment_text"=>$comment_text), TRUE);
        return $this->parsed_response ? true : false;
    }

    public function photos_comments_getList ($photo_id, $min_comment_date = NULL, $max_comment_date = NULL, $page = NULL, $per_page = NULL, $include_faves = NULL) {
        /* http://www.flickr.com/services/api/flickr.photos.comments.getList.html */
        return $this->call('flickr.photos.comments.getList', array('photo_id' => $photo_id, 'min_comment_date' => $min_comment_date, 'max_comment_date' => $max_comment_date, 'page' => $page, 'per_page' => $per_page, 'include_faves' => $include_faves));
    }

    public function photos_comments_getRecentForContacts ($date_lastcomment = NULL, $contacts_filter = NULL, $extras = NULL, $per_page = NULL, $page = NULL) {
        /* http://www.flickr.com/services/api/flickr.photos.comments.getRecentForContacts.html */
        return $this->call('flickr.photos.comments.getRecentForContacts', array('date_lastcomment' => $date_lastcomment, 'contacts_filter' => $contacts_filter, 'extras' => $extras, 'per_page' => $per_page, 'page' => $page));
    }

    /* Photos - Geo Methods */
    public function photos_geo_batchCorrectLocation ($lat, $lon, $accuracy, $place_id = NULL, $woe_id = NULL) {
        /* http://www.flickr.com/services/api/flickr.photos.geo.batchCorrectLocation.html */
        return $this->call('flickr.photos.geo.batchCorrectLocation', array('lat' => $lat, 'lon' => $lon, 'accuracy' => $accuracy, 'place_id' => $place_id, 'woe_id' => $woe_id));
    }

    public function photos_geo_correctLocation ($photo_id, $place_id = NULL, $woe_id = NULL) {
        /* http://www.flickr.com/services/api/flickr.photos.geo.correctLocation.html */
        return $this->call('flickr.photos.geo.correctLocation', array('photo_id' => $photo_id, 'place_id' => $place_id, 'woe_id' => $woe_id));
    }

    public function photos_geo_getLocation ($photo_id) {
        /* http://www.flickr.com/services/api/flickr.photos.geo.getLocation.html */
        $this->request("flickr.photos.geo.getLocation", array("photo_id"=>$photo_id));
        return $this->parsed_response ? $this->parsed_response['photo'] : false;
    }

    public function photos_geo_getPerms ($photo_id) {
        /* http://www.flickr.com/services/api/flickr.photos.geo.getPerms.html */
        $this->request("flickr.photos.geo.getPerms", array("photo_id"=>$photo_id));
        return $this->parsed_response ? $this->parsed_response['perms'] : false;
    }

    public function photos_geo_photosForLocation ($lat, $lon, $accuracy = NULL, $extras = NULL, $per_page = NULL, $page = NULL) {
        /* http://www.flickr.com/services/api/flickr.photos.geo.photosForLocation.html */
        return $this->call('flickr.photos.geo.photosForLocation', array('lat' => $lat, 'lon' => $lon, 'accuracy' => $accuracy, 'extras' => $extras, 'per_page' => $per_page, 'page' => $page));
    }

    public function photos_geo_removeLocation ($photo_id) {
        /* http://www.flickr.com/services/api/flickr.photos.geo.removeLocation.html */
        $this->request("flickr.photos.geo.removeLocation", array("photo_id"=>$photo_id), TRUE);
        return $this->parsed_response ? true : false;
    }

    public function photos_geo_setContext ($photo_id, $context) {
        /* http://www.flickr.com/services/api/flickr.photos.geo.setContext.html */
        return $this->call('flickr.photos.geo.setContext', array('photo_id' => $photo_id, 'context' => $context));
    }

    public function photos_geo_setLocation ($photo_id, $lat, $lon, $accuracy = NULL, $context = NULL, $bookmark_id = NULL) {
        /* http://www.flickr.com/services/api/flickr.photos.geo.setLocation.html */
        return $this->call('flickr.photos.geo.setLocation', array('photo_id' => $photo_id, 'lat' => $lat, 'lon' => $lon, 'accuracy' => $accuracy, 'context' => $context, 'bookmark_id' => $bookmark_id));
    }

    public function photos_geo_setPerms ($is_public, $is_contact, $is_friend, $is_family, $photo_id) {
        /* http://www.flickr.com/services/api/flickr.photos.geo.setPerms.html */
        return $this->call('flickr.photos.geo.setPerms', array('is_public' => $is_public, 'is_contact' => $is_contact, 'is_friend' => $is_friend, 'is_family' => $is_family, 'photo_id' => $photo_id));
    }

    /* Photos - Licenses Methods */
    public function photos_licenses_getInfo () {
        /* http://www.flickr.com/services/api/flickr.photos.licenses.getInfo.html */
        $this->request("flickr.photos.licenses.getInfo");
        return $this->parsed_response ? $this->parsed_response['licenses']['license'] : false;
    }

    public function photos_licenses_setLicense ($photo_id, $license_id) {
        /* http://www.flickr.com/services/api/flickr.photos.licenses.setLicense.html */
        /* Requires Authentication */
        $this->request("flickr.photos.licenses.setLicense", array("photo_id"=>$photo_id, "license_id"=>$license_id), TRUE);
        return $this->parsed_response ? true : false;
    }

    /* Photos - Notes Methods */
    public function photos_notes_add ($photo_id, $note_x, $note_y, $note_w, $note_h, $note_text) {
        /* http://www.flickr.com/services/api/flickr.photos.notes.add.html */
        $this->request("flickr.photos.notes.add", array("photo_id" => $photo_id, "note_x" => $note_x, "note_y" => $note_y, "note_w" => $note_w, "note_h" => $note_h, "note_text" => $note_text), TRUE);
        return $this->parsed_response ? $this->parsed_response['note'] : false;
    }

    public function photos_notes_delete ($note_id) {
        /* http://www.flickr.com/services/api/flickr.photos.notes.delete.html */
        $this->request("flickr.photos.notes.delete", array("note_id" => $note_id), TRUE);
        return $this->parsed_response ? true : false;
    }

    public function photos_notes_edit ($note_id, $note_x, $note_y, $note_w, $note_h, $note_text) {
        /* http://www.flickr.com/services/api/flickr.photos.notes.edit.html */
        $this->request("flickr.photos.notes.edit", array("note_id" => $note_id, "note_x" => $note_x, "note_y" => $note_y, "note_w" => $note_w, "note_h" => $note_h, "note_text" => $note_text), TRUE);
        return $this->parsed_response ? true : false;
    }

    /* Photos - Transform Methods */
    public function photos_transform_rotate ($photo_id, $degrees) {
        /* http://www.flickr.com/services/api/flickr.photos.transform.rotate.html */
        $this->request("flickr.photos.transform.rotate", array("photo_id" => $photo_id, "degrees" => $degrees), TRUE);
        return $this->parsed_response ? true : false;
    }

    /* Photos - People Methods */
    public function photos_people_add ($photo_id, $user_id, $person_x = NULL, $person_y = NULL, $person_w = NULL, $person_h = NULL) {
        /* http://www.flickr.com/services/api/flickr.photos.people.add.html */
        return $this->call('flickr.photos.people.add', array('photo_id' => $photo_id, 'user_id' => $user_id, 'person_x' => $person_x, 'person_y' => $person_y, 'person_w' => $person_w, 'person_h' => $person_h));
    }

    public function photos_people_delete ($photo_id, $user_id, $email = NULL) {
        /* http://www.flickr.com/services/api/flickr.photos.people.delete.html */
        return $this->call('flickr.photos.people.delete', array('photo_id' => $photo_id, 'user_id' => $user_id, 'email' => $email));
    }

    public function photos_people_deleteCoords ($photo_id, $user_id) {
        /* http://www.flickr.com/services/api/flickr.photos.people.deleteCoords.html */
        return $this->call('flickr.photos.people.deleteCoords', array('photo_id' => $photo_id, 'user_id' => $user_id));
    }

    public function photos_people_editCoords ($photo_id, $user_id, $person_x, $person_y, $person_w, $person_h, $email = NULL) {
        /* http://www.flickr.com/services/api/flickr.photos.people.editCoords.html */
        return $this->call('flickr.photos.people.editCoords', array('photo_id' => $photo_id, 'user_id' => $user_id, 'person_x' => $person_x, 'person_y' => $person_y, 'person_w' => $person_w, 'person_h' => $person_h, 'email' => $email));
    }

    public function photos_people_getList ($photo_id) {
        /* http://www.flickr.com/services/api/flickr.photos.people.getList.html */
        return $this->call('flickr.photos.people.getList', array('photo_id' => $photo_id));
    }

    /* Photos - Upload Methods */
    public function photos_upload_checkTickets ($tickets) {
        /* http://www.flickr.com/services/api/flickr.photos.upload.checkTickets.html */
        if (is_array($tickets)) {
            $tickets = implode(",", $tickets);
        }
        $this->request("flickr.photos.upload.checkTickets", array("tickets" => $tickets), TRUE);
        return $this->parsed_response ? $this->parsed_response['uploader']['ticket'] : false;
    }

    /* Photosets Methods */
    public function photosets_addPhoto ($photoset_id, $photo_id) {
        /* http://www.flickr.com/services/api/flickr.photosets.addPhoto.html */
        $this->request("flickr.photosets.addPhoto", array("photoset_id" => $photoset_id, "photo_id" => $photo_id), TRUE);
        return $this->parsed_response ? true : false;
    }

    public function photosets_create ($title, $description, $primary_photo_id) {
        /* http://www.flickr.com/services/api/flickr.photosets.create.html */
        $this->request("flickr.photosets.create", array("title" => $title, "primary_photo_id" => $primary_photo_id, "description" => $description), TRUE);
        return $this->parsed_response ? $this->parsed_response['photoset'] : false;
    }

    public function photosets_delete ($photoset_id) {
        /* http://www.flickr.com/services/api/flickr.photosets.delete.html */
        $this->request("flickr.photosets.delete", array("photoset_id" => $photoset_id), TRUE);
        return $this->parsed_response ? true : false;
    }

    public function photosets_editMeta ($photoset_id, $title, $description = NULL) {
        /* http://www.flickr.com/services/api/flickr.photosets.editMeta.html */
        $this->request("flickr.photosets.editMeta", array("photoset_id" => $photoset_id, "title" => $title, "description" => $description), TRUE);
        return $this->parsed_response ? true : false;
    }

    public function photosets_editPhotos ($photoset_id, $primary_photo_id, $photo_ids) {
        /* http://www.flickr.com/services/api/flickr.photosets.editPhotos.html */
        $this->request("flickr.photosets.editPhotos", array("photoset_id" => $photoset_id, "primary_photo_id" => $primary_photo_id, "photo_ids" => $photo_ids), TRUE);
        return $this->parsed_response ? true : false;
    }

    public function photosets_getContext ($photo_id, $photoset_id, $num_prev = NULL, $num_next = NULL) {
        /* http://www.flickr.com/services/api/flickr.photosets.getContext.html */
        return $this->call('flickr.photosets.getContext', array('photo_id' => $photo_id, 'photoset_id' => $photoset_id, 'num_prev' => $num_prev, 'num_next' => $num_next));
    }

    public function photosets_getInfo ($photoset_id) {
        /* http://www.flickr.com/services/api/flickr.photosets.getInfo.html */
        $this->request("flickr.photosets.getInfo", array("photoset_id" => $photoset_id));
        return $this->parsed_response ? $this->parsed_response['photoset'] : false;
    }

    public function photosets_getList ($user_id = NULL) {
        /* http://www.flickr.com/services/api/flickr.photosets.getList.html */
        $this->request("flickr.photosets.getList", array("user_id" => $user_id));
        return $this->parsed_response ? $this->parsed_response['photosets'] : false;
    }

    public function photosets_getPhotos ($photoset_id, $extras = NULL, $privacy_filter = NULL, $per_page = NULL, $page = NULL, $media = NULL) {
        /* http://www.flickr.com/services/api/flickr.photosets.getPhotos.html */
        return $this->call('flickr.photosets.getPhotos', array('photoset_id' => $photoset_id, 'extras' => $extras, 'privacy_filter' => $privacy_filter, 'per_page' => $per_page, 'page' => $page, 'media' => $media));
    }

    public function photosets_orderSets ($photoset_ids) {
        /* http://www.flickr.com/services/api/flickr.photosets.orderSets.html */
        if (is_array($photoset_ids)) {
            $photoset_ids = implode(",", $photoset_ids);
        }
        $this->request("flickr.photosets.orderSets", array("photoset_ids" => $photoset_ids), TRUE);
        return $this->parsed_response ? true : false;
    }

    public function photosets_removePhoto ($photoset_id, $photo_id) {
        /* http://www.flickr.com/services/api/flickr.photosets.removePhoto.html */
        $this->request("flickr.photosets.removePhoto", array("photoset_id" => $photoset_id, "photo_id" => $photo_id), TRUE);
        return $this->parsed_response ? true : false;
    }

    public function photosets_removePhotos ($photoset_id, $photo_ids) {
        /* http://www.flickr.com/services/api/flickr.photosets.removePhotos.html */
        return $this->call('flickr.photosets.removePhotos', array('photoset_id' => $photoset_id, 'photo_ids' => $photo_ids));
    }

    public function photosets_reorderPhotos ($photoset_id, $photo_ids) {
        /* http://www.flickr.com/services/api/flickr.photosets.reorderPhotos.html */
        return $this->call('flickr.photosets.reorderPhotos', array('photoset_id' => $photoset_id, 'photo_ids' => $photo_ids));
    }

    public function photosets_setPrimaryPhoto ($photoset_id, $photo_id) {
        /* http://www.flickr.com/services/api/flickr.photosets.setPrimaryPhoto.html */
        return $this->call('flickr.photosets.setPrimaryPhoto', array('photoset_id' => $photoset_id, 'photo_id' => $photo_id));
    }

    /* Photosets Comments Methods */
    public function photosets_comments_addComment ($photoset_id, $comment_text) {
        /* http://www.flickr.com/services/api/flickr.photosets.comments.addComment.html */
        $this->request("flickr.photosets.comments.addComment", array("photoset_id" => $photoset_id, "comment_text"=>$comment_text), TRUE);
        return $this->parsed_response ? $this->parsed_response['comment'] : false;
    }

    public function photosets_comments_deleteComment ($comment_id) {
        /* http://www.flickr.com/services/api/flickr.photosets.comments.deleteComment.html */
        $this->request("flickr.photosets.comments.deleteComment", array("comment_id" => $comment_id), TRUE);
        return $this->parsed_response ? true : false;
    }

    public function photosets_comments_editComment ($comment_id, $comment_text) {
        /* http://www.flickr.com/services/api/flickr.photosets.comments.editComment.html */
        $this->request("flickr.photosets.comments.editComment", array("comment_id" => $comment_id, "comment_text"=>$comment_text), TRUE);
        return $this->parsed_response ? true : false;
    }

    public function photosets_comments_getList ($photoset_id) {
        /* http://www.flickr.com/services/api/flickr.photosets.comments.getList.html */
        $this->request("flickr.photosets.comments.getList", array("photoset_id"=>$photoset_id));
        return $this->parsed_response ? $this->parsed_response['comments'] : false;
    }

    /* Places Methods */
    public function places_find ($query) {
        /* http://www.flickr.com/services/api/flickr.places.find.html */
        return $this->call('flickr.places.find', array('query' => $query));
    }

    public function places_findByLatLon ($lat, $lon, $accuracy = NULL) {
        /* http://www.flickr.com/services/api/flickr.places.findByLatLon.html */
        return $this->call('flickr.places.findByLatLon', array('lat' => $lat, 'lon' => $lon, 'accuracy' => $accuracy));
    }

    public function places_getChildrenWithPhotosPublic ($place_id = NULL, $woe_id = NULL) {
        /* http://www.flickr.com/services/api/flickr.places.getChildrenWithPhotosPublic.html */
        return $this->call('flickr.places.getChildrenWithPhotosPublic', array('place_id' => $place_id, 'woe_id' => $woe_id));
    }

    public function places_getInfo ($place_id = NULL, $woe_id = NULL) {
        /* http://www.flickr.com/services/api/flickr.places.getInfo.html */
        return $this->call('flickr.places.getInfo', array('place_id' => $place_id, 'woe_id' => $woe_id));
    }

    public function places_getInfoByUrl ($url) {
        /* http://www.flickr.com/services/api/flickr.places.getInfoByUrl.html */
        return $this->call('flickr.places.getInfoByUrl', array('url' => $url));
    }

    public function places_getPlaceTypes () {
        /* http://www.flickr.com/services/api/flickr.places.getPlaceTypes.html */
        return $this->call('flickr.places.getPlaceTypes', array());
    }

    public function places_getShapeHistory ($place_id = NULL, $woe_id = NULL) {
        /* http://www.flickr.com/services/api/flickr.places.getShapeHistory.html */
        return $this->call('flickr.places.getShapeHistory', array('place_id' => $place_id, 'woe_id' => $woe_id));
    }

    public function places_getTopPlacesList ($place_type_id, $date = NULL, $woe_id = NULL, $place_id = NULL) {
        /* http://www.flickr.com/services/api/flickr.places.getTopPlacesList.html */
        return $this->call('flickr.places.getTopPlacesList', array('place_type_id' => $place_type_id, 'date' => $date, 'woe_id' => $woe_id, 'place_id' => $place_id));
    }

    public function places_placesForBoundingBox ($bbox, $place_type = NULL, $place_type_id = NULL, $recursive = NULL) {
        /* http://www.flickr.com/services/api/flickr.places.placesForBoundingBox.html */
        return $this->call('flickr.places.placesForBoundingBox', array('bbox' => $bbox, 'place_type' => $place_type, 'place_type_id' => $place_type_id, 'recursive' => $recursive));
    }

    public function places_placesForContacts ($place_type = NULL, $place_type_id = NULL, $woe_id = NULL, $place_id = NULL, $threshold = NULL, $contacts = NULL, $min_upload_date = NULL, $max_upload_date = NULL, $min_taken_date = NULL, $max_taken_date = NULL) {
        /* http://www.flickr.com/services/api/flickr.places.placesForContacts.html */
        return $this->call('flickr.places.placesForContacts', array('place_type' => $place_type, 'place_type_id' => $place_type_id, 'woe_id' => $woe_id, 'place_id' => $place_id, 'threshold' => $threshold, 'contacts' => $contacts, 'min_upload_date' => $min_upload_date, 'max_upload_date' => $max_upload_date, 'min_taken_date' => $min_taken_date, 'max_taken_date' => $max_taken_date));
    }

    public function places_placesForTags ($place_type_id, $woe_id = NULL, $place_id = NULL, $threshold = NULL, $tags = NULL, $tag_mode = NULL, $machine_tags = NULL, $machine_tag_mode = NULL, $min_upload_date = NULL, $max_upload_date = NULL, $min_taken_date = NULL, $max_taken_date = NULL) {
        /* http://www.flickr.com/services/api/flickr.places.placesForTags.html */
        return $this->call('flickr.places.placesForTags', array('place_type_id' => $place_type_id, 'woe_id' => $woe_id, 'place_id' => $place_id, 'threshold' => $threshold, 'tags' => $tags, 'tag_mode' => $tag_mode, 'machine_tags' => $machine_tags, 'machine_tag_mode' => $machine_tag_mode, 'min_upload_date' => $min_upload_date, 'max_upload_date' => $max_upload_date, 'min_taken_date' => $min_taken_date, 'max_taken_date' => $max_taken_date));
    }

    public function places_placesForUser ($place_type_id = NULL, $place_type = NULL, $woe_id = NULL, $place_id = NULL, $threshold = NULL, $min_upload_date = NULL, $max_upload_date = NULL, $min_taken_date = NULL, $max_taken_date = NULL) {
        /* http://www.flickr.com/services/api/flickr.places.placesForUser.html */
        return $this->call('flickr.places.placesForUser', array('place_type_id' => $place_type_id, 'place_type' => $place_type, 'woe_id' => $woe_id, 'place_id' => $place_id, 'threshold' => $threshold, 'min_upload_date' => $min_upload_date, 'max_upload_date' => $max_upload_date, 'min_taken_date' => $min_taken_date, 'max_taken_date' => $max_taken_date));
    }

    public function places_resolvePlaceId ($place_id) {
        /* http://www.flickr.com/services/api/flickr.places.resolvePlaceId.html */
        $rsp = $this->call('flickr.places.resolvePlaceId', array('place_id' => $place_id));
        return $rsp ? $rsp['location'] : $rsp;
    }

    public function places_resolvePlaceURL ($url) {
        /* http://www.flickr.com/services/api/flickr.places.resolvePlaceURL.html */
        $rsp = $this->call('flickr.places.resolvePlaceURL', array('url' => $url));
        return $rsp ? $rsp['location'] : $rsp;
    }

    public function places_tagsForPlace ($woe_id = NULL, $place_id = NULL, $min_upload_date = NULL, $max_upload_date = NULL, $min_taken_date = NULL, $max_taken_date = NULL) {
        /* http://www.flickr.com/services/api/flickr.places.tagsForPlace.html */
        return $this->call('flickr.places.tagsForPlace', array('woe_id' => $woe_id, 'place_id' => $place_id, 'min_upload_date' => $min_upload_date, 'max_upload_date' => $max_upload_date, 'min_taken_date' => $min_taken_date, 'max_taken_date' => $max_taken_date));
    }

    /* Prefs Methods */
    public function prefs_getContentType () {
        /* http://www.flickr.com/services/api/flickr.prefs.getContentType.html */
        $rsp = $this->call('flickr.prefs.getContentType', array());
        return $rsp ? $rsp['person'] : $rsp;
    }

    public function prefs_getGeoPerms () {
        /* http://www.flickr.com/services/api/flickr.prefs.getGeoPerms.html */
        return $this->call('flickr.prefs.getGeoPerms', array());
    }

    public function prefs_getHidden () {
        /* http://www.flickr.com/services/api/flickr.prefs.getHidden.html */
        $rsp = $this->call('flickr.prefs.getHidden', array());
        return $rsp ? $rsp['person'] : $rsp;
    }

    public function prefs_getPrivacy () {
        /* http://www.flickr.com/services/api/flickr.prefs.getPrivacy.html */
        $rsp = $this->call('flickr.prefs.getPrivacy', array());
        return $rsp ? $rsp['person'] : $rsp;
    }

    public function prefs_getSafetyLevel () {
        /* http://www.flickr.com/services/api/flickr.prefs.getSafetyLevel.html */
        $rsp = $this->call('flickr.prefs.getSafetyLevel', array());
        return $rsp ? $rsp['person'] : $rsp;
    }

    /* Reflection Methods */
    public function reflection_getMethodInfo ($method_name) {
        /* http://www.flickr.com/services/api/flickr.reflection.getMethodInfo.html */
        $this->request("flickr.reflection.getMethodInfo", array("method_name" => $method_name));
        return $this->parsed_response ? $this->parsed_response : false;
    }

    public function reflection_getMethods () {
        /* http://www.flickr.com/services/api/flickr.reflection.getMethods.html */
        $this->request("flickr.reflection.getMethods");
        return $this->parsed_response ? $this->parsed_response['methods']['method'] : false;
    }

    /* Stats Methods */
    public function stats_getCollectionDomains ($date, $collection_id = NULL, $per_page = NULL, $page = NULL) {
        /* http://www.flickr.com/services/api/flickr.stats.getCollectionDomains.html */
        return $this->call('flickr.stats.getCollectionDomains', array('date' => $date, 'collection_id' => $collection_id, 'per_page' => $per_page, 'page' => $page));
    }

    public function stats_getCollectionReferrers ($date, $domain, $collection_id = NULL, $per_page = NULL, $page = NULL) {
        /* http://www.flickr.com/services/api/flickr.stats.getCollectionReferrers.html */
        return $this->call('flickr.stats.getCollectionReferrers', array('date' => $date, 'domain' => $domain, 'collection_id' => $collection_id, 'per_page' => $per_page, 'page' => $page));
    }

    public function stats_getCollectionStats ($date, $collection_id) {
        /* http://www.flickr.com/services/api/flickr.stats.getCollectionStats.html */
        return $this->call('flickr.stats.getCollectionStats', array('date' => $date, 'collection_id' => $collection_id));
    }

    public function stats_getCSVFiles () {
        /* http://www.flickr.com/services/api/flickr.stats.getCSVFiles.html */
        return $this->call('flickr.stats.getCSVFiles', array());
    }

    public function stats_getPhotoDomains ($date, $photo_id = NULL, $per_page = NULL, $page = NULL) {
        /* http://www.flickr.com/services/api/flickr.stats.getPhotoDomains.html */
        return $this->call('flickr.stats.getPhotoDomains', array('date' => $date, 'photo_id' => $photo_id, 'per_page' => $per_page, 'page' => $page));
    }

    public function stats_getPhotoReferrers ($date, $domain, $photo_id = NULL, $per_page = NULL, $page = NULL) {
        /* http://www.flickr.com/services/api/flickr.stats.getPhotoReferrers.html */
        return $this->call('flickr.stats.getPhotoReferrers', array('date' => $date, 'domain' => $domain, 'photo_id' => $photo_id, 'per_page' => $per_page, 'page' => $page));
    }

    public function stats_getPhotosetDomains ($date, $photoset_id = NULL, $per_page = NULL, $page = NULL) {
        /* http://www.flickr.com/services/api/flickr.stats.getPhotosetDomains.html */
        return $this->call('flickr.stats.getPhotosetDomains', array('date' => $date, 'photoset_id' => $photoset_id, 'per_page' => $per_page, 'page' => $page));
    }

    public function stats_getPhotosetReferrers ($date, $domain, $photoset_id = NULL, $per_page = NULL, $page = NULL) {
        /* http://www.flickr.com/services/api/flickr.stats.getPhotosetReferrers.html */
        return $this->call('flickr.stats.getPhotosetReferrers', array('date' => $date, 'domain' => $domain, 'photoset_id' => $photoset_id, 'per_page' => $per_page, 'page' => $page));
    }

    public function stats_getPhotosetStats ($date, $photoset_id) {
        /* http://www.flickr.com/services/api/flickr.stats.getPhotosetStats.html */
        return $this->call('flickr.stats.getPhotosetStats', array('date' => $date, 'photoset_id' => $photoset_id));
    }

    public function stats_getPhotoStats ($date, $photo_id) {
        /* http://www.flickr.com/services/api/flickr.stats.getPhotoStats.html */
        return $this->call('flickr.stats.getPhotoStats', array('date' => $date, 'photo_id' => $photo_id));
    }

    public function stats_getPhotostreamDomains ($date, $per_page = NULL, $page = NULL) {
        /* http://www.flickr.com/services/api/flickr.stats.getPhotostreamDomains.html */
        return $this->call('flickr.stats.getPhotostreamDomains', array('date' => $date, 'per_page' => $per_page, 'page' => $page));
    }

    public function stats_getPhotostreamReferrers ($date, $domain, $per_page = NULL, $page = NULL) {
        /* http://www.flickr.com/services/api/flickr.stats.getPhotostreamReferrers.html */
        return $this->call('flickr.stats.getPhotostreamReferrers', array('date' => $date, 'domain' => $domain, 'per_page' => $per_page, 'page' => $page));
    }

    public function stats_getPhotostreamStats ($date) {
        /* http://www.flickr.com/services/api/flickr.stats.getPhotostreamStats.html */
        return $this->call('flickr.stats.getPhotostreamStats', array('date' => $date));
    }

    public function stats_getPopularPhotos ($date = NULL, $sort = NULL, $per_page = NULL, $page = NULL) {
        /* http://www.flickr.com/services/api/flickr.stats.getPopularPhotos.html */
        return $this->call('flickr.stats.getPopularPhotos', array('date' => $date, 'sort' => $sort, 'per_page' => $per_page, 'page' => $page));
    }

    public function stats_getTotalViews ($date = NULL) {
        /* http://www.flickr.com/services/api/flickr.stats.getTotalViews.html */
        return $this->call('flickr.stats.getTotalViews', array('date' => $date));
    }

    /* Tags Methods */
    public function tags_getClusterPhotos ($tag, $cluster_id) {
        /* http://www.flickr.com/services/api/flickr.tags.getClusterPhotos.html */
        return $this->call('flickr.tags.getClusterPhotos', array('tag' => $tag, 'cluster_id' => $cluster_id));
    }

    public function tags_getClusters ($tag) {
        /* http://www.flickr.com/services/api/flickr.tags.getClusters.html */
        return $this->call('flickr.tags.getClusters', array('tag' => $tag));
    }

    public function tags_getHotList ($period = NULL, $count = NULL) {
        /* http://www.flickr.com/services/api/flickr.tags.getHotList.html */
        $this->request("flickr.tags.getHotList", array("period" => $period, "count" => $count));
        return $this->parsed_response ? $this->parsed_response['hottags'] : false;
    }

    public function tags_getListPhoto ($photo_id) {
        /* http://www.flickr.com/services/api/flickr.tags.getListPhoto.html */
        $this->request("flickr.tags.getListPhoto", array("photo_id" => $photo_id));
        return $this->parsed_response ? $this->parsed_response['photo']['tags']['tag'] : false;
    }

    public function tags_getListUser ($user_id = NULL) {
        /* http://www.flickr.com/services/api/flickr.tags.getListUser.html */
        $this->request("flickr.tags.getListUser", array("user_id" => $user_id));
        return $this->parsed_response ? $this->parsed_response['who']['tags']['tag'] : false;
    }

    public function tags_getListUserPopular ($user_id = NULL, $count = NULL) {
        /* http://www.flickr.com/services/api/flickr.tags.getListUserPopular.html */
        $this->request("flickr.tags.getListUserPopular", array("user_id" => $user_id, "count" => $count));
        return $this->parsed_response ? $this->parsed_response['who']['tags']['tag'] : false;
    }

    public function tags_getListUserRaw ($tag = NULL) {
        /* http://www.flickr.com/services/api/flickr.tags.getListUserRaw.html */
        return $this->call('flickr.tags.getListUserRaw', array('tag' => $tag));
    }

    public function tags_getRelated ($tag) {
        /* http://www.flickr.com/services/api/flickr.tags.getRelated.html */
        $this->request("flickr.tags.getRelated", array("tag" => $tag));
        return $this->parsed_response ? $this->parsed_response['tags'] : false;
    }

    public function test_echo ($args = array()) {
        /* http://www.flickr.com/services/api/flickr.test.echo.html */
        $this->request("flickr.test.echo", $args);
        return $this->parsed_response ? $this->parsed_response : false;
    }

    public function test_login () {
        /* http://www.flickr.com/services/api/flickr.test.login.html */
        $this->request("flickr.test.login");
        return $this->parsed_response ? $this->parsed_response['user'] : false;
    }

    public function urls_getGroup ($group_id) {
        /* http://www.flickr.com/services/api/flickr.urls.getGroup.html */
        $this->request("flickr.urls.getGroup", array("group_id"=>$group_id));
        return $this->parsed_response ? $this->parsed_response['group']['url'] : false;
    }

    public function urls_getUserPhotos ($user_id = NULL) {
        /* http://www.flickr.com/services/api/flickr.urls.getUserPhotos.html */
        $this->request("flickr.urls.getUserPhotos", array("user_id"=>$user_id));
        return $this->parsed_response ? $this->parsed_response['user']['url'] : false;
    }

    public function urls_getUserProfile ($user_id = NULL) {
        /* http://www.flickr.com/services/api/flickr.urls.getUserProfile.html */
        $this->request("flickr.urls.getUserProfile", array("user_id"=>$user_id));
        return $this->parsed_response ? $this->parsed_response['user']['url'] : false;
    }

    public function urls_lookupGallery ($url) {
        /* http://www.flickr.com/services/api/flickr.urls.lookupGallery.html */
        return $this->call('flickr.urls.lookupGallery', array('url' => $url));
    }

    public function urls_lookupGroup ($url) {
        /* http://www.flickr.com/services/api/flickr.urls.lookupGroup.html */
        $this->request("flickr.urls.lookupGroup", array("url"=>$url));
        return $this->parsed_response ? $this->parsed_response['group'] : false;
    }

    public function urls_lookupUser ($url) {
        /* http://www.flickr.com/services/api/flickr.photos.notes.edit.html */
        $this->request("flickr.urls.lookupUser", array("url"=>$url));
        return $this->parsed_response ? $this->parsed_response['user'] : false;
    }

    /**
     * Handle the Flickr callback.
     */
    public function callback($permissions = 'write', $default_redirect = null, $get = null)
    {
        // If get data is not passed in, then get it from the request.
        if (empty($get)) {
            $get = $_GET;
        }

        if (empty($default_redirect)) {
            $default_redirect = '/';
        }

        // If a token is already stored in the session, then it is probably
        // expired or invalid, so remove it. A new token will be fetched.
        if ($this->session->has($this->token_session_var)) {
            $this->session->remove($this->token_session_var);
        }

        // If we have a page to redirect back to, then pull that out of the session.
        if ($this->session->has($this->redirect_session_var) && $this->session->get($this->redirect_session_var, '') != '') {
            $redirect = $this->session->get($this->redirect_session_var);
            $this->session->remove($this->redirect_session_var);
        }

        // A "frob" will have been passed in by Flickr if the user has authenticated
        // the request.
        if (empty($get['frob'])) {
            // No frob provided; authenticate the user on Flickr.
            // This will  be an exit point (redirect to Flickr) because the auth token
            // has been removed from the session.
            // CHECKME: we have removed the redirect URL earlier, so are we going to have a problem
            // redirecting to the page we originally came from? Possibly we only extract the redirect
            // from the session once we successfully get the token.
            $this->auth($permissions, false);
        } else {
            // Get the token from Flickr (using the frob) and store it in the session.
            $this->auth_getToken($get['frob']);
        }

        // If we get this far, then we should have a token for accessing the Flickr services in
        // our session.
        // Redirect back to the page we came from, or the default.
        if (empty($redirect)) {
            header("Location: " . $default_redirect);
        } else {
            header("Location: " . $redirect);
        }
    }
}

