package it.apsemplice.app.core.cloud

/**
 * Ruoli condivisi tra app Android, backend e plugin WordPress.
 * In modalità locale l'utente è sempre OWNER.
 */
enum class Role(val label: String) {
    OWNER("Titolare"),         // gestisce abbonamento, utenti, tutto
    ADMIN("Amministratore"),   // tutto tranne abbonamento
    TREASURER("Tesoriere"),    // registra e annulla movimenti, report, export
    OPERATOR("Operatore"),     // registra incassi/spese, non annulla né esporta
    VIEWER("Sola lettura"),    // consulta
}

enum class Permission {
    VIEW,
    RECORD_ENTRY,
    VOID_ENTRY,
    MANAGE_ACCOUNTS,
    MANAGE_MEMBERS,
    MANAGE_ACTIVITIES,
    EXPORT_REPORTS,
    MANAGE_USERS,
    MANAGE_SUBSCRIPTION,
}

object Permissions {
    private val matrix: Map<Role, Set<Permission>> = mapOf(
        Role.OWNER to Permission.entries.toSet(),
        Role.ADMIN to Permission.entries.toSet() - Permission.MANAGE_SUBSCRIPTION,
        Role.TREASURER to setOf(
            Permission.VIEW, Permission.RECORD_ENTRY, Permission.VOID_ENTRY,
            Permission.MANAGE_MEMBERS, Permission.MANAGE_ACTIVITIES, Permission.EXPORT_REPORTS,
        ),
        Role.OPERATOR to setOf(Permission.VIEW, Permission.RECORD_ENTRY, Permission.MANAGE_MEMBERS),
        Role.VIEWER to setOf(Permission.VIEW),
    )

    fun can(role: Role, permission: Permission): Boolean = permission in matrix.getValue(role)
}
