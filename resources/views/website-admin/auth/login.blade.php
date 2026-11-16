<!doctype html>
<html lang=zh-CN>
<head>
    <meta charset=utf-8>
    <meta name='viewport' content='width=device-width, initial-scale=1'>
    <title>网站管理登录</title>
    <link rel='stylesheet' href='/assets/css/website-admin.css?v={{ filemtime(public_path('assets/css/website-admin.css')) }}'>
    <link rel='stylesheet' href='/assets/css/website-admin-extra.css?v={{ filemtime(public_path('assets/css/website-admin-extra.css')) }}'>
    <link rel='stylesheet' href='/assets/css/website-admin-reference.css?v={{ filemtime(public_path('assets/css/website-admin-reference.css')) }}'>
</head>
<body class='wa-login-page'>
    <main class='wa-login-shell'>
        <section class='wa-login-aside'>
            <div class='wa-login-brand'><span class='wa-brand-mark'>站</span><span>企业网站管理</span></div>
            <div class='wa-login-message'><h1>管理网站内容，<br>维护企业形象。</h1><p>文章、产品、页面与网站设置集中管理。</p></div>
            <div class='wa-login-foot'><span>站点隔离</span><span>搜索友好</span><span>角色权限</span></div>
        </section>
        <section class='wa-login-main'><form class='wa-login-form' method='POST' action='{{ route('website-admin.login.attempt') }}'>
            @csrf
            <h2>登录网站后台</h2><p>{{ $site->custom_domain ?: $site->domain }}</p>
            @if ($errors->has('login'))<div class='wa-alert wa-alert-danger' role='alert'>{{ $errors->first('login') }}</div>@endif
            <label class='wa-field'><span>账号</span><input name='username' value='{{ old('username') }}' autocomplete='username' required></label>
            <label class='wa-field'><span>密码</span><input type='password' name='password' autocomplete='current-password' required></label>
            <button class='wa-button wa-button-primary wa-login-submit' type='submit'>登录</button>
            <p class='wa-login-note'>站点主账号使用主控制台账号登录；其他管理员由主账号创建。</p>
        </form></section>
    </main>
</body>
</html>
