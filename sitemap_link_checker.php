<?php

/**
 * Simple script to pull in a Sitemap XML file and check all urls listed.  Recursively pull in additional sitemap xml files from
 * sitemap index files.
 * 
 * Usage:
 *     php sitemap_link_checker.php <sitemap xml file url> [<max number of concurrent requests>]
 *     
 * Results are generated comma separated format to standard out. The format is as follow:
 *     
 *     <http status code>,<url being checked>,<sitemap xml>,<message>
 *     
 *         - http status code  : the return code from the link checked 
 *         - url being checked : the url being check or the sitemap file being loaded
 *         - sitemap xml url   : The current sitemap being read, aka the location of the link
 *         - message           : Either a success message, error message, or indication of a redirected link and the url where it 
 *                               was being redirected
* General Notes:
 *      1. If a sitemap file is and index of other sitemap files it will pull those in and parse them as well.  
 *      2. Sitemap xml files be either a sitemap index file listing other sitemap xml files or be a sitemap file with urls, not both.
 *      3. This doesn't validate the structure of your sitemap files.
 *      4. The max number of concurrent requests is optional, by default it will run one at a time.  There is no forced limit to
 *         the number you can specify, so feel free to happily shoot both your and the server you're hitting's feet completely off.
 *      5. Some versions of PHP have problems with curl multi connect functionality and will simply hang.  Ran successfully with 5.4.17
 *          
 * Notes for SITEMAP INDEX files ONLY:
 *     1. For simplicity, errors with SITEMAP INDEX files (those listing other sitemap files) are written to this file as well.
 *        Yes, I know this may add confusion, but I just wanted all results to be in one place.
 *     2. The status will be 404 if the indicated sitemap url can't be loaded
 *     3. The status will be 500 if there seems to be some xml error with the sitemap file
 *
 * @author Raymond Plante
 * @version 0.1
 *     
 */

if (empty($argv[1] )) { 
    echo "No Sitemap URL specified";
    exit;
}

$sitemap_url = $argv[1];

$curl_requests = !empty($argv[2]) &&  (int)$argv[2] ? (int)$argv[2]: 1;

check_sitemap($argv[1], $curl_requests);

/**
 * Reads a sitemap xml file.  If it's a sitemap index file, parses it and loads all the included sitemap.xml files to be checked. 
 * If the file is regular sitemap file with listing of urls, it calls a function to check the links.
 * 
 * @param string $sitemap_url the url of the sitemap or sitemap index xml file being checked
 * @param int $curl_requests maximum number of concurrent curl requests to make at any given time.
 */
function check_sitemap ($sitemap_url, $curl_requests) {
    $xml_objects = simplexml_load_file($sitemap_url);
    if(!empty($xml_objects)) {
       if(isset($xml_objects->sitemap)) {
           foreach($xml_objects as $xml_object)  {
               check_sitemap($xml_object->loc, $curl_requests);       
           }
       } else if (isset($xml_objects->url)) {
           $url_array = json_decode(json_encode($xml_objects), TRUE);
           check_urls($sitemap_url, $url_array['url'], $curl_requests);
       } else {
           print_message(500, "????", "Invalid sitemap url", $sitemap_url);
       }     
    } else {
        print_message(404, "????", "Sitemap file not found", $sitemap_url);  
    }   
}

/**
 * Checks the URLS of a sitemap xml file
 * 
 * @param sting $sitemap_url a url to the sitemap file providing the URLS being checked
 * @param array $url_array An array of URL items to be checked 
 * @param int $curl_requests maximum number of concurrent curl requests to make at any given time.
 */
function check_urls($sitemap_url, array $url_array, $curl_requests) {
    $count =  sizeof($url_array);
    $loops = ceil($count / $curl_requests);
    
    for($loop = 0; $loop < $loops; $loop++) {
        $urls = array_slice($url_array, $loop * $curl_requests, $curl_requests);
        
       $handles = array();
          foreach($urls as $url ) {
            $handle =  curl_init($url['loc']);
            curl_setopt($handle, CURLOPT_HEADER, TRUE);
            curl_setopt($handle,  CURLOPT_RETURNTRANSFER, TRUE);
            
            $handles[$url['loc']] = $handle;
            //add the two handles
        }
        
        $mh = curl_multi_init();
        
        foreach($handles as $handle) {
            curl_multi_add_handle($mh,$handle);
        }

        $active = null;
        //execute the handles
        do {
            $mrc = curl_multi_exec($mh, $active);
        } while ($mrc == CURLM_CALL_MULTI_PERFORM);
        
        while ($active && $mrc == CURLM_OK) {
            if (curl_multi_select($mh) != -1) {
                do {
                    $mrc = curl_multi_exec($mh, $active);
                } while ($mrc == CURLM_CALL_MULTI_PERFORM);
            }
        }  
        
        //close the handles
        foreach ($handles as $url =>  $handle) {
            $http_status = curl_getinfo($handle, CURLINFO_HTTP_CODE);
            
            $message = "Link Good"; 
            if($http_status == 301 || $http_status == 302) {
                $redirect = curl_getinfo($handle, CURLINFO_REDIRECT_URL);
                $message = "Redirected to: $redirect";
            }
            print_message($http_status, $url, $sitemap_url, $message);
            curl_multi_remove_handle($mh, $handle);
        }
       
        curl_multi_close($mh);
    }
}

/**
 * Simple function to print out csv string
 * 
 * @param int $status
 * @param string $url
 * @param string $sitemap_url
 * @param string $message
 */
function print_message($status, $url,  $sitemap_url, $message) {
    echo " \"$status\",\"$url\",\"$sitemap_url\",\"$message\"\n";
    
}
