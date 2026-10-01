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
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.dp
import androidx.lifecycle.ViewModel
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import androidx.lifecycle.viewModelScope
import it.apsemplice.app.AppContainer
import it.apsemplice.app.core.itFormat
import it.apsemplice.app.data.CardNumberTakenException
import it.apsemplice.app.data.EnrollmentStatus
import it.apsemplice.app.data.MemberInput
import it.apsemplice.app.data.db.MembershipEntity
import kotlinx.coroutines.ExperimentalCoroutinesApi
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.SharingStarted
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.combine
import kotlinx.coroutines.flow.filterNotNull
import kotlinx.coroutines.flow.first
import kotlinx.coroutines.flow.flowOf
import kotlinx.coroutines.flow.map
import kotlinx.coroutines.flow.stateIn
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch
import java.time.YearMonth

data class MemberForm(
    val card: String = "",
    val first: String = "",
    val last: String = "",
    val taxCode: String = "",
    val email: String = "",
    val phone: String = "",
) {
    val valid: Boolean get() = first.isNotBlank() && last.isNotBlank()
}

/** [memberId] null = nuovo socio. */
class MemberDetailVm(private val c: AppContainer, private val memberId: String?) : ViewModel() {
    private val repo = c.repo
    val isNew = memberId == null
    val year: String = c.reports.currentSocialYear().label

    val form = MutableStateFlow(MemberForm())
    val error = MutableStateFlow<String?>(null)
    val saved = MutableStateFlow(false)

    val membership: StateFlow<MembershipEntity?> =
        (if (memberId == null) flowOf(null) else repo.observeMembership(memberId, year))
            .stateIn(viewModelScope, SharingStarted.WhileSubscribed(5000), null)

    val statuses: StateFlow<List<EnrollmentStatus>> =
        (if (memberId == null) flowOf<List<EnrollmentStatus>>(emptyList())
        else combine(repo.observeEnrollmentsOfMember(memberId), repo.observeChanges()) { _, _ -> Unit }
            .map { repo.paymentStatusForMember(memberId) })
            .stateIn(viewModelScope, SharingStarted.WhileSubscribed(5000), emptyList())

    init {
        if (memberId != null) {
            viewModelScope.launch {
                val m = repo.observeMember(memberId).filterNotNull().first()
                form.value = MemberForm(m.cardNumber.orEmpty(), m.firstName, m.lastName, m.taxCode.orEmpty(), m.email.orEmpty(), m.phone.orEmpty())
            }
        }
    }

    fun edit(transform: (MemberForm) -> MemberForm) {
        form.update(transform)
        error.value = null
    }

    fun fillNextCard() {
        viewModelScope.launch { form.update { it.copy(card = repo.nextFreeCardNumber()) } }
    }

    fun save() {
        val f = form.value
        if (!f.valid) return
        val input = MemberInput(f.card, f.first, f.last, f.taxCode, f.email, f.phone)
        viewModelScope.launch {
            try {
                if (memberId == null) repo.createMember(input) else repo.updateMember(memberId, input)
                saved.value = true
            } catch (e: CardNumberTakenException) {
                error.value = e.message
            }
        }
    }

    fun setMembership(enrolled: Boolean) {
        if (memberId != null) viewModelScope.launch { repo.setMembership(memberId, year, enrolled) }
    }
}

@Composable
fun MemberDetailScreen(memberId: String, onBack: () -> Unit) {
    val id = memberId.takeIf { it != "new" }
    val vm = appViewModel { MemberDetailVm(it, id) }
    val form by vm.form.collectAsStateWithLifecycle()
    val error by vm.error.collectAsStateWithLifecycle()
    val saved by vm.saved.collectAsStateWithLifecycle()
    val membership by vm.membership.collectAsStateWithLifecycle()
    val statuses by vm.statuses.collectAsStateWithLifecycle()
    LaunchedEffect(saved) { if (saved) onBack() }

    ScreenScaffold(title = if (vm.isNew) "Nuovo socio" else "Socio", onBack = onBack) { padding ->
        Column(
            Modifier.padding(padding).padding(horizontal = 16.dp).verticalScroll(rememberScrollState()),
            verticalArrangement = Arrangement.spacedBy(8.dp),
        ) {
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                TextInput(form.card, { v -> vm.edit { it.copy(card = v) } }, "N. tessera", Modifier.weight(1f))
                OutlinedButton(onClick = vm::fillNextCard, modifier = Modifier.padding(top = 8.dp)) { Text("Prossimo libero") }
            }
            error?.let { Text(it, color = MaterialTheme.colorScheme.error) }
            TextInput(form.first, { v -> vm.edit { it.copy(first = v) } }, "Nome", Modifier.fillMaxWidth())
            TextInput(form.last, { v -> vm.edit { it.copy(last = v) } }, "Cognome", Modifier.fillMaxWidth())
            TextInput(form.taxCode, { v -> vm.edit { it.copy(taxCode = v.uppercase()) } }, "Codice fiscale", Modifier.fillMaxWidth())
            TextInput(form.email, { v -> vm.edit { it.copy(email = v) } }, "Email", Modifier.fillMaxWidth())
            TextInput(form.phone, { v -> vm.edit { it.copy(phone = v) } }, "Telefono", Modifier.fillMaxWidth())
            Button(onClick = vm::save, enabled = form.valid, modifier = Modifier.fillMaxWidth()) { Text("Salva") }

            if (!vm.isNew) {
                SectionTitle("Iscrizione all'associazione · anno sociale ${vm.year}")
                if (membership != null) {
                    Text("Iscritto", color = MaterialTheme.colorScheme.primary)
                    OutlinedButton(onClick = { vm.setMembership(false) }) { Text("Togli l'iscrizione") }
                } else {
                    Text("Non iscritto (la quota associativa incassata lo iscrive in automatico)")
                    OutlinedButton(onClick = { vm.setMembership(true) }) { Text("Segna come iscritto") }
                }

                SectionTitle("Attività e pagamenti")
                if (statuses.isEmpty()) Text("Non è iscritto a nessuna attività. Si iscrive dalla scheda Attività.", style = MaterialTheme.typography.bodySmall)
                statuses.forEach { s ->
                    Card(Modifier.fillMaxWidth()) {
                        Column(Modifier.padding(12.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                            Text("${s.activity.name} (${s.activity.socialYear})", style = MaterialTheme.typography.titleSmall)
                            Text(
                                if (s.enrollment.isActive) "Iscritto dal ${YearMonth.parse(s.enrollment.startMonth).itFormat()}"
                                else "Cancellato (ultimo mese dovuto: ${YearMonth.parse(s.enrollment.endMonth!!).itFormat()})",
                                style = MaterialTheme.typography.bodySmall,
                            )
                            PaymentStatusText(s.summary)
                            PaymentMonthsTable(s.summary)
                        }
                    }
                }
            }
            Text("", modifier = Modifier.padding(bottom = 16.dp))
        }
    }
}
