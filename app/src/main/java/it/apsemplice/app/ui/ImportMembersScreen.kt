@file:OptIn(ExperimentalMaterial3Api::class)

package it.apsemplice.app.ui

import android.content.ContentResolver
import android.net.Uri
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.Checkbox
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.unit.dp
import androidx.lifecycle.ViewModel
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import androidx.lifecycle.viewModelScope
import it.apsemplice.app.AppContainer
import it.apsemplice.app.data.ImportResult
import it.apsemplice.app.export.Sharing
import it.apsemplice.app.importer.MemberCsv
import it.apsemplice.app.importer.MemberImportPlanner
import it.apsemplice.app.importer.ParseResult
import it.apsemplice.app.importer.PlanAction
import it.apsemplice.app.importer.PlannedRow
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext

data class ImportUi(
    val fileName: String? = null,
    val plan: List<PlannedRow> = emptyList(),
    val error: String? = null,
    val markAsMembers: Boolean = false,
    val result: ImportResult? = null,
    val busy: Boolean = false,
) {
    val creatable: Int get() = plan.count { it.action == PlanAction.CREATE }
    val updatable: Int get() = plan.count { it.action == PlanAction.UPDATE }
    val errors: Int get() = plan.count { it.action == PlanAction.ERROR }
}

class ImportMembersVm(private val c: AppContainer) : ViewModel() {
    val year = c.reports.currentSocialYear()
    private val _ui = MutableStateFlow(ImportUi())
    val ui: StateFlow<ImportUi> = _ui

    fun load(resolver: ContentResolver, uri: Uri) {
        _ui.value = ImportUi(busy = true)
        viewModelScope.launch {
            try {
                val bytes = withContext(Dispatchers.IO) { resolver.openInputStream(uri)?.use { it.readBytes() } }
                    ?: error("File non leggibile")
                when (val parsed = MemberCsv.parse(MemberCsv.decode(bytes))) {
                    is ParseResult.Error -> _ui.value = ImportUi(error = parsed.message)
                    is ParseResult.Ok -> {
                        val plan = MemberImportPlanner.plan(parsed.rows, c.repo.existingForImport())
                        _ui.value = ImportUi(fileName = uri.lastPathSegment, plan = plan)
                    }
                }
            } catch (e: Exception) {
                _ui.value = ImportUi(error = "Impossibile leggere il file: ${e.message}")
            }
        }
    }

    fun setMark(v: Boolean) = _ui.update { it.copy(markAsMembers = v) }

    fun confirm() {
        val s = _ui.value
        _ui.update { it.copy(busy = true) }
        viewModelScope.launch {
            try {
                val result = c.repo.applyImport(s.plan, if (s.markAsMembers) year else null)
                _ui.value = ImportUi(result = result)
            } catch (e: Exception) {
                _ui.value = s.copy(busy = false, error = "Import annullato, nessun dato modificato: ${e.message}")
            }
        }
    }
}

@Composable
fun ImportMembersScreen(onBack: () -> Unit) {
    val vm = appViewModel { ImportMembersVm(it) }
    val ui by vm.ui.collectAsStateWithLifecycle()
    val context = LocalContext.current
    val picker = rememberLauncherForActivityResult(ActivityResultContracts.OpenDocument()) { uri ->
        if (uri != null) vm.load(context.contentResolver, uri)
    }

    ScreenScaffold(title = "Importa soci", onBack = onBack) { padding ->
        LazyColumn(
            Modifier.fillMaxSize().padding(padding).padding(horizontal = 16.dp),
            verticalArrangement = Arrangement.spacedBy(8.dp),
        ) {
            item {
                Text(
                    "Carica un file CSV (da Excel: File → Salva con nome → CSV). Prima riga = intestazioni. " +
                        "Colonne riconosciute: Numero tessera, Nome, Cognome, Codice fiscale, Email, Telefono (Nome e Cognome obbligatori).",
                    style = MaterialTheme.typography.bodyMedium, modifier = Modifier.padding(top = 8.dp),
                )
            }
            item {
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    Button(onClick = { picker.launch(arrayOf("*/*")) }, enabled = !ui.busy) { Text("Scegli file CSV") }
                    OutlinedButton(onClick = { Sharing.shareCsv(context, "modello-import-soci.csv", MemberCsv.TEMPLATE) }) { Text("Modello") }
                }
            }
            ui.error?.let { item { Text(it, color = MaterialTheme.colorScheme.error) } }

            ui.result?.let { r ->
                item {
                    Card(Modifier.fillMaxWidth()) {
                        Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                            Text("Import completato", style = MaterialTheme.typography.titleMedium)
                            Text("${r.created} soci creati · ${r.updated} aggiornati · ${r.skipped} righe scartate")
                            Button(onClick = onBack) { Text("Fine") }
                        }
                    }
                }
            }

            if (ui.plan.isNotEmpty() && ui.result == null) {
                item {
                    Card(Modifier.fillMaxWidth()) {
                        Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                            Text("Anteprima ${ui.fileName ?: ""}", style = MaterialTheme.typography.titleSmall)
                            Text("${ui.creatable} nuovi · ${ui.updatable} da aggiornare · ${ui.errors} con errori (saltati)")
                            Row {
                                Checkbox(checked = ui.markAsMembers, onCheckedChange = vm::setMark)
                                Text("Segna come iscritti all'anno sociale ${vm.year.label}", modifier = Modifier.padding(top = 12.dp))
                            }
                            Button(
                                onClick = vm::confirm,
                                enabled = !ui.busy && ui.creatable + ui.updatable > 0,
                                modifier = Modifier.fillMaxWidth(),
                            ) { Text("Importa ${ui.creatable + ui.updatable} soci") }
                        }
                    }
                }
                items(ui.plan) { p ->
                    val color = when (p.action) {
                        PlanAction.ERROR -> MaterialTheme.colorScheme.error
                        PlanAction.UPDATE -> MaterialTheme.colorScheme.tertiary
                        PlanAction.CREATE -> MaterialTheme.colorScheme.primary
                    }
                    Column(Modifier.fillMaxWidth()) {
                        Text(
                            "${p.row.line}. ${p.row.cardNumber?.let { "n.$it · " } ?: ""}${p.row.firstName} ${p.row.lastName}".trim(),
                            style = MaterialTheme.typography.bodyLarge,
                        )
                        Text(
                            p.action.label + (p.message?.let { " — $it" } ?: ""),
                            color = if (p.action == PlanAction.CREATE && p.message == null) Color.Unspecified else color,
                            style = MaterialTheme.typography.bodySmall,
                        )
                    }
                }
                item { Text("", modifier = Modifier.padding(bottom = 16.dp)) }
            }
        }
    }
}
