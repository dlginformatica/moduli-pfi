# Storia di pfi/esecutore

Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.

## 1.0.0 - 14 novembre 2026

Prima versione (release 9.0 del portale, blocco M2b, D-163, proposta 49 § 3.10): `Coda` delle richieste fra le pagine e il servizio di sistema con scrittura atomica ed esiti leggibili dal portale; `Catalogo` chiuso delle operazioni da un file di root non scrivibile da altri; `Operazione` con argomenti in elenco, parametri tipizzati (testo con modello, intero, scelta, booleano, percorso sotto una radice) e nessun valore incollato dentro altri argomenti; `Esecutore` che controlla le richieste (file regolare, dimensione, identificativo, scadenza), esegue senza shell con ambiente ridotto e tempo massimo, chiude le richieste interrotte, tiene vuota la cartella delle richieste (i file non validi passano fra quelli in corso e la pulizia li elimina, perché l'unità `.path` di systemd riavvia il servizio finché la cartella non è vuota) e registra ogni operazione con la correlazione; programma `bin/pfi-esecutore` per systemd; prova di salute `esecutore.coda`.
