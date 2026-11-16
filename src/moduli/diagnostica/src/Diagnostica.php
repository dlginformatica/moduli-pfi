<?php
// Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.
// Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva (https://www.gnu.org/licenses/gpl-3.0.html).

declare(strict_types=1);

namespace Pfi\Diagnostica;

use Pfi\Contratti\EsitoSalute;
use Pfi\Contratti\Manifesto;
use Pfi\Contratti\ProvaDiSalute;
use Pfi\Contratti\Registro;
use Pfi\Contratti\RegistroNullo;

/**
 * Esecuzione delle prove di salute dei moduli (release 9.0, blocco M1b; D-163).
 *
 * Le prove vengono dai manifesti dei moduli (campo «salute») oppure si aggiungono come oggetti già costruiti, per le prove
 * che hanno bisogno di dipendenze (per esempio il deposito dei segreti). Una prova che lancia un'eccezione o supera il tempo
 * massimo dà «guasto»: la diagnostica non si interrompe mai per una prova.
 */
final class Diagnostica {

    /** @var array<int,array{0:string,1:ProvaDiSalute}> */
    private array $prove = [];

    private Registro $registro;

    public function __construct(?Registro $registro = null, private readonly float $soglialenta = 2000.0) {
        $this->registro = $registro ?? new RegistroNullo();
    }

    /**
     * Aggiunge le prove dichiarate nei manifesti; le classi con costruttore che richiede argomenti si saltano con un avviso.
     *
     * @param iterable<Manifesto> $manifesti
     * @param array<string,ProvaDiSalute> $sostituzioni classe => istanza già costruita al posto di quella del manifesto
     */
    public function da_manifesti(iterable $manifesti, array $sostituzioni = []): self {
        foreach ($manifesti as $m) {
            foreach ($m->salute as $classe) {
                if (isset($sostituzioni[$classe])) {
                    $this->aggiungi($m->nome, $sostituzioni[$classe]);
                    continue;
                }
                try {
                    $r = new \ReflectionClass($classe);
                    $c = $r->getConstructor();
                    if (!$r->implementsInterface(ProvaDiSalute::class) || ($c && $c->getNumberOfRequiredParameters() > 0)) {
                        throw new \RuntimeException('classe non utilizzabile come prova di salute');
                    }
                    $this->aggiungi($m->nome, $r->newInstance());
                } catch (\Throwable $e) {
                    $this->aggiungi($m->nome, new ProvaNonCaricabile($classe, $e->getMessage()));
                }
            }
        }
        return $this;
    }

    public function aggiungi(string $modulo, ProvaDiSalute $prova): self {
        $this->prove[] = [$modulo, $prova];
        return $this;
    }

    /**
     * Esegue tutte le prove.
     *
     * @return Risultato[]
     */
    public function esegui(): array {
        $risultati = [];
        foreach ($this->prove as [$modulo, $prova]) {
            $inizio = hrtime(true);
            try {
                $esito = $prova->esegui();
            } catch (\Throwable $e) {
                $esito = EsitoSalute::guasto('La prova si è interrotta: ' . get_class($e));
            }
            $ms = (hrtime(true) - $inizio) / 1e6;
            if ($ms > $this->soglialenta && $esito->stato === EsitoSalute::BUONO) {
                $esito = EsitoSalute::avviso($esito->messaggio . ' (prova lenta: ' . round($ms) . ' ms)', $esito->dettagli);
            }
            $r = new Risultato($modulo, $prova->codice(), $prova->descrizione(), $esito, $ms);
            if ($esito->stato !== EsitoSalute::BUONO) {
                $this->registro->registra($esito->stato === EsitoSalute::GUASTO ? Registro::ERRORE : Registro::AVVISO,
                    'Prova di salute {codice}: {messaggio}', ['modulo' => 'pfi/diagnostica', 'codice' => $r->codice,
                        'messaggio' => $esito->messaggio]);
            }
            $risultati[] = $r;
        }
        return $risultati;
    }

    /**
     * Stato complessivo: «guasto» se almeno una prova è guasta, «avviso» se almeno una è in avviso, altrimenti «buono».
     *
     * @param Risultato[] $risultati
     */
    public static function stato(array $risultati): string {
        $stati = array_map(fn(Risultato $r) => $r->esito->stato, $risultati);
        return in_array(EsitoSalute::GUASTO, $stati, true) ? EsitoSalute::GUASTO
            : (in_array(EsitoSalute::AVVISO, $stati, true) ? EsitoSalute::AVVISO : EsitoSalute::BUONO);
    }

    /**
     * Rapporto per i sistemi di monitoraggio: JSON senza dettagli, solo stato, codici e messaggi.
     *
     * @param Risultato[] $risultati
     */
    public static function json(array $risultati, \DateTimeInterface $quando): string {
        return json_encode(['stato' => self::stato($risultati), 'quando' => $quando->format(DATE_ATOM),
            'prove' => array_map(fn(Risultato $r) => ['modulo' => $r->modulo, 'codice' => $r->codice,
                'stato' => $r->esito->stato, 'messaggio' => $r->esito->messaggio], $risultati)],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }

    /**
     * Rapporto in Markdown per le persone.
     *
     * @param Risultato[] $risultati
     */
    public static function markdown(array $risultati): string {
        $righe = ['| Modulo | Prova | Esito | Messaggio |', '|---|---|---|---|'];
        foreach ($risultati as $r) {
            $righe[] = sprintf('| %s | %s | %s | %s |', $r->modulo, $r->descrizione, $r->esito->stato,
                str_replace('|', '/', $r->esito->messaggio));
        }
        return 'Stato complessivo: ' . self::stato($risultati) . "\n\n" . implode("\n", $righe) . "\n";
    }
}
