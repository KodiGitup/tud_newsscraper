<?php

namespace base;

/**
 * Interface newssource
 * @package base
 */



interface newssource
{
    /**
     * Returns the available feed posts in an array
     *
     * @return array
     */
    public function getItems();

}

/**
 * Class feedreader
 * @package base
 */
abstract class feedreader implements newssource
{
    /**
     * @var array $posts holds the posts currently available in the feed
     * @var string $feedid unique identifier for the current feed
     * @var boolean $downloadqualifier specifies if we are allowed to load data from a remote ressource
     * TIMEOUT a constant defining how long the posts shall be cached (in seconds)
     * CACHEDIR a constant holding the directory of the cached files
     * DOCTYPE type of the document to be cached
     * MAXSTRINGLENGTH when to truncate long strings
     * RESERVEDSPECIAL special value which indicates emptiness
     */
    private $posts = array();
    private $requestdata = "";
    protected $source = ""; //TODO remove override
    protected $feedid = "";
    private $downloadqualifier = true;
    const TIMEOUT =  1800;
    const CACHEDIR = "/../cache/";
    const DOCTYPE = "generic";
    const MAXSTRINGLENGTH = 80;
    const RESERVEDSPECIAL = "EMPTY";

    public final function getItems() {
        $this->updateItems();
        return $this->posts;
    }

    public function SetDownloadAllowed($input) {
        $this->downloadqualifier = $input;
    }

    protected function __construct($feedid) {
        $this->feedid = $feedid;
    }

    protected final function GetRequestData() {
        return $this->requestdata;
    }

    protected abstract function processItems();

    /**
     * This functions truncates text to a given length and removes newline-characters
     *
     * @param $input
     * @return string
     */
    protected final function tidyText($input) {
        $input = trim(preg_replace('/\s+/', ' ', $input)); //remove newlines

        if (strlen($input) > static::MAXSTRINGLENGTH-4) {
            return substr($input, 0, static::MAXSTRINGLENGTH-4)." ...";
        }
        else {
            return $input;
        }
    }

    protected final function SetPostingsToEmpty() {
        //usually something has gone wrong so we set posts to empty here
        $this->posts = array();
    }

    /**
     * Writes a posting to the output-array.
     * Thus ensuring consistent key/value-relations for a single posting in a newssource.
     *
     * @param $date integer A unix timestamp of when the posting was made
     * @param $author string The author of the posting
     * @param $text string The heading of the posting
     * @param $link string An URL to the posting
     * @return array The formatted posting
     */
    protected final function AppendToPostings($date, $author, $text, $link) {
        $output = array ("timestamp" => $date, "author" => $author, "text" => $text, "link" => $link);
        $this->posts[] = $output;
    }

    private final function IsDownloadAllowed() {
        return $this->downloadqualifier;
    }

    /**
     * Holds the internal logic for acquiring new feed data
     *
     * @return null
     */
    private final function updateItems() {
        if ($this->CacheFileAvailable()) {
            if (!$this->IsTimeout()) {
                $this->ReadFromCache();
            }
            elseif ($this->IsDownloadAllowed()) {
                //grabfromremote with conditional get
                if (($this->GrabFromRemote($this->CacheFileAge(), $this->GetEtagFromCache())) == false) {
                    $this->ReadFromCache();
                }
                //TODO else => return cache; decrement downloadcounter
            }
            else {
                //fallback: read from cache regardless of its age
                $this->ReadFromCache();
            }
        }
        else {
            //unconditional get from remote; fallback to "no output" if that fails
            if (($this->GrabFromRemote()) == false) {
                $this->SetPostingsToEmpty();
            }
        }
    }

    /**
     * Grab the feed from its remote location
     * Since PHP still has rather poor threading support we do accept simply accept this call to take a while rather
     * than to fork it in the background and return the cache contents for the meantime
     *
     * @param $cachefallback boolean defines if a fallback to the cache file is allowed if remote resource is unavailable TODO
     * @return null
     */
    private final function GrabFromRemote($fileage = self::RESERVEDSPECIAL, $etag = self::RESERVEDSPECIAL) {
        //setup cURL
        $curlhandler = curl_init();
        curl_setopt($curlhandler, CURLOPT_URL, $this->source);
        curl_setopt($curlhandler, CURLOPT_HEADER, true); //return body+header
        curl_setopt($curlhandler, CURLOPT_TIMEOUT, 10);
        curl_setopt($curlhandler, CURLOPT_RETURNTRANSFER, true);
        //curl_setopt($curlhandler, CURLOPT_USERAGENT, 'TUDnewsscraperbot/0.1 (+http://github.com/morido/tudnewsscraper)');
        curl_setopt($curlhandler, CURLOPT_USERAGENT, 'Mozilla/4.0 (compatible; MSIE 5.01; Windows NT 5.0)');

        if ($fileage != self::RESERVEDSPECIAL) {
            curl_setopt($curlhandler, CURLOPT_TIMECONDITION, CURL_TIMECOND_IFMODSINCE);
            curl_setopt($curlhandler, CURLOPT_TIMEVALUE, $fileage);
        }
        if ($etag != self::RESERVEDSPECIAL) {
            curl_setopt($curlhandler, CURLOPT_HTTPHEADER, array('If-None-Match: '.$etag));
        }

        //execute the request
        $response = curl_exec($curlhandler);
        $info = curl_getinfo($curlhandler);
        $http_return_code = $info['http_code'];
        $headers = substr($response, 0, $info['header_size']);

        if ($http_return_code == 304) {
            //content is up to date; stay with the cache
            touch($this->GetCacheFilename());
            $this->ReadFromCache();
            return true;
        }
        elseif ($http_return_code == 200) {
            //content is not up to date; preform update
            $body = substr($response, -$info['download_content_length']);
            $this->requestdata = $body;

            //get the etag from the header
            //we are intentionally not using http_parse_headers here - because a pecl install for a single function is overkill
            if ((preg_match("/etag: (.*)/i",$headers,$returnvalue)) === 1) {
                $etag = $returnvalue[1];
                //leave the etag as it is -- dont remove any surrounding quotes or similar
                $etag = trim($etag);
            }

            $this->processItems();
            $this->WriteToCache($etag);
            return true;
        }
        else {
            return false;
        }
    }

    /**
     * Returns the filename to be used for the Cache file
     * @return string
     */
    private final function GetCacheFilename() {
        return realpath(NULL).static::CACHEDIR.static::DOCTYPE."_".$this->feedid.".cache";
    }

    private final function CacheFileAvailable() {
        return file_exists($this->GetCacheFilename());
    }

    private final function CacheFileAge() {
        return filemtime($this->GetCacheFilename());
    }

    /**
     * Returns true if the cache-file is too old
     *
     * @return bool
     */
    private final function IsTimeout() {
        if (time() - $this->CacheFileAge() >= static::TIMEOUT) {
            return true;
        }
        else {
            return false;
        }
    }

    /**
     * Reads in data from the cache file
     */
    private final function ReadFromCache() {
        $output = unserialize(file_get_contents($this->GetCacheFilename()));
        $this->posts = $output->posts;
    }

    private final function GetEtagFromCache() {
        $output = unserialize(file_get_contents($this->GetCacheFilename()));
        return $output->etag;
    }

    /**
     * Writes data into the Cache file
     */
    private final function WriteToCache($etag) {
        //make sure we are not interfering with other parallel calls
        clearstatcache();
        touch($this->GetCacheFilename());

        $output = new \stdClass(); //sort of a struct...
        $output->etag = $etag;
        $output->posts = $this->posts;
        file_put_contents($this->GetCacheFilename(),serialize($output));
    }
}


final class feedsorter implements newssource {
    const MAXFRESHFEEDS =  1;

    private $feeds;
    private $itemsToReturn;
    private $currentDownloads = 0;

    public function __construct($feeds, $itemsToReturn) {
        $this->feeds = $feeds;
        $this->itemsToReturn = $itemsToReturn;
    }

    public function getItems() {
        //shuffle the feeds so we make sure that caches get updated in an equally likely manner
        shuffle($this->feeds);

        //Read out all available postings
        $output = array();
        foreach ($this->feeds as $feed) {
            $feed->SetDownloadAllowed($this->downloadAllowed());
            $postings = $feed->getItems();
            foreach ($postings as $posting) {
                $output[] = $posting;
            }
        }

        //Sort the resulting array with the newest posting first
        foreach ($output as $key => $value) {
            $timestamp[$key] = $value["timestamp"];
        }
        array_multisort($timestamp, SORT_DESC, $output);

        //Emit the n newest postings
        return array_slice($output, 0, $this->itemsToReturn);
    }

    /**
     * This ensures that only MAXFRESHFEEDS are downloaded from a remote site during each cycle. Since the feeds are in
     * random order for each invocation of the script this updates them "sequentially"
     *
     * @return bool
     */
    private function downloadAllowed() {
        if ($this->currentDownloads < static::MAXFRESHFEEDS) {
            $this->currentDownloads++;
            return true;
        }
        else {
            return false;
        }
    }

}


?>