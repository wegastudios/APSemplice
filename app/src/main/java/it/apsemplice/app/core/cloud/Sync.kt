package it.apsemplice.app.core.cloud

/**
 * Sincronizzazione offline-first. Ogni riga del database ha già i metadati necessari
 * (id UUID, updatedAt, deletedAt, dirty, remoteRev: vedi data/db/Entities.kt).
 *
 * Protocollo previsto (dettagli in docs/API_CLOUD_E_WORDPRESS.md):
 *  - push: invia le righe con dirty = true, in ordine di updatedAt;
 *  - pull: chiede le modifiche del server con remoteRev > ultimo cursore;
 *  - conflitti: vince l'updatedAt più recente per anagrafiche; i movimenti di prima nota
 *    sono append-only (si annullano, non si modificano) quindi non confliggono.
 */
sealed interface SyncResult {
    data class Done(val pushed: Int, val pulled: Int) : SyncResult
    data object Disabled : SyncResult
    data class Failed(val reason: String) : SyncResult
}

interface SyncEngine {
    suspend fun sync(): SyncResult
}

class NoOpSyncEngine : SyncEngine {
    override suspend fun sync(): SyncResult = SyncResult.Disabled
}
