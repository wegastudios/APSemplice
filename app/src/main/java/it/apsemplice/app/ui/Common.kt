package it.apsemplice.app.ui

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import it.apsemplice.app.core.Money
import it.apsemplice.app.core.SocialYear
import it.apsemplice.app.core.itFormat
import it.apsemplice.app.data.db.MemberEntity
import it.apsemplice.app.domain.MonthState
import it.apsemplice.app.domain.PaymentSummary
import java.time.YearMonth

/** "n.12 · Mario Rossi" oppure solo il nome se il socio non ha ancora la tessera. */
fun MemberEntity.displayLabel(): String = (cardNumber?.let { "n.$it · " } ?: "") + fullName

fun memberMatches(m: MemberEntity, query: String): Boolean {
    val q = query.trim()
    return q.isEmpty() || listOf(m.fullName, "${m.lastName} ${m.firstName}", m.cardNumber, m.taxCode)
        .any { it?.contains(q, ignoreCase = true) == true }
}

/** Porta un mese dentro i limiti dell'anno sociale (es. oggi è luglio e l'anno sociale è già finito). */
fun clampToYear(month: YearMonth, year: SocialYear): YearMonth {
    val months = year.months()
    return when {
        month < months.first() -> months.first()
        month > months.last() -> months.last()
        else -> month
    }
}

@Composable
fun PaymentStatusText(summary: PaymentSummary, modifier: Modifier = Modifier) {
    val color = if (summary.isRegular) MaterialTheme.colorScheme.primary else MaterialTheme.colorScheme.error
    val text = when {
        summary.balance < 0 -> "Da versare ${Money.format(-summary.balance)}"
        summary.balance > 0 -> "In regola (credito ${Money.format(summary.balance)})"
        else -> "In regola"
    }
    Text(text, color = color, fontWeight = FontWeight.SemiBold, modifier = modifier)
}

@Composable
fun PaymentMonthsTable(summary: PaymentSummary) {
    if (summary.months.isEmpty()) {
        Text("Nessuna mensilità dovuta finora.", style = MaterialTheme.typography.bodySmall)
        return
    }
    Column(verticalArrangement = Arrangement.spacedBy(2.dp)) {
        summary.months.forEach { m ->
            val (label, color) = when (m.state) {
                MonthState.PAID -> "pagato" to MaterialTheme.colorScheme.primary
                MonthState.PARTIAL -> "parziale" to MaterialTheme.colorScheme.tertiary
                MonthState.UNPAID -> "da pagare" to MaterialTheme.colorScheme.error
                MonthState.ADVANCE -> "versato (fuori periodo)" to MaterialTheme.colorScheme.secondary
            }
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
                Text(m.month.itFormat(), style = MaterialTheme.typography.bodyMedium)
                Text("${Money.format(m.paidCents)} / ${Money.format(m.dueCents)} · $label", color = color, style = MaterialTheme.typography.bodySmall)
            }
        }
    }
}

