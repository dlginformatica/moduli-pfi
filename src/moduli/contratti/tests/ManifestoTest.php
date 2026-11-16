<?php
// Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.
// Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva (https://www.gnu.org/licenses/gpl-3.0.html).

declare(strict_types=1);

namespace Pfi\Contratti\Prove;

use PHPUnit\Framework\TestCase;
use Pfi\Contratti\Caricatore;
use Pfi\Contratti\Eccezione;
use Pfi\Contratti\EsitoSalute;
use Pfi\Contratti\Manifesto;
use Pfi\Contratti\RegistroNullo;
use Pfi\Contratti\Versione;

final class ManifestoTest extends TestCase {

    private function json(array $modifiche = []): string {
        return json_encode(array_merge(['nome' => 'pfi/prova', 'versione' => '1.2.0', 'descrizione' => 'Prova',
            'spazio' => 'Pfi\\Prova\\', 'php' => '>=8.2', 'richiede' => ['pfi/contratti' => '^1.0'], 'salute' => []], $modifiche));
    }

    public function test_manifesto_valido_e_requisiti(): void {
        $m = Manifesto::da_json($this->json());
        $this->assertSame('pfi/prova', $m->nome);
        $this->assertSame('1.2.0', (string) $m->versione);
        $this->assertSame([], $m->requisiti_mancanti(['pfi/contratti' => Versione::da_testo('1.0.0')], '8.4.1'));
        $this->assertSame(['manca pfi/contratti («^1.0»)'], $m->requisiti_mancanti([], '8.4.1'));
        $this->assertCount(2, $m->requisiti_mancanti(['pfi/contratti' => Versione::da_testo('2.0.0')], '8.1.0'));
    }

    public static function non_validi(): array {
        return [[['nome' => 'altro/prova']], [['versione' => 'uno']], [['spazio' => 'pfi\\prova']], [['descrizione' => ' ']],
            [['richiede' => ['pfi/x' => '^^1']]], [['salute' => [1]]]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('non_validi')]
    public function test_manifesti_non_validi(array $modifiche): void {
        $this->expectException(Eccezione::class);
        Manifesto::da_json($this->json($modifiche));
    }

    public function test_caricatore_da_cartella_base(): void {
        $base = sys_get_temp_dir() . '/pfi-moduli-' . bin2hex(random_bytes(4));
        mkdir("$base/prova/1.2.0/src", 0777, true);
        file_put_contents("$base/prova/1.2.0/modulo.json", $this->json(['nome' => 'pfi/provacaricatore',
            'spazio' => 'Pfi\\Provacaricatore\\']));
        file_put_contents("$base/prova/1.2.0/src/Saluto.php",
            "<?php\nnamespace Pfi\\Provacaricatore;\nfinal class Saluto { public static function testo(): string { return 'ciao'; } }\n");
        symlink("$base/prova/1.2.0", "$base/prova/attuale");
        $nuovi = Caricatore::registra($base);
        $this->assertArrayHasKey('pfi/provacaricatore', $nuovi);
        $this->assertSame('ciao', \Pfi\Provacaricatore\Saluto::testo());
        $this->assertArrayHasKey('pfi/contratti', Caricatore::moduli());
    }

    public function test_esito_e_registro_nullo(): void {
        $this->assertSame('buono', EsitoSalute::buono('ok')->stato);
        (new RegistroNullo())->registra('info', 'messaggio {x}', ['x' => 1]);
        $this->expectException(Eccezione::class);
        (new RegistroNullo())->registra('grave', 'x');
    }
}
