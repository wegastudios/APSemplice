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
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.horizontalScroll
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.FilterChip
import androidx.compose.material3.HorizontalDivider
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
import it.apsemplice.app.core.itFormat
import it.apsemplice.app.data.TxRow
import it.apsemplice.app.domain.TxType
import kotlinx.coroutines.ExperimentalCoroutinesApi
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.SharingStarted
import kotlinx.coroutines.flow.combine
import kotlinx.coroutines.flow.flatMapLatest
import kotlinx.coroutines.flow.stateIn
import kotlinx.coroutines.launch
import java.time.LocalDate

class LedgerVm(private val c: AppContainer) : ViewModel() {
    val year = MutableStateFlow(LocalDate.now().year)
    val accountFilter = MutableStateFlow<String?>(null)
    val accounts = c.repo.observeAccounts().stateIn(viewModelScope, SharingStarted.WhileSubscribed(5000), emptyList())

    val rows = combine(
        year.flatMapLatest { y -> c.repo.observeRows(LocalDate.of(y, 1, 1), LocalDate.of(y, 12, 31)) },
        accountFilter,
    ) { rows, filter -> if (filter == null) rows else rows.filter { it.tx.accountId == filter } }
        .stateIn(viewModelScope, SharingStarted.WhileSubscribed(5000), emptyList())

    fun void(id: String, reason: String) {
        viewModelScope.launch { c.repo.voidTransaction(id, reason.ifBlank { "Annullato" }) }
    }
}

@Composable
fun LedgerScreen() {
    val vm = appViewModel { LedgerVm(it) }
    val year by vm.year.collectAsStateWithLifecycle()
    val filter by vm.accountFilter.collectAsStateWithLifecycle()
    val accounts by vm.accounts.collectAsStateWithLifecycle()
    val rows by vm.rows.collectAsStateWithLifecycle()
    var selected by remember { mutableStateOf<TxRow?>(null) }

    val income = rows.filter { it.tx.type == TxType.INCOME }.sumOf { it.tx.amountCents }
    val expense = rows.filter { it.tx.type == TxType.EXPENSE }.sumOf { it.tx.amountCents }

    ScreenScaffold(title = "Prima nota") { padding ->
        Column(Modifier.padding(padding).fillMaxSize()) {
            Row(Modifier.fillMaxWidth().padding(horizontal = 8.dp), horizontalArrangement = Arrangement.SpaceBetween) {
                TextButton(onClick = { vm.year.value = year - 1 }) { Text("‹") }
                Text("Anno solare $year", modifier = Modifier.padding(top = 12.dp), style = MaterialTheme.typography.titleMedium)
                TextButton(onClick = { vm.year.value = year + 1 }) { Text("›") }
            }
            Row(Modifier.horizontalScroll(rememberScrollState()).padding(horizontal = 16.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                FilterChip(selected = filter == null, onClick = { vm.accountFilter.value = null }, label = { Text("Tutti i conti") })
                accounts.forEach { a ->
                    FilterChip(selected = filter == a.id, onClick = { vm.accountFilter.value = a.id }, label = { Text(a.name) })
                }
            }
            Row(Modifier.fillMaxWidth().padding(16.dp), horizontalArrangement = Arrangement.SpaceBetween) {
                Text("Entrate ${Money.format(income)}")
                Text("Uscite ${Money.format(expense)}")
            }
            HorizontalDivider()
            LazyColumn(Modifier.fillMaxSize()) {
                items(rows, key = { it.tx.id }) { r ->
                    Row(
                        Modifier.fillMaxWidth().clickable { selected = r }.padding(horizontal = 16.dp, vertical = 8.dp),
                        horizontalArrangement = Arrangement.SpaceBetween,
                    ) {
                        Column(Modifier.weight(1f)) {
                            Text(if (r.tx.type.isTransfer) r.tx.type.label else r.categoryName, style = MaterialTheme.typography.bodyLarge)
                            Text(
                                listOfNotNull(LocalDate.parse(r.tx.date).itFormat(), r.accountName, r.tx.method.label, r.memberName, r.activityName).joinToString(" · "),
                                style = MaterialTheme.typography.bodySmall,
                            )
                        }
                        val sign = if (r.tx.type.sign > 0) "+" else "−"
                        Text(
                            sign + Money.format(r.tx.amountCents),
                            color = when {
                                r.tx.type.isTransfer -> MaterialTheme.colorScheme.onSurface
                                r.tx.type.sign > 0 -> MaterialTheme.colorScheme.primary
                                else -> MaterialTheme.colorScheme.error
                            },
                        )
                    }
                    HorizontalDivider()
                }
            }
        }
    }

    selected?.let { r ->
        var reason by remember(r.tx.id) { mutableStateOf("") }
        FormDialog(
            title = "Movimento",
            okEnabled = true,
            onOk = { vm.void(r.tx.id, reason); selected = null },
            onDismiss = { selected = null },
            okLabel = "Annulla movimento",
            dismissLabel = "Chiudi",
        ) {
            Text("${r.tx.type.label} · ${Money.format(r.tx.amountCents)}")
            Text("${LocalDate.parse(r.tx.date).itFormat()} · ${r.accountName} · ${r.tx.method.label}")
            if (r.tx.description.isNotBlank()) Text(r.tx.description)
            r.tx.competenceMonth?.let { Text("Competenza: $it") }
            r.tx.documentRef?.let { Text("Rif.: $it") }
            Text("Per correggere: annulla e registra di nuovo. L'annullamento resta tracciato.", style = MaterialTheme.typography.bodySmall)
            TextInput(reason, { reason = it }, "Motivo dell'annullamento", Modifier.fillMaxWidth())
        }
    }
}
