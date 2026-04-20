# VTU Android App (WebView Wrapper)

This Android project wraps your Laravel VTU site inside a secure WebView.

## What this starter includes
- JavaScript, cookies, and DOM storage enabled for Laravel auth sessions
- Pull-to-refresh
- Back button support
- External intent handling for `intent://`, `tel:`, `mailto:`, WhatsApp and similar links
- File upload support for `<input type="file">`

## 1) Update your live URL
Edit `app/src/main/res/values/strings.xml`:
- Replace `https://your-domain.com` with your real site URL, for example `https://belovedsubp.com`

Do not use `localhost` on Android device. For local testing, use your computer LAN IP such as `http://192.168.1.20:8000`.

## 2) Update package name (recommended)
Current package is `com.belovedsubp.app`.
If you want a different package:
- change `namespace` and `applicationId` in `app/build.gradle.kts`
- rename folder path under `app/src/main/java/`
- update package declaration in `MainActivity.kt`

## 3) Open in Android Studio
- Open Android Studio
- Choose **Open**
- Select this folder: `android-app`
- Let Gradle sync and install missing SDK components

## 4) Build APK
In Android Studio:
- Build -> Build Bundle(s) / APK(s) -> Build APK(s)

Debug APK will be generated under:
`app/build/outputs/apk/debug/`

## Production notes
- For production, serve your website on HTTPS.
- Cleartext HTTP is already disabled in `network_security_config.xml` and manifest (`usesCleartextTraffic=false`).
