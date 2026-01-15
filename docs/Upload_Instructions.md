Instrucciones para publicar la Política de Privacidad y el paquete de revisión en Google Play
=========================================================================================

1) Asegurar URL pública para `privacy-policy.html`
- Google Play exige que la Política de Privacidad sea accesible desde una URL pública (https). Opciones rápidas:
  - Subir `docs/privacy-policy.html` a la web corporativa (por ejemplo: https://www.corporativodrt.com/privacy-policy/nexus.html).
  - O bien usar GitHub Pages: publicar el repo y activar Pages desde la rama `main` (o `gh-pages`).

2) Pasos cortos para GitHub Pages (si usan GitHub):
```bash
# 1. Añadir, commitear y push de `docs/privacy-policy.html`
git add docs/privacy-policy.html docs/Play_Review_Package.md docs/mockups/*
git commit -m "Add updated privacy policy and play review package"
git push origin main

# 2. Activar GitHub Pages en el repo (Settings → Pages → Source: branch 'main' / folder '/docs' o root).
# 3. Acceder a la URL generada, por ejemplo: https://<org>.github.io/<repo>/privacy-policy.html
```

3) En Play Console
- Entra a la app → Grow Users > Store presence > Store listings y coloca la URL pública en el campo `Privacy Policy`.
- Ve a Security & privacy / Data safety y completa el formulario indicando recolección de ubicación y fotos.
- En la revisión de la política (si te lo piden) adjunta `docs/Play_Review_Package.md` y las capturas en `docs/mockups/`.

4) Subir release corregido
- Incrementa `versionCode`/`versionName` en `android/app/build.gradle.kts` o `build.gradle` según corresponda.
- Genera AAB firmado y súbelo en el mismo track (producción) y `Send for review`.

5) Comunicación con el revisor
- Adjunta en la sección de notas/review: enlace a la política pública + breve justificante (usar texto de `docs/Play_Review_Package.md`).

Si quieres, puedo:
- Preparar los archivos listos para push (ya añadí mockups y el paquete de revisión). 
- Incrementar `versionCode` automáticamente y generar comandos para construir el AAB.
