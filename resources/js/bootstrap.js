// Axios Kütüphanesini yüklüyoruz ve global penceremize (window) tanımlıyoruz.
import axios from 'axios';
window.axios = axios;

// İsteklerimizin standart AJAX isteği olduğunu bildiren başlık ayarı
window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';
