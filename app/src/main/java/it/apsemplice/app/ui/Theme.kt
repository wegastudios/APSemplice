package it.apsemplice.app.ui

import androidx.compose.foundation.isSystemInDarkTheme
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.darkColorScheme
import androidx.compose.material3.lightColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.runtime.staticCompositionLocalOf
import androidx.compose.ui.graphics.Color
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewmodel.compose.viewModel
import androidx.lifecycle.viewmodel.initializer
import androidx.lifecycle.viewmodel.viewModelFactory
import it.apsemplice.app.AppContainer

val LocalContainer = staticCompositionLocalOf<AppContainer> { error("AppContainer non fornito") }

private val Green = Color(0xFF1F6F5C)

private val Light = lightColorScheme(primary = Green, secondary = Color(0xFF4A6B63))
private val Dark = darkColorScheme(primary = Color(0xFF7FD1BB), secondary = Color(0xFFB2CCC4))

@Composable
fun ApsTheme(content: @Composable () -> Unit) {
    MaterialTheme(colorScheme = if (isSystemInDarkTheme()) Dark else Light, content = content)
}

/** Crea un ViewModel passandogli il container delle dipendenze. */
@Composable
inline fun <reified VM : ViewModel> appViewModel(crossinline create: (AppContainer) -> VM): VM {
    val container = LocalContainer.current
    return viewModel(factory = viewModelFactory { initializer { create(container) } })
}
