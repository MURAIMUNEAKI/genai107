import fitz
import sys
import os

sys.stdout.reconfigure(encoding='utf-8')

pdf_path = os.path.join(os.path.dirname(__file__), 'data1.pdf')
out_path = os.path.join(os.path.dirname(__file__), 'data1_extracted.txt')

doc = fitz.open(pdf_path)
with open(out_path, 'w', encoding='utf-8') as f:
    for i, page in enumerate(doc):
        text = page.get_text('text')
        f.write(f'=== PAGE {i+1} ===\n')
        f.write(text)
        f.write('\n\n')
doc.close()
print(f'Done: {len(doc)} pages extracted to {out_path}')
