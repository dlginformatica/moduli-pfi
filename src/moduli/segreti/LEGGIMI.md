# pfi/segreti

Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.

Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva.

Deposito cifrato dei segreti del portale e di qualunque applicazione PHP: credenziali di posta e delle API, chiavi dei fornitori, chiave del backup, chiave di firma dei pacchetti dei moduli (release 9.0, blocco M1a, D-163). Richiede l'estensione `sodium` di PHP e `pfi/contratti` ^1.0.

## Formato

Compatibile con il deposito delle chiavi della release 8.0 del portale (C-07): un portale 8.0 con il deposito acceso continua a leggere le sue voci senza migrazione.

| Elemento | Formato |
|---|---|
| Chiave del deposito | file di testo di almeno 32 caratteri, fuori dal database e dalla cartella dei dati; la chiave di cifratura è BLAKE2b del testo a 32 byte |
| Voce | file `<cartella>/<sha256 del nome>.cifrato`: nonce di 24 byte seguito dal valore cifrato con XSalsa20-Poly1305 |
| Nome di una voce | stringa libera; nel portale è il percorso del file del server che la voce sostituisce |
| Impronta | primi 12 caratteri esadecimali di HMAC-SHA-256 del valore con la chiave, nella forma `xxxx-xxxx-xxxx` |

## Interfaccia pubblica

| Metodo di `Deposito` | Scopo |
|---|---|
| `valore($nome)`, `presente($nome)`, `impronta($nome)` | interfaccia `Pfi\Contratti\Segreti`; con il ripiego attivo una voce assente si legge dal file con quel nome |
| `scrivi($nome, $valore)` | scrittura atomica con permessi 0600; restituisce l'impronta |
| `togli($nome)` | rimozione di una voce |
| `ruota($nuovotesto)` | cifra di nuovo tutte le voci con una nuova chiave; si ferma senza modifiche se una voce non si decifra |
| `attivo()`, `voci()`, `nel_deposito($nome)` | stato del deposito |

Il registro (`Pfi\Contratti\Registro`) riceve scritture, rimozioni, rotazioni e voci non decifrabili, mai il valore né il nome in chiaro: il nome compare come impronta SHA-256 di 12 caratteri.

## Uso fuori da Moodle

```php
require '/usr/local/lib/pfi/moduli/contratti/attuale/avvio.php';
\Pfi\Contratti\Caricatore::registra('/usr/local/lib/pfi/moduli');
$d = new \Pfi\Segreti\Deposito('/etc/pfi/deposito/chiave', '/var/lib/applicazione/deposito');
$d->scrivi('smtp', $password);
$password = $d->valore('smtp');
```

## Limiti

- I nomi delle voci non sono recuperabili dal deposito (il file ha l'impronta del nome): l'elenco dei segreti attesi lo conosce l'applicazione.
- La rotazione riscrive le voci una per una: va eseguita con l'applicazione ferma o in manutenzione; il nuovo testo della chiave si scrive nel file della chiave dopo la rotazione.
- Scadenza e promemoria della rotazione arrivano con l'adattatore del portale (`tool_pfisegreti`).
