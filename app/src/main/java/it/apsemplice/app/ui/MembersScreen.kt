@file:OptIn(ExperimentalMaterial3Api::class)

package it.apsemplice.app.ui

import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxSize
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
import it.apsemplice.app.data.db.MemberEntity
import kotlinx.coroutines.ExperimentalCoroutinesApi
import kotlinx.coroutines.flow.SharingStarted
import kotlinx.coroutines.flow.combine
import kotlinx.coroutines.flow.map
import kotlinx.coroutines.flow.stateIn
import kotlinx.coroutines.launch

class MembersVm(private val c: AppContainer) : ViewModel() {
    val year: String = c.reports.currentAcademicYear().label
    val members = c.repo.observeMembers().stateIn(viewModelScope, SharingStarted.WhileSubscribed(5000), emptyList<MemberEntity>())
    val enrolledIds = c.repo.observeMemberships(year).map { list -> list.map { it.memberId }.toSet() }
        .stateIn(viewModelScope, SharingStarted.WhileSubscribed(5000), emptySet())

    fun add(first: String, last: String, email: String, phone: String) {
        viewModelScope.launch { c.repo.saveMember(first, last, email, phone, null) }
    }
}

@Composable
fun MembersScreen() {
    val vm = appViewModel { MembersVm(it) }
    val members by vm.members.collectAsStateWithLifecycle()
    val enrolled by vm.enrolledIds.collectAsStateWithLifecycle()
    var adding by remember { mutableStateOf(false) }

    ScreenScaffold(
        title = "Soci",
        fab = { FloatingActionButton(onClick = { adding = true }) { Icon(Icons.Filled.Add, "Nuovo socio") } },
    ) { padding ->
        Column(Modifier.padding(padding)) {
            if (members.isEmpty()) {
                Text("Nessun socio. Aggiungine uno con + oppure direttamente dalla schermata di incasso.", modifier = Modifier.padding(16.dp))
            }
            LazyColumn(Modifier.fillMaxSize()) {
                items(members, key = { it.id }) { m ->
                    ListItem(
                        headlineContent = { Text(m.fullName) },
                        supportingContent = { Text(listOfNotNull(m.email, m.phone).joinToString(" · ").ifEmpty { "—" }) },
                        trailingContent = {
                            if (m.id in enrolled) Text("Socio ${vm.year}", color = MaterialTheme.colorScheme.primary)
                            else Text("Non iscritto", color = MaterialTheme.colorScheme.outline)
                        },
                    )
                    HorizontalDivider()
                }
            }
        }
    }

    if (adding) {
        NewMemberDialog(
            onSave = { f, l, e, p -> vm.add(f, l, e, p); adding = false },
            onDismiss = { adding = false },
        )
    }
}
