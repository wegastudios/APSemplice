package it.apsemplice.app.data

import it.apsemplice.app.core.SocialYear
import it.apsemplice.app.data.db.AccountEntity
import it.apsemplice.app.data.db.ActivityEntity
import it.apsemplice.app.data.db.AppDatabase
import it.apsemplice.app.data.db.TransactionEntity
import it.apsemplice.app.domain.TxType
import java.time.LocalDate

data class AccountSummary(
    val account: AccountEntity,
    val openingCents: Long,
    val incomeCents: Long,
    val expenseCents: Long,
    val transfersNetCents: Long,
) {
    val closingCents: Long get() = openingCents + incomeCents - expenseCents + transfersNetCents
}

data class CategoryTotal(val categoryName: String, val fiscalGroup: String?, val cents: Long)

/** Rendiconto per cassa di un periodo (anno solare per il commercialista). */
data class PeriodReport(
    val from: LocalDate,
    val to: LocalDate,
    val accounts: List<AccountSummary>,
    val income: List<CategoryTotal>,
    val expenses: List<CategoryTotal>,
) {
    val totalIncome: Long get() = income.sumOf { it.cents }
    val totalExpense: Long get() = expenses.sumOf { it.cents }
    val result: Long get() = totalIncome - totalExpense
    val openingTotal: Long get() = accounts.sumOf { it.openingCents }
    val closingTotal: Long get() = accounts.sumOf { it.closingCents }
}

data class ActivitySummary(
    val activity: ActivityEntity,
    val participants: Int,
    val incomeCents: Long,
    val costCents: Long,
) {
    /** Quanto resta all'associazione da questa attività. */
    val marginCents: Long get() = incomeCents - costCents
}

/** Valutazione per anno sociale: cosa rende ogni attività e cosa resta all'associazione. */
data class SocialYearReport(
    val year: SocialYear,
    val activities: List<ActivitySummary>,
    val membersCount: Int,
    val generalIncome: List<CategoryTotal>, // quote associative, liberalità... non legate ad attività
    val generalExpenses: List<CategoryTotal>,
) {
    val activitiesMargin: Long get() = activities.sumOf { it.marginCents }
    val generalIncomeTotal: Long get() = generalIncome.sumOf { it.cents }
    val generalExpenseTotal: Long get() = generalExpenses.sumOf { it.cents }
    val totalIncome: Long get() = activities.sumOf { it.incomeCents } + generalIncomeTotal
    val totalExpense: Long get() = activities.sumOf { it.costCents } + generalExpenseTotal
    val result: Long get() = totalIncome - totalExpense
}

class ReportService(
    private val db: AppDatabase,
    private val settings: AppSettings,
) {
    fun currentSocialYear(): SocialYear =
        SocialYear.forDate(LocalDate.now(), settings.profile.socialYearStartMonth)

    fun socialYear(startYear: Int) = SocialYear(startYear, settings.profile.socialYearStartMonth)

    suspend fun periodReport(from: LocalDate, to: LocalDate): PeriodReport {
        val accounts = db.accountDao().getAll()
        val categories = db.categoryDao().getAll().associateBy { it.id }
        val txs = db.transactionDao().between(from.toString(), to.toString())
        val before = db.transactionDao().deltasUpTo(from.minusDays(1).toString()).associate { it.accountId to it.delta }

        val summaries = accounts.map { account ->
            val own = txs.filter { it.accountId == account.id }
            AccountSummary(
                account = account,
                openingCents = account.openingBalanceCents + (before[account.id] ?: 0L),
                incomeCents = own.filter { it.type == TxType.INCOME }.sumOf { it.amountCents },
                expenseCents = own.filter { it.type == TxType.EXPENSE }.sumOf { it.amountCents },
                transfersNetCents = own.filter { it.type.isTransfer }.sumOf { it.type.sign * it.amountCents },
            )
        }
        fun totals(type: TxType) = txs.filter { it.type == type }
            .groupBy { it.categoryId }
            .map { (id, list) ->
                val c = categories[id]
                CategoryTotal(c?.name ?: "?", c?.fiscalGroup, list.sumOf { it.amountCents })
            }
            .sortedWith(compareBy({ it.fiscalGroup ?: "" }, { it.categoryName }))

        return PeriodReport(from, to, summaries, totals(TxType.INCOME), totals(TxType.EXPENSE))
    }

    suspend fun socialYearReport(year: SocialYear): SocialYearReport {
        val activities = db.activityDao().getForYear(year.label)
        val categories = db.categoryDao().getAll().associateBy { it.id }
        val activityTx: List<TransactionEntity> =
            db.transactionDao().forActivities(activities.map { it.id })
        val periodTx = db.transactionDao().between(year.start.toString(), year.end.toString())
        val general = periodTx.filter { it.activityId == null && !it.type.isTransfer }

        val summaries = activities.map { a ->
            val own = activityTx.filter { it.activityId == a.id }
            ActivitySummary(
                activity = a,
                participants = db.enrollmentDao().getForActivity(a.id).count { it.isActive },
                incomeCents = own.filter { it.type == TxType.INCOME }.sumOf { it.amountCents },
                costCents = own.filter { it.type == TxType.EXPENSE }.sumOf { it.amountCents },
            )
        }
        fun totals(type: TxType) = general.filter { it.type == type }
            .groupBy { it.categoryId }
            .map { (id, list) ->
                val c = categories[id]
                CategoryTotal(c?.name ?: "?", c?.fiscalGroup, list.sumOf { it.amountCents })
            }
            .sortedBy { it.categoryName }

        return SocialYearReport(
            year = year,
            activities = summaries,
            membersCount = db.membershipDao().getForYear(year.label).size,
            generalIncome = totals(TxType.INCOME),
            generalExpenses = totals(TxType.EXPENSE),
        )
    }
}
