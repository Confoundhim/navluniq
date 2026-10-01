package com.navluniq.toplayici;

import android.content.Context;
import android.content.SharedPreferences;

/** Ayarlar: sunucu adresi, anahtar, açık/kapalı seçenekler, son gönderim durumu. Yalnız bu telefonda durur. */
public final class Prefs {
    public static final int VERSION_CODE = 4;
    public static final String VERSION_NAME = "1.3";
    private static final String FILE = "toplayici";

    private Prefs() {}

    private static SharedPreferences sp(Context c) {
        return c.getSharedPreferences(FILE, Context.MODE_PRIVATE);
    }

    public static String url(Context c) {
        String u = sp(c).getString("url", "https://navluniq.com").trim();
        while (u.endsWith("/")) {
            u = u.substring(0, u.length() - 1);
        }
        return u;
    }

    public static String token(Context c) {
        return sp(c).getString("token", "").trim();
    }

    public static boolean fbEnabled(Context c) {
        return sp(c).getBoolean("fb", true);
    }

    public static boolean waEnabled(Context c) {
        return sp(c).getBoolean("wa", true); // v1.3: WhatsApp da uygulamadan (Osman); MacroDroid WhatsApp makrosu doğrulama sonrası kapatılır
    }

    public static boolean waGroupsOnly(Context c) {
        return sp(c).getBoolean("wa_groups_only", true);
    }

    public static boolean autoExpand(Context c) {
        return sp(c).getBoolean("auto_expand", true); // v1.2: Osman tam metni istiyor; "diğer" düğmesine uygulama dokunur, kullanıcı değil
    }

    public static void save(Context c, String url, String token) {
        sp(c).edit().putString("url", url.trim()).putString("token", token.trim()).apply();
    }

    public static void setBool(Context c, String key, boolean value) {
        sp(c).edit().putBoolean(key, value).apply();
    }

    public static void recordSend(Context c, boolean ok, String result) {
        SharedPreferences.Editor e = sp(c).edit().putLong("last_send_at", System.currentTimeMillis()).putString("last_send_result", result);
        if (ok) {
            e.putInt("sent_count", sp(c).getInt("sent_count", 0) + 1);
        }
        e.apply();
    }

    public static long lastSendAt(Context c) {
        return sp(c).getLong("last_send_at", 0L);
    }

    public static String lastSendResult(Context c) {
        return sp(c).getString("last_send_result", "");
    }

    public static int sentCount(Context c) {
        return sp(c).getInt("sent_count", 0);
    }

    public static void bumpScreens(Context c) {
        sp(c).edit().putInt("fb_screens", sp(c).getInt("fb_screens", 0) + 1).apply();
    }

    public static int screens(Context c) {
        return sp(c).getInt("fb_screens", 0);
    }

    public static void bumpWhatsApp(Context c) {
        sp(c).edit().putInt("wa_messages", sp(c).getInt("wa_messages", 0) + 1).apply();
    }

    public static int whatsAppMessages(Context c) {
        return sp(c).getInt("wa_messages", 0);
    }

    public static void bumpExpanded(Context c) {
        sp(c).edit().putInt("fb_expanded", sp(c).getInt("fb_expanded", 0) + 1).apply();
    }

    /** Uygulamanın "diğer" düğmesine dokunup açtığı gönderi sayısı; telefonda mekanizmanın çalıştığı buradan görülür. */
    public static int expanded(Context c) {
        return sp(c).getInt("fb_expanded", 0);
    }
}
