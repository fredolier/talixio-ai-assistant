<?php
declare(strict_types=1);
// Every address the app calls is one the server answers (1.41.2): the Missions tab called api/directives for a month while
// the router had no 'directives' area, and every Accept said "not found". This reads the addresses in app.js and the routes
// in Web.php, so a route dropped or renamed on one side fails here instead of in someone's hands.
require_once __DIR__ . '/../helpers.php';

/** The addresses app.js calls: each quoted or template string starting with api/, its ${…} parts kept whole, up to ? or #. */
function census_paths(string $js): array
{
    $out = [];
    $n = strlen($js);
    for ($i = 0; ($i = strpos($js, 'api/', $i)) !== false; $i++) {
        $q = $js[$i - 1] ?? '';
        if (!in_array($q, ['`', "'", '"'], true)) continue;
        $p = ''; $j = $i;
        while ($j < $n) {
            $ch = $js[$j];
            if ($ch === $q || $ch === '?' || $ch === '#' || ctype_space($ch)) break;
            if ($ch === '$' && ($js[$j + 1] ?? '') === '{') { // ${ … } with its own braces and strings inside (also in "…" written inside a template)
                $depth = 0;
                for (; $j < $n; $j++) { if ($js[$j] === '{') $depth++; elseif ($js[$j] === '}' && --$depth === 0) break; }
                $p .= '{}'; $j++; continue;
            }
            $p .= $ch; $j++;
        }
        $out[$p] = true;
    }
    return array_keys($out);
}

/** The routes in a slice of Web.php: exact addresses, address patterns and address prefixes. */
function census_routes(string $php): array
{
    preg_match_all("#'(/api/[^'\$]*)'#", $php, $l);
    preg_match_all('~\#\^(/api/[^\#\']*)(?:\#|\')~', $php, $r); // '#^/api/agents/s/([\w.:-]+)/' . $p . '$#' counts by its fixed start
    preg_match_all("#str_starts_with\(\\\$path, '(/api/[^']*)'\)#", $php, $s);
    // a prefix ending in / is a guard before a closer look (str_starts_with($path, '/api/') …), not a route
    return [array_flip($l[1]), $r[1], array_values(array_filter($s[1], fn($p) => !str_ends_with($p, '/')))];
}

function census_matches(string $p, array $routes): bool
{
    [$lits, $rxs, $pres] = $routes;
    // what a ${…} part stands for: an id, a hex key, a name, or one of the words a route pattern allows there (tasks/${id}/${op})
    $fills = ['1', 'a1b2c3d4e5f60718', 'x'];
    foreach ($rxs as $rx) if (preg_match_all('/\(([a-z-]+(?:\|[a-z-]+)+)\)/', $rx, $w)) foreach ($w[1] as $alt) array_push($fills, ...explode('|', $alt));
    $fills = array_values(array_unique($fills));
    $tries = [''];
    foreach (array_slice(explode('{}', $p), 0, -1) as $i => $_) { // every way to fill the parts, in order
        $next = [];
        foreach ($tries as $t) foreach ($i < 2 ? $fills : ['1'] as $f) $next[] = $t === '' ? [$f] : [...$t, $f];
        $tries = $next;
    }
    foreach ($tries as $t) {
        $parts = explode('{}', $p); $c = array_shift($parts);
        foreach ($parts as $k => $rest) $c .= $t[$k] . $rest;
        $c = '/' . rtrim($c, '/');
        if (isset($lits[$c])) return true;
        foreach ($rxs as $rx) if (@preg_match("#^$rx#", $c) === 1) return true;
        foreach ($pres as $pre) if (str_starts_with($c, $pre)) return true;
    }
    return false;
}

/** The addresses of app.js the server does not answer, given Web.php's source. */
function census_missing(string $js, string $web): array
{
    $at = strpos($web, 'private function route(');
    $early = substr($web, 0, (int)strpos($web, '$out = $this->route(')); // handle(): answered before the areas
    preg_match('/match \(\$area\) \{(.*?)default =>/s', substr($web, (int)$at), $mm);
    preg_match_all("#'([a-z-]+)'#", $mm[1] ?? '', $am);
    $areas = array_flip($am[1]);
    $all = census_routes($web); $first = census_routes($early);
    $missing = [];
    foreach (census_paths($js) as $p) {
        $area = explode('/', $p)[1] ?? '';
        $reached = census_matches($p, $first) || isset($areas[$area]);
        if (!$reached || !census_matches($p, $all)) $missing[] = $p;
    }
    return $missing;
}

$js = (string)file_get_contents(dirname(__DIR__, 2) . '/web/public/app.js');
$web = (string)file_get_contents(dirname(__DIR__, 2) . '/src/Web.php');
$paths = census_paths($js);
check('census: the app\'s addresses are found (a broken reader would pass anything)', count($paths) > 250 && in_array('api/directives/{}/rate', $paths, true), count($paths) . ' ' . implode(' ', array_slice($paths, 0, 5)));
$missing = census_missing($js, $web);
check('census: every address app.js calls is routed by the server', $missing === [], implode(', ', $missing));
// the test itself: the month-long bug comes back when the area is taken out, and so does a route removed inside an area
$noArea = str_replace("'jobs', 'directives', 'mail'", "'jobs', 'mail'", $web);
check('census: an area missing from the router is caught', $noArea !== $web && in_array('api/directives/{}/rate', census_missing($js, $noArea), true));
$noRoute = preg_replace("#\\\$path === '/api/dav/unblock'#", "\$path === '/api/dav/unblocked'", $web, 1, $cnt);
check('census: a route missing inside an area is caught', $cnt === 1 && census_missing($js, $noRoute) === ['api/dav/unblock']);
