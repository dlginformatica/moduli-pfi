#!/usr/bin/env php
<?php
// Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.
// Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva (https://www.gnu.org/licenses/gpl-3.0.html).
//
// Riferimento dell'interfaccia pubblica dei moduli universali, estratto dal codice con la riflessione di PHP.
//
//   php tools/moduli_riferimento.php [--controlla] [nome ...]
//
// Per ogni modulo scrive in src/moduli/<nome>/MANUALE.md, fra i segnaposto
//   <!-- riferimento:inizio --> e <!-- riferimento:fine -->
// l'elenco di classi, interfacce ed enumerazioni del modulo con costanti, proprietà pubbliche, metodi pubblici
// (firma completa, prima riga del commento, eccezioni dichiarate). Con --controlla non scrive: esce con codice 1 se
// il riferimento nel manuale non corrisponde al codice. Il resto del manuale non viene toccato.

declare(strict_types=1);

$radice = dirname(__DIR__) . '/src/moduli';
require_once "{$radice}/contratti/avvio.php";
foreach (glob("{$radice}/*", GLOB_ONLYDIR) as $c) {
    \Pfi\Contratti\Caricatore::registra_cartella($c);
}

$args = array_slice($argv, 1);
$controlla = in_array('--controlla', $args, true);
$nomi = array_values(array_filter($args, fn($a) => $a !== '--controlla'));
$diversi = 0;

foreach (glob("{$radice}/*", GLOB_ONLYDIR) as $cartella) {
    $nome = basename($cartella);
    if ($nomi && !in_array($nome, $nomi, true)) {
        continue;
    }
    $manifesto = json_decode(file_get_contents("{$cartella}/modulo.json"), true, flags: JSON_THROW_ON_ERROR);
    $spazio = rtrim($manifesto['spazio'], '\\');
    $classi = [];
    $file = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("{$cartella}/src", FilesystemIterator::SKIP_DOTS));
    foreach ($file as $f) {
        if ($f->getExtension() !== 'php') {
            continue;
        }
        $rel = substr($f->getPathname(), strlen("{$cartella}/src/"), -4);
        $classi[] = $spazio . '\\' . str_replace('/', '\\', $rel);
    }
    sort($classi);
    $testo = riferimento($classi, $spazio);
    $manuale = "{$cartella}/MANUALE.md";
    if (!is_file($manuale)) {
        fwrite(STDERR, "{$nome}: MANUALE.md assente\n");
        $diversi++;
        continue;
    }
    $attuale = file_get_contents($manuale);
    $re = '/(<!-- riferimento:inizio -->\n)(.*?)(<!-- riferimento:fine -->)/s';
    if (!preg_match($re, $attuale, $m)) {
        fwrite(STDERR, "{$nome}: segnaposto del riferimento assenti in MANUALE.md\n");
        $diversi++;
        continue;
    }
    if ($m[2] === $testo) {
        echo "{$nome}: riferimento allineato (" . count($classi) . " tipi)\n";
        continue;
    }
    if ($controlla) {
        echo "{$nome}: riferimento NON allineato al codice\n";
        $diversi++;
        continue;
    }
    file_put_contents($manuale, preg_replace_callback($re, fn($x) => $x[1] . $testo . $x[3], $attuale, 1));
    echo "{$nome}: riferimento aggiornato (" . count($classi) . " tipi)\n";
}
exit($diversi ? 1 : 0);

function riferimento(array $classi, string $spazio): string {
    $out = '';
    foreach ($classi as $nome) {
        if (!class_exists($nome) && !interface_exists($nome) && !enum_exists($nome) && !trait_exists($nome)) {
            throw new RuntimeException("Tipo non caricabile: {$nome}");
        }
        $r = new ReflectionClass($nome);
        $breve = substr($nome, strlen($spazio) + 1);
        $tipo = $r->isEnum() ? 'enumerazione' : ($r->isInterface() ? 'interfaccia' : ($r->isTrait() ? 'tratto'
            : (($r->isFinal() ? 'classe finale' : ($r->isAbstract() ? 'classe astratta' : 'classe')))));
        $out .= "\n#### `{$breve}`\n\n";
        $extra = [];
        if ($r->getParentClass()) {
            $extra[] = 'estende `' . $r->getParentClass()->getName() . '`';
        }
        $interfacce = array_values(array_filter($r->getInterfaceNames(), fn($i) => !in_array($i, ['UnitEnum', 'BackedEnum', 'Stringable'], true)));
        if ($interfacce) {
            $extra[] = 'implementa ' . implode(', ', array_map(fn($i) => "`{$i}`", $interfacce));
        }
        $out .= ucfirst($tipo) . ($extra ? ', ' . implode('; ', $extra) : '') . ".";
        $doc = descrizione($r->getDocComment());
        $out .= $doc !== '' ? " {$doc}\n" : "\n";
        if ($r->isEnum()) {
            $casi = array_map(fn($c) => '`' . $c->getName() . ($c instanceof ReflectionEnumBackedCase ? ' = ' . var_export($c->getBackingValue(), true) : '') . '`', (new ReflectionEnum($nome))->getCases());
            $out .= "\nCasi: " . implode(', ', $casi) . ".\n";
        }
        $costanti = [];
        foreach ($r->getReflectionConstants() as $c) {
            if ($c->isPublic() && $c->getDeclaringClass()->getName() === $nome && !($r->isEnum() && $c->isEnumCase())) {
                $costanti[] = '`' . $c->getName() . ' = ' . valore($c->getValue()) . '`';
            }
        }
        if ($costanti) {
            $out .= "\nCostanti: " . implode(', ', $costanti) . ".\n";
        }
        $proprieta = [];
        foreach ($r->getProperties(ReflectionProperty::IS_PUBLIC) as $p) {
            if ($p->getDeclaringClass()->getName() !== $nome || $p->isStatic() && $r->isEnum()) {
                continue;
            }
            $proprieta[] = '`' . ($p->isReadOnly() ? 'readonly ' : '') . ($p->getType() ? $p->getType() . ' ' : '') . '$' . $p->getName() . '`';
        }
        if ($proprieta) {
            $out .= "\nProprietà pubbliche: " . implode(', ', $proprieta) . ".\n";
        }
        $metodi = array_filter($r->getMethods(ReflectionMethod::IS_PUBLIC), fn($m) => $m->getDeclaringClass()->getName() === $nome
            && !in_array($m->getName(), ['cases', 'from', 'tryFrom'], true));
        usort($metodi, fn($a, $b) => [$a->getName() !== '__construct', $a->getStartLine()] <=> [$b->getName() !== '__construct', $b->getStartLine()]);
        if ($metodi) {
            $out .= "\n| Metodo | Descrizione |\n|---|---|\n";
            foreach ($metodi as $m) {
                $commento = $m->getDocComment();
                if ($commento === false) {
                    try {
                        $commento = $m->getPrototype()->getDocComment();
                    } catch (ReflectionException) {
                    }
                }
                $d = descrizione($commento);
                $lancia = lancia($commento);
                if ($lancia) {
                    $d .= ($d !== '' ? ' ' : '') . 'Lancia ' . implode(', ', $lancia) . '.';
                }
                $out .= '| `' . str_replace('|', '\|', firma($m)) . '` | ' . str_replace('|', '\|', $d) . " |\n";
            }
        }
    }
    return ltrim($out, "\n") . "\n";
}

function firma(ReflectionMethod $m): string {
    $p = [];
    foreach ($m->getParameters() as $x) {
        $s = '';
        if ($x->isPromoted()) {
            $s .= 'promossa ';
        }
        if ($x->getType()) {
            $s .= $x->getType() . ' ';
        }
        if ($x->isVariadic()) {
            $s .= '...';
        }
        $s .= ($x->isPassedByReference() ? '&' : '') . '$' . $x->getName();
        if ($x->isDefaultValueAvailable()) {
            $s .= ' = ' . predefinito($x);
        }
        $p[] = $s;
    }
    $prefisso = $m->isStatic() ? 'static ' : '';
    $ritorno = $m->hasReturnType() ? ': ' . $m->getReturnType() : '';
    return $prefisso . $m->getName() . '(' . implode(', ', $p) . ')' . $ritorno;
}

function predefinito(ReflectionParameter $x): string {
    if ($x->isDefaultValueConstant()) {
        $c = $x->getDefaultValueConstantName();
        return str_contains($c, '\\') ? substr($c, strrpos($c, '\\') + 1) : $c;
    }
    try {
        return valore($x->getDefaultValue());
    } catch (Throwable) {
        return '…';
    }
}

function valore(mixed $v): string {
    if (is_object($v)) {
        return 'new ' . (new ReflectionClass($v))->getShortName() . '()';
    }
    if (is_array($v)) {
        if ($v === []) {
            return '[]';
        }
        $lista = array_is_list($v);
        $voci = [];
        foreach ($v as $k => $x) {
            $voci[] = ($lista ? '' : var_export($k, true) . ' => ') . valore($x);
        }
        $t = '[' . implode(', ', $voci) . ']';
        return strlen($t) > 160 ? '[' . count($v) . ' voci]' : $t;
    }
    if ($v === null) {
        return 'null';
    }
    if (is_string($v) && preg_match('/[\x00-\x1f]/', $v)) {
        // Caratteri di controllo (per esempio "\r\n") nella forma di PHP fra virgolette doppie, su una sola riga.
        return '"' . addcslashes($v, "\0..\37\"\\$") . '"';
    }
    return str_replace("\n", ' ', var_export($v, true));
}

function descrizione(string|false $doc): string {
    if ($doc === false) {
        return '';
    }
    $righe = [];
    foreach (preg_split('/\R/', $doc) as $r) {
        $r = trim(preg_replace('/^\s*(\/\*\*|\*\/|\*)\s?/', '', $r));
        $r = trim(preg_replace('/\s*\*\/\s*$/', '', $r));
        if ($r === '' && $righe) {
            break;
        }
        if ($r === '' || str_starts_with($r, '@')) {
            if (str_starts_with($r, '@')) {
                break;
            }
            continue;
        }
        $righe[] = $r;
    }
    // «e così via» è fra le frasi che i controlli dei documenti (tools/documenti.py) vietano: nel manuale diventa «eccetera».
    return str_replace('e così via', 'eccetera', trim(implode(' ', $righe)));
}

function lancia(string|false $doc): array {
    if ($doc === false || !preg_match_all('/@throws\s+(\S+)/', $doc, $m)) {
        return [];
    }
    return array_map(fn($x) => '`' . ltrim($x, '\\') . '`', array_unique($m[1]));
}
