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
import androidx.compose.material.icons.filled.Add
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Card
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.FloatingActionButton
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
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
import it.apsemplice.app.data.AccountBalance
import it.apsemplice.app.domain.AccountType
import kotlinx.coroutines.flow.SharingStarted
import kotlinx.coroutines.flow.stateIn
import kotlinx.coroutines.launch
import java.time.LocalDate

class AccountsVm(private val c: AppContainer) : ViewModel() {
    val balances = c.repo.observeBalances().stateIn(viewModelScope, SharingStarted.WhileSubscribed(5000), emptyList())

    fun addAccount(name: String, type: AccountType, openingCents: Long) {
        viewModelScope.launch { c.repo.saveAccount(name, type, openingCents) }
    }

    suspend fun expected(accountId: String, date: LocalDate) = c.repo.expectedBalance(accountId, date)

    fun recordCount(accountId: String, counted: Long, adjust: Boolean) {
        viewModelScope.launch { c.repo.recordCashCount(accountId, LocalDate.now(), counted, adjust, null) }
    }
}

@Composable
fun AccountsScreen(onBack: () -> Unit) {
    val vm = appViewModel { AccountsVm(it) }
    val balances by vm.balances.collectAsStateWithLifecycle()
    var adding by remember { mutableStateOf(false) }
    var checking by remember { mutableStateOf<AccountBalance?>(null) }

    ScreenScaffold(
        title = "Conti",
        onBack = onBack,
        fab = { FloatingActionButton(onClick = { adding = true }) { Icon(Icons.Filled.Add, "Nuovo conto") } },
    ) { padding ->
        LazyColumn(
            Modifier.fillMaxSize().padding(padding).padding(horizontal = 16.dp),
            verticalArrangement = Arrangement.spacedBy(8.dp),
        ) {
            item {
                Text(
                    "Il saldo dell'app deve coincidere con la realtà: usa \"Verifica saldo\" con il contante contato o l'estratto conto.",
                    style = MaterialTheme.typography.bodySmall, modifier = Modifier.padding(top = 8.dp),
                )
            }
            items(balances, key = { it.account.id }) { b ->
                Card(Modifier.fillMaxWidth()) {
                    Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                        LabeledRow(b.account.name) { MoneyText(b.balanceCents, bold = true) }
                        Text(b.account.type.label, style = MaterialTheme.typography.bodySmall)
                        OutlinedButton(onClick = { checking = b }) { Text("Verifica saldo") }
                    }
                }
            }
        }
    }

    if (adding) {
        var name by remember { mutableStateOf("") }
        var type by remember { mutableStateOf(AccountType.BANK) }
        var opening by remember { mutableStateOf("") }
        FormDialog(
            title = "Nuovo conto",
            okEnabled = name.isNotBlank(),
            onOk = { vm.addAccount(name, type, Money.parse(opening) ?: 0); adding = false },
            onDismiss = { adding = false },
        ) {
            TextInput(name, { name = it }, "Nome (es. Conto POS)", Modifier.fillMaxWidth())
            Picker("Tipo", AccountType.entries, type, { it.label }, { it?.let { t -> type = t } }, Modifier.fillMaxWidth())
            MoneyField(opening, { opening = it }, "Saldo iniziale attuale", Modifier.fillMaxWidth())
        }
    }

    checking?.let { b ->
        var counted by remember(b.account.id) { mutableStateOf("") }
        var expected by remember(b.account.id) { mutableStateOf<Long?>(null) }
        LaunchedEffect(b.account.id) { expected = vm.expected(b.account.id, LocalDate.now()) }
        val countedCents = Money.parse(counted)
        val diff = if (countedCents != null && expected != null) countedCents - expected!! else null
        AlertDialog(
            onDismissRequest = { checking = null },
            title = { Text("Verifica ${b.account.name}") },
            text = {
                Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    Text("Saldo secondo l'app: ${expected?.let { Money.format(it) } ?: "…"}")
                    MoneyField(counted, { counted = it }, "Saldo reale (contato / estratto conto)", Modifier.fillMaxWidth())
                    if (diff != null) {
                        Text(
                            if (diff == 0L) "Coincide ✓" else "Differenza: ${Money.format(diff)}",
                            color = if (diff == 0L) MaterialTheme.colorScheme.primary else MaterialTheme.colorScheme.error,
                        )
                    }
                }
            },
            confirmButton = {
                Row {
                    TextButton(enabled = countedCents != null, onClick = { vm.recordCount(b.account.id, countedCents!!, false); checking = null }) { Text("Solo registra") }
                    TextButton(enabled = countedCents != null && diff != 0L, onClick = { vm.recordCount(b.account.id, countedCents!!, true); checking = null }) { Text("Rettifica") }
                }
            },
            dismissButton = { TextButton(onClick = { checking = null }) { Text("Annulla") } },
        )
    }
}
