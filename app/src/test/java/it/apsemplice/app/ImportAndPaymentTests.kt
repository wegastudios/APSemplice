package it.apsemplice.app

import it.apsemplice.app.core.SocialYear
import it.apsemplice.app.domain.MonthState
import it.apsemplice.app.domain.PaymentCalc
import it.apsemplice.app.importer.ExistingMember
import it.apsemplice.app.importer.MemberCsv
import it.apsemplice.app.importer.MemberImportPlanner
import it.apsemplice.app.importer.ParseResult
import it.apsemplice.app.importer.PlanAction
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test
import java.time.YearMonth

class MemberCsvTest {
    private fun rows(text: String) = (MemberCsv.parse(text) as ParseResult.Ok).rows

    @Test fun readsSemicolonFileWithAnyColumnOrder() {
        val r = rows("Cognome;Nome;N. Tessera;Codice Fiscale\nRossi;Mario;12;rssmra80a01h501u\nBianchi;Anna;;\n")
        assertEquals(2, r.size)
        assertEquals("Mario", r[0].firstName)
        assertEquals("Rossi", r[0].lastName)
        assertEquals("12", r[0].cardNumber)
        assertEquals("RSSMRA80A01H501U", r[0].taxCode)
        assertEquals(null, r[1].cardNumber)
    }

    @Test fun excelArtifactsAreCleaned() {
        assertEquals("123", rows("tessera,nome,cognome\n123.0,A,B")[0].cardNumber)
    }

    @Test fun quotedFieldsMayContainDelimiter() {
        val r = rows("Nome;Cognome;Email\n\"Anna, Maria\";\"De \"\"Luca\"\"\";a@b.it")
        assertEquals("Anna, Maria", r[0].firstName)
        assertEquals("De \"Luca\"", r[0].lastName)
    }

    @Test fun missingRequiredColumnsIsAnError() {
        assertTrue(MemberCsv.parse("Tessera;Nome\n1;Mario") is ParseResult.Error)
    }

    @Test fun decodesWindows1252() {
        val bytes = "Nome;Cognome\nNiccolò;D'Amico".toByteArray(charset("windows-1252"))
        assertTrue(MemberCsv.decode(bytes).contains("Niccolò"))
    }
}

class MemberImportPlannerTest {
    private val existing = listOf(
        ExistingMember("1", "10", "Mario", "Rossi", "RSSMRA80A01H501U"),
        ExistingMember("2", null, "Anna", "Verdi", null),
    )

    private fun plan(csv: String) =
        MemberImportPlanner.plan((MemberCsv.parse(csv) as ParseResult.Ok).rows, existing)

    @Test fun newMemberIsCreated() {
        val p = plan("Tessera;Nome;Cognome\n11;Luca;Neri")
        assertEquals(PlanAction.CREATE, p[0].action)
    }

    @Test fun sameCardAndNameUpdatesExistingMember() {
        val p = plan("Tessera;Nome;Cognome;Email\n10;Mario;Rossi;m@x.it")
        assertEquals(PlanAction.UPDATE, p[0].action)
        assertEquals("1", p[0].matchedId)
    }

    @Test fun cardOfAnotherPersonIsRejected() {
        val p = plan("Tessera;Nome;Cognome\n10;Luca;Neri")
        assertEquals(PlanAction.ERROR, p[0].action)
    }

    @Test fun duplicateCardInsideTheFileIsRejected() {
        val p = plan("Tessera;Nome;Cognome\n20;Luca;Neri\n20;Paola;Gialli")
        assertEquals(PlanAction.CREATE, p[0].action)
        assertEquals(PlanAction.ERROR, p[1].action)
    }

    @Test fun matchesByTaxCodeThenName() {
        val p = plan("Nome;Cognome;Codice fiscale\nMarietto;Rossi;RSSMRA80A01H501U\nAnna;Verdi;")
        assertEquals("1", p[0].matchedId)
        assertEquals("2", p[1].matchedId)
    }

    @Test fun missingNameIsRejected() {
        assertEquals(PlanAction.ERROR, plan("Nome;Cognome\n;Rossi")[0].action)
    }
}

class PaymentCalcTest {
    private val year = SocialYear(2025, 9)
    private fun ym(s: String) = YearMonth.parse(s)

    @Test fun dueFromStartUntilToday() {
        val s = PaymentCalc.compute(1000, ym("2025-10"), null, ym("2025-12"), year, mapOf(ym("2025-10") to 1000L, ym("2025-11") to 500L))
        assertEquals(3000L, s.totalDue)
        assertEquals(1500L, s.totalPaid)
        assertEquals(-1500L, s.balance)
        assertEquals(listOf(ym("2025-11"), ym("2025-12")), s.unpaidMonths.map { it.month })
        assertEquals(MonthState.PARTIAL, s.months.first { it.month == ym("2025-11") }.state)
    }

    @Test fun cancelledEnrollmentStopsOwing() {
        val s = PaymentCalc.compute(1000, ym("2025-10"), ym("2025-11"), ym("2026-03"), year, emptyMap())
        assertEquals(2000L, s.totalDue)
    }

    @Test fun advancePaymentIsACredit() {
        val s = PaymentCalc.compute(1000, ym("2025-10"), null, ym("2025-10"), year, mapOf(ym("2025-10") to 1000L, ym("2025-11") to 1000L))
        assertEquals(1000L, s.balance)
        assertTrue(s.isRegular)
        assertEquals(MonthState.ADVANCE, s.months.last().state)
    }

    @Test fun nothingDueBeforeStart() {
        val s = PaymentCalc.compute(1000, ym("2026-01"), null, ym("2025-11"), year, emptyMap())
        assertEquals(0L, s.totalDue)
        assertTrue(s.months.isEmpty())
    }
}
