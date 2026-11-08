<?php
declare(strict_types=1);
// Forecast (Google TimesFM service or the built-in fallback) and decision models (POST /v1/systemone).
require_once __DIR__ . '/../helpers.php';
use Talixio\{App, Config, Decision, Fleet, Forecast, Settings};

$d = test_dir('prediction');
foreach (['TALIXIO_DATA_DIR' => $d, 'TALIXIO_PROVIDER' => 'echo', 'TALIXIO_EMBEDDINGS' => 'off', 'TALIXIO_NOTIFY' => 'none'] as $k => $v) putenv("$k=$v");
putenv('TALIXIO_FORECAST_URL'); putenv('TALIXIO_DECISION_URL'); putenv('TALIXIO_DECISION_KEY'); putenv('TALIXIO_DECISION_MODEL');
$app = App::fromEnv();
$err = function (callable $f): string { try { $f(); return ''; } catch (\Throwable $e) { return $e->getMessage(); } };

// ---- series parsing
check('forecast: parses lists, commas, lines and decimal commas', Forecast::parseSeries('1,2,3') === [1.0, 2.0, 3.0]
    && Forecast::parseSeries("10\n12,5\n14") === [10.0, 12.5, 14.0] && Forecast::parseSeries([1, '2', 3.5]) === [1.0, 2.0, 3.5]);
check('forecast: rejects text and series that are too short', str_contains($err(fn() => Forecast::parseSeries('1,a,3')), 'only numbers') && str_contains($err(fn() => Forecast::parseSeries([1, 2])), 'at least 3'));

// ---- built-in fallback (no service set)
$y = []; for ($i = 0; $i < 36; $i++) $y[] = 100 + $i * 2 + [0, 10, 20, 10, 0, -10, -20, -10, 0, 5, -5, 0][$i % 12];
$r = Forecast::run($app->cfg, $y, 12, 12);
check('forecast: without a service the built-in method answers and says so', count($r['mean']) === 12 && count($r['p10']) === 12 && count($r['p90']) === 12
    && $r['p10'][0] <= $r['mean'][0] && $r['mean'][0] <= $r['p90'][0] && !str_contains(strtolower($r['method']), 'timesfm'), json_encode($r['method']));
check('forecast: the seasonal pattern is kept (peak at the same place)', $r['mean'][1] > $r['mean'][0] && $r['mean'][2] > $r['mean'][1], json_encode($r['mean']));
check('forecast: the horizon is capped', count(Forecast::run($app->cfg, $y, 100000)['mean']) === Forecast::MAX_HORIZON);
$txt = Forecast::render($r, $y);
check('forecast: the rendering names the method and gives an interval', str_contains($txt, $r['method']) && str_contains($txt, '80'), $txt);

// ---- TimesFM service
putenv('TALIXIO_FORECAST_URL=http://talixio-caddy:8765');
$cfg = Config::fromEnv();
$seen = null;
Forecast::$post = function (string $url, array $payload) use (&$seen): array {
    $seen = [$url, $payload];
    $h = $payload['horizon']; $m = array_fill(0, $h, 5.0);
    return [200, json_encode(['model' => 'TimesFM 2.5', 'mean' => $m, 'p10' => array_fill(0, $h, 4.0), 'p90' => array_fill(0, $h, 6.0)])];
};
$r = Forecast::run($cfg, [1.0, 2.0, 3.0, 4.0], 3);
check('forecast: the service is called on /forecast with series and horizon', $seen[0] === 'http://talixio-caddy:8765/forecast' && $seen[1] === ['series' => [1.0, 2.0, 3.0, 4.0], 'horizon' => 3]
    && $r['method'] === 'TimesFM 2.5' && $r['mean'] === [5.0, 5.0, 5.0] && $r['p10'][0] === 4.0, json_encode($r));
Forecast::$post = fn() => [500, json_encode(['error' => 'boom'])];
$r = Forecast::run($cfg, [1, 2, 3, 4, 5, 6], 2);
check('forecast: when the service fails the fallback answers and the note says why', count($r['mean']) === 2 && str_contains($r['note'], 'did not answer') && str_contains($r['note'], 'boom'), json_encode($r));
Forecast::$post = fn() => [200, json_encode(['mean' => [1.0]])];
check('forecast: an answer of the wrong length is refused (fallback used)', str_contains(Forecast::run($cfg, [1, 2, 3, 4], 3)['note'], 'did not answer'));
Forecast::$post = null; putenv('TALIXIO_FORECAST_URL');

// ---- decisions
check('decision: question validation', str_contains($err(fn() => Decision::questions([])), 'between 1 and 20')
    && str_contains($err(fn() => Decision::questions(['a' => ['type' => 'text']])), 'type must be')
    && str_contains($err(fn() => Decision::questions(['a' => ['type' => 'choice', 'criteria' => ['x']]])), '2 to 26')
    && str_contains($err(fn() => Decision::questions(['a' => ['type' => 'score', 'criteria' => ['low']]])), '2 to 10')
    && str_contains($err(fn() => Decision::questions(['bad id' => ['type' => 'noul']])), 'bad question id'));
$q = Decision::questions(['go' => ['type' => 'choice', 'instructions' => ' Which? ', 'criteria' => ['yes', 'no']], 'risk' => ['type' => 'score', 'criteria' => ['low', 'mid', 'high']], 'ok' => ['type' => 'noul']]);
check('decision: choices given as a list become keyed options', $q['go']['criteria'] === ['yes' => null, 'no' => null] && $q['go']['instructions'] === 'Which?' && $q['risk']['criteria'] === ['low', 'mid', 'high'] && $q['ok'] === ['type' => 'noul']);
check('decision: nothing is sent without an address', str_contains($err(fn() => Decision::run($app->cfg, 'state', ['ok' => ['type' => 'noul']])), 'no decision model is set'));
putenv('TALIXIO_DECISION_URL=https://kev.example'); putenv('TALIXIO_DECISION_KEY=sk-k'); putenv('TALIXIO_DECISION_MODEL=kev-latest');
$cfg = Config::fromEnv(); $seen = null;
Decision::$post = function (string $url, array $payload, array $headers) use (&$seen): array {
    $seen = [$url, $payload, $headers];
    return [200, json_encode(['answers' => [
        'go' => ['type' => 'choice', 'choice' => 'yes', 'confidence' => 0.8, 'probabilities' => ['yes' => 0.8, 'no' => 0.2]],
        'risk' => ['type' => 'score', 'score' => 1.0, 'confidence' => 0.6, 'legend' => ['0' => 'low', '1' => 'mid', '2' => 'high']],
        'ok' => ['type' => 'noul', 'noul' => 0.9], 'extra' => ['type' => 'noul', 'noul' => 0.1]]])];
};
$a = Decision::run($cfg, 'The client asks for a 10% discount.', ['go' => ['type' => 'choice', 'criteria' => ['yes', 'no']], 'risk' => ['type' => 'score', 'criteria' => ['low', 'mid', 'high']], 'ok' => ['type' => 'noul']]);
check('decision: posts to /v1/systemone with state, model and bearer key; keeps only the questions asked', $seen[0] === 'https://kev.example/v1/systemone'
    && $seen[1]['state'] === 'The client asks for a 10% discount.' && $seen[1]['model'] === 'kev-latest' && in_array('Authorization: Bearer sk-k', $seen[2], true) && array_keys($a) === ['go', 'risk', 'ok'], json_encode($seen));
$txt = Decision::render($a);
check('decision: the rendering gives choice, probabilities, level and yes-probability', str_contains($txt, '**go** → yes (confidence 80%)') && str_contains($txt, 'yes 80%, no 20%')
    && str_contains($txt, 'level mid') && str_contains($txt, 'yes with probability 90%') && str_contains($txt, 'not from a language model'), $txt);
Decision::$post = fn() => [200, json_encode(['answers' => []])];
check('decision: a missing answer is an error', str_contains($err(fn() => Decision::run($cfg, 's', ['ok' => ['type' => 'noul']])), 'no answer for "ok"'));
Decision::$post = fn() => [401, json_encode(['error' => 'bad key'])];
check('decision: an HTTP error carries the server message', str_contains($err(fn() => Decision::run($cfg, 's', ['ok' => ['type' => 'noul']])), 'HTTP 401: bad key'));
Decision::$post = null; putenv('TALIXIO_DECISION_URL'); putenv('TALIXIO_DECISION_KEY'); putenv('TALIXIO_DECISION_MODEL');

// ---- tools and settings
$names = fn($app) => $app->tools->names();
{
    check('tools: forecast is always offered, decide only when a decision model is set', in_array('forecast', $names($app), true) && !in_array('decide', $names($app), true));
    putenv('TALIXIO_DECISION_URL=https://kev.example');
    $app2 = App::fromEnv();
    check('tools: decide appears once an address is set', in_array('decide', $names($app2), true));
    putenv('TALIXIO_DECISION_URL');
}
$out = $app->tools->run('forecast', ['series' => '1 2 3 4 5 6 7 8', 'horizon' => 3]);
check('tools: forecast runs the fallback and answers in text', is_string($out) && str_contains($out, 'built-in') || str_contains((string)$out, 'Forecast'), (string)$out);
check('tools: forecast refuses made-up input', str_starts_with((string)$app->tools->run('forecast', ['series' => 'a b c']), 'error'));
$sch = json_encode(Settings::schema());
check('settings: the Forecast and decisions group sits in Model and budget', Settings::SECTIONS['prediction'] === 'model' && str_contains($sch, 'Forecast and decisions')
    && str_contains($sch, 'TALIXIO_FORECAST_URL') && str_contains($sch, 'TALIXIO_DECISION_URL') && str_contains($sch, 'TALIXIO_DECISION_KEY') && str_contains($sch, 'TALIXIO_DECISION_MODEL'));

// ---- Docker stack
$fl = new Fleet("$d/fleet", "$d/no-docker.sock");
$fs = $fl->state(); $fs['settings']['domain'] = 'assistant.example.com';
$cf0 = $fl->caddyfile($fs);
putenv('TALIXIO_MODELS=1');
$cf1 = $fl->caddyfile($fs);
check('fleet: with --with-models Caddy exposes only /forecast and /health to the instances', Fleet::modelsOn() && str_contains($cf1, "http://:8765 {\n\t@fc path /forecast /health") && str_contains($cf1, 'reverse_proxy talixio-models:8765'));
putenv('TALIXIO_MODELS');
check('fleet: without it nothing is exposed', !Fleet::modelsOn() && !str_contains($cf0, '8765'));
