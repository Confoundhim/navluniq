package com.navluniq.toplayici;

import android.accessibilityservice.AccessibilityService;
import android.os.Handler;
import android.os.Looper;
import android.view.accessibility.AccessibilityEvent;
import android.view.accessibility.AccessibilityNodeInfo;

import java.util.ArrayList;
import java.util.List;

/**
 * Facebook ekranını okur (yalnız Facebook paketleri; bkz. res/xml/accessibility_service_config.xml). Kullanıcı Facebook'ta
 * normal gezinir; ekran değişince 1,2 sn beklenir, görünen yazılar satır satır toplanır, aynı ekran bir daha gönderilmez.
 * Dökümler "-----" ayracıyla biriktirilir ve 20 sn'de bir ya da 60 KB dolunca tek istekle sunucuya gider.
 * Hiçbir şeye dokunulmaz; tek istisna, açıksa, kısaltılmış gönderinin "diğer" (devamını gör) düğmesi.
 */
public class ScreenReaderService extends AccessibilityService {
    private static final long CAPTURE_DELAY_MS = 700;   // son olaydan sonra bekleme (kullanıcı durdu)
    private static final long CAPTURE_EVERY_MS = 350;   // hızlı kaydırmada en az bu sıklıkla okuma (kaydırma bitmese de)
    private static final long RECLICK_GUARD_MS = 6000;  // aynı "diğer" düğmesine ikinci kez dokunulmaz
    private static final long FLUSH_AFTER_MS = 12000;  // küçük ve sık paket: ağ kopmasında az kayıp, hızlı yeniden deneme
    private static final int FLUSH_AT_CHARS = 24000;
    private static final int MAX_NODES = 2500;
    private static final int MAX_DEPTH = 80;
    private static final int MAX_EXPAND_CLICKS = 3;

    private final Handler handler = new Handler(Looper.getMainLooper());
    private final StringBuilder buffer = new StringBuilder();
    private long bufferStartedAt = 0L;
    private int lastHash = 0;
    private int screensInBuffer = 0;
    private long lastCaptureAt = 0L;
    private String lastExpandSeen = "";
    private final java.util.LinkedHashMap<String, Long> clicked = new java.util.LinkedHashMap<String, Long>();

    private final Runnable capture = new Runnable() {
        @Override
        public void run() {
            captureNow();
        }
    };

    private final Runnable flush = new Runnable() {
        @Override
        public void run() {
            flushBuffer("süre doldu");
        }
    };

    @Override
    protected void onServiceConnected() {
        super.onServiceConnected();
        AppLog.add(this, "Facebook okuma hizmeti bağlandı.");
    }

    @Override
    public void onAccessibilityEvent(AccessibilityEvent event) {
        if (event == null || !Prefs.fbEnabled(this)) {
            return;
        }
        int type = event.getEventType();
        if (type != AccessibilityEvent.TYPE_WINDOW_CONTENT_CHANGED && type != AccessibilityEvent.TYPE_VIEW_SCROLLED
            && type != AccessibilityEvent.TYPE_WINDOW_STATE_CHANGED) {
            return;
        }
        // Hızlı kaydırmada bile her 350 ms'de bir okuma; kaydırma durunca 700 ms sonra son okuma.
        long sinceLast = System.currentTimeMillis() - lastCaptureAt;
        handler.removeCallbacks(capture);
        if (sinceLast >= CAPTURE_EVERY_MS) {
            handler.post(capture);
        } else {
            handler.postDelayed(capture, CAPTURE_EVERY_MS - sinceLast);
        }
        handler.postDelayed(capture, CAPTURE_DELAY_MS);
    }

    @Override
    public void onInterrupt() {
        // okuma kesildi; bir sonraki olayda devam eder
    }

    @Override
    public boolean onUnbind(android.content.Intent intent) {
        flushBuffer("hizmet kapanıyor");
        return super.onUnbind(intent);
    }

    private void captureNow() {
        lastCaptureAt = System.currentTimeMillis();
        AccessibilityNodeInfo root;
        try {
            root = getRootInActiveWindow();
        } catch (Exception e) {
            return;
        }
        if (root == null) {
            return;
        }
        CharSequence pkg = root.getPackageName();
        if (pkg == null || !pkg.toString().startsWith("com.facebook.")) {
            return;
        }
        List<String> lines = new ArrayList<String>();
        List<AccessibilityNodeInfo> expandable = new ArrayList<AccessibilityNodeInfo>();
        List<String> expandKeys = new ArrayList<String>();
        int[] budget = {MAX_NODES};
        walk(root, lines, expandable, expandKeys, budget, 0);

        if (!expandable.isEmpty()) {
            int clickable = 0;
            for (AccessibilityNodeInfo n : expandable) {
                try {
                    if (n.isClickable()) {
                        clickable++;
                    }
                } catch (Exception ignored) {
                    // düğüm kaybolmuş olabilir
                }
            }
            String seen = expandable.size() + "/" + clickable;
            if (!seen.equals(lastExpandSeen)) {
                lastExpandSeen = seen;
                AppLog.add(this, "\"diğer\" düğmesi: " + expandable.size() + " görüldü, " + clickable + " dokunulabilir.");
            }
        }
        if (Prefs.autoExpand(this)) {
            // Kısaltılmış gönderinin "diğer" düğmesine uygulama dokunur (kullanıcı değil); gönderi yerinde açılır ve bir
            // sonraki okumada tam metin gelir. Aynı düğmeye 6 sn içinde ikinci kez dokunulmaz (anahtar: önceki satırın metni).
            long now = System.currentTimeMillis();
            int clicks = 0;
            for (int i = 0; i < expandable.size() && clicks < MAX_EXPAND_CLICKS; i++) {
                String key = expandKeys.get(i);
                Long last = clicked.get(key);
                if (last != null && now - last < RECLICK_GUARD_MS) {
                    continue;
                }
                if (clickOrParent(expandable.get(i))) {
                    clicks++;
                    clicked.put(key, now);
                    Prefs.bumpExpanded(this);
                }
            }
            while (clicked.size() > 200) {
                clicked.remove(clicked.keySet().iterator().next());
            }
            if (clicks > 0) {
                handler.removeCallbacks(capture);
                handler.postDelayed(capture, CAPTURE_DELAY_MS); // açılan metin bir sonraki okumada gelir
            }
        }

        if (lines.size() < 3) {
            return;
        }
        StringBuilder sb = new StringBuilder();
        for (String l : lines) {
            sb.append(l).append('\n');
        }
        String dump = sb.toString();
        int hash = dump.hashCode();
        if (hash == lastHash) {
            return;
        }
        lastHash = hash;
        appendToBuffer(dump);
    }

    private void walk(AccessibilityNodeInfo node, List<String> lines, List<AccessibilityNodeInfo> expandable, List<String> expandKeys, int[] budget, int depth) {
        if (node == null || depth > MAX_DEPTH || budget[0] <= 0) {
            return;
        }
        budget[0]--;
        if (node.isVisibleToUser()) {
            CharSequence text = node.getText();
            CharSequence desc = node.getContentDescription();
            String line = null;
            if (text != null && text.toString().trim().length() > 0) {
                line = text.toString();
            } else if (desc != null && desc.toString().trim().length() > 0) {
                line = desc.toString();
            }
            if (line != null) {
                line = line.replace(' ', ' ').trim();
                lines.add(line);
                if (isSeeMore(line)) {
                    expandable.add(node);
                    expandKeys.add(lines.size() >= 2 ? lines.get(lines.size() - 2) : String.valueOf(lines.size()));
                }
            }
        }
        int count = node.getChildCount();
        for (int i = 0; i < count && budget[0] > 0; i++) {
            AccessibilityNodeInfo child = null;
            try {
                child = node.getChild(i);
            } catch (Exception ignored) {
                // pencere değişmiş olabilir
            }
            if (child != null) {
                walk(child, lines, expandable, expandKeys, budget, depth + 1);
            }
        }
    }

    private static boolean isSeeMore(String line) {
        return line.equals("diğer") || line.equals("Devamını gör") || line.equals("See more") || line.equals("Daha fazla gör");
    }

    /**
     * Yalnız "diğer" düğmesinin kendisine dokunulur; üst öğeye (gönderinin tamamına) asla çıkılmaz, yoksa gönderi sayfası
     * açılıp kullanıcının akışı bozulurdu. Düğme dokunmaya kapalıysa işlem yapılmaz (false döner, hiçbir şey olmaz).
     */
    private static boolean clickOrParent(AccessibilityNodeInfo node) {
        if (node == null) {
            return false;
        }
        try {
            if (!node.isClickable()) {
                return false;
            }
            return node.performAction(AccessibilityNodeInfo.ACTION_CLICK);
        } catch (Exception e) {
            return false;
        }
    }

    private void appendToBuffer(String dump) {
        if (buffer.length() == 0) {
            bufferStartedAt = System.currentTimeMillis();
            handler.removeCallbacks(flush);
            handler.postDelayed(flush, FLUSH_AFTER_MS);
        }
        buffer.append("\n-----\n").append(dump);
        screensInBuffer++;
        Prefs.bumpScreens(this);
        if (buffer.length() >= FLUSH_AT_CHARS) {
            flushBuffer("paket doldu");
        }
    }

    private void flushBuffer(String why) {
        handler.removeCallbacks(flush);
        if (buffer.length() == 0) {
            return;
        }
        String payload = buffer.toString();
        int screens = screensInBuffer;
        buffer.setLength(0);
        screensInBuffer = 0;
        bufferStartedAt = 0L;
        AppLog.add(this, "Facebook: " + screens + " ekran paketlendi (" + why + "), gönderiliyor…");
        Uploader.enqueueScreen(this, payload);
    }
}
