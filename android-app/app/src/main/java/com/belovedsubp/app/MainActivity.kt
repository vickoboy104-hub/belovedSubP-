package com.belovedsubp.app

import android.annotation.SuppressLint
import android.app.DownloadManager
import android.content.ActivityNotFoundException
import android.content.ContentValues
import android.content.Context
import android.content.Intent
import android.graphics.Bitmap
import android.graphics.drawable.GradientDrawable
import android.net.Uri
import android.os.Build
import android.os.Bundle
import android.os.Handler
import android.os.Looper
import android.os.Environment
import android.os.Message
import android.print.PrintAttributes
import android.print.PrintManager
import android.provider.MediaStore
import android.util.Base64
import android.view.View
import android.webkit.CookieManager
import android.webkit.JavascriptInterface
import android.webkit.URLUtil
import android.webkit.ValueCallback
import android.webkit.WebChromeClient
import android.webkit.WebResourceRequest
import android.webkit.WebSettings
import android.webkit.WebView
import android.webkit.WebViewClient
import android.widget.LinearLayout
import android.widget.ProgressBar
import android.widget.TextView
import android.widget.Toast
import androidx.activity.OnBackPressedCallback
import androidx.activity.result.contract.ActivityResultContracts
import androidx.appcompat.app.AppCompatActivity
import androidx.core.content.ContextCompat
import androidx.swiperefreshlayout.widget.SwipeRefreshLayout
import androidx.viewpager2.widget.ViewPager2
import com.google.android.material.button.MaterialButton
import java.io.File
import java.io.FileOutputStream

class MainActivity : AppCompatActivity() {

    private lateinit var introContainer: View
    private lateinit var webContainer: View
    private lateinit var introPager: ViewPager2
    private lateinit var introIndicator: LinearLayout
    private lateinit var introContinueButton: MaterialButton
    private lateinit var introSignUpText: TextView
    internal lateinit var webView: WebView
    private lateinit var swipeRefresh: SwipeRefreshLayout
    private lateinit var progressBar: ProgressBar

    private val onboardingSlides by lazy {
        listOf(
            OnboardingSlide(
                iconResId = R.drawable.vtu_data,
                title = "Cheap Data & Airtime",
                description = "Buy data and recharge airtime instantly across all major networks. Fast processing, discounted rates, and instant delivery."
            ),
            OnboardingSlide(
                iconResId = R.drawable.secure_payment,
                title = "Secure Payments & Wallet Funding",
                description = "Engineered for secure transactions. Fund your wallet seamlessly and pay with confidence with encrypted payments, instant confirmation, and reliable infrastructure."
            ),
            OnboardingSlide(
                iconResId = R.drawable.automation_system,
                title = "Smart Automation",
                description = "Automated, reliable, and real-time. Our system runs on intelligent APIs that process airtime, data, cable, and electricity instantly with no manual delays."
            ),
            OnboardingSlide(
                iconResId = R.drawable.referral_earn,
                title = "Referral & Earnings",
                description = "Scale your earnings digitally. Earn commissions automatically when your referrals transact with smart tracking, transparent reports, and passive income potential."
            ),
        )
    }

    private var filePickerCallback: ValueCallback<Array<Uri>>? = null
    private var pendingWebState: Bundle? = null
    private var webLoaded = false
    private val autoSlideHandler = Handler(Looper.getMainLooper())
    private val autoSlideRunnable = object : Runnable {
        override fun run() {
            if (introContainer.visibility != View.VISIBLE || onboardingSlides.isEmpty()) {
                return
            }
            val next = (introPager.currentItem + 1) % onboardingSlides.size
            introPager.setCurrentItem(next, true)
            autoSlideHandler.postDelayed(this, ONBOARDING_AUTO_SLIDE_MS)
        }
    }

    private val filePickerLauncher = registerForActivityResult(
        ActivityResultContracts.StartActivityForResult()
    ) { result ->
        val uris = WebChromeClient.FileChooserParams.parseResult(result.resultCode, result.data)
        filePickerCallback?.onReceiveValue(uris)
        filePickerCallback = null
    }

    private val backPressedCallback = object : OnBackPressedCallback(true) {
        override fun handleOnBackPressed() {
            if (introContainer.visibility == View.VISIBLE) {
                if (introPager.currentItem > 0) {
                    introPager.currentItem = introPager.currentItem - 1
                } else {
                    finish()
                }
                return
            }

            if (webView.canGoBack()) {
                webView.goBack()
            } else {
                finish()
            }
        }
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContentView(R.layout.activity_main)

        bindViews()
        pendingWebState = savedInstanceState?.getBundle(KEY_WEB_STATE)

        setupOnboarding()
        setupWebView()

        swipeRefresh.setOnRefreshListener { webView.reload() }

        showOnboarding()

        onBackPressedDispatcher.addCallback(this, backPressedCallback)
    }

    override fun onResume() {
        super.onResume()
        backPressedCallback.isEnabled = true
        if (introContainer.visibility == View.VISIBLE) {
            startOnboardingAutoSlide()
        }
    }

    override fun onPause() {
        super.onPause()
        backPressedCallback.isEnabled = false
        stopOnboardingAutoSlide()
        CookieManager.getInstance().flush()
    }

    override fun onSaveInstanceState(outState: Bundle) {
        if (webLoaded) {
            val webState = Bundle()
            webView.saveState(webState)
            outState.putBundle(KEY_WEB_STATE, webState)
        }
        super.onSaveInstanceState(outState)
    }

    override fun onDestroy() {
        stopOnboardingAutoSlide()
        filePickerCallback?.onReceiveValue(null)
        filePickerCallback = null
        super.onDestroy()
    }

    private fun bindViews() {
        introContainer = findViewById(R.id.introContainer)
        webContainer = findViewById(R.id.webContainer)
        introPager = findViewById(R.id.introPager)
        introIndicator = findViewById(R.id.introIndicator)
        introContinueButton = findViewById(R.id.introContinueButton)
        introSignUpText = findViewById(R.id.introSignUpText)
        webView = findViewById(R.id.webView)
        swipeRefresh = findViewById(R.id.swipeRefresh)
        progressBar = findViewById(R.id.progressBar)
    }

    private fun setupOnboarding() {
        introPager.adapter = OnboardingSlideAdapter(onboardingSlides)
        buildIndicators(onboardingSlides.size)
        updateIndicators(0)
        updateOnboardingButton(0)

        introPager.registerOnPageChangeCallback(object : ViewPager2.OnPageChangeCallback() {
            override fun onPageSelected(position: Int) {
                updateIndicators(position)
                startOnboardingAutoSlide()
            }
        })

        introContinueButton.setOnClickListener {
            stopOnboardingAutoSlide()
            unlockAndShowWeb(loadLoginPage = false)
        }

        introSignUpText.setOnClickListener {
            stopOnboardingAutoSlide()
            unlockAndShowWeb(loadLoginPage = true, initialUrl = getString(R.string.signup_url))
        }
    }

    private fun buildIndicators(count: Int) {
        introIndicator.removeAllViews()
        repeat(count) {
            val dot = View(this).apply {
                layoutParams = LinearLayout.LayoutParams(16.dp, 16.dp).also { params ->
                    params.marginEnd = 10.dp
                }
                background = buildIndicatorDrawable(false)
            }
            introIndicator.addView(dot)
        }
    }

    private fun updateIndicators(activeIndex: Int) {
        for (index in 0 until introIndicator.childCount) {
            val dot = introIndicator.getChildAt(index)
            val width = if (index == activeIndex) 38.dp else 16.dp
            val alpha = if (index == activeIndex) 1f else 0.45f
            dot.layoutParams = (dot.layoutParams as LinearLayout.LayoutParams).apply {
                this.width = width
                this.height = 16.dp
            }
            dot.alpha = alpha
            dot.background = buildIndicatorDrawable(index == activeIndex)
        }
    }

    private fun buildIndicatorDrawable(active: Boolean): GradientDrawable {
        return GradientDrawable().apply {
            shape = GradientDrawable.RECTANGLE
            cornerRadius = 100f
            val color = if (active) R.color.indicator_dot_active else R.color.indicator_dot_inactive
            setColor(ContextCompat.getColor(this@MainActivity, color))
        }
    }

    private fun updateOnboardingButton(@Suppress("UNUSED_PARAMETER") page: Int) {
        introContinueButton.text = getString(R.string.continue_text)
    }

    private fun showOnboarding() {
        introContainer.visibility = View.VISIBLE
        webContainer.visibility = View.GONE
        startOnboardingAutoSlide()
    }

    private fun unlockAndShowWeb(loadLoginPage: Boolean, initialUrl: String? = null) {
        showWeb(loadLoginPage, initialUrl)
    }

    private fun showWeb(loadLoginPage: Boolean, initialUrl: String? = null) {
        stopOnboardingAutoSlide()
        introContainer.visibility = View.GONE
        webContainer.visibility = View.VISIBLE

        if (webLoaded) {
            return
        }

        webLoaded = true

        val restored = pendingWebState
        pendingWebState = null
        if (restored != null) {
            webView.restoreState(restored)
            return
        }

        val targetUrl = when {
            !initialUrl.isNullOrBlank() -> initialUrl
            loadLoginPage -> getString(R.string.login_url)
            else -> getString(R.string.home_url)
        }
        webView.loadUrl(targetUrl)
    }

    @SuppressLint("SetJavaScriptEnabled")
    private fun setupWebView() {
        val cookieManager = CookieManager.getInstance()
        cookieManager.setAcceptCookie(true)
        cookieManager.setAcceptThirdPartyCookies(webView, true)

        webView.settings.apply {
            javaScriptEnabled = true
            domStorageEnabled = true
            databaseEnabled = true
            loadWithOverviewMode = false
            useWideViewPort = false
            builtInZoomControls = false
            displayZoomControls = false
            setSupportZoom(false)
            javaScriptCanOpenWindowsAutomatically = true
            setSupportMultipleWindows(true)
            mixedContentMode = WebSettings.MIXED_CONTENT_COMPATIBILITY_MODE
            userAgentString = "$userAgentString BelovedSubPAndroid/1.1"
        }

        webView.addJavascriptInterface(PrintBridge(this), "AndroidPrintBridge")
        webView.addJavascriptInterface(DownloadBridge(this), "AndroidDownloadBridge")
        webView.setDownloadListener { url, userAgent, contentDisposition, mimeType, _ ->
            startFileDownload(url, userAgent, contentDisposition, mimeType)
        }

        webView.webViewClient = object : WebViewClient() {
            override fun shouldOverrideUrlLoading(view: WebView, request: WebResourceRequest): Boolean {
                return handleNavigation(request.url)
            }

            override fun onPageStarted(view: WebView, url: String?, favicon: Bitmap?) {
                super.onPageStarted(view, url, favicon)
                progressBar.visibility = View.VISIBLE
                swipeRefresh.isRefreshing = true
            }

            override fun onPageFinished(view: WebView, url: String?) {
                super.onPageFinished(view, url)
                progressBar.visibility = View.GONE
                swipeRefresh.isRefreshing = false
                injectPrintBridge()
                injectBlobDownloadBridge()
            }
        }

        webView.webChromeClient = object : WebChromeClient() {
            override fun onProgressChanged(view: WebView?, newProgress: Int) {
                progressBar.progress = newProgress
                progressBar.visibility = if (newProgress in 1..99) View.VISIBLE else View.GONE
            }

            override fun onShowFileChooser(
                webView: WebView,
                filePathCallback: ValueCallback<Array<Uri>>,
                fileChooserParams: FileChooserParams
            ): Boolean {
                filePickerCallback?.onReceiveValue(null)
                filePickerCallback = filePathCallback
                filePickerLauncher.launch(fileChooserParams.createIntent())
                return true
            }

            override fun onCreateWindow(
                view: WebView?,
                isDialog: Boolean,
                isUserGesture: Boolean,
                resultMsg: Message?,
            ): Boolean {
                val transport = resultMsg?.obj as? WebView.WebViewTransport ?: return false
                val popupWebView = WebView(this@MainActivity).apply {
                    settings.javaScriptEnabled = true
                    settings.domStorageEnabled = true
                    settings.javaScriptCanOpenWindowsAutomatically = true
                    settings.setSupportMultipleWindows(true)
                    setDownloadListener { url, userAgent, contentDisposition, mimeType, _ ->
                        startFileDownload(url, userAgent, contentDisposition, mimeType)
                    }
                    webViewClient = object : WebViewClient() {
                        override fun shouldOverrideUrlLoading(
                            view: WebView,
                            request: WebResourceRequest,
                        ): Boolean {
                            return handleNavigation(request.url)
                        }

                        override fun onPageFinished(view: WebView, url: String?) {
                            super.onPageFinished(view, url)
                            val loaded = url?.trim().orEmpty()
                            if (loaded.isNotBlank()) {
                                if (handleNavigation(Uri.parse(loaded)).not()) {
                                    this@MainActivity.webView.loadUrl(loaded)
                                }
                            }
                            view.destroy()
                        }
                    }
                }
                transport.webView = popupWebView
                resultMsg.sendToTarget()
                return true
            }
        }
    }

    private fun handleNavigation(uri: Uri): Boolean {
        val scheme = uri.scheme?.lowercase() ?: return true

        if (scheme == "http" || scheme == "https") {
            if (isInternalHost(uri)) {
                return false
            }
            return openExternal(Intent(Intent.ACTION_VIEW, uri))
        }

        if (
            scheme == "about" ||
            scheme == "javascript" ||
            scheme == "blob" ||
            scheme == "data" ||
            scheme == "file" ||
            scheme == "content"
        ) {
            return false
        }

        if (scheme == "intent") {
            return handleIntentScheme(uri)
        }

        return openExternal(Intent(Intent.ACTION_VIEW, uri))
    }

    private fun isInternalHost(uri: Uri): Boolean {
        val host = uri.host?.lowercase() ?: return false
        return host == "belovedsubp.com" || host.endsWith(".belovedsubp.com")
    }

    private fun handleIntentScheme(uri: Uri): Boolean {
        return try {
            val intent = Intent.parseUri(uri.toString(), Intent.URI_INTENT_SCHEME).apply {
                addCategory(Intent.CATEGORY_BROWSABLE)
                component = null
                selector = null
            }
            openExternal(intent)
        } catch (_: Exception) {
            false
        }
    }

    private fun openExternal(intent: Intent): Boolean {
        return try {
            startActivity(intent)
            true
        } catch (_: Exception) {
            val fallback = intent.getStringExtra("browser_fallback_url")
            if (!fallback.isNullOrBlank()) {
                webView.loadUrl(fallback)
                true
            } else {
                Toast.makeText(this, getString(R.string.unable_to_open_link), Toast.LENGTH_SHORT).show()
                true
            }
        }
    }

    private fun injectPrintBridge() {
        val script = """
            (function () {
                if (window.__belovedPrintPatched) return;
                window.__belovedPrintPatched = true;
                window.print = function () {
                    if (window.AndroidPrintBridge && window.AndroidPrintBridge.printCurrentPage) {
                        window.AndroidPrintBridge.printCurrentPage();
                    }
                };
            })();
        """.trimIndent()
        webView.evaluateJavascript(script, null)
    }

    private fun injectBlobDownloadBridge() {
        val script = """
            (function () {
                if (window.__belovedBlobDownloadPatched) return;
                window.__belovedBlobDownloadPatched = true;

                function normalizeName(name) {
                    var raw = String(name || '').trim();
                    if (!raw) raw = 'document-' + Date.now() + '.pdf';
                    return raw.replace(/[\\/:*?"<>|]+/g, '_');
                }

                function sendBlob(blob, fileName) {
                    if (!blob || !window.AndroidDownloadBridge || !window.AndroidDownloadBridge.downloadBase64) return;
                    var reader = new FileReader();
                    reader.onloadend = function () {
                        var result = String(reader.result || '');
                        var marker = 'base64,';
                        var idx = result.indexOf(marker);
                        if (idx === -1) return;
                        var base64 = result.substring(idx + marker.length);
                        window.AndroidDownloadBridge.downloadBase64(
                            base64,
                            blob.type || 'application/octet-stream',
                            normalizeName(fileName)
                        );
                    };
                    reader.readAsDataURL(blob);
                }

                async function resolveAndSend(url, nameHint) {
                    try {
                        var res = await fetch(url);
                        var blob = await res.blob();
                        sendBlob(blob, nameHint);
                    } catch (e) {}
                }

                document.addEventListener('click', function (event) {
                    var anchor = event.target && event.target.closest ? event.target.closest('a[href], a[download]') : null;
                    if (!anchor) return;
                    var href = anchor.getAttribute('href') || '';
                    if (!href || !href.startsWith('blob:')) return;
                    event.preventDefault();
                    resolveAndSend(href, anchor.getAttribute('download') || '');
                }, true);

                var originalOpen = window.open;
                window.open = function (url, target, features) {
                    if (typeof url === 'string' && url.startsWith('blob:')) {
                        resolveAndSend(url, '');
                        return null;
                    }
                    if (originalOpen) return originalOpen.call(window, url, target, features);
                    return null;
                };
            })();
        """.trimIndent()
        webView.evaluateJavascript(script, null)
    }

    private fun startFileDownload(
        url: String,
        userAgent: String?,
        contentDisposition: String?,
        mimeType: String?,
    ) {
        try {
            val fileName = URLUtil.guessFileName(url, contentDisposition, mimeType)
            val request = DownloadManager.Request(Uri.parse(url)).apply {
                setMimeType(mimeType)
                setTitle(fileName)
                setDescription(getString(R.string.download_started))
                setNotificationVisibility(DownloadManager.Request.VISIBILITY_VISIBLE_NOTIFY_COMPLETED)
                setAllowedOverMetered(true)
                setAllowedOverRoaming(true)
                addRequestHeader("User-Agent", userAgent ?: "")
                CookieManager.getInstance().getCookie(url)?.let { addRequestHeader("Cookie", it) }
                setDestinationInExternalPublicDir(Environment.DIRECTORY_DOWNLOADS, fileName)
            }
            val downloadManager = getSystemService(DOWNLOAD_SERVICE) as DownloadManager
            downloadManager.enqueue(request)
            Toast.makeText(this, getString(R.string.download_started), Toast.LENGTH_SHORT).show()
        } catch (_: Exception) {
            openExternal(Intent(Intent.ACTION_VIEW, Uri.parse(url)))
        }
    }

    internal fun saveBase64ToDownloads(
        base64Data: String,
        mimeType: String,
        requestedName: String,
    ): Boolean {
        val cleanBase64 = base64Data.substringAfter("base64,", base64Data).trim()
        if (cleanBase64.isEmpty()) return false
        val bytes = Base64.decode(cleanBase64, Base64.DEFAULT)
        if (bytes.isEmpty()) return false

        val fileName = sanitizeFileName(requestedName)

        return if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) {
            val values = ContentValues().apply {
                put(MediaStore.Downloads.DISPLAY_NAME, fileName)
                put(MediaStore.Downloads.MIME_TYPE, mimeType)
                put(MediaStore.Downloads.RELATIVE_PATH, Environment.DIRECTORY_DOWNLOADS)
                put(MediaStore.Downloads.IS_PENDING, 1)
            }

            val resolver = contentResolver
            val uri = resolver.insert(MediaStore.Downloads.EXTERNAL_CONTENT_URI, values) ?: return false
            try {
                resolver.openOutputStream(uri)?.use { output ->
                    output.write(bytes)
                    output.flush()
                } ?: return false

                values.clear()
                values.put(MediaStore.Downloads.IS_PENDING, 0)
                resolver.update(uri, values, null, null)
                true
            } catch (_: Exception) {
                resolver.delete(uri, null, null)
                false
            }
        } else {
            val downloadsDir = Environment.getExternalStoragePublicDirectory(Environment.DIRECTORY_DOWNLOADS)
            if (!downloadsDir.exists()) downloadsDir.mkdirs()
            val outFile = File(downloadsDir, fileName)
            try {
                FileOutputStream(outFile).use { output ->
                    output.write(bytes)
                    output.flush()
                }
                true
            } catch (_: Exception) {
                false
            }
        }
    }

    private fun sanitizeFileName(name: String): String {
        val trimmed = name.trim().ifEmpty { "download-${System.currentTimeMillis()}" }
        val clean = trimmed.replace(Regex("[\\\\/:*?\"<>|]+"), "_")
        return if (clean.length > 120) clean.takeLast(120) else clean
    }

    private fun startOnboardingAutoSlide() {
        stopOnboardingAutoSlide()
        autoSlideHandler.postDelayed(autoSlideRunnable, ONBOARDING_AUTO_SLIDE_MS)
    }

    private fun stopOnboardingAutoSlide() {
        autoSlideHandler.removeCallbacks(autoSlideRunnable)
    }

    companion object {
        private const val KEY_WEB_STATE = "web_state"
        private const val ONBOARDING_AUTO_SLIDE_MS = 5_000L
    }
}

private class PrintBridge(private val activity: MainActivity) {
    @JavascriptInterface
    fun printCurrentPage() {
        activity.runOnUiThread {
            try {
                val jobName = "BelovedSubP-${System.currentTimeMillis()}"
                val printManager = activity.getSystemService(Context.PRINT_SERVICE) as PrintManager
                val adapter = activity.webView.createPrintDocumentAdapter(jobName)
                val attributes = PrintAttributes.Builder().build()
                printManager.print(jobName, adapter, attributes)
                Toast.makeText(activity, activity.getString(R.string.print_started), Toast.LENGTH_SHORT).show()
            } catch (_: Exception) {
                Toast.makeText(activity, activity.getString(R.string.unable_to_open_link), Toast.LENGTH_SHORT).show()
            }
        }
    }
}

private class DownloadBridge(private val activity: MainActivity) {
    @JavascriptInterface
    fun downloadBase64(base64Data: String, mimeType: String?, fileName: String?) {
        activity.runOnUiThread {
            val ok = try {
                activity.saveBase64ToDownloads(
                    base64Data = base64Data,
                    mimeType = mimeType ?: "application/octet-stream",
                    requestedName = fileName ?: "document-${System.currentTimeMillis()}.bin",
                )
            } catch (_: Exception) {
                false
            }

            if (ok) {
                Toast.makeText(
                    activity,
                    activity.getString(R.string.download_saved),
                    Toast.LENGTH_SHORT
                ).show()
            } else {
                Toast.makeText(
                    activity,
                    activity.getString(R.string.download_failed),
                    Toast.LENGTH_SHORT
                ).show()
            }
        }
    }
}
