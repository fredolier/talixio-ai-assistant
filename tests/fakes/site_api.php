<?php
// A small shop whose pages load their data from its own JSON API (1.37.0, "learn this site's API").
$p = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$json = function (int $code, $d) { http_response_code($code); header('Content-Type: application/json'); echo json_encode($d); };
$signed = ($_COOKIE['sid'] ?? '') === 'good-session-1234567890abcdef1234';
if ($p === '/login') { setcookie('sid', 'good-session-1234567890abcdef1234', ['path' => '/', 'httponly' => true]); ?><!doctype html><title>Signed in</title><p>Signed in</p><?php }
elseif ($p === '/logout') { setcookie('sid', '', ['path' => '/', 'expires' => 1]); ?><!doctype html><title>Signed out</title><p>Signed out</p><?php }
elseif ($p === '/app') { ?><!doctype html><title>Shop app</title><ul id="list">loading</ul><p id="orders">…</p>
<script>
fetch('/api/products?q=lamp&session_token=tok-9f8e7d6c5b4a39281706f5e4d3c2b1a0').then(r => r.json()).then(d => { document.getElementById('list').textContent = d.items.map(i => i.name).join(', '); });
fetch('/api/orders', { credentials: 'same-origin' }).then(r => r.json()).then(d => { document.getElementById('orders').textContent = 'Orders: ' + (d.orders || []).length; });
fetch('/api/cart', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ product: 'p1', qty: 1, password: 'hunter2' }) });
</script><?php }
elseif ($p === '/api/products') $json(200, ['items' => array_map(fn($i) => ['id' => "p$i", 'name' => ($_GET['q'] ?? 'item') . " $i", 'price' => 10 * $i, 'api_key' => 'fake_key_0123456789abcdefABCDEFghij'], range(1, 6)), 'q' => $_GET['q'] ?? '']);
elseif ($p === '/api/orders') $signed ? $json(200, ['orders' => [['id' => 'o1', 'total' => 59], ['id' => 'o2', 'total' => 12]]]) : $json(401, ['error' => 'login required']);
elseif (preg_match('#^/api/orders/([^/]+)$#', $p, $m)) $signed ? $json(200, ['id' => urldecode($m[1]), 'raw' => $m[1]]) : $json(401, ['error' => 'login required']);
elseif ($p === '/api/cart') { file_put_contents(sys_get_temp_dir() . '/talixio-site-cart.json', file_get_contents('php://input')); $json(200, ['ok' => true, 'method' => $_SERVER['REQUEST_METHOD']]); }
elseif ($p === '/api/keyed') ($_SERVER['HTTP_X_API_KEY'] ?? '') === 'k-123' ? $json(200, ['ok' => 'keyed', 'lang' => $_GET['lang'] ?? '']) : $json(401, ['error' => 'bad key']);
elseif ($p === '/api/query-keyed') ($_GET['api_key'] ?? '') === 'q-456' ? $json(200, ['ok' => 'query']) : $json(403, ['error' => 'bad key']);
else { http_response_code(404); echo 'not found'; }
