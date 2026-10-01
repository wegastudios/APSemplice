package it.apsemplice.app.export

import android.content.Context
import android.content.Intent
import androidx.core.content.FileProvider
import java.io.File

object Sharing {
    /** Scrive il CSV in cache (con BOM UTF-8 per Excel) e apre il selettore di condivisione. */
    fun shareCsv(context: Context, fileName: String, content: String) {
        val dir = File(context.cacheDir, "exports").apply { mkdirs() }
        val file = File(dir, fileName)
        file.writeText("﻿" + content, Charsets.UTF_8)
        val uri = FileProvider.getUriForFile(context, "${context.packageName}.files", file)
        val send = Intent(Intent.ACTION_SEND).apply {
            type = "text/csv"
            putExtra(Intent.EXTRA_STREAM, uri)
            addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION)
        }
        context.startActivity(Intent.createChooser(send, "Condividi").addFlags(Intent.FLAG_ACTIVITY_NEW_TASK))
    }
}
