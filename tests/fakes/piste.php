<?php
// Fake PISTE (Légifrance + JudiLibre) for tests.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
parse_str((string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY), $q);
$m = $_SERVER['REQUEST_METHOD'];
$body = (string)file_get_contents('php://input');
header('Content-Type: application/json');

if ($path === '/token') {
    parse_str($body, $p);
    if (($p['grant_type'] ?? '') === 'client_credentials' && ($p['client_id'] ?? '') === 'pcid' && ($p['client_secret'] ?? '') === 'psec') {
        echo json_encode(['access_token' => 'pat-1', 'expires_in' => 3600, 'token_type' => 'Bearer']); return;
    }
    http_response_code(400); echo '{"error":"invalid_client","error_description":"bad credentials"}'; return;
}
if (($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== 'Bearer pat-1') { http_response_code(401); echo '{"error":"unauthorized"}'; return; }

switch ($path) {
    case '/dila/legifrance/lf-engine-app/search':
        $in = json_decode($body, true);
        $term = $in['recherche']['champs'][0]['criteres'][0]['valeur'] ?? '';
        echo json_encode(['totalResultNumber' => 2, 'results' => [
            ['titles' => [['id' => 'LEGIARTI000006419288', 'title' => 'Article 1382 du Code civil']], 'nature' => 'CODE', 'date' => '2024-01-01'],
            ['titles' => [['id' => 'JORFTEXT000000001', 'title' => "Résultat pour $term"]], 'nature' => 'LOI', 'date' => '2023-05-02'],
        ]]); return;
    case '/dila/legifrance/lf-engine-app/consult/getArticle':
        echo json_encode(['article' => ['titre' => 'Article 1382', 'texte' => '<p>Tout fait quelconque de l&#039;homme&nbsp;<br/>qui cause un dommage&hellip;</p>']]); return;
    case '/cassation/judilibre/v1.0/search':
        echo json_encode(['total' => 1, 'results' => [
            ['id' => 'dec-1', 'number' => '21-12.345', 'jurisdiction' => 'cc', 'decision_date' => '2022-03-15', 'summary' => 'Responsabilité du fait des choses.'],
        ]]); return;
    case '/cassation/judilibre/v1.0/decision':
        if (($q['id'] ?? '') !== 'dec-1') { http_response_code(404); echo '{}'; return; }
        echo json_encode(['id' => 'dec-1', 'number' => '21-12.345', 'jurisdiction' => 'cc', 'decision_date' => '2022-03-15', 'text' => 'LA COUR DE CASSATION… (texte intégral).']); return;
}
http_response_code(404); echo '{"error":"not found"}';
