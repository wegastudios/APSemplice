package it.apsemplice.app.core

import java.math.BigDecimal
import java.math.RoundingMode
import java.text.NumberFormat
import java.util.Locale
import java.util.UUID

/** Tutti gli importi dell'app sono interi in centesimi (Long): niente errori di arrotondamento. */
object Money {
    private val THOUSANDS = Regex("^\\d{1,3}(\\.\\d{3})+$")

    fun format(cents: Long): String =
        NumberFormat.getCurrencyInstance(Locale.ITALY).format(BigDecimal.valueOf(cents, 2))

    /** Senza simbolo, per i CSV: 1234,50 */
    fun plain(cents: Long): String = BigDecimal.valueOf(cents, 2).toPlainString().replace('.', ',')

    /** Accetta "12,50", "12.50", "1.500", "1.500,00", "€ 12". Null se non valido. */
    fun parse(input: String): Long? {
        var s = input.trim().replace("€", "").replace(" ", "")
        if (s.isEmpty()) return null
        s = when {
            ',' in s -> s.replace(".", "").replace(',', '.')
            THOUSANDS.matches(s) -> s.replace(".", "")
            else -> s
        }
        val bd = s.toBigDecimalOrNull() ?: return null
        return bd.setScale(2, RoundingMode.HALF_UP).movePointRight(2).longValueExact()
    }
}

fun newId(): String = UUID.randomUUID().toString()
