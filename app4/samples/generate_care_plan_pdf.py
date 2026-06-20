from __future__ import annotations

import re
import sys
from pathlib import Path

from reportlab.lib import colors
from reportlab.lib.enums import TA_LEFT
from reportlab.lib.pagesizes import A4
from reportlab.lib.styles import ParagraphStyle, getSampleStyleSheet
from reportlab.lib.units import mm
from reportlab.pdfbase import pdfmetrics
from reportlab.pdfbase.ttfonts import TTFont
from reportlab.platypus import (
    PageBreak,
    Paragraph,
    SimpleDocTemplate,
    Spacer,
    Table,
    TableStyle,
)


FONT_PATHS = [
    Path(r"C:\Windows\Fonts\meiryo.ttc"),
    Path(r"C:\Windows\Fonts\msgothic.ttc"),
]


def register_japanese_font() -> str:
    for font_path in FONT_PATHS:
        if font_path.exists():
            font_name = font_path.stem.replace(" ", "")
            pdfmetrics.registerFont(TTFont(font_name, str(font_path)))
            return font_name
    raise FileNotFoundError("Japanese font was not found.")


def parse_sections(text: str) -> list[tuple[str, str]]:
    pattern = re.compile(r"^(居宅サービス計画書（第\d表）.*)$", re.MULTILINE)
    matches = list(pattern.finditer(text))
    sections: list[tuple[str, str]] = []
    for index, match in enumerate(matches):
        start = match.end()
        end = matches[index + 1].start() if index + 1 < len(matches) else len(text)
        title = match.group(1).strip()
        body = text[start:end].strip()
        sections.append((title, body))
    return sections


def split_label_value(line: str) -> tuple[str, str] | None:
    if "：" not in line:
        return None
    label, value = line.split("：", 1)
    return label.strip(), value.strip()


def make_paragraph(text: str, style: ParagraphStyle) -> Paragraph:
    safe = (
        text.replace("&", "&amp;")
        .replace("<", "&lt;")
        .replace(">", "&gt;")
        .replace("\n", "<br/>")
    )
    return Paragraph(safe, style)


def section_box(label: str, value: str, label_style: ParagraphStyle, body_style: ParagraphStyle) -> Table:
    table = Table(
        [[make_paragraph(label, label_style), make_paragraph(value, body_style)]],
        colWidths=[50 * mm, 130 * mm],
    )
    table.setStyle(
        TableStyle(
            [
                ("BOX", (0, 0), (-1, -1), 0.7, colors.black),
                ("INNERGRID", (0, 0), (-1, -1), 0.5, colors.black),
                ("BACKGROUND", (0, 0), (0, 0), colors.HexColor("#f1f1f1")),
                ("VALIGN", (0, 0), (-1, -1), "TOP"),
                ("LEFTPADDING", (0, 0), (-1, -1), 6),
                ("RIGHTPADDING", (0, 0), (-1, -1), 6),
                ("TOPPADDING", (0, 0), (-1, -1), 6),
                ("BOTTOMPADDING", (0, 0), (-1, -1), 6),
            ]
        )
    )
    return table


def build_first_table(body: str, styles: dict[str, ParagraphStyle]) -> list:
    lines = [line.strip() for line in body.splitlines() if line.strip()]
    story: list = []

    meta_rows = []
    index = 0
    while index < len(lines):
        if lines[index].endswith("："):
            break
        meta_rows.append(lines[index])
        index += 1

    for row in meta_rows:
        story.append(section_box("基本情報", row, styles["label"], styles["body"]))
        story.append(Spacer(1, 4 * mm))

    current_label = ""
    buffer: list[str] = []
    for line in lines[index:]:
        if line.endswith("："):
            if current_label:
                story.append(
                    section_box(
                        current_label,
                        "\n".join(buffer).strip(),
                        styles["label"],
                        styles["body"],
                    )
                )
                story.append(Spacer(1, 4 * mm))
            current_label = line[:-1]
            buffer = []
        else:
            parsed = split_label_value(line)
            if parsed and not current_label:
                story.append(section_box(parsed[0], parsed[1], styles["label"], styles["body"]))
                story.append(Spacer(1, 4 * mm))
            else:
                buffer.append(line.lstrip("　"))

    if current_label:
        story.append(
            section_box(current_label, "\n".join(buffer).strip(), styles["label"], styles["body"])
        )
    return story


def build_second_table(body: str, styles: dict[str, ParagraphStyle]) -> list:
    lines = [line.strip() for line in body.splitlines() if line.strip()]
    story: list = []
    current_need = ""
    buffer: list[str] = []

    def flush_need() -> None:
        if not current_need:
            return
        rows = [[make_paragraph("課題", styles["label"]), make_paragraph(current_need, styles["body"])]]
        for item in buffer:
            parsed = split_label_value(item.lstrip("　"))
            if parsed:
                rows.append(
                    [
                        make_paragraph(parsed[0], styles["label"]),
                        make_paragraph(parsed[1], styles["body"]),
                    ]
                )
        table = Table(rows, colWidths=[38 * mm, 142 * mm])
        table.setStyle(
            TableStyle(
                [
                    ("BOX", (0, 0), (-1, -1), 0.7, colors.black),
                    ("INNERGRID", (0, 0), (-1, -1), 0.5, colors.black),
                    ("BACKGROUND", (0, 0), (0, -1), colors.HexColor("#f1f1f1")),
                    ("VALIGN", (0, 0), (-1, -1), "TOP"),
                    ("LEFTPADDING", (0, 0), (-1, -1), 6),
                    ("RIGHTPADDING", (0, 0), (-1, -1), 6),
                    ("TOPPADDING", (0, 0), (-1, -1), 6),
                    ("BOTTOMPADDING", (0, 0), (-1, -1), 6),
                ]
            )
        )
        story.append(table)
        story.append(Spacer(1, 5 * mm))

    informal_support = None
    for line in lines:
        if line.startswith("ニーズ"):
            flush_need()
            current_need = split_label_value(line)[1] if split_label_value(line) else line
            buffer = []
        elif line.startswith("インフォーマルサポート："):
            flush_need()
            current_need = ""
            buffer = []
            informal_support = line.split("：", 1)[1].strip()
        else:
            buffer.append(line)
    flush_need()

    if informal_support:
        story.append(section_box("インフォーマルサポート", informal_support, styles["label"], styles["body"]))
    return story


def build_third_table(title: str, body: str, styles: dict[str, ParagraphStyle]) -> list:
    lines = [line.strip() for line in body.splitlines() if line.strip()]
    story: list = []
    if "）" in title:
        subtitle = title.split("）", 1)[1].strip()
        if subtitle:
            story.append(make_paragraph(subtitle, styles["subtitle"]))
            story.append(Spacer(1, 4 * mm))

    schedule_rows = []
    monitoring = ""
    for line in lines:
        parsed = split_label_value(line.lstrip("　"))
        if not parsed:
            continue
        if parsed[0] == "モニタリング":
            monitoring = parsed[1]
        else:
            schedule_rows.append(
                [make_paragraph(parsed[0], styles["label"]), make_paragraph(parsed[1], styles["body"])]
            )

    table = Table(schedule_rows, colWidths=[28 * mm, 152 * mm])
    table.setStyle(
        TableStyle(
            [
                ("BOX", (0, 0), (-1, -1), 0.7, colors.black),
                ("INNERGRID", (0, 0), (-1, -1), 0.5, colors.black),
                ("BACKGROUND", (0, 0), (0, -1), colors.HexColor("#f1f1f1")),
                ("VALIGN", (0, 0), (-1, -1), "TOP"),
                ("LEFTPADDING", (0, 0), (-1, -1), 6),
                ("RIGHTPADDING", (0, 0), (-1, -1), 6),
                ("TOPPADDING", (0, 0), (-1, -1), 6),
                ("BOTTOMPADDING", (0, 0), (-1, -1), 6),
            ]
        )
    )
    story.append(table)
    story.append(Spacer(1, 5 * mm))

    if monitoring:
        story.append(section_box("モニタリング", monitoring, styles["label"], styles["body"]))
    return story


def build_pdf(input_path: Path, output_path: Path) -> None:
    font_name = register_japanese_font()
    stylesheet = getSampleStyleSheet()
    styles = {
        "title": ParagraphStyle(
            "title",
            parent=stylesheet["Title"],
            fontName=font_name,
            fontSize=16,
            leading=20,
            alignment=TA_LEFT,
            spaceAfter=6 * mm,
        ),
        "subtitle": ParagraphStyle(
            "subtitle",
            parent=stylesheet["Heading2"],
            fontName=font_name,
            fontSize=11,
            leading=14,
            spaceAfter=2 * mm,
        ),
        "label": ParagraphStyle(
            "label",
            parent=stylesheet["BodyText"],
            fontName=font_name,
            fontSize=9.5,
            leading=13,
        ),
        "body": ParagraphStyle(
            "body",
            parent=stylesheet["BodyText"],
            fontName=font_name,
            fontSize=9.5,
            leading=14,
        ),
    }

    text = input_path.read_text(encoding="utf-8")
    sections = parse_sections(text)
    story: list = []

    for idx, (title, body) in enumerate(sections):
        story.append(make_paragraph(title, styles["title"]))
        if "第1表" in title:
            story.extend(build_first_table(body, styles))
        elif "第2表" in title:
            story.extend(build_second_table(body, styles))
        elif "第3表" in title:
            story.extend(build_third_table(title, body, styles))

        if idx < len(sections) - 1:
            story.append(PageBreak())

    doc = SimpleDocTemplate(
        str(output_path),
        pagesize=A4,
        leftMargin=15 * mm,
        rightMargin=15 * mm,
        topMargin=15 * mm,
        bottomMargin=15 * mm,
        title=input_path.stem,
        author="OpenAI Codex",
    )
    doc.build(story)


def main() -> int:
    if len(sys.argv) not in {2, 3}:
        print("Usage: generate_care_plan_pdf.py <input.txt> [output.pdf]")
        return 1

    input_path = Path(sys.argv[1]).resolve()
    output_path = Path(sys.argv[2]).resolve() if len(sys.argv) == 3 else input_path.with_suffix(".pdf")
    build_pdf(input_path, output_path)
    print(output_path)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
