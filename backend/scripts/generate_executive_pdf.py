import json
import os
import sys
from reportlab.lib import colors
from reportlab.lib.pagesizes import A4
from reportlab.lib.styles import getSampleStyleSheet, ParagraphStyle
from reportlab.lib.units import mm
from reportlab.platypus import SimpleDocTemplate, Paragraph, Spacer, Table, TableStyle


def money(value):
    return f"${float(value):,.0f}"


def build_pdf(data, output_path):
    os.makedirs(os.path.dirname(output_path), exist_ok=True)
    doc = SimpleDocTemplate(output_path, pagesize=A4, leftMargin=16 * mm, rightMargin=16 * mm, topMargin=16 * mm, bottomMargin=16 * mm)
    styles = getSampleStyleSheet()
    styles.add(ParagraphStyle(name="SectionTitle", parent=styles["Heading2"], fontName="Helvetica-Bold", fontSize=13, leading=16, textColor=colors.HexColor("#0f172a"), spaceAfter=8))
    styles.add(ParagraphStyle(name="Metric", parent=styles["BodyText"], fontName="Helvetica", fontSize=10, leading=14, textColor=colors.HexColor("#334155")))

    story = []
    story.append(Paragraph(f"Reporte Ejecutivo - {data['company']['nombre']}", styles["Title"]))
    story.append(Paragraph(f"Generado: {data['generated_at']}", styles["Metric"]))
    story.append(Spacer(1, 8))

    summary = data["summary"]
    summary_table = Table([
        ["Leads activos", "Contratados mes", "Calificados mes", "Entrevistas 7d"],
        [str(summary["active_leads"]), str(summary["hires_month"]), str(summary["qualified_month"]), str(summary["interviews_week"])],
    ], colWidths=[42 * mm, 42 * mm, 42 * mm, 42 * mm])
    summary_table.setStyle(TableStyle([
        ("BACKGROUND", (0, 0), (-1, 0), colors.HexColor("#e2e8f0")),
        ("TEXTCOLOR", (0, 0), (-1, 0), colors.HexColor("#0f172a")),
        ("GRID", (0, 0), (-1, -1), 0.5, colors.HexColor("#cbd5e1")),
        ("FONTNAME", (0, 0), (-1, 0), "Helvetica-Bold"),
        ("FONTNAME", (0, 1), (-1, 1), "Helvetica"),
        ("ALIGN", (0, 0), (-1, -1), "CENTER"),
        ("PADDING", (0, 0), (-1, -1), 6),
    ]))
    story.append(summary_table)
    story.append(Spacer(1, 12))

    forecast = data["forecast"]
    story.append(Paragraph("Forecast de contratación", styles["SectionTitle"]))
    story.append(Paragraph(
        f"Conversión calificado -> contratado: {forecast['conversion_qualified_to_hire_pct']}%<br/>"
        f"Pipeline calificado activo: {forecast['active_qualified_pipeline']}<br/>"
        f"Proyección próximas 4 semanas: {forecast['projected_hires_next_30d']} contrataciones<br/>"
        f"Supuesto: {forecast['assumption']}",
        styles["Metric"],
    ))
    story.append(Spacer(1, 12))

    story.append(Paragraph("Finanzas por vacante", styles["SectionTitle"]))
    finance_rows = [["Vacante", "Salario semanal", "Leads activos", "Contratados mes", "Payroll semanal proyectado"]]
    for item in data["finance"]:
        finance_rows.append([
            item["vacancy"],
            money(item["weekly_salary"]),
            str(item["active_leads"]),
            str(item["hires_month"]),
            money(item["projected_weekly_payroll"]),
        ])
    finance_table = Table(finance_rows, colWidths=[42 * mm, 32 * mm, 26 * mm, 28 * mm, 44 * mm])
    finance_table.setStyle(TableStyle([
        ("BACKGROUND", (0, 0), (-1, 0), colors.HexColor("#dbeafe")),
        ("GRID", (0, 0), (-1, -1), 0.5, colors.HexColor("#cbd5e1")),
        ("FONTNAME", (0, 0), (-1, 0), "Helvetica-Bold"),
        ("FONTNAME", (0, 1), (-1, -1), "Helvetica"),
        ("PADDING", (0, 0), (-1, -1), 5),
    ]))
    story.append(finance_table)
    story.append(Spacer(1, 12))

    story.append(Paragraph("Multi-sede / franquicia", styles["SectionTitle"]))
    site_rows = [["Sede", "Ciudad", "Leads", "Contratados", "Entrevistas pendientes"]]
    for site in data["sites"]:
        site_rows.append([
            site["nombre"],
            site["ciudad"] or "-",
            str(site["leads_total"]),
            str(site["hires_total"]),
            str(site["interviews_pending"]),
        ])
    site_table = Table(site_rows, colWidths=[50 * mm, 45 * mm, 24 * mm, 28 * mm, 34 * mm])
    site_table.setStyle(TableStyle([
        ("BACKGROUND", (0, 0), (-1, 0), colors.HexColor("#dcfce7")),
        ("GRID", (0, 0), (-1, -1), 0.5, colors.HexColor("#cbd5e1")),
        ("FONTNAME", (0, 0), (-1, 0), "Helvetica-Bold"),
        ("FONTNAME", (0, 1), (-1, -1), "Helvetica"),
        ("PADDING", (0, 0), (-1, -1), 5),
    ]))
    story.append(site_table)

    doc.build(story)


def main():
    if len(sys.argv) != 3:
        raise SystemExit("usage: generate_executive_pdf.py <input.json> <output.pdf>")
    with open(sys.argv[1], "r", encoding="utf-8") as fh:
        data = json.load(fh)
    build_pdf(data, sys.argv[2])


if __name__ == "__main__":
    main()
