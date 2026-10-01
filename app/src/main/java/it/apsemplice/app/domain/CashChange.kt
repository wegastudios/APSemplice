package it.apsemplice.app.domain

sealed interface CashChangeResult {
    /** Resto da dare; [breakdown] = coppie (taglio in centesimi, quantità). */
    data class Change(val cents: Long, val breakdown: List<Pair<Long, Int>>) : CashChangeResult

    /** Contanti ricevuti insufficienti: mancano [cents]. */
    data class Missing(val cents: Long) : CashChangeResult
}

object CashChange {
    /** Tagli euro in centesimi, dal più grande al più piccolo. */
    val DENOMINATIONS = listOf(
        50000L, 20000, 10000, 5000, 2000, 1000, 500, 200, 100, 50, 20, 10, 5, 2, 1,
    )

    fun compute(dueCents: Long, tenderedCents: Long): CashChangeResult {
        if (tenderedCents < dueCents) return CashChangeResult.Missing(dueCents - tenderedCents)
        var rest = tenderedCents - dueCents
        val change = rest
        val breakdown = mutableListOf<Pair<Long, Int>>()
        for (d in DENOMINATIONS) {
            val n = (rest / d).toInt()
            if (n > 0) {
                breakdown += d to n
                rest -= d * n
            }
        }
        return CashChangeResult.Change(change, breakdown)
    }

    /** Importi rapidi suggeriti per "contanti ricevuti": esatto + tagli comuni che coprono il dovuto. */
    fun quickTenders(dueCents: Long): List<Long> {
        if (dueCents <= 0) return emptyList()
        val notes = listOf(500L, 1000, 2000, 5000, 10000, 20000).filter { it >= dueCents }
        return (listOf(dueCents) + notes).distinct().take(4)
    }
}
