package it.apsemplice.app.domain

enum class AccountType(val label: String) {
    CASH("Cassa contanti"),
    BANK("Conto corrente"),
    POS("Conto POS"),
    OTHER("Altro"),
}

enum class PaymentMethod(val label: String) {
    CASH("Contanti"),
    BANK_TRANSFER("Bonifico"),
    POS("POS / carta"),
    CHECK("Assegno"),
    OTHER("Altro"),
}

/** Segno con cui il movimento incide sul saldo del conto. I giroconti sono due righe collegate. */
enum class TxType(val label: String, val sign: Int) {
    INCOME("Entrata", 1),
    EXPENSE("Uscita", -1),
    TRANSFER_IN("Giroconto in entrata", 1),
    TRANSFER_OUT("Giroconto in uscita", -1);

    val isTransfer: Boolean get() = this == TRANSFER_IN || this == TRANSFER_OUT
}

enum class CategoryKind(val label: String, val income: Boolean, val expense: Boolean) {
    MEMBERSHIP("Quota associativa", true, false),
    ACTIVITY_FEE("Quota attività / corso", true, false),
    DONATION("Erogazione liberale", true, false),
    OTHER_INCOME("Altra entrata", true, false),
    INSTRUCTOR_REIMBURSEMENT("Compenso / rimborso istruttore", false, true),
    MEMBER_REIMBURSEMENT("Rimborso spese socio", false, true),
    ACTIVITY_COST("Costo attività", false, true),
    GENERAL_COST("Costo generale", false, true),
    ADJUSTMENT("Rettifica di cassa", true, true),
}
