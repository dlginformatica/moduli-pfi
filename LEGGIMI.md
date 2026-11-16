# Moduli universali PFI

Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.

Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva (file `LICENSE`). Autori: Gaetano Giarrusso e Ufficio USIS.

Dodici librerie PHP (`pfi/contratti`, `segreti`, `diagnostica`, `registro`, `connettori`, `esecutore`, `modelli`, `comunicazione`, `esportazione`, `stampa`, `backup`, `gestore`) nate con il Portale di autoformazione IT e riusabili in qualunque applicazione PHP 8.2 o successiva.

| Dove | Che cosa |
|---|---|
| `src/moduli/MANUALE-GENERALE.md` | guida generale: installazione con Composer anche senza rete, versioni, pubblicazione, istruzioni per gli agenti |
| `src/moduli/<nome>/MANUALE.md` (e `.pdf`) | manuale di ogni modulo, con esempio eseguito e riferimento dell'interfaccia |
| `src/moduli/LEGGIMI.md` | convenzioni e tabella dei moduli |
| `repository/` | repository Composer pronto: `dist/` (archivi), `packages.json`, `manuali/`, `SHA256SUMS.txt` |
| `tools/` | controllo, distribuzione, riferimento, esempi, firma dei pacchetti |

Uso rapido, con questo repository clonato in `/percorso/moduli-pfi`:

```json
{
    "repositories": [{"type": "artifact", "url": "/percorso/moduli-pfi/repository/dist/"}],
    "require": {"pfi/registro": "^1.0"}
}
```

Ogni versione pubblicata di un modulo ha l'etichetta `pfi-<nome>-<versione>`. Il repository del progetto del portale resta l'origine dei sorgenti.
