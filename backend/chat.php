<?php
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'method_not_allowed']);
    exit();
}

$config_file = __DIR__ . '/chat-config.php';
if (!file_exists($config_file)) {
    http_response_code(503);
    echo json_encode(['error' => 'not_configured']);
    exit();
}
$config = require $config_file;

// Herkunfts-Check: kostenpflichtige API, daher strenger als beim Kontaktformular.
// Ohne Origin/Referer dürfen normale Browserbesucher weiterhin durch; bei vorhandenen
// Headern werden ausschließlich die echte Domain und lokale Entwicklung akzeptiert.
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$referer = $_SERVER['HTTP_REFERER'] ?? '';

function allowed_request_source($value) {
    if ($value === '') {
        return true;
    }

    $host = parse_url($value, PHP_URL_HOST);
    if (!is_string($host)) {
        return false;
    }

    $host = strtolower($host);
    return $host === 'bogaards.at' || $host === 'www.bogaards.at' ||
        $host === 'localhost' || $host === '127.0.0.1';
}

if (!allowed_request_source($origin) || !allowed_request_source($referer)) {
    http_response_code(403);
    echo json_encode(['error' => 'forbidden']);
    exit();
}

$log_dir = __DIR__ . '/logs';
if (!is_dir($log_dir)) {
    @mkdir($log_dir, 0755, true);
}

// Datei-basiertes Rate-Limiting: pro IP + globales Tageslimit als Kostenbremse.
function rate_limit_check($log_dir) {
    $state_file = $log_dir . '/chat_rate.json';
    $fp = fopen($state_file, 'c+');
    if (!$fp) {
        return true; // Kann nicht geprüft werden, nicht blockieren.
    }
    flock($fp, LOCK_EX);
    $raw = stream_get_contents($fp);
    $state = json_decode($raw, true);
    if (!is_array($state)) {
        $state = [];
    }

    $now = time();
    $today = date('Y-m-d', $now);
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $ip_hash = substr(hash('sha256', $ip), 0, 16);

    // Alte IP-Einträge (älter als 10 Minuten) aufräumen.
    if (!isset($state['ips']) || !is_array($state['ips'])) {
        $state['ips'] = [];
    }
    foreach ($state['ips'] as $key => $entry) {
        if ($now - ($entry['first'] ?? 0) > 600) {
            unset($state['ips'][$key]);
        }
    }

    if (!isset($state['day']) || $state['day'] !== $today) {
        $state['day'] = $today;
        $state['day_count'] = 0;
    }

    $allowed = true;

    // Globales Tageslimit (Kostenschutz).
    if ($state['day_count'] >= 300) {
        $allowed = false;
    }

    // Pro-IP-Limit: max. 15 Anfragen pro 10 Minuten.
    $entry = $state['ips'][$ip_hash] ?? ['first' => $now, 'count' => 0];
    if ($entry['count'] >= 15) {
        $allowed = false;
    }

    if ($allowed) {
        $entry['count'] += 1;
        $state['ips'][$ip_hash] = $entry;
        $state['day_count'] += 1;
    }

    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($state));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    return $allowed;
}

if (!rate_limit_check($log_dir)) {
    http_response_code(429);
    echo json_encode(['error' => 'rate_limited']);
    exit();
}

$raw_body = file_get_contents('php://input');
$input = json_decode($raw_body, true);
if (!is_array($input) || !isset($input['message']) || !is_string($input['message'])) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid_request']);
    exit();
}

$message = trim($input['message']);
if ($message === '' || mb_strlen($message) > 1500) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid_message']);
    exit();
}

// Bisherige Konversation vom Client übernehmen, aber streng begrenzen/validieren.
$history = [];
if (isset($input['history']) && is_array($input['history'])) {
    $raw_history = array_slice($input['history'], -12);
    foreach ($raw_history as $turn) {
        if (!is_array($turn) || !isset($turn['role'], $turn['text'])) {
            continue;
        }
        $role = $turn['role'] === 'model' ? 'model' : 'user';
        $text = mb_substr(trim((string) $turn['text']), 0, 1500);
        if ($text === '') {
            continue;
        }
        $history[] = ['role' => $role, 'parts' => [['text' => $text]]];
    }
}

$system_prompt = <<<PROMPT
Du bist Clara, die digitale Versicherungsassistenz auf der Website von Patrick Bogaards, einem unabhängigen Versicherungsberater in Wien (bogaards.at). Antworte auf Deutsch (außer der Besucher schreibt in einer anderen Sprache, dann in dieser Sprache), freundlich, kompetent und in kurzen, gut lesbaren Absätzen.

Deine Aufgaben:
- Allgemeine Fragen zu Versicherungsthemen verständlich beantworten.
- Fragen zu Patrick Bogaards' Leistungsbereichen beantworten: Cyber-Risiken, D&O-Versicherung (Manager-Haftpflicht), Betriebliche Versicherungen, Betriebliche Altersvorsorge, Personenversicherungen (Kranken-, Berufsunfähigkeits-, Unfall-, Risikolebensversicherung), Bau-Projektversicherung.
- Auch allgemeine Fragen zu weiteren gängigen Versicherungen wie Haushalts-, Eigenheim- oder Kfz-Versicherung verständlich beantworten. Mache transparent, dass die konkrete Absicherung erst nach einer individuellen Prüfung beurteilt werden kann.
- Besucher bei konkretem Interesse aktiv zur Kontaktaufnahme ermutigen: Kontaktformular unter /kontakt oder E-Mail an beratung@bogaards.at, für ein persönliches, unverbindliches Beratungsgespräch.

Wichtige Grenzen:
- Du gibst KEINE konkreten Prämien, Vertragsbedingungen einzelner Versicherer oder rechtsverbindliche Beratung. Für individuelle Angebote und verbindliche Auskünfte verweist du auf ein persönliches Gespräch mit Patrick Bogaards.
- Erfinde keine Fakten über bestimmte Versicherer oder Tarife.
- Antworte hilfreich und etwas ausführlicher: in der Regel 5-8 Sätze oder, wenn passend, mit einer kurzen Aufzählung. Beginne mit einer klaren Einordnung, erkläre anschließend den praktischen Nutzen und nenne 2-4 typische Risiken oder Leistungsbausteine. Verwende verständliche, kundenorientierte Beispiele statt Fachjargon.
- Wenn ein Besucher nach einer Versicherungsart fragt, erkläre für wen sie allgemein interessant sein kann und welche finanziellen oder organisatorischen Folgen sie im Ernstfall abfedern kann. Wecke Interesse durch konkrete, sachliche Mehrwerte wie Planbarkeit, Schutz des Privatvermögens, Absicherung des Betriebs oder Entlastung im Schadenfall.
- Schließe bei erkennbarem Interesse mit einer freundlichen, unverbindlichen Einladung zum persönlichen Gespräch ab. Weise darauf hin, dass Patrick die individuelle Situation prüfen und ein passendes Konzept erarbeiten kann. Formuliere niemals drängend, manipulierend oder mit künstlicher Verknappung.
- Wenn ein Besucher ausdrücklich ein Angebot, eine Angebotsprüfung oder eine Beratung zu einer Versicherungsart wünscht, liefere zusätzlich eine kurze Überschrift wie "Für eine erste Angebotsprüfung hilfreich:" und eine passende Checkliste. Bitte um folgende allgemeinen Eckdaten:
  - Cyber-Risiken: Branche, Mitarbeiterzahl, Umsatzgrößenordnung, gespeicherte Datenarten, IT-/Cloud-Nutzung und bekannte Vorschäden.
  - D&O: Rechtsform, Branche, Umsatzgrößenordnung, Zahl der Geschäftsführer/Vorstände, gewünschte Versicherungssumme und bekannte Vorschäden.
  - Betriebliche Versicherungen: Branche, Standorte, Mitarbeiterzahl, Werte von Betriebseinrichtung/Waren, Fuhrpark oder Maschinen sowie bestehende Absicherungen.
  - Betriebliche Altersvorsorge: Mitarbeiterzahl, Ziel der Lösung, gewünschter Startzeitpunkt und ob bereits ein Modell besteht.
  - Personenversicherungen: Altersgruppe, Beruf bzw. Beschäftigung, gewünschte Absicherung, grobe familiäre Situation und bestehende Vorsorge. Frage im Chat nie nach Gesundheitsdaten.
  - Bau-Projektversicherung: Art des Bauvorhabens, Rolle im Projekt, Bauort, geplante Baukosten, Bauzeit und beteiligte Gewerke.
  - Haushalts- oder Eigenheimversicherung: Wohnform (Miete/Eigentum), Wohnungs- bzw. Hausart, Wohnfläche, grobe Postleitzahl, gewünschter Schutz und besondere Wertgegenstände. Frage nicht nach vollständiger Adresse oder detaillierten Inventarlisten im Chat.
  - Kfz-Versicherung: Fahrzeugart, Marke/Modell, Baujahr bzw. Erstzulassung, Leistung des Verbrennungsmotors, bei Hybridfahrzeugen zusätzlich Leistung des Elektromotors, CO₂-Ausstoß, gewünschter Beginn, jährliche Kilometer, Nutzungsart und gewünschter Schutz (Haftpflicht, Teilkasko oder Vollkasko). Frage nicht nach Kennzeichen, Führerschein-, Bank- oder Schadenunterlagen im Chat.
- Erkläre bei jeder Checkliste, dass eine erste Nachricht an beratung@bogaards.at oder über /kontakt genügt. Weise ausdrücklich darauf hin, dass Gesundheitsdaten, Vertragsnummern, Schadenunterlagen, Ausweisdaten und Bankdaten nicht im Chat und nicht unverschlüsselt per E-Mail gesendet werden sollen; dafür soll der Besucher zunächst persönlich Kontakt aufnehmen.
- Verwende reinen Text ohne Markdown-Syntax: insbesondere keine Sternchen für Fettdruck. Wenn du auf die Kontaktadresse verweist, schreibe sie exakt als beratung@bogaards.at.
- Frage niemals nach Gesundheitsdaten, Vertragsnummern, Schadendetails, Ausweisdaten, Bankdaten oder anderen sensiblen personenbezogenen Daten. Falls solche Angaben freiwillig genannt werden, fordere keine weiteren Details an und verweise auf die persönliche Kontaktaufnahme.
- Behandle Anfragen zu konkreten Schäden, Kündigungen, Leistungsfällen oder individuellen Verträgen als Anlass für einen persönlichen Kontakt. Nenne keine Fristen oder rechtlichen Bewertungen, wenn sie nicht eindeutig aus den Angaben hervorgehen.
PROMPT;

$contents = $history;
$contents[] = ['role' => 'user', 'parts' => [['text' => $message]]];

$payload = [
    'system_instruction' => ['parts' => [['text' => $system_prompt]]],
    'contents' => $contents,
    'generationConfig' => [
        'temperature' => 0.6,
        // Ausreichend Raum für vollständige, ausführlichere Antworten.
        'maxOutputTokens' => 2048,
        // Ein kleiner Denkrahmen lässt mehr Budget für den sichtbaren Text.
        'thinkingConfig' => ['thinkingBudget' => 256],
    ],
];

$model = $config['gemini_model'] ?? 'gemini-2.5-flash';
$api_key = $config['gemini_api_key'] ?? '';

$url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent";

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'x-goog-api-key: ' . $api_key,
    ],
    CURLOPT_POSTFIELDS => json_encode($payload),
    CURLOPT_TIMEOUT => 25,
]);
$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curl_error = curl_error($ch);
curl_close($ch);

if ($response === false) {
    error_log('Gemini API cURL error: ' . $curl_error);
    http_response_code(502);
    echo json_encode(['error' => 'upstream_unreachable']);
    exit();
}

$data = json_decode($response, true);

if ($http_code !== 200) {
    error_log('Gemini API error (' . $http_code . '): ' . $response);
    http_response_code(502);
    echo json_encode(['error' => 'upstream_error']);
    exit();
}

$candidate = $data['candidates'][0] ?? [];
$reply_parts = $candidate['content']['parts'] ?? [];
$reply = '';
foreach ($reply_parts as $part) {
    if (isset($part['text']) && is_string($part['text'])) {
        $reply .= $part['text'];
    }
}

if ($reply === '') {
    $finish_reason = $candidate['finishReason'] ?? 'unknown';
    error_log('Gemini API: no reply text, finishReason=' . $finish_reason);
    http_response_code(502);
    echo json_encode(['error' => 'empty_reply']);
    exit();
}

// Falls der Anbieter trotz des größeren Budgets wegen einer Längenbegrenzung
// stoppt, ist für den Besucher klar, dass eine persönliche Einordnung sinnvoll ist.
if (($candidate['finishReason'] ?? '') === 'MAX_TOKENS') {
    $reply .= "\n\nFür eine vollständige Einordnung besprechen wir Ihr Anliegen gerne persönlich: beratung@bogaards.at";
}

echo json_encode(['reply' => $reply]);
