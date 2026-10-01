package it.apsemplice.app.core.cloud

import java.time.LocalDate

enum class Plan { LOCAL_FREE, CLOUD_BASE, CLOUD_PLUS }

enum class SubscriptionStatus { NONE, TRIAL, ACTIVE, PAST_DUE, CANCELED }

/**
 * Verifica della titolarità: chi si registra deve dimostrare di rappresentare l'associazione.
 * Flusso previsto lato backend (vedi docs/API_CLOUD_E_WORDPRESS.md):
 *  1. l'utente inserisce codice fiscale dell'APS + ruolo (presidente/legale rappresentante);
 *  2. controllo di coerenza (CF, eventuale riscontro RUNTS / Registro Imprese / Agenzia Entrate);
 *  3. caricamento documento (statuto/verbale di nomina + documento d'identità) o invio PEC;
 *  4. approvazione manuale/automatica -> VERIFIED. Senza VERIFIED niente cloud condiviso.
 */
enum class OwnershipStatus { NOT_STARTED, PENDING, VERIFIED, REJECTED }

data class Entitlement(
    val plan: Plan,
    val status: SubscriptionStatus,
    val ownership: OwnershipStatus,
    val validUntil: LocalDate?,
    val cloudSyncAllowed: Boolean,
    val wordpressPluginAllowed: Boolean,
)

interface LicenseService {
    suspend fun current(): Entitlement
}

/** Modalità locale: tutte le funzioni offline disponibili, nessun cloud. */
class LocalLicenseService : LicenseService {
    override suspend fun current() = Entitlement(
        plan = Plan.LOCAL_FREE,
        status = SubscriptionStatus.NONE,
        ownership = OwnershipStatus.NOT_STARTED,
        validUntil = null,
        cloudSyncAllowed = false,
        wordpressPluginAllowed = false,
    )
}
