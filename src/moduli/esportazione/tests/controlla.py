# Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.
# Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva (https://www.gnu.org/licenses/gpl-3.0.html).
# Lettura indipendente dei file esportati (openpyxl, python-docx, odfpy) per le prove: stampa le celle in JSON.
import json, sys
tipo, percorso = sys.argv[1], sys.argv[2]
righe = []
if tipo == 'xlsx':
    import openpyxl
    ws = openpyxl.load_workbook(percorso).active
    righe = [[c.value for c in r] for r in ws.iter_rows()]
    righe.append(['__grassetto__', bool(ws['A1'].font.b), ws.freeze_panes, ws.title])
elif tipo == 'docx':
    import docx
    d = docx.Document(percorso)
    righe = [[c.text for c in r.cells] for r in d.tables[0].rows]
    righe.append(['__titolo__', d.paragraphs[0].text if d.paragraphs else ''])
elif tipo in ('ods', 'odt'):
    from odf.opendocument import load
    from odf import table, text
    from odf.element import Text
    def testo(n):
        s = []
        for p in n.getElementsByType(text.P):
            s.append(''.join(str(x) for x in p.childNodes))
        return '\n'.join(s)
    doc = load(percorso)
    for r in doc.getElementsByType(table.TableRow):
        righe.append([testo(c) for c in r.getElementsByType(table.TableCell)])
print(json.dumps(righe, ensure_ascii=False, default=str))
