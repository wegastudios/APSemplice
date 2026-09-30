package it.apsemplice.app.data.db

import androidx.room.Embedded
import androidx.room.Entity
import androidx.room.Index
import androidx.room.PrimaryKey
import it.apsemplice.app.core.newId
import it.apsemplice.app.domain.AccountType
import it.apsemplice.app.domain.CategoryKind
import it.apsemplice.app.domain.PaymentMethod
import it.apsemplice.app.domain.TxType

/**
 * Metadati di sincronizzazione presenti su ogni tabella (predisposizione cloud).
 * deletedAt = cancellazione logica: niente si cancella davvero, per tracciabilità e sync.
 */
data class SyncMeta(
    val updatedAt: Long = System.currentTimeMillis(),
    val deletedAt: Long? = null,
    val dirty: Boolean = true,
    val remoteRev: Long = 0,
) {
    fun touch(deleted: Boolean = false, restored: Boolean = false): SyncMeta {
        val now = System.currentTimeMillis()
        return copy(
            updatedAt = now,
            dirty = true,
            deletedAt = when {
                restored -> null
                deleted -> now
                else -> deletedAt
            },
        )
    }
}

@Entity(tableName = "accounts")
data class AccountEntity(
    @PrimaryKey val id: String = newId(),
    val name: String,
    val type: AccountType,
    /** Saldo iniziale, cioè prima del primo movimento registrato nell'app. */
    val openingBalanceCents: Long = 0,
    val active: Boolean = true,
    val sortOrder: Int = 0,
    @Embedded val sync: SyncMeta = SyncMeta(),
)

@Entity(tableName = "members")
data class MemberEntity(
    @PrimaryKey val id: String = newId(),
    val firstName: String,
    val lastName: String,
    val taxCode: String? = null,
    val email: String? = null,
    val phone: String? = null,
    val notes: String? = null,
    @Embedded val sync: SyncMeta = SyncMeta(),
) {
    val fullName: String get() = "$firstName $lastName".trim()
}

/** Iscrizione all'associazione per un anno accademico (es. "2025/2026"). */
@Entity(tableName = "memberships", indices = [Index(value = ["memberId", "academicYear"], unique = true)])
data class MembershipEntity(
    @PrimaryKey val id: String = newId(),
    val memberId: String,
    val academicYear: String,
    val transactionId: String? = null,
    @Embedded val sync: SyncMeta = SyncMeta(),
)

@Entity(tableName = "activities", indices = [Index("academicYear")])
data class ActivityEntity(
    @PrimaryKey val id: String = newId(),
    val name: String,
    val academicYear: String,
    val instructorMemberId: String? = null,
    val defaultMonthlyFeeCents: Long = 0,
    val notes: String? = null,
    val active: Boolean = true,
    @Embedded val sync: SyncMeta = SyncMeta(),
)

@Entity(tableName = "enrollments", indices = [Index(value = ["activityId", "memberId"], unique = true)])
data class EnrollmentEntity(
    @PrimaryKey val id: String = newId(),
    val activityId: String,
    val memberId: String,
    @Embedded val sync: SyncMeta = SyncMeta(),
)

@Entity(tableName = "categories")
data class CategoryEntity(
    @PrimaryKey val id: String = newId(),
    val name: String,
    val kind: CategoryKind,
    /** Voce di rendiconto per il commercialista (raggruppamento nei report annuali). */
    val fiscalGroup: String? = null,
    val active: Boolean = true,
    @Embedded val sync: SyncMeta = SyncMeta(),
)

/**
 * Riga di prima nota. Un incasso con più voci (iscrizione + mensilità) sono più righe con lo stesso
 * [receiptId]; un giroconto sono due righe con lo stesso [transferId].
 * Il resto in contanti NON è contabilità: è solo un aiuto in fase di incasso.
 */
@Entity(
    tableName = "transactions",
    indices = [
        Index("date"), Index("accountId"), Index("categoryId"),
        Index("activityId"), Index("memberId"), Index("receiptId"), Index("transferId"),
    ],
)
data class TransactionEntity(
    @PrimaryKey val id: String = newId(),
    val date: String, // ISO yyyy-MM-dd
    val type: TxType,
    val amountCents: Long, // sempre positivo; il segno viene da type
    val accountId: String,
    val method: PaymentMethod,
    val categoryId: String,
    val activityId: String? = null,
    val memberId: String? = null, // chi paga / chi viene rimborsato
    val description: String = "",
    val competenceMonth: String? = null, // yyyy-MM, per le mensilità
    val documentRef: String? = null, // n. ricevuta / fattura / scontrino
    val receiptId: String? = null,
    val transferId: String? = null,
    val voidReason: String? = null,
    val createdAt: Long = System.currentTimeMillis(),
    @Embedded val sync: SyncMeta = SyncMeta(),
)

/** Verifica di cassa/conto: confronto tra saldo dell'app e saldo reale contato/da estratto conto. */
@Entity(tableName = "cash_counts", indices = [Index("accountId")])
data class CashCountEntity(
    @PrimaryKey val id: String = newId(),
    val accountId: String,
    val date: String,
    val countedCents: Long,
    val expectedCents: Long,
    val differenceCents: Long,
    val adjustmentTransactionId: String? = null,
    val notes: String? = null,
    @Embedded val sync: SyncMeta = SyncMeta(),
)
