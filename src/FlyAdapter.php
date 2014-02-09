<?php

namespace Academe\Flickr;

use League\Flysystem\Adapter\AbstractAdapter;

class FlyAdapter extends AbstractAdapter
{
    // Injected Flickr API.
    protected $api;

    // Customised alphabet for base58 encoding.
    protected $alphabet = '123456789abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ';

    // List of mimetypes by extension
    protected $mimetypes = array(
        'jpg' => 'image/jpeg',
        'gif' => 'image/gif',
        'png' => 'image/png',
        'csv' => 'text/plain',
        'txt' => 'text/plain',
        'json' => 'text/plain',
    );

    // Need an active/authorised Flickr API.
    // Ideally we would have an ApiInterface here for more flexibility.
    public function __construct(Api $api)
    {
        $this->api = $api;
    }

    /**
     * Various URLs into Flickr.
     */
    public function profileUrl($user_id)
    {
        return "http://www.flickr.com/people/$user_id/";
    }

    public function photostreamUrl($user_id)
    {
        return "http://www.flickr.com/photos/$user_id/";
    }

    public function photostreamPhotoUrl($user_id, $photo_id)
    {
        return "http://www.flickr.com/photos/$user_id/$photo_id/";
    }

    public function photosetsUrl($user_id)
    {
        return "http://www.flickr.com/photos/$user_id/sets/";
    }

    public function photosetUrl($user_id, $set_id)
    {
        return "http://www.flickr.com/photos/$user_id/sets/$set_id/";
    }

    public function shortPhotoUrl($photo_id)
    {
        $base58_photo_id = $this->base58_encode($photo_id);
        return "http://flic.kr/p/$base58_photo_id";
    }

    // Size can be:
    // s    small square 75x75
    // q    large square 150x150
    // t    thumbnail, 100 on longest side
    // m    small, 240 on longest side
    // n    small, 320 on longest side
    // -    medium, 500 on longest side
    // z    medium 640, 640 on longest side
    // c    medium 800, 800 on longest side†
    // b    large, 1024 on longest side (not all availble pre-2010)
    // o    original, but we also need to know the original extension (jpg, gif, png) "originalformat".
    //      also need to pass in "originalsecret" which is a different secret than the resized images.

    public function photoUrl($farm_id, $server_id, $photo_id, $secret, $size = null, $ext = 'jpg')
    {
        if (isset($size)) {
            $size_code = "_$size";
        } else {
            $size_code = '';
        }

        return "http://farm${farm_id}.staticflickr.com/${server_id}/${photo_id}_${secret}${size_code}.${ext}";
    }

    // Encode a number as "base 58".
    // This is like base 64 but with some ambiguous letters and digits excluded, e.g. 0Oo 1Il.
    function base58_encode($num) {
        $base_count = strlen($this->alphabet);
        $encoded = '';
        while ($num >= $base_count) {
            $div = $num / $base_count;
            $mod = ($num - ($base_count * intval($div)));
            $encoded = $this->alphabet[$mod] . $encoded;
            $num = intval($div);
        }

        if ($num) $encoded = $this->alphabet[$num] . $encoded;

        return $encoded;
    }

    // Decode a number from "base 58".
    function base58_decode($num) {
        $decoded = 0;
        $multi = 1;
        while (strlen($num) > 0) {
            $digit = $num[strlen($num)-1];
            $decoded += $multi * strpos($this->alphabet, $digit);
            $multi = $multi * strlen($this->alphabet);
            $num = substr($num, 0, -1);
        }

        return $decoded;
    }

    public function update($path, $contents)
    {
    }

    public function write($path, $contents, $config = null)
    {
    }

    public function rename($path, $newpath)
    {
    }

    public function delete($path)
    {
    }

    public function deleteDir($dirname)
    {
    }

    public function createDir($dirname)
    {
    }

    /**
     * Take an array of arrays, and format it into a CSV file content.
     */
    public function formatCsv(array $data)
    {
        $rows = array();

        foreach($data as $row) {
            foreach($row as &$row_value) {
                if (strpos($row_value, '"') !== false || strpos($row_value, ',') !== false) {
                    $row_value = '"' . str_replace('"', '""', $row_value) . '"';
                }
            }
            unset($row_value);

            $rows[] = implode(',', $row);
        }

        return implode("\n", $rows);
    }

    public function read($path)
    {
        if (preg_match('#^contacts/(friends|family|both|neither)/metadata.csv$#', $path)) {
            list(, $filter) = explode('/', $path);
        }

        $list = $this->api->contacts_getList($filter);
        $metadata = array(array(
            'nsid', 'username', 'realname', 'friend', 'family', 'profile', 'photostream', 'photosets',
            'photos_count', 'mobileurl',
        ));

        if ($list['total'] > 0) {
            foreach($list['contact'] as $contact) {
                // This can be a bit slow, fetching for each person.
                // It probably needs to be cached.
                $person = $this->api->people_getInfo($contact['nsid']);

                $metadata[] = array(
                    $contact['nsid'],
                    $contact['username'],
                    $contact['realname'],
                    $contact['friend'],
                    $contact['family'],
                    $this->profileUrl($contact['nsid']),
                    $this->photostreamUrl($contact['nsid']),
                    $this->photosetsUrl($contact['nsid']),

                    $person['photos']['count'],
                    $person['mobileurl'],
                );
            }

            return array('contents' => $this->formatCsv($metadata));
        }

    }

    /**
     * Format a simple directory array.
     * TODO: support additional metadata.
     */
    public function formatDirectory($directory)
    {
        $dir = array('type' => 'dir', 'path' => $directory);

        return $dir;
    }

    /**
     * Format a file directory entry.
     */
    public function formatFile($name, $base, $meta)
    {
        $type = 'file';
        $path = (empty($base) ? $name : "$base/$name");
        $visibility = 'public';
        $size = (!empty($meta['size']) ? $meta['size'] : 0);
        $timestamp = (!empty($meta['timestamp']) ? $meta['timestamp'] : time());

        // Base the mimetype off the extension only.
        $ext = pathinfo($path, PATHINFO_EXTENSION);
        $mimetype = (isset($this->mimetypes[$ext]) ? $this->mimetypes[$ext] : 'text/plain');

        $file = compact('type', 'path', 'visibility', 'size', 'timestamp', 'mimetype');

        return $file;
    }

    public function listContents($directory = '', $recursive = false)
    {
        $files = array();

        // A big, long if-else statement just for the demo.
        // We would probably take an approach similar to the routes, registering
        // matching paths and wildcards then handlign them off to different methods to handle.

        if ($directory == '' || $directory == '/') {
            // Root directory.
            // Return the main entry directoris into the Flickr database.
            $files = array(
                $this->formatDirectory('contacts'),
            );
        } elseif ($directory == 'contacts') {
            // The contacts directory
            $files[] = $this->formatDirectory('contacts/friends');
            $files[] = $this->formatDirectory('contacts/family');
            $files[] = $this->formatDirectory('contacts/both');
            $files[] = $this->formatDirectory('contacts/neither');
        } elseif (preg_match('#^contacts/(friends|family|both|neither)$#', $directory)) {
            // We are in one of the contacts folders.
            list(, $filter) = explode('/', $directory);

            // Fetch the list of contacts according to the filter.
            $list = $this->api->contacts_getList($filter);

            if ($list['total'] > 0) {
                // Make each contact a directory, but we will dump a metadata file into this base directory.
                foreach($list['contact'] as $contact) {
                    $files[] = $this->formatDirectory("contacts/$filter/" . $contact['nsid']);

                    /*$metadata[] = array(
                        $contact['nsid'],
                        $contact['username'],
                        $contact['realname'],
                        $contact['friend'],
                        $contact['family'],
                        $contact['profile'],
                    );*/
                }

                // Add the metadata.
                // It will be CSV for this demo, but could be other formats, or even multiple
                // metadata files with different formats.
                $files[] = $this->formatFile('metadata.csv', $directory, array());
            }
        }

        return $files;
    }

    public function getMetadata($path)
    {
        // The source of metadata will depend on what the path is.
        // TODO
        if (preg_match('#^contacts/(friends|family|both|neither)/metadata.csv$#', $path)) {
            return array(
                $this->formatFile('metadata.csv', $path, array()),
            );
        }
    }

    // Bizarely, these methods all need to return the metadata.
    public function getSize($path)
    {
        return $this->getMetadata($path);
    }

    public function getMimetype($path)
    {
        return $this->getMetadata($path);
    }

    public function getTimestamp($path)
    {
        return $this->getMetadata($path);
    }

    public function has($path)
    {
        return $this->getMetadata($path);
    }
}

