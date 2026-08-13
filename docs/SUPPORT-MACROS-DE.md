# SnackCheck Support-Makros (DE)

Kurze Ops-Texte für First-Level. Keine Secrets einfügen.

## Lizenz 402 am Küchen-Tablet

Die Web-App bleibt kostenlos. Das Wand-Tablet braucht eine SNK2-Gerätelizenz mit `terminalDevices ≥ 1`.

1. Einstellungen → Lizenz → SNK2-Schlüssel einfügen → Anwenden  
2. Tablet registrieren (bei Multi-Standort Site wählen) → `snkterm_…` einmal kopieren  
3. Am Tablet: Server-URL + Token → Koppeln  

Wenn weiterhin „Lizenz erforderlich“: `terminalDevices` erweitern, erneut Anwenden (Überkapazität wird getrimmt), gestohlene Geräte widerrufen.

## Offline „Wartet auf Sync“

Pending ist ehrlich: der Tipp steht erst nach HTTP 2xx im Ledger.

1. WLAN / Server prüfen  
2. Sync erneut versuchen  
3. Bei abgelaufenem Unlock: dieselbe Person erneut entsperren — Warteschlange versucht es erneut  
4. „Verworfene Tipps“ nur bei echten Konflikten

## Export / Lohn-Abweichung

Payroll = centgenaue persönliche Zeilen (Hospitality und Gratis ausgeschlossen).

1. Periode muss **geschlossen** sein vor „An HR übergeben“  
2. Reconcile muss OK sein  
3. Bei Multi-Standort Site-Filter an Payroll/Hospitality  
4. Wiederöffnen nur mit Begründung ≥ 3 Zeichen (löscht „An HR“)

## Unlock-Sperre

3 falsche PIN/QR → Soft-Sperre ab **30 s**, dann **60 s → 5 Min → 15 Min** bei wiederholten Runden ohne erfolgreiches Unlock. Countdown auf dem Tablet abwarten. Erfolgreiches Unlock setzt die Eskalation zurück. PIN/QR unter Einstellungen → Entsperren neu setzen.
