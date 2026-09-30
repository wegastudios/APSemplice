@file:OptIn(ExperimentalMaterial3Api::class, ExperimentalCoroutinesApi::class)

package it.apsemplice.app.ui

import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Add
import androidx.compose.material3.Card
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.FloatingActionButton
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
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
import it.apsemplice.app.data.SocialYearReport
import kotlinx.coroutines.ExperimentalCoroutinesApi
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.SharingStarted
import kotlinx.coroutines.flow.combine
import kotlinx.coroutines.flow.flatMapLatest
import kotlinx.coroutines.flow.map
import kotlinx.coroutines.flow.stateIn
import kotlinx.coroutines.launch

class ActivitiesVm(private val c: AppContainer) : ViewModel() {
    val startYear = MutableStateFlow(c.reports.currentSocialYear().startYear)
    private fun sy(startYear: Int) = c.reports.socialYear(startYear)

    val members = c.repo.observeMembers().stateIn(viewModelScope, SharingStarted.WhileSubscribed(5000), emptyList())

    // Si ricalcola quando cambiano le attività, la prima nota o le iscrizioni.
    val report = startYear.flatMapLatest { y ->
        combine(c.repo.observeActivities(sy(y).label), c.repo.observeChanges(), c.repo.observeEnrollmentChanges()) { _, _, _ -> y }
            .map { c.reports.socialYearReport(sy(it)) }
    }.stateIn(viewModelScope, SharingStarted.WhileSubscribed(5000), null as SocialYearReport?)

    fun addActivity(name: String, feeCents: Long, instructorId: String?) {
        viewModelScope.launch { c.repo.saveActivity(name, sy(startYear.value).label, feeCents, instructorId) }
    }
}

@Composable
fun ActivitiesScreen(onOpen: (String) -> Unit) {
    val vm = appViewModel { ActivitiesVm(it) }
    val startYear by vm.startYear.collectAsStateWithLifecycle()
    val report by vm.report.collectAsStateWithLifecycle()
    val members by vm.members.collectAsStateWithLifecycle()
    var adding by remember { mutableStateOf(false) }
    val label = report?.year?.label ?: ""

    ScreenScaffold(
        title = "Attività",
        fab = { FloatingActionButton(onClick = { adding = true }) { Icon(Icons.Filled.Add, "Nuova attività") } },
    ) { padding ->
        Column(Modifier.padding(padding)) {
            Row(Modifier.fillMaxWidth().padding(horizontal = 8.dp), horizontalArrangement = Arrangement.SpaceBetween) {
                TextButton(onClick = { vm.startYear.value = startYear - 1 }) { Text("‹") }
                Text("Anno sociale $label", modifier = Modifier.padding(top = 12.dp), style = MaterialTheme.typography.titleMedium)
                TextButton(onClick = { vm.startYear.value = startYear + 1 }) { Text("›") }
            }
            LazyColumn(
                Modifier.fillMaxSize().padding(horizontal = 16.dp),
                verticalArrangement = Arrangement.spacedBy(8.dp),
            ) {
                val list = report?.activities.orEmpty()
                if (list.isEmpty()) item { Text("Nessuna attività in questo anno. Aggiungine una con +.") }
                items(list, key = { it.activity.id }) { s ->
                    Card(Modifier.fillMaxWidth().clickable { onOpen(s.activity.id) }) {
                        Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                            Text(s.activity.name, style = MaterialTheme.typography.titleMedium)
                            Text("Mensilità ${Money.format(s.activity.defaultMonthlyFeeCents)} · ${s.participants} iscritti attivi", style = MaterialTheme.typography.bodySmall)
                            LabeledRow("Incassi") { MoneyText(s.incomeCents) }
                            LabeledRow("Costi (istruttore, materiali)") { MoneyText(s.costCents) }
                            LabeledRow("Resta all'associazione") { MoneyText(s.marginCents, bold = true) }
                            Text("Tocca per iscritti e pagamenti ›", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.primary)
                        }
                    }
                }
            }
        }
    }

    if (adding) {
        var name by remember { mutableStateOf("") }
        var fee by remember { mutableStateOf("") }
        var instructor by remember { mutableStateOf<String?>(null) }
        FormDialog(
            title = "Nuova attività ($label)",
            okEnabled = name.isNotBlank(),
            onOk = { vm.addActivity(name, Money.parse(fee) ?: 0, instructor); adding = false },
            onDismiss = { adding = false },
        ) {
            TextInput(name, { name = it }, "Nome (es. Yoga)", Modifier.fillMaxWidth())
            MoneyField(fee, { fee = it }, "Quota mensile", Modifier.fillMaxWidth())
            Picker(
                "Istruttore", members, members.firstOrNull { it.id == instructor }, { it.displayLabel() },
                { instructor = it?.id }, Modifier.fillMaxWidth(), noneLabel = "Nessuno",
            )
        }
    }
}
