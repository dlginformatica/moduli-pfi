# Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.
# Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva.
"""Controllo dei moduli universali in src/moduli (release 9.0, blocco M1).

Uso (dalla radice del progetto):

    python tools/moduli.py [--senza-prove] [nome ...]
    python tools/moduli.py --installa CARTELLA      (copia i moduli in CARTELLA/<nome>/<versione> e sposta «attuale»)

Per ogni modulo controlla: presenza di modulo.json, composer.json, CHANGELOG.md, LEGGIMI.md, phpunit.xml, src/ e tests/;
nome, versione e spazio dei nomi coerenti fra modulo.json e composer.json; prima voce di CHANGELOG.md uguale alla
versione; moduli richiesti presenti fra quelli del progetto e con versione che soddisfa il vincolo (stesse regole di
Pfi\\Contratti\\Versione); riga di attribuzione nei file PHP; poi esegue le prove PHPUnit del modulo (programma phpunit
nel PATH o nella variabile PFI_PHPUNIT). Codice di uscita 0 se tutto è in ordine.
"""
import argparse
import json
import os
import pathlib
import re
import shutil
import subprocess
import sys

RADICE = pathlib.Path(__file__).resolve().parent.parent
MODULI = RADICE / "src" / "moduli"
ATTRIBUZIONE = "Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale."


def versione(testo):
    m = re.fullmatch(r"v?(\d+)(?:\.(\d+))?(?:\.(\d+))?", testo.strip())
    if not m:
        raise ValueError("versione non valida: %s" % testo)
    return tuple(int(x or 0) for x in m.groups())


def soddisfa(v, vincolo):
    for alternativa in vincolo.split("||"):
        parti = alternativa.split()
        if parti and all(soddisfa_semplice(v, p) for p in parti):
            return True
    return False


def soddisfa_semplice(v, p):
    if p == "*":
        return True
    m = re.fullmatch(r"(\^|~|>=|<=|>|<|=)?(.+)", p)
    op, testo = m.group(1) or "", m.group(2)
    b = versione(testo)
    if op == "^":
        return v >= b and (v[0] == b[0] if b[0] > 0 else (v[0] == 0 and v[1] == b[1]))
    if op == "~":
        if len(testo.lstrip("v").split(".")) >= 3:
            return v >= b and v[:2] == b[:2]
        return v >= b and v[0] == b[0]
    return {">=": v >= b, "<=": v <= b, ">": v > b, "<": v < b}.get(op, v == b)


def controlla(cartella, tutti, prove, phpunit):
    errori = []
    for f in ("modulo.json", "composer.json", "CHANGELOG.md", "LEGGIMI.md", "phpunit.xml", "src", "tests"):
        if not (cartella / f).exists():
            errori.append("manca %s" % f)
    if errori:
        return errori
    m = json.loads((cartella / "modulo.json").read_text(encoding="utf-8"))
    c = json.loads((cartella / "composer.json").read_text(encoding="utf-8"))
    if m.get("nome") != c.get("name"):
        errori.append("nome diverso fra modulo.json (%s) e composer.json (%s)" % (m.get("nome"), c.get("name")))
    if m.get("nome") != "pfi/" + cartella.name:
        errori.append("nome %s diverso dalla cartella %s" % (m.get("nome"), cartella.name))
    psr4 = c.get("autoload", {}).get("psr-4", {})
    if psr4.get(m.get("spazio")) != "src/":
        errori.append("spazio dei nomi %s non mappato su src/ in composer.json" % m.get("spazio"))
    if c.get("license") != "GPL-3.0-or-later":
        errori.append("licenza diversa da GPL-3.0-or-later")
    storia = re.search(r"^## (\d+\.\d+\.\d+)", (cartella / "CHANGELOG.md").read_text(encoding="utf-8"), re.M)
    if not storia or storia.group(1) != m.get("versione"):
        errori.append("prima voce di CHANGELOG.md (%s) diversa dalla versione %s" % (storia and storia.group(1), m.get("versione")))
    for nome, vincolo in (m.get("richiede") or {}).items():
        if nome not in tutti:
            errori.append("richiede %s, che non è fra i moduli" % nome)
        elif not soddisfa(versione(tutti[nome]), vincolo):
            errori.append("richiede %s %s, disponibile %s" % (nome, vincolo, tutti[nome]))
    for php in list(cartella.rglob("*.php")):
        if ATTRIBUZIONE not in php.read_text(encoding="utf-8"):
            errori.append("%s senza la riga di attribuzione" % php.relative_to(cartella))
    if prove and not errori:
        if not phpunit:
            errori.append("phpunit non trovato (PATH o PFI_PHPUNIT)")
        else:
            r = subprocess.run([phpunit, "-c", str(cartella / "phpunit.xml")], capture_output=True, text=True, cwd=cartella)
            righe = [x for x in r.stdout.strip().splitlines() if x.strip()]
            if r.returncode != 0:
                errori.append("prove non superate: " + (righe[-1] if righe else r.stderr.strip()[-200:]))
            else:
                return ["prove: " + (righe[-1] if righe else "ok")], []
    return [], errori


def installa(base):
    """Copia ogni modulo in base/<nome>/<versione> (sostituendo la copia di sviluppo) e punta «attuale» alla versione."""
    base.mkdir(parents=True, exist_ok=True)
    for p in sorted(x.parent for x in MODULI.glob("*/modulo.json")):
        d = json.loads((p / "modulo.json").read_text(encoding="utf-8"))
        dest = base / p.name / d["versione"]
        if dest.exists():
            shutil.rmtree(dest)
        shutil.copytree(p, dest, ignore=shutil.ignore_patterns(".phpunit.cache", "tests"))
        link = base / p.name / "attuale"
        tmp = base / p.name / ".attuale-nuovo"
        if tmp.is_symlink() or tmp.exists():
            tmp.unlink()
        tmp.symlink_to(d["versione"])
        os.replace(tmp, link)
        print("%s %s installato in %s" % (d["nome"], d["versione"], dest))
    return 0


def main():
    a = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    a.add_argument("--senza-prove", action="store_true")
    a.add_argument("--installa", metavar="CARTELLA", help="installazione di sviluppo: copia e collegamento «attuale»")
    a.add_argument("nomi", nargs="*")
    arg = a.parse_args()
    if arg.installa:
        return installa(pathlib.Path(arg.installa))
    cartelle = sorted(p.parent for p in MODULI.glob("*/modulo.json"))
    tutti = {}
    for p in cartelle:
        try:
            d = json.loads((p / "modulo.json").read_text(encoding="utf-8"))
            tutti[d.get("nome", "")] = d.get("versione", "0.0.0")
        except ValueError:
            pass
    phpunit = os.environ.get("PFI_PHPUNIT") or shutil.which("phpunit")
    esito = 0
    for p in cartelle:
        if arg.nomi and p.name not in arg.nomi:
            continue
        r = controlla(p, tutti, not arg.senza_prove, phpunit)
        note, errori = r if isinstance(r, tuple) else ([], r)
        print("%s %s: %s" % (p.name, tutti.get("pfi/" + p.name, "?"), "; ".join(errori) if errori else "in ordine" + (" (" + "; ".join(note) + ")" if note else "")))
        esito |= bool(errori)
    if not cartelle:
        print("nessun modulo in %s" % MODULI)
    return esito


if __name__ == "__main__":
    sys.exit(main())
