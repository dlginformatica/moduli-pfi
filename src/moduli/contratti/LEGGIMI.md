# pfi/contratti

Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.

Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva.

Interfacce e strumenti comuni a tutti i moduli universali del portale (release 9.0, blocco M1). Non dipende da Moodle né da altre librerie.

## Interfaccia pubblica

| Elemento | Scopo |
|---|---|
| `Registro` | registro dell'applicazione con i livelli di RFC 5424 e la forma di PSR-3; `RegistroNullo` non scrive nulla |
| `Segreti` | lettura di credenziali e chiavi, con impronta al posto del valore quando serve riconoscerle |
| `ProvaDiSalute`, `EsitoSalute` | prove rapide in sola lettura con stato `buono`, `avviso` o `guasto` |
| `Versione` | versione semantica e vincoli nella forma di Composer (`1.2.3`, `^1.2`, `~1.2`, `>=1.0 <2.0`, `*`, `\|\|`) |
| `Manifesto` | lettura e controllo di `modulo.json`, requisiti mancanti rispetto ai moduli disponibili e alla versione di PHP |
| `Caricatore` | caricamento PSR-4 dei moduli installati in `<base>/<nome>/attuale/` |
| `Eccezione` | eccezione di base, messaggi in italiano |

## Manifesto `modulo.json`

```json
{
    "nome": "pfi/registro",
    "versione": "1.0.0",
    "descrizione": "Registro dell'applicazione con invio a syslog.",
    "spazio": "Pfi\\Registro\\",
    "php": ">=8.2",
    "richiede": {"pfi/contratti": "^1.0"},
    "salute": ["Pfi\\Registro\\Salute\\Scrittura"]
}
```

`nome` è `pfi/<nome della cartella>`; `spazio` è lo spazio dei nomi PSR-4 della cartella `src/`; `salute` elenca le classi che implementano `ProvaDiSalute` con un costruttore senza argomenti obbligatori.

## Uso fuori da Moodle

Con Composer basta richiedere il pacchetto. Senza Composer:

```php
require '/usr/local/lib/pfi/moduli/contratti/attuale/avvio.php';
\Pfi\Contratti\Caricatore::registra('/usr/local/lib/pfi/moduli');
$m = \Pfi\Contratti\Caricatore::moduli()['pfi/contratti'];
echo $m->versione;                                         // 1.0.0
var_dump($m->versione->soddisfa('^1.0'));                  // true
```

## Limiti

- `Versione` non gestisce le etichette di pre-rilascio (`1.0.0-beta`): i moduli usano solo versioni stabili.
- Il caricatore registra un solo modulo per nome: la prima cartella base che lo contiene vince.
