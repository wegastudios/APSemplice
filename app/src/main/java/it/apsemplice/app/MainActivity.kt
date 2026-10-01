package it.apsemplice.app

import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.compose.runtime.CompositionLocalProvider
import it.apsemplice.app.ui.ApsTheme
import it.apsemplice.app.ui.ApsNavHost
import it.apsemplice.app.ui.LocalContainer

class MainActivity : ComponentActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        val container = (application as ApsApp).container
        setContent {
            CompositionLocalProvider(LocalContainer provides container) {
                ApsTheme { ApsNavHost() }
            }
        }
    }
}
