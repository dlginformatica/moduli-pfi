# pfi/diagnostica

Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.

Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva.

Esecuzione delle prove di salute dei moduli universali (release 9.0, blocco M1b, D-163). La usano `pfi-verifica`, il pannello dei moduli prima e dopo un aggiornamento e l'indirizzo di salute per il monitoraggio. Richiede `pfi/contratti` ^1.0.

## Interfaccia pubblica

| Elemento | Scopo |
|---|---|
| `Diagnostica::da_manifesti($manifesti, $sostituzioni)` | aggiunge le prove del campo `salute` dei manifesti; `$sostituzioni` dà istanze già costruite per le prove con dipendenze |
| `Diagnostica::aggiungi($modulo, $prova)` | aggiunge una prova costruita dall'applicazione |
| `Diagnostica::esegui()` | esegue tutte le prove; un'eccezione o una classe non caricabile danno `guasto`, una prova più lenta della soglia (2 secondi) un `avviso` |
| `Diagnostica::stato($risultati)` | `guasto`, `avviso` o `buono` |
| `Diagnostica::json($risultati, $quando)` | rapporto per il monitoraggio: stato, codici e messaggi, senza dettagli |
| `Diagnostica::markdown($risultati)` | tabella per le persone |

Il messaggio di una prova interrotta riporta solo la classe dell'eccezione, non il suo testo, che potrebbe contenere segreti.

## Uso fuori da Moodle

```php
\Pfi\Contratti\Caricatore::registra('/usr/local/lib/pfi/moduli');
$d = (new \Pfi\Diagnostica\Diagnostica())->da_manifesti(\Pfi\Contratti\Caricatore::moduli());
$r = $d->esegui();
echo \Pfi\Diagnostica\Diagnostica::markdown($r);
exit(\Pfi\Diagnostica\Diagnostica::stato($r) === 'guasto' ? 1 : 0);
```

## Limiti

- La soglia di lentezza misura la durata dopo la fine della prova: una prova bloccata blocca la diagnostica. Le prove devono essere rapide e con tempi massimi propri sulle operazioni di rete.
