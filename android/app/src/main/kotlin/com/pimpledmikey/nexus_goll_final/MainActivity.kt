package com.pimpledmikey.nexus_goll_final

import android.os.Bundle
import androidx.core.view.WindowCompat
import io.flutter.embedding.android.FlutterFragmentActivity

/**
 * MainActivity con soporte Edge-to-Edge para Android 15+
 * 
 * - WindowCompat.setDecorFitsSystemWindows(window, false) habilita el modo edge-to-edge
 * - Esto permite que la app se dibuje detrás de las barras de sistema
 * - Flutter maneja los insets automáticamente con SafeArea/MediaQuery
 */
class MainActivity : FlutterFragmentActivity() {
    
    override fun onCreate(savedInstanceState: Bundle?) {
        // Habilitar edge-to-edge ANTES de llamar a super.onCreate()
        WindowCompat.setDecorFitsSystemWindows(window, false)
        super.onCreate(savedInstanceState)
    }
}
