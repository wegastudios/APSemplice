package it.apsemplice.app.core.cloud

import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow

data class UserSession(
    val userId: String,
    val email: String,
    val displayName: String?,
    val provider: String, // "google" | "local"
    val role: Role,
    val associationId: String?,
)

/**
 * Login. Oggi: solo modalità locale. Domani: Google Sign-In (Credential Manager) -> ID token
 * verificato dal backend APSemplice, che restituisce sessione, ruolo e associazione.
 * NB: l'app non dovrebbe mai fidarsi del solo token Google; ruoli e abbonamento arrivano dal backend.
 */
interface AuthProvider {
    val session: StateFlow<UserSession?>
    suspend fun signIn(): Result<UserSession>
    suspend fun signOut()
}

/** Nessun cloud: utente unico locale con pieni poteri. */
class LocalAuthProvider : AuthProvider {
    private val local = UserSession(
        userId = "local",
        email = "",
        displayName = null,
        provider = "local",
        role = Role.OWNER,
        associationId = null,
    )
    override val session: StateFlow<UserSession?> = MutableStateFlow(local)
    override suspend fun signIn(): Result<UserSession> = Result.success(local)
    override suspend fun signOut() = Unit
}
