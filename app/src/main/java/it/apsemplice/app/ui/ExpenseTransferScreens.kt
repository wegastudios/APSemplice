@file:OptIn(ExperimentalMaterial3Api::class)

package it.apsemplice.app.ui

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.Button
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
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
import it.apsemplice.app.data.ExpenseDraft
import it.apsemplice.app.data.db.AccountEntity
import it.apsemplice.app.domain.AccountType
import it.apsemplice.app.domain.CategoryKind
import it.apsemplice.app.domain.PaymentMethod
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.SharingStarted
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.stateIn
import kotlinx.coroutines.launch
import java.time.LocalDate

class ExpenseVm(private val c: AppContainer) : ViewModel() {
    private val hot = SharingStarted.WhileSubscribed(5000)
    val accounts = c.repo.observeAccounts().stateIn(viewModelScope, hot, emptyList())
    val categories = c.repo.observeCategories().stateIn(viewModelScope, hot, emptyList())
    val members = c.repo.observeMembers().stateIn(viewModelScope, hot, emptyList())
    val activities = c.repo.observeActivities(c.reports.currentSocialYear().label).stateIn(viewModelScope, hot, emptyList())
    val balances = c.repo.observeBalances().stateIn(viewModelScope, hot, emptyList())

    private val _saved = MutableStateFlow(false)
    val saved: StateFlow<Boolean> = _saved

    fun save(draft: ExpenseDraft) {
        viewModelScope.launch {
            c.repo.recordExpense(draft)
            _saved.value = true
        }
    }
}

@Composable
fun ExpenseScreen(onBack: () -> Unit) {
    val vm = appViewModel { ExpenseVm(it) }
    val accounts by vm.accounts.collectAsStateWithLifecycle()
    val categories by vm.categories.collectAsStateWithLifecycle()
    val members by vm.members.collectAsStateWithLifecycle()
    val activities by vm.activities.collectAsStateWithLifecycle()
    val balances by vm.balances.collectAsStateWithLifecycle()
    val saved by vm.saved.collectAsStateWithLifecycle()
    LaunchedEffect(saved) { if (saved) onBack() }

    var date by remember { mutableStateOf(LocalDate.now()) }
    var method by remember { mutableStateOf(PaymentMethod.CASH) }
    var accountId by remember { mutableStateOf<String?>(null) }
    var categoryId by remember { mutableStateOf<String?>(null) }
    var amount by remember { mutableStateOf("") }
    var activityId by remember { mutableStateOf<String?>(null) }
    var memberId by remember { mutableStateOf<String?>(null) }
    var description by remember { mutableStateOf("") }
    var documentRef by remember { mutableStateOf("") }

    val effectiveAccount = accounts.firstOrNull { it.id == accountId } ?: defaultAccountFor(method, accounts)
    val cents = Money.parse(amount) ?: 0L
    val available = balances.firstOrNull { it.account.id == effectiveAccount?.id }?.balanceCents
    val expenseCategories = categories.filter { it.kind.expense && it.kind != CategoryKind.ADJUSTMENT }

    ScreenScaffold(title = "Nuova spesa / rimborso", onBack = onBack) { padding ->
        Column(
            Modifier.padding(padding).padding(horizontal = 16.dp).verticalScroll(rememberScrollState()),
            verticalArrangement = Arrangement.spacedBy(8.dp),
        ) {
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                DateButton(date, { date = it }, Modifier.weight(1f))
                Picker(
                    "Pagamento", PaymentMethod.entries, method, { it.label },
                    { m -> if (m != null) { method = m; accountId = null } }, Modifier.weight(1f),
                )
            }
            Picker<AccountEntity>(
                "Conto", accounts, effectiveAccount, { it.name },
                { accountId = it?.id }, Modifier.fillMaxWidth(),
            )
            Picker(
                "Voce", expenseCategories, expenseCategories.firstOrNull { it.id == categoryId }, { it.name },
                { categoryId = it?.id }, Modifier.fillMaxWidth(),
            )
            MoneyField(amount, { amount = it }, "Importo", Modifier.fillMaxWidth())
            if (available != null && cents > available) {
                Text(
                    "Attenzione: il saldo di ${effectiveAccount?.name} (${Money.format(available)}) non basta.",
                    color = MaterialTheme.colorScheme.error, style = MaterialTheme.typography.bodySmall,
                )
            }
            Picker(
                "Attività", activities, activities.firstOrNull { it.id == activityId }, { it.name },
                { activityId = it?.id }, Modifier.fillMaxWidth(), noneLabel = "Nessuna (costo generale)",
            )
            Picker(
                "Beneficiario", members, members.firstOrNull { it.id == memberId }, { it.displayLabel() },
                { memberId = it?.id }, Modifier.fillMaxWidth(), noneLabel = "Nessuno / fornitore",
            )
            TextInput(description, { description = it }, "Descrizione", Modifier.fillMaxWidth())
            TextInput(documentRef, { documentRef = it }, "N. fattura / scontrino (facoltativo)", Modifier.fillMaxWidth())
            Button(
                onClick = {
                    vm.save(
                        ExpenseDraft(
                            date = date, accountId = effectiveAccount!!.id, method = method,
                            categoryId = categoryId!!, amountCents = cents, activityId = activityId, memberId = memberId,
                            description = description.trim(), documentRef = documentRef.trim().ifEmpty { null },
                        ),
                    )
                },
                enabled = effectiveAccount != null && categoryId != null && cents > 0,
                modifier = Modifier.fillMaxWidth().padding(bottom = 16.dp),
            ) { Text("Registra spesa") }
        }
    }
}

class TransferVm(private val c: AppContainer) : ViewModel() {
    val accounts = c.repo.observeAccounts().stateIn(viewModelScope, SharingStarted.WhileSubscribed(5000), emptyList())
    private val _saved = MutableStateFlow(false)
    val saved: StateFlow<Boolean> = _saved

    fun save(date: LocalDate, from: String, to: String, cents: Long, method: PaymentMethod, description: String) {
        viewModelScope.launch {
            c.repo.recordTransfer(date, from, to, cents, method, description)
            _saved.value = true
        }
    }
}

@Composable
fun TransferScreen(onBack: () -> Unit) {
    val vm = appViewModel { TransferVm(it) }
    val accounts by vm.accounts.collectAsStateWithLifecycle()
    val saved by vm.saved.collectAsStateWithLifecycle()
    LaunchedEffect(saved) { if (saved) onBack() }

    var date by remember { mutableStateOf(LocalDate.now()) }
    var fromId by remember { mutableStateOf<String?>(null) }
    var toId by remember { mutableStateOf<String?>(null) }
    var amount by remember { mutableStateOf("") }
    var description by remember { mutableStateOf("") }
    val cents = Money.parse(amount) ?: 0L

    ScreenScaffold(title = "Giroconto tra conti", onBack = onBack) { padding ->
        Column(
            Modifier.padding(padding).padding(horizontal = 16.dp).verticalScroll(rememberScrollState()),
            verticalArrangement = Arrangement.spacedBy(8.dp),
        ) {
            Text("Per spostamenti tra i tuoi conti (es. versamento contanti in banca, accredito POS). Non conta come entrata né uscita.", style = MaterialTheme.typography.bodySmall)
            DateButton(date, { date = it }, Modifier.fillMaxWidth())
            Picker("Da", accounts, accounts.firstOrNull { it.id == fromId }, { it.name }, { fromId = it?.id }, Modifier.fillMaxWidth())
            Picker("A", accounts, accounts.firstOrNull { it.id == toId }, { it.name }, { toId = it?.id }, Modifier.fillMaxWidth())
            MoneyField(amount, { amount = it }, "Importo", Modifier.fillMaxWidth())
            TextInput(description, { description = it }, "Descrizione", Modifier.fillMaxWidth())
            Button(
                onClick = {
                    val from = accounts.first { it.id == fromId }
                    val method = if (from.type == AccountType.CASH) PaymentMethod.CASH else PaymentMethod.BANK_TRANSFER
                    vm.save(date, fromId!!, toId!!, cents, method, description.trim())
                },
                enabled = fromId != null && toId != null && fromId != toId && cents > 0,
                modifier = Modifier.fillMaxWidth().padding(bottom = 16.dp),
            ) { Text("Registra giroconto") }
        }
    }
}
