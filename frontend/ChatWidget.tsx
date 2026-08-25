'use client';

import { useEffect, useRef, useState } from 'react';
import Link from 'next/link';
import { Bot, MessageCircle, Send, ShieldCheck, X } from 'lucide-react';

interface ChatMessage {
  role: 'user' | 'model';
  text: string;
}

const GREETING: ChatMessage = {
  role: 'model',
  text: 'Guten Tag! Ich bin der digitale Assistent von Patrick Bogaards. Ich beantworte gerne erste allgemeine Fragen zu Versicherungen und den angebotenen Leistungen.',
};

const QUICK_SUGGESTIONS = [
  'Wie schützt eine Cyber-Versicherung?',
  'Was leistet eine D&O-Versicherung?',
  'Berufsunfähigkeit absichern',
  'Betriebliche Altersvorsorge',
];

const FALLBACK_ERROR_TEXT =
  'Entschuldigung, gerade gibt es ein technisches Problem. Bitte nutzen Sie das Kontaktformular oder schreiben Sie an beratung@bogaards.at.';

const EMAIL_PART_PATTERN = /([A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,})/gi;
const EXACT_EMAIL_PATTERN = /^[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}$/i;

function ChatMessageContent({ text }: { text: string }) {
  // Gemini nutzt gelegentlich Markdown für Fettdruck. Der Chat zeigt bewusst
  // keinen freien HTML-/Markdown-Inhalt an, deshalb entfernen wir nur die
  // Formatierungszeichen und verlinken E-Mail-Adressen sicher als mailto.
  // Einzelne Sternchen kommen ebenfalls aus Markdown-Listen oder -Betonungen.
  // In diesem kompakten Chat-Layout sollen sie nie als Zeichen erscheinen.
  const plainText = text.replaceAll('*', '');
  const parts = plainText.split(EMAIL_PART_PATTERN);

  return (
    <>
      {parts.map((part, index) =>
        EXACT_EMAIL_PATTERN.test(part) ? (
          <a className="chat-widget-message-link" href={`mailto:${part}`} key={`${part}-${index}`}>
            {part}
          </a>
        ) : (
          part
        ),
      )}
    </>
  );
}

export default function ChatWidget() {
  const [isOpen, setIsOpen] = useState(false);
  const [messages, setMessages] = useState<ChatMessage[]>([GREETING]);
  const [input, setInput] = useState('');
  const [isLoading, setIsLoading] = useState(false);
  const messagesEndRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    messagesEndRef.current?.scrollIntoView({ behavior: 'smooth' });
  }, [messages, isOpen]);

  useEffect(() => {
    // Auf dem Desktop reicht Platz, um den Chat direkt einladend zu öffnen.
    // Auf schmalen Bildschirmen würde das die ganze Startseite verdecken,
    // dort startet er zu und macht stattdessen nur über den Button auf sich aufmerksam.
    if (window.matchMedia('(min-width: 481px)').matches) {
      const frame = window.requestAnimationFrame(() => setIsOpen(true));
      return () => window.cancelAnimationFrame(frame);
    }
  }, []);

  async function sendMessage(text: string) {
    const trimmed = text.trim();
    if (trimmed === '' || isLoading) return;

    const history = messages;
    const nextMessages: ChatMessage[] = [...history, { role: 'user', text: trimmed }];
    setMessages(nextMessages);
    setInput('');
    setIsLoading(true);

    // Die statische Begrüßung ist nur UI-Zucker und kein echter Konversations-Turn;
    // Gemini erwartet, dass "contents" mit einer user-Nachricht beginnt.
    const apiHistory = history[0] === GREETING ? history.slice(1) : history;

    try {
      const response = await fetch('/chat.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          message: trimmed,
          history: apiHistory.map((m) => ({ role: m.role, text: m.text })),
        }),
      });

      if (!response.ok) {
        throw new Error('request_failed');
      }

      const data = await response.json();
      if (!data.reply) {
        throw new Error('empty_reply');
      }

      setMessages((prev) => [...prev, { role: 'model', text: data.reply }]);
    } catch {
      setMessages((prev) => [...prev, { role: 'model', text: FALLBACK_ERROR_TEXT }]);
    } finally {
      setIsLoading(false);
    }
  }

  function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    sendMessage(input);
  }

  return (
    <div className="chat-widget-root">
      {isOpen && (
        <section className="chat-widget-panel" aria-label="KI-Chat für Versicherungsfragen">
          <div className="chat-widget-header">
            <div className="chat-widget-title-group">
              <span className="chat-widget-avatar" aria-hidden="true"><Bot size={20} /></span>
              <span>
                <strong>Versicherungs-Concierge</strong>
                <small>Erste Orientierung – persönlich, klar, unverbindlich</small>
              </span>
            </div>
            <button
              type="button"
              className="chat-widget-close"
              onClick={() => setIsOpen(false)}
              aria-label="Chat schließen"
            >
              <X size={20} />
            </button>
          </div>

          <div className="chat-widget-messages">
            {messages.map((m, i) => (
              <div
                key={i}
                className={`chat-widget-bubble ${m.role === 'user' ? 'chat-widget-bubble-user' : 'chat-widget-bubble-model'}`}
              >
                <ChatMessageContent text={m.text} />
              </div>
            ))}
            {isLoading && (
              <div className="chat-widget-bubble chat-widget-bubble-model chat-widget-typing">
                <span></span>
                <span></span>
                <span></span>
              </div>
            )}
            {messages.length === 1 && (
              <div className="chat-widget-suggestions" aria-label="Häufige Themen">
                {QUICK_SUGGESTIONS.map((s) => (
                  <button
                    key={s}
                    type="button"
                    className="chat-widget-suggestion"
                    onClick={() => sendMessage(s)}
                  >
                    {s}
                  </button>
                ))}
              </div>
            )}
            <div ref={messagesEndRef} />
          </div>

          <form className="chat-widget-input-row" onSubmit={handleSubmit}>
            <input
              type="text"
              value={input}
              onChange={(e) => setInput(e.target.value)}
              placeholder="Ihre Frage..."
              className="chat-widget-input"
              maxLength={1500}
              disabled={isLoading}
            />
            <button
              type="submit"
              className="chat-widget-send"
              disabled={isLoading || input.trim() === ''}
              aria-label="Senden"
            >
              <Send size={18} />
            </button>
          </form>

          <div className="chat-widget-footer">
            <div className="chat-widget-disclaimer">
              <ShieldCheck size={13} aria-hidden="true" />
              <span>Keine verbindliche Beratung. Bitte keine sensiblen Daten eingeben.</span>
            </div>
            <div className="chat-widget-footer-links">
              <Link href="/kontakt">Persönlich beraten lassen</Link>
              <span aria-hidden="true">·</span>
              <Link href="/datenschutz">Datenschutz</Link>
            </div>
          </div>
        </section>
      )}

      <button
        type="button"
        className={`chat-widget-toggle${isOpen ? '' : ' chat-widget-toggle-pulse'}`}
        onClick={() => setIsOpen((v) => !v)}
        aria-label={isOpen ? 'Chat schließen' : 'Chat öffnen'}
      >
        {isOpen ? <X size={26} /> : <MessageCircle size={26} />}
      </button>
    </div>
  );
}

