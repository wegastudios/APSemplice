@file:OptIn(ExperimentalMaterial3Api::class, ExperimentalCoroutinesApi::class)

package it.apsemplice.app.ui

import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Add
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Card
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.FloatingActionButton
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.ListItem
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.dp
import androidx.lifecycle.ViewModel
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import androidx.lifecycle.viewModelScope
import it.apsemplice.app.AppContainer
import it.apsemplice.app.core.Money
import it.apsemplice.app.core.SocialYear
import it.apsemplice.app.core.itFormat
import it.apsemplice.app.data.EnrollmentStatus
import it.apsemplice.app.data.db.ActivityEntity
import it.apsemplice.app.data.db.MemberEntity
import kotlinx.coroutines.ExperimentalCoroutinesApi
import kotlinx.coroutines.flow.SharingStarted
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.combine
import kotlinx.coroutines.flow.map
import kotlinx.coroutines.flow.stateIn
import kotlinx.coroutines.launch
import java.time.YearMonth

class ActivityDetailVm(private val c: AppContainer, private val activityId: String) : ViewModel() {
    private val repo = c.repo
    private val hot = SharingStarted.WhileSubscribed(5000)

    val activity: StateFlow<ActivityEntity?> = repo.observeActivity(activityId).stateIn(viewModelScope, hot, null)
    val members: StateFlow<List<MemberEntity>> = repo.observeMembers().stateIn(viewModelScope, hot, emptyList())
    val statuses: StateFlow<List<EnrollmentStatus>> =
        combine(repo.observeEnrollments(activityId), repo.observeChanges()) { _, _ -> Unit }
            .map { repo.paymentStatusForActivity(activityId) }
            .stateIn(viewModelScope, hot, emptyList())

    fun socialYear(a: ActivityEntity) = SocialYear.fromLabel(a.socialYear, c.settings.profile.socialYearStartMonth)

    fun enroll(memberId: String, start: YearMonth) {
        viewModelScope.launch { repo.enroll(activityId, memberId, start) }
    }

    fun cancel(memberId: String, lastMonth: YearMonth) {
        viewModelScope.launch { repo.cancelEnrollment(activityId, memberId, lastMonth) }
    }
}

@Composable
fun ActivityDetailScreen(activityId: String, onBack: () -> Unit) {
    val vm = appViewModel { ActivityDetailVm(it, activityId) }
    val activity by vm.activity.collectAsStateWithLifecycle()
    val statuses by vm.statuses.collectAsStateWithLifecycle()
    val members by vm.members.collectAsStateWithLifecycle()
    var adding by remember { mutableStateOf(false) }
    var selected by remember { mutableStateOf<EnrollmentStatus?>(null) }

    val a = activity
    val year = a?.let(vm::socialYear)
    val active = statuses.filter { it.enrollment.isActive }
    val due = statuses.sumOf { it.summary.totalDue }
    val paid = statuses.sumOf { it.summary.totalPaid }
    val toCollect = statuses.sumOf { (-it.summary.balance).coerceAtLeast(0) }

    ScreenScaffold(
        title = a?.name ?: "Attività",
        onBack = onBack,
        fab = { FloatingActionButton(onClick = { adding = true }) { Icon(Icons.Filled.Add, "Iscrivi socio") } },
    ) { padding ->
        LazyColumn(
            Modifier.fillMaxSize().padding(padding).padding(horizontal = 16.dp),
            verticalArrangement = Arrangement.spacedBy(8.dp),
        ) {
            item {
                Card(Modifier.fillMaxWidth()) {
                    Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                        Text("Anno sociale ${a?.socialYear ?: ""} · quota mensile ${Money.format(a?.defaultMonthlyFeeCents ?: 0)}")
                        Text("${active.size} iscritti attivi", style = MaterialTheme.typography.bodySmall)
                        LabeledRow("Dovuto finora") { MoneyText(due) }
                        LabeledRow("Incassato") { MoneyText(paid) }
                        LabeledRow("Ancora da incassare") { MoneyText(toCollect, bold = true) }
                    }
                }
            }
            if (statuses.isEmpty()) item { Text("Nessun iscritto. Usa + per iscrivere un socio.") }
            items(statuses, key = { it.enrollment.id }) { s ->
                Card(Modifier.fillMaxWidth().clickable { selected = s }) {
                    Column(Modifier.padding(12.dp)) {
                        Text(s.member.displayLabel(), style = MaterialTheme.typography.titleSmall)
                        if (!s.enrollment.isActive) {
                            Text("Cancellato (fino a ${YearMonth.parse(s.enrollment.endMonth!!).itFormat()})", style = MaterialTheme.typography.bodySmall)
                        }
                        PaymentStatusText(s.summary)
                        if (s.summary.unpaidMonths.isNotEmpty()) {
                            Text("Mesi: " + s.summary.unpaidMonths.joinToString { it.month.itFormat() }, style = MaterialTheme.typography.bodySmall)
                        }
                    }
                }
            }
            item { Text("", modifier = Modifier.padding(bottom = 72.dp)) }
        }
    }

    if (adding && year != null) {
        var start by remember { mutableStateOf(clampToYear(YearMonth.now(), year)) }
        var query by remember { mutableStateOf("") }
        val activeIds = active.map { it.member.id }.toSet()
        AlertDialog(
            onDismissRequest = { adding = false },
            title = { Text("Iscrivi un socio") },
            text = {
                Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    Picker("Dal mese", year.months(), start, { it.itFormat() }, { it?.let { m -> start = m } }, Modifier.fillMaxWidth())
                    TextInput(query, { query = it }, "Cerca", Modifier.fillMaxWidth())
                    LazyColumn(Modifier.heightIn(max = 280.dp)) {
                        items(members.filter { it.id !in activeIds && memberMatches(it, query) }, key = { it.id }) { m ->
                            ListItem(
                                modifier = Modifier.clickable { vm.enroll(m.id, start); adding = false },
                                headlineContent = { Text(m.displayLabel()) },
                            )
                            HorizontalDivider()
                        }
                    }
                }
            },
            confirmButton = {},
            dismissButton = { TextButton(onClick = { adding = false }) { Text("Chiudi") } },
        )
    }

    selected?.let { s ->
        // si rilegge dallo stato corrente, così il dialog si aggiorna dopo l'azione
        val current = statuses.firstOrNull { it.enrollment.id == s.enrollment.id } ?: s
        var month by remember(s.enrollment.id) { mutableStateOf(year?.let { clampToYear(YearMonth.now(), it) } ?: YearMonth.now()) }
        AlertDialog(
            onDismissRequest = { selected = null },
            title = { Text(current.member.displayLabel()) },
            text = {
                Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    PaymentStatusText(current.summary)
                    PaymentMonthsTable(current.summary)
                    if (year != null) {
                        Picker(
                            if (current.enrollment.isActive) "Ultimo mese dovuto" else "Riattiva dal mese",
                            year.months(), month, { it.itFormat() }, { it?.let { m -> month = m } }, Modifier.fillMaxWidth(),
                        )
                        if (current.enrollment.isActive) {
                            OutlinedButton(onClick = { vm.cancel(current.member.id, month); selected = null }, modifier = Modifier.fillMaxWidth()) {
                                Text("Cancella dall'attività")
                            }
                            Text("I mesi successivi non saranno più dovuti. I pagamenti già fatti restano registrati.", style = MaterialTheme.typography.bodySmall)
                        } else {
                            OutlinedButton(onClick = { vm.enroll(current.member.id, month); selected = null }, modifier = Modifier.fillMaxWidth()) {
                                Text("Riattiva iscrizione")
                            }
                        }
                    }
                }
            },
            confirmButton = { TextButton(onClick = { selected = null }) { Text("Chiudi") } },
        )
    }
}
