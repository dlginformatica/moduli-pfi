<?php
// Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.
// Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva (https://www.gnu.org/licenses/gpl-3.0.html).

declare(strict_types=1);

namespace Pfi\Modelli\Prove;

use PHPUnit\Framework\TestCase;
use Pfi\Contratti\Eccezione;
use Pfi\Modelli\Archivi\Memoria;
use Pfi\Modelli\Archivi\Tabella;
use Pfi\Modelli\Archivio;
use Pfi\Modelli\ErroreModello;
use Pfi\Modelli\Modello;
use Pfi\Modelli\Modelli;
use Pfi\Modelli\Motore;
use Pfi\Modelli\Schema;

final class ModelliTest extends TestCase {

    private function schema(): Schema {
        return (new Schema('tool_pfiaula/promemoria', 'Promemoria della lezione'))
            ->segnaposto('nome', 'Nome della persona', 'Maria')
            ->segnaposto('corso.titolo', 'Titolo del corso', 'Contratti pubblici')
            ->segnaposto('lezioni', 'Lezioni', null, true)
            ->segnaposto('lezioni.data', 'Data', '20/11/2026')
            ->segnaposto('lezioni.aula', 'Aula', 'Aula 1')
            ->segnaposto('firma', 'Firma in HTML', '<b>Ufficio</b>');
    }

    private function predefinito(): Modello {
        return new Modello('tool_pfiaula/promemoria', 'posta', 'it', [
            'oggetto' => "Promemoria:\n{{corso.titolo}}",
            'html' => '<p>Gentile {{nome}},</p><ul>{{#lezioni}}<li>{{data}} in {{aula}} ({{corso.titolo}})</li>{{/lezioni}}'
                . '</ul>{{^lezioni}}<p>Nessuna lezione.</p>{{/lezioni}}{{{firma}}}',
            'testo' => "Gentile {{nome}},\n{{#lezioni}}- {{data}} {{aula}}\n{{/lezioni}}",
        ]);
    }

    public function test_motore_e_rendering(): void {
        $parti = $this->predefinito()->rendi(['nome' => 'Anna <Rossi>', 'corso' => ['titolo' => 'A & B'],
            'lezioni' => [['data' => '1/12', 'aula' => '3'], ['data' => '2/12', 'aula' => '4']], 'firma' => '<i>U</i>']);
        $this->assertSame('Promemoria: A & B', $parti['oggetto']);
        $this->assertSame('<p>Gentile Anna &lt;Rossi&gt;,</p><ul><li>1/12 in 3 (A &amp; B)</li><li>2/12 in 4 (A &amp; B)</li></ul><i>U</i>',
            $parti['html']);
        $this->assertSame("Gentile Anna <Rossi>,\n- 1/12 3\n- 2/12 4\n", $parti['testo']);
        $vuoto = $this->predefinito()->rendi(['nome' => 'X', 'lezioni' => []]);
        $this->assertStringContainsString('Nessuna lezione.', $vuoto['html']);
        $this->assertSame('Promemoria:', $vuoto['oggetto']);
        $a = Motore::analizza('{{#ok}}sì{{/ok}}{{#zero}}no{{/zero}}{{#mappa}}{{x}}{{/mappa}}{{#elenco}}{{.}},{{/elenco}}');
        $this->assertSame('sì7a,b,', Motore::rendi($a, ['ok' => true, 'zero' => 0, 'mappa' => ['x' => 7], 'elenco' => ['a', 'b']], false));
    }

    public function test_errori_di_sintassi(): void {
        foreach (["Riga\n{{#a}}x" => 2, '{{> parziale}}' => 1, '{{=<% %>=}}' => 1, '{{#a}}{{/b}}' => 1] as $t => $riga) {
            try {
                new Modello('a/b', 'posta', 'it', ['html' => $t]);
                $this->fail("accettato: {$t}");
            } catch (ErroreModello $e) {
                $this->assertSame($riga, $e->riga);
                $this->assertStringStartsWith('Parte «html»', $e->getMessage());
            }
        }
    }

    public function test_verifica_ed_esempi(): void {
        $s = $this->schema();
        $m = new Modello('tool_pfiaula/promemoria', 'posta', 'it', ['oggetto' => '{{nome}} {{cognome}}',
            'html' => '{{#lezioni}}{{nome}}{{aula}}{{orario}}{{/lezioni}}{{&firma}}']);
        $p = $m->verifica($s);
        $this->assertSame(['Segnaposto non previsto: {{cognome}}', 'Segnaposto non previsto: {{lezioni.orario}}'],
            array_slice($p, 0, 2));
        $this->assertStringContainsString('senza escape', $p[2]);
        $pp = $this->predefinito()->verifica($s);
        $this->assertCount(1, $pp);
        $this->assertStringContainsString('senza escape', $pp[0]);
        $e = $s->esempi();
        $this->assertSame(['corso' => ['titolo' => 'Contratti pubblici'], 'firma' => '<b>Ufficio</b>',
            'lezioni' => [['aula' => 'Aula 1', 'data' => '20/11/2026']], 'nome' => 'Maria'], $e);
        $this->expectException(Eccezione::class);
        new Schema('senza-barra');
    }

    public static function archivi(): array {
        $pdo = new \PDO('sqlite::memory:', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $t = new Tabella($pdo);
        $t->crea_tabella();
        return ['memoria' => [new Memoria()], 'tabella' => [$t]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('archivi')]
    public function test_versioni_lingue_e_ripristino(Archivio $archivio): void {
        $m = (new Modelli($archivio))->dichiara($this->schema(), $this->predefinito());
        $this->assertCount(1, $m->predefiniti('tool_pfiaula/promemoria'));
        $this->assertSame([], $m->predefiniti('altro/schema'));
        $this->assertSame(0, $m->trova('tool_pfiaula/promemoria', 'posta', 'en')->versione);
        [$v1, $problemi] = $m->salva(new Modello('tool_pfiaula/promemoria', 'posta', 'it', ['oggetto' => 'Uno {{nome}}']), 'admin');
        $this->assertSame([1, []], [$v1->versione, $problemi]);
        [$v2, $problemi] = $m->salva(new Modello('tool_pfiaula/promemoria', 'posta', 'it', ['oggetto' => 'Due {{x}}']), 'admin', 'prova');
        $this->assertSame(2, $v2->versione);
        $this->assertCount(1, $problemi);
        $m->salva(new Modello('tool_pfiaula/promemoria', 'posta', 'en', ['oggetto' => 'Reminder {{nome}}']), 'admin');
        $this->assertSame(['oggetto' => 'Reminder Maria'], $m->rendi('tool_pfiaula/promemoria', 'posta', 'en', ['nome' => 'Maria']));
        $this->assertSame(['oggetto' => 'Due'], $m->rendi('tool_pfiaula/promemoria', 'posta', 'fr', []));
        $r = $m->ripristina('tool_pfiaula/promemoria', 'posta', 'it', 1, 'admin');
        $this->assertSame([3, ['oggetto' => 'Uno {{nome}}']], [$r->versione, $r->parti]);
        $this->assertSame([3, 2, 1], array_column($archivio->versioni('tool_pfiaula/promemoria', 'posta', 'it'), 'versione'));
        $this->assertSame('Ripristino della versione 1', $archivio->versioni('tool_pfiaula/promemoria', 'posta', 'it')[0]['nota']);
        $p = $m->ripristina('tool_pfiaula/promemoria', 'posta', 'it', 0, 'admin');
        $this->assertSame([4, $this->predefinito()->parti], [$p->versione, $p->parti]);
        $this->assertSame('Promemoria: Contratti pubblici', $m->anteprima($p)['oggetto']);
        $this->expectException(Eccezione::class);
        $m->trova('tool_pfiaula/promemoria', 'telegram', 'it');
    }
}
