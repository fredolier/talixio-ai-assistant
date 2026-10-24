<?php

declare(strict_types=1);

namespace Talixio;

/**
 * EU law from EUR-Lex / the Publications Office CELLAR, which are public and need no key:
 * read a document by its CELEX number (regulations, directives, decisions, treaties, CJEU judgments),
 * and a best-effort search by words in the title through the CELLAR SPARQL endpoint.
 * Content is reused under Commission Decision 2011/833/EU (attribution kept, meaning not distorted).
 */
final class Eurlex
{
    public function __construct(private App $app) {}

    private const LANGS = ['EN' => 'ENG', 'FR' => 'FRA', 'DE' => 'DEU', 'ES' => 'SPA', 'IT' => 'ITA', 'NL' => 'NLD', 'PT' => 'POR', 'PL' => 'POL'];

    /** Search EU legislation by words in the title (CELLAR SPARQL). Best-effort: for a precise lookup, use a CELEX number with eurlex_get. */
    public function search(string $query, int $limit = 10, string $lang = 'EN'): string
    {
        $query = trim($query);
        if ($query === '') throw new \InvalidArgumentException('give something to search for');
        $iso = self::LANGS[strtoupper($lang)] ?? 'ENG';
        $limit = max(1, min(20, $limit));
        $needle = str_replace('"', '', mb_strtolower($query));
        $sparql = 'PREFIX cdm: <http://publications.europa.eu/ontology/cdm#>'
            . ' SELECT DISTINCT ?celex ?title (STR(?date) AS ?d) WHERE {'
            . ' ?work cdm:resource_legal_id_celex ?celex . OPTIONAL { ?work cdm:work_date_document ?date . }'
            . ' ?exp cdm:expression_belongs_to_work ?work ;'
            . ' cdm:expression_uses_language <http://publications.europa.eu/resource/authority/language/' . $iso . '> ;'
            . ' cdm:expression_title ?title .'
            . ' FILTER(CONTAINS(LCASE(STR(?title)), "' . addslashes($needle) . '")) }'
            . ' ORDER BY DESC(?d) LIMIT ' . $limit;
        $rows = $this->sparql($sparql);
        if (!$rows) return "No EUR-Lex title matched \"$query\". If you know the CELEX number (e.g. 32016R0679 for the GDPR), use eurlex_get.";
        $out = ["EUR-Lex — \"$query\" (by title, $iso):"];
        foreach ($rows as $b) {
            $celex = (string)($b['celex']['value'] ?? '');
            $title = trim((string)($b['title']['value'] ?? ''));
            $date = (string)($b['d']['value'] ?? '');
            $out[] = "- $celex" . ($date ? ' (' . substr($date, 0, 10) . ')' : '') . ' — ' . mb_substr($title, 0, 200);
        }
        $out[] = 'Read one in full with eurlex_get and its CELEX number.';
        return implode("\n", $out);
    }

    /** Read an EU document in full by CELEX (e.g. 32016R0679 = GDPR, 62014CJ0362 = a CJEU judgment). */
    public function get(string $celex, string $lang = 'EN'): string
    {
        $celex = strtoupper(trim(preg_replace('/\s+/', '', $celex)));
        if (!preg_match('/^[0-9][0-9A-Z()]+$/', $celex)) throw new \InvalidArgumentException('give a CELEX number, e.g. 32016R0679');
        $iso2 = strtolower(substr(strtoupper($lang), 0, 2));
        if (!isset(self::LANGS[strtoupper($iso2)])) $iso2 = 'en';
        $url = $this->app->cfg->eurlexContentBase . '/resource/celex/' . rawurlencode($celex);
        [$code, $body] = Http::request('GET', $url, ['Accept: text/html; charset=utf-8', 'Accept-Language: ' . $iso2], null, 55);
        if ($code === 406 || $code === 404 || trim($body) === '') { // no such language/expression: fall back to English
            [$code, $body] = Http::request('GET', $url, ['Accept: text/html; charset=utf-8', 'Accept-Language: en'], null, 55);
        }
        if ($code >= 400 || trim($body) === '') return "No EUR-Lex document for CELEX $celex (HTTP $code). Check the number on eur-lex.europa.eu.";
        $text = trim(html_entity_decode(strip_tags(preg_replace(['#<(script|style)[^>]*>.*?</\1>#is', '#<br\s*/?>#i', '#</(p|div|li|tr|h\d)>#i'], ['', "\n", "\n"], $body)), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $text = preg_replace("/\n{3,}/", "\n\n", preg_replace('/[ \t]+/', ' ', $text));
        return "EUR-Lex CELEX $celex\n\n" . mb_substr(trim($text), 0, 7000);
    }

    /** POST a read-only SPARQL SELECT to CELLAR and return the result bindings. */
    private function sparql(string $query): array
    {
        [$code, $body] = Http::request('POST', $this->app->cfg->eurlexSparqlUrl,
            ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/sparql-results+json'],
            http_build_query(['query' => $query, 'format' => 'application/sparql-results+json']), 55);
        if ($code >= 400) throw new \RuntimeException('EUR-Lex SPARQL error ' . $code);
        $d = json_decode($body, true);
        return $d['results']['bindings'] ?? [];
    }

    public function register(Tools $t): void
    {
        $t->register('eurlex_search', "Search EU law on EUR-Lex by words in the title (regulations, directives, decisions, treaties). Best-effort title search; for a precise text use eurlex_get with a CELEX number. lang: EN (default), FR, DE…",
            ['query' => ['type' => 'string'], 'lang' => ['type' => 'string'], 'limit' => ['type' => 'integer']], ['query'],
            fn(array $a) => $this->search((string)$a['query'], (int)($a['limit'] ?? 10), (string)($a['lang'] ?? 'EN')));
        $t->register('eurlex_get', "Read an EU legal text in full from EUR-Lex by its CELEX number (e.g. 32016R0679 for the GDPR, 32024R1689 for the AI Act, 62014CJ0362 for a Court of Justice judgment). lang: EN (default), FR, DE…",
            ['celex' => ['type' => 'string'], 'lang' => ['type' => 'string']], ['celex'],
            fn(array $a) => $this->get((string)$a['celex'], (string)($a['lang'] ?? 'EN')));
    }
}
