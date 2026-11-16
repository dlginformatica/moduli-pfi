<?php
// Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.
// Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva (https://www.gnu.org/licenses/gpl-3.0.html).

declare(strict_types=1);

namespace Pfi\Backup\Salute;

use Pfi\Backup\Backup;
use Pfi\Contratti\EsitoSalute;
use Pfi\Contratti\ProvaDiSalute;

/**
 * Prova di salute dei backup: l'ultimo backup è più recente di $ore, le sue impronte corrispondono, lo spazio libero
 * nella cartella è almeno il doppio dell'ultimo backup. Senza backup configurato (costruzione dal manifesto) controlla
 * solo l'estensione sodium.
 */
final class Recente implements ProvaDiSalute {

    public function __construct(private readonly ?Backup $backup = null, private readonly string $cartella = '', private readonly int $ore = 26) {
    }

    public function codice(): string {
        return 'backup.recente';
    }

    public function descrizione(): string {
        return 'Backup recente e integro';
    }

    public function esegui(): EsitoSalute {
        if (!function_exists('sodium_crypto_secretstream_xchacha20poly1305_init_push')) {
            return EsitoSalute::guasto('Estensione sodium di PHP mancante: la cifratura dei backup non è disponibile.');
        }
        if ($this->backup === null) {
            return EsitoSalute::buono('Estensione sodium presente; i backup li controlla l\'applicazione che li configura.');
        }
        $elenco = $this->backup->backup();
        if (!$elenco) {
            return EsitoSalute::guasto('Nessun backup presente.');
        }
        $ultimo = $elenco[0];
        $data = \DateTimeImmutable::createFromFormat('Ymd-His', $ultimo);
        $ore = $data ? (time() - $data->getTimestamp()) / 3600 : PHP_INT_MAX;
        try {
            $m = Backup::verifica_impronte($this->backup->percorso($ultimo));
        } catch (\Throwable $e) {
            return EsitoSalute::guasto("Ultimo backup {$ultimo} non integro.", ['backup' => $ultimo]);
        }
        $dimensione = array_sum(array_column($m['unita'] ?? [], 'byte'));
        $libero = $this->cartella !== '' ? (int) @disk_free_space($this->cartella) : PHP_INT_MAX;
        $dettagli = ['ultimo' => $ultimo, 'ore' => (int) $ore, 'byte' => $dimensione];
        if ($ore > $this->ore) {
            return EsitoSalute::guasto("Ultimo backup di " . (int) $ore . " ore fa.", $dettagli);
        }
        if ($libero < 2 * $dimensione) {
            return EsitoSalute::avviso('Spazio libero nella cartella dei backup inferiore al doppio dell\'ultimo backup.', $dettagli + ['libero' => $libero]);
        }
        return EsitoSalute::buono('Backup recente e integro.', $dettagli);
    }
}
