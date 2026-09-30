package it.apsemplice.app

import it.apsemplice.app.core.AcademicYear
import it.apsemplice.app.core.Money
import it.apsemplice.app.domain.CashChange
import it.apsemplice.app.domain.CashChangeResult
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test
import java.time.LocalDate

class MoneyTest {
    @Test fun parsesItalianInput() {
        assertEquals(1250L, Money.parse("12,50"))
        assertEquals(1250L, Money.parse("12.50"))
        assertEquals(150000L, Money.parse("1.500"))
        assertEquals(150000L, Money.parse("1.500,00"))
        assertEquals(1000L, Money.parse("€ 10"))
    }

    @Test fun rejectsGarbage() {
        assertNull(Money.parse(""))
        assertNull(Money.parse("abc"))
        assertNull(Money.parse("1,5,5"))
    }

    @Test fun plainFormatForCsv() {
        assertEquals("1234,50", Money.plain(123450))
        assertEquals("-0,05", Money.plain(-5))
    }
}

class CashChangeTest {
    @Test fun exactPaymentHasNoChange() {
        val r = CashChange.compute(1500, 1500) as CashChangeResult.Change
        assertEquals(0L, r.cents)
        assertTrue(r.breakdown.isEmpty())
    }

    @Test fun changeIsBrokenIntoDenominations() {
        // dovuto 12,50, ricevuti 20,00 -> resto 7,50 = 5 + 2 + 0,50
        val r = CashChange.compute(1250, 2000) as CashChangeResult.Change
        assertEquals(750L, r.cents)
        assertEquals(listOf(500L to 1, 200L to 1, 50L to 1), r.breakdown)
        assertEquals(r.cents, r.breakdown.sumOf { (d, n) -> d * n })
    }

    @Test fun insufficientCashReportsMissing() {
        val r = CashChange.compute(2000, 1500) as CashChangeResult.Missing
        assertEquals(500L, r.cents)
    }

    @Test fun quickTendersStartWithExact() {
        val q = CashChange.quickTenders(1250)
        assertEquals(1250L, q.first())
        assertTrue(q.all { it >= 1250 })
    }
}

class AcademicYearTest {
    @Test fun septemberStartsNewYear() {
        assertEquals(2025, AcademicYear.forDate(LocalDate.of(2025, 9, 1), 9).startYear)
        assertEquals(2025, AcademicYear.forDate(LocalDate.of(2026, 8, 31), 9).startYear)
        assertEquals(2024, AcademicYear.forDate(LocalDate.of(2025, 8, 31), 9).startYear)
    }

    @Test fun rangeAndLabel() {
        val ay = AcademicYear(2025, 9)
        assertEquals("2025/2026", ay.label)
        assertEquals(LocalDate.of(2025, 9, 1), ay.start)
        assertEquals(LocalDate.of(2026, 8, 31), ay.end)
        assertEquals(12, ay.months().size)
    }
}
