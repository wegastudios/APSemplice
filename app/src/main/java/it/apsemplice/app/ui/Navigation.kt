package it.apsemplice.app.ui

import androidx.compose.foundation.layout.WindowInsets
import androidx.compose.foundation.layout.padding
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.List
import androidx.compose.material.icons.filled.Home
import androidx.compose.material.icons.filled.Info
import androidx.compose.material.icons.filled.Person
import androidx.compose.material.icons.filled.Star
import androidx.compose.material3.Icon
import androidx.compose.material3.NavigationBar
import androidx.compose.material3.NavigationBarItem
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.unit.dp
import androidx.navigation.NavType
import androidx.navigation.compose.NavHost
import androidx.navigation.compose.composable
import androidx.navigation.compose.currentBackStackEntryAsState
import androidx.navigation.compose.rememberNavController
import androidx.navigation.navArgument

object Routes {
    const val HOME = "home"
    const val LEDGER = "ledger"
    const val MEMBERS = "members"
    const val ACTIVITIES = "activities"
    const val REPORTS = "reports"
    const val ACCOUNTS = "accounts"
    const val SETTINGS = "settings"
    const val INCOME = "income"
    const val EXPENSE = "expense"
    const val TRANSFER = "transfer"
    const val MEMBER = "member/{id}"
    const val ACTIVITY = "activity/{id}"
    const val IMPORT_MEMBERS = "import-members"

    fun member(id: String) = "member/$id"
    fun activity(id: String) = "activity/$id"
}

private data class Tab(val route: String, val label: String, val icon: ImageVector)

private val tabs = listOf(
    Tab(Routes.HOME, "Home", Icons.Filled.Home),
    Tab(Routes.LEDGER, "Prima nota", Icons.AutoMirrored.Filled.List),
    Tab(Routes.MEMBERS, "Soci", Icons.Filled.Person),
    Tab(Routes.ACTIVITIES, "Attività", Icons.Filled.Star),
    Tab(Routes.REPORTS, "Report", Icons.Filled.Info),
)

@Composable
fun ApsNavHost() {
    val nav = rememberNavController()
    val entry by nav.currentBackStackEntryAsState()
    val current = entry?.destination?.route
    val go: (String) -> Unit = { nav.navigate(it) }
    val back: () -> Unit = { nav.popBackStack() }

    Scaffold(
        contentWindowInsets = WindowInsets(0.dp, 0.dp, 0.dp, 0.dp),
        bottomBar = {
            if (tabs.any { it.route == current }) {
                NavigationBar {
                    tabs.forEach { t ->
                        NavigationBarItem(
                            selected = current == t.route,
                            onClick = {
                                nav.navigate(t.route) {
                                    popUpTo(Routes.HOME) { saveState = true }
                                    launchSingleTop = true
                                    restoreState = true
                                }
                            },
                            icon = { Icon(t.icon, contentDescription = t.label) },
                            label = { Text(t.label) },
                        )
                    }
                }
            }
        },
    ) { padding ->
        NavHost(nav, startDestination = Routes.HOME, modifier = Modifier.padding(padding)) {
            composable(Routes.HOME) { HomeScreen(go) }
            composable(Routes.LEDGER) { LedgerScreen() }
            composable(Routes.MEMBERS) {
                MembersScreen(onOpen = { nav.navigate(Routes.member(it)) }, onNew = { nav.navigate(Routes.member("new")) }, onImport = { go(Routes.IMPORT_MEMBERS) })
            }
            composable(Routes.ACTIVITIES) { ActivitiesScreen(onOpen = { nav.navigate(Routes.activity(it)) }) }
            composable(Routes.MEMBER, arguments = listOf(navArgument("id") { type = NavType.StringType })) { e ->
                MemberDetailScreen(e.arguments?.getString("id") ?: "new", back)
            }
            composable(Routes.ACTIVITY, arguments = listOf(navArgument("id") { type = NavType.StringType })) { e ->
                ActivityDetailScreen(e.arguments?.getString("id").orEmpty(), back)
            }
            composable(Routes.IMPORT_MEMBERS) { ImportMembersScreen(back) }
            composable(Routes.REPORTS) { ReportsScreen() }
            composable(Routes.ACCOUNTS) { AccountsScreen(back) }
            composable(Routes.SETTINGS) { SettingsScreen(back) }
            composable(Routes.INCOME) { IncomeScreen(back) }
            composable(Routes.EXPENSE) { ExpenseScreen(back) }
            composable(Routes.TRANSFER) { TransferScreen(back) }
        }
    }
}
