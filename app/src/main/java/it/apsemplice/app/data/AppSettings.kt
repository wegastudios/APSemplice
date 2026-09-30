package it.apsemplice.app.data

import android.content.Context
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow

data class Profile(
    val associationName: String = "",
    val taxCode: String = "",
    /** Mese di inizio dell'anno accademico (9 = settembre). */
    val academicYearStartMonth: Int = 9,
    /** Quota associativa proposta negli incassi. */
    val membershipFeeCents: Long = 1000,
)

class AppSettings(context: Context) {
    private val prefs = context.getSharedPreferences("apsemplice", Context.MODE_PRIVATE)
    private val _profile = MutableStateFlow(load())
    val profileFlow: StateFlow<Profile> = _profile
    val profile: Profile get() = _profile.value

    fun update(profile: Profile) {
        prefs.edit()
            .putString("name", profile.associationName)
            .putString("taxCode", profile.taxCode)
            .putInt("ayStartMonth", profile.academicYearStartMonth)
            .putLong("membershipFee", profile.membershipFeeCents)
            .apply()
        _profile.value = profile
    }

    private fun load() = Profile(
        associationName = prefs.getString("name", "") ?: "",
        taxCode = prefs.getString("taxCode", "") ?: "",
        academicYearStartMonth = prefs.getInt("ayStartMonth", 9),
        membershipFeeCents = prefs.getLong("membershipFee", 1000),
    )
}
