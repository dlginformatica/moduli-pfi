# pfi/esecutore

Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.

Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva.

Unico canale fra le pagine del portale e le operazioni che richiedono root (release 9.0, blocco M2b; D-163; proposta 49 § 3.10). Lo usano il backup e il ripristino (M7) e il pannello dei moduli (M7b). Richiede `pfi/contratti` ^1.0 e l'estensione POSIX di PHP; il servizio registra con `pfi/registro` se installato.

## Funzionamento

1. La pagina, come utente del server web, accoda una richiesta con `Coda::richiedi($operazione, $parametri, $richiedente, $correlazione)` e ne riceve l'identificativo.
2. Il servizio `pfi-esecutore`, avviato da systemd come root alla comparsa di una richiesta, la prende, la controlla con il catalogo ed esegue il comando dichiarato.
3. La pagina legge l'esito con `Coda::esito($id)` o `Coda::attendi($id, $secondi)`: `in coda`, `in corso`, `eseguita`, `non riuscita` (con codice e uscita), `rifiutata` (con il motivo), `interrotta`.

## Catalogo delle operazioni

File JSON di proprietà di root, non scrivibile da altri (predefinito `/etc/pfi/esecutore.json`):

```json
{"operazioni": {
  "ripristino-prova": {
    "descrizione": "Prova a vuoto del ripristino",
    "comando": ["/usr/local/sbin/pfi-ripristino", "{cartella}", "{?conferma:--conferma}"],
    "parametri": {
      "cartella": {"tipo": "percorso", "radice": "/var/backups/pfi"},
      "conferma": {"tipo": "booleano"}
    },
    "tempomassimo": 3600
  }
}}
```

| Tipo di parametro | Regole |
|---|---|
| `testo` | `modello` (espressione regolare) obbligatorio; al massimo 1024 caratteri, senza carattere nullo |
| `intero` | `min`, `max` facoltativi |
| `scelta` | `valori` ammessi |
| `booleano` | vero o falso, falso se manca; nel comando `{?nome:testo}` |
| `percorso` | `radice` obbligatoria; il percorso reale (collegamenti risolti) deve stare sotto la radice |

Nel comando un elemento `{nome}` è sostituito per intero dal valore; ogni altro elemento è letterale. Il comando si esegue senza shell, con `PATH` ridotto, `LANG=C.UTF-8`, la variabile `PFI_CORRELAZIONE`, cartella `/`, ingresso vuoto, uscita (standard ed errori) limitata a `uscitamassima` byte e tempo massimo `tempomassimo` secondi (codice 124 se superato).

## Interfaccia pubblica

| Elemento | Scopo |
|---|---|
| `Coda($base)` | `prepara()`, `richiedi()`, `esito()`, `attendi()`; `valido($id)` |
| `Catalogo` | `da_file($file)`, `da_json()`, `operazione($nome)`, `operazioni()` |
| `Operazione` | `controlla($parametri)`, `argomenti($valori)` |
| `Esecutore($coda, $catalogo, $registro, $scadenza)` | `elabora()`, `pulisci($giorni)` |
| `Salute\Coda` | prova di salute `esecutore.coda`: richieste in attesa da oltre 5 minuti indicano il servizio fermo |
| `bin/pfi-esecutore` | programma per systemd: `--coda`, `--catalogo`, `--moduli`, `--registro`, `--pulisci GIORNI` |

## Installazione (kit, blocco M7)

Cartella `/var/spool/pfi-esecutore` con `richieste` scrivibile dal gruppo del server web, `in-corso` ed `esiti` scritte da root e leggibili dal gruppo; unità systemd `.path` sulla cartella `richieste` e `.service` che esegue `pfi-esecutore`; catalogo in `/etc/pfi/esecutore.json`.

## Limiti

- L'uscita dei comandi torna al portale: i comandi del catalogo non devono stampare segreti.
- Il servizio esegue una richiesta alla volta: un'operazione lunga ritarda le successive.
- Chi può scrivere nella cartella `richieste` può chiedere qualunque operazione del catalogo: l'autorizzazione dell'utente (capacità di Moodle) si controlla nella pagina prima di accodare.
