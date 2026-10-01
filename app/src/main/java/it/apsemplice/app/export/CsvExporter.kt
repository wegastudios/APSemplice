package it.apsemplice.app.export

import it.apsemplice.app.core.Money
import it.apsemplice.app.core.itFormat
import it.apsemplice.app.data.SocialYearReport
import it.apsemplice.app.data.PeriodReport
import it.apsemplice.app.data.TxRow
import java.time.LocalDate

/** CSV con separatore ";" e decimali con la virgola: si apre correttamente in Excel italiano. */
object CsvExporter {
    private fun cell(s: String?): String {
        val v = s ?: ""
        return if (v.any { it == ';' || it == '"' || it == '\n' }) "\"" + v.replace("\"", "\"\"") + "\"" else v
    }

    private fun line(vararg cells: String?) = cells.joinToString(";") { cell(it) } + "\r\n"

    fun ledger(rows: List<TxRow>): String = buildString {
        append(line("Data", "Tipo", "Conto", "Modalità", "Voce", "Attività", "N. tessera", "Socio", "Descrizione", "Competenza", "Riferimento", "Entrata", "Uscita"))
        for (r in rows) {
            val t = r.tx
            val plus = if (t.type.sign > 0) Money.plain(t.amountCents) else ""
            val minus = if (t.type.sign < 0) Money.plain(t.amountCents) else ""
            append(
                line(
                    LocalDate.parse(t.date).itFormat(), t.type.label, r.accountName, t.method.label,
                    if (t.type.isTransfer) "Giroconto" else r.categoryName,
                    r.activityName, r.memberCard, r.memberName, t.description, t.competenceMonth, t.documentRef, plus, minus,
                ),
            )
        }
    }

    fun periodReport(r: PeriodReport, associationName: String): String = buildString {
        append(line("Rendiconto per cassa", associationName))
        append(line("Periodo", "${r.from.itFormat()} - ${r.to.itFormat()}"))
        append("\r\n")
        append(line("SALDI DEI CONTI", "Iniziale", "Entrate", "Uscite", "Giroconti", "Finale"))
        for (a in r.accounts) {
            append(line(a.account.name, Money.plain(a.openingCents), Money.plain(a.incomeCents), Money.plain(a.expenseCents), Money.plain(a.transfersNetCents), Money.plain(a.closingCents)))
        }
        append(line("Totale", Money.plain(r.openingTotal), Money.plain(r.totalIncome), Money.plain(r.totalExpense), "0,00", Money.plain(r.closingTotal)))
        append("\r\n")
        append(line("ENTRATE", "Voce di rendiconto", "Importo"))
        r.income.forEach { append(line(it.categoryName, it.fiscalGroup, Money.plain(it.cents))) }
        append(line("Totale entrate", "", Money.plain(r.totalIncome)))
        append("\r\n")
        append(line("USCITE", "Voce di rendiconto", "Importo"))
        r.expenses.forEach { append(line(it.categoryName, it.fiscalGroup, Money.plain(it.cents))) }
        append(line("Totale uscite", "", Money.plain(r.totalExpense)))
        append("\r\n")
        append(line("Avanzo / disavanzo", "", Money.plain(r.result)))
    }

    fun socialYearReport(r: SocialYearReport, associationName: String): String = buildString {
        append(line("Valutazione anno sociale", r.year.label, associationName))
        append(line("Soci iscritti", r.membersCount.toString()))
        append("\r\n")
        append(line("ATTIVITÀ", "Iscritti", "Incassi", "Costi", "Resta all'associazione"))
        for (a in r.activities) {
            append(line(a.activity.name, a.participants.toString(), Money.plain(a.incomeCents), Money.plain(a.costCents), Money.plain(a.marginCents)))
        }
        append("\r\n")
        append(line("ENTRATE GENERALI (non di attività)", "", "Importo"))
        r.generalIncome.forEach { append(line(it.categoryName, "", Money.plain(it.cents))) }
        append(line("USCITE GENERALI (non di attività)", "", "Importo"))
        r.generalExpenses.forEach { append(line(it.categoryName, "", Money.plain(it.cents))) }
        append("\r\n")
        append(line("Totale entrate", Money.plain(r.totalIncome)))
        append(line("Totale uscite", Money.plain(r.totalExpense)))
        append(line("Risultato", Money.plain(r.result)))
    }
}
