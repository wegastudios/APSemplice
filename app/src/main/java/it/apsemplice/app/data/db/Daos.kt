package it.apsemplice.app.data.db

import androidx.room.Dao
import androidx.room.Query
import androidx.room.Upsert
import kotlinx.coroutines.flow.Flow

data class AccountDelta(val accountId: String, val delta: Long)

private const val DELTA_SUM =
    "SUM(CASE WHEN type IN ('INCOME','TRANSFER_IN') THEN amountCents ELSE -amountCents END)"

@Dao
interface AccountDao {
    @Query("SELECT * FROM accounts WHERE deletedAt IS NULL ORDER BY sortOrder, name")
    fun observeAll(): Flow<List<AccountEntity>>

    @Query("SELECT * FROM accounts WHERE deletedAt IS NULL ORDER BY sortOrder, name")
    suspend fun getAll(): List<AccountEntity>

    @Query("SELECT * FROM accounts WHERE id = :id")
    suspend fun get(id: String): AccountEntity?

    @Query("SELECT COUNT(*) FROM accounts")
    suspend fun count(): Int

    @Upsert
    suspend fun upsert(entity: AccountEntity)
}

@Dao
interface MemberDao {
    @Query("SELECT * FROM members WHERE deletedAt IS NULL ORDER BY lastName, firstName")
    fun observeAll(): Flow<List<MemberEntity>>

    @Query("SELECT * FROM members WHERE deletedAt IS NULL ORDER BY lastName, firstName")
    suspend fun getAll(): List<MemberEntity>

    @Query("SELECT * FROM members WHERE id = :id")
    fun observe(id: String): Flow<MemberEntity?>

    @Query("SELECT * FROM members WHERE id = :id")
    suspend fun get(id: String): MemberEntity?

    /** Confronto senza maiuscole/minuscole: "a12" e "A12" sono la stessa tessera. */
    @Query("SELECT * FROM members WHERE deletedAt IS NULL AND UPPER(cardNumber) = UPPER(:card) LIMIT 1")
    suspend fun findByCard(card: String): MemberEntity?

    @Query("SELECT cardNumber FROM members WHERE deletedAt IS NULL AND cardNumber IS NOT NULL")
    suspend fun allCardNumbers(): List<String>

    @Upsert
    suspend fun upsert(entity: MemberEntity)

    @Upsert
    suspend fun upsertAll(entities: List<MemberEntity>)
}

@Dao
interface MembershipDao {
    @Query("SELECT * FROM memberships WHERE socialYear = :year AND deletedAt IS NULL")
    fun observeForYear(year: String): Flow<List<MembershipEntity>>

    @Query("SELECT * FROM memberships WHERE socialYear = :year AND deletedAt IS NULL")
    suspend fun getForYear(year: String): List<MembershipEntity>

    /** Include le righe annullate: serve a riattivarle rispettando l'indice univoco. */
    @Query("SELECT * FROM memberships WHERE memberId = :memberId AND socialYear = :year")
    suspend fun find(memberId: String, year: String): MembershipEntity?

    @Query("SELECT * FROM memberships WHERE memberId = :memberId AND socialYear = :year AND deletedAt IS NULL")
    fun observeFor(memberId: String, year: String): Flow<MembershipEntity?>

    @Query("SELECT * FROM memberships WHERE transactionId = :transactionId AND deletedAt IS NULL")
    suspend fun byTransaction(transactionId: String): MembershipEntity?

    @Upsert
    suspend fun upsert(entity: MembershipEntity)
}

@Dao
interface ActivityDao {
    @Query("SELECT * FROM activities WHERE socialYear = :year AND deletedAt IS NULL ORDER BY name")
    fun observeForYear(year: String): Flow<List<ActivityEntity>>

    @Query("SELECT * FROM activities WHERE socialYear = :year AND deletedAt IS NULL ORDER BY name")
    suspend fun getForYear(year: String): List<ActivityEntity>

    @Query("SELECT * FROM activities WHERE id = :id")
    suspend fun get(id: String): ActivityEntity?

    @Query("SELECT * FROM activities WHERE id = :id")
    fun observe(id: String): Flow<ActivityEntity?>

    @Query("SELECT * FROM activities WHERE deletedAt IS NULL ORDER BY socialYear DESC, name")
    fun observeAll(): Flow<List<ActivityEntity>>

    @Query("SELECT * FROM activities WHERE deletedAt IS NULL ORDER BY socialYear DESC, name")
    suspend fun getAll(): List<ActivityEntity>

    @Upsert
    suspend fun upsert(entity: ActivityEntity)
}

@Dao
interface EnrollmentDao {
    @Query("SELECT * FROM enrollments WHERE activityId = :activityId AND deletedAt IS NULL")
    fun observeForActivity(activityId: String): Flow<List<EnrollmentEntity>>

    @Query("SELECT * FROM enrollments WHERE activityId = :activityId AND deletedAt IS NULL")
    suspend fun getForActivity(activityId: String): List<EnrollmentEntity>

    @Query("SELECT * FROM enrollments WHERE memberId = :memberId AND deletedAt IS NULL")
    fun observeForMember(memberId: String): Flow<List<EnrollmentEntity>>

    @Query("SELECT * FROM enrollments WHERE deletedAt IS NULL")
    fun observeAll(): Flow<List<EnrollmentEntity>>

    @Query("SELECT * FROM enrollments WHERE memberId = :memberId AND deletedAt IS NULL")
    suspend fun getForMember(memberId: String): List<EnrollmentEntity>

    @Query("SELECT * FROM enrollments WHERE activityId = :activityId AND memberId = :memberId")
    suspend fun find(activityId: String, memberId: String): EnrollmentEntity?

    @Upsert
    suspend fun upsert(entity: EnrollmentEntity)
}

@Dao
interface CategoryDao {
    @Query("SELECT * FROM categories WHERE deletedAt IS NULL AND active = 1 ORDER BY name")
    fun observeAll(): Flow<List<CategoryEntity>>

    @Query("SELECT * FROM categories WHERE deletedAt IS NULL ORDER BY name")
    suspend fun getAll(): List<CategoryEntity>

    @Query("SELECT COUNT(*) FROM categories")
    suspend fun count(): Int

    @Upsert
    suspend fun upsert(entity: CategoryEntity)

    @Upsert
    suspend fun upsertAll(entities: List<CategoryEntity>)
}

@Dao
interface TransactionDao {
    @Query("SELECT * FROM transactions WHERE deletedAt IS NULL AND date BETWEEN :from AND :to ORDER BY date DESC, createdAt DESC")
    fun observeBetween(from: String, to: String): Flow<List<TransactionEntity>>

    @Query("SELECT * FROM transactions WHERE deletedAt IS NULL AND date BETWEEN :from AND :to ORDER BY date, createdAt")
    suspend fun between(from: String, to: String): List<TransactionEntity>

    @Query("SELECT * FROM transactions WHERE deletedAt IS NULL AND activityId IN (:activityIds)")
    suspend fun forActivities(activityIds: List<String>): List<TransactionEntity>

    @Query("SELECT * FROM transactions WHERE id = :id")
    suspend fun get(id: String): TransactionEntity?

    @Query("SELECT * FROM transactions WHERE transferId = :transferId AND deletedAt IS NULL")
    suspend fun byTransfer(transferId: String): List<TransactionEntity>

    @Query("SELECT COUNT(*) FROM transactions WHERE deletedAt IS NULL")
    fun observeCount(): Flow<Int>

    /** Variazione per conto di tutti i movimenti (saldo attuale = saldo iniziale + delta). */
    @Query("SELECT accountId, $DELTA_SUM AS delta FROM transactions WHERE deletedAt IS NULL GROUP BY accountId")
    fun observeDeltas(): Flow<List<AccountDelta>>

    /** Variazione per conto fino a una data inclusa (saldi a inizio/fine periodo). */
    @Query("SELECT accountId, $DELTA_SUM AS delta FROM transactions WHERE deletedAt IS NULL AND date <= :upTo GROUP BY accountId")
    suspend fun deltasUpTo(upTo: String): List<AccountDelta>

    @Upsert
    suspend fun upsert(entity: TransactionEntity)

    @Upsert
    suspend fun upsertAll(entities: List<TransactionEntity>)
}

@Dao
interface CashCountDao {
    @Query("SELECT * FROM cash_counts WHERE accountId = :accountId AND deletedAt IS NULL ORDER BY date DESC")
    fun observeForAccount(accountId: String): Flow<List<CashCountEntity>>

    @Upsert
    suspend fun upsert(entity: CashCountEntity)
}
