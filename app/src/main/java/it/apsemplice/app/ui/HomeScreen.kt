@file:OptIn(ExperimentalMaterial3Api::class)

package it.apsemplice.app.ui

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Settings
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.dp
import androidx.lifecycle.ViewModel
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import androidx.lifecycle.viewModelScope
import it.apsemplice.app.AppContainer
import it.apsemplice.app.core.Money
import it.apsemplice.app.data.SocialYearReport
import it.apsemplice.app.data.AccountBalance
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.SharingStarted
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.stateIn
import kotlinx.coroutines.launch

class HomeVm(private val c: AppContainer) : ViewModel() {
    val balances: StateFlow<List<AccountBalance>> =
        c.repo.observeBalances().stateIn(viewModelScope, SharingStarted.WhileSubscribed(5000), emptyList())

    private val _report = MutableStateFlow<SocialYearReport?>(null)
    val report: StateFlow<SocialYearReport?> = _report

    init {
        viewModelScope.launch {
            c.repo.observeChanges().collect {
                _report.value = c.reports.socialYearReport(c.reports.currentSocialYear())
            }
        }
    }
}

@Composable
fun HomeScreen(go: (String) -> Unit) {
    val vm = appViewModel { HomeVm(it) }
    val balances by vm.balances.collectAsStateWithLifecycle()
    val report by vm.report.collectAsStateWithLifecycle()

    ScreenScaffold(
        title = "APSemplice",
        actions = { IconButton(onClick = { go(Routes.SETTINGS) }) { Icon(Icons.Filled.Settings, "Impostazioni") } },
    ) { padding ->
        LazyColumn(
            Modifier.fillMaxSize().padding(padding).padding(horizontal = 16.dp),
            verticalArrangement = Arrangement.spacedBy(8.dp),
        ) {
            item {
                Row(Modifier.fillMaxWidth().padding(top = 8.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    Button(onClick = { go(Routes.INCOME) }, modifier = Modifier.weight(1f)) { Text("Incasso") }
                    Button(onClick = { go(Routes.EXPENSE) }, modifier = Modifier.weight(1f)) { Text("Spesa") }
                    OutlinedButton(onClick = { go(Routes.TRANSFER) }, modifier = Modifier.weight(1f)) { Text("Giroconto") }
                }
            }
            item {
                Card(Modifier.fillMaxWidth()) {
                    Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                        Text("Disponibilità totale", style = MaterialTheme.typography.labelLarge)
                        MoneyText(balances.sumOf { it.balanceCents }, bold = true)
                        balances.forEach { b ->
                            LabeledRow(b.account.name) { MoneyText(b.balanceCents) }
                        }
                        TextButton(onClick = { go(Routes.ACCOUNTS) }) { Text("Gestisci conti e verifica saldi") }
                    }
                }
            }
            report?.let { r ->
                item {
                    Card(Modifier.fillMaxWidth()) {
                        Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                            Text("Anno sociale ${r.year.label}", style = MaterialTheme.typography.titleMedium)
                            LabeledRow("Soci iscritti") { Text("${r.membersCount}") }
                            LabeledRow("Entrate") { MoneyText(r.totalIncome) }
                            LabeledRow("Uscite") { MoneyText(r.totalExpense) }
                            LabeledRow("Resta all'associazione") { MoneyText(r.result, bold = true) }
                        }
                    }
                }
                items(r.activities) { a ->
                    Card(Modifier.fillMaxWidth()) {
                        Column(Modifier.padding(12.dp)) {
                            Text(a.activity.name, style = MaterialTheme.typography.titleSmall)
                            LabeledRow("Incassi ${Money.format(a.incomeCents)} · costi ${Money.format(a.costCents)}") { MoneyText(a.marginCents, bold = true) }
                        }
                    }
                }
            }
        }
    }
}
