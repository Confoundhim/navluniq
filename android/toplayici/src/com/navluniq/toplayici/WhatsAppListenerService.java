package com.navluniq.toplayici;

import android.app.Notification;
import android.os.Bundle;
import android.os.Parcelable;
import android.service.notification.NotificationListenerService;
import android.service.notification.StatusBarNotification;

import org.json.JSONObject;

import java.text.SimpleDateFormat;
import java.util.Date;
import java.util.LinkedHashMap;
import java.util.Locale;
import java.util.Map;

/**
 * WhatsApp (ve WhatsApp Business) bildirimlerini okur; grup sohbetlerindeki her yeni mesajı MacroDroid'in gönderdiği
 * biçimle (title = "Grup: Gönderen", text = mesaj, app = WhatsApp) sunucuya iletir. WhatsApp'a bağlanılmaz, yalnız
 * telefonun bildirim çekmecesindeki metin okunur. Aynı mesaj bildirim her güncellendiğinde yeniden gönderilmez.
 */
public class WhatsAppListenerService extends NotificationListenerService {
    private static final int REMEMBER = 600;
    private final LinkedHashMap<String, Long> sent = new LinkedHashMap<String, Long>(64, 0.75f, true) {
        @Override
        protected boolean removeEldestEntry(Map.Entry<String, Long> eldest) {
            return size() > REMEMBER;
        }
    };

    @Override
    public void onListenerConnected() {
        super.onListenerConnected();
        AppLog.add(this, "WhatsApp bildirim dinleyicisi bağlandı.");
    }

    @Override
    public void onNotificationPosted(StatusBarNotification sbn) {
        if (sbn == null || !Prefs.waEnabled(this)) {
            return;
        }
        String pkg = sbn.getPackageName();
        if (!"com.whatsapp".equals(pkg) && !"com.whatsapp.w4b".equals(pkg)) {
            return;
        }
        Notification n = sbn.getNotification();
        if (n == null || (n.flags & Notification.FLAG_GROUP_SUMMARY) != 0 || (n.flags & Notification.FLAG_ONGOING_EVENT) != 0) {
            return;
        }
        Bundle ex = n.extras;
        if (ex == null) {
            return;
        }
        String title = str(ex.getCharSequence(Notification.EXTRA_CONVERSATION_TITLE));
        if (title.isEmpty()) {
            title = str(ex.getCharSequence(Notification.EXTRA_TITLE));
        }
        if (title.isEmpty() || title.equalsIgnoreCase("WhatsApp") || title.equalsIgnoreCase("WhatsApp Business")) {
            return; // özet ("5 mesaj 2 sohbetten") ya da sistem bildirimi
        }
        boolean isGroup = ex.getBoolean(Notification.EXTRA_IS_GROUP_CONVERSATION, false)
            || str(ex.getCharSequence(Notification.EXTRA_CONVERSATION_TITLE)).length() > 0;

        Parcelable[] messages = ex.getParcelableArray(Notification.EXTRA_MESSAGES);
        String appName = "com.whatsapp.w4b".equals(pkg) ? "WhatsApp Business" : "WhatsApp";
        int sentNow = 0;
        if (messages != null && messages.length > 0) {
            for (Parcelable p : messages) {
                if (!(p instanceof Bundle)) {
                    continue;
                }
                Bundle m = (Bundle) p;
                String text = str(m.getCharSequence("text"));
                String sender = str(m.getCharSequence("sender"));
                if (sender.isEmpty() && android.os.Build.VERSION.SDK_INT >= 28) {
                    sender = personName(m.getParcelable("sender_person"));
                }
                long time = m.getLong("time", 0L);
                if (text.isEmpty()) {
                    continue;
                }
                boolean groupMsg = isGroup || !sender.isEmpty();
                if (Prefs.waGroupsOnly(this) && !groupMsg) {
                    continue;
                }
                String key = title + "|" + sender + "|" + text + "|" + time;
                if (seen(key)) {
                    continue;
                }
                send(appName, sender.isEmpty() ? title : title + ": " + sender, text, str(n.tickerText), time);
                sentNow++;
            }
        } else {
            String text = str(ex.getCharSequence(Notification.EXTRA_BIG_TEXT));
            if (text.isEmpty()) {
                text = str(ex.getCharSequence(Notification.EXTRA_TEXT));
            }
            CharSequence[] linesArr = ex.getCharSequenceArray(Notification.EXTRA_TEXT_LINES);
            if (text.isEmpty() && linesArr != null) {
                StringBuilder sb = new StringBuilder();
                for (CharSequence l : linesArr) {
                    if (l != null && l.length() > 0) {
                        sb.append(l).append('\n');
                    }
                }
                text = sb.toString().trim();
            }
            if (text.isEmpty()) {
                return;
            }
            if (Prefs.waGroupsOnly(this) && !isGroup) {
                return;
            }
            String key = title + "|" + text;
            if (!seen(key)) {
                send(appName, title, text, str(n.tickerText), sbn.getPostTime());
                sentNow++;
            }
        }
        if (sentNow > 0) {
            AppLog.add(this, "WhatsApp: \"" + shorten(title, 30) + "\" içinden " + sentNow + " mesaj kuyruğa alındı.");
        }
    }

    private boolean seen(String key) {
        synchronized (sent) {
            if (sent.containsKey(key)) {
                return true;
            }
            sent.put(key, System.currentTimeMillis());
            return false;
        }
    }

    private void send(String appName, String title, String text, String ticker, long time) {
        try {
            JSONObject o = new JSONObject();
            o.put("title", title);
            o.put("text", text);
            o.put("ticker", ticker);
            o.put("app", appName);
            o.put("posted_at", new SimpleDateFormat("yyyy-MM-dd HH:mm:ss", Locale.US).format(new Date(time > 0 ? time : System.currentTimeMillis())));
            Prefs.bumpWhatsApp(this);
            Uploader.enqueueJson(this, o.toString());
        } catch (Exception e) {
            AppLog.add(this, "WhatsApp paketi kurulamadı: " + e.getMessage());
        }
    }

    @android.annotation.TargetApi(28)
    private static String personName(Parcelable p) {
        if (p instanceof android.app.Person) {
            return str(((android.app.Person) p).getName());
        }
        return "";
    }

    private static String str(CharSequence cs) {
        return cs == null ? "" : cs.toString().trim();
    }

    private static String shorten(String s, int max) {
        return s.length() > max ? s.substring(0, max) + "…" : s;
    }
}
