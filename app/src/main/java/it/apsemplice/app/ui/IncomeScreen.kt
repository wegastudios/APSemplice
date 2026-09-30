@file:OptIn(ExperimentalMaterial3Api::class)

package it.apsemplice.app.ui

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Delete
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
import it.apsemplice.app.core.itFormat
import it.apsemplice.app.data.ReceiptDraft
import it.apsemplice.app.data.ReceiptLine
import it.apsemplice.app.data.db.AccountEntity
import it.apsemplice.app.data.db.ActivityEntity
import it.apsemplice.app.data.db.CategoryEntity
import it.apsemplice.app.domain.AccountType
import it.apsemplice.app.domain.CashChange
import it.apsemplice.app.domain.CashChangeResult
import it.apsemplice.app.domain.CategoryKind
import it.apsemplice.app.domain.PaymentMethod
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.SharingStarted
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.first
import kotlinx.coroutines.flow.stateIn
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch
import java.time.LocalDate
import java.time.YearMonth

data class LineUi(
    val key: Long,
    val title: String,
    val categoryId: String,
    val activityId: String?,
    val month: YearMonth?,
    val amountText: String,
)

data class IncomeUi(
    val date: LocalDate = LocalDate.now(),
    val method: PaymentMethod = PaymentMethod.CASH,
    val accountId: String? = null,
    val memberId: String? = null,
    val memberIsEnrolled: Boolean = false,
    val lines: List<LineUi> = emptyList(),
    val tenderedText: String = "",
    val documentRef: String = "",
    val saved: Boolean = false,
) {
    val totalCents: Long get() = lines.sumOf { Money.parse(it.amountText) ?: 0L }
    val linesValid: Boolean get() = lines.isNotEmpty() && lines.all { (Money.parse(it.amountText) ?: 0L) > 0 }
}

/** Conto suggerito per la modalità di pagamento: contanti->cassa, POS->conto POS, altro->banca. */
fun defaultAccountFor(method: PaymentMethod, accounts: List<AccountEntity>): AccountEntity? {
    val wanted = when (method) {
        PaymentMethod.CASH -> AccountType.CASH
        PaymentMethod.POS -> AccountType.POS
        else -> AccountType.BANK
    }
    return accounts.firstOrNull { it.type == wanted } ?: accounts.firstOrNull()
}

class IncomeVm(private val c: AppContainer) : ViewModel() {
    private val repo = c.repo
    private fun <T> kotlinx.coroutines.flow.Flow<T>.hot(initial: T) =
        stateIn(viewModelScope, SharingStarted.WhileSubscribed(5000), initial)

    val accounts = repo.observeAccounts().hot(emptyList())
    val members = repo.observeMembers().hot(emptyList())
    val categories = repo.observeCategories().hot(emptyList())
    val activities = repo.observeActivities(c.reports.currentAcademicYear().label).hot(emptyList())
    val membershipFeeCents: Long get() = c.settings.profile.membershipFeeCents

    private val _ui = MutableStateFlow(IncomeUi())
    val ui: StateFlow<IncomeUi> = _ui
    private var nextKey = 0L

    init {
        viewModelScope.launch {
            val list = accounts.first { it.isNotEmpty() }
            _ui.update { if (it.accountId == null) it.copy(accountId = defaultAccountFor(it.method, list)?.id) else it }
        }
    }

    fun setDate(d: LocalDate) {
        _ui.update { it.copy(date = d) }
        refreshEnrollment()
    }

    fun setMethod(m: PaymentMethod) = _ui.update {
        it.copy(method = m, accountId = defaultAccountFor(m, accounts.value)?.id ?: it.accountId, tenderedText = "")
    }

    fun setAccount(id: String) = _ui.update { it.copy(accountId = id) }
    fun setTendered(s: String) = _ui.update { it.copy(tenderedText = s) }
    fun setDocumentRef(s: String) = _ui.update { it.copy(documentRef = s) }

    fun setMember(id: String?) {
        _ui.update { it.copy(memberId = id, memberIsEnrolled = false) }
        refreshEnrollment()
    }

    private fun refreshEnrollment() {
        val s = _ui.value
        val id = s.memberId ?: return
        viewModelScope.launch {
            val enrolled = repo.isMember(id, s.date)
            _ui.update { if (it.memberId == id) it.copy(memberIsEnrolled = enrolled) else it }
        }
    }

    private fun addLine(title: String, category: CategoryEntity, activity: ActivityEntity? = null, month: YearMonth? = null, cents: Long = 0) {
        val text = if (cents > 0) Money.plain(cents) else ""
        _ui.update {
            it.copy(lines = it.lines + LineUi(nextKey++, title, category.id, activity?.id, month, text))
        }
    }

    fun addMembershipLine() {
        val cat = categories.value.firstOrNull { it.kind == CategoryKind.MEMBERSHIP } ?: return
        addLine("Quota associativa ${c.reports.currentAcademicYear().label}", cat, cents = membershipFeeCents)
    }

    fun addActivityLine(a: ActivityEntity) {
        val cat = categories.value.firstOrNull { it.kind == CategoryKind.ACTIVITY_FEE } ?: return
        addLine(a.name, cat, a, YearMonth.from(_ui.value.date), a.defaultMonthlyFeeCents)
    }

    fun addOtherLine(cat: CategoryEntity) = addLine(cat.name, cat)

    fun setAmount(key: Long, text: String) = _ui.update { s ->
        s.copy(lines = s.lines.map { if (it.key == key) it.copy(amountText = text) else it })
    }

    fun shiftMonth(key: Long, delta: Long) = _ui.update { s ->
        s.copy(lines = s.lines.map { if (it.key == key && it.month != null) it.copy(month = it.month.plusMonths(delta)) else it })
    }

    fun removeLine(key: Long) = _ui.update { s -> s.copy(lines = s.lines.filterNot { it.key == key }) }

    fun addNewMember(first: String, last: String, email: String, phone: String) {
        viewModelScope.launch {
            val m = repo.saveMember(first, last, email, phone, null)
            setMember(m.id)
        }
    }

    fun save(tenderOk: Boolean) {
        val s = _ui.value
        val accountId = s.accountId ?: return
        if (!s.linesValid || !tenderOk) return
        viewModelScope.launch {
            repo.recordReceipt(
                ReceiptDraft(
                    date = s.date,
                    accountId = accountId,
                    method = s.method,
                    memberId = s.memberId,
                    documentRef = s.documentRef.trim().ifEmpty { null },
                    lines = s.lines.map {
                        ReceiptLine(
                            categoryId = it.categoryId,
                            amountCents = Money.parse(it.amountText) ?: 0,
                            activityId = it.activityId,
                            competenceMonth = it.month,
                            description = it.title,
                        )
                    },
                ),
            )
            _ui.update { it.copy(saved = true) }
        }
    }
}

@Composable
fun IncomeScreen(onBack: () -> Unit) {
    val vm = appViewModel { IncomeVm(it) }
    val ui by vm.ui.collectAsStateWithLifecycle()
    val accounts by vm.accounts.collectAsStateWithLifecycle()
    val members by vm.members.collectAsStateWithLifecycle()
    val categories by vm.categories.collectAsStateWithLifecycle()
    val activities by vm.activities.collectAsStateWithLifecycle()
    var newMember by remember { mutableStateOf(false) }

    LaunchedEffect(ui.saved) { if (ui.saved) onBack() }

    val total = ui.totalCents
    // Contanti ricevuti: se vuoto si assume l'importo esatto.
    val tendered = Money.parse(ui.tenderedText) ?: total
    val change = if (ui.method == PaymentMethod.CASH && total > 0) CashChange.compute(total, tendered) else null
    val tenderOk = change !is CashChangeResult.Missing

    ScreenScaffold(title = "Nuovo incasso", onBack = onBack) { padding ->
        Column(
            Modifier.padding(padding).padding(horizontal = 16.dp).verticalScroll(rememberScrollState()),
            verticalArrangement = Arrangement.spacedBy(8.dp),
        ) {
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                DateButton(ui.date, vm::setDate, Modifier.weight(1f))
                Picker(
                    "Pagamento", PaymentMethod.entries, ui.method, { it.label },
                    { it?.let(vm::setMethod) }, Modifier.weight(1f),
                )
            }
            Picker(
                "Conto", accounts, accounts.firstOrNull { it.id == ui.accountId }, { it.name },
                { it?.let { a -> vm.setAccount(a.id) } }, Modifier.fillMaxWidth(),
            )

            SectionTitle("Da chi")
            Picker(
                "Socio", members, members.firstOrNull { it.id == ui.memberId }, { it.fullName },
                { vm.setMember(it?.id) }, Modifier.fillMaxWidth(), noneLabel = "Nessuno / anonimo",
            )
            TextButton(onClick = { newMember = true }) { Text("+ Nuovo socio") }

            SectionTitle("Voci")
            ui.lines.forEach { line ->
                Card(Modifier.fillMaxWidth()) {
                    Column(Modifier.padding(12.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
                            Text(line.title, style = MaterialTheme.typography.titleSmall, modifier = Modifier.weight(1f))
                            IconButton(onClick = { vm.removeLine(line.key) }) { Icon(Icons.Filled.Delete, "Rimuovi") }
                        }
                        if (line.month != null) {
                            Row(horizontalArrangement = Arrangement.spacedBy(4.dp)) {
                                TextButton(onClick = { vm.shiftMonth(line.key, -1) }) { Text("‹") }
                                Text("Mese: ${line.month.itFormat()}", modifier = Modifier.padding(top = 12.dp))
                                TextButton(onClick = { vm.shiftMonth(line.key, 1) }) { Text("›") }
                            }
                        }
                        MoneyField(line.amountText, { vm.setAmount(line.key, it) }, "Importo", Modifier.fillMaxWidth())
                    }
                }
            }
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                if (ui.memberId != null && !ui.memberIsEnrolled && ui.lines.none { l -> categories.firstOrNull { it.id == l.categoryId }?.kind == CategoryKind.MEMBERSHIP }) {
                    OutlinedButton(onClick = vm::addMembershipLine) { Text("+ Iscrizione") }
                }
                var pickActivity by remember { mutableStateOf(false) }
                OutlinedButton(onClick = { pickActivity = true }, enabled = activities.isNotEmpty()) { Text("+ Mensilità") }
                if (pickActivity) {
                    androidx.compose.material3.AlertDialog(
                        onDismissRequest = { pickActivity = false },
                        title = { Text("Attività") },
                        text = {
                            Column {
                                activities.forEach { a ->
                                    TextButton(onClick = { vm.addActivityLine(a); pickActivity = false }) { Text(a.name) }
                                }
                            }
                        },
                        confirmButton = {},
                        dismissButton = { TextButton(onClick = { pickActivity = false }) { Text("Chiudi") } },
                    )
                }
                Picker(
                    "+ Altro", categories.filter { it.kind.income && it.kind != CategoryKind.ADJUSTMENT }, null, { it.name },
                    { it?.let(vm::addOtherLine) }, Modifier.weight(1f),
                )
            }
            if (activities.isEmpty()) {
                Text("Nessuna attività per quest'anno accademico: creala dalla scheda Attività.", style = MaterialTheme.typography.bodySmall)
            }

            SectionTitle("Totale")
            MoneyText(total, bold = true)

            if (ui.method == PaymentMethod.CASH && total > 0) {
                MoneyField(ui.tenderedText, vm::setTendered, "Contanti ricevuti", Modifier.fillMaxWidth())
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    CashChange.quickTenders(total).forEach { q ->
                        OutlinedButton(onClick = { vm.setTendered(Money.plain(q)) }) {
                            Text(if (q == total) "Esatto" else Money.format(q).replace(",00", ""))
                        }
                    }
                }
                when (change) {
                    is CashChangeResult.Change -> {
                        Text("Resto da dare: ${Money.format(change.cents)}", style = MaterialTheme.typography.titleMedium, color = MaterialTheme.colorScheme.primary)
                        if (change.breakdown.isNotEmpty()) {
                            Text(change.breakdown.joinToString(" · ") { (d, n) -> "$n × ${Money.format(d)}" }, style = MaterialTheme.typography.bodySmall)
                        }
                    }
                    is CashChangeResult.Missing ->
                        Text("Mancano ${Money.format(change.cents)}", style = MaterialTheme.typography.titleMedium, color = MaterialTheme.colorScheme.error)
                    null -> Unit
                }
            }

            TextInput(ui.documentRef, vm::setDocumentRef, "N. ricevuta (facoltativo)", Modifier.fillMaxWidth())
            Button(
                onClick = { vm.save(tenderOk) },
                enabled = ui.linesValid && tenderOk && ui.accountId != null,
                modifier = Modifier.fillMaxWidth().padding(bottom = 16.dp),
            ) { Text("Registra incasso") }
        }
    }

    if (newMember) {
        NewMemberDialog(
            onSave = { f, l, e, p -> vm.addNewMember(f, l, e, p); newMember = false },
            onDismiss = { newMember = false },
        )
    }
}
