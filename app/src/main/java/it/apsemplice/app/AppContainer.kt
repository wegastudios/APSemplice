package it.apsemplice.app

import android.app.Application
import android.content.Context
import it.apsemplice.app.core.cloud.AuthProvider
import it.apsemplice.app.core.cloud.LicenseService
import it.apsemplice.app.core.cloud.LocalAuthProvider
import it.apsemplice.app.core.cloud.LocalLicenseService
import it.apsemplice.app.core.cloud.NoOpSyncEngine
import it.apsemplice.app.core.cloud.SyncEngine
import it.apsemplice.app.data.AppSettings
import it.apsemplice.app.data.LedgerRepository
import it.apsemplice.app.data.ReportService
import it.apsemplice.app.data.db.AppDatabase
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.launch

/** Composizione manuale delle dipendenze (niente framework DI: meno magia, più chiarezza). */
class AppContainer(context: Context) {
    val settings = AppSettings(context)
    private val db = AppDatabase.build(context)
    val repo = LedgerRepository(db, settings)
    val reports = ReportService(db, settings)

    // Cloud: oggi sempre le implementazioni locali. Quando BuildConfig.CLOUD_ENABLED sarà true
    // qui si sostituiranno con Google Sign-In + backend APSemplice (vedi docs/).
    val auth: AuthProvider = LocalAuthProvider()
    val license: LicenseService = LocalLicenseService()
    val sync: SyncEngine = NoOpSyncEngine()
}

class ApsApp : Application() {
    lateinit var container: AppContainer
        private set

    override fun onCreate() {
        super.onCreate()
        container = AppContainer(this)
        CoroutineScope(SupervisorJob() + Dispatchers.IO).launch { container.repo.seedIfNeeded() }
    }
}
