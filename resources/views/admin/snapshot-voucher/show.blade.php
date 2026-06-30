<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>{{ $voucher ? $voucher['question'].' - 快照凭证' : '收录词不存在 - 快照凭证' }}</title>
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            color: #111827;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", "PingFang SC", "Microsoft YaHei", sans-serif;
            background:
                radial-gradient(circle at 12% 6%, rgba(94, 129, 255, .12), transparent 30%),
                linear-gradient(135deg, #e8efff 0%, #f3e8ff 100%);
        }
        .brand {
            position: fixed;
            top: 16px;
            left: 16px;
            z-index: 2;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            font-size: 16px;
            font-weight: 700;
            color: #020617;
        }
        .brand-icon {
            display: grid;
            width: 24px;
            height: 24px;
            place-items: center;
            overflow: hidden;
            border-radius: 7px;
            background: #ffffff;
            box-shadow: 0 8px 18px rgba(79, 70, 229, .14);
        }
        .brand-icon img { width: 100%; height: 100%; object-fit: contain; }
        .brand-icon span { font-size: 13px; color: #2563eb; }
        .shell {
            width: min(800px, calc(100% - 32px));
            margin: 105px auto 0;
            padding-bottom: 84px;
        }
        .card {
            min-height: calc(100vh - 105px);
            border-radius: 20px 20px 0 0;
            background: #ffffff;
            box-shadow: 0 24px 70px rgba(30, 41, 59, .08);
            padding: 22px 20px 120px;
        }
        .card-header {
            padding: 0 0 12px;
            border-bottom: 1px solid #e5e7eb;
        }
        h1 {
            margin: 0;
            font-size: clamp(22px, 3vw, 28px);
            line-height: 1.35;
            letter-spacing: 0;
        }
        .meta {
            margin-top: 10px;
            color: #8a94a6;
            font-size: 12px;
        }
        .conversation {
            padding: 20px 14px 0;
        }
        .question-bubble {
            display: flex;
            justify-content: flex-end;
            margin-bottom: 28px;
        }
        .question-bubble span {
            max-width: min(520px, 88%);
            border-radius: 10px;
            background: #f3f4f6;
            color: #111827;
            padding: 10px 12px;
            font-size: 14px;
            line-height: 1.6;
            word-break: break-word;
        }
        .answer {
            color: #111827;
            font-size: 14px;
            line-height: 1.85;
            word-break: break-word;
        }
        .answer :where(p, ul, ol, blockquote, pre, .article-table-wrap) { margin: 0 0 14px; }
        .answer :where(h2, h3, h4) { margin: 24px 0 10px; line-height: 1.45; }
        .answer h2 { font-size: 20px; }
        .answer h3 { font-size: 17px; }
        .answer ul, .answer ol { padding-left: 1.45em; }
        .answer li { margin: 4px 0; }
        .answer strong { font-weight: 800; }
        .answer mark {
            border-radius: 3px;
            background: #fff36d;
            color: #111827;
            padding: 0 2px;
        }
        .answer blockquote {
            border-left: 4px solid #dbe4ff;
            padding: 8px 14px;
            color: #475569;
            background: #f8fafc;
        }
        .answer code {
            border-radius: 4px;
            background: #f3f4f6;
            padding: 2px 4px;
            font-size: .92em;
        }
        .answer pre {
            overflow-x: auto;
            border-radius: 10px;
            background: #0f172a;
            color: #f8fafc;
            padding: 14px;
        }
        .answer pre code {
            background: transparent;
            color: inherit;
            padding: 0;
        }
        .article-table-wrap {
            overflow-x: auto;
        }
        .article-table {
            width: 100%;
            min-width: 640px;
            border-collapse: collapse;
            font-size: 14px;
        }
        .article-table th,
        .article-table td {
            border: 1px solid #e5e7eb;
            padding: 10px 14px;
            text-align: left;
            vertical-align: top;
        }
        .article-table th {
            background: #f8fafc;
            font-weight: 700;
        }
        .sources {
            margin-top: 22px;
            border-top: 1px solid #edf0f5;
            padding-top: 16px;
        }
        .sources-title {
            margin: 0 0 10px;
            color: #475569;
            font-size: 13px;
            font-weight: 700;
        }
        .source-list {
            display: grid;
            gap: 8px;
            margin: 0;
            padding: 0;
            list-style: none;
        }
        .source-list a,
        .source-list span {
            display: block;
            border-radius: 8px;
            background: #f8fafc;
            color: #334155;
            padding: 10px 12px;
            text-decoration: none;
            font-size: 13px;
            line-height: 1.5;
        }
        .source-list small {
            display: block;
            margin-top: 3px;
            color: #8a94a6;
        }
        .continue-bar {
            position: fixed;
            left: 0;
            right: 0;
            bottom: 28px;
            z-index: 3;
            display: flex;
            justify-content: center;
            pointer-events: none;
        }
        .continue-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            min-height: 38px;
            border-radius: 999px;
            background: #1d5cff;
            color: #ffffff;
            padding: 9px 18px;
            box-shadow: 0 14px 30px rgba(29, 92, 255, .32);
            font-size: 14px;
            font-weight: 700;
            text-decoration: none;
            pointer-events: auto;
        }
        .continue-btn svg { width: 16px; height: 16px; }
        .empty-card {
            min-height: 360px;
            display: grid;
            place-items: center;
            text-align: center;
        }
        .empty-title {
            margin: 0;
            font-size: 26px;
            font-weight: 800;
        }
        .empty-text {
            margin: 10px 0 0;
            color: #64748b;
            font-size: 14px;
        }
        @media (max-width: 720px) {
            .brand {
                position: static;
                padding: 14px 16px 0;
            }
            .shell {
                width: 100%;
                margin-top: 18px;
            }
            .card {
                min-height: calc(100vh - 56px);
                border-radius: 18px 18px 0 0;
                padding: 20px 16px 112px;
            }
            .conversation {
                padding: 18px 0 0;
            }
            .continue-bar {
                bottom: 18px;
            }
        }
    </style>
</head>
<body>
@if ($voucher)
    <div class="brand" aria-label="{{ $voucher['platform'] }}">
        <span class="brand-icon">
            @if ($voucher['platform_icon'] !== '')
                <img src="{{ $voucher['platform_icon'] }}" alt="">
            @else
                <span>{{ mb_substr((string) $voucher['platform'], 0, 1) }}</span>
            @endif
        </span>
        <span>{{ $voucher['platform'] }}</span>
    </div>

    <main class="shell">
        <section class="card" aria-label="快照凭证">
            <header class="card-header">
                <h1>{{ $voucher['question'] }}</h1>
                <div class="meta">{{ $voucher['time'] }}　内容由 Ai 生成，不能完全保障真实</div>
            </header>

            <div class="conversation">
                <div class="question-bubble">
                    <span>{{ $voucher['question'] }}</span>
                </div>

                <article class="answer">
                    {!! $voucher['answer_html'] !!}
                </article>

                @if (! empty($voucher['sources']))
                    <aside class="sources" aria-label="引用资料">
                        <p class="sources-title">引用资料</p>
                        <ul class="source-list">
                            @foreach ($voucher['sources'] as $source)
                                <li>
                                    @if ($source['url'] !== '')
                                        <a href="{{ $source['url'] }}" target="_blank" rel="noopener noreferrer">
                                            {{ $source['title'] !== '' ? $source['title'] : $source['url'] }}
                                            @if ($source['domain'] !== '')
                                                <small>{{ $source['domain'] }}</small>
                                            @endif
                                        </a>
                                    @else
                                        <span>
                                            {{ $source['title'] !== '' ? $source['title'] : '引用资料' }}
                                            @if ($source['domain'] !== '')
                                                <small>{{ $source['domain'] }}</small>
                                            @endif
                                        </span>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    </aside>
                @endif
            </div>
        </section>
    </main>

    @if ($voucher['platform_url'] !== '')
        <div class="continue-bar">
            <a class="continue-btn" href="{{ $voucher['platform_url'] }}" target="_blank" rel="noopener noreferrer">
                <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                    <path d="M8.75 12.25h5.5M8.75 8.75h3.5M6.8 18.25l-3.05 1V5.75A2.75 2.75 0 0 1 6.5 3h11A2.75 2.75 0 0 1 20.25 5.75v9.75a2.75 2.75 0 0 1-2.75 2.75H6.8Z" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
                和{{ $voucher['platform'] }}继续聊
            </a>
        </div>
    @endif
@else
    <main class="shell">
        <section class="card empty-card" aria-label="快照凭证不存在">
            <div>
                <h1 class="empty-title">收录词不存在</h1>
                <p class="empty-text">请检查快照凭证链接是否正确。</p>
            </div>
        </section>
    </main>
@endif
</body>
</html>
