# Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.
# Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva.
"""Esempi dei manuali dei moduli universali: li esegue in un progetto pulito e li copia nei manuali.

Uso (dalla radice del progetto, dopo tools/moduli_distribuzione.py):

    python tools/moduli_esempi.py REPOSITORY [--controlla] [nome ...]

REPOSITORY è la cartella creata da tools/moduli_distribuzione.py. Per ogni modulo con esempi in
src/moduli/<nome>/esempi/*.php lo script crea un progetto vuoto in una cartella temporanea, installa con Composer
solo pfi/<nome> dal repository (senza rete: Packagist è disattivato), copia l'esempio ed esegue «php esempio.php» con il
PHP del sistema. L'uscita deve coincidere con esempi/<esempio>.atteso. Poi copia il codice e l'uscita nel MANUALE.md del
modulo fra i segnaposto «<!-- esempio:<file>:inizio -->» e «<!-- esempio:<file>:fine -->».
Con --controlla non scrive: esce con codice 1 se un esempio fallisce o se il manuale non riporta l'esempio attuale.

Negli esempi, %TMP% nell'uscita attesa indica la cartella temporanea del progetto.
"""
import argparse
import json
import os
import pathlib
import re
import shutil
import subprocess
import sys
import tempfile

RADICE = pathlib.Path(__file__).resolve().parent.parent
MODULI = RADICE / "src" / "moduli"


def blocco(file, codice, uscita):
    return (f"```php\n{codice.rstrip()}\n```\n\nUscita (verificata eseguendo l'esempio in un progetto pulito):\n\n"
            f"```text\n{uscita.rstrip()}\n```\n")


def main():
    a = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    a.add_argument("repository")
    a.add_argument("--controlla", action="store_true")
    a.add_argument("nomi", nargs="*")
    arg = a.parse_args()
    repo = pathlib.Path(arg.repository).resolve()
    if not (repo / "packages.json").exists():
        sys.exit(f"{repo}: packages.json assente (eseguire prima tools/moduli_distribuzione.py)")
    errori = 0
    for cartella in sorted(p for p in MODULI.iterdir() if (p / "modulo.json").exists()):
        if arg.nomi and cartella.name not in arg.nomi:
            continue
        esempi = sorted((cartella / "esempi").glob("*.php")) if (cartella / "esempi").is_dir() else []
        if not esempi:
            print(f"{cartella.name}: nessun esempio")
            continue
        nome = json.loads((cartella / "modulo.json").read_text(encoding="utf-8"))["nome"]
        manuale_file = cartella / "MANUALE.md"
        manuale = manuale_file.read_text(encoding="utf-8")
        with tempfile.TemporaryDirectory(prefix=f"esempio-{cartella.name}-") as tmp:
            progetto = pathlib.Path(tmp)
            (progetto / "composer.json").write_text(json.dumps({
                "name": "esempio/applicazione", "type": "project",
                "repositories": [{"type": "composer", "url": repo.as_uri() + "/"}, {"packagist.org": False}],
                "require": {nome: "*"}}, indent=2), encoding="utf-8")
            env = dict(os.environ, COMPOSER_ALLOW_SUPERUSER="1", COMPOSER_DISABLE_NETWORK="1",
                       COMPOSER_HOME=str(progetto / ".composer"))
            r = subprocess.run(["composer", "install", "--no-interaction", "--quiet"], cwd=progetto, env=env,
                               capture_output=True, text=True)
            if r.returncode:
                print(f"{cartella.name}: composer install non riuscito\n{r.stdout}{r.stderr}")
                errori += 1
                continue
            for esempio in esempi:
                shutil.copy2(esempio, progetto / esempio.name)
                r = subprocess.run(["php", esempio.name], cwd=progetto, capture_output=True, text=True, timeout=120)
                uscita = (r.stdout + (r.stderr if r.returncode else "")).replace(str(progetto), "%TMP%")
                atteso_file = esempio.with_suffix(".atteso")
                atteso = atteso_file.read_text(encoding="utf-8") if atteso_file.exists() else None
                if r.returncode:
                    print(f"{cartella.name}/{esempio.name}: codice di uscita {r.returncode}\n{uscita}")
                    errori += 1
                    continue
                if atteso is None:
                    if arg.controlla:
                        print(f"{cartella.name}/{esempio.name}: manca {atteso_file.name}")
                        errori += 1
                        continue
                    atteso_file.write_text(uscita, encoding="utf-8")
                    atteso = uscita
                    print(f"{cartella.name}/{esempio.name}: uscita registrata in {atteso_file.name} (da rileggere)")
                elif uscita != atteso:
                    print(f"{cartella.name}/{esempio.name}: uscita diversa dall'attesa\n--- attesa\n{atteso}--- ottenuta\n{uscita}")
                    errori += 1
                    continue
                codice = esempio.read_text(encoding="utf-8")
                testo = blocco(esempio.name, codice, atteso)
                re_seg = re.compile(rf"(<!-- esempio:{re.escape(esempio.name)}:inizio -->\n)(.*?)(<!-- esempio:{re.escape(esempio.name)}:fine -->)", re.S)
                m = re_seg.search(manuale)
                if not m:
                    print(f"{cartella.name}/{esempio.name}: segnaposto assenti in MANUALE.md")
                    errori += 1
                    continue
                if m.group(2) != testo:
                    if arg.controlla:
                        print(f"{cartella.name}/{esempio.name}: il manuale non riporta l'esempio attuale")
                        errori += 1
                        continue
                    manuale = manuale[:m.start(2)] + testo + manuale[m.end(2):]
                print(f"{cartella.name}/{esempio.name}: eseguito, uscita come attesa")
        if not arg.controlla:
            manuale_file.write_text(manuale, encoding="utf-8")
    sys.exit(1 if errori else 0)


if __name__ == "__main__":
    main()
