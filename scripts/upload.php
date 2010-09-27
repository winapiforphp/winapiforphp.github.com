<?php
/**
 * Simple Script using PHP http streams
 * To perform automated uploads to bitbucket
 * Goes to the download page, grabs the relevant S3 information
 * Makes posts with files
 *
 * NOTE: you MUST use regular authentication, oauth does NOT work
 */

class BitbucketUpload {
    protected $username;
    protected $password;
    protected $project;
    protected $boundary;
    protected $aws;

    public function setUsername($username) {
        $this->username = $username;
    }

    public function setPassword($password) {
        $this->password = $password;
    }

    public function setProject($project) {
        $this->project = $project;
    }

    public function getAws() {
        // Visit login page, get csrf token and session id
        $options = array(
            'http' => array(
                'method' => 'GET'
            )
        );

        $context = stream_context_create($options);
        $url = 'https://bitbucket.org/account/signin/';

        $string = file_get_contents($url, false, $context);

        // parse out csrf and session id headers
        foreach($http_response_header as $value) {
            if (strpos($value, 'Set-Cookie') === 0) {
                $data = explode(':', $value, 2);
                $data = array_map('trim', $data);
                $csrf = preg_match('/csrftoken=(.*?);/', $data[1], $matches);
                if ($csrf) {
                    $csrf_token = $matches[1];
                } else {
                    $session = preg_match('/sessionid=(.*?);/', $data[1], $matches);
                    if ($session) {
                        $sessionid = $matches[1];
                    }
                }
            }
        }

        // build params and headers
        $headers = array('Cookie: csrftoken=' . $csrf_token . '; sessionid=' . $sessionid . ';',
                         'Referer: https://bitbucket.org/account/signin/',
                         'Content-type: application/x-www-form-urlencoded');

        $params = array('username' => $this->username,
                        'password' => $this->password,
                        'csrfmiddlewaretoken' => $csrf_token);

        // POST to login page, get new sessionid
        $options = array(
            'http' => array(
                'method' => 'POST',
                'content' => http_build_query($params),
                'header' => $headers,
                //'max_redirects' => 1
            )
        );

        $context = stream_context_create($options);
        $url = 'https://bitbucket.org/account/signin/';

        $string = file_get_contents($url, false, $context);

        // parse out session id
        foreach($http_response_header as $value) {
            if (strpos($value, 'Set-Cookie') === 0) {
                $data = explode(':', $value, 2);
                $data = array_map('trim', $data);
                $session = preg_match('/sessionid=(.*?);/', $data[1], $matches);
                if ($session) {
                    $sessionid = $matches[1];
                    break;
                }
            }
        }

        $headers = array('Cookie: sessionid=' . $sessionid . ';');

        // set up options for the request
        $options = array(
            'http' => array(
                'method' => 'GET',
                'header' => $headers,
            )
        );

        $context = stream_context_create($options);
        $url = 'https://bitbucket.org/' . $this->project . '/downloads';

        $string = file_get_contents($url, false, $context);
        $params = array();
        $name = null;

        $dom = DOMDocument::loadHtml($string);
        $node = $dom->getElementById('new-download-form');
        foreach($node->getElementsByTagName('input') as $input) {
            foreach($input->attributes as $item) {
                if ($item->name === 'name') {
                    $name = $item->value;
                } elseif ($item->name === 'value') {
                    $params[$name] = $item->value;
                }
            }
        }
        $this->aws = $params;
        if (isset($this->aws['file'])) {
            unset($this->aws['file']);
        }

    }

    public function uploadFile($filename) {
        // get aws if necessary
        if (is_null($this->aws)) {
            $this->getAws();
        }

        // Grab our file
        //$data = file_get_contents($filename);
        $data = 'I am a cold turkey';

        $body = array();
        $this->boundary = '---BITBUCKETUPLOAD-' . md5(microtime());
        $params = $this->aws;
        $headers = array();
        $headers[] = 'Content-type: multipart/form-data; boundary=' . $this->boundary;

        foreach($params as $name => $value) {
            $body[] =  $this->encodeFormData($name, $value);
        }

        $body[] = $this->encodeFormData('file', $data, $filename);


        $body[] = "--{$this->boundary}--\r\n";
        $body = implode("\r\n", $body);
        $headers[] = 'Content-Length: ' . strlen($body);

        // POST to login page, get new sessionid
        $options = array(
            'http' => array(
                'method' => 'POST',
                'content' => $body,
                'header' => $headers
            )
        );

        $context = stream_context_create($options);
        $url = 'http://bbuseruploads.s3.amazonaws.com/';

        $string = file_get_contents($url, false, $context);

        var_dump($string);

    }

    public function encodeFormData($name, $value, $filename = null) {
        $ret = array();
        $ret[] = "--{$this->boundary}";

        if ($filename) {
            $ret[] = 'Content-Disposition: form-data; name="' . $name .'"; filename="' . basename($filename) . '"';
            $ret[] = "Content-type: application/octet-stream\r\n";
        } else {
            $ret[] = 'Content-Disposition: form-data; name="' . $name .'"';
        }

        $ret[] = '';
        $ret[] = $value;

        return implode("\r\n", $ret);
    }
}

$upload = new BitbucketUpload;
$upload->setUsername('auroraeosrose');
$upload->setPassword('iwiwqsdd');
$upload->setProject('garnetcms/garnetcms');
$upload->uploadFile(__FILE__);