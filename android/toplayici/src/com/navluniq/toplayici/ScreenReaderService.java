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
    private static final long CAPTURE_DELAY_MS = 1200;
    private static final long FLUSH_AFTER_MS = 20000;
    private static final int FLUSH_AT_CHARS = 60000;
    private static final int MAX_NODES = 2500;
    private static final int MAX_DEPTH = 80;
    private static final int MAX_EXPAND_CLICKS = 2;

    private final Handler handler = new Handler(Looper.getMainLooper());
    private final StringBuilder buffer = new StringBuilder();
    private long bufferStartedAt = 0L;
    private int lastHash = 0;
    private int screensInBuffer = 0;

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
        handler.removeCallbacks(capture);
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
        int[] budget = {MAX_NODES};
        walk(root, lines, expandable, budget, 0);

        if (Prefs.autoExpand(this)) {
            int clicks = 0;
            for (AccessibilityNodeInfo n : expandable) {
                if (clicks >= MAX_EXPAND_CLICKS) {
                    break;
                }
                if (clickOrParent(n)) {
                    clicks++;
                }
            }
            if (clicks > 0) {
                // gönderi açılınca ekran değişir; yeni okuma zaten tetiklenir
                handler.removeCallbacks(capture);
                handler.postDelayed(capture, CAPTURE_DELAY_MS);
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

    private void walk(AccessibilityNodeInfo node, List<String> lines, List<AccessibilityNodeInfo> expandable, int[] budget, int depth) {
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
                walk(child, lines, expandable, budget, depth + 1);
            }
        }
    }

    private static boolean isSeeMore(String line) {
        return line.equals("diğer") || line.equals("Devamını gör") || line.equals("See more") || line.equals("Daha fazla gör");
    }

    private static boolean clickOrParent(AccessibilityNodeInfo node) {
        AccessibilityNodeInfo n = node;
        for (int i = 0; i < 4 && n != null; i++) {
            if (n.isClickable()) {
                try {
                    return n.performAction(AccessibilityNodeInfo.ACTION_CLICK);
                } catch (Exception e) {
                    return false;
                }
            }
            try {
                n = n.getParent();
            } catch (Exception e) {
                return false;
            }
        }
        return false;
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
