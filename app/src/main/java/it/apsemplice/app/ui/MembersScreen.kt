@file:OptIn(ExperimentalMaterial3Api::class)

package it.apsemplice.app.ui

import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Add
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.FloatingActionButton
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.ListItem
import androidx.compose.material3.MaterialTheme
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
import it.apsemplice.app.data.db.MemberEntity
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.SharingStarted
import kotlinx.coroutines.flow.combine
import kotlinx.coroutines.flow.map
import kotlinx.coroutines.flow.stateIn

class MembersVm(c: AppContainer) : ViewModel() {
    val year: String = c.reports.currentSocialYear().label
    val query = MutableStateFlow("")
    private val all = c.repo.observeMembers()

    val total = all.map { it.size }.stateIn(viewModelScope, SharingStarted.WhileSubscribed(5000), 0)
    val members = combine(all, query) { list, q -> list.filter { memberMatches(it, q) } }
        .stateIn(viewModelScope, SharingStarted.WhileSubscribed(5000), emptyList<MemberEntity>())
    val enrolledIds = c.repo.observeMemberships(year).map { list -> list.map { it.memberId }.toSet() }
        .stateIn(viewModelScope, SharingStarted.WhileSubscribed(5000), emptySet())
}

@Composable
fun MembersScreen(onOpen: (String) -> Unit, onNew: () -> Unit, onImport: () -> Unit) {
    val vm = appViewModel { MembersVm(it) }
    val members by vm.members.collectAsStateWithLifecycle()
    val total by vm.total.collectAsStateWithLifecycle()
    val enrolled by vm.enrolledIds.collectAsStateWithLifecycle()
    val query by vm.query.collectAsStateWithLifecycle()

    ScreenScaffold(
        title = "Soci ($total)",
        actions = { TextButton(onClick = onImport) { Text("Importa") } },
        fab = { FloatingActionButton(onClick = onNew) { Icon(Icons.Filled.Add, "Nuovo socio") } },
    ) { padding ->
        Column(Modifier.padding(padding)) {
            TextInput(query, { vm.query.value = it }, "Cerca per nome, tessera o codice fiscale", Modifier.fillMaxWidth().padding(horizontal = 16.dp, vertical = 8.dp))
            if (total == 0) {
                Text("Nessun socio. Aggiungine uno con +, oppure usa Importa per caricare l'elenco da un file CSV.", modifier = Modifier.padding(16.dp))
            }
            LazyColumn(Modifier.fillMaxSize()) {
                items(members, key = { it.id }) { m ->
                    ListItem(
                        modifier = Modifier.clickable { onOpen(m.id) },
                        headlineContent = { Text("${m.lastName} ${m.firstName}") },
                        supportingContent = { Text(m.cardNumber?.let { "Tessera n. $it" } ?: "Senza tessera") },
                        trailingContent = {
                            if (m.id in enrolled) Text("Iscritto ${vm.year}", color = MaterialTheme.colorScheme.primary)
                            else Text("Non iscritto", color = MaterialTheme.colorScheme.outline)
                        },
                    )
                    HorizontalDivider()
                }
            }
        }
    }
}
