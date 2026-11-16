# Storia di pfi/connettori

Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.

## 1.0.0 - 14 novembre 2026

Prima versione (release 9.0 del portale, blocco M2a, D-163, proposta 49 § 3.10): `Connettore` con tentativi, attese esponenziali con variazione casuale e rispetto di Retry-After (`Politica`), limite di frequenza a secchiello per processo o condiviso su file (`Limite`), registro di ogni richiesta senza intestazioni, corpi né parametri e con i tratti del percorso simili a chiavi sostituiti; trasporto `Curl` con verifica del certificato, proxy dell'ente, nessun reindirizzamento seguito, tempi massimi e dimensione massima della risposta; fornitore `Finto` per le prove; prova di salute `connettori.raggiungibili`.
