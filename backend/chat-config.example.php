<?php
// Vorlage für die Chat-Konfiguration.
//
// 1. Diese Datei zu "chat-config.php" kopieren (im selben Ordner).
// 2. Echten Gemini API-Key eintragen (console: https://aistudio.google.com/apikey).
// 3. chat-config.php NICHT einchecken (steht in .gitignore) und nur direkt per FTP
//    auf den Server hochladen, niemals ins Git-Repo committen.
return [
    'gemini_api_key' => 'DEIN_GEMINI_API_KEY_HIER_EINTRAGEN',
    'gemini_model' => 'gemini-3.6-flash',
];

