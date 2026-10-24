<?php

declare(strict_types=1);

namespace Talixio;

/**
 * French law search through the PISTE APIs (piste.gouv.fr): Légifrance (codes, laws, decrees, JORF, case law)
 * and JudiLibre (court decisions of the Cour de cassation and the courts of appeal). The user creates a free
 * PISTE account, subscribes to the Légifrance and JudiLibre APIs, and puts the client id and secret in Settings.
 * OAuth2 client_credentials; the bearer token is cached in data/piste.json (private, mode 0600).
 */
final class Legifrance
{
    public function __construct(private App $app) {}

    public function configured(): bool { return $this->app->cfg->pisteClientId !== '' && $this->app->cfg->pisteClientSecret !== ''; }

    private function file(): string { return $this->app->cfg->dataDir . '/piste.json'; }

    private function saveToken(array $t): void
    {
        $tmp = $this->file() . '.tmp';
        file_put_contents($tmp, json_encode($t, JSON_UNESCAPED_SLASHES));
        chmod($tmp, 0600);
        rename($tmp, $this->file());
    }

    /** A valid bearer token (client_credentials), cached until it is about to expire. */
    private function accessToken(): string
    {
        if (!$this->configured()) throw new \RuntimeException('Légifrance is not set up: add your PISTE client id and secret in Settings → Connected accounts.');
        $t = json_decode((string)@file_get_contents($this->file()), true) ?: [];
        if (($t['access_token'] ?? '') !== '' && ($t['expires'] ?? 0) > time()) return $t['access_token'];
        $c = $this->app->cfg;
        [$code, $body] = Http::request('POST', $c->pisteTokenUrl, ['Content-Type: application/x-www-form-urlencoded'],
            http_build_query(['grant_type' => 'client_credentials', 'client_id' => $c->pisteClientId, 'client_secret' => $c->pisteClientSecret, 'scope' => 'openid']), 30);
        $d = json_decode($body, true) ?: [];
        if ($code >= 400 || empty($d['access_token'])) throw new \RuntimeException('PISTE sign-in failed: ' . ($d['error_description'] ?? $d['error'] ?? "HTTP $code") . '. Check the client id and secret in Settings.');
        $this->saveToken(['access_token' => $d['access_token'], 'expires' => time() + (int)($d['expires_in'] ?? 3600) - 60]);
        return $d['access_token'];
    }

    /** Fetch a token now, to check the keys work. Returns a short status line. */
    public function test(): string
    {
        @unlink($this->file()); // force a fresh token
        $this->accessToken();
        return 'Connected to PISTE. Make sure your account is subscribed to the Légifrance and JudiLibre APIs on piste.gouv.fr.';
    }

    private function api(string $method, string $url, ?array $body = null): array
    {
        [$code, $resp] = Http::request($method, $url, ['Authorization: Bearer ' . $this->accessToken(),
            'Accept: application/json', 'Content-Type: application/json'],
            $body !== null ? json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : ($method === 'GET' ? null : ''), 30);
        $d = json_decode($resp, true);
        if ($code === 401 || $code === 403) throw new \RuntimeException('PISTE refused the request (HTTP ' . $code . '): your account may not be subscribed to this API on piste.gouv.fr.');
        if ($code >= 400) throw new \RuntimeException('PISTE error ' . $code . ': ' . mb_substr(is_array($d) ? json_encode($d) : $resp, 0, 300));
        return is_array($d) ? $d : [];
    }

    private function legiUrl(string $path): string { return $this->app->cfg->pisteApiBase . '/dila/legifrance/lf-engine-app' . $path; }
    private function judiUrl(string $path): string { return $this->app->cfg->pisteApiBase . '/cassation/judilibre/v1.0' . $path; }

    public const FONDS = ['ALL', 'CODE_DATE', 'LODA_DATE', 'JORF', 'JURI', 'CETAT', 'CONSTIT', 'KALI', 'CIRC'];

    /** Search Légifrance. $fond: which body of law (ALL, CODE_DATE for codes, LODA_DATE for laws/decrees, JURI for case law…). */
    public function searchLegi(string $query, string $fond = 'ALL', int $limit = 10): string
    {
        $query = trim($query);
        if ($query === '') throw new \InvalidArgumentException('give something to search for');
        $fond = in_array($fond, self::FONDS, true) ? $fond : 'ALL';
        $payload = ['fond' => $fond, 'recherche' => [
            'champs' => [['typeChamp' => 'ALL', 'operateur' => 'ET',
                'criteres' => [['valeur' => $query, 'typeRecherche' => 'TOUS_LES_MOTS_DANS_UN_CHAMP', 'operateur' => 'ET']]]],
            'filtres' => [], 'pageNumber' => 1, 'pageSize' => max(1, min(20, $limit)),
            'operateur' => 'ET', 'sort' => 'PERTINENCE', 'typePagination' => 'DEFAUT']];
        $r = $this->api('POST', $this->legiUrl('/search'), $payload);
        $results = $r['results'] ?? [];
        if (!$results) return "No Légifrance result for \"$query\" (fond $fond).";
        $out = ["Légifrance — $query (" . ($r['totalResultNumber'] ?? count($results)) . ' results, fond ' . $fond . '):'];
        foreach (array_slice($results, 0, $limit) as $x) {
            $title = trim((string)($x['titles'][0]['title'] ?? $x['title'] ?? '(untitled)'));
            $id = (string)($x['titles'][0]['id'] ?? $x['id'] ?? '');
            $nature = (string)($x['nature'] ?? '');
            $date = (string)($x['date'] ?? '');
            $out[] = "- $title" . ($nature ? " [$nature]" : '') . ($date ? " ($date)" : '') . ($id ? " — id $id" : '');
        }
        $out[] = 'To read a text in full, call legifrance_get with its id (e.g. LEGIARTI…, LEGITEXT…, JURITEXT…).';
        return implode("\n", $out);
    }

    /** Read a Légifrance text in full by its id (LEGIARTI…, LEGITEXT…, JURITEXT…, CNILTEXT…). */
    public function getLegi(string $id): string
    {
        $id = trim(explode('_', $id)[0]);
        if (!preg_match('/^[A-Z]{4,}[0-9A-Z]+$/', $id)) throw new \InvalidArgumentException('give a Légifrance id, e.g. LEGIARTI000006419288');
        [$url, $body] = match (true) {
            str_starts_with($id, 'LEGIARTI'), str_starts_with($id, 'LEGISCTA') => [$this->legiUrl('/consult/getArticle'), ['id' => $id]],
            str_starts_with($id, 'LEGITEXT') => [$this->legiUrl('/consult/legiPart'), ['textId' => $id, 'date' => date('Y-m-d')]],
            str_starts_with($id, 'JURITEXT'), str_starts_with($id, 'CETATEXT') => [$this->legiUrl('/consult/juri'), ['textId' => $id]],
            str_starts_with($id, 'CNILTEXT') => [$this->legiUrl('/consult/cnil'), ['textId' => $id]],
            default => [$this->legiUrl('/consult/jorf'), ['textCid' => $id]],
        };
        $r = $this->api('POST', $url, $body);
        $article = $r['article'] ?? $r['text'] ?? $r;
        $title = (string)($article['titre'] ?? $article['title'] ?? $r['title'] ?? $id);
        $content = (string)($article['texte'] ?? $article['content'] ?? '');
        $content = trim(html_entity_decode(strip_tags(preg_replace('/<br\s*\/?>/i', "\n", $content)), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($content === '') return "Found $id but it has no readable body here. Open it on legifrance.gouv.fr.";
        return "$title ($id)\n\n" . mb_substr($content, 0, 6000);
    }

    /** Search JudiLibre case law. */
    public function searchJudi(string $query, int $limit = 10): string
    {
        $query = trim($query);
        if ($query === '') throw new \InvalidArgumentException('give something to search for');
        $r = $this->api('GET', $this->judiUrl('/search?' . http_build_query(['query' => $query, 'page_size' => max(1, min(20, $limit)), 'resolve_references' => 'true'])));
        $results = $r['results'] ?? [];
        if (!$results) return "No case law found for \"$query\".";
        $out = ['JudiLibre — ' . $query . ' (' . ($r['total'] ?? count($results)) . ' decisions):'];
        foreach (array_slice($results, 0, $limit) as $x) {
            $num = (string)($x['number'] ?? ($x['numbers'][0] ?? ''));
            $jur = (string)($x['jurisdiction'] ?? '');
            $date = (string)($x['decision_date'] ?? '');
            $sum = trim((string)($x['summary'] ?? ($x['themes'][0] ?? '')));
            $out[] = "- $jur $num" . ($date ? " ($date)" : '') . ($sum ? ' — ' . mb_substr($sum, 0, 180) : '') . ' — id ' . (string)($x['id'] ?? '');
        }
        $out[] = 'To read a decision, call judilibre_get with its id.';
        return implode("\n", $out);
    }

    /** Read a JudiLibre decision in full by its id. */
    public function getJudi(string $id): string
    {
        $id = trim($id);
        if ($id === '') throw new \InvalidArgumentException('give a JudiLibre decision id');
        $r = $this->api('GET', $this->judiUrl('/decision?' . http_build_query(['id' => $id, 'resolve_references' => 'true'])));
        if (!$r) return "No decision with id $id.";
        $head = trim(((string)($r['jurisdiction'] ?? '')) . ' ' . ((string)($r['number'] ?? '')) . ' ' . ((string)($r['decision_date'] ?? '')));
        $text = trim((string)($r['text'] ?? ''));
        return ($head ?: $id) . "\n\n" . ($text !== '' ? mb_substr($text, 0, 6000) : '(no text available)');
    }

    public function register(Tools $t): void
    {
        if (!$this->configured()) return;
        $t->register('legifrance_search',
            "Search French law on Légifrance: codes, laws, decrees, the Journal officiel (JORF) and case law. fond picks the body of law: ALL (default), CODE_DATE (codes like the Code civil), LODA_DATE (laws/ordonnances/décrets/arrêtés), JORF, JURI (judicial case law), CETAT (Conseil d'État), CONSTIT (Conseil constitutionnel), KALI (collective agreements). Returns a list with ids; read one with legifrance_get.",
            ['query' => ['type' => 'string'], 'fond' => ['type' => 'string', 'enum' => self::FONDS], 'limit' => ['type' => 'integer']], ['query'],
            fn(array $a) => $this->searchLegi((string)$a['query'], (string)($a['fond'] ?? 'ALL'), (int)($a['limit'] ?? 10)));
        $t->register('legifrance_get', "Read a Légifrance text in full by its id (e.g. LEGIARTI… for an article, LEGITEXT… for a consolidated text, JURITEXT… for a judgment).",
            ['id' => ['type' => 'string']], ['id'], fn(array $a) => $this->getLegi((string)$a['id']));
        $t->register('judilibre_search', "Search French court decisions (case law) on JudiLibre: Cour de cassation and courts of appeal. Returns decisions with ids; read one with judilibre_get.",
            ['query' => ['type' => 'string'], 'limit' => ['type' => 'integer']], ['query'],
            fn(array $a) => $this->searchJudi((string)$a['query'], (int)($a['limit'] ?? 10)));
        $t->register('judilibre_get', "Read a French court decision in full by its JudiLibre id.",
            ['id' => ['type' => 'string']], ['id'], fn(array $a) => $this->getJudi((string)$a['id']));
    }
}
