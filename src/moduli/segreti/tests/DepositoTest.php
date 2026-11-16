<?php
// Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.
// Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva (https://www.gnu.org/licenses/gpl-3.0.html).

declare(strict_types=1);

namespace Pfi\Segreti\Prove;

use PHPUnit\Framework\TestCase;
use Pfi\Contratti\Eccezione;
use Pfi\Contratti\Registro;
use Pfi\Segreti\Deposito;
use Pfi\Segreti\Salute\Chiave;

final class DepositoTest extends TestCase {

    private string $dir;
    private string $chiave;

    protected function setUp(): void {
        $this->dir = sys_get_temp_dir() . '/pfi-segreti-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/voci', 0700, true);
        $this->chiave = $this->dir . '/chiave';
        file_put_contents($this->chiave, bin2hex(random_bytes(24)) . "\n");
        chmod($this->chiave, 0640);
    }

    protected function tearDown(): void {
        foreach (glob($this->dir . '/voci/*') ?: [] as $f) {
            unlink($f);
        }
        @rmdir($this->dir . '/voci');
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            unlink($f);
        }
        rmdir($this->dir);
    }

    /** Registro di prova che conserva le righe per controllare che non contengano segreti. */
    private function registro(): Registro {
        return new class implements Registro {
            public array $righe = [];
            public function registra(string $livello, string $messaggio, array $contesto = []): void {
                $this->righe[] = $messaggio . ' ' . json_encode($contesto);
            }
        };
    }

    public function test_scrittura_lettura_impronta_e_registro_senza_segreti(): void {
        $r = $this->registro();
        $d = new Deposito($this->chiave, $this->dir . '/voci', true, $r);
        $this->assertTrue($d->attivo());
        $impronta = $d->scrivi('/etc/pfi/servizi/posta.json', '{"password":"Segreto-123"}');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}$/', $impronta);
        $this->assertSame('{"password":"Segreto-123"}', $d->valore('/etc/pfi/servizi/posta.json'));
        $this->assertSame($impronta, $d->impronta('/etc/pfi/servizi/posta.json'));
        $this->assertTrue($d->presente('/etc/pfi/servizi/posta.json'));
        $this->assertSame(1, $d->voci());
        $this->assertSame(0600, fileperms(glob($this->dir . '/voci/*.cifrato')[0]) & 0777);
        foreach ($r->righe as $riga) {
            $this->assertStringNotContainsString('Segreto-123', $riga);
            $this->assertStringNotContainsString('/etc/pfi/servizi', $riga);
        }
        $this->assertTrue($d->togli('/etc/pfi/servizi/posta.json'));
        $this->assertNull($d->valore('/etc/pfi/servizi/posta.json'));
    }

    public function test_compatibile_con_il_deposito_della_8_0(): void {
        // Voce scritta come tool_pfisocieta\local\deposito::carica della release 8.0.
        $k = sodium_crypto_generichash(trim((string) file_get_contents($this->chiave)), '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        file_put_contents($this->dir . '/voci/' . hash('sha256', '/etc/pfi/servizi/canali.json') . '.cifrato',
            $nonce . sodium_crypto_secretbox('{"telegram":{"token":"x"}}', $nonce, $k));
        $d = new Deposito($this->chiave, $this->dir . '/voci');
        $this->assertSame('{"telegram":{"token":"x"}}', $d->valore('/etc/pfi/servizi/canali.json'));
        $this->assertSame(implode('-', str_split(substr(hash_hmac('sha256', '{"telegram":{"token":"x"}}', $k), 0, 12), 4)),
            $d->impronta('/etc/pfi/servizi/canali.json'));
    }

    public function test_ripiego_sul_file_e_voce_alterata(): void {
        file_put_contents($this->dir . '/servizio.json', 'dal file');
        $con = new Deposito($this->chiave, $this->dir . '/voci', true);
        $senza = new Deposito($this->chiave, $this->dir . '/voci', false);
        $this->assertSame('dal file', $con->valore($this->dir . '/servizio.json'));
        $this->assertNull($senza->valore($this->dir . '/servizio.json'));
        $con->scrivi($this->dir . '/servizio.json', 'dal deposito');
        $this->assertSame('dal deposito', $con->valore($this->dir . '/servizio.json'));
        $f = glob($this->dir . '/voci/*.cifrato')[0];
        file_put_contents($f, substr((string) file_get_contents($f), 0, -1) . 'X');
        $r = $this->registro();
        $alterato = new Deposito($this->chiave, $this->dir . '/voci', true, $r);
        $this->assertSame('dal file', $alterato->valore($this->dir . '/servizio.json'));
        $this->assertStringContainsString('non decifrabile', $r->righe[0]);
    }

    public function test_rotazione_della_chiave(): void {
        $d = new Deposito($this->chiave, $this->dir . '/voci');
        $d->scrivi('a', 'valore a');
        $d->scrivi('b', 'valore b');
        $nuova = bin2hex(random_bytes(24));
        $this->assertSame(2, $d->ruota($nuova));
        file_put_contents($this->chiave, $nuova);
        $this->assertSame('valore a', $d->valore('a'));
        $this->assertSame('valore b', $d->valore('b'));
        $this->expectException(Eccezione::class);
        $d->ruota('corta');
    }

    public function test_salute(): void {
        $this->assertSame('avviso', (new Chiave())->esegui()->stato);
        $d = new Deposito($this->chiave, $this->dir . '/voci');
        $this->assertSame('buono', (new Chiave($d))->esegui()->stato);
        chmod($this->chiave, 0644);
        $this->assertSame('guasto', (new Chiave($d))->esegui()->stato);
        unlink($this->chiave);
        $this->assertSame('guasto', (new Chiave($d))->esegui()->stato);
        $this->assertFalse($d->attivo());
    }
}
