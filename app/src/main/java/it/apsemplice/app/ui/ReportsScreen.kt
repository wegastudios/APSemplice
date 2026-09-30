@file:OptIn(ExperimentalMaterial3Api::class, ExperimentalCoroutinesApi::class)

package it.apsemplice.app.ui

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.FilterChip
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.unit.dp
import androidx.lifecycle.ViewModel
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import androidx.lifecycle.viewModelScope
import it.apsemplice.app.AppContainer
import it.apsemplice.app.core.Money
import it.apsemplice.app.data.AcademicYearReport
import it.apsemplice.app.data.PeriodReport
import it.apsemplice.app.export.CsvExporter
import it.apsemplice.app.export.Sharing
import kotlinx.coroutines.ExperimentalCoroutinesApi
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.SharingStarted
import kotlinx.coroutines.flow.combine
import kotlinx.coroutines.flow.flatMapLatest
import kotlinx.coroutines.flow.flow
import kotlinx.coroutines.flow.stateIn
import kotlinx.coroutines.launch
import java.time.LocalDate

enum class ReportMode(val label: String) { SOLAR("Anno solare (commercialista)"), ACADEMIC("Anno accademico (attività)") }

class ReportsVm(private val c: AppContainer) : ViewModel() {
    val mode = MutableStateFlow(ReportMode.SOLAR)
    val year = MutableStateFlow(LocalDate.now().year)             // anno solare
    val academicStart = MutableStateFlow(c.reports.currentAcademicYear().startYear)

    private data class Key(val mode: ReportMode, val year: Int, val academic: Int, val tick: Int)

    private val key = combine(mode, year, academicStart, c.repo.observeChanges()) { m, y, a, t -> Key(m, y, a, t) }

    val period = key.flatMapLatest { k ->
        flow {
            emit(if (k.mode == ReportMode.SOLAR) c.reports.periodReport(LocalDate.of(k.year, 1, 1), LocalDate.of(k.year, 12, 31)) else null)
        }
    }.stateIn(viewModelScope, SharingStarted.WhileSubscribed(5000), null as PeriodReport?)

    val academic = key.flatMapLatest { k ->
        flow {
            emit(if (k.mode == ReportMode.ACADEMIC) c.reports.academicYearReport(c.reports.academicYear(k.academic)) else null)
        }
    }.stateIn(viewModelScope, SharingStarted.WhileSubscribed(5000), null as AcademicYearReport?)

    private fun range(): Pair<LocalDate, LocalDate> =
        if (mode.value == ReportMode.SOLAR) LocalDate.of(year.value, 1, 1) to LocalDate.of(year.value, 12, 31)
        else c.reports.academicYear(academicStart.value).let { it.start to it.end }

    /** (nome file, contenuto) della prima nota del periodo selezionato. */
    suspend fun ledgerCsv(): Pair<String, String> {
        val (from, to) = range()
        return "prima-nota-$from-$to.csv" to CsvExporter.ledger(c.repo.rows(from, to))
    }

    suspend fun reportCsv(): Pair<String, String>? {
        val name = c.settings.profile.associationName
        return if (mode.value == ReportMode.SOLAR) {
            val (from, to) = range()
            "rendiconto-$from-$to.csv" to CsvExporter.periodReport(c.reports.periodReport(from, to), name)
        } else {
            val ay = c.reports.academicYear(academicStart.value)
            "attivita-${ay.label.replace('/', '-')}.csv" to CsvExporter.academicYearReport(c.reports.academicYearReport(ay), name)
        }
    }
}

@Composable
fun ReportsScreen() {
    val vm = appViewModel { ReportsVm(it) }
    val mode by vm.mode.collectAsStateWithLifecycle()
    val year by vm.year.collectAsStateWithLifecycle()
    val academicStart by vm.academicStart.collectAsStateWithLifecycle()
    val period by vm.period.collectAsStateWithLifecycle()
    val academic by vm.academic.collectAsStateWithLifecycle()
    val context = LocalContext.current
    val scope = rememberCoroutineScope()

    ScreenScaffold(title = "Report") { padding ->
        Column(
            Modifier.padding(padding).padding(horizontal = 16.dp).verticalScroll(rememberScrollState()),
            verticalArrangement = Arrangement.spacedBy(8.dp),
        ) {
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                ReportMode.entries.forEach { m ->
                    FilterChip(selected = mode == m, onClick = { vm.mode.value = m }, label = { Text(m.label) })
                }
            }
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
                if (mode == ReportMode.SOLAR) {
                    TextButton(onClick = { vm.year.value = year - 1 }) { Text("‹") }
                    Text("Anno $year", modifier = Modifier.padding(top = 12.dp), style = MaterialTheme.typography.titleMedium)
                    TextButton(onClick = { vm.year.value = year + 1 }) { Text("›") }
                } else {
                    TextButton(onClick = { vm.academicStart.value = academicStart - 1 }) { Text("‹") }
                    Text("A.A. ${academic?.year?.label ?: ""}", modifier = Modifier.padding(top = 12.dp), style = MaterialTheme.typography.titleMedium)
                    TextButton(onClick = { vm.academicStart.value = academicStart + 1 }) { Text("›") }
                }
            }

            period?.let { r ->
                Card(Modifier.fillMaxWidth()) {
                    Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                        Text("Saldi dei conti", style = MaterialTheme.typography.titleSmall)
                        r.accounts.forEach { a ->
                            LabeledRow(a.account.name) { Text("${Money.format(a.openingCents)} → ${Money.format(a.closingCents)}") }
                        }
                    }
                }
                Card(Modifier.fillMaxWidth()) {
                    Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                        Text("Entrate", style = MaterialTheme.typography.titleSmall)
                        r.income.forEach { t -> LabeledRow(t.categoryName) { MoneyText(t.cents) } }
                        LabeledRow("Totale entrate") { MoneyText(r.totalIncome, bold = true) }
                        Text("Uscite", style = MaterialTheme.typography.titleSmall, modifier = Modifier.padding(top = 8.dp))
                        r.expenses.forEach { t -> LabeledRow(t.categoryName) { MoneyText(t.cents) } }
                        LabeledRow("Totale uscite") { MoneyText(r.totalExpense, bold = true) }
                        LabeledRow("Avanzo / disavanzo") { MoneyText(r.result, bold = true) }
                    }
                }
            }

            academic?.let { r ->
                Card(Modifier.fillMaxWidth()) {
                    Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                        LabeledRow("Soci iscritti") { Text("${r.membersCount}") }
                        r.activities.forEach { a -> LabeledRow(a.activity.name) { MoneyText(a.marginCents) } }
                        LabeledRow("Entrate generali") { MoneyText(r.generalIncomeTotal) }
                        LabeledRow("Uscite generali") { MoneyText(r.generalExpenseTotal) }
                        LabeledRow("Risultato") { MoneyText(r.result, bold = true) }
                    }
                }
            }

            Button(
                onClick = { scope.launch { vm.reportCsv()?.let { (n, c) -> Sharing.shareCsv(context, n, c) } } },
                modifier = Modifier.fillMaxWidth(),
            ) { Text(if (mode == ReportMode.SOLAR) "Esporta rendiconto (CSV)" else "Esporta report attività (CSV)") }
            Button(
                onClick = { scope.launch { vm.ledgerCsv().let { (n, c) -> Sharing.shareCsv(context, n, c) } } },
                modifier = Modifier.fillMaxWidth().padding(bottom = 16.dp),
            ) { Text("Esporta prima nota (CSV)") }
        }
    }
}
