"""Insert ERP fields into the owner's UPD DOCX without rebuilding its layout."""
import base64
import hashlib
import io
import zipfile
from pathlib import Path
from lxml import etree

ROOT = Path(__file__).resolve().parents[1]
OUT = ROOT / "resources" / "document-templates"
SOURCE = OUT / "client_upd_owner_reference.docx"
NAME = "client_upd_status2_v1.docx"
SOURCE_SHA256 = "00eafa6e333e160f8ed7afa6049e6bf1a3a25b881d70d8e53405e0027d88867f"
NS = {"w": "http://schemas.openxmlformats.org/wordprocessingml/2006/main"}

# Paragraph positions in word/document.xml of the exact supplied reference.
FIELDS = {
    2: "{{upd.number}}", 4: "{{upd.date}}",
    18: "{{company.name}}", 21: "{{client.name}}",
    25: "{{company.legal_address}}", 28: "{{client.legal_address}}",
    34: "{{company.inn_kpp}}", 37: "{{client.inn_kpp}}",
    41: "—", 62: "{{upd.number}} от {{upd.date}}",
    121: "{{service.description}}", 124: "усл.", 125: "1",
    126: "{{service.amount_ru}}", 127: "{{service.amount_ru}}",
    131: "{{service.amount_ru}}", 144: "{{service.amount_ru_compact}}",
    148: "{{service.amount_ru}}",
    153: "{{company.signature_name}}", 157: "",
    183: "Договор субаренды транспортного средства с экипажем № {{contract.number}} от {{contract.date_plain}}",
    189: "Рейс № {{trip.business_id}}",
    199: "{{company.director_position}}", 203: "{{company.signature_name}}",
    227: "{{upd.day}}»", 229: "{{upd.month}}", 232: "{{upd.year_suffix}}",
    262: "{{company.director_position}}", 266: "{{company.signature_name}}",
}

def replace_paragraph(paragraph, value):
    texts = paragraph.xpath(".//w:t", namespaces=NS)
    if not texts:
        run = paragraph.find("w:r", namespaces=NS)
        if run is None:
            run = etree.SubElement(paragraph, "{%s}r" % NS["w"])
        texts = [etree.SubElement(run, "{%s}t" % NS["w"])]
    texts[0].text = value
    for item in texts[1:]:
        item.text = ""

def main():
    source = SOURCE.read_bytes()
    if hashlib.sha256(source).hexdigest() != SOURCE_SHA256:
        raise SystemExit("Owner reference DOCX changed; audit field positions again")
    output = io.BytesIO()
    with zipfile.ZipFile(io.BytesIO(source)) as original, zipfile.ZipFile(output, "w") as rendered:
        for member in original.infolist():
            data = original.read(member.filename)
            if member.filename == "word/document.xml":
                root = etree.fromstring(data)
                paragraphs = root.xpath("//w:body//w:p", namespaces=NS)
                for index, value in FIELDS.items():
                    replace_paragraph(paragraphs[index], value)
                # The supplied total cell is narrow; fit a real amount on one line.
                for run in paragraphs[144].xpath("./w:r", namespaces=NS):
                    props = run.find("w:rPr", namespaces=NS)
                    if props is None:
                        props = etree.Element("{%s}rPr" % NS["w"])
                        run.insert(0, props)
                    size = props.find("w:sz", namespaces=NS)
                    if size is None:
                        size = etree.SubElement(props, "{%s}sz" % NS["w"])
                    size.set("{%s}val" % NS["w"], "10")
                service_row = root.xpath("//w:body//w:tr", namespaces=NS)[14]
                height = service_row.xpath("./w:trPr/w:trHeight", namespaces=NS)[0]
                height.set("{%s}val" % NS["w"], "960")
                height.set("{%s}hRule" % NS["w"], "atLeast")
                data = etree.tostring(root, encoding="UTF-8", xml_declaration=True, standalone=True)
            fixed = zipfile.ZipInfo(member.filename, (1980, 1, 1, 0, 0, 0))
            fixed.compress_type = member.compress_type
            fixed.external_attr = member.external_attr
            rendered.writestr(fixed, data)
    data = output.getvalue()
    encoded = base64.b64encode(data).decode("ascii")
    for index, offset in enumerate(range(0, len(encoded), 60000), 1):
        (OUT / f"{NAME}.b64.part{index:03d}").write_bytes((encoded[offset:offset + 60000] + "\n").encode("ascii"))
    print(f"{NAME} SHA256={hashlib.sha256(data).hexdigest()} parts={(len(encoded) + 59999) // 60000}")

if __name__ == "__main__":
    main()
