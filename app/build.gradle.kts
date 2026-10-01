plugins {
    alias(libs.plugins.android.application)
    alias(libs.plugins.kotlin.android)
    alias(libs.plugins.kotlin.compose)
    alias(libs.plugins.ksp)
}

android {
    namespace = "it.apsemplice.app"
    compileSdk = 35

    defaultConfig {
        applicationId = "it.apsemplice.app"
        minSdk = 26
        targetSdk = 35
        versionCode = 1
        versionName = "0.1.0"

        // Interruttore per le funzioni cloud (login Google, sync, licenza).
        // Finché resta false l'app funziona solo in locale (vedi core/cloud).
        buildConfigField("boolean", "CLOUD_ENABLED", "false")
    }

    // Firma fissa per le build di test: con la stessa chiave Android accetta gli aggiornamenti
    // sopra la versione già installata (senza disinstallare e perdere i dati).
    // La chiave arriva dai segreti di GitHub Actions; in locale/senza segreti si usa la chiave di debug.
    val sharedKeystore = System.getenv("APS_KEYSTORE")
    if (sharedKeystore != null) {
        signingConfigs {
            create("shared") {
                storeFile = file(sharedKeystore)
                storeType = "pkcs12"
                storePassword = System.getenv("APS_KEYSTORE_PASSWORD")
                keyAlias = "apsemplice"
                keyPassword = System.getenv("APS_KEYSTORE_PASSWORD")
            }
        }
    }

    buildTypes {
        debug {
            if (sharedKeystore != null) signingConfig = signingConfigs.getByName("shared")
        }
        release {
            isMinifyEnabled = true
            proguardFiles(getDefaultProguardFile("proguard-android-optimize.txt"), "proguard-rules.pro")
        }
    }

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }
    kotlinOptions {
        jvmTarget = "17"
    }
    buildFeatures {
        compose = true
        buildConfig = true
    }
}

ksp {
    arg("room.schemaLocation", "$projectDir/schemas")
}

dependencies {
    implementation(libs.androidx.core.ktx)
    implementation(libs.androidx.activity.compose)
    implementation(libs.androidx.lifecycle.runtime.compose)
    implementation(libs.androidx.lifecycle.viewmodel.compose)
    implementation(libs.androidx.navigation.compose)
    implementation(libs.kotlinx.coroutines.android)

    implementation(platform(libs.androidx.compose.bom))
    implementation(libs.androidx.compose.ui)
    implementation(libs.androidx.compose.ui.tooling.preview)
    implementation(libs.androidx.compose.material3)
    debugImplementation(libs.androidx.compose.ui.tooling)

    implementation(libs.androidx.room.runtime)
    implementation(libs.androidx.room.ktx)
    ksp(libs.androidx.room.compiler)

    testImplementation(libs.junit)
}
