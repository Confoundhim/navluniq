@include('errors.layout', ['code' => 429, 'title' => 'Çok fazla istek', 'message' => 'Kısa sürede çok fazla deneme yaptınız. Bir dakika bekleyip yeniden deneyin.', 'refresh' => 60])
