package it.apsemplice.app.importer

import java.nio.ByteBuffer
import java.nio.charset.CharacterCodingException
import java.nio.charset.CodingErrorAction
import java.text.Normalizer

data class ImportedMember(
    val line: Int, // numero di riga nel file (l'intestazione è la riga 1)
    val cardNumber: String?,
    val firstName: String,
    val lastName: String,
    val taxCode: String?,
    val email: String?,
    val phone: String?,
)

sealed interface ParseResult {
    data class Ok(val rows: List<ImportedMember>) : ParseResult
    data class Error(val message: String) : ParseResult
}

/** Socio già presente, nella forma minima che serve al confronto. */
data class ExistingMember(val id: String, val cardNumber: String?, val firstName: String, val lastName: String, val taxCode: String?)

enum class PlanAction(val label: String) { CREATE("Nuovo"), UPDATE("Aggiorna"), ERROR("Errore") }

data class PlannedRow(val row: ImportedMember, val action: PlanAction, val message: String?, val matchedId: String?)

object MemberCsv {
    const val TEMPLATE =
        "Numero tessera;Nome;Cognome;Codice fiscale;Email;Telefono\r\n" +
            "1;Mario;Rossi;RSSMRA80A01H501U;mario.rossi@example.com;3331234567\r\n"

    /** Decodifica UTF-8 (anche con BOM); se i byte non sono UTF-8 valido ripiega su Windows-1252 (Excel). */
    fun decode(bytes: ByteArray): String {
        val text = try {
            Charsets.UTF_8.newDecoder()
                .onMalformedInput(CodingErrorAction.REPORT)
                .onUnmappableCharacter(CodingErrorAction.REPORT)
                .decode(ByteBuffer.wrap(bytes)).toString()
        } catch (e: CharacterCodingException) {
            String(bytes, charset("windows-1252"))
        }
        return text.removePrefix("﻿")
    }

    /** Legge un CSV con separatore ; , o tab (riconosciuto dall'intestazione) e colonne in qualunque ordine. */
    fun parse(text: String): ParseResult {
        val table = readTable(text)
        if (table.isEmpty()) return ParseResult.Error("Il file è vuoto.")
        val header = table.first().map { normalize(it) }
        fun col(vararg names: String) = header.indexOfFirst { it in names }

        val iCard = col("tessera", "numerotessera", "ntessera", "nrtessera", "numtessera", "nrtess", "numero", "n", "nr")
        val iFirst = col("nome", "firstname", "name")
        val iLast = col("cognome", "surname", "lastname")
        val iTax = col("codicefiscale", "cf", "codfisc", "codicefisc", "fiscalcode")
        val iMail = col("email", "mail", "emailaddress", "indirizzoemail", "postaelettronica")
        val iPhone = col("telefono", "tel", "cellulare", "cell", "mobile", "phone", "telefonocellulare")
        if (iFirst < 0 || iLast < 0) {
            return ParseResult.Error("Nella prima riga servono almeno le colonne \"Nome\" e \"Cognome\". Trovate: ${table.first().joinToString(", ")}")
        }

        val result = mutableListOf<ImportedMember>()
        for ((index, cells) in table.withIndex().drop(1)) {
            fun cell(i: Int) = if (i in cells.indices) cells[i].trim().ifEmpty { null } else null
            result += ImportedMember(
                line = index + 1,
                cardNumber = cell(iCard)?.let(::cleanCard),
                firstName = cell(iFirst) ?: "",
                lastName = cell(iLast) ?: "",
                taxCode = cell(iTax)?.replace(" ", "")?.uppercase(),
                email = cell(iMail),
                phone = cell(iPhone),
            )
        }
        return ParseResult.Ok(result)
    }

    /** "123.0" (artefatto di Excel) -> "123". */
    fun cleanCard(raw: String): String = raw.trim().removeSuffix(".0").removeSuffix(",0")

    fun normalize(s: String): String =
        Normalizer.normalize(s.lowercase(), Normalizer.Form.NFD).filter { it.isLetterOrDigit() && it.code < 128 }

    /** Righe di celle, senza righe completamente vuote. Gestisce i campi tra virgolette. */
    internal fun readTable(text: String): List<List<String>> {
        val firstLine = text.lineSequence().firstOrNull { it.isNotBlank() } ?: return emptyList()
        val delimiter = listOf(';', ',', '\t').maxByOrNull { d -> firstLine.count { it == d } }!!
            .takeIf { d -> firstLine.contains(d) } ?: ';'

        val rows = mutableListOf<List<String>>()
        var row = mutableListOf<String>()
        val cell = StringBuilder()
        var quoted = false
        var i = 0
        fun endCell() { row.add(cell.toString()); cell.clear() }
        fun endRow() {
            endCell()
            if (row.any { it.isNotBlank() }) rows.add(row)
            row = mutableListOf()
        }
        while (i < text.length) {
            val ch = text[i]
            when {
                quoted && ch == '"' && i + 1 < text.length && text[i + 1] == '"' -> { cell.append('"'); i++ }
                ch == '"' -> quoted = !quoted
                !quoted && ch == delimiter -> endCell()
                !quoted && ch == '\n' -> endRow()
                !quoted && ch == '\r' -> Unit
                else -> cell.append(ch)
            }
            i++
        }
        if (cell.isNotEmpty() || row.isNotEmpty()) endRow()
        return rows
    }
}

object MemberImportPlanner {
    private fun key(s: String?) = s?.trim()?.uppercase().orEmpty()
    private fun nameKey(first: String, last: String) = MemberCsv.normalize(first) + "|" + MemberCsv.normalize(last)
    private fun label(m: ExistingMember) = "${m.firstName} ${m.lastName}".trim()

    /**
     * Decide per ogni riga se creare, aggiornare o rifiutare. Ordine di riconoscimento dei soci già presenti:
     * numero tessera, poi codice fiscale, poi nome+cognome (solo se univoco).
     */
    fun plan(rows: List<ImportedMember>, existing: List<ExistingMember>): List<PlannedRow> {
        val byCard = existing.filter { !it.cardNumber.isNullOrBlank() }.associateBy { key(it.cardNumber) }
        val byTax = existing.filter { !it.taxCode.isNullOrBlank() }.associateBy { key(it.taxCode) }
        val byName = existing.groupBy { nameKey(it.firstName, it.lastName) }

        val cardsInFile = mutableMapOf<String, Int>()
        val taxInFile = mutableMapOf<String, Int>()
        val touched = mutableMapOf<String, Int>() // id socio esistente -> riga che lo aggiorna

        return rows.map { r ->
            fun error(msg: String) = PlannedRow(r, PlanAction.ERROR, msg, null)
            if (r.firstName.isBlank() || r.lastName.isBlank()) return@map error("Nome o cognome mancante")

            val card = r.cardNumber?.let(::key)
            val tax = r.taxCode?.let(::key)
            if (card != null && cardsInFile.containsKey(card)) return@map error("Tessera ${r.cardNumber} già usata alla riga ${cardsInFile[card]} del file")
            if (tax != null && taxInFile.containsKey(tax)) return@map error("Codice fiscale già presente alla riga ${taxInFile[tax]} del file")

            val cardHolder = card?.let { byCard[it] }
            val taxHolder = tax?.let { byTax[it] }
            val nameMatches = byName[nameKey(r.firstName, r.lastName)].orEmpty()

            // La tessera identifica il socio: se è di un'altra persona non si importa.
            if (cardHolder != null && nameKey(cardHolder.firstName, cardHolder.lastName) != nameKey(r.firstName, r.lastName) &&
                (taxHolder == null || taxHolder.id == cardHolder.id)
            ) {
                return@map error("Tessera ${r.cardNumber} già assegnata a ${label(cardHolder)}")
            }
            val match: ExistingMember? = when {
                cardHolder != null && taxHolder != null && cardHolder.id != taxHolder.id ->
                    return@map error("Tessera ${r.cardNumber} è di ${label(cardHolder)}, ma il codice fiscale è di ${label(taxHolder)}")
                cardHolder != null -> cardHolder
                taxHolder != null -> taxHolder
                nameMatches.size == 1 -> nameMatches.first()
                else -> null
            }
            if (match == null && nameMatches.size > 1 && card == null && tax == null) {
                return@map error("Più soci con questo nome: servono tessera o codice fiscale per distinguerli")
            }

            if (match != null) {
                touched[match.id]?.let { return@map error("Lo stesso socio è già aggiornato dalla riga $it") }
                // Se la tessera indicata è di un altro socio non si può assegnare.
                if (card != null && cardHolder != null && cardHolder.id != match.id) return@map error("Tessera ${r.cardNumber} già assegnata a ${label(cardHolder)}")
                touched[match.id] = r.line
                if (card != null) cardsInFile[card] = r.line
                if (tax != null) taxInFile[tax] = r.line
                val how = when {
                    cardHolder != null -> "per tessera"
                    taxHolder != null -> "per codice fiscale"
                    else -> "per nome e cognome"
                }
                PlannedRow(r, PlanAction.UPDATE, "Socio esistente ($how)", match.id)
            } else {
                if (card != null) cardsInFile[card] = r.line
                if (tax != null) taxInFile[tax] = r.line
                PlannedRow(r, PlanAction.CREATE, if (card == null) "Senza tessera: assegnabile dopo" else null, null)
            }
        }
    }
}
