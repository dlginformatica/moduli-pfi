# pfi/registro

Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.

Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva.

Registro degli eventi dei moduli universali (release 9.0, blocco M2; proposta 49 § 3.3). Richiede `pfi/contratti` ^1.0. Nel portale lo configura l'adattatore `tool_pfiregistro`; `logstore_pfisyslog` vi invia gli eventi standard di Moodle.

## Interfaccia pubblica

| Elemento | Scopo |
|---|---|
| `Registro` | `registra($livello, $messaggio, $contesto)` e i metodi di PSR-3 (`error`, `info`…, `log`); `aggiungi($destinazione, $livellominimo)`; `per_modulo($modulo)` e `con_correlazione($id)` restituiscono una copia con le stesse destinazioni; `guasti()` elenca gli ultimi errori di scrittura |
| `Mascheratore` | maschera prima di ogni destinazione: valori con chiave sensibile (password, token, chiave, segreto, autorizzazione, cookie, sessione…), forme nel testo (`password=…`, `Authorization: …`, `Bearer …`, credenziali in un indirizzo), valori registrati con `valore_segreto()` |
| `Voce` | evento pronto: data, livello, messaggio interpolato, modulo, correlazione, contesto mascherato; `json()` |
| `Destinazione` | interfaccia: `nome()`, `scrivi(Voce)`, `salute()` |
| `Destinazioni\Tabella` | tabella via PDO; `crea_tabella()` fuori da Moodle, `cerca($filtri)`, `conserva($giorni)` |
| `Destinazioni\File` | una riga JSON per evento, rotazione per dimensione, conservazione per numero di file e per giorni |
| `Destinazioni\Syslog` | `udp`, `tcp`, `tls` (RFC 5424, con conteggio dei byte su TCP e TLS) o `locale` (syslog del server) |
| `Destinazioni\Memoria` | per le prove e per mostrare gli eventi di un'operazione |
| `Rfc5424` | forma dell'evento: struttura (facility) da `user`, `daemon`, `auth`, `authpriv`, `local0`…`local7`; dati strutturati `pfi@32473` e `pfictx@32473` |
| `Salute\Destinazioni` | prova di salute `registro.destinazioni` |

Il contesto ammette scalari, liste e oggetti; un'eccezione diventa classe e messaggio mascherato, un oggetto la sua classe. Le chiavi `modulo` e `correlazione` del contesto sostituiscono quelle predefinite per un solo evento.

## Uso fuori da Moodle

```php
use Pfi\Registro\{Registro, Mascheratore, Rfc5424};
use Pfi\Registro\Destinazioni\{File, Syslog};

$r = (new Registro(new Mascheratore(), 'mia-applicazione'))
    ->aggiungi(new File('/var/log/mia/registro.log', 10 * 1024 * 1024, 10, 90), Registro::INFORMAZIONE)
    ->aggiungi(new Syslog('tls', 'syslog.example.org', 6514, new Rfc5424('local0'), 2.0,
        ['ca' => '/etc/ssl/certs/ca-certificates.crt']), Registro::AVVISO);
$r->error('Invio a {destinatario} non riuscito', ['destinatario' => 'mario@example.org', 'password' => $p]);
```

## Limiti

- Il numero di impresa 32473 dei dati strutturati è quello riservato agli esempi (RFC 5612): per inviare a sistemi esterni all'organizzazione serve un numero assegnato dalla IANA [DA VERIFICARE].
- Il mascheramento delle forme nel testo riconosce i casi comuni; un segreto scritto in una forma non prevista si maschera solo se registrato con `valore_segreto()` o passato nel contesto con una chiave sensibile.
- Con UDP gli eventi si perdono senza avviso se il server non li riceve; con TCP e TLS l'invio si ritenta una volta riaprendo la connessione.
- La ricerca per testo nella tabella usa `LIKE` senza indice: va limitata con il periodo sui registri grandi.
