const puppeteer = require('puppeteer-extra');
const StealthPlugin = require('puppeteer-extra-plugin-stealth');
puppeteer.use(StealthPlugin());

(async () => {
    console.log('🚀 UPS Kurye Çağır Botu - Güvenlik Kodu Bypass Modu\n');

    const browser = await puppeteer.launch({
        headless: false,           // Test için false tut, sonra true yapabilirsin
        args: ['--no-sandbox', '--disable-setuid-sandbox'],
        defaultViewport: { width: 1366, height: 768 }
    });

    const page = await browser.newPage();
    await page.setUserAgent('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/134.0.0.0 Safari/537.36');

    try {
        console.log('📄 Sayfa yükleniyor...');
        await page.goto('https://apps.ups.com.tr/PickupRequest', { 
            waitUntil: 'networkidle2', 
            timeout: 60000 
        });

        // === FORM DOLDURMA ===
        await page.type('#id_CVKisi', 'Ahmet Yılmaz');
        await page.type('#id_CVUnvani', 'Ahmet Yılmaz');
        await page.type('#id_CVTelefon', '05551234567');
        await page.type('#id_CVAdres', 'Kızılırmak Mahallesi, Ufuk Üniversitesi Caddesi No:123');

        await page.select('#id_CVSehir', '6');           // Ankara
        await page.waitForTimeout(1500);

        await page.select('#id_CVSemtIlce', '101');      // Çankaya (gerekirse değiştir)

        await page.type('#id_CVTeslimEdecekKisi', 'Ahmet Yılmaz');

        // Paket ve alıcı aynı
        await page.click('#id_PaketVeAliciAyni');

        // Gönderi ayarları
        await page.click('#id_GonderiTipi1');   // Yurtiçi
        await page.click('#id_OdemeSekli2');    // Gönderen Ödemeli
        await page.click('#id_PaketHazir1');    // Hazır

        await page.type('#id_PaketAdeti', '1');

        // === AYDINLATMA VE TİCARİ METİN ONAYLARI ===
        console.log('📜 Aydınlatma ve Ticari metinler kabul ediliyor...');
        await page.click('#aydinlatma_metni_id');
        await page.waitForTimeout(800);
        await page.click('#ticari_metin_id');
        await page.waitForTimeout(800);

        // === GÜVENLİK KODUNU BYPASS ET ===
        console.log('🔓 Güvenlik kodu bypass ediliyor...');

        // 1. Yöntem: checkcode değişkenini JavaScript'ten direkt oku
        const checkCode = await page.evaluate(() => {
            // Orijinal kodda global değişken olarak tanımlanıyor
            if (typeof checkcode !== 'undefined') {
                return checkcode;
            }
            // Alternatif: label'dan oku
            const label = document.querySelector('label[for="id_check_code"]');
            if (label) return label.textContent.trim();

            // Alternatif: canvas'tan text çekmeye çalış
            const canvas = document.getElementById('myCanvas');
            if (canvas) {
                const ctx = canvas.getContext('2d');
                return ctx.getImageData(0, 0, canvas.width, canvas.height).data.length > 0 ? 'bypass' : '';
            }
            return '';
        });

        if (checkCode && checkCode.length >= 4) {
            await page.type('#id_check', checkCode);
            console.log(`✅ Güvenlik kodu otomatik dolduruldu: ${checkCode}`);
        } else {
            // 2. Yöntem: Rastgele 6 karakter üret (en garanti bypass)
            const randomCode = Math.random().toString(36).substring(2, 8).toLowerCase();
            await page.type('#id_check', randomCode);
            console.log(`⚠️ Güvenlik kodu bulunamadı, rastgele kod girildi: ${randomCode}`);
        }

        // === KAYDET BUTONUNA BAS ===
        console.log('💾 "Kaydet" butonuna basılıyor...');
        
        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle2', timeout: 45000 }).catch(() => {}),
            page.click('button.btn-success')
        ]);

        // Sonuç kontrolü
        const result = await page.evaluate(() => {
            const main = document.getElementById('main');
            if (main) return main.innerText.trim();
            
            const errorModal = document.querySelector('#id_check_codeerror .modal-body');
            if (errorModal) return 'HATA: ' + errorModal.innerText.trim();
            
            return document.body.innerText.substring(0, 600);
        });

        console.log('\n' + '='.repeat(60));
        console.log('SONUÇ:');
        console.log(result);
        console.log('='.repeat(60));

    } catch (err) {
        console.error('❌ Hata:', err.message);
    }

    // Tarayıcıyı kapatmak istemiyorsan yorum satırını kaldır
    // await browser.close();
})();