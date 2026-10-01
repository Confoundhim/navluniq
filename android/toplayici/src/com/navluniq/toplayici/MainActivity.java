package com.navluniq.toplayici;

import android.app.Activity;
import android.content.ComponentName;
import android.content.Context;
import android.content.Intent;
import android.graphics.Color;
import android.graphics.Typeface;
import android.net.Uri;
import android.os.Bundle;
import android.os.Handler;
import android.os.Looper;
import android.os.PowerManager;
import android.provider.Settings;
import android.text.InputType;
import android.text.format.DateUtils;
import android.util.TypedValue;
import android.view.Gravity;
import android.view.View;
import android.view.ViewGroup;
import android.widget.Button;
import android.widget.CompoundButton;
import android.widget.EditText;
import android.widget.LinearLayout;
import android.widget.ScrollView;
import android.widget.Switch;
import android.widget.TextView;
import android.widget.Toast;

import java.io.BufferedReader;
import java.io.InputStreamReader;
import java.net.HttpURLConnection;
import java.net.URL;
import java.nio.charset.StandardCharsets;
import java.util.List;
import java.util.regex.Matcher;
import java.util.regex.Pattern;

/** Tek ekran: adres + anahtar, izin düğmeleri, açık/kapalı seçenekler, durum ve günlük. Arayüz kodla kurulur (düzen dosyası yok). */
public class MainActivity extends Activity {
    private EditText urlInput;
    private EditText tokenInput;
    private TextView a11yStatus;
    private TextView listenerStatus;
    private TextView batteryStatus;
    private TextView sendStatus;
    private TextView updateStatus;
    private TextView logView;
    private final Handler handler = new Handler(Looper.getMainLooper());
    private final Runnable refresher = new Runnable() {
        @Override
        public void run() {
            refresh();
            handler.postDelayed(this, 3000);
        }
    };

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        final Context c = this;
        LinearLayout root = new LinearLayout(this);
        root.setOrientation(LinearLayout.VERTICAL);
        int pad = dp(16);
        root.setPadding(pad, pad, pad, pad);

        TextView title = text("NavlunIQ Toplayıcı", 20, true);
        root.addView(title);
        root.addView(text("Sürüm " + Prefs.VERSION_NAME + " · WhatsApp grup bildirimlerini ve Facebook'ta gördüğünüz grup gönderilerini NavlunIQ sunucusuna iletir. Başka hiçbir yere veri gitmez.", 13, false));

        root.addView(section("1. Sunucu"));
        urlInput = input("Sunucu adresi", Prefs.url(this), InputType.TYPE_TEXT_VARIATION_URI);
        tokenInput = input("Anahtar (panel → Dış kaynak → Kaynaklar ve telefon)", Prefs.token(this), InputType.TYPE_TEXT_FLAG_NO_SUGGESTIONS);
        root.addView(urlInput);
        root.addView(tokenInput);
        LinearLayout row1 = row();
        row1.addView(button("Kaydet", new View.OnClickListener() {
            @Override
            public void onClick(View v) {
                Prefs.save(c, urlInput.getText().toString(), tokenInput.getText().toString());
                Toast.makeText(c, "Kaydedildi", Toast.LENGTH_SHORT).show();
                Uploader.flush(c);
                refresh();
            }
        }));
        row1.addView(button("Bağlantıyı sına", new View.OnClickListener() {
            @Override
            public void onClick(View v) {
                Prefs.save(c, urlInput.getText().toString(), tokenInput.getText().toString());
                ping();
            }
        }));
        root.addView(row1);

        root.addView(section("2. İzinler"));
        a11yStatus = text("", 14, false);
        root.addView(a11yStatus);
        LinearLayout row2 = row();
        row2.addView(button("Facebook okuma iznini aç", new View.OnClickListener() {
            @Override
            public void onClick(View v) {
                open(new Intent(Settings.ACTION_ACCESSIBILITY_SETTINGS));
            }
        }));
        root.addView(row2);
        listenerStatus = text("", 14, false);
        root.addView(listenerStatus);
        LinearLayout row3 = row();
        row3.addView(button("WhatsApp bildirim iznini aç", new View.OnClickListener() {
            @Override
            public void onClick(View v) {
                open(new Intent(Settings.ACTION_NOTIFICATION_LISTENER_SETTINGS));
            }
        }));
        root.addView(row3);
        batteryStatus = text("", 14, false);
        root.addView(batteryStatus);
        LinearLayout row4 = row();
        row4.addView(button("Pil kısıtlamasını kaldır", new View.OnClickListener() {
            @Override
            public void onClick(View v) {
                Intent i = new Intent(Settings.ACTION_REQUEST_IGNORE_BATTERY_OPTIMIZATIONS);
                i.setData(Uri.parse("package:" + getPackageName()));
                open(i);
            }
        }));
        row4.addView(button("Uygulama bilgisi", new View.OnClickListener() {
            @Override
            public void onClick(View v) {
                Intent i = new Intent(Settings.ACTION_APPLICATION_DETAILS_SETTINGS);
                i.setData(Uri.parse("package:" + getPackageName()));
                open(i);
            }
        }));
        root.addView(row4);
        root.addView(text("İzin anahtarı gri ve tıklanmıyorsa (Android 13+): Uygulama bilgisi → sağ üst ⋮ → \"Kısıtlı ayarlara izin ver\", sonra tekrar deneyin.", 12, false));

        root.addView(section("3. Seçenekler"));
        root.addView(toggle("Facebook gönderilerini topla", "fb", Prefs.fbEnabled(this)));
        root.addView(toggle("Uzun gönderileri kendiliğinden aç (\"diğer\")", "auto_expand", Prefs.autoExpand(this)));
        root.addView(toggle("WhatsApp bildirimlerini ilet", "wa", Prefs.waEnabled(this)));
        root.addView(toggle("Yalnız grup sohbetleri (kişisel sohbetler gitmez)", "wa_groups_only", Prefs.waGroupsOnly(this)));

        root.addView(section("4. Durum"));
        sendStatus = text("", 14, false);
        root.addView(sendStatus);
        updateStatus = text("", 13, false);
        updateStatus.setTextColor(Color.rgb(180, 83, 9));
        root.addView(updateStatus);
        LinearLayout row5 = row();
        row5.addView(button("Bekleyenleri şimdi gönder", new View.OnClickListener() {
            @Override
            public void onClick(View v) {
                Uploader.flush(c);
                Toast.makeText(c, "Gönderim başlatıldı", Toast.LENGTH_SHORT).show();
            }
        }));
        root.addView(row5);

        root.addView(section("Günlük (son olaylar)"));
        logView = text("", 12, false);
        logView.setTypeface(Typeface.MONOSPACE);
        root.addView(logView);

        ScrollView scroll = new ScrollView(this);
        scroll.addView(root);
        setContentView(scroll);
    }

    @Override
    protected void onResume() {
        super.onResume();
        refresh();
        handler.removeCallbacks(refresher);
        handler.postDelayed(refresher, 3000);
        checkUpdate();
    }

    @Override
    protected void onPause() {
        super.onPause();
        handler.removeCallbacks(refresher);
    }

    private void refresh() {
        boolean a11y = isAccessibilityEnabled();
        boolean listener = isListenerEnabled();
        PowerManager pm = (PowerManager) getSystemService(POWER_SERVICE);
        boolean battery = pm != null && pm.isIgnoringBatteryOptimizations(getPackageName());
        a11yStatus.setText((a11y ? "✓ " : "✗ ") + "Facebook okuma (Erişilebilirlik): " + (a11y ? "açık" : "kapalı"));
        a11yStatus.setTextColor(a11y ? Color.rgb(21, 128, 61) : Color.rgb(185, 28, 28));
        listenerStatus.setText((listener ? "✓ " : "✗ ") + "WhatsApp bildirimleri: " + (listener ? "açık" : "kapalı"));
        listenerStatus.setTextColor(listener ? Color.rgb(21, 128, 61) : Color.rgb(185, 28, 28));
        batteryStatus.setText((battery ? "✓ " : "! ") + "Pil kısıtlaması: " + (battery ? "kaldırıldı" : "açık (arka planda durabilir)"));
        batteryStatus.setTextColor(battery ? Color.rgb(21, 128, 61) : Color.rgb(180, 83, 9));

        long last = Prefs.lastSendAt(this);
        String when = last == 0 ? "henüz yok" : DateUtils.getRelativeTimeSpanString(last, System.currentTimeMillis(), DateUtils.MINUTE_IN_MILLIS).toString();
        sendStatus.setText("Son gönderim: " + when + (Prefs.lastSendResult(this).isEmpty() ? "" : " · " + Prefs.lastSendResult(this))
            + "\nBekleyen paket: " + Uploader.pending(this)
            + "\nToplam gönderim: " + Prefs.sentCount(this) + " · Facebook ekranı: " + Prefs.screens(this) + " · WhatsApp mesajı: " + Prefs.whatsAppMessages(this)
            + (Prefs.token(this).isEmpty() ? "\n! Anahtar girilmedi; hiçbir şey gönderilmez." : ""));

        List<String> lines = AppLog.read(this);
        StringBuilder sb = new StringBuilder();
        for (int i = lines.size() - 1; i >= 0 && i >= lines.size() - 60; i--) {
            sb.append(lines.get(i)).append('\n');
        }
        logView.setText(sb.length() == 0 ? "Henüz olay yok." : sb.toString());
    }

    private boolean isAccessibilityEnabled() {
        String enabled = Settings.Secure.getString(getContentResolver(), Settings.Secure.ENABLED_ACCESSIBILITY_SERVICES);
        String me = new ComponentName(this, ScreenReaderService.class).flattenToString();
        String meShort = new ComponentName(this, ScreenReaderService.class).flattenToShortString();
        return enabled != null && (enabled.contains(me) || enabled.contains(meShort));
    }

    private boolean isListenerEnabled() {
        String enabled = Settings.Secure.getString(getContentResolver(), "enabled_notification_listeners");
        return enabled != null && enabled.contains(getPackageName());
    }

    private void ping() {
        final Context c = this;
        final String url = Prefs.url(this) + "/api/v1/webhook/notification/ping";
        final String token = Prefs.token(this);
        if (token.isEmpty()) {
            Toast.makeText(c, "Önce anahtarı girin", Toast.LENGTH_LONG).show();
            return;
        }
        new Thread(new Runnable() {
            @Override
            public void run() {
                String result;
                try {
                    HttpURLConnection conn = (HttpURLConnection) new URL(url).openConnection();
                    conn.setConnectTimeout(15000);
                    conn.setReadTimeout(20000);
                    conn.setRequestProperty("X-Scraper-Token", token);
                    conn.setRequestProperty("X-Intake-App", "toplayici/" + Prefs.VERSION_NAME);
                    int code = conn.getResponseCode();
                    result = code == 200 ? "Sunucuya ulaşıldı, anahtar doğru. Canlı akışta \"Bağlantı sınaması\" satırı görünmeli."
                        : code == 401 ? "Sunucuya ulaşıldı ama anahtar hatalı (401)." : "Sunucu " + code + " döndü.";
                    conn.disconnect();
                } catch (Exception e) {
                    result = "Sunucuya ulaşılamadı: " + e.getClass().getSimpleName() + " " + (e.getMessage() == null ? "" : e.getMessage());
                }
                final String shown = result;
                AppLog.add(c, "Bağlantı sınaması: " + shown);
                handler.post(new Runnable() {
                    @Override
                    public void run() {
                        Toast.makeText(c, shown, Toast.LENGTH_LONG).show();
                        refresh();
                    }
                });
            }
        }).start();
    }

    /** Sunucudaki sürüm bilgisini okur; yenisi varsa indirme bağlantısı gösterir. */
    private void checkUpdate() {
        final Context c = this;
        final String base = Prefs.url(this);
        new Thread(new Runnable() {
            @Override
            public void run() {
                try {
                    HttpURLConnection conn = (HttpURLConnection) new URL(base + "/api/v1/toplayici/version").openConnection();
                    conn.setConnectTimeout(10000);
                    conn.setReadTimeout(10000);
                    BufferedReader r = new BufferedReader(new InputStreamReader(conn.getInputStream(), StandardCharsets.UTF_8));
                    StringBuilder sb = new StringBuilder();
                    String line;
                    while ((line = r.readLine()) != null) {
                        sb.append(line);
                    }
                    r.close();
                    Matcher code = Pattern.compile("\"versionCode\"\\s*:\\s*(\\d+)").matcher(sb);
                    Matcher name = Pattern.compile("\"versionName\"\\s*:\\s*\"([^\"]+)\"").matcher(sb);
                    Matcher url = Pattern.compile("\"url\"\\s*:\\s*\"([^\"]+)\"").matcher(sb);
                    if (code.find() && Integer.parseInt(code.group(1)) > Prefs.VERSION_CODE && url.find()) {
                        final String v = name.find() ? name.group(1) : code.group(1);
                        final String link = url.group(1).replace("\\/", "/");
                        handler.post(new Runnable() {
                            @Override
                            public void run() {
                                updateStatus.setText("Yeni sürüm var: " + v + " · dokunup indirin, sonra dosyaya dokunup kurun.");
                                updateStatus.setOnClickListener(new View.OnClickListener() {
                                    @Override
                                    public void onClick(View view) {
                                        open(new Intent(Intent.ACTION_VIEW, Uri.parse(link)));
                                    }
                                });
                            }
                        });
                    }
                } catch (Exception ignored) {
                    // sürüm denetimi isteğe bağlı
                }
            }
        }).start();
    }

    private void open(Intent i) {
        try {
            i.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK);
            startActivity(i);
        } catch (Exception e) {
            Toast.makeText(this, "Bu ekran açılamadı: " + e.getMessage(), Toast.LENGTH_LONG).show();
        }
    }

    private TextView section(String s) {
        TextView t = text(s, 16, true);
        t.setPadding(0, dp(18), 0, dp(6));
        return t;
    }

    private TextView text(String s, int sp, boolean bold) {
        TextView t = new TextView(this);
        t.setText(s);
        t.setTextSize(TypedValue.COMPLEX_UNIT_SP, sp);
        t.setTextColor(Color.rgb(31, 41, 55));
        if (bold) {
            t.setTypeface(Typeface.DEFAULT_BOLD);
        }
        t.setPadding(0, dp(4), 0, dp(4));
        return t;
    }

    private EditText input(String hint, String value, int type) {
        EditText e = new EditText(this);
        e.setHint(hint);
        e.setText(value);
        e.setSingleLine(true);
        e.setInputType(InputType.TYPE_CLASS_TEXT | type);
        e.setTextSize(TypedValue.COMPLEX_UNIT_SP, 15);
        return e;
    }

    private Button button(String label, View.OnClickListener l) {
        Button b = new Button(this);
        b.setText(label);
        b.setAllCaps(false);
        b.setOnClickListener(l);
        LinearLayout.LayoutParams lp = new LinearLayout.LayoutParams(0, ViewGroup.LayoutParams.WRAP_CONTENT, 1f);
        lp.setMargins(0, 0, dp(6), 0);
        b.setLayoutParams(lp);
        return b;
    }

    private LinearLayout row() {
        LinearLayout r = new LinearLayout(this);
        r.setOrientation(LinearLayout.HORIZONTAL);
        r.setGravity(Gravity.CENTER_VERTICAL);
        return r;
    }

    private Switch toggle(String label, final String key, boolean on) {
        final Context c = this;
        Switch s = new Switch(this);
        s.setText(label);
        s.setChecked(on);
        s.setTextSize(TypedValue.COMPLEX_UNIT_SP, 14);
        s.setPadding(0, dp(8), 0, dp(8));
        s.setOnCheckedChangeListener(new CompoundButton.OnCheckedChangeListener() {
            @Override
            public void onCheckedChanged(CompoundButton v, boolean checked) {
                Prefs.setBool(c, key, checked);
            }
        });
        return s;
    }

    private int dp(int v) {
        return (int) (v * getResources().getDisplayMetrics().density + 0.5f);
    }
}
