package it.apsemplice.app.domain

import it.apsemplice.app.core.SocialYear
import java.time.YearMonth

enum class MonthState { PAID, PARTIAL, UNPAID, ADVANCE }

data class MonthPayment(val month: YearMonth, val dueCents: Long, val paidCents: Long) {
    val state: MonthState
        get() = when {
            dueCents == 0L -> MonthState.ADVANCE // pagato un mese non (ancora) dovuto
            paidCents >= dueCents -> MonthState.PAID
            paidCents > 0 -> MonthState.PARTIAL
            else -> MonthState.UNPAID
        }
    val missingCents: Long get() = (dueCents - paidCents).coerceAtLeast(0)
}

/** Situazione pagamenti di un socio in un'attività per un anno sociale. */
data class PaymentSummary(val months: List<MonthPayment>) {
    val totalDue: Long get() = months.sumOf { it.dueCents }
    val totalPaid: Long get() = months.sumOf { it.paidCents }

    /** Positivo = credito (pagato più del dovuto), negativo = da versare. */
    val balance: Long get() = totalPaid - totalDue
    val isRegular: Boolean get() = balance >= 0
    val unpaidMonths: List<MonthPayment> get() = months.filter { it.state == MonthState.UNPAID || it.state == MonthState.PARTIAL }
}

object PaymentCalc {
    /**
     * Mesi dovuti = da [start] fino a [end] (se cancellato) o fino al mese corrente, dentro l'anno sociale.
     * I pagamenti sono attribuiti al mese di competenza.
     */
    fun compute(
        monthlyFeeCents: Long,
        start: YearMonth,
        end: YearMonth?,
        today: YearMonth,
        year: SocialYear,
        paidByMonth: Map<YearMonth, Long>,
    ): PaymentSummary {
        val lastDue = if (end == null) today else minOf(end, today)
        val months = year.months().mapNotNull { m ->
            val due = if (m >= start && m <= lastDue) monthlyFeeCents else 0L
            val paid = paidByMonth[m] ?: 0L
            if (due == 0L && paid == 0L) null else MonthPayment(m, due, paid)
        }
        return PaymentSummary(months)
    }
}
