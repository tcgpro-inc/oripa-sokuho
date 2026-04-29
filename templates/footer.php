<!-- フッター -->
<div class="footer">
    <div class="footer-about">
        運営：オリパ速報編集部 ｜ TCGオリパの最新情報を速報配信するニュースサイトです。<br>
        お問い合わせ：info@oripanews.com
    </div>
    <div class="footer-text">
        オリパ速報 &copy; 2025-<?= date('Y') ?> ━━ TCGオリパまとめ速報
    </div>
    <div class="footer-decoration">
        ━━━━━━━━━━━━━━━━━━━━━━━━━━
    </div>
</div>

<script>
// X(Twitter) widget を lazy-load: tweet要素が画面に近づいたときだけ widgets.js を読み込む。
// embedしない記事ページや tweet が下部にしかない場合に 270KB+ の初期ロードを節約。
(function () {
    var tweets = document.querySelectorAll('blockquote.twitter-tweet');
    if (!tweets.length) return;

    var loaded = false;
    function loadWidgets() {
        if (loaded) return;
        loaded = true;
        var s = document.createElement('script');
        s.src = 'https://platform.twitter.com/widgets.js';
        s.async = true;
        s.charset = 'utf-8';
        document.body.appendChild(s);
        // widgets.js が読み込まれると自動で .twitter-tweet を iframe にレンダリングする。
    }

    if ('IntersectionObserver' in window) {
        var observer = new IntersectionObserver(function (entries) {
            for (var i = 0; i < entries.length; i++) {
                if (entries[i].isIntersecting) {
                    loadWidgets();
                    observer.disconnect();
                    return;
                }
            }
        }, { rootMargin: '300px' });
        tweets.forEach(function (t) { observer.observe(t); });
    } else {
        // 旧ブラウザは即時ロード
        loadWidgets();
    }
})();
</script>
</body>
</html>
