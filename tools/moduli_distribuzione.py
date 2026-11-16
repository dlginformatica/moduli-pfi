# Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.
# Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva.
"""Repository Composer statico dei moduli universali (src/moduli), per riusarli in altre applicazioni PHP.

Uso (dalla radice del progetto):

    python tools/moduli_distribuzione.py DESTINAZIONE [--url URL] [nome ...]

Per ogni modulo (tutti, o quelli indicati) crea in DESTINAZIONE/dist/ l'archivio pfi-<nome>-<versione>.zip con il
contenuto del modulo (composer.json, modulo.json, LEGGIMI.md, CHANGELOG.md, src/, bin/, avvio.php; senza le prove
e senza il manuale, che si aggiorna anche senza cambiare versione e si pubblica a parte in DESTINAZIONE/manuali/; nel composer.json dell'archivio è scritta la versione), aggiorna DESTINAZIONE/packages.json (repository Composer
di tipo «composer») e DESTINAZIONE/SHA256SUMS.txt, e copia i manuali (e la guida generale, come guida-generale.md e .pdf) in DESTINAZIONE/manuali/.

URL è l'indirizzo da cui le applicazioni leggeranno il repository (per esempio https://server/moduli-pfi/ o
file:///srv/moduli-pfi/): Composer non accetta indirizzi relativi negli archivi, quindi packages.json contiene
URL/dist/<archivio>. Se il repository si sposta basta rigenerare packages.json con il nuovo URL (gli archivi non
cambiano). Senza --url si usa l'indirizzo file:// della destinazione. In alternativa, senza packages.json, la cartella
dist/ si usa come repository di tipo «artifact» (solo da disco).

Le versioni già pubblicate non si riscrivono: se esiste già pfi-<nome>-<versione>.zip con contenuto diverso lo script
si ferma, perché una versione pubblicata è immutabile (per cambiare il codice serve una versione nuova del modulo).
Gli archivi sono riproducibili: stesso contenuto, stessi byte (date fisse, ordine fisso).

Uso nelle applicazioni (composer.json dell'applicazione):

    "repositories": [{"type": "composer", "url": "https://<server>/moduli-pfi/"}],      oppure
    "repositories": [{"type": "artifact", "url": "/percorso/moduli-pfi/dist/"}],
    "require": {"pfi/registro": "^1.0"}
"""
import argparse
import hashlib
import io
import json
import pathlib
import shutil
import sys
import zipfile

RADICE = pathlib.Path(__file__).resolve().parent.parent
MODULI = RADICE / "src" / "moduli"
INCLUSI = ("composer.json", "modulo.json", "LEGGIMI.md", "CHANGELOG.md", "avvio.php")
CARTELLE = ("src", "bin")
DATA_FISSA = (2026, 1, 1, 0, 0, 0)


def file_del_modulo(cartella):
    voci = []
    for nome in INCLUSI:
        if (cartella / nome).is_file():
            voci.append(cartella / nome)
    for nome in CARTELLE:
        base = cartella / nome
        if base.is_dir():
            voci += sorted(p for p in base.rglob("*") if p.is_file() and "__pycache__" not in p.parts)
    return sorted(voci, key=lambda p: p.relative_to(cartella).as_posix())


def archivio(cartella, versione, composer):
    """Zip riproducibile; i file stanno nella cartella pfi-<nome>-<versione>/ come negli archivi di GitHub."""
    nome = cartella.name
    radice = f"pfi-{nome}-{versione}/"
    dati = io.BytesIO()
    with zipfile.ZipFile(dati, "w", zipfile.ZIP_DEFLATED, compresslevel=9) as z:
        for p in file_del_modulo(cartella):
            rel = p.relative_to(cartella).as_posix()
            info = zipfile.ZipInfo(radice + rel, DATA_FISSA)
            eseguibile = rel.startswith("bin/")
            info.external_attr = ((0o755 if eseguibile else 0o644) | 0o100000) << 16
            info.compress_type = zipfile.ZIP_DEFLATED
            contenuto = p.read_bytes()
            if rel == "composer.json":
                contenuto = (json.dumps(composer, ensure_ascii=False, indent=4) + "\n").encode("utf-8")
            z.writestr(info, contenuto)
    return dati.getvalue()


def main():
    a = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    a.add_argument("destinazione")
    a.add_argument("--url", help="indirizzo del repository per le applicazioni (predefinito: file:// della destinazione)")
    a.add_argument("nomi", nargs="*")
    arg = a.parse_args()
    dest = pathlib.Path(arg.destinazione).resolve()
    (dest / "dist").mkdir(parents=True, exist_ok=True)
    (dest / "manuali").mkdir(exist_ok=True)
    indice = dest / "packages.json"
    pacchetti = json.loads(indice.read_text(encoding="utf-8"))["packages"] if indice.exists() else {}
    cartelle = sorted(p for p in MODULI.iterdir() if p.is_dir() and (p / "modulo.json").exists())
    if arg.nomi:
        cartelle = [c for c in cartelle if c.name in arg.nomi]
        mancanti = set(arg.nomi) - {c.name for c in cartelle}
        if mancanti:
            sys.exit(f"moduli inesistenti: {', '.join(sorted(mancanti))}")
    for c in cartelle:
        manifesto = json.loads((c / "modulo.json").read_text(encoding="utf-8"))
        composer = json.loads((c / "composer.json").read_text(encoding="utf-8"))
        nome, versione = manifesto["nome"], manifesto["versione"]
        if composer["name"] != nome:
            sys.exit(f"{c.name}: nome diverso fra modulo.json e composer.json")
        composer_versione = dict(composer)
        composer_versione["version"] = versione
        dati = archivio(c, versione, composer_versione)
        file_zip = dest / "dist" / f"pfi-{c.name}-{versione}.zip"
        if file_zip.exists() and file_zip.read_bytes() != dati:
            sys.exit(f"{file_zip.name} esiste già con contenuto diverso: la versione {versione} di {nome} è già "
                     f"pubblicata e non si riscrive; aumentare la versione in modulo.json e CHANGELOG.md")
        stato = "invariato" if file_zip.exists() else "pubblicato"
        file_zip.write_bytes(dati)
        voce = dict(composer_versione)
        voce["dist"] = {"type": "zip", "url": f"dist/{file_zip.name}", "shasum": hashlib.sha1(dati).hexdigest()}
        pacchetti.setdefault(nome, {})[versione] = voce
        if (c / "MANUALE.md").exists():
            shutil.copy2(c / "MANUALE.md", dest / "manuali" / f"{c.name}.md")
        if (c / "MANUALE.pdf").exists():
            shutil.copy2(c / "MANUALE.pdf", dest / "manuali" / f"{c.name}.pdf")
        print(f"{nome} {versione}: {stato} ({len(dati)} byte)")
    for est in ("md", "pdf"):
        if (MODULI / f"MANUALE-GENERALE.{est}").exists():
            shutil.copy2(MODULI / f"MANUALE-GENERALE.{est}", dest / "manuali" / f"guida-generale.{est}")
    base = (arg.url or dest.as_uri()).rstrip("/") + "/"
    for versioni in pacchetti.values():
        for voce in versioni.values():
            voce["dist"]["url"] = base + "dist/" + voce["dist"]["url"].rsplit("/", 1)[-1]
    ordinati = {n: dict(sorted(v.items())) for n, v in sorted(pacchetti.items())}
    indice.write_text(json.dumps({"packages": ordinati}, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    righe = []
    for p in sorted(dest.rglob("*")):
        if p.is_file() and p.name != "SHA256SUMS.txt":
            righe.append(f"{hashlib.sha256(p.read_bytes()).hexdigest()}  {p.relative_to(dest).as_posix()}")
    (dest / "SHA256SUMS.txt").write_text("\n".join(righe) + "\n", encoding="utf-8")
    print(f"packages.json: {sum(len(v) for v in pacchetti.values())} versioni di {len(pacchetti)} moduli")


if __name__ == "__main__":
    main()
