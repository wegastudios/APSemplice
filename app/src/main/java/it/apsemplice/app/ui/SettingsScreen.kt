@file:OptIn(ExperimentalMaterial3Api::class)

package it.apsemplice.app.ui

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
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
import it.apsemplice.app.core.Money
import it.apsemplice.app.core.cloud.Entitlement
import java.time.Month
import java.time.format.TextStyle
import java.util.Locale

@Composable
fun SettingsScreen(onBack: () -> Unit) {
    val container = LocalContainer.current
    val settings = container.settings
    val profile = settings.profile

    var name by remember { mutableStateOf(profile.associationName) }
    var taxCode by remember { mutableStateOf(profile.taxCode) }
    var startMonth by remember { mutableStateOf(profile.academicYearStartMonth) }
    var fee by remember { mutableStateOf(Money.plain(profile.membershipFeeCents)) }
    var entitlement by remember { mutableStateOf<Entitlement?>(null) }
    LaunchedEffect(Unit) { entitlement = container.license.current() }

    ScreenScaffold(title = "Impostazioni", onBack = onBack) { padding ->
        Column(
            Modifier.padding(padding).padding(horizontal = 16.dp).verticalScroll(rememberScrollState()),
            verticalArrangement = Arrangement.spacedBy(8.dp),
        ) {
            SectionTitle("Associazione")
            TextInput(name, { name = it }, "Denominazione", Modifier.fillMaxWidth())
            TextInput(taxCode, { taxCode = it.uppercase() }, "Codice fiscale", Modifier.fillMaxWidth())
            Picker(
                "L'anno accademico inizia a", (1..12).toList(), startMonth,
                { Month.of(it).getDisplayName(TextStyle.FULL, Locale.ITALY) },
                { it?.let { m -> startMonth = m } }, Modifier.fillMaxWidth(),
            )
            MoneyField(fee, { fee = it }, "Quota associativa annuale proposta", Modifier.fillMaxWidth())
            Button(
                onClick = {
                    settings.update(
                        profile.copy(
                            associationName = name.trim(), taxCode = taxCode.trim(),
                            academicYearStartMonth = startMonth, membershipFeeCents = Money.parse(fee) ?: profile.membershipFeeCents,
                        ),
                    )
                    onBack()
                },
                modifier = Modifier.fillMaxWidth(),
            ) { Text("Salva") }

            SectionTitle("Account e cloud")
            val session = container.auth.session.value
            Text(
                if (session?.provider == "local") "Modalità locale: i dati restano su questo dispositivo."
                else "Connesso come ${session?.email}",
            )
            Text(
                "Accesso con Google, sincronizzazione tra dispositivi, verifica della titolarità e plugin WordPress saranno disponibili con l'abbonamento.",
                style = MaterialTheme.typography.bodySmall,
            )
            entitlement?.let { Text("Piano: ${it.plan} · Titolarità: ${it.ownership}", style = MaterialTheme.typography.bodySmall) }
        }
    }
}
