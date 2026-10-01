package it.apsemplice.app.data.db

import android.content.Context
import androidx.room.Database
import androidx.room.Room
import androidx.room.RoomDatabase

@Database(
    entities = [
        AccountEntity::class,
        MemberEntity::class,
        MembershipEntity::class,
        ActivityEntity::class,
        EnrollmentEntity::class,
        CategoryEntity::class,
        TransactionEntity::class,
        CashCountEntity::class,
    ],
    version = 2,
    exportSchema = true,
)
abstract class AppDatabase : RoomDatabase() {
    abstract fun accountDao(): AccountDao
    abstract fun memberDao(): MemberDao
    abstract fun membershipDao(): MembershipDao
    abstract fun activityDao(): ActivityDao
    abstract fun enrollmentDao(): EnrollmentDao
    abstract fun categoryDao(): CategoryDao
    abstract fun transactionDao(): TransactionDao
    abstract fun cashCountDao(): CashCountDao

    companion object {
        fun build(context: Context): AppDatabase =
            Room.databaseBuilder(context, AppDatabase::class.java, "apsemplice.db")
                // FASE BOZZA: a ogni cambio di schema il database di prova viene ricreato (dati persi).
                // Da sostituire con migrazioni vere PRIMA di usare l'app con dati reali.
                .fallbackToDestructiveMigration()
                .build()
    }
}
