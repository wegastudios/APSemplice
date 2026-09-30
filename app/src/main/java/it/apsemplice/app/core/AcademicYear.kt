package it.apsemplice.app.core

import java.time.LocalDate
import java.time.YearMonth
import java.time.format.DateTimeFormatter
import java.util.Locale

/**
 * Anno accademico/sociale (es. 1 set 2025 - 31 ago 2026), distinto dall'anno solare
 * usato per la contabilità legale. Il mese di inizio è configurabile.
 */
data class AcademicYear(val startYear: Int, val startMonth: Int) {
    val start: LocalDate get() = LocalDate.of(startYear, startMonth, 1)
    val end: LocalDate get() = start.plusYears(1).minusDays(1)
    val label: String get() = if (startMonth == 1) "$startYear" else "$startYear/${startYear + 1}"

    fun months(): List<YearMonth> = (0 until 12).map { YearMonth.from(start).plusMonths(it.toLong()) }
    fun previous() = AcademicYear(startYear - 1, startMonth)
    fun next() = AcademicYear(startYear + 1, startMonth)

    companion object {
        fun forDate(date: LocalDate, startMonth: Int) =
            AcademicYear(if (date.monthValue >= startMonth) date.year else date.year - 1, startMonth)

        fun fromLabel(label: String, startMonth: Int) =
            AcademicYear(label.substringBefore('/').toInt(), startMonth)
    }
}

private val IT_DATE = DateTimeFormatter.ofPattern("dd/MM/yyyy", Locale.ITALY)
private val IT_MONTH = DateTimeFormatter.ofPattern("MMMM yyyy", Locale.ITALY)

fun LocalDate.itFormat(): String = format(IT_DATE)
fun YearMonth.itFormat(): String = format(IT_MONTH).replaceFirstChar { it.uppercase() }
