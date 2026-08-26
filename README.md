# AI Chatbot Homepage

Eigenständiger Gemini-Chat für eine Next.js-Homepage mit PHP-Backend. Dieses Repository enthält absichtlich nur die für den Chat benötigten Dateien und niemals einen API-Schlüssel.

## Enthalten

- `frontend/ChatWidget.tsx` – React-/Next.js-Komponente
- `frontend/chat-widget.css` – Navy-Gold-Design des Widgets
- `chat-concierge-avatar.png` – menschlich wirkendes Avatarbild für den Chat
- `backend/chat.php` – serverseitiger Gemini-Endpunkt mit Rate-Limit
- `backend/chat-config.example.php` – Vorlage für die private Gemini-Konfiguration
- `backend/.htaccess` und `backend/logs/.htaccess` – Schutz vor direktem Zugriff auf Konfiguration und Rate-Limit-Datei

## Einbau

1. `frontend/ChatWidget.tsx` nach `src/components/` und `chat-concierge-avatar.png` nach `public/images/` kopieren.
2. Die Regeln aus `chat-widget.css` in die globale CSS-Datei übernehmen. Die Datei verwendet die vorhandenen CSS-Variablen `--primary-color`, `--secondary-color`, `--accent-color`, `--accent-hover`, `--surface-color`, `--bg-color`, `--text-primary`, `--text-secondary` und `--text-light`.
3. `ChatWidget` in das Layout einbinden, zum Beispiel `<ChatWidget />`.
4. Die Dateien aus `backend/` in das Web-Stammverzeichnis eines PHP-fähigen Hostings kopieren.
5. Auf dem Server `chat-config.example.php` in `chat-config.php` kopieren und dort den Gemini-API-Key eintragen. Diese Datei darf nie in Git landen.

## Voraussetzungen

- Next.js/React mit `next/link`
- Paket `lucide-react`
- PHP mit cURL und Schreibrecht für `logs/`
- Gemini API-Key

Der Endpunkt erlaubt standardmäßig nur `bogaards.at`, `www.bogaards.at` sowie lokale Entwicklung. Bei einer anderen Domain muss die Funktion `allowed_request_source` in `backend/chat.php` angepasst werden.

## Sicherheit

- Der API-Key wird ausschließlich serverseitig verwendet.
- Konversationen werden nicht gespeichert; das Rate-Limit speichert nur einen gekürzten Hash der IP-Adresse.
- Die Chat-Oberfläche entfernt Markdown-Sternchen und wandelt E-Mail-Adressen in `mailto:`-Links um.
