# Storia di pfi/segreti

Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.

## 1.0.0 - 14 novembre 2026

Prima versione (release 9.0 del portale, blocco M1a, D-163): `Deposito` che implementa `Pfi\Contratti\Segreti`, compatibile con il deposito delle chiavi della release 8.0 (stesso formato delle voci e della chiave), con scrittura atomica, ripiego sul file del server, impronta HMAC, rotazione della chiave e registro senza valori né nomi in chiaro; prova di salute `Salute\Chiave` su chiave e permessi.
