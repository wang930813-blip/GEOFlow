<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <meta name="theme-color" content="#071a13">
    <title>正在进入监测中心</title>
    <style>
        :root { color-scheme: light; font-family: Inter, "Microsoft YaHei", sans-serif; color: #17221d; background: #f4f7f5; }
        * { box-sizing: border-box; }
        body { min-height: 100vh; margin: 0; display: grid; place-items: center; padding: 24px; }
        main { width: min(100%, 420px); padding: 32px; border: 1px solid #dce6df; border-radius: 8px; background: #fff; box-shadow: 0 16px 48px rgb(16 45 30 / 8%); text-align: center; }
        .mark { width: 36px; height: 36px; margin: 0 auto 18px; border: 3px solid #dbe8df; border-top-color: #16834b; border-radius: 50%; animation: spin .8s linear infinite; }
        h1 { margin: 0; font-size: 18px; line-height: 1.5; font-weight: 650; }
        p { margin: 9px 0 0; color: #65736a; font-size: 14px; line-height: 1.7; }
        a { display: none; margin-top: 20px; color: #147a46; font-size: 14px; font-weight: 600; text-decoration: none; }
        a:hover { text-decoration: underline; }
        @keyframes spin { to { transform: rotate(360deg); } }
        @media (prefers-reduced-motion: reduce) { .mark { animation-duration: 2.4s; } }
    </style>
</head>
<body>
    <main>
        <div class="mark" id="loading-mark" aria-hidden="true"></div>
        <h1 id="status-title">正在进入监测中心</h1>
        <p id="status-message">正在建立客户账号登录状态</p>
        <a id="fallback-link" href="/monitor/login/">返回监测中心登录</a>
    </main>
    <script>
        (() => {
            const tokenKey = 'ceying-monitor-token';
            const code = @json($code);
            const title = document.getElementById('status-title');
            const message = document.getElementById('status-message');
            const fallback = document.getElementById('fallback-link');
            const mark = document.getElementById('loading-mark');

            localStorage.removeItem(tokenKey);

            fetch('/api/v1/monitor/auth/sso/exchange', {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({ code }),
                credentials: 'same-origin',
                cache: 'no-store'
            })
                .then(async (response) => {
                    const payload = await response.json();
                    if (!response.ok || payload.success !== true || typeof payload.data?.token !== 'string') {
                        throw new Error(payload.error?.message || '自动登录失败，请从后台重新进入');
                    }

                    localStorage.setItem(tokenKey, payload.data.token);
                    window.location.replace('/monitor/');
                })
                .catch((error) => {
                    mark.style.display = 'none';
                    title.textContent = '无法进入监测中心';
                    message.textContent = error.message || '登录状态建立失败，请从后台重新进入';
                    fallback.style.display = 'inline-block';
                });
        })();
    </script>
</body>
</html>
