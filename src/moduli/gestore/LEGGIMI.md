# pfi/gestore

Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.

Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva.

Gestione dei moduli universali installati (release 9.0, blocco M7b; D-162; proposta 49 § 3.9), a sua volta modulo universale. Richiede `pfi/contratti` e `pfi/diagnostica` ^1.0 e l'estensione sodium. Nel portale il pannello è l'adattatore `tool_pfimoduli`; le operazioni che scrivono le esegue il servizio `pfi-esecutore` con `pfi-moduli`.

## Struttura sul server

```
/usr/local/lib/pfi/moduli/
  contratti/1.0.0/   contratti/attuale -> 1.0.0
  registro/1.0.0/    registro/1.1.0/   registro/attuale -> 1.1.0
  .storia.json
```

Il caricatore di `pfi/contratti` registra le classi dalle cartelle `attuale`; un aggiornamento installa la versione nuova accanto alle altre e sposta il collegamento.

## Aggiornamento di un singolo modulo

1. Verifica del pacchetto: firma Ed25519 dell'impronta SHA-256 con la chiave pubblica del server (`/etc/pfi/moduli-firma.pub`); manifesto uguale a nome e versione firmati; nessun percorso fuori dalla cartella e nessun collegamento simbolico.
2. Dipendenze nei due versi: il modulo nuovo trova le versioni che richiede; i moduli che dipendono da lui restano soddisfatti.
3. Installazione nella cartella `<nome>/<versione>` e spostamento atomico di `attuale`.
4. Prove di salute del modulo in un processo PHP nuovo (`pfi-salute-modulo`), che carica il codice appena installato.
5. Prova guasta: si rimette in uso la versione precedente e l'operazione risulta annullata nella storia.

Il ritorno indietro (`indietro`) rimette in uso la versione precedente o una versione presente indicata, con lo stesso controllo delle dipendenze. Il gestore si aggiorna con la stessa procedura: il processo in corso termina con il codice vecchio, il successivo usa quello nuovo (aggiornamento in due tempi).

## Interfaccia pubblica

| Elemento | Scopo |
|---|---|
| `Pacchetto::genera_chiavi()`, `crea($cartella, $destinazione, $privata)`, `verifica($archivio, $pubblica, $temporanea)` | coppia di chiavi, pacchetto firmato, verifica |
| `Gestore($base, $pubblica, $registro, $salute)` | `installati()`, `versioni()`, `attuale()`, `elenco()`, `scostamenti($collaudata)`, `installa()`, `indietro()`, `rimuovi()`, `leggi_storia()` |
| `bin/pfi-moduli` | `elenco`, `installa`, `indietro`, `rimuovi`, per il catalogo di `pfi-esecutore` |
| `Salute\Dipendenze` | prova di salute `gestore.dipendenze` |

## Limiti

- La chiave privata per firmare i pacchetti la conserva chi pubblica i moduli, fuori dal portale; con la chiave pubblica sbagliata nessun modulo si installa.
- `scostamenti()` confronta con la combinazione collaudata che gli si passa: la tabella delle versioni per release è nel kit (blocco M9).
- Le prove di salute controllano il modulo, non l'uso che ne fanno i componenti Moodle: dopo un aggiornamento resta consigliato `pfi-verifica`.
