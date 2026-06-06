import fitz
import json
import os

pdf_path = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'data1.pdf')
out_path = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'data1_extracted.txt')

doc = fitz.open(pdf_path)
pages = []
for i, page in enumerate(doc):
    text = page.get_text('text')
    pages.append(f'=== PAGE {i+1} ===\n{text}')
doc.close()

full_text = '\n\n'.join(pages)
with open(out_path, 'w', encoding='utf-8') as f:
    f.write(full_text)

# Also write a status file
status_path = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'extract_status.txt')
with open(status_path, 'w', encoding='utf-8') as f:
    f.write(f'OK: {len(doc)} pages extracted to {out_path}\n')
    f.write(f'File size: {os.path.getsize(out_path)} bytes\n')
