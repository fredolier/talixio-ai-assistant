<?php
// Small test website for the browser tests.
$p = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($p === '/') { ?><!doctype html><title>Test Shop</title><h1>Welcome to the shop</h1>
<p id="js">loading</p><script>document.getElementById('js').textContent = 'Rendered by JavaScript: 3 products';</script>
<a href="/products">See products</a>
<form action="/search" method="get"><input name="q" placeholder="Search"><button>Go</button></form>
<form action="/login" method="post"><input name="email" placeholder="Email"><input type="password" name="pw"><button type="submit">Sign in</button></form>
<select id="c"><option>Red</option><option>Blue</option></select>
<?php } elseif ($p === '/products') { ?><!doctype html><title>Products</title><ul><li>Lamp 49 EUR</li><li>Desk 199 EUR</li></ul><?php }
elseif ($p === '/search') { ?><!doctype html><title>Results</title><p>Results for <?= htmlspecialchars($_GET['q'] ?? '') ?></p><?php }
elseif ($p === '/login') { file_put_contents(sys_get_temp_dir() . '/talixio-site-login.txt', json_encode($_POST)); ?><!doctype html><title>Signed in</title><p>Hello <?= htmlspecialchars($_POST['email'] ?? '') ?></p><?php }
