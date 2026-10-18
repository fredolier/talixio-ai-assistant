<?php
// Downloads labelled cases from public datasets that someone else wrote and annotated, maps their PII span
// types to our levels (table below, fixed before any run), and writes a locked test split and a dev split.
// Usage: php fetch.php   -> data/dev.jsonl, data/test.jsonl (not committed: synthetic PII, third-party licences)

const API = 'https://datasets-server.huggingface.co/rows?config=default';
// Span type -> level. Anything not listed is business; a document with no span keeps its source's floor.
const SECRET = ['password', 'account_pin', 'pin', 'api_key', 'credit_card_security_code', 'cvv', 'http_cookie',
    'medical_record_number', 'biometric_identifier', 'health_plan_beneficiary_number', 'blood_type'];
const CONFIDENTIAL = ['iban', 'bban', 'account_number', 'bank_routing_number', 'credit_card_number', 'credit_debit_card',
    'ssn', 'passport_number', 'driver_license_number', 'certificate_license_number', 'date_of_birth',
    'race_ethnicity', 'religious_belief', 'political_view', 'sexuality'];
const ASKS = ['Summarise this document.', 'What are the key points here?', 'Résume ce document.', 'Draft a reply based on this.',
    'Extract the action items.', 'Translate this into French.', 'Is anything missing from this?', 'Check this for errors.'];

function rows(string $ds, string $split, int $offset, int $n = 100): array
{
    for ($try = 0; $try < 4; $try++) {
        $ch = curl_init(API . "&dataset=$ds&split=$split&offset=$offset&length=$n");
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60] + (getenv('CURL_CA_BUNDLE') ? [CURLOPT_CAINFO => getenv('CURL_CA_BUNDLE')] : []));
        $j = json_decode((string) curl_exec($ch), true);
        if (!empty($j['rows'])) return array_column($j['rows'], 'row');
        sleep(2 << $try);
    }
    fwrite(STDERR, "fetch failed: $ds $split $offset\n");
    return [];
}

function level(array $types, string $floor): string
{
    if (array_intersect($types, SECRET)) return 'secret';
    if (array_intersect($types, CONFIDENTIAL)) return 'confidential';
    return $types ? 'business' : $floor;
}

mt_srand(42);
$cases = [];
// Gretel synthetic_pii_finance_multilingual (Apache-2.0): finance documents in 7 languages.
foreach ([0, 700, 1400, 2100, 2800, 3500, 4200, 4900] as $o) foreach (rows('gretelai/synthetic_pii_finance_multilingual', 'test', $o) as $r) {
    $types = array_column(json_decode($r['pii_spans'], true) ?: [], 'label');
    $cases[] = ['id' => "g{$r['index']}", 'src' => 'gretel', 'space' => 'public', 'text' => ASKS[mt_rand(0, 7)],
        'attach' => $r['generated_text'], 'label' => level($types, 'business'), 'kind' => $r['document_type']];
}
// NVIDIA Nemotron-PII (CC BY 4.0): documents across 50+ domains, incl. health, HR and banking.
foreach ([0, 12000, 24000, 36000, 48000, 60000, 72000, 84000] as $o) foreach (rows('nvidia/Nemotron-PII', 'test', $o) as $r) {
    preg_match_all("/'label': '([a-z_]+)'/", $r['spans'], $m);
    $cases[] = ['id' => 'n' . substr($r['uid'], 0, 10), 'src' => 'nemotron', 'space' => 'public', 'text' => ASKS[mt_rand(0, 7)],
        'attach' => $r['text'], 'label' => level($m[1], 'business'), 'kind' => $r['domain']];
}
// Databricks Dolly 15k (CC BY-SA 3.0): questions written by people, nothing private -> public.
foreach ([0, 3000, 6000, 9000, 12000] as $o) foreach (rows('databricks/databricks-dolly-15k', 'train', $o) as $i => $r) {
    $cases[] = ['id' => 'd' . ($o + $i), 'src' => 'dolly', 'space' => 'public', 'text' => $r['instruction'],
        'attach' => $r['context'] ?: null, 'label' => 'public', 'kind' => $r['category']];
}
$cases = array_map(fn($c) => array_filter($c, fn($v) => $v !== null), $cases);
// Stratified 70/30 split by source and label, seeded; the test split is only read for the final scores.
$dev = $test = [];
$groups = [];
foreach ($cases as $c) $groups[$c['src'] . $c['label']][] = $c;
foreach ($groups as $g) {
    usort($g, fn($a, $b) => strcmp(md5('42' . $a['id']), md5('42' . $b['id'])));
    $k = (int) round(count($g) * 0.3);
    array_push($test, ...array_slice($g, 0, $k));
    array_push($dev, ...array_slice($g, $k));
}
@mkdir(__DIR__ . '/data');
foreach (['dev' => $dev, 'test' => $test] as $n => $set) file_put_contents(__DIR__ . "/data/$n.jsonl", implode("\n", array_map(fn($c) => json_encode($c, JSON_UNESCAPED_UNICODE), $set)) . "\n");
$count = fn($set) => array_count_values(array_column($set, 'label'));
printf("dev %d %s\ntest %d %s\n", count($dev), json_encode($count($dev)), count($test), json_encode($count($test)));
