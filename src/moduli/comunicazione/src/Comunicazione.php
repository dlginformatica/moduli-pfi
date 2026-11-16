<?php
// Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.
// Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva (https://www.gnu.org/licenses/gpl-3.0.html).

declare(strict_types=1);

namespace Pfi\Comunicazione;

use Pfi\Contratti\Eccezione;
use Pfi\Contratti\Registro;
use Pfi\Contratti\RegistroNullo;
use Pfi\Modelli\Modelli;

/**
 * Contratto per i componenti (proposta 49 § 3.4): invia($canale, $modello, $destinatari, $dati) rende il modello per
 * ogni destinatario, accoda un invio per destinatario e, se richiesto, lo esegue subito; elabora() esegue la coda con
 * tentativi ripetuti (1, 5, 15, 60, 240 minuti) per gli errori temporanei.
 *
 * Parti dei modelli per canale: posta «oggetto», «html», «testo»; telegram e notifica «testo» (e «oggetto»);
 * whatsapp «nome» del modello approvato e «parametri», uno per riga.
 */
final class Comunicazione {

    public const ATTESE = [60, 300, 900, 3600, 14400];

    private Registro $registro;

    /** @var array<string,Canale> */
    private array $canali = [];

    public function __construct(
        private readonly Modelli $modelli,
        private readonly Coda $coda,
        ?Registro $registro = null,
        private readonly ?Indirizzo $mittente = null,
        #[\SensitiveParameter] private readonly string $sale = '',
        private readonly bool $destinatariinchiaro = false,
    ) {
        $this->registro = $registro ?? new RegistroNullo();
    }

    public function canale(Canale $canale): self {
        $this->canali[$canale->tipo()] = $canale;
        return $this;
    }

    /** @return array<string,Canale> */
    public function canali(): array {
        return $this->canali;
    }

    /**
     * Accoda un invio per destinatario e restituisce gli identificativi degli invii.
     *
     * @param list<Indirizzo> $destinatari
     * @param array{lingua?:string,allegati?:list<Allegato>,rispondia?:Indirizzo,intestazioni?:array<string,string>,
     *     extra?:array<string,mixed>,subito?:bool,correlazione?:string,dati_per?:\Closure(Indirizzo):array,etichetta?:string} $opzioni
     *     «etichetta» è il nome registrato al posto della chiave del modello, per i messaggi composti dall'applicazione con
     *     un proprio modello e passati con un modello di solo inoltro
     * @return list<int>
     */
    public function invia(string $canale, string $chiave, array $destinatari, array $dati, array $opzioni = []): array {
        $c = $this->canali[$canale] ?? throw new Eccezione("Canale non configurato: «{$canale}»");
        $lingua = $opzioni['lingua'] ?? 'it';
        $modello = $this->modelli->trova($chiave, $canale, $lingua);
        $correlazione = $opzioni['correlazione'] ?? bin2hex(random_bytes(8));
        $ids = [];
        foreach ($destinatari as $d) {
            $parti = $modello->rendi(isset($opzioni['dati_per']) ? $dati + ($opzioni['dati_per'])($d) : $dati);
            $extra = $opzioni['extra'] ?? [];
            if ($canale === 'whatsapp') {
                $extra += ['modello' => trim($parti['nome'] ?? ''), 'lingua' => $lingua,
                    'parametri' => array_values(array_filter(array_map('trim', explode("\n", $parti['parametri'] ?? '')), 'strlen'))];
            }
            $m = new Messaggio([$d], $parti['oggetto'] ?? '', $parti['html'] ?? '', $parti['testo'] ?? '',
                $canale === 'posta' ? $this->mittente : null, [], [], $opzioni['allegati'] ?? [], $opzioni['rispondia'] ?? null,
                ['X-Pfi-Correlazione' => $correlazione] + ($opzioni['intestazioni'] ?? []), $extra);
            $ids[] = $this->coda->accoda(['correlazione' => $correlazione, 'canale' => $canale, 'configurazione' => $c->nome(),
                'modello' => mb_substr($opzioni['etichetta'] ?? $chiave, 0, 100), 'versione' => $modello->versione,
                'lingua' => $modello->lingua,
                'destinatario' => $d->pseudonimo($this->sale), 'inchiaro' => $this->destinatariinchiaro ? mb_substr($d->valore, 0, 255) : null,
                'contenuto' => json_encode($m->a_array(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)]);
        }
        $this->registro->registra(Registro::INFORMAZIONE, 'Accodati {numero} invii {canale} con il modello {modello} v{versione}', [
            'modulo' => 'pfi/comunicazione', 'correlazione' => $correlazione, 'numero' => count($ids), 'canale' => $canale,
            'modello' => $opzioni['etichetta'] ?? $chiave, 'versione' => $modello->versione]);
        if (!empty($opzioni['subito'])) {
            foreach ($ids as $id) {
                $this->esegui($this->coda->prendi_uno($id));
            }
        }
        return $ids;
    }

    /** Esegue gli invii scaduti della coda; restituisce quanti ne ha trattati. */
    public function elabora(int $massimo = 50): int {
        $righe = $this->coda->prendi($massimo);
        foreach ($righe as $r) {
            $this->esegui($r);
        }
        return count($righe);
    }

    private function esegui(?array $r): void {
        if ($r === null) {
            return;
        }
        $tentativi = (int) $r['tentativi'] + 1;
        $contesto = ['modulo' => 'pfi/comunicazione', 'correlazione' => $r['correlazione'], 'invio' => (int) $r['id'],
            'canale' => $r['canale'], 'modello' => $r['modello'], 'destinatario' => $r['destinatario'], 'tentativo' => $tentativi];
        try {
            $canale = $this->canali[$r['canale']] ?? throw new ErroreInvio("Canale «{$r['canale']}» non più configurato.", true);
            $esito = $canale->invia(Messaggio::da_array(json_decode((string) $r['contenuto'], true, 32, JSON_THROW_ON_ERROR)));
            $this->coda->esito((int) $r['id'], 'inviato', $tentativi, time(), $esito->idfornitore, $esito->risposta, true);
            $this->registro->registra(Registro::INFORMAZIONE, 'Invio {invio} {canale} riuscito', $contesto);
        } catch (ErroreInvio $e) {
            $ancora = $e->temporaneo && $tentativi <= count(self::ATTESE);
            $this->coda->esito((int) $r['id'], $ancora ? 'in_coda' : 'fallito', $tentativi,
                time() + ($ancora ? self::ATTESE[$tentativi - 1] : 0), '', $e->getMessage(), !$ancora);
            $this->registro->registra($ancora ? Registro::AVVISO : Registro::ERRORE,
                'Invio {invio} {canale} non riuscito' . ($ancora ? ', nuovo tentativo programmato' : '') . ': {errore}',
                $contesto + ['errore' => $e->getMessage()]);
        } catch (\JsonException|Eccezione $e) {
            $this->coda->esito((int) $r['id'], 'fallito', $tentativi, time(), '', 'Contenuto dell\'invio non valido.', true);
            $this->registro->registra(Registro::ERRORE, 'Invio {invio} non valido: {errore}', $contesto + ['errore' => $e->getMessage()]);
        }
    }
}
