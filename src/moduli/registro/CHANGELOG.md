# Storia di pfi/registro

Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.

## 1.0.0 - 14 novembre 2026

Prima versione (release 9.0 del portale, blocco M2, proposta 49 § 3.3): `Registro` con l'interfaccia di pfi/contratti e i metodi di PSR-3, livello minimo per destinazione, modulo e identificativo di correlazione; `Mascheratore` per chiavi sensibili, forme riconoscibili nel testo e valori noti; destinazioni `Tabella` (PDO, con ricerca e conservazione), `File` (righe JSON, rotazione per dimensione, conservazione per numero e giorni, permessi 0640), `Syslog` (RFC 5424 su UDP, TCP e TLS con conteggio dei byte, oppure syslog del server) e `Memoria`; prova di salute `registro.destinazioni`.
