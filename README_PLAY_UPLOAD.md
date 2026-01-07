Guía rápida: subir AAB a Google Play con Fastlane (internal testing)

1) Crear cuenta de servicio en Google Play Console
- Entra en Play Console -> Settings -> API access.
- Crea un proyecto de Google Cloud si no existe.
- Crea una cuenta de servicio y genera una clave JSON ("Create service account").
- Concede permisos: en Play Console, asigna la cuenta de servicio como usuario con permiso "Release manager" o el rol necesario para subir bundles.
- Descarga el JSON y guárdalo localmente en `fastlane/service-account.json` (NO lo comitees).

2) Preparar el AAB
- Ya generaste el `.aab` con:

```bash
flutter build appbundle --release
```

3) Subir con Fastlane
- Instala Fastlane (se recomienda usar Ruby + Bundler):

```bash
gem install bundler
bundle init
# añade `gem "fastlane"` al Gemfile y luego:
bundle install
```

- O instalar fastlane directamente:

```bash
sudo gem install fastlane -NV
```

- Coloca el JSON de la cuenta de servicio en `fastlane/service-account.json`.
- Ejecuta el script de subida (o llama fastlane):

```bash
./scripts/upload_aab_fastlane.sh
# o
fastlane android upload_aab
```

4) Consejos y seguridad
- Añade `fastlane/service-account.json` a `.gitignore` (ya añadido).
- Usa un gestor de secretos en CI (GitHub Actions) para almacenar la clave JSON y restaurarla en el runner antes de ejecutar fastlane.
- Para CI, considera cifrar el JSON y usar acciones que lo desencripten con secretos.

5) Automatización (CI)
- En GitHub Actions puedes crear un job que restaure `fastlane/service-account.json` (desde secretos o base64), ejecute `flutter build appbundle --release` y luego `fastlane android upload_aab`.

Ejemplo CI (GitHub Actions)

1. Añade estos secretos en tu repositorio (Settings → Secrets):
- `KEYSTORE_BASE64`  → contenido del keystore `.jks` codificado en base64. (Ej: `base64 android/keystore/nexus_upload_key.jks | pbcopy`)
- `KEYSTORE_PASSWORD` → contraseña del keystore
- `KEY_PASSWORD` → contraseña de la clave
- `KEY_ALIAS` → alias de la clave (ej. `nexus_upload`)
- `FASTLANE_SERVICE_ACCOUNT_BASE64` → contenido del JSON de la cuenta de servicio encodificado en base64. (Ej: `base64 fastlane/service-account.json | pbcopy`)

2. He añadido un workflow de ejemplo en `.github/workflows/android_publish.yml` que:
- decodifica el keystore y lo escribe en `android/keystore/nexus_upload_key.jks`;
- genera `android/key.properties` con las contraseñas desde los secrets;
- decodifica `fastlane/service-account.json` desde el secret correspondiente;
- ejecuta `flutter build appbundle --release` y luego `fastlane android upload_aab`.

3. Cómo generar los secrets desde tu máquina (mac/linux):

```bash
# Base64 encode keystore
base64 android/keystore/nexus_upload_key.jks | pbcopy
# pega en el secret KEYSTORE_BASE64

# Base64 encode service account JSON
base64 fastlane/service-account.json | pbcopy
# pega en el secret FASTLANE_SERVICE_ACCOUNT_BASE64
```

Nota: el workflow sube el `.aab` al track `internal` configurado en el `Fastfile`. Revisa permisos de la cuenta de servicio en Play Console (Release Manager o el rol necesario).
