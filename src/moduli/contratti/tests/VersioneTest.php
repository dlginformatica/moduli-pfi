<?php
// Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.
// Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva (https://www.gnu.org/licenses/gpl-3.0.html).

declare(strict_types=1);

namespace Pfi\Contratti\Prove;

use PHPUnit\Framework\TestCase;
use Pfi\Contratti\Eccezione;
use Pfi\Contratti\Versione;

final class VersioneTest extends TestCase {

    public function test_lettura_e_confronto(): void {
        $this->assertSame('1.2.0', (string) Versione::da_testo('1.2'));
        $this->assertSame('2.0.0', (string) Versione::da_testo('v2'));
        $this->assertLessThan(0, Versione::da_testo('1.9.9')->confronta(Versione::da_testo('1.10.0')));
        $this->expectException(Eccezione::class);
        Versione::da_testo('1.x');
    }

    public static function casi(): array {
        return [
            ['1.4.2', '^1.2', true], ['2.0.0', '^1.2', false], ['1.1.9', '^1.2', false],
            ['0.3.5', '^0.3', true], ['0.4.0', '^0.3', false],
            ['1.9.0', '~1.2', true], ['2.0.0', '~1.2', false], ['1.2.9', '~1.2.3', true], ['1.3.0', '~1.2.3', false],
            ['1.5.0', '>=1.0 <2.0', true], ['2.0.0', '>=1.0 <2.0', false],
            ['3.1.0', '^1.0 || ^3.0', true], ['2.1.0', '^1.0 || ^3.0', false],
            ['1.2.3', '1.2.3', true], ['1.2.4', '1.2.3', false], ['9.9.9', '*', true],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('casi')]
    public function test_vincoli(string $versione, string $vincolo, bool $atteso): void {
        $this->assertSame($atteso, Versione::da_testo($versione)->soddisfa($vincolo), "$versione con $vincolo");
    }
}
