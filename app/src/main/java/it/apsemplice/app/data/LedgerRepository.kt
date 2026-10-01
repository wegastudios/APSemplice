package it.apsemplice.app.data

import androidx.room.withTransaction
import it.apsemplice.app.core.SocialYear
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
import it.apsemplice.app.domain.PaymentCalc
import it.apsemplice.app.domain.PaymentSummary
import it.apsemplice.app.importer.ExistingMember
import it.apsemplice.app.importer.PlanAction
import it.apsemplice.app.importer.PlannedRow
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
    val memberCard: String? = null,
)

data class MemberInput(
    val cardNumber: String?,
    val firstName: String,
    val lastName: String,
    val taxCode: String?,
    val email: String?,
    val phone: String?,
)

class CardNumberTakenException(val card: String, val holderName: String) :
    Exception("La tessera $card è già assegnata a $holderName")

data class ImportResult(val created: Int, val updated: Int, val skipped: Int)

data class EnrollmentStatus(
    val enrollment: EnrollmentEntity,
    val member: MemberEntity,
    val activity: ActivityEntity,
    val summary: PaymentSummary,
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

    private fun socialYearOf(date: LocalDate) =
        SocialYear.forDate(date, settings.profile.socialYearStartMonth)

    // ---------- Osservazione ----------

    fun observeAccounts() = accountDao.observeAll()
    fun observeCategories() = categoryDao.observeAll()
    fun observeMembers() = memberDao.observeAll()
    fun observeActivities(year: String) = activityDao.observeForYear(year)
    fun observeAllActivities() = activityDao.observeAll()
    fun observeActivity(id: String) = activityDao.observe(id)
    /** Emette a ogni variazione delle iscrizioni alle attività (report e stati pagamento). */
    fun observeEnrollmentChanges() = enrollmentDao.observeAll()
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
                memberCard = it.memberId?.let { id -> m[id]?.cardNumber },
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
                    registerMembership(draft.memberId, socialYearOf(draft.date).label, tx.id)
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

    /** Socio senza tessera (es. creato al volo durante un incasso): il numero si assegna dopo. */
    suspend fun saveMember(firstName: String, lastName: String, email: String?, phone: String?, taxCode: String?): MemberEntity =
        createMember(MemberInput(null, firstName, lastName, taxCode, email, phone))

    suspend fun createMember(input: MemberInput): MemberEntity {
        val card = input.cardNumber.clean()
        checkCardFree(card, exceptMemberId = null)
        val m = MemberEntity(
            cardNumber = card,
            firstName = input.firstName.trim(), lastName = input.lastName.trim(),
            taxCode = input.taxCode.clean()?.uppercase(), email = input.email.clean(), phone = input.phone.clean(),
        )
        memberDao.upsert(m)
        return m
    }

    /** Modifica anagrafica e numero tessera. Lancia [CardNumberTakenException] se la tessera è di un altro socio. */
    suspend fun updateMember(id: String, input: MemberInput) {
        val current = memberDao.get(id) ?: return
        val card = input.cardNumber.clean()
        checkCardFree(card, exceptMemberId = id)
        memberDao.upsert(
            current.copy(
                cardNumber = card,
                firstName = input.firstName.trim(), lastName = input.lastName.trim(),
                taxCode = input.taxCode.clean()?.uppercase(), email = input.email.clean(), phone = input.phone.clean(),
                sync = current.sync.touch(),
            ),
        )
    }

    private fun String?.clean(): String? = this?.trim()?.ifEmpty { null }

    private suspend fun checkCardFree(card: String?, exceptMemberId: String?) {
        if (card == null) return
        val holder = memberDao.findByCard(card)
        if (holder != null && holder.id != exceptMemberId) throw CardNumberTakenException(card, holder.fullName)
    }

    /** Prossimo numero libero: massimo tra le tessere puramente numeriche + 1 (1 se non ce ne sono). */
    suspend fun nextFreeCardNumber(): String {
        val max = memberDao.allCardNumbers().mapNotNull { it.trim().toLongOrNull() }.maxOrNull() ?: 0L
        return (max + 1).toString()
    }

    fun observeMember(id: String) = memberDao.observe(id)
    fun observeMembership(memberId: String, year: String) = membershipDao.observeFor(memberId, year)
    fun observeEnrollmentsOfMember(memberId: String) = enrollmentDao.observeForMember(memberId)

    suspend fun isMember(memberId: String, date: LocalDate): Boolean =
        membershipDao.getForYear(socialYearOf(date).label).any { it.memberId == memberId }

    private suspend fun registerMembership(memberId: String, year: String, transactionId: String?) {
        val existing = membershipDao.find(memberId, year)
        if (existing == null) {
            membershipDao.upsert(MembershipEntity(memberId = memberId, socialYear = year, transactionId = transactionId))
        } else if (existing.sync.deletedAt != null) {
            membershipDao.upsert(existing.copy(transactionId = transactionId, sync = existing.sync.touch(restored = true)))
        }
    }

    /** Iscrizione all'associazione per l'anno sociale impostata a mano (senza incasso, es. soci già in regola). */
    suspend fun setMembership(memberId: String, year: String, enrolled: Boolean) {
        if (enrolled) {
            registerMembership(memberId, year, null)
        } else {
            membershipDao.find(memberId, year)?.takeIf { it.sync.deletedAt == null }?.let {
                membershipDao.upsert(it.copy(sync = it.sync.touch(deleted = true)))
            }
        }
    }

    // ---------- Import soci ----------

    suspend fun existingForImport(): List<ExistingMember> =
        memberDao.getAll().map { ExistingMember(it.id, it.cardNumber, it.firstName, it.lastName, it.taxCode) }

    /** Applica il piano di import (le righe in errore vengono saltate). Tutto o niente: transazione unica. */
    suspend fun applyImport(plan: List<PlannedRow>, markMembersForYear: SocialYear?): ImportResult {
        var created = 0
        var updated = 0
        db.withTransaction {
            for (p in plan) {
                val r = p.row
                when (p.action) {
                    PlanAction.ERROR -> Unit
                    PlanAction.CREATE -> {
                        val m = MemberEntity(
                            cardNumber = r.cardNumber, firstName = r.firstName.trim(), lastName = r.lastName.trim(),
                            taxCode = r.taxCode, email = r.email, phone = r.phone,
                        )
                        memberDao.upsert(m)
                        if (markMembersForYear != null) registerMembership(m.id, markMembersForYear.label, null)
                        created++
                    }
                    PlanAction.UPDATE -> {
                        val current = memberDao.get(p.matchedId!!) ?: continue
                        memberDao.upsert(
                            current.copy(
                                cardNumber = r.cardNumber ?: current.cardNumber,
                                firstName = r.firstName.trim(), lastName = r.lastName.trim(),
                                taxCode = r.taxCode ?: current.taxCode,
                                email = r.email ?: current.email,
                                phone = r.phone ?: current.phone,
                                sync = current.sync.touch(),
                            ),
                        )
                        if (markMembersForYear != null) registerMembership(current.id, markMembersForYear.label, null)
                        updated++
                    }
                }
            }
        }
        return ImportResult(created, updated, plan.count { it.action == PlanAction.ERROR })
    }

    suspend fun saveActivity(name: String, year: String, monthlyFeeCents: Long, instructorId: String?) {
        activityDao.upsert(
            ActivityEntity(name = name.trim(), socialYear = year, defaultMonthlyFeeCents = monthlyFeeCents, instructorMemberId = instructorId),
        )
    }

    // ---------- Iscrizioni alle attività e situazione pagamenti ----------

    /** Iscrive (o riattiva) un socio a un'attività: le mensilità sono dovute da [startMonth]. */
    suspend fun enroll(activityId: String, memberId: String, startMonth: YearMonth) {
        val existing = enrollmentDao.find(activityId, memberId)
        enrollmentDao.upsert(
            if (existing == null) {
                EnrollmentEntity(activityId = activityId, memberId = memberId, startMonth = startMonth.toString())
            } else {
                existing.copy(startMonth = startMonth.toString(), endMonth = null, sync = existing.sync.touch(restored = true))
            },
        )
    }

    /** Cancella il socio dall'attività: [lastMonth] è l'ultimo mese ancora dovuto. I pagamenti restano. */
    suspend fun cancelEnrollment(activityId: String, memberId: String, lastMonth: YearMonth) {
        val existing = enrollmentDao.find(activityId, memberId) ?: return
        enrollmentDao.upsert(existing.copy(endMonth = lastMonth.toString(), sync = existing.sync.touch()))
    }

    private fun summarize(activity: ActivityEntity, enrollment: EnrollmentEntity, txs: List<TransactionEntity>): PaymentSummary {
        val year = SocialYear.fromLabel(activity.socialYear, settings.profile.socialYearStartMonth)
        val paid = txs
            .filter { it.activityId == activity.id && it.memberId == enrollment.memberId && it.type == TxType.INCOME }
            .groupBy { YearMonth.parse(it.competenceMonth ?: it.date.take(7)) }
            .mapValues { (_, list) -> list.sumOf { it.amountCents } }
        return PaymentCalc.compute(
            monthlyFeeCents = activity.defaultMonthlyFeeCents,
            start = YearMonth.parse(enrollment.startMonth),
            end = enrollment.endMonth?.let { YearMonth.parse(it) },
            today = YearMonth.now(),
            year = year,
            paidByMonth = paid,
        )
    }

    /** Iscritti (attivi e cancellati) di un'attività con la loro situazione pagamenti. */
    suspend fun paymentStatusForActivity(activityId: String): List<EnrollmentStatus> {
        val activity = activityDao.get(activityId) ?: return emptyList()
        val members = memberDao.getAll().associateBy { it.id }
        val txs = txDao.forActivities(listOf(activityId))
        return enrollmentDao.getForActivity(activityId).mapNotNull { e ->
            val member = members[e.memberId] ?: return@mapNotNull null
            EnrollmentStatus(e, member, activity, summarize(activity, e, txs))
        }.sortedWith(compareBy({ !it.enrollment.isActive }, { it.member.lastName.lowercase() }, { it.member.firstName.lowercase() }))
    }

    /** Attività di un socio con la sua situazione pagamenti. */
    suspend fun paymentStatusForMember(memberId: String): List<EnrollmentStatus> {
        val member = memberDao.get(memberId) ?: return emptyList()
        val enrollments = enrollmentDao.getForMember(memberId)
        val activities = activityDao.getAll().associateBy { it.id }
        val txs = txDao.forActivities(enrollments.map { it.activityId })
        return enrollments.mapNotNull { e ->
            val activity = activities[e.activityId] ?: return@mapNotNull null
            EnrollmentStatus(e, member, activity, summarize(activity, e, txs))
        }.sortedByDescending { it.activity.socialYear }
    }

    /** Primo mese dovuto e non (del tutto) pagato: serve a proporlo in fase di incasso. */
    suspend fun firstUnpaidMonth(activityId: String, memberId: String): YearMonth? =
        paymentStatusForActivity(activityId).firstOrNull { it.member.id == memberId && it.enrollment.isActive }
            ?.summary?.unpaidMonths?.firstOrNull()?.month

    /** Id delle attività a cui il socio è iscritto (iscrizione attiva). */
    suspend fun activeActivityIds(memberId: String): Set<String> =
        enrollmentDao.getForMember(memberId).filter { it.isActive }.map { it.activityId }.toSet()

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
