import os
import sys
from pypdf import PdfReader


def read_text(path: str) -> str:
    ext = os.path.splitext(path)[1].lower()
    if ext == ".pdf":
        reader = PdfReader(path)
        return "\n".join((page.extract_text() or "") for page in reader.pages)
    with open(path, "r", encoding="utf-8", errors="ignore") as fh:
        return fh.read()


if __name__ == "__main__":
    if len(sys.argv) != 2:
        raise SystemExit("usage: extract_document_text.py <file>")
    print(read_text(sys.argv[1]))
