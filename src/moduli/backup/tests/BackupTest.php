<?php
// Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.
// Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva (https://www.gnu.org/licenses/gpl-3.0.html).

declare(strict_types=1);

namespace Pfi\Backup\Prove;

use PHPUnit\Framework\TestCase;
use Pfi\Backup\Backup;
use Pfi\Backup\Cifratura;
use Pfi\Backup\Comando;
use Pfi\Backup\Destinazioni\Cartella as DestinazioneCartella;
use Pfi\Backup\Destinazioni\S3;
use Pfi\Backup\Salute\Recente;
use Pfi\Backup\Unita\Cartella;
use Pfi\Backup\Unita\Postgres;
use Pfi\Contratti\Eccezione;
use Pfi\Registro\Destinazioni\Memoria;
use Pfi\Registro\Registro;

final class BackupTest extends TestCase {

    private string $base;

    protected function setUp(): void {
        $this->base = sys_get_temp_dir() . '/pfi-backup-' . bin2hex(random_bytes(4));
        mkdir($this->base . '/dati/sotto', 0755, true);
        mkdir($this->base . '/etc', 0700, true);
        file_put_contents($this->base . '/dati/a.txt', 'alfa');
        file_put_contents($this->base . '/dati/sotto/b.bin', random_bytes(200000));
        file_put_contents($this->base . '/dati/cache.tmp', 'da escludere');
        file_put_contents($this->base . '/etc/segreto.conf', 'password=MoltoSegreta');
        Cifratura::genera($this->base . '/chiave');
    }

    protected function tearDown(): void {
        Comando::esegui(['rm', '-rf', '--', $this->base]);
    }

    public function test_cifratura(): void {
        $c = Cifratura::da_file($this->base . '/chiave');
        $c->cifra($this->base . '/dati/sotto/b.bin', $this->base . '/b.cif');
        $this->assertStringNotContainsString(substr(file_get_contents($this->base . '/dati/sotto/b.bin'), 0, 64), file_get_contents($this->base . '/b.cif'));
        $c->decifra($this->base . '/b.cif', $this->base . '/b.dec');
        $this->assertFileEquals($this->base . '/dati/sotto/b.bin', $this->base . '/b.dec');
        $alterato = file_get_contents($this->base . '/b.cif');
        $alterato[1000] = chr(ord($alterato[1000]) ^ 1);
        file_put_contents($this->base . '/b.alt', $alterato);
        file_put_contents($this->base . '/b.tronco', substr(file_get_contents($this->base . '/b.cif'), 0, 5 + 24 + 65536 + 17));
        foreach (['b.alt' => 'alterato', 'b.tronco' => 'troncato'] as $f => $atteso) {
            try {
                $c->decifra($this->base . '/' . $f, $this->base . '/' . $f . '.dec');
                $this->fail("{$f} decifrato");
            } catch (Eccezione $e) {
                $this->assertStringContainsString($atteso, $e->getMessage());
                $this->assertFileDoesNotExist($this->base . '/' . $f . '.dec');
            }
        }
        try {
            (new Cifratura(str_repeat('x', 40)))->decifra($this->base . '/b.cif', $this->base . '/b.x');
            $this->fail('chiave errata accettata');
        } catch (Eccezione $e) {
            $this->assertStringContainsString('chiave errata', $e->getMessage());
        }
        chmod($this->base . '/chiave', 0644);
        $this->expectException(Eccezione::class);
        Cifratura::da_file($this->base . '/chiave');
    }

    public function test_backup_cartelle_impronte_ripristino_e_conservazione(): void {
        $mem = new Memoria();
        $c = Cifratura::da_file($this->base . '/chiave');
        $b = (new Backup($this->base . '/backup', '/opt/moodle-9.0', 2, 1, (new Registro())->aggiungi($mem)))
            ->unita(new Cartella('moodledata', $this->base . '/dati', $this->base . '/backup', null, null, ['cache.tmp']))
            ->unita(new Cartella('configurazione', $this->base . '/etc', $this->base . '/backup', $c))
            ->destinazione(new DestinazioneCartella($this->base . '/remota'));
        mkdir($this->base . '/remota');
        $id = $b->esegui([], new \DateTimeImmutable('2026-11-01 02:00:00'));
        $this->assertSame('20261101-020000', $id);
        $dir = $b->percorso($id);
        $this->assertSame(['MANIFESTO.json', 'RILASCIO', 'SHA256SUMS', 'configurazione.tar.gz.cifrato', 'moodledata.tar.gz'], Backup::file_di($dir));
        $this->assertSame(0700, fileperms($dir) & 0777);
        $this->assertStringNotContainsString('MoltoSegreta', (string) shell_exec('zcat -f ' . escapeshellarg("{$dir}/configurazione.tar.gz.cifrato") . ' 2>/dev/null'));
        $m = Backup::verifica_impronte($dir);
        $this->assertSame(['moodledata', 'configurazione'], array_keys($m['unita']));
        $this->assertSame("/opt/moodle-9.0\n", file_get_contents("{$dir}/RILASCIO"));
        $this->assertSame(['20261101-020000'], (new DestinazioneCartella($this->base . '/remota'))->elenco());
        $this->assertSame(['moodledata', 'configurazione'], $b->ripristina($id));
        file_put_contents($this->base . '/dati/a.txt', 'modificato');
        file_put_contents($this->base . '/dati/nuovo.txt', 'nuovo');
        unlink($this->base . '/etc/segreto.conf');
        $b->ripristina($id, [], false);
        $this->assertSame('alfa', file_get_contents($this->base . '/dati/a.txt'));
        $this->assertFileDoesNotExist($this->base . '/dati/nuovo.txt');
        $this->assertFileDoesNotExist($this->base . '/dati/cache.tmp');
        $this->assertSame('password=MoltoSegreta', file_get_contents($this->base . '/etc/segreto.conf'));
        $accantonati = glob($this->base . '/backup/moodledata-prima-del-ripristino-*');
        $this->assertCount(1, $accantonati);
        $this->assertSame('nuovo', file_get_contents($accantonati[0] . '/nuovo.txt'));
        try {
            (new Backup($this->base . '/backup', '/opt/moodle-9.1'))->unita(new Cartella('moodledata', $this->base . '/dati', $this->base))->ripristina($id);
            $this->fail('rilascio diverso accettato');
        } catch (Eccezione $e) {
            $this->assertStringContainsString('rilascio', $e->getMessage());
        }
        file_put_contents("{$dir}/moodledata.tar.gz", 'alterato', FILE_APPEND);
        try {
            $b->ripristina($id, ['moodledata'], false);
            $this->fail('backup alterato ripristinato');
        } catch (Eccezione $e) {
            $this->assertStringContainsString('moodledata.tar.gz', $e->getMessage());
        }
        foreach (['2026-11-02', '2026-11-03', '2026-11-04'] as $g) {
            $b->esegui(['configurazione'], new \DateTimeImmutable("{$g} 02:00:00"));
        }
        $this->assertSame(['20261104-020000', '20261103-020000'], $b->backup());
        $this->assertCount(2, (new DestinazioneCartella($this->base . '/remota'))->elenco());
        try {
            $b->esegui(['inesistente']);
            $this->fail();
        } catch (Eccezione) {
            $this->assertCount(2, $b->backup());
        }
        $this->assertStringNotContainsString('MoltoSegreta', implode('', array_map(fn($v) => $v->json(), $mem->voci)));
        $this->assertSame('guasto', (new Recente($b, $this->base))->esegui()->stato, 'ultimo backup di giorni fa');
        $b->esegui(['configurazione']);
        $this->assertSame('buono', (new Recente($b, $this->base))->esegui()->stato);
    }

    public function test_database_postgres(): void {
        $amb = ['PGHOST' => getenv('PFI_PGHOST') ?: '/tmp', 'PGPORT' => getenv('PFI_PGPORT') ?: '5433', 'PGUSER' => getenv('PFI_PGUSER') ?: 'moodle'];
        [$c] = Comando::esegui(['psql', '-d', 'postgres', '-Atc', 'select 1'], null, null, $amb);
        if ($c !== 0) {
            $this->markTestSkipped('PostgreSQL di prova non raggiungibile');
        }
        $db = 'pfi_prova_bk_' . bin2hex(random_bytes(3));
        $sql = fn(string $q) => trim((string) shell_exec('PGHOST=' . escapeshellarg($amb['PGHOST']) . ' PGPORT=' . $amb['PGPORT'] . ' PGUSER='
            . $amb['PGUSER'] . ' psql -d ' . $db . ' -Atc ' . escapeshellarg($q) . ' 2>&1'));
        Comando::riuscito(['createdb', $db], null, null, $amb);
        try {
            $sql('create table t (id int primary key, nome text); insert into t values (1, \'uno\'), (2, \'due\')');
            $u = new Postgres($db, $amb['PGUSER'], [], $amb, true);
            mkdir($this->base . '/bk');
            $this->assertSame(['database.dump'], $u->salva($this->base . '/bk'));
            $u->prova($this->base . '/bk');
            $sql('insert into t values (3, \'tre\'); create table dopo (x int)');
            $u->ripristina($this->base . '/bk');
            $this->assertSame('2', $sql('select count(*) from t'));
            $this->assertStringContainsString('does not exist', $sql('select * from dopo'));
            file_put_contents($this->base . '/bk/database.dump', 'non un dump');
            $this->expectException(Eccezione::class);
            $u->prova($this->base . '/bk');
        } finally {
            Comando::esegui(['dropdb', '--force', '--if-exists', $db], null, null, $amb);
        }
    }

    public function test_destinazione_s3(): void {
        $dir = $this->base . '/s3';
        mkdir($dir);
        $s = stream_socket_server('tcp://127.0.0.1:0');
        $porta = (int) substr(strrchr(stream_socket_get_name($s, false), ':'), 1);
        fclose($s);
        $p = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$porta}", __DIR__ . '/s3finto.php'], [1 => ['file', '/dev/null', 'w'],
            2 => ['file', '/dev/null', 'w']], $t, null, ['PFI_S3_DIR' => $dir]);
        for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $porta); $i++) {
            usleep(100000);
        }
        try {
            $d = new S3("http://127.0.0.1:{$porta}", 'backup-ente', 'eu-south-1', 'AKPROVA', 'segreto', 'pfi', null, true);
            $b = (new Backup($this->base . '/backup', 'r1'))->unita(new Cartella('moodledata', $this->base . '/dati', $this->base))->destinazione($d);
            $id = $b->esegui([], new \DateTimeImmutable('2026-11-10 02:00:00'));
            $this->assertSame([$id], $d->elenco());
            $d->scarica($id, $this->base . '/scaricato');
            Backup::verifica_impronte($this->base . '/scaricato');
            $this->assertFileEquals($b->percorso($id) . '/moodledata.tar.gz', $this->base . '/scaricato/moodledata.tar.gz');
            $d->elimina($id);
            $this->assertSame([], $d->elenco());
            $sbagliata = new S3("http://127.0.0.1:{$porta}", 'backup-ente', 'eu-south-1', 'ALTRA', 'x', 'pfi', null, true);
            $this->expectException(Eccezione::class);
            $sbagliata->elenco();
        } finally {
            proc_terminate($p);
            proc_close($p);
        }
    }

    public function test_solo_https(): void {
        $this->expectException(Eccezione::class);
        new S3('http://s3.example.org', 'contenitore', 'eu-south-1', 'a', 'b');
    }
}
