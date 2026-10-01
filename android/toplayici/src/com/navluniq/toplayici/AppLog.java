package com.navluniq.toplayici;

import android.content.Context;
import android.util.Log;

import java.io.File;
import java.io.FileOutputStream;
import java.io.OutputStreamWriter;
import java.nio.charset.StandardCharsets;
import java.nio.file.Files;
import java.text.SimpleDateFormat;
import java.util.ArrayList;
import java.util.Date;
import java.util.List;
import java.util.Locale;

/** Uygulama içi günlük: son 200 satır telefonda dosyada durur, ana ekranda görünür (ekran görüntüsüyle sorun aranır). */
public final class AppLog {
    private static final String TAG = "NavlunIQ";
    private static final int KEEP = 200;

    private AppLog() {}

    public static synchronized void add(Context c, String line) {
        Log.i(TAG, line);
        try {
            File f = file(c);
            List<String> lines = read(c);
            lines.add(new SimpleDateFormat("dd.MM HH:mm:ss", Locale.getDefault()).format(new Date()) + "  " + line);
            while (lines.size() > KEEP) {
                lines.remove(0);
            }
            OutputStreamWriter w = new OutputStreamWriter(new FileOutputStream(f, false), StandardCharsets.UTF_8);
            for (String l : lines) {
                w.write(l);
                w.write('\n');
            }
            w.close();
        } catch (Exception e) {
            Log.w(TAG, "günlük yazılamadı: " + e);
        }
    }

    public static synchronized List<String> read(Context c) {
        List<String> out = new ArrayList<String>();
        try {
            File f = file(c);
            if (f.exists()) {
                for (String l : Files.readAllLines(f.toPath(), StandardCharsets.UTF_8)) {
                    if (!l.isEmpty()) {
                        out.add(l);
                    }
                }
            }
        } catch (Exception e) {
            Log.w(TAG, "günlük okunamadı: " + e);
        }
        return out;
    }

    private static File file(Context c) {
        return new File(c.getFilesDir(), "gunluk.txt");
    }
}
