package com.navluniq.toplayici;

import android.content.Context;

import java.io.BufferedReader;
import java.io.File;
import java.io.FileOutputStream;
import java.io.InputStream;
import java.io.InputStreamReader;
import java.io.OutputStream;
import java.net.HttpURLConnection;
import java.net.URL;
import java.nio.charset.StandardCharsets;
import java.nio.file.Files;
import java.util.Arrays;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;
import java.util.regex.Matcher;
import java.util.regex.Pattern;

/**
 * Sunucuya gönderim. Her paket önce telefonda bir kuyruk dosyasına yazılır, sonra sırayla gönderilir; internet yoksa
 * dosya kalır ve bir sonraki fırsatta gider (veri kaybı olmaz). Tek arka plan iş parçacığı, paralel gönderim yok.
 *
 * İki paket türü: ".screen" (Facebook ekran dökümü; düz metin gövde, X-Intake-Kind: screen) ve ".json" (WhatsApp
 * bildirimi; MacroDroid'in gönderdiği JSON ile aynı alanlar). Anahtar her zaman X-Scraper-Token başlığında gider.
 */
public final class Uploader {
    private static final ExecutorService EXEC = Executors.newSingleThreadExecutor();
    private static final int MAX_QUEUE = 120;
    private static final Pattern PROCESSED = Pattern.compile("\"processed\"\\s*:\\s*(\\d+)");
    private static final Pattern SKIPPED = Pattern.compile("\"reason\"\\s*:\\s*\"([^\"]{0,60})\"");

    private Uploader() {}

    public static void enqueueScreen(Context c, String dump) {
        enqueue(c, dump, "screen");
    }

    public static void enqueueJson(Context c, String json) {
        enqueue(c, json, "json");
    }

    private static void enqueue(final Context c, String body, String ext) {
        try {
            File dir = queueDir(c);
            File f = new File(dir, System.currentTimeMillis() + "-" + (int) (Math.random() * 1000) + "." + ext);
            FileOutputStream out = new FileOutputStream(f);
            out.write(body.getBytes(StandardCharsets.UTF_8));
            out.close();
            trim(dir);
        } catch (Exception e) {
            AppLog.add(c, "Kuyruğa yazılamadı: " + e.getMessage());
        }
        flush(c);
    }

    /** Kuyruktaki her şeyi sırayla gönderir; ağ hatasında durur (dosyalar kalır). */
    public static void flush(final Context c) {
        EXEC.execute(new Runnable() {
            @Override
            public void run() {
                flushNow(c.getApplicationContext());
            }
        });
    }

    public static int pending(Context c) {
        File[] files = queueDir(c).listFiles();
        return files == null ? 0 : files.length;
    }

    private static void flushNow(Context c) {
        String token = Prefs.token(c);
        if (token.isEmpty()) {
            return; // anahtar girilmeden gönderim yok; dosyalar bekler
        }
        File[] files = queueDir(c).listFiles();
        if (files == null || files.length == 0) {
            return;
        }
        Arrays.sort(files);
        for (File f : files) {
            boolean screen = f.getName().endsWith(".screen");
            String body;
            try {
                body = new String(Files.readAllBytes(f.toPath()), StandardCharsets.UTF_8);
            } catch (Exception e) {
                f.delete();
                continue;
            }
            int code;
            String response;
            try {
                HttpURLConnection conn = (HttpURLConnection) new URL(Prefs.url(c) + "/api/v1/webhook/notification").openConnection();
                conn.setConnectTimeout(15000);
                conn.setReadTimeout(40000);
                conn.setRequestMethod("POST");
                conn.setDoOutput(true);
                conn.setRequestProperty("X-Scraper-Token", token);
                conn.setRequestProperty("X-Intake-App", "toplayici/" + Prefs.VERSION_NAME);
                conn.setRequestProperty("Accept", "application/json");
                if (screen) {
                    conn.setRequestProperty("X-Intake-Kind", "screen");
                    conn.setRequestProperty("Content-Type", "text/plain; charset=utf-8");
                } else {
                    conn.setRequestProperty("Content-Type", "application/json; charset=utf-8");
                }
                byte[] bytes = body.getBytes(StandardCharsets.UTF_8);
                conn.setFixedLengthStreamingMode(bytes.length);
                OutputStream os = conn.getOutputStream();
                os.write(bytes);
                os.close();
                code = conn.getResponseCode();
                InputStream is = code >= 400 ? conn.getErrorStream() : conn.getInputStream();
                response = is == null ? "" : readAll(is);
                conn.disconnect();
            } catch (Exception e) {
                String msg = "Gönderilemedi (ağ): " + e.getClass().getSimpleName() + " " + (e.getMessage() == null ? "" : e.getMessage());
                AppLog.add(c, msg);
                Prefs.recordSend(c, false, msg);
                return; // sonraki fırsatta yeniden denenir
            }
            if (code >= 200 && code < 300) {
                String summary = summarize(response, screen);
                AppLog.add(c, (screen ? "Facebook dökümü gönderildi" : "WhatsApp mesajı gönderildi") + " · " + summary);
                Prefs.recordSend(c, true, "HTTP " + code + " · " + summary);
                f.delete();
            } else if (code == 401) {
                AppLog.add(c, "Anahtar hatalı (401): panelden anahtarı kopyalayıp yeniden girin.");
                Prefs.recordSend(c, false, "Anahtar hatalı (401)");
                return; // anahtar düzelince dosyalar gider
            } else if (code == 429 || code >= 500) {
                AppLog.add(c, "Sunucu meşgul (" + code + "), sonra yeniden denenecek.");
                Prefs.recordSend(c, false, "HTTP " + code);
                return;
            } else {
                AppLog.add(c, "Sunucu kabul etmedi (" + code + "): " + shorten(response, 120));
                Prefs.recordSend(c, false, "HTTP " + code);
                f.delete(); // bizim hatamız; tekrar denemek anlamsız
            }
        }
    }

    private static String summarize(String response, boolean screen) {
        Matcher m = PROCESSED.matcher(response);
        if (m.find()) {
            String n = m.group(1);
            if ("0".equals(n)) {
                Matcher r = SKIPPED.matcher(response);
                return r.find() ? "yeni gönderi yok (" + r.group(1) + ")" : "yeni gönderi yok";
            }
            return n + (screen ? " gönderi kuyruğa alındı" : " mesaj kuyruğa alındı");
        }
        return "kabul edildi";
    }

    private static String readAll(InputStream is) throws Exception {
        BufferedReader r = new BufferedReader(new InputStreamReader(is, StandardCharsets.UTF_8));
        StringBuilder sb = new StringBuilder();
        char[] buf = new char[4096];
        int n;
        while ((n = r.read(buf)) > 0 && sb.length() < 20000) {
            sb.append(buf, 0, n);
        }
        r.close();
        return sb.toString();
    }

    private static String shorten(String s, int max) {
        s = s.replaceAll("\\s+", " ").trim();
        return s.length() > max ? s.substring(0, max) + "…" : s;
    }

    private static File queueDir(Context c) {
        File dir = new File(c.getFilesDir(), "kuyruk");
        if (!dir.exists()) {
            dir.mkdirs();
        }
        return dir;
    }

    private static void trim(File dir) {
        File[] files = dir.listFiles();
        if (files == null || files.length <= MAX_QUEUE) {
            return;
        }
        Arrays.sort(files);
        for (int i = 0; i < files.length - MAX_QUEUE; i++) {
            files[i].delete();
        }
    }
}
