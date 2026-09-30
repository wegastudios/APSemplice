package it.apsemplice.app.data

import androidx.room.withTransaction
import it.apsemplice.app.core.AcademicYear
import it.apsemplice.app.core.newId
import it.apsemplice.app.data.db.AccountEntity
import it.apsemplice.app.data.db.ActivityEntity
import it.apsemplice.app.data.db.AppDatabase
import it.apsemplice.app.data.db.CashCountEntity
import it.apsemplice.app.data.db.CategoryEntity
import it.apsemplice.app.data.db.EnrollmentEntity
import it.apsemplice.app.data.db.MemberEntity
import it.apsemplice.app.data.db.MembershipEntity
import it.apsemplice.app.data.db.TransactionEntity
import it.apsemplice.app.domain.AccountType
import it.apsemplice.app.domain.CategoryKind
import it.apsemplice.app.domain.PaymentMethod
import it.apsemplice.app.domain.TxType
import kotlinx.coroutines.flow.Flow
import kotlinx.coroutines.flow.combine
import java.time.LocalDate
import java.time.YearMonth

data class AccountBalance(val account: AccountEntity, val balanceCents: Long)

/** Riga di prima nota con i nomi già risolti (per liste ed export). */
data class TxRow(
    val tx: TransactionEntity,
    val accountName: String,
    val categoryName: String,
    val memberName: String?,
    val activityName: String?,
)

data class ReceiptLine(
    val categoryId: String,
    val amountCents: Long,
    val activityId: String? = null,
    val competenceMonth: YearMonth? = null,
    val description: String = "",
)

/** Incasso da un socio/cliente: una o più voci, un solo conto e una sola modalità. */
data class ReceiptDraft(
    val date: LocalDate,
    val accountId: String,
    val method: PaymentMethod,
    val memberId: String?,
    val lines: List<ReceiptLine>,
    val documentRef: String? = null,
)

data class ExpenseDraft(
    val date: LocalDate,
    val accountId: String,
    val method: PaymentMethod,
    val categoryId: String,
    val amountCents: Long,
    val activityId: String? = null,
    val memberId: String? = null,
    val description: String = "",
    val documentRef: String? = null,
)

class LedgerRepository(
    private val db: AppDatabase,
    private val settings: AppSettings,
) {
    private val accountDao = db.accountDao()
    private val memberDao = db.memberDao()
    private val membershipDao = db.membershipDao()
    private val activityDao = db.activityDao()
    private val enrollmentDao = db.enrollmentDao()
    private val categoryDao = db.categoryDao()
    private val txDao = db.transactionDao()
    private val cashCountDao = db.cashCountDao()

    private fun academicYearOf(date: LocalDate) =
        AcademicYear.forDate(date, settings.profile.academicYearStartMonth)

    // ---------- Osservazione ----------

    fun observeAccounts() = accountDao.observeAll()
    fun observeCategories() = categoryDao.observeAll()
    fun observeMembers() = memberDao.observeAll()
    fun observeActivities(year: String) = activityDao.observeForYear(year)
    fun observeAllActivities() = activityDao.observeAll()
    fun observeMemberships(year: String) = membershipDao.observeForYear(year)
    fun observeEnrollments(activityId: String) = enrollmentDao.observeForActivity(activityId)
    fun observeCashCounts(accountId: String) = cashCountDao.observeForAccount(accountId)

    /** Emette a ogni modifica della prima nota: serve a far ricalcolare i report. */
    fun observeChanges(): Flow<Int> = txDao.observeCount()

    fun observeBalances(): Flow<List<AccountBalance>> =
        combine(accountDao.observeAll(), txDao.observeDeltas()) { accounts, deltas ->
            val byAccount = deltas.associate { it.accountId to it.delta }
            accounts.map { AccountBalance(it, it.openingBalanceCents + (byAccount[it.id] ?: 0L)) }
        }

    fun observeRows(from: LocalDate, to: LocalDate): Flow<List<TxRow>> =
        combine(
            txDao.observeBetween(from.toString(), to.toString()),
            accountDao.observeAll(),
            categoryDao.observeAll(),
            memberDao.observeAll(),
            activityDao.observeAll(),
        ) { txs, accounts, categories, members, activities ->
            toRows(txs, accounts, categories, members, activities)
        }

    suspend fun rows(from: LocalDate, to: LocalDate): List<TxRow> = toRows(
        txDao.between(from.toString(), to.toString()), // in ordine cronologico
        accountDao.getAll(),
        categoryDao.getAll(),
        memberDao.getAll(),
        activityDao.getAll(),
    )

    private fun toRows(
        txs: List<TransactionEntity>,
        accounts: List<AccountEntity>,
        categories: List<CategoryEntity>,
        members: List<MemberEntity>,
        activities: List<ActivityEntity>,
    ): List<TxRow> {
        val a = accounts.associateBy { it.id }
        val c = categories.associateBy { it.id }
        val m = members.associateBy { it.id }
        val act = activities.associateBy { it.id }
        return txs.map {
            TxRow(
                tx = it,
                accountName = a[it.accountId]?.name ?: "?",
                categoryName = c[it.categoryId]?.name ?: "?",
                memberName = it.memberId?.let { id -> m[id]?.fullName },
                activityName = it.activityId?.let { id -> act[id]?.name },
            )
        }
    }

    // ---------- Registrazione movimenti ----------

    suspend fun recordReceipt(draft: ReceiptDraft) {
        require(draft.lines.isNotEmpty()) { "Nessuna voce" }
        require(draft.lines.all { it.amountCents > 0 }) { "Importi non validi" }
        val categories = categoryDao.getAll().associateBy { it.id }
        val receiptId = newId()
        db.withTransaction {
            for (line in draft.lines) {
                val category = categories.getValue(line.categoryId)
                val tx = TransactionEntity(
                    date = draft.date.toString(),
                    type = TxType.INCOME,
                    amountCents = line.amountCents,
                    accountId = draft.accountId,
                    method = draft.method,
                    categoryId = line.categoryId,
                    activityId = line.activityId,
                    memberId = draft.memberId,
                    description = line.description,
                    competenceMonth = line.competenceMonth?.toString(),
                    documentRef = draft.documentRef,
                    receiptId = receiptId,
                )
                txDao.upsert(tx)
                if (category.kind == CategoryKind.MEMBERSHIP && draft.memberId != null) {
                    registerMembership(draft.memberId, academicYearOf(draft.date).label, tx.id)
                }
            }
        }
    }

    suspend fun recordExpense(draft: ExpenseDraft) {
        require(draft.amountCents > 0) { "Importo non valido" }
        txDao.upsert(
            TransactionEntity(
                date = draft.date.toString(),
                type = TxType.EXPENSE,
                amountCents = draft.amountCents,
                accountId = draft.accountId,
                method = draft.method,
                categoryId = draft.categoryId,
                activityId = draft.activityId,
                memberId = draft.memberId,
                description = draft.description,
                documentRef = draft.documentRef,
            ),
        )
    }

    /** Giroconto (es. versamento contanti in banca, accredito POS): non è né entrata né uscita. */
    suspend fun recordTransfer(
        date: LocalDate,
        fromAccountId: String,
        toAccountId: String,
        amountCents: Long,
        method: PaymentMethod,
        description: String,
    ) {
        require(amountCents > 0) { "Importo non valido" }
        require(fromAccountId != toAccountId) { "Conti uguali" }
        val adjustmentCategory = transferCategoryId()
        val transferId = newId()
        txDao.upsertAll(
            listOf(
                TransactionEntity(
                    date = date.toString(), type = TxType.TRANSFER_OUT, amountCents = amountCents,
                    accountId = fromAccountId, method = method, categoryId = adjustmentCategory,
                    description = description, transferId = transferId,
                ),
                TransactionEntity(
                    date = date.toString(), type = TxType.TRANSFER_IN, amountCents = amountCents,
                    accountId = toAccountId, method = method, categoryId = adjustmentCategory,
                    description = description, transferId = transferId,
                ),
            ),
        )
    }

    /** Annulla (senza cancellare) un movimento; per i giroconti annulla entrambe le righe. */
    suspend fun voidTransaction(id: String, reason: String) {
        db.withTransaction {
            val tx = txDao.get(id) ?: return@withTransaction
            val targets = tx.transferId?.let { txDao.byTransfer(it) } ?: listOf(tx)
            for (t in targets) {
                txDao.upsert(t.copy(voidReason = reason, sync = t.sync.touch(deleted = true)))
                membershipDao.byTransaction(t.id)?.let {
                    membershipDao.upsert(it.copy(sync = it.sync.touch(deleted = true)))
                }
            }
        }
    }

    // ---------- Verifica cassa ----------

    suspend fun expectedBalance(accountId: String, upTo: LocalDate): Long {
        val account = accountDao.get(accountId) ?: return 0
        val delta = txDao.deltasUpTo(upTo.toString()).firstOrNull { it.accountId == accountId }?.delta ?: 0
        return account.openingBalanceCents + delta
    }

    /**
     * Registra il confronto tra saldo app e saldo reale. Con [adjust] genera anche il movimento
     * di rettifica che riallinea l'app alla realtà (entrata o uscita di differenza).
     */
    suspend fun recordCashCount(accountId: String, date: LocalDate, countedCents: Long, adjust: Boolean, notes: String?) {
        db.withTransaction {
            val expected = expectedBalance(accountId, date)
            val diff = countedCents - expected
            var adjustmentId: String? = null
            if (adjust && diff != 0L) {
                val account = accountDao.get(accountId)
                val category = categoryDao.getAll().first { it.kind == CategoryKind.ADJUSTMENT }
                val adj = TransactionEntity(
                    date = date.toString(),
                    type = if (diff > 0) TxType.INCOME else TxType.EXPENSE,
                    amountCents = kotlin.math.abs(diff),
                    accountId = accountId,
                    method = if (account?.type == AccountType.CASH) PaymentMethod.CASH else PaymentMethod.OTHER,
                    categoryId = category.id,
                    description = "Rettifica da verifica saldo",
                )
                txDao.upsert(adj)
                adjustmentId = adj.id
            }
            cashCountDao.upsert(
                CashCountEntity(
                    accountId = accountId, date = date.toString(), countedCents = countedCents,
                    expectedCents = expected, differenceCents = diff,
                    adjustmentTransactionId = adjustmentId, notes = notes,
                ),
            )
        }
    }

    // ---------- Anagrafiche ----------

    suspend fun saveAccount(name: String, type: AccountType, openingCents: Long) {
        accountDao.upsert(AccountEntity(name = name.trim(), type = type, openingBalanceCents = openingCents, sortOrder = accountDao.count()))
    }

    suspend fun saveMember(firstName: String, lastName: String, email: String?, phone: String?, taxCode: String?): MemberEntity {
        val m = MemberEntity(
            firstName = firstName.trim(), lastName = lastName.trim(),
            email = email?.trim()?.ifEmpty { null }, phone = phone?.trim()?.ifEmpty { null },
            taxCode = taxCode?.trim()?.uppercase()?.ifEmpty { null },
        )
        memberDao.upsert(m)
        return m
    }

    suspend fun isMember(memberId: String, date: LocalDate): Boolean =
        membershipDao.getForYear(academicYearOf(date).label).any { it.memberId == memberId }

    private suspend fun registerMembership(memberId: String, year: String, transactionId: String) {
        val existing = membershipDao.find(memberId, year)
        if (existing == null) {
            membershipDao.upsert(MembershipEntity(memberId = memberId, academicYear = year, transactionId = transactionId))
        } else if (existing.sync.deletedAt != null) {
            membershipDao.upsert(existing.copy(transactionId = transactionId, sync = existing.sync.touch(restored = true)))
        }
    }

    suspend fun saveActivity(name: String, year: String, monthlyFeeCents: Long, instructorId: String?) {
        activityDao.upsert(
            ActivityEntity(name = name.trim(), academicYear = year, defaultMonthlyFeeCents = monthlyFeeCents, instructorMemberId = instructorId),
        )
    }

    suspend fun setEnrollment(activityId: String, memberId: String, enrolled: Boolean) {
        val existing = enrollmentDao.find(activityId, memberId)
        when {
            existing == null && enrolled -> enrollmentDao.upsert(EnrollmentEntity(activityId = activityId, memberId = memberId))
            existing != null -> enrollmentDao.upsert(
                existing.copy(sync = existing.sync.touch(deleted = !enrolled, restored = enrolled)),
            )
        }
    }

    // ---------- Dati iniziali ----------

    suspend fun seedIfNeeded() {
        if (categoryDao.count() == 0) {
            categoryDao.upsertAll(
                listOf(
                    CategoryEntity(name = "Quota associativa", kind = CategoryKind.MEMBERSHIP, fiscalGroup = "Entrate da quote associative"),
                    CategoryEntity(name = "Quota attività / corso", kind = CategoryKind.ACTIVITY_FEE, fiscalGroup = "Entrate da attività di interesse generale"),
                    CategoryEntity(name = "Erogazione liberale", kind = CategoryKind.DONATION, fiscalGroup = "Erogazioni liberali"),
                    CategoryEntity(name = "Altre entrate", kind = CategoryKind.OTHER_INCOME, fiscalGroup = "Altre entrate"),
                    CategoryEntity(name = "Compenso / rimborso istruttore", kind = CategoryKind.INSTRUCTOR_REIMBURSEMENT, fiscalGroup = "Uscite da attività di interesse generale"),
                    CategoryEntity(name = "Rimborso spese socio", kind = CategoryKind.MEMBER_REIMBURSEMENT, fiscalGroup = "Uscite da attività di interesse generale"),
                    CategoryEntity(name = "Costi attività (materiali, noleggi)", kind = CategoryKind.ACTIVITY_COST, fiscalGroup = "Uscite da attività di interesse generale"),
                    CategoryEntity(name = "Costi generali (affitto, utenze, assicurazione)", kind = CategoryKind.GENERAL_COST, fiscalGroup = "Uscite di supporto generale"),
                    CategoryEntity(name = "Rettifica di cassa", kind = CategoryKind.ADJUSTMENT, fiscalGroup = "Rettifiche"),
                ),
            )
        }
        if (accountDao.count() == 0) {
            accountDao.upsert(AccountEntity(name = "Cassa contanti", type = AccountType.CASH, sortOrder = 0))
            accountDao.upsert(AccountEntity(name = "Conto corrente", type = AccountType.BANK, sortOrder = 1))
        }
    }

    /** Le righe di giroconto usano la categoria "Rettifica di cassa" come segnaposto neutro. */
    private suspend fun transferCategoryId(): String =
        categoryDao.getAll().first { it.kind == CategoryKind.ADJUSTMENT }.id
}
