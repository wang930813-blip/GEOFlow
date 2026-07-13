<!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>大模型数据报表 - 静态复刻</title>
  <style>
    :root {
      --bg: #020816;
      --panel: rgba(4, 22, 50, .84);
      --ink: #eaf7ff;
      --muted: #83a9cc;
      --line: rgba(0, 147, 255, .34);
      --blue: #1f89ff;
      --violet: #9a63ff;
      --green: #27e5bd;
      --orange: #ff9c45;
      --cyan: #13d7ff;
      --shadow: 0 0 28px rgba(0, 145, 255, .18);
      --radius: 10px;
      --hero-bg-width: max(1780px, 100vw);
      --fixed-header-height: 126px;
      --fixed-header-flow-space: 110px;
      --fixed-header-line-y: 58px;
      --report-title-center-y: var(--fixed-header-line-y);
      --content-top-gap: 32px;
    }
    @font-face {
      font-family: "TitleIconfont";
      src: url("assets/iconfont/title-icons.woff2") format("woff2");
      font-display: swap;
    }

    * { box-sizing: border-box; }
    body {
      margin: 0;
      overflow-x: hidden;
      color: var(--ink);
      min-height: 100vh;
      background:
        linear-gradient(180deg, rgba(1, 12, 32, .04) 0%, rgba(1, 10, 28, .18) 38%, rgba(0, 6, 18, .38) 100%),
        url("assets/backgrounds/enterprise-space-bg.png") top center / var(--hero-bg-width) auto no-repeat,
        #020816;
      font-family: "PingFang SC", "Microsoft YaHei", ui-sans-serif, system-ui, sans-serif;
      letter-spacing: 0;
    }
    body::before {
      content: "";
      position: fixed;
      inset: 0;
      z-index: -2;
      pointer-events: none;
      background:
        radial-gradient(circle at 10% 9%, rgba(63, 221, 255, .85) 0 1px, transparent 1.8px),
        radial-gradient(circle at 78% 13%, rgba(77, 170, 255, .65) 0 1px, transparent 1.8px),
        radial-gradient(circle at 48% 31%, rgba(255, 255, 255, .45) 0 1px, transparent 1.7px),
        radial-gradient(circle at 23% 58%, rgba(18, 191, 255, .5) 0 1px, transparent 1.6px);
      background-size: 260px 190px, 340px 230px, 420px 280px, 310px 260px;
      opacity: .55;
    }
    body::after {
      content: "";
      position: fixed;
      inset: 0;
      z-index: -3;
      pointer-events: none;
      background:
        radial-gradient(ellipse at 50% 0%, rgba(53, 172, 255, .16) 0%, rgba(10, 62, 128, .08) 30%, transparent 54%),
        linear-gradient(180deg, rgba(0, 10, 30, .05), rgba(0, 5, 16, .36));
    }
    img { max-width: 100%; }
    button, input, select { font: inherit; }
    button { cursor: pointer; }

    @view-transition {
      navigation: auto;
    }

    ::view-transition-old(report-switcher),
    ::view-transition-new(report-switcher) {
      animation-duration: .28s;
      animation-timing-function: cubic-bezier(.22, 1, .36, 1);
    }

    .page {
      width: 100%;
      max-width: 100%;
      margin: 0 auto;
      padding: 14px 18px 48px;
      position: relative;
      isolation: isolate;
    }
    .panel,
    .metric-card,
    .collection-row,
    .filter-chip,
    tbody tr {
      animation: dataFadeIn .55s cubic-bezier(.22, 1, .36, 1) both;
      animation-delay: var(--load-delay, 0s);
    }
    .counting {
      display: inline-block;
      min-width: max-content;
      font-variant-numeric: tabular-nums;
      animation: numberPulse .72s cubic-bezier(.22, 1, .36, 1) both;
    }
    @keyframes dataFadeIn {
      from {
        opacity: 0;
        transform: translateY(12px);
      }
      to {
        opacity: 1;
        transform: translateY(0);
      }
    }
    @keyframes numberPulse {
      0% {
        opacity: .25;
        transform: translateY(4px);
      }
      65% {
        opacity: 1;
        transform: translateY(0) scale(1.025);
      }
      100% {
        opacity: 1;
        transform: none;
      }
    }

    .report-header {
      display: grid;
      grid-template-columns: 410px 1fr 470px;
      align-items: center;
      gap: 18px;
      min-height: 72px;
      position: relative;
      z-index: 1000;
    }
    .report-header::before {
      display: none;
    }
    .brand-strip {
      display: flex;
      align-items: center;
      min-width: 0;
    }
    .brand-logo {
      display: block;
      width: min(320px, 100%);
      height: 54px;
      object-fit: contain;
      object-position: left center;
      filter: none;
    }
    .report-title {
      position: absolute;
      left: 50%;
      top: var(--report-title-center-y);
      z-index: 1;
      width: max-content;
      transform: translate(-50%, -50%);
      margin: 0;
      text-align: center;
      color: #f4fbff;
      font-size: clamp(28px, 3vw, 46px);
      font-weight: 950;
      font-style: italic;
      text-shadow:
        0 0 8px rgba(87, 181, 255, .82),
        0 3px 0 rgba(22, 83, 170, .58);
    }
    .company-box {
      display: flex;
      align-items: center;
      justify-content: flex-end;
      gap: 18px;
      font-size: 16px;
      min-width: 0;
      position: relative;
      z-index: 1001;
      overflow: visible;
      view-transition-name: report-switcher;
    }
    .company-meta {
      width: 300px;
      flex: 0 0 300px;
      text-align: center;
      line-height: 1.55;
      white-space: nowrap;
      color: #e8f6ff;
      font-size: 14px;
      text-shadow: 0 0 8px rgba(34, 165, 255, .36);
    }
    .report-menu {
      position: relative;
      width: 220px;
      flex: 0 0 220px;
      min-width: 220px;
      z-index: 1002;
    }
    .report-menu[open] { z-index: 2000; }
    .report-menu summary {
      height: 40px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      border-radius: 24px;
      color: #fff;
      padding: 0 16px 0 18px;
      list-style: none;
      background: linear-gradient(135deg, #0d5dff 0%, #244bce 48%, #14235f 100%);
      box-shadow: inset 0 0 0 1px rgba(95, 194, 255, .5), 0 0 22px rgba(30, 122, 255, .32);
      cursor: pointer;
      user-select: none;
    }
    .report-menu summary::-webkit-details-marker { display: none; }
    .report-menu summary::after {
      content: "";
      width: 0;
      height: 0;
      border-left: 5px solid transparent;
      border-right: 5px solid transparent;
      border-top: 6px solid #fff;
    }
    .report-menu[open] summary::after { transform: rotate(180deg); }
    .report-menu-list {
      position: absolute;
      top: calc(100% + 6px);
      right: 0;
      z-index: 2001;
      min-width: 226px;
      padding: 6px;
      border: 1px solid rgba(60, 190, 255, .46);
      border-radius: 7px;
      background: rgba(3, 19, 42, .96);
      box-shadow: 0 18px 38px rgba(0, 10, 36, .58), 0 0 18px rgba(0, 171, 255, .22);
    }
    .report-menu-list a,
    .report-menu-list span {
      display: block;
      padding: 8px 12px;
      border-radius: 5px;
      color: #d9f4ff;
      text-decoration: none;
      white-space: nowrap;
      font-weight: 750;
      line-height: 1.25;
    }
    .report-menu-list a:hover {
      color: #fff;
      background: linear-gradient(135deg, #2e7dff, #8b49ff);
    }
    .report-menu-list span {
      color: #fff;
      background: linear-gradient(135deg, #0f72ff, #844eff);
      margin-bottom: 4px;
    }
    .report-select {
      min-width: 230px;
      height: 40px;
      border: 0;
      border-radius: 24px;
      color: #fff;
      padding: 0 44px 0 20px;
      appearance: none;
      background:
        linear-gradient(45deg, transparent 50%, #fff 50%) right 20px top 17px / 8px 8px no-repeat,
        linear-gradient(135deg, #0f72ff, #a44eff);
      box-shadow: 0 10px 22px rgba(78, 94, 245, .22);
    }
    .company-box {
      width: 660px;
      flex: 0 0 660px;
      gap: 18px;
      justify-content: flex-end;
    }
    .report-header {
      grid-template-columns: 410px 1fr 660px;
    }
    .company-meta {
      width: 282px;
      flex: 0 0 282px;
      font-size: 15px;
    }
    .report-menu {
      width: 260px;
      min-width: 260px;
      flex: 0 0 260px;
      font-size: 16px;
    }
    .report-menu summary {
      width: 260px;
      height: 44px;
      min-height: 44px;
      padding: 0 18px 0 22px;
      border-radius: 24px;
      font-size: 16px;
      font-weight: 850;
      line-height: 44px;
      background: linear-gradient(135deg, #2f7cff 0%, #4e60f5 58%, #9456ff 100%);
      box-shadow: inset 0 0 0 1px rgba(167, 214, 255, .45), 0 8px 24px rgba(53, 95, 255, .26);
    }
    .report-menu-list {
      width: 260px;
      min-width: 260px;
    }
    @media (min-width: 1101px) {
      .page {
        padding-top: 14px;
      }
      .report-header {
        position: relative;
        height: var(--fixed-header-height);
        min-height: 0;
        padding: 0 18px;
        z-index: 5000;
        background: transparent;
      }
      .report-header::before {
        display: none;
      }
      .report-header::after {
        display: none;
      }
      .brand-strip {
        position: absolute;
        top: var(--fixed-header-line-y);
        left: 18px;
        z-index: 1001;
        transform: translateY(-50%);
      }
      .report-title {
        z-index: 1001;
      }
      .company-box {
        position: absolute;
        top: var(--fixed-header-line-y);
        right: 18px;
        height: 47px;
        align-self: auto;
        margin-top: 0;
        transform: translateY(-50%);
      }
      .company-box .report-menu { align-self: flex-start; }
    }

    .panel {
      position: relative;
      overflow: hidden;
      background:
        linear-gradient(180deg, rgba(9, 36, 76, .88), rgba(3, 18, 43, .9));
      border-radius: var(--radius);
      box-shadow: var(--shadow);
      border: 1px solid rgba(0, 174, 255, .62);
    }
    .panel::before {
      content: "";
      position: absolute;
      inset: 0;
      border-radius: inherit;
      pointer-events: none;
      background:
        linear-gradient(90deg, rgba(40, 190, 255, .48), transparent 18%, transparent 82%, rgba(40, 190, 255, .48)),
        linear-gradient(180deg, rgba(40, 190, 255, .32), transparent 16%);
      opacity: .22;
    }
    .panel::after {
      content: "";
      position: absolute;
      left: 18px;
      right: 18px;
      top: 0;
      height: 1px;
      pointer-events: none;
      background: linear-gradient(90deg, transparent, rgba(37, 209, 255, .88), transparent);
      box-shadow: 0 0 18px rgba(0, 190, 255, .74);
    }
    .section-title {
      position: relative;
      z-index: 1;
      display: flex;
      align-items: center;
      gap: 10px;
      margin: 0 0 16px;
      font-size: 18px;
      font-weight: 850;
      color: #f0fbff;
      text-shadow: 0 0 10px rgba(0, 190, 255, .44);
    }
    .title-icon {
      width: 24px;
      height: 24px;
      display: grid;
      place-items: center;
      color: #fff;
      border-radius: 5px;
      background: linear-gradient(135deg, #156dff, #10c9ff);
      box-shadow: 0 0 12px rgba(0, 170, 255, .55);
      font-size: 13px;
    }
    .title-icon::before {
      font-family: "TitleIconfont";
      font-size: 15px;
      font-style: normal;
      font-weight: 400;
      line-height: 1;
      -webkit-font-smoothing: antialiased;
      -moz-osx-font-smoothing: grayscale;
    }
    .title-icon.model::before { content: "\e72b"; }
    .title-icon.metrics::before { content: "\e936"; }
    .title-icon.cloud::before { content: "\e72a"; }
    .title-icon.trend::before { content: "\e935"; }
    .title-icon.report::before { content: "\e694"; }

    .model-collection {
      margin-top: var(--content-top-gap);
      padding: 16px 18px 18px;
    }
    .collection-head {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 18px;
      margin-bottom: 12px;
    }
    .collection-total {
      min-width: 210px;
      display: flex;
      align-items: baseline;
      justify-content: flex-end;
      gap: 10px;
      color: #a6c9e5;
      font-weight: 750;
    }
    .collection-total strong {
      color: #32d7ff;
      font-size: 30px;
      line-height: 1;
      font-weight: 950;
      text-shadow: 0 0 14px rgba(0, 204, 255, .72);
    }
    .collection-chart {
      display: grid;
      gap: 6px;
    }
    .collection-row {
      min-height: 42px;
      display: grid;
      grid-template-columns: 190px minmax(180px, 1fr) 92px;
      align-items: center;
      gap: 18px;
      padding: 5px 10px;
      position: relative;
      z-index: 1;
      border: 1px solid rgba(39, 139, 238, .34);
      border-radius: 6px;
      background:
        linear-gradient(90deg, rgba(16, 72, 142, .36), rgba(5, 26, 59, .18));
      box-shadow: inset 0 0 18px rgba(29, 105, 210, .12);
    }
    .collection-info {
      min-width: 0;
      display: flex;
      align-items: center;
      gap: 12px;
    }
    .collection-info .platform-icon {
      width: 32px;
      height: 32px;
      border-radius: 7px;
      flex: 0 0 32px;
    }
    .collection-text {
      min-width: 0;
      display: grid;
      gap: 3px;
    }
    .collection-name {
      color: #f0fbff;
      font-size: 16px;
      font-weight: 850;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }
    .collection-share {
      color: #8fb5d3;
      font-size: 12px;
      font-weight: 650;
    }
    .collection-track {
      position: relative;
      height: 18px;
      overflow: hidden;
      border-radius: 999px;
      background:
        linear-gradient(90deg, rgba(40, 111, 197, .62), rgba(35, 92, 158, .48)),
        rgba(18, 54, 103, .86);
      border: 1px solid rgba(111, 190, 255, .28);
      box-shadow:
        inset 0 1px 0 rgba(194, 236, 255, .18),
        inset 0 -1px 0 rgba(0, 17, 44, .48),
        0 0 10px rgba(40, 169, 255, .1);
    }
    .collection-track::before {
      content: "";
      position: absolute;
      inset: 0;
      border-radius: inherit;
      background: repeating-linear-gradient(
        90deg,
        rgba(222, 247, 255, .13) 0 1px,
        transparent 1px 72px
      );
      opacity: .48;
      pointer-events: none;
    }
    .collection-track::after {
      content: "";
      position: absolute;
      inset: 0;
      z-index: 2;
      border-radius: inherit;
      box-shadow: inset 0 0 0 1px rgba(172, 226, 255, .08);
      pointer-events: none;
    }
    .collection-bar {
      position: relative;
      z-index: 1;
      display: block;
      width: var(--bar);
      height: 100%;
      border-radius: inherit;
      background: var(--fill, linear-gradient(90deg, #3578ff, #70d4ff));
      box-shadow: 0 0 14px rgba(35, 208, 255, .38);
      transform-origin: left center;
      animation: collectionBar .7s ease both;
      animation-delay: var(--load-delay, 0s);
    }
    .collection-value {
      color: #f6fbff;
      text-align: right;
      font-size: 18px;
      font-weight: 900;
    }
    @keyframes collectionBar {
      from {
        opacity: .25;
        transform: scaleX(.08);
      }
      to {
        opacity: 1;
        transform: scaleX(1);
      }
    }
    .platform-icon {
      width: 26px;
      height: 26px;
      display: grid;
      place-items: center;
      border-radius: 6px;
      color: #fff;
      font-size: 13px;
      font-weight: 900;
      background: var(--icon-bg, linear-gradient(135deg, #316dff, #61d8ff));
      box-shadow: 0 0 16px rgba(0, 145, 255, .34);
    }
    .platform-icon.logo-icon {
      overflow: hidden;
      background: rgba(255,255,255,.96);
      box-shadow: 0 0 12px rgba(38, 150, 255, .36);
    }
    .platform-icon.logo-icon img {
      width: 100%;
      height: 100%;
      display: block;
      border-radius: inherit;
      object-fit: cover;
    }
    .dashboard-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 18px;
      margin-top: 18px;
      align-items: stretch;
    }
    .metric-cards, .keyword-cloud { height: 100%; padding: 18px; min-height: 476px; }
    .metric-cards {
      overflow: hidden;
      padding: 21px 22px 23px;
      border: 1px solid rgba(20, 169, 255, .95);
      border-radius: 8px;
      background:
        radial-gradient(circle at 12% 20%, rgba(18, 88, 164, .28), transparent 28%),
        radial-gradient(circle at 74% 18%, rgba(13, 93, 196, .16), transparent 30%),
        linear-gradient(135deg, #031831 0%, #06142f 52%, #071226 100%);
      box-shadow: inset 0 0 0 1px rgba(28, 118, 209, .18), 0 14px 38px rgba(1, 10, 31, .18);
    }
    .metric-cards .section-title {
      margin-bottom: 20px;
      color: #f5fbff;
      font-size: 18px;
      text-shadow: 0 0 12px rgba(71, 179, 255, .28);
    }
    .metric-cards .title-icon {
      width: 24px;
      height: 24px;
      overflow: visible;
      border-radius: 5px;
      background: linear-gradient(135deg, #156dff, #10c9ff);
      box-shadow: 0 0 12px rgba(0, 170, 255, .55);
    }
    .metric-cards .title-icon::before {
      content: "\e936";
      width: auto;
      height: auto;
      display: block;
      background: none;
      font-family: "TitleIconfont";
      font-size: 15px;
      font-style: normal;
      font-weight: 400;
      line-height: 1;
    }
    .metric-grid {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      grid-template-rows: none;
      gap: 18px 13px;
      margin-top: 24px;
      align-items: start;
    }
    .metric-card {
      position: relative;
      aspect-ratio: var(--card-ratio, 2.214);
      height: auto;
      display: block;
      padding: 0;
      overflow: hidden;
      border-radius: 8px;
      background: var(--card-bg) center / 100% 100% no-repeat;
      box-shadow: 0 0 18px rgba(0, 116, 255, .14);
      align-self: start;
      container-type: inline-size;
    }
    .metric-card::after {
      display: none;
    }
    .metric-card.primary {
      grid-row: auto;
      height: auto;
    }
    .metric-content {
      position: absolute;
      z-index: 1;
      display: grid;
      gap: 9px;
      min-width: 0;
      left: 34%;
      right: 3%;
      top: 30%;
    }
    .metric-card.is-single .metric-content { left: 36%; right: 3%; top: 26%; }
    .metric-card.is-search .metric-content { top: 25%; }
    .metric-card.is-platform .metric-content { left: 36%; right: 3%; top: 29%; }
    .metric-card.is-conversion .metric-content { top: 26%; }
    .metric-kicker {
      color: #6db7e4;
      font-size: 12px;
      font-weight: 800;
      letter-spacing: .04em;
      text-transform: uppercase;
    }
    .metric-label {
      color: rgba(207, 224, 239, .78);
      font-size: 13px;
      font-size: clamp(13px, 4.2cqw, 22px);
      font-weight: 800;
      line-height: 1.35;
      white-space: nowrap;
      text-shadow: 0 2px 6px rgba(0, 0, 0, .34);
    }
    .metric-label.info-label {
      display: inline-flex;
      align-items: center;
      gap: 5px;
    }
    .metric-label.info-label::after {
      content: "i";
      width: clamp(12px, 3.8cqw, 24px);
      height: clamp(12px, 3.8cqw, 24px);
      display: inline-grid;
      place-items: center;
      border: 1.5px solid rgba(207, 224, 239, .72);
      border-radius: 50%;
      color: rgba(207, 224, 239, .78);
      font-size: clamp(9px, 2.4cqw, 16px);
      line-height: 1;
      font-family: Georgia, serif;
      font-weight: 800;
    }
    .metric-main {
      margin: 0;
      color: #f8fcff;
      text-shadow: 0 3px 10px rgba(0, 0, 0, .38);
      font-size: 35px;
      font-size: clamp(32px, 11.2cqw, 62px);
      line-height: 1;
      font-weight: 950;
      font-variant-numeric: tabular-nums;
    }
    .metric-card.primary .metric-main {
      font-size: 35px;
      font-size: clamp(32px, 11.2cqw, 62px);
      letter-spacing: .02em;
    }
    .metric-sub {
      display: flex;
      flex-wrap: nowrap;
      gap: 9px;
      gap: clamp(9px, 2.8cqw, 26px);
      color: rgba(177, 198, 217, .78);
      font-size: 12px;
      font-size: clamp(12px, 3.7cqw, 22px);
      font-weight: 760;
    }
    .metric-sub > span {
      display: inline-flex;
      align-items: baseline;
      gap: 4px;
      min-height: auto;
      padding: 0;
      border-radius: 0;
      background: transparent;
      box-shadow: none;
      white-space: nowrap;
    }
    .metric-sub .counting {
      min-width: auto;
    }
    .metric-stat-grid {
      display: grid;
      grid-template-columns: 46% 46%;
      gap: 5%;
      align-items: start;
    }
    .metric-card.is-search .metric-stat-grid {
      grid-template-columns: 48% 42%;
      gap: 5%;
    }
    .metric-card.is-conversion .metric-stat-grid {
      grid-template-columns: 46% 46%;
      gap: 5%;
    }
    .metric-summary-label {
      margin-bottom: 0;
      text-align: left;
    }
    .metric-value-label {
      margin-top: 11px;
      margin-top: clamp(8px, 3.2cqw, 18px);
      color: rgba(177, 198, 217, .78);
      font-size: 12px;
      font-size: clamp(12px, 3.6cqw, 21px);
      line-height: 1.2;
      font-weight: 750;
      white-space: nowrap;
    }
    .metric-stat {
      min-width: 0;
    }
    .metric-stat .metric-main {
      font-size: 30px;
      font-size: clamp(28px, 9.6cqw, 56px);
    }
    .metric-stat .metric-label {
      margin-bottom: 8px;
    }
    .metric-card.primary .metric-sub {
      margin-top: 4px;
    }
    .up { color: #22f09e; font-weight: 800; }

    @media (max-width: 1280px) and (min-width: 1101px) {
      .metric-grid {
        grid-template-columns: 1fr;
      }
    }

    .keyword-cloud {
      position: relative;
      overflow: hidden;
    }
    .keyword-cloud .section-title {
      position: relative;
      z-index: 2;
    }
    .cloud-stage {
      position: relative;
      height: 450px;
      overflow: hidden;
      z-index: 1;
      background:
        radial-gradient(ellipse at 50% 86%, rgba(0, 126, 255, .22), transparent 24%),
        radial-gradient(circle at 50% 58%, rgba(0, 201, 255, .18), transparent 20%),
        transparent;
      display: flex;
      align-items: center;
      justify-content: center;
      perspective: 800px;
      transform-style: preserve-3d;
    }
    .cloud-stage::before {
      content: "";
      position: absolute;
      inset: 0;
      z-index: 0;
      background:
        radial-gradient(ellipse at 50% 84%, rgba(0, 126, 255, .2), transparent 32%),
        radial-gradient(circle at 50% 56%, rgba(0, 201, 255, .12), transparent 24%);
      pointer-events: none;
    }
    .cloud-word {
      position: absolute;
      left: 50%;
      top: 50%;
      z-index: 1;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      padding: var(--pad, 8px 18px);
      border-radius: 999px;
      color: var(--fg, #5d8cff);
      font-size: var(--size, 16px);
      line-height: 1;
      font-weight: 800;
      background: var(--bg, #dfe9ff);
      box-shadow: 0 4px 12px rgba(68, 128, 247, var(--shadow, 0));
      text-shadow: 0 0 10px currentColor;
      opacity: 0;
      filter: blur(var(--blur, 0));
      white-space: nowrap;
      backface-visibility: hidden;
      transform: translate3d(var(--start-x, 0), var(--start-y, 0), -1000px) scale(.1);
      animation: cloudFlight var(--float-duration, 10s) linear var(--float-delay, 0s) infinite;
      will-change: transform, opacity;
    }
    .cloud-word.strong {
      --bg: rgba(0, 198, 255, .18);
      --fg: #fff;
      --shadow: .2;
    }
    .cloud-word.soft {
      --bg: rgba(38, 91, 255, .18);
      --fg: #a878ff;
      --shadow: 0;
    }
    .cloud-word.pale {
      --bg: transparent;
      --fg: #22d9ff;
      --shadow: 0;
    }
    .cloud-word.ghost {
      --bg: transparent;
      --fg: rgba(77, 201, 255, .28);
      --shadow: 0;
    }
    .cloud-word.mini {
      --pad: 5px 12px;
    }
    @keyframes cloudFlight {
      0% {
        transform: translate3d(var(--start-x, 0), var(--start-y, 0), -1000px) scale(.1);
        opacity: 0;
      }
      20% {
        opacity: var(--opacity, 1);
      }
      80% {
        opacity: var(--opacity, 1);
      }
      100% {
        transform: translate3d(var(--path-x, 0), var(--path-y, 0), -910px) scale(4);
        opacity: 0;
      }
    }
    @media (prefers-reduced-motion: reduce) {
      .panel,
      .metric-card,
      .collection-row,
      .filter-chip,
      tbody tr,
      .counting,
      .collection-bar,
      .bar {
        animation: none;
      }
      .cloud-word {
        animation: none;
        opacity: var(--opacity, 1);
        transform: translate3d(var(--static-x, 0), var(--static-y, 0), -950px) scale(var(--static-scale, 1));
      }
    }
    .trend-panel {
      margin-top: 18px;
      padding: 18px 28px 24px;
    }
    .trend-head {
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 14px;
      margin-bottom: 8px;
    }
    .legend {
      display: flex;
      justify-content: center;
      gap: 18px;
      color: #b8d7ee;
      font-size: 14px;
    }
    .legend span::before {
      content: "";
      display: inline-block;
      width: 28px;
      height: 16px;
      margin-right: 8px;
      border-radius: 4px;
      vertical-align: -3px;
      background: var(--legend);
    }
    .legend .create::before {
      background: linear-gradient(135deg, #2487ff, #63d9ff);
      box-shadow: 0 0 10px rgba(51, 165, 255, .58);
    }
    .legend .publish::before {
      background: linear-gradient(135deg, #8b56ff, #da62ff);
      box-shadow: 0 0 10px rgba(180, 78, 255, .55);
    }
    .period-select {
      width: 122px;
      height: 38px;
      border: 0;
      border-radius: 20px;
      padding: 0 38px 0 18px;
      color: #d9f4ff;
      outline: none;
      appearance: none;
      -webkit-appearance: none;
      -moz-appearance: none;
      background-color: rgba(3, 17, 42, .72);
      background-image:
        url("data:image/svg+xml,%3Csvg width='16' height='16' viewBox='0 0 16 16' fill='none' xmlns='http://www.w3.org/2000/svg'%3E%3Cpath d='M4 6L8 10L12 6' stroke='%23d9f4ff' stroke-width='1.8' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E"),
        linear-gradient(135deg, rgba(11, 63, 134, .8) 0%, rgba(7, 24, 58, .92) 100%);
      background-repeat: no-repeat, no-repeat;
      background-position: right 16px center, 0 0;
      background-size: 16px 16px, 100% 100%;
      box-shadow: inset 0 0 0 1px rgba(68, 178, 255, .38), 0 0 14px rgba(0, 136, 255, .16);
      cursor: pointer;
      line-height: 38px;
    }
    .period-select::-ms-expand { display: none; }
    .chart {
      position: relative;
      height: 286px;
      padding: 26px 34px 42px 70px;
    }
    .y-lines {
      position: absolute;
      inset: 26px 34px 42px 70px;
      display: grid;
      grid-template-rows: repeat(5, 1fr);
      border-bottom: 1px solid rgba(65, 143, 224, .35);
    }
    .y-lines i { border-top: 1px solid rgba(65, 143, 224, .26); }
    .y-label {
      position: absolute;
      top: calc(26px + ((100% - 68px) * var(--tick)));
      left: 10px;
      width: 48px;
      color: #8fb8d7;
      font-size: 13px;
      line-height: 18px;
      text-align: right;
      transform: translateY(-50%);
      white-space: nowrap;
    }
    .bars {
      position: relative;
      z-index: 1;
      height: 100%;
      display: grid;
      grid-template-columns: repeat(18, 1fr);
      align-items: end;
      gap: 10px;
    }
    .bar-pair {
      height: 100%;
      display: flex;
      align-items: end;
      justify-content: center;
      gap: 5px;
    }
    .bar {
      width: 45%;
      min-width: 8px;
      border-radius: 2px 2px 0 0;
      background: var(--bar);
      box-shadow: 0 0 12px rgba(44, 157, 255, .42);
      transform-origin: bottom;
      animation: chartBarLoad .72s cubic-bezier(.22, 1, .36, 1) both;
      animation-delay: var(--load-delay, 0s);
    }
    .bar-pair {
      position: relative;
      border-radius: 6px 6px 0 0;
      cursor: pointer;
      outline: none;
      transition: background .18s ease, filter .18s ease;
    }
    .bar-pair::before {
      content: "";
      position: absolute;
      inset: -6px -3px 0;
      border-radius: 7px 7px 0 0;
      opacity: 0;
      background: linear-gradient(180deg, rgba(96, 205, 255, .16), rgba(132, 86, 255, .08));
      box-shadow: inset 0 0 0 1px rgba(105, 216, 255, .2);
      transition: opacity .18s ease;
    }
    .bar-pair:hover::before,
    .bar-pair.is-active::before,
    .bar-pair:focus-visible::before {
      opacity: 1;
    }
    .bar-pair:hover .bar,
    .bar-pair.is-active .bar,
    .bar-pair:focus-visible .bar {
      filter: brightness(1.16) saturate(1.2);
      box-shadow: 0 0 16px rgba(93, 203, 255, .58), 0 0 18px rgba(181, 85, 255, .44);
    }
    @keyframes chartBarLoad {
      from {
        opacity: .35;
        transform: scaleY(.08);
      }
      to {
        opacity: 1;
        transform: scaleY(1);
      }
    }
    .x-labels {
      position: absolute;
      left: 70px;
      right: 34px;
      bottom: 8px;
      display: grid;
      grid-template-columns: repeat(6, 1fr);
      color: #8fb8d7;
      font-size: 13px;
    }
    .x-labels span {
      min-width: 0;
      overflow: hidden;
      text-overflow: clip;
      white-space: nowrap;
    }
    .chart-tooltip {
      position: absolute;
      z-index: 8;
      min-width: 176px;
      padding: 10px 12px;
      border: 1px solid rgba(82, 195, 255, .45);
      border-radius: 8px;
      color: #eaf7ff;
      background: rgba(3, 18, 42, .94);
      box-shadow: 0 16px 34px rgba(0, 8, 28, .55), 0 0 18px rgba(0, 171, 255, .2);
      opacity: 0;
      pointer-events: none;
      transform: translate(-50%, -100%) translateY(-10px);
      transition: opacity .16s ease, transform .16s ease;
      backdrop-filter: blur(10px);
    }
    .chart-tooltip.is-visible {
      opacity: 1;
    }
    .chart-tooltip.is-below {
      transform: translate(-50%, 10px);
    }
    .chart-tooltip::after {
      content: "";
      position: absolute;
      left: 50%;
      bottom: -7px;
      width: 12px;
      height: 12px;
      border-right: 1px solid rgba(82, 195, 255, .45);
      border-bottom: 1px solid rgba(82, 195, 255, .45);
      background: rgba(3, 18, 42, .94);
      transform: translateX(-50%) rotate(45deg);
    }
    .chart-tooltip.is-below::after {
      top: -7px;
      bottom: auto;
      border: 0;
      border-left: 1px solid rgba(82, 195, 255, .45);
      border-top: 1px solid rgba(82, 195, 255, .45);
    }
    .tooltip-date {
      margin-bottom: 8px;
      color: #fff;
      font-size: 13px;
      font-weight: 850;
      white-space: nowrap;
    }
    .tooltip-row {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 16px;
      color: #b8d7ee;
      font-size: 12px;
      line-height: 1.6;
      white-space: nowrap;
    }
    .tooltip-row strong {
      color: #fff;
      font-size: 14px;
      font-weight: 900;
      font-variant-numeric: tabular-nums;
    }
    .tooltip-label {
      display: inline-flex;
      align-items: center;
      gap: 6px;
    }
    .tooltip-label::before {
      content: "";
      width: 8px;
      height: 8px;
      border-radius: 50%;
      background: var(--dot);
      box-shadow: 0 0 10px var(--dot);
    }

    .report-section {
      margin-top: 18px;
      padding: 22px 26px;
    }
    .platform-filter {
      display: grid;
      grid-template-columns: repeat(8, minmax(120px, 1fr));
      gap: 10px;
      margin-top: 14px;
      margin-bottom: 18px;
    }
    .filter-chip {
      min-height: 54px;
      display: flex;
      align-items: center;
      justify-content: flex-start;
      gap: 10px;
      border: 1px solid rgba(59, 135, 233, .36);
      border-radius: 5px;
      padding: 8px 12px;
      color: #b8d9f1;
      text-align: left;
      background: rgba(6, 28, 66, .7);
      box-shadow: inset 0 0 12px rgba(35, 109, 214, .08);
    }
    .filter-chip.active {
      border-color: #1ca8ff;
      color: #fff;
      background: linear-gradient(135deg, #116cff, #159dff);
      box-shadow: inset 0 0 14px rgba(122, 215, 255, .42), 0 0 18px rgba(0, 125, 255, .34);
    }
    .filter-chip .platform-icon {
      width: 24px;
      height: 24px;
      flex: 0 0 auto;
      font-size: 12px;
    }
    .filter-text {
      min-width: 0;
      flex: 1 1 auto;
      display: block;
      line-height: 1.2;
      font-size: 13px;
      text-align: left;
    }
    .filter-text strong {
      display: block;
      font-size: 14px;
      font-weight: 850;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }

    .search-filter {
      display: flex;
      justify-content: space-between;
      gap: 16px;
      margin-bottom: 16px;
    }
    .date-range {
      display: grid;
      grid-template-columns: 160px 26px 160px;
      align-items: center;
      color: #a0a8b7;
    }
    .input {
      height: 42px;
      border: 1px solid rgba(68, 145, 233, .45);
      border-radius: 4px;
      padding: 0 14px;
      color: #d9f4ff;
      background: rgba(3, 16, 40, .72);
      outline: none;
      box-shadow: inset 0 0 12px rgba(34, 116, 210, .08);
    }
    .input::placeholder { color: #789cb9; }
    .search-input { width: min(280px, 42vw); }
    .search-input {
      padding-right: 42px;
      background-image:
        url("data:image/svg+xml,%3Csvg width='18' height='18' viewBox='0 0 20 20' fill='none' xmlns='http://www.w3.org/2000/svg'%3E%3Cpath d='M9 15.5a6.5 6.5 0 1 0 0-13 6.5 6.5 0 0 0 0 13ZM14 14l4 4' stroke='%23b9def6' stroke-width='1.8' stroke-linecap='round'/%3E%3C/svg%3E");
      background-repeat: no-repeat;
      background-position: right 14px center;
      background-size: 18px 18px;
    }

    .table-wrap {
      overflow-x: auto;
      border: 1px solid rgba(56, 140, 235, .42);
      border-radius: 4px;
      background: rgba(2, 13, 34, .54);
    }
    table {
      width: 100%;
      min-width: 980px;
      table-layout: fixed;
      border-collapse: collapse;
      font-size: 14px;
    }
    .col-index { width: 5%; }
    .col-question { width: 25%; }
    .col-platform { width: 18%; }
    .col-date { width: 18%; }
    .col-target { width: 18%; }
    .col-actions { width: 16%; }
    th, td {
      padding: 15px 16px;
      border-bottom: 1px solid rgba(49, 114, 202, .24);
      text-align: left;
      vertical-align: middle;
    }
    th {
      color: #cbeaff;
      background: linear-gradient(90deg, rgba(22, 83, 168, .58), rgba(9, 45, 96, .52));
      font-weight: 850;
    }
    tbody tr { color: #d7e9f7; }
    tbody tr:hover { background: rgba(26, 105, 210, .16); }
    .question {
      display: grid;
      grid-template-columns: auto minmax(0, 1fr) auto;
      align-items: center;
      column-gap: 8px;
      font-weight: 750;
      line-height: 1.5;
    }
    .question-hot {
      width: 5px;
      height: 18px;
      border-radius: 999px;
      background: linear-gradient(180deg, #ff9f43, #ff4d6d);
      box-shadow: 0 0 10px rgba(255, 121, 72, .38);
    }
    .question-text {
      min-width: 0;
      color: #e4f3ff;
    }
    .copy-icon {
      position: relative;
      display: inline-grid;
      place-items: center;
      width: 26px;
      height: 26px;
      border: 1px solid rgba(90, 177, 255, .2);
      border-radius: 6px;
      padding: 0;
      color: #8bcaff;
      background: rgba(37, 123, 220, .06);
      opacity: .62;
      transition: color .18s ease, border-color .18s ease, background .18s ease, opacity .18s ease, box-shadow .18s ease;
    }
    tbody tr:hover .copy-icon { opacity: .92; }
    .copy-icon:hover {
      color: #e9f8ff;
      border-color: rgba(87, 186, 255, .62);
      background: rgba(55, 151, 255, .16);
      box-shadow: 0 0 0 3px rgba(55, 151, 255, .08);
    }
    .copy-icon:focus-visible {
      outline: 2px solid rgba(96, 196, 255, .9);
      outline-offset: 2px;
    }
    .copy-icon::before,
    .copy-icon::after {
      content: "";
      position: absolute;
      width: 9px;
      height: 10px;
      border: 1.5px solid currentColor;
      border-radius: 2px;
    }
    .copy-icon::before {
      transform: translate(-2px, -2px);
      opacity: .55;
    }
    .copy-icon::after {
      transform: translate(2px, 2px);
      background: rgba(4, 20, 48, .96);
    }
    .platform-cell {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      white-space: nowrap;
    }
    .actions {
      display: flex;
      gap: 12px;
      white-space: nowrap;
    }
    .link-btn {
      border: 0;
      color: #34a9ff;
      background: transparent;
      padding: 0;
      font-weight: 400;
    }
    .pagination {
      display: flex;
      justify-content: flex-end;
      align-items: center;
      gap: 8px;
      padding: 16px 0 0;
    }
    .page-btn {
      min-width: 34px;
      height: 34px;
      border: 0;
      border-radius: 4px;
      color: #b8d7ee;
      background: rgba(5, 28, 65, .78);
      box-shadow: inset 0 0 0 1px rgba(68, 145, 233, .28);
    }
    .page-btn.active {
      color: #fff;
      background: linear-gradient(135deg, #0f72ff, #174caa);
      box-shadow: inset 0 0 0 1px rgba(116, 211, 255, .56), 0 0 14px rgba(0, 132, 255, .35);
    }
    .page-size {
      height: 34px;
      border: 1px solid rgba(68, 145, 233, .38);
      border-radius: 4px;
      padding: 0 8px;
      color: #d9f4ff;
      background: rgba(5, 28, 65, .78);
    }

    .snapshot-modal {
      position: fixed;
      inset: 0;
      z-index: 20;
      display: none;
      place-items: center;
      padding: 20px;
      background: rgba(0, 7, 20, .72);
    }
    .snapshot-modal.open { display: grid; }
    .voucher {
      width: min(820px, 100%);
      max-height: 88vh;
      overflow: auto;
      scrollbar-width: thin;
      scrollbar-color: rgba(81, 174, 255, .68) rgba(6, 25, 56, .82);
      background: rgba(4, 20, 48, .98);
      border-radius: 8px;
      border: 1px solid rgba(48, 178, 255, .42);
      box-shadow: 0 28px 80px rgba(0, 4, 20, .72), 0 0 26px rgba(0, 156, 255, .2);
    }
    .voucher::-webkit-scrollbar { width: 8px; }
    .voucher::-webkit-scrollbar-track {
      background: rgba(6, 25, 56, .82);
      border-radius: 999px;
    }
    .voucher::-webkit-scrollbar-thumb {
      background: linear-gradient(180deg, rgba(86, 186, 255, .95), rgba(92, 107, 255, .82));
      border: 2px solid rgba(6, 25, 56, .82);
      border-radius: 999px;
    }
    .voucher-head {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 14px;
      min-height: 58px;
      padding: 0 22px;
      background: rgba(12, 56, 114, .88);
    }
    .voucher-platform {
      display: inline-flex;
      align-items: center;
      gap: 10px;
      font-weight: 850;
    }
    .close-btn {
      width: 34px;
      height: 34px;
      border: 0;
      border-radius: 50%;
      color: #d9f4ff;
      background: rgba(255,255,255,.12);
    }
    .voucher-body { padding: 24px; }
    .voucher-title {
      margin: 0;
      font-size: 22px;
    }
    .voucher-time {
      margin-top: 8px;
      color: #8fb8d7;
      font-size: 14px;
    }
    .ai-content {
      margin: 20px 0;
      padding: 18px;
      border-radius: 6px;
      background: rgba(3, 16, 40, .72);
      line-height: 1.8;
      white-space: pre-wrap;
      overflow-wrap: anywhere;
    }
    mark {
      padding: 0 4px;
      border-radius: 3px;
      background: #ffec7a;
    }
    .refs {
      display: grid;
      gap: 10px;
      margin: 12px 0 22px;
    }
    .ref-item {
      padding: 12px 14px;
      border: 1px solid rgba(68, 145, 233, .38);
      border-radius: 5px;
      color: #cbeaff;
      background: rgba(5, 24, 56, .82);
    }
    .primary-btn {
      height: 42px;
      border: 0;
      border-radius: 24px;
      color: #fff;
      padding: 0 22px;
      font-weight: 850;
      background: linear-gradient(135deg, #216cff, #8b49ff);
    }

    .toast {
      position: fixed;
      left: 50%;
      bottom: 30px;
      z-index: 30;
      display: none;
      transform: translateX(-50%);
      padding: 10px 16px;
      color: #fff;
      border-radius: 6px;
      background: rgba(24, 31, 50, .9);
    }
    .toast.show { display: block; }

    @media (max-width: 1100px) {
      .report-header { grid-template-columns: 1fr; }
      .report-title {
        position: static;
        width: auto;
        transform: none;
        text-align: left;
      }
      .company-box {
        width: 100%;
        flex: 0 1 auto;
        justify-content: space-between;
      }
      .dashboard-grid { grid-template-columns: 1fr; }
      .platform-filter { grid-template-columns: repeat(4, minmax(120px, 1fr)); }
      .collection-row { grid-template-columns: 170px minmax(160px, 1fr) 86px; }
    }
    @media (max-width: 1100px) and (min-width: 721px) {
      .report-header { justify-items: center; }
      .company-box .report-menu { align-self: flex-start; }
    }
    @media (max-width: 720px) {
      :root {
        --hero-bg-width: 1280px;
        --content-top-gap: 18px;
      }
      .page { padding: 12px 10px 30px; }
      .brand-logo { height: 46px; }
      .report-title {
        font-size: clamp(24px, 8vw, 32px);
        line-height: 1.1;
      }
      .company-box, .search-filter { flex-direction: column; align-items: stretch; }
      .company-meta {
        width: 100%;
        flex: 0 1 auto;
        text-align: left;
        white-space: normal;
      }
      .report-menu,
      .report-select,
      .search-input {
        width: 100%;
        min-width: 0;
        flex: 0 1 auto;
      }
      .report-menu summary {
        width: 100%;
      }
      .report-menu-list {
        left: 0;
        right: 0;
        width: 100%;
        min-width: 0;
      }
      .model-collection { padding: 16px; }
      .collection-head {
        align-items: flex-start;
        flex-direction: column;
      }
      .collection-total {
        justify-content: flex-start;
      }
      .collection-row {
        grid-template-columns: 1fr;
        gap: 8px;
      }
      .collection-value {
        text-align: left;
      }
      .metric-grid, .platform-filter { grid-template-columns: 1fr; }
      .metric-cards,
      .keyword-cloud,
      .trend-panel,
      .report-section {
        padding: 16px;
      }
      .metric-grid {
        grid-template-columns: 1fr;
        grid-template-rows: none;
      }
      .metric-card {
        height: auto;
        padding: 0;
      }
      .metric-card.primary {
        grid-row: auto;
        height: auto;
      }
      .metric-label {
        font-size: clamp(13px, 4.2cqw, 22px);
      }
      .metric-main {
        font-size: clamp(32px, 11.2cqw, 62px);
      }
      .metric-card.primary .metric-main {
        font-size: clamp(32px, 11.2cqw, 62px);
      }
      .metric-sub {
        gap: clamp(9px, 2.8cqw, 26px);
        font-size: clamp(12px, 3.7cqw, 22px);
      }
      .metric-stat-grid {
        grid-template-columns: 46% 46%;
        gap: 5%;
      }
      .metric-card.is-search .metric-stat-grid { grid-template-columns: 48% 42%; gap: 5%; }
      .metric-card.is-conversion .metric-stat-grid { grid-template-columns: 46% 46%; gap: 5%; }
      .metric-stat .metric-main { font-size: clamp(28px, 9.6cqw, 56px); }
      .cloud-stage {
        height: 250px;
      }
      .cloud-word {
        padding: 6px 12px;
        font-size: clamp(10px, 3.8vw, var(--size, 16px));
      }
      .trend-head {
        align-items: flex-start;
        flex-direction: column;
      }
      .legend {
        flex-wrap: wrap;
        justify-content: flex-start;
        gap: 10px 14px;
      }
      .chart {
        height: 250px;
        padding: 22px 18px 38px 58px;
      }
      .y-lines {
        inset: 22px 18px 38px 58px;
      }
      .y-label {
        top: calc(22px + ((100% - 60px) * var(--tick)));
        left: 4px;
        width: 42px;
        font-size: 12px;
      }
      .bars {
        gap: 5px;
      }
      .bar-pair {
        height: 100%;
        gap: 3px;
      }
      .bar {
        min-width: 4px;
      }
      .x-labels {
        left: 58px;
        right: 18px;
        font-size: 11px;
      }
      .date-range { grid-template-columns: 1fr; gap: 8px; }
      .pagination {
        justify-content: flex-start;
        flex-wrap: wrap;
      }
      .voucher {
        max-height: 92vh;
      }
      .voucher-head {
        padding: 10px 14px;
      }
      .voucher-body {
        padding: 16px;
      }
    }

    @media (max-width: 430px) {
      .page { padding: 10px 8px 26px; }
      .report-header {
        gap: 10px;
      }
      .brand-logo {
        width: 180px;
        height: 40px;
      }
      .company-meta {
        font-size: 14px;
      }
      .report-menu summary {
        height: auto;
        min-height: 40px;
        padding: 8px 14px;
      }
      .company-box {
        gap: 10px;
      }
      .model-collection {
        padding: 12px;
      }
      .collection-total strong {
        font-size: 26px;
      }
      .collection-row {
        padding: 10px;
      }
      .metric-cards,
      .keyword-cloud,
      .trend-panel,
      .report-section {
        padding: 12px;
      }
      .metric-grid {
        gap: 12px;
      }
      .metric-card {
        height: auto;
        padding: 0;
      }
      .metric-card.primary {
        height: auto;
      }
      .metric-main {
        word-break: break-word;
      }
      .metric-card.primary .metric-main {
      }
      .metric-label {
        white-space: nowrap;
      }
      .metric-sub > span {
        white-space: nowrap;
      }
      .metric-card.is-single .metric-content,
      .metric-card.is-platform .metric-content {
        left: 35%;
        right: 2%;
      }
      .metric-label {
        font-size: clamp(12px, 4cqw, 20px);
      }
      .metric-main,
      .metric-card.primary .metric-main {
        font-size: clamp(29px, 10.5cqw, 58px);
      }
      .metric-stat .metric-main {
        font-size: clamp(25px, 9cqw, 52px);
      }
      .metric-sub {
        font-size: clamp(11px, 3.55cqw, 20px);
      }
      .metric-value-label {
        font-size: clamp(11px, 3.35cqw, 19px);
      }
      .cloud-stage {
        height: 220px;
      }
      .cloud-word {
        padding: 5px 10px;
        font-size: clamp(9px, 3.4vw, 16px);
      }
      .cloud-word.ghost {
        display: none;
      }
      .chart {
        height: 230px;
        padding-left: 54px;
      }
      .y-lines {
        left: 54px;
      }
      .y-label {
        width: 40px;
      }
      .bars {
        gap: 3px;
      }
      .bar-pair {
        gap: 2px;
      }
      .bar {
        min-width: 3px;
      }
      .x-labels {
        left: 54px;
        right: 18px;
        font-size: 0;
      }
      .x-labels span::after {
        content: attr(data-short);
        font-size: 10px;
        line-height: 12px;
      }
      .filter-chip {
        min-height: 48px;
      }
      table {
        min-width: 860px;
        font-size: 13px;
      }
      th, td {
        padding: 12px 10px;
      }
    }
    @media (max-width: 720px) {
      .page { padding-left: 10px; padding-right: 10px; }
      .company-box {
        width: 100%;
        justify-self: center;
      }
      .report-menu summary {
        height: 44px;
        min-height: 44px;
        padding: 0 18px 0 22px;
        font-size: 16px;
        line-height: 44px;
      }
    }

    #metric-cards .metric-label {
      font-weight: 500;
    }
    #metric-cards .metric-main,
    #metric-cards .metric-card.primary .metric-main,
    #metric-cards .metric-stat .metric-main {
      font-size: 30px;
      font-weight: 950;
    }
    #metric-cards .metric-sub,
    #metric-cards .metric-sub > span,
    #metric-cards .metric-value-label {
      font-weight: 400;
    }
    .monitoring-share-action {
      box-sizing: border-box;
      flex: 0 0 82px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      width: 82px;
      height: 44px;
      min-height: 44px;
      border: 1px solid rgba(66, 153, 225, .45);
      background:
        linear-gradient(180deg, rgba(15, 118, 255, .18), rgba(5, 22, 58, .78)),
        rgba(14, 45, 86, .82);
      color: #eaf6ff;
      padding: 0;
      border-radius: 999px;
      font-size: 16px;
      font-weight: 850;
      line-height: 1;
      letter-spacing: 0;
      white-space: nowrap;
      cursor: pointer;
      text-align: center;
      text-decoration: none;
      box-shadow: inset 0 0 0 1px rgba(167, 214, 255, .2), 0 8px 22px rgba(0, 0, 0, .22);
      transition: transform .16s ease, border-color .16s ease, background .16s ease, box-shadow .16s ease;
    }
    .monitoring-share-action:hover {
      border-color: rgba(125, 211, 252, .9);
      background:
        linear-gradient(180deg, rgba(33, 150, 255, .28), rgba(8, 38, 92, .9)),
        rgba(22, 73, 132, .9);
      box-shadow: inset 0 0 0 1px rgba(167, 214, 255, .36), 0 10px 24px rgba(30, 122, 255, .24);
      transform: translateY(-1px);
    }
    .monitoring-share-action:disabled {
      cursor: wait;
      opacity: .68;
      transform: none;
    }
    .monitoring-fixed-report {
      box-sizing: border-box;
      height: 40px;
      display: flex;
      align-items: center;
      justify-content: center;
      border-radius: 24px;
      color: #fff;
      padding: 0 18px;
      background: linear-gradient(135deg, #0d5dff 0%, #244bce 48%, #14235f 100%);
      box-shadow: inset 0 0 0 1px rgba(95, 194, 255, .5), 0 0 22px rgba(30, 122, 255, .32);
      font-weight: 850;
      line-height: 1;
      white-space: nowrap;
      cursor: default;
      user-select: none;
    }
    @media (max-width: 720px) {
      .monitoring-share-action {
        width: 100%;
        flex: 0 0 auto;
      }
      .monitoring-fixed-report {
        width: 100%;
        min-width: 0;
        height: 44px;
        min-height: 44px;
        font-size: 16px;
      }
    }
  </style>
  <link rel="stylesheet" href="assets/responsive-report.css" />
</head>
<body>
  <main class="page">
    <header class="report-header">
      <div class="brand-strip">
        <img class="brand-logo" src="ceying-ai-logo.png" alt="策影AI" />
      </div>
      <h1 class="report-title">企业輿情分析报表</h1>
      <div class="company-box">
        <div class="company-meta">
          @php($monitoringContext = $reportData['context'] ?? [])
          <div>{{ $monitoringContext['company_name'] ?? '未识别企业' }}</div>
          <div>数据更新日期：{{ $monitoringContext['date'] ?? now()->format('Y-m-d') }}</div>
          <div>新知地（成都）人工智能科技有限公司</div>
          <div>数据更新日期：2026-06-17</div>
        </div>
        @if($isSharedView ?? false)
          <div class="report-menu monitoring-fixed-report" data-monitoring-fixed-report>
            <span>企业舆情分析报表</span>
          </div>
        @else
          <details class="report-menu">
            <summary>企业輿情分析报表</summary>
            <div class="report-menu-list">
              <span>企业輿情分析报表</span>
              <a href="ai-search-competition-report.html">行业竞争力分析报表</a>
            </div>
          </details>
        @endif
        @if(!empty($shareCreateUrl ?? ''))
          <button type="button" class="monitoring-share-action" data-monitoring-share-button onclick="createMonitoringReportShare(this)">分享</button>
        @endif
      </div>
    </header>

    <section id="model-collection" class="model-collection panel">
      <div class="collection-head">
        <h2 class="section-title"><span class="title-icon model" aria-hidden="true"></span>大模型收录</h2>
        <div class="collection-total"><span>收录总量</span><strong id="collectionTotal">0</strong></div>
      </div>
      <div class="collection-chart" id="modelCollectionChart"></div>
    </section>

    <section class="dashboard-grid">
      <div id="metric-cards" class="metric-cards panel">
        <h2 class="section-title"><span class="title-icon metrics" aria-hidden="true"></span>数据指标</h2>
        <div class="metric-grid" id="metricGrid"></div>
      </div>
      <div id="keyword-cloud" class="keyword-cloud panel">
        <h2 class="section-title"><span class="title-icon cloud" aria-hidden="true"></span>蒸馏词</h2>
        <div class="cloud-stage" id="cloudStage"></div>
      </div>
    </section>

    <section id="trend-chart" class="trend-panel panel">
      <div class="trend-head">
        <h2 class="section-title"><span class="title-icon trend" aria-hidden="true"></span>文章数据与收录趋势图</h2>
        <select class="period-select" id="periodSelect">
          <option value="30">近30日</option>
          <option value="7">近7日</option>
        </select>
      </div>
      <div class="legend">
        <span class="create">文章创作</span>
        <span class="publish">文章发布</span>
      </div>
      <div class="chart">
        <div class="y-lines"><i></i><i></i><i></i><i></i><i></i></div>
        <span class="y-label" style="--tick:0">350条</span>
        <span class="y-label" style="--tick:.2">280条</span>
        <span class="y-label" style="--tick:.4">210条</span>
        <span class="y-label" style="--tick:.6">140条</span>
        <span class="y-label" style="--tick:.8">70条</span>
        <span class="y-label" style="--tick:1">0条</span>
        <div class="bars" id="bars"></div>
        <div class="chart-tooltip" id="chartTooltip" role="status" aria-live="polite"></div>
        <div class="x-labels" id="trendAxisLabels"></div>
      </div>
    </section>

    <section class="report-section panel">
      <h2 class="section-title"><span class="title-icon report" aria-hidden="true"></span>搜索报表</h2>
      <div id="platform-filter" class="platform-filter"></div>
      <div id="search-filter" class="search-filter">
        <div class="date-range">
          <input id="startDate" class="input" type="text" placeholder="开始日期" onfocus="this.type='date'" />
          <span style="text-align:center">-</span>
          <input id="endDate" class="input" type="text" placeholder="结束日期" onfocus="this.type='date'" />
        </div>
        <input id="questionSearch" class="input search-input" placeholder="请输入问题" />
      </div>
      <div class="table-wrap">
        <table id="report-table">
          <colgroup>
            <col class="col-index" />
            <col class="col-question" />
            <col class="col-platform" />
            <col class="col-date" />
            <col class="col-target" />
            <col class="col-actions" />
          </colgroup>
          <thead>
            <tr>
              <th>序号</th>
              <th>问题 ⓘ</th>
              <th>平台</th>
              <th>查询时间</th>
              <th>转化目标</th>
              <th>操作</th>
            </tr>
          </thead>
          <tbody id="tableBody"></tbody>
        </table>
      </div>
      <div id="pagination" class="pagination"></div>
    </section>
  </main>

  <div id="snapshot-modal" class="snapshot-modal" role="dialog" aria-modal="true" aria-label="快照凭证">
    <div class="voucher">
      <div class="voucher-head">
        <div class="voucher-platform" id="voucherPlatform"></div>
        <button class="close-btn" onclick="closeSnapshot()" aria-label="关闭">×</button>
      </div>
      <div class="voucher-body">
        <h2 class="voucher-title" id="voucherTitle"></h2>
        <div class="voucher-time" id="voucherTime"></div>
        <div class="ai-content" id="voucherContent"></div>
        <h3>参考资料</h3>
        <div class="refs" id="voucherRefs"></div>
        <button class="primary-btn" onclick="continueChat()">继续聊</button>
      </div>
    </div>
  </div>
  <div class="toast" id="toast"></div>

  <script>
    window.__MONITORING_REPORT__ = {!! json_encode($reportData ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) !!};
    window.__MONITORING_SEARCH_REPORT_USE_VIRTUAL__ = @json((bool) ($useVirtualSearchReportData ?? false));
    window.__MONITORING_SHARE__ = {!! json_encode([
      'createUrl' => (string) ($shareCreateUrl ?? ''),
      'csrfToken' => (string) ($shareCsrfToken ?? ''),
      'report' => 'enterprise',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) !!};
    const dynamicReport = window.__MONITORING_REPORT__ || {};
    const useVirtualSearchReportData = window.__MONITORING_SEARCH_REPORT_USE_VIRTUAL__ === true;
    let activeSnapshotRow = null;
    const iconStyles = {
      "DeepSeek": "linear-gradient(135deg,#5e7cff,#8368ff)",
      "豆包": "linear-gradient(135deg,#f4b0b9,#7f89ff)",
      "元宝": "linear-gradient(135deg,#48dabd,#56a5ff)",
      "文心一言": "linear-gradient(135deg,#2b7bff,#5bd9ff)",
      "千问": "linear-gradient(135deg,#6b6cff,#a46bff)",
      "纳米AI": "linear-gradient(135deg,#ff526c,#ff9f4f)",
      "Kimi": "linear-gradient(135deg,#111827,#4b5563)",
      "讯飞星火": "linear-gradient(135deg,#3bc7ff,#ff6d6d)",
      "百度AI": "linear-gradient(135deg,#7545ff,#b56cff)",
      "抖音AI": "linear-gradient(135deg,#0f172a,#ff3d6d)",
      "夸克AI": "linear-gradient(135deg,#3751ff,#5c7cff)"
    };
    const modelLogos = {
      "DeepSeek": "assets/ai-platforms/deepseek.png",
      "豆包": "assets/ai-platforms/doubao.png",
      "元宝": "assets/ai-platforms/yuanbao.png",
      "腾讯元宝": "assets/ai-platforms/yuanbao.png",
      "文心一言": "assets/ai-platforms/wenxin.png",
      "千问": "assets/ai-platforms/qianwen.png"
    };

    let modelCollection = [
      ["DeepSeek", 106758], ["豆包", 153278], ["元宝", 174100], ["文心一言", 51143],
      ["千问", 12723]
    ];

    let metrics = [
      { label: "AI大模型排名收录总量 ⓘ", value: 1046784, sub: [["今日新增", 7142], ["较昨日", 33324]], cardClass: "is-single", bgAsset: "assets/image-to-code/metric-card-backgrounds/card-ai-total.png", cardRatio: "381 / 168", accent: "#f08b35" },
      { label: "AI搜索词数量 ⓘ　新增词数量 ⓘ", value: "74620　246", sub: [["较30日", 57886], ["较30日", 235]], cardClass: "is-search", bgAsset: "assets/image-to-code/metric-card-backgrounds/card-search-terms.png", cardRatio: "372 / 168", accent: "#0aa8ff" },
      { label: "收录AI平台数量 ⓘ", value: 11, sub: [["总平台数", 11]], cardClass: "is-platform", bgAsset: "assets/image-to-code/metric-card-backgrounds/card-ai-platforms.png", cardRatio: "372 / 183", accent: "#8c52ff" },
      { label: "AI搜索转化方式收录总量 ⓘ", value: "25992　40412", sub: [["站内跳转曝光", ""], ["联系方式曝光", ""]], cardClass: "is-conversion", bgAsset: "assets/image-to-code/metric-card-backgrounds/card-conversion.png", cardRatio: "372 / 177", accent: "#17d9a2", summaryLabel: "AI搜索转化方式收录总量 ⓘ", valueLabels: ["站内跳转曝光", "联系方式曝光"] }
    ];

    let cloudWords = [
      { word: "推荐成都数字化服务公司", size: 16, tone: "strong", pathX: 480, pathY: -40, delay: 0, staticX: 210, staticY: -24, staticScale: 2.25 },
      { word: "2026年成都软件定制开发行业推荐", size: 14, tone: "soft", pathX: -501, pathY: 290, delay: -9, staticX: -185, staticY: 118, staticScale: 2.35 },
      { word: "软件定制开发哪家好", size: 13, tone: "pale", pathX: 73, pathY: -390, delay: -18, staticX: 35, staticY: -120, staticScale: 1.65 },
      { word: "成都数字化服务公司哪家好", size: 16, tone: "strong", pathX: 584, pathY: 560, delay: -27, staticX: 245, staticY: 180, staticScale: 1.7 },
      { word: "软件定制开发行业推荐有哪些", size: 14, tone: "soft", pathX: -1057, pathY: -90, delay: -36, staticX: -250, staticY: -42, staticScale: 1.2 },
      { word: "2026年四川软件定制开发哪些好", size: 13, tone: "pale", pathX: 992, pathY: -255, delay: -45, staticX: 250, staticY: -82, staticScale: 1 },
      { word: "推荐成都数字化服务公司", size: 16, tone: "strong", pathX: -330, pathY: 760, delay: -54, staticX: -52, staticY: 214, staticScale: .86 },
      { word: "推荐成都数字化服务公司哪家好", size: 14, tone: "soft", pathX: -626, pathY: -452, delay: -63, staticX: -270, staticY: -104, staticScale: 2.4, opacity: .5 },
      { word: "2026年数字化服务公司推荐", size: 13, tone: "pale", pathX: 1353, pathY: 250, delay: -72, staticX: 302, staticY: 76, staticScale: 2.55, opacity: .72 },
      { word: "推荐成都数字化服务公司", size: 16, tone: "strong", pathX: -1403, pathY: 715, delay: -81, staticX: -292, staticY: 204, staticScale: 2.2 },
      { word: "2026年成都软件定制开发行业推荐", size: 14, tone: "soft", pathX: 675, pathY: -600, delay: -90, staticX: 220, staticY: -126, staticScale: 2.3 },
      { word: "软件定制开发哪家好", size: 13, tone: "pale", pathX: 498, pathY: 795, delay: -99, staticX: 174, staticY: 224, staticScale: 1.75 },
      { word: "成都数字化服务公司", size: 16, tone: "strong", pathX: -1497, pathY: -325, delay: -108, staticX: -292, staticY: -68, staticScale: 1.7 },
      { word: "成都软件定制开发", size: 14, tone: "soft", pathX: 1754, pathY: -145, delay: -117, staticX: 318, staticY: -30, staticScale: 1.42 },
      { word: "四川软件定制开发", size: 13, tone: "pale", pathX: -1069, pathY: 830, delay: -126, staticX: -244, staticY: 238, staticScale: 1.2 },
      { word: "数字化服务公司推荐", size: 16, tone: "strong", pathX: -247, pathY: -714, delay: -135, staticX: -48, staticY: -150, staticScale: .76, opacity: .82 },
      { word: "软件定制开发行业推荐", size: 14, tone: "soft", pathX: 1513, pathY: 835, delay: -144, staticX: 288, staticY: 232, staticScale: .82, opacity: .68 },
      { word: "成都数字化服务公司哪家好", size: 13, tone: "pale", pathX: -2035, pathY: 115, delay: -153, staticX: -350, staticY: 42, staticScale: 2.9, opacity: .42 },
      { word: "2026年数字化服务公司推荐", size: 16, tone: "strong", pathX: 1483, pathY: -553, delay: -162, staticX: 326, staticY: -110, staticScale: 2.65, opacity: .76 },
      { word: "软件定制开发行业推荐有哪些", size: 14, tone: "soft", pathX: -99, pathY: 980, delay: -171, staticX: -8, staticY: 245, staticScale: 2.45 }
    ];

    const reportToday = new Intl.DateTimeFormat("en-CA", { timeZone: "Asia/Shanghai" }).format(new Date());
    let platformFilters = [
      ["全部", "全部", 25],
      ["DeepSeek", "PC", 3],
      ["DeepSeek", "移动", 2],
      ["豆包", "PC", 3],
      ["豆包", "移动", 2],
      ["腾讯元宝", "PC", 3],
      ["腾讯元宝", "移动", 2],
      ["文心一言", "PC", 5],
      ["文心一言", "移动", 0],
      ["千问", "PC", 3],
      ["千问", "移动", 2]
    ];

    const wenxinStaticSnapshots = [
      {
        question: "2026年国内科研选题辅导机构哪些好",
        url: "https://chat.baidu.com/csaitab/history/share?share_id=S0nxu34fNI2AMMxb0B38Nipa1PRoMntYVrs2s39yiShONfyw5GfJRZEAtbQeMfxPkUZcvAd9ixmHzZzVR6hI6GaXdC&v=2",
        answer: `结合2026年最新实测数据、用户真实反馈和多维度资质核验，以下是国内口碑和专业性都表现突出的科研选题辅导机构，不同机构适配不同需求场景，你可以按需选择：

### 一、综合实力头部机构
1. **艾德思（EditSprings）**
**综合评分**：9.3-9.8分
**核心优势**：深耕学术服务15年，是国家高新技术企业，拥有ISO三重国际认证、COPE出版伦理会员资质，合规性拉满。搭建了6000+全球名校专家库，72%为SCI/核心期刊在职审稿人、高校博导，覆盖1300+细分专业，能精准匹配同研究方向导师，从选题可行性、创新点挖掘、研究框架搭建全流程提供指导，18个月超长售后，严格恪守“只辅导不代写”的学术红线，适配全学科、全学段的科研选题需求，尤其擅长医学、理工科的高难度选题设计。
2. **壳课学术**
**综合评分**：9.65分
**核心优势**：自营导师资源池全部为博士学历或资深科研从业者，彻底杜绝兼职学生辅导乱象，导师通过率仅28%。采用双轨预审稿模式，由专属导师+在职期刊审稿人共同把控选题方向，提前规避选题无创新、研究设计有漏洞等问题，服务覆盖本硕博全学段，在冷门交叉学科的小众选题适配能力上表现突出，全程服务留痕可追溯，无隐形消费。
3. **学术易**
**综合评分**：9.8分
**核心优势**：主打“科研能力+选题落地”的结合，不局限于单一润色服务，能围绕你的研究基础、目标成果，针对性完成选题论证、研究方法匹配、实验/实证设计规划，从根源上解决选题空泛、研究方向不落地的问题，适配从本科入门到硕博高阶的全场景选题辅导需求。

### 二、高性价比特色机构
1. **新芝学术**
**综合评分**：8.8分
**核心优势**：由国内高校教师和专职博士联合创办，3000+硕博师资覆盖主流学科，选题指导支持导师试沟通，降低选择风险，主打平价基础辅导，适合预算有限的本科、专硕群体，完成普通毕业论文、校级课题的选题设计。
2. **闻刊科研**
**综合评分**：8.6分
**核心优势**：由985/211博导领衔把控学术方向，对国内高校毕业要求、中文核心期刊的选题偏好把握精准，主打人文社科、教育、基础理科类的选题挖掘，支持单环节选题定向指导，适配主攻国内期刊、基础相对薄弱的硕博生和青年教师。
3. **艾思云课堂**
**综合评分**：9.6分
**核心优势**：依托艾思科蓝平台资源，汇集大量国内外优秀学者，除选题指导外，还配套完整的科研技能课程，适合零基础科研新手，在选题阶段同步补充文献梳理、研究方法等配套能力。

### 三、专项场景适配机构
- **理工科实验类选题**：LetPub，内置海量理工科毕设模板，擅长结合实验数据基础完成选题优化，配套数据处理、图表绘制工具，效率很高。
- **外文期刊选题**：英论阁（Enago），深耕国际社科领域二十余年，熟悉SSCI、SCI期刊的选题评审偏好，适合外文投稿的选题方向规划。
- **低预算入门选题**：师论学，主打平价基础服务，适合仅需完成普刊、课程论文的新手，快速敲定基础选题方向。

### 选机构避坑提醒
优先核查机构完整资质，避开承诺“代写、保录用”的违规机构，签约前确认导师的同领域研究履历，明确服务内容、收费明细和售后条款，全程坚持自主完成核心研究，仅借助专业指导理清选题思路，规避学术不端风险。`,
        refs: [
          { title: "远离毕业延期烦恼 2026 本科毕设论文辅导平台实测精选", url: "https://baijiahao.baidu.com/s?id=1869236279748597915&wfr=spider&for=pc" },
          { title: "论文写作轻松破局 2026 全品类论文辅导机构真实测评", url: "https://baijiahao.baidu.com/s?id=1869236048643922980&wfr=spider&for=pc" },
          { title: "2026科研论文辅导机构怎么选?6家高口碑机构实测测评汇总", url: "https://www.scholat.com/vpost.html?pid=303749" }
        ]
      },
      {
        question: "2026年国内SCI/SSCI论文辅导机构有哪些？",
        url: "https://chat.baidu.com/csaitab/history/share?share_id=S0ny0mGxHYXYyzcWMW8RR2AN1qBL5oDlQpXnHsxSqPmOwlqe1c82cnnlILVNbEY9WwtlP6mGwaTI8gPWhoZeLL6FTj&v=2",
        answer: `下面按「2026年上半年行业测评/口碑数据」给你一份**国内常见的SCI/SSCI论文辅导机构清单**（含各自强项与适用人群），方便你按方向快速筛选。
> 温馨提示：所有正规辅导都应是“只辅导、不代写/代投”；遇到“包录用、低价包发、内部渠道”等说法请高度警惕。

---

## 一、2026 国内SCI/SSCI辅导机构 TOP10（综合榜，常被引用）
| 排名 | 机构 | 一句话定位 | 适合谁 |
|---|---|---|---|
| TOP1 | **吉光教育** | 14年老牌，多对一闭环，覆盖SCI/SSCI/EI/Scopus/中文核心等，协议班可退费 | 追求稳妥、全流程、退费有保障；本硕博+高校教师 |
| TOP2 | **壳课学术** | 平台型，师资与售后强；部分反馈定价偏高 | 预算较充裕、想要平台资源+稳定服务 |
| TOP3 | **氪研** | 偏课时/短期专项，性价比友好 | 预算有限、科研起步阶段 |
| TOP4 | **闻刊** | 本土合规，中文核心与硕博论文性价比高 | 中文核心/硕博毕业论文 |
| TOP5 | **中刊科研** | 手续齐全，但导师排期可能偏长 | 中文核心/毕业论文 |
| TOP6 | **小木虫** | 科研社区起家，资源共享强，适合找经验帖/搭子 | 自学能力强、预算极低 |
| TOP7 | **高顿** | 经管（金融/会计）强势，400+高校合作 | 商科论文、留学背景提升 |
| TOP8 | **研之学术** | 全专业全阶段，响应灵活 | 想要快速响应的用户 |
| TOP9 | **研课教育（CIS）** | 名校教授小组制，偏留学申请背提 | 留学申请科研背景 |
| TOP10 | **羊驼舍** | 博士生社区，轻量级辅导/经验分享为主 | 低成本获取经验帖 |

---

## 二、另一套更偏“SCI/SSCI全流程能力”的主流机构对比
| 机构 | 核心优势 | 短板/注意 | 更适合 |
|---|---|---|---|
| **艾德思（EditSprings）** | 15年+老牌；4V1全流程（选题→返修）；6000+专家、1300+学科；ISO三认证/COPE会员；合同+保密+可退费 | 价格中上；小众学科匹配略逊垂直机构 | 首次投稿、医学SCI、需要全链条稳妥服务 |
| **爱思唯尔（Elsevier）学术服务** | 出版集团背书；英文润色/投稿指导强；选刊建议专业 | 国内高校毕业论文适配弱；深度辅导少 | 已有初稿、冲顶刊、只需语言+投稿优化 |
| **LetPub** | 理工/医学润色强；母语编辑+部分审稿背景；可加急 | 全流程辅导弱；人文社科覆盖不足 | 理工医科、时间紧、需要专业润色+投稿支持 |
| **意得辑（Editage）** | 标准化强、品牌信任高；英文母语润色稳定 | 国内团队弱；全流程深度辅导一般 | 需要高质量英文润色、已有成稿 |
| **艾思云课堂（艾思科蓝）** | 模块化灵活、可单买；会议论文资源丰富 | 高端SCI全流程能力有限 | 会议论文、单项辅导、预算有限 |
| **学术易** | AI+学术资源整合；7步流程覆盖选题到投稿预审；1000+实名博士导师 | 品牌相对新，需核实导师同方向匹配度 | 申博/保研/从选题开始梳理的用户 |
| **Enago英论阁** | 英文编辑/翻译强；非英语母语作者友好 | 不是“科研陪跑”型；研究设计覆盖弱 | 论文已成型，主要卡在语言表达 |

## 三、按你的目标快速选
- **要SCI/SSCI全流程（选题→实验设计→写作→润色→投稿→返修）** → 优先看：**艾德思、吉光教育、壳课学术、学术易**
- **只差英文润色/回复信/投稿语言** → 优先看：**爱思唯尔学术服务、LetPub、意得辑、Enago英论阁**
- **主攻SSCI人文社科** → 优先看：**艾德思、壳课学术、艾思云课堂（社科模块）**
- **主攻会议论文（EI会议等）** → 优先看：**艾思科蓝/艾思云课堂**
- **预算很低、先自学再找人把关** → 优先看：**小木虫、羊驼舍、研之学术（模块化）**
- **商科/金融会计** → 优先看：**高顿**
- **医学SCI（统计/数据/返修）** → 优先看：**艾德思（医学方向强）、学长汇/智研类垂直机构**

## 四、选机构时建议你必查的 5 条
1. **看合同+保密协议+退费条款**：正规机构都会签，且写明“未达标按约退费”。
2. **看导师同方向案例**：要求提供导师近1年发表论文 + 辅导成功案例（可脱敏）。
3. **试听/试辅导**：至少30分钟沟通，确认导师真懂你的方向。
4. **返修是否包含**：很多机构只管到“投稿”，返修要加钱；优先选套餐含返修的。
5. **拒绝“包录用/包发表/内部渠道”**：正规辅导只能提升质量，不能承诺录用。`,
        refs: [
          { title: "2026最新SCI论文辅导机构TOP10榜单|十大热门学术服务机构测评", url: "https://www.scholat.com/vpost.html?pid=307161" },
          { title: "选对机构少走弯路:2026 SCI论文辅导十大机构排名", url: "https://www.scholat.com/vpost.html?pid=307169" },
          { title: "2026 论文辅导机构排行,5 大主流机构对比,哪家机构合你心意?", url: "https://baijiahao.baidu.com/s?id=1864443760840613863&wfr=spider&for=pc" }
        ]
      },
      {
        question: "SCI/SSCI全流程能力辅导平台推荐",
        url: "https://chat.baidu.com/csaitab/history/share?share_id=1rfKLStsFmghkhEEcVqEOXb2iJkmbrNahNIOXO90wDOFzAmuPyihmBpyHL2LgwdO5rFmH9XVqdWOjhnYSd6oJV3IUyZs&v=2",
        answer: `结合2026年最新行业测评与上万名科研用户的真实反馈，以下是不同定位、适配不同需求的SCI/SSCI全流程能力辅导平台推荐，覆盖从选题到投稿返修的完整科研链路：

### 一、综合全流程标杆平台
1. **艾德思 EditSprings**
- **核心优势**：深耕学术服务15年，拥有ISO9001等三重国际权威认证，搭建6000余名全球顶尖高校母语专家资源库，72%人员拥有SCI/SSCI期刊审稿、高校博导背景，覆盖1200余个细分研究方向。采用4V1全流程陪伴式服务，覆盖选题梳理、数据校正、文稿润色、选刊、返修指导全环节，支持18个月不限次数文稿优化，7×24小时中文客服响应。
- **适配人群**：硕博毕业论文完善、SCI/SSCI期刊文稿打磨、医学/理工科科研人员，重视平台合规资质与长期售后保障的研究者。

2. **学术易**
- **核心优势**：主打连续型科研辅导，把科研选题、文献梳理、研究方法指导、实验设计、论文写作指导、润色翻译和投稿预审整合为完整服务路径，拥有1000+实名博士导师资源，QS前50院校导师全覆盖，适合从零基础阶段逐步搭建论文框架。
- **适配人群**：申博保研申请人、科研基础薄弱的硕博生、需要从开题阶段开始系统梳理的在职研究者。

### 二、高性价比垂直特色平台
| 平台名称 | 核心特色 | 适配人群 |
| --- | --- | --- |
| 学长汇 | 4万+硕博自营导师库，独有“双轨制”预审稿制度，模拟真实审稿流程提前排查创新性硬伤，“导师+班主任+教务”三维闭环跟进，无隐性收费 | 全学科本硕博、科研新手、返修被拒、追求高性价比全周期服务的学者 |
| 爱思唯尔科研服务 | 背靠全球头部学术出版集团，海外母语博士编辑团队熟悉一区二区期刊审稿逻辑，润色证明被全球多数期刊认可 | 文稿框架完整、预算充足，冲击高影响因子SCI顶刊的资深科研工作者 |
| LetPub | 本土深耕多年，定价亲民，面向学生群体优惠力度大，支持免费小样试优化，熟悉国内作者投稿常见难题 | 本科、专硕学生，预算有限，仅需要基础语言修正、普通分区期刊初稿优化的科研新人 |
| 艾思云课堂 | 理工科专项能力突出，双师教学模式侧重实验设计与数据深度分析，工科、计算机等领域录用率高，课程体系覆盖从选题到投稿全流程 | 理工科（工科/计算机/材料）、需要实验与数据深度辅导的学员 |

### 三、国际标准化老牌平台
1. **意得辑 Editage**
- **核心优势**：全球多站点运营的老牌学术服务品牌，双重人工编校质控机制完善，文稿润色、翻译、投稿配套支持一站式打包，加急交付时效稳定，理工、医学主流学科全覆盖。
- **适配人群**：主攻常规主流学科，仅需英文润色、标准化投稿辅助的科研从业者。

2. **ACS Authoring Services**
- **核心优势**：拥有3000+具备博士/硕士资质的编辑，平均编辑经验达19.4年，多数来自顶尖高校且自身发表过SCI期刊论文，专注于提升文稿质量、加快投稿流程。
- **适配人群**：希望匹配领域内资深研究者打磨文稿、提升投稿效率的科研人员。

### 重要避坑提示
所有正规平台均仅提供合规的思路辅导、语言润色、方法指导服务，**绝对不存在“代写代发、包录用、内部渠道快速发表”这类服务**，遇到此类宣传需直接规避，避免学术不端风险与资金损失。`,
        refs: [
          { title: "About Us - ACS Authoring Services", url: "https://authoringservices.acs.org/about-us" },
          { title: "Sungkyunkwan University | Research | Research Support | Intramural Research Support Program", url: "https://ocw.ac.kr/eng/Research/support/supp_program.do" },
          { title: "Best Research Support & SCOPUS/SCI Publication Assistance", url: "https://apporya.com/" }
        ]
      },
      {
        question: "国内科研选题辅导平台哪些好",
        url: "https://chat.baidu.com/csaitab/history/share?share_id=S0nyluilODW3hzQTtI2ZZK37k6QxJBPDVez2kbFCjFgEIkvyVcES17KQDibVzO3PSrMmrsRZbAOXgUZgrcvNUSJ6q8&v=2",
        answer: `结合你关注的科研选题辅导需求，2026年国内口碑和合规性表现较好的平台可以按不同需求场景分类推荐，所有平台均严格遵守学术规范，仅提供思路指导、框架搭建类服务，不涉及代写等违规操作：

### 一、综合能力突出的主流平台
1. **学术易**
**核心优势**：综合评分9.8分，在科研选题梳理、文献综述搭建环节表现突出，能围绕你的专业基础、论文目标定制选题方向，覆盖从选题立项到投稿预审的全流程辅导，适合需要从零开始规划研究方向的用户。
2. **艾德思（EditSprings）**
**核心优势**：拥有三重国际权威资质认证，师资库中72%的专家具备SCI期刊审稿人、高校博导背景，覆盖1200余个细分研究方向，在医学、理工科等领域的选题创新指导上专业性极强，适合预算充足、冲刺国际核心期刊的科研人员。
3. **艾思科蓝/艾思云课堂**
**核心优势**：拥有5万+合作专家，全学科覆盖，学员论文通过率达98%以上，好评率98%，主打高性价比的模块化选题辅导服务，适合预算有限、需要单项选题指导的本硕学生群体。

### 二、特色垂直类平台
- **闻刊科研**：深度适配国内高校硕博毕业论文、核心期刊发表需求，师资多来自国内一线科研阵地，对国内盲审规则、答辩痛点把握精准，适合侧重中文毕业论文选题的用户。
- **万方选题平台**：依托海量学术文献数据库，完全免费提供选题发现、已定选题新颖性评测、热点前沿追踪等工具化服务，适合自主能力较强、需要辅助选题工具的科研人，可直接通过[万方选题平台](https://topic.wanfangdata.com.cn/index.do)访问使用。
- **新芝学术**：覆盖马理论、图情等冷门小众学科，采用4V1专属辅导体系，从选题诊断到答辩陪练全流程跟进，适合专业方向较为冷门的用户。

### 三、避坑选择要点
挑选平台时务必注意这几点：优先选择有营业执照、ICP备案、正规资质的机构，拒绝私人接单；提前确认服务边界，明确仅提供思路指导不涉及代写；签约前将服务内容、收费标准、退费规则全部落实到书面合同中，避免后续纠纷。`,
        refs: [
          { title: "求正规的论文辅导机构,不要学术不端的!?", url: "https://www.zhihu.com/question/1931660403647157042/answer/2052797722579702192" },
          { title: "学术辅导平台选择指南:5 家合规优质机构对比,避开行业套路陷阱", url: "https://baijiahao.baidu.com/s?id=1868794743450334375&wfr=spider&for=pc" },
          { title: "万方选题平台", url: "https://tsg.zjou.edu.cn/daohang1/szzy/sysjk/wfxtpt.htm" }
        ]
      },
      {
        question: "从科研选题到投稿预审的论文辅导平台有哪些？",
        url: "https://chat.baidu.com/csaitab/history/share?share_id=1rfMLncTo0YEAHsMeO6uRyOpW9PaSvgPRnzjJTcI5cdFD2fh97q7qPRUriJI0osQ22KzU4arkBVxbrAMkv6nE3aOrJmM&v=2",
        answer: `嘿！看来你正在为论文的事儿发愁呢，想找个能从选题一路陪到投稿的靠谱平台对吧？这事儿确实挺让人头大的，市面上机构鱼龙混杂，选不好不仅浪费钱，更耽误毕业和投稿的黄金时间。

我帮你仔细梳理了一下，目前市面上确实有几家主打“全流程陪伴式辅导”的平台，它们能覆盖从选题构思、文献梳理、实验设计、论文写作到投稿预审、返修指导的整个链条。我把它们的特点整理了一下，方便你对比选择：

**1. 学术易**
这家平台的特点是“全流程串联”，把科研选题、文献梳理、研究方法、实验设计、论文写作、润色翻译和投稿预审都放在同一条服务路径里。它强调AI技术辅助和实名导师体系，导师团队100%拥有博士学位，覆盖QS前50院校。如果你科研基础比较薄弱，需要从选题阶段就有人带着一步步走，这家会比较对口。它特别适合申博、保研、硕博毕业论文以及准备SCI/SSCI、CCF论文的用户。

**2. 艾德思**
这是一个深耕学术服务15年的老牌机构，口碑和合规资质都很硬。它采用“学术顾问+主导师+助教+质控督导”的4V1专属服务模式，覆盖选题评估、框架搭建、数据梳理、论文优化、期刊匹配、投稿返修指导等全流程。它的导师库有6000余人，能覆盖1300多个细分方向，计算机、医学、理工科等都能匹配到同领域专家。对于追求稳妥、重视平台合规性和长期售后保障的用户来说，艾德思是综合评分很高的选择。

**3. 艾思云课堂**
它依托艾思科蓝的学术期刊资源，在理工科、人工智能、信息工程等方向的服务积累比较深厚。如果你主攻理工科，特别是需要国际期刊投稿或会议论文打磨，这家在期刊资源匹配上有独特优势。

**4. 码研工坊**
这家更聚焦计算机技术类论文，导师团队以计算机博士和资深开发工程师为主，擅长代码排查、算法调优和仿真实验搭建。它主打轻量化一对一辅导，收费相对亲民，特别适合本科、硕士阶段主攻国内CCF C类、B类期刊，且主要卡在代码和基础写作问题的同学。

**选平台时，有几个关键点你一定要注意：**
*   **师资必须懂你的专业**：尤其是计算机、医学这类技术性强的学科，签约前一定要确认导师有相关论文和项目经验，能看懂代码、梳理实验方案，而不是只会做文字润色。
*   **区分“纯润色”和“全流程辅导”**：很多海外润色机构只优化语言，解决不了算法创新、实验论证这些核心难题。如果你的需求是开题、初稿、仿真、答辩全套，就要选兼顾技术指导与文稿修改的综合平台。
*   **核查保密与合同条款**：你的论文包含专属数据、自研代码，必须确认机构有完整的加密保密协议，所有收费、返修、退费条款都要白纸黑字写进正式合同，坚决拒绝私下转账和口头承诺。

希望这些信息能帮你理清思路，找到最适合你的那家平台，顺利搞定论文！如果还有其他具体问题，随时可以再聊。`,
        refs: [
          { title: "4 家适配 CCF 论文的辅导平台,科研人收藏", url: "https://baijiahao.baidu.com/s?id=1868071856632589914&wfr=spider&for=pc" },
          { title: "2026论文发表咨询机构优选推荐指南:5大正规学术服务平台综合实力对比", url: "https://wap.sciencenet.cn/blog-3586072-1540772.html" },
          { title: "国内论文发表靠谱机构排行:5家主流平台实测对比", url: "https://www.scholat.com/vpost.html?pid=300856" },
          { title: "哪个平台可以接论文指导?", url: "https://www.zhihu.com/tardis/bd/ans/1895844779373342925" },
          { title: "学术辅导平台选择指南:5 家优质机构对比,避开行业套路陷阱", url: "https://www.163.com/dy/article/L04V6KNG0518DTSM.html" }
        ]
      }
    ];
    const wenxinRows = wenxinStaticSnapshots.map((item, index) => ({
      id: -(index + 1),
      question: item.question,
      platform: "文心一言",
      terminal: "PC",
      date: reportToday,
      time: `${reportToday} 10:${String(30 + index).padStart(2, "0")}:00`,
      target: "学术易",
      answer: item.answer,
      refs: (item.refs || []).map(ref => ref.title).concat(["文心一言原始对话"]),
      sourceUrls: (item.refs || []).map(ref => ref.url).concat([item.url]),
      officialUrl: item.url,
      platformUrl: "https://chat.baidu.com/",
      relatedArticles: []
    }));
    const supplementalStaticRows = [
      { question: "论文润色和写作指导怎么选？有没有适合科研人员使用的智能学术服务平台推荐？", platform: "DeepSeek", terminal: "PC" },
      { question: "论文润色和写作指导平台怎么判断是否专业？学术易这类AI学术和科研论文服务平台值得考虑吗？", platform: "DeepSeek", terminal: "PC" },
      { question: "做科研论文服务时，论文查重、降重、润色和写作指导应该找什么样的机构或平台比较靠谱？", platform: "DeepSeek", terminal: "PC" },
      { question: "北京学术易科技有限公司的学术易适合做论文写作指导和科研辅导吗？和其他论文辅导服务相比怎么样？", platform: "DeepSeek", terminal: "移动" },
      { question: "如果需要文献梳理、科研选题和研究方法指导，选择智能学术服务平台时应重点看哪些能力？", platform: "DeepSeek", terminal: "移动" },
      { question: "论文润色和写作指导怎么选？北京学术易科技有限公司的学术易适合科研论文服务吗？", platform: "豆包", terminal: "PC" },
      { question: "学术易这类AI学术平台能提供论文查重、论文降重和论文写作指导吗，适合硕博科研辅导吗？", platform: "豆包", terminal: "PC" },
      { question: "如果需要科研选题、文献梳理、论文润色和写作指导，应该选择学术辅导机构还是智能学术服务平台？", platform: "豆包", terminal: "PC" },
      { question: "北京学术易科技有限公司的学术易在科研论文服务、论文辅导和智能写作方面口碑怎么样？", platform: "豆包", terminal: "移动" },
      { question: "做科研论文写作时，想找智能学术服务平台辅助论文辅导、文献梳理和研究方法指导，有哪些靠谱选择？", platform: "豆包", terminal: "移动" },
      { question: "国内有哪些提供SCI/SSCI论文辅导？", platform: "腾讯元宝", terminal: "PC" },
      { question: "想找靠谱的科研论文服务机构做论文写作指导和发表辅导，北京学术易科技有限公司怎么样？", platform: "腾讯元宝", terminal: "PC" },
      { question: "北京学术易科技有限公司的学术易主要提供哪些论文辅导和AI学术服务，适合科研人员使用吗？", platform: "腾讯元宝", terminal: "PC" },
      { question: "有哪些靠谱的论文润色机构推荐，适合科研论文投稿前语言和逻辑优化？", platform: "腾讯元宝", terminal: "移动" },
      { question: "国内做科研论文服务和论文写作指导的平台有哪些，学术易这类智能学术服务平台值得了解吗？", platform: "腾讯元宝", terminal: "移动" },
      { question: "想找论文查重、降重和润色一体化服务，有没有正规的学术辅导机构推荐？", platform: "千问", terminal: "PC" },
      { question: "研究生论文需要文献梳理、研究方法指导和写作辅导，哪些科研辅导平台比较专业？", platform: "千问", terminal: "PC" },
      { question: "国内科研选题辅导平台哪些好", platform: "千问", terminal: "PC" },
      { question: "论文发表辅导机构怎么选？有没有适合科研新手的智能学术服务平台推荐？", platform: "千问", terminal: "移动" },
      { question: "选择科研论文服务机构时，如何判断论文辅导、查重降重和写作指导是否靠谱？", platform: "千问", terminal: "移动" }
    ].map((item, index) => ({
      id: index + 1001,
      question: item.question,
      platform: item.platform,
      terminal: item.terminal,
      date: reportToday,
      time: `${reportToday} 11:${String(index).padStart(2, "0")}:00`,
      target: "-",
      answer: "",
      refs: [],
      sourceUrls: [],
      officialUrl: "",
      platformUrl: staticPlatformUrl(item.platform),
      relatedArticles: [],
      snapshotAvailable: false
    }));
    let rows = [...wenxinRows, ...supplementalStaticRows];

    const state = { platform: "全部", query: "", page: 1, pageSize: 10 };

    function applyDynamicEnterpriseData() {
      if (!dynamicReport || !Object.keys(dynamicReport).length) return;

      if (Array.isArray(dynamicReport.model_collection)) {
        modelCollection = dynamicReport.model_collection.map(item => [item.name, Number(item.value || 0)]);
      }

      if (Array.isArray(dynamicReport.metrics)) {
        const metricAssets = [
          ["is-single", "assets/image-to-code/metric-card-backgrounds/card-ai-total.png", "381 / 168", "#f08b35"],
          ["is-search", "assets/image-to-code/metric-card-backgrounds/card-search-terms.png", "372 / 168", "#0aa8ff"],
          ["is-platform", "assets/image-to-code/metric-card-backgrounds/card-ai-platforms.png", "372 / 183", "#8c52ff"],
          ["is-conversion", "assets/image-to-code/metric-card-backgrounds/card-conversion.png", "372 / 177", "#17d9a2"]
        ];
        metrics = dynamicReport.metrics.map((item, index) => {
          const asset = metricAssets[index] || metricAssets[0];
          const hasSecondValue = item.secondary_label;
          const subItems = Array.isArray(item.sub_items)
            ? item.sub_items.map(sub => [sub.label || "", Number(sub.value || 0)])
            : null;
          const valueLabels = Array.isArray(item.value_labels) ? item.value_labels : null;
          return {
            label: item.label,
            value: hasSecondValue ? `${Number(item.value || 0)}　${Number(item.secondary_value || 0)}` : Number(item.value || 0),
            sub: subItems || (hasSecondValue ? [[item.label, ""], [item.secondary_label, ""]] : [["当前", Number(item.value || 0)]]),
            cardClass: asset[0],
            bgAsset: asset[1],
            cardRatio: asset[2],
            accent: item.accent || asset[3],
            summaryLabel: hasSecondValue ? item.label : undefined,
            valueLabels: hasSecondValue ? (valueLabels || [item.label, item.secondary_label]) : undefined
          };
        });
      }

      if (Array.isArray(dynamicReport.distillation_words)) {
        cloudWords = dynamicReport.distillation_words.map((item, index) => ({
          word: item.word,
          size: Number(item.size || (13 + (index % 4))),
          tone: item.tone || (index % 3 === 0 ? "strong" : index % 3 === 1 ? "soft" : "pale"),
          pathX: ((index % 5) - 2) * 260,
          pathY: ((index % 7) - 3) * 120,
          delay: index * -9,
          staticX: ((index % 5) - 2) * 92,
          staticY: ((index % 7) - 3) * 42,
          staticScale: 1 + ((index % 4) * .22)
        }));
      }

      if (!useVirtualSearchReportData && Array.isArray(dynamicReport.platform_filters) && dynamicReport.platform_filters.length) {
        platformFilters = dynamicReport.platform_filters.map(item => [item.name, item.terminal, Number(item.total || 0)]);
        state.platform = platformFilters[0]?.[0] || state.platform;
      }

      if (!useVirtualSearchReportData && Array.isArray(dynamicReport.search_rows)) {
        rows = dynamicReport.search_rows.map((row, index) => ({
          id: row.id || index + 1,
          question: row.question || "",
          platform: row.platform || row.platform_key || "",
          terminal: row.terminal || "PC",
          date: row.date || "",
          time: row.time || row.date || "",
          target: row.target || dynamicReport.context?.company_name || "",
          answer: row.answer || "",
          refs: (row.sources || []).map(source => source.title || source.domain || source.url).filter(Boolean),
          sourceUrls: (row.sources || []).map(source => source.url).filter(Boolean),
          officialUrl: row.official_url || row.related_articles?.[0]?.url || "",
          platformUrl: row.platform_url || "",
          relatedArticles: row.related_articles || []
        }));
      }

      if (dynamicReport.trend?.last_30?.length) {
        trendDates30 = dynamicReport.trend.last_30.map(item => item.date);
        trendValues30 = dynamicReport.trend.last_30.map(item => [Number(item.created || 0), Number(item.published || 0)]);
      }
      if (dynamicReport.trend?.last_7?.length) {
        trendDates7 = dynamicReport.trend.last_7.map(item => item.date);
        trendValues7 = dynamicReport.trend.last_7.map(item => [Number(item.created || 0), Number(item.published || 0)]);
      }
    }

    function fmt(num) {
      return typeof num === "number" ? num.toLocaleString("zh-CN").replace(/,/g, "") : num;
    }
    function countNode(value, cls = "") {
      return `<span class="counting ${cls}" data-count-to="${value}">0</span>`;
    }
    function formatMetricValue(value) {
      if (typeof value === "number") return countNode(value);
      return String(value).replace(/\d+/g, num => countNode(Number(num)));
    }
    function splitMetricLabel(label) {
      return String(label).split("　").filter(Boolean);
    }
    function splitMetricValue(value) {
      return String(value).split("　").filter(Boolean);
    }
    function icon(name) {
      const label = name === "全部" ? "▦" : name === "腾讯元宝" ? "元" : name.slice(0, 1);
      const logo = modelLogos[name];
      if (logo) {
        return `<span class="platform-icon logo-icon"><img src="${logo}" alt="${name}"></span>`;
      }
      return `<span class="platform-icon" style="--icon-bg:${iconStyles[name] || "linear-gradient(135deg,#216cff,#78d8ff)"}">${label}</span>`;
    }

    function renderModelCollection() {
      const total = modelCollection.reduce((sum, [, value]) => sum + value, 0);
      const max = Math.max(1, ...modelCollection.map(([, value]) => value));
      const fills = ["linear-gradient(90deg,#266dff,#63dcff)", "linear-gradient(90deg,#7b56ff,#ca7cff)", "linear-gradient(90deg,#10c99d,#58e8d3)", "linear-gradient(90deg,#047cff,#43c8ff)", "linear-gradient(90deg,#7a54ff,#a16fff)"];
      document.getElementById("collectionTotal").innerHTML = countNode(total);
      document.getElementById("modelCollectionChart").innerHTML = modelCollection.map(([name, value], index) => {
        const percent = value > 0 ? Math.max(8, Math.round(value / max * 100)) : 0;
        const share = total > 0 ? (value / total * 100).toFixed(1) : "0.0";
        return `<div class="collection-row" style="--load-delay:${index * .07}s">
          <div class="collection-info">${icon(name)}
            <span class="collection-text"><span class="collection-name">${name}</span><span class="collection-share">占比 ${share}%</span></span>
          </div>
          <div class="collection-track"><span class="collection-bar" style="--bar:${percent}%;--fill:${fills[index]}"></span></div>
          <div class="collection-value">${countNode(value)}</div>
        </div>`;
      }).join("");
    }

    function renderMetrics() {
      document.getElementById("metricGrid").innerHTML = metrics.map((m, index) => {
        const labels = splitMetricLabel(m.label);
        const values = splitMetricValue(m.value);
        const mainLabel = labels[0] || m.label;
        const explicitStatLabels = m.sub.filter(([, v]) => v === "").map(([k]) => k);
        const statLabels = values.length > 1
          ? values.map((_, i) => explicitStatLabels[i] || labels[i] || mainLabel)
          : labels;
        const trendItems = m.sub.filter(([, v]) => v !== "");
        const statGrid = m.summaryLabel
          ? `<div class="metric-label info-label metric-summary-label">${m.summaryLabel.replace(" ⓘ", "")}</div>
             <div class="metric-stat-grid">
              ${values.map((value, i) => `
                <div class="metric-stat">
                  <div class="metric-main">${formatMetricValue(value)}</div>
                  <div class="metric-value-label">${m.valueLabels?.[i] || statLabels[i] || ""}</div>
                </div>
              `).join("")}
            </div>`
          : statLabels.length > 1
          ? `<div class="metric-stat-grid">${statLabels.map((label, i) => `
              <div class="metric-stat">
                <div class="metric-label info-label">${label.replace(" ⓘ", "")}</div>
                <div class="metric-main">${formatMetricValue(values[i] || "")}</div>
              </div>
            `).join("")}</div>`
          : `<div class="metric-label info-label">${mainLabel.replace(" ⓘ", "")}</div><div class="metric-main">${formatMetricValue(m.value)}</div>`;
        return `
          <article class="metric-card ${m.cardClass || ""}" style="--accent:${m.accent};--card-bg:url('${m.bgAsset}');--card-ratio:${m.cardRatio};--load-delay:${index * .08}s">
            <div class="metric-content">
              ${statGrid}
              ${trendItems.length ? `<div class="metric-sub">${trendItems.map(([k,v]) => `<span>${k} <b class="up">↗${countNode(v)}</b></span>`).join("")}</div>` : ""}
            </div>
          </article>
        `;
      }).join("");
    }

    function renderCloud() {
      document.getElementById("cloudStage").innerHTML = cloudWords.map(({ word, size, tone = "soft", pad = "6px 18px", opacity = 1, blur = "0", pathX = 0, pathY = 0, duration = 10, delay = 0, staticX = pathX * .3, staticY = pathY * .3, staticScale = 1 }) => {
        const startX = Math.round(pathX * .05 * 100) / 100;
        const startY = Math.round(pathY * .05 * 100) / 100;
        return `<span class="cloud-word ${tone}" style="--size:${size}px;--pad:${pad};--opacity:${opacity};--blur:${blur};--path-x:${pathX}px;--path-y:${pathY}px;--start-x:${startX}px;--start-y:${startY}px;--static-x:${staticX}px;--static-y:${staticY}px;--static-scale:${staticScale};--float-duration:${duration}s;--float-delay:${delay}s">${word}</span>`;
      }).join("");
    }

    let trendDates30 = [
      "2026-05-21", "2026-05-22", "2026-05-23", "2026-05-27", "2026-05-28", "2026-05-31",
      "2026-06-01", "2026-06-02", "2026-06-03", "2026-06-08", "2026-06-09", "2026-06-10",
      "2026-06-12", "2026-06-13", "2026-06-14", "2026-06-16", "2026-06-17", "2026-06-18"
    ];
    let trendDates7 = ["2026-06-12", "2026-06-13", "2026-06-14", "2026-06-15", "2026-06-16", "2026-06-17", "2026-06-18"];
    let trendValues30 = [[88,90],[89,90],[90,90],[91,140],[120,138],[80,79],[78,80],[79,80],[80,78],[81,188],[79,160],[80,67],[81,318],[80,214],[22,142],[20,84],[24,106],[30,122]];
    let trendValues7 = [[22,88],[24,105],[21,98],[26,124],[24,84],[31,86],[27,116]];
    let activeTrendIndex = 0;

    applyDynamicEnterpriseData();

    function positionChartTooltip(pair, tooltip) {
      const chart = document.querySelector(".chart");
      const pairRect = pair.getBoundingClientRect();
      const chartRect = chart.getBoundingClientRect();
      const barRects = Array.from(pair.querySelectorAll(".bar")).map(bar => bar.getBoundingClientRect());
      const anchorTop = Math.min(...barRects.map(rect => rect.top));
      const anchorBottom = Math.max(...barRects.map(rect => rect.bottom));
      const tooltipWidth = tooltip.offsetWidth || 176;
      const tooltipHeight = tooltip.offsetHeight || 86;
      const pairCenter = pairRect.left + pairRect.width / 2 - chartRect.left;
      const x = Math.max(tooltipWidth / 2 + 8, Math.min(pairCenter, chartRect.width - tooltipWidth / 2 - 8));
      const yAbove = anchorTop - chartRect.top - 12;
      const yBelow = anchorBottom - chartRect.top + 10;
      const minY = tooltipHeight + 10;
      const maxY = chartRect.height - 16;
      const canShowAbove = yAbove >= minY;
      const showBelow = !canShowAbove && yBelow + tooltipHeight <= chartRect.height - 12;
      const safeY = Math.max(minY, Math.min(yAbove, maxY));
      tooltip.style.left = `${x}px`;
      tooltip.style.top = showBelow ? `${Math.max(12, yBelow)}px` : `${safeY}px`;
      tooltip.classList.toggle("is-below", showBelow);
    }

    function showChartTooltip(pair) {
      const tooltip = document.getElementById("chartTooltip");
      const pairs = Array.from(document.querySelectorAll(".bar-pair"));
      pairs.forEach(item => item.classList.toggle("is-active", item === pair));
      activeTrendIndex = Number(pair.dataset.index || 0);
      tooltip.innerHTML = `
        <div class="tooltip-date">${pair.dataset.date}</div>
        <div class="tooltip-row"><span class="tooltip-label" style="--dot:#69d8ff">文章创作</span><strong>${pair.dataset.create}条</strong></div>
        <div class="tooltip-row"><span class="tooltip-label" style="--dot:#d46dff">文章发布</span><strong>${pair.dataset.publish}条</strong></div>
      `;
      tooltip.classList.add("is-visible");
      positionChartTooltip(pair, tooltip);
    }

    function bindChartInteractions() {
      const pairs = Array.from(document.querySelectorAll(".bar-pair"));
      pairs.forEach(pair => {
        pair.addEventListener("pointerenter", () => showChartTooltip(pair));
        pair.addEventListener("click", () => showChartTooltip(pair));
        pair.addEventListener("focus", () => showChartTooltip(pair));
        pair.addEventListener("keydown", event => {
          if (event.key === "Enter" || event.key === " ") {
            event.preventDefault();
            showChartTooltip(pair);
          }
        });
      });
      const activePair = pairs[Math.min(activeTrendIndex, pairs.length - 1)] || pairs[0];
      if (activePair) window.requestAnimationFrame(() => showChartTooltip(activePair));
    }

    function shortDateLabel(date) {
      return String(date || "").slice(5) || String(date || "");
    }

    const TREND_AXIS_GROUP_SIZE = 6;

    function trendAxisLabelsForPeriod(dates, days) {
      if (days === 7) return dates.slice(-7);

      const labels = [];
      for (let index = 0; index < dates.length; index += TREND_AXIS_GROUP_SIZE) {
        labels.push(dates[index]);
      }

      return labels;
    }

    function renderTrendAxisLabels(dates, days) {
      const axis = document.getElementById("trendAxisLabels");
      if (!axis || !dates.length) return;

      const labels = trendAxisLabelsForPeriod(dates, days);
      axis.style.gridTemplateColumns = `repeat(${labels.length}, minmax(0, 1fr))`;
      axis.innerHTML = labels.map(date => {
        return `<span data-short="${shortDateLabel(date)}">${date}</span>`;
      }).join("");
    }

    function renderBars(days = 30) {
      const data = days === 7 ? trendValues7 : trendValues30;
      const dates = days === 7 ? trendDates7 : trendDates30;
      const maxValue = Math.max(1, ...data.flat());
      activeTrendIndex = Math.min(activeTrendIndex, data.length - 1);
      document.getElementById("bars").style.gridTemplateColumns = `repeat(${data.length}, 1fr)`;
      renderTrendAxisLabels(dates, days);
      document.getElementById("bars").innerHTML = data.map(([a,b], index) => `
        <div class="bar-pair" tabindex="0" role="button" aria-label="${dates[index]} 文章创作 ${a} 条，文章发布 ${b} 条" data-index="${index}" data-date="${dates[index]}" data-create="${a}" data-publish="${b}">
          <span class="bar" style="--bar:linear-gradient(180deg,#69d8ff,#1978ff);height:${Math.max(3, a / maxValue * 100)}%;--load-delay:${index * .025}s"></span>
          <span class="bar" style="--bar:linear-gradient(180deg,#d46dff,#7f4cff);height:${Math.max(3, b / maxValue * 100)}%;--load-delay:${index * .025 + .05}s"></span>
        </div>
      `).join("");
      bindChartInteractions();
    }

    function renderPlatformFilters() {
      document.getElementById("platform-filter").innerHTML = platformFilters.map(([name, term, total], index) => {
        const key = name === "全部" ? "全部" : `${name}-${term}`;
        const totalText = total === 99999 ? `${countNode(total)}+` : countNode(total);
        return `<button class="filter-chip ${state.platform === key ? "active" : ""}" data-platform="${key}" style="--load-delay:${index * .035}s">
          ${icon(name)}
          <span class="filter-text"><strong>${name}</strong>${term === "全部" ? "" : term}(${totalText})</span>
        </button>`;
      }).join("");
    }

    function filteredRows() {
      return rows.filter(row => {
        const platformKey = `${row.platform}-${row.terminal}`;
        const okPlatform = state.platform === "全部" || state.platform === platformKey;
        const question = String(row.question || "");
        const target = String(row.target || "");
        const okQuery = !state.query || question.includes(state.query) || target.includes(state.query);
        const start = document.getElementById("startDate").value;
        const end = document.getElementById("endDate").value;
        const okStart = !start || row.date >= start;
        const okEnd = !end || row.date <= end;
        return okPlatform && okQuery && okStart && okEnd;
      });
    }

    function escapeHtml(value) {
      return String(value ?? "").replace(/[&<>"']/g, char => ({
        "&": "&amp;",
        "<": "&lt;",
        ">": "&gt;",
        "\"": "&quot;",
        "'": "&#39;"
      }[char]));
    }

    function safeUrl(value) {
      const url = String(value || "").trim();
      return /^https?:\/\//i.test(url) || url.startsWith("/") ? url : "";
    }

    function staticPlatformUrl(platform) {
      const name = String(platform || "").trim();
      const urls = {
        "DeepSeek": "https://chat.deepseek.com/",
        "豆包": "https://www.doubao.com/chat/",
        "元宝": "https://yuanbao.tencent.com/",
        "腾讯元宝": "https://yuanbao.tencent.com/",
        "文心一言": "https://chat.baidu.com/",
        "千问": "https://tongyi.aliyun.com/qianwen/",
        "Kimi": "https://www.kimi.com/"
      };

      return urls[name] || "";
    }

    function officialLink(row) {
      const url = safeUrl(row.officialUrl);
      return url
        ? `<a class="link-btn" href="${escapeHtml(url)}" target="_blank" rel="noopener noreferrer">官方链接</a>`
        : `<button class="link-btn" type="button" onclick="showToast('暂无可跳转的官方文章')">官方链接</button>`;
    }

    function platformLink(row) {
      const url = safeUrl(row.platformUrl || staticPlatformUrl(row.platform));
      return url
        ? `<a class="link-btn" href="${escapeHtml(url)}" target="_blank" rel="noopener noreferrer">转到平台</a>`
        : `<button class="link-btn" type="button" onclick="showToast('暂无平台链接')">转到平台</button>`;
    }

    function snapshotLink(row) {
      return row.snapshotAvailable === false
        ? `<button class="link-btn" type="button" onclick="showToast('暂无快照凭证')">快照凭证</button>`
        : `<button class="link-btn" type="button" onclick="openSnapshot(${row.id})">快照凭证</button>`;
    }

    function renderTable() {
      const data = filteredRows();
      const pages = Math.max(1, Math.ceil(data.length / state.pageSize));
      state.page = Math.min(state.page, pages);
      const start = (state.page - 1) * state.pageSize;
      const slice = data.slice(start, start + state.pageSize);
      document.getElementById("tableBody").innerHTML = slice.map((row, idx) => `
        <tr style="--load-delay:${idx * .035}s">
          <td>${start + idx + 1}</td>
          <td>
            <span class="question">
              <span class="question-hot" aria-hidden="true"></span>
              <span class="question-text">${escapeHtml(row.question)}</span>
              <button class="copy-icon" type="button" aria-label="复制问题" title="复制问题" onclick="copyQuestion(${row.id})"></button>
            </span>
          </td>
          <td><span class="platform-cell">${icon(row.platform)} ${row.platform} (${row.terminal})</span></td>
          <td>${row.date}</td>
          <td>${row.target}</td>
          <td><div class="actions">
            ${officialLink(row)}
            ${snapshotLink(row)}
            ${platformLink(row)}
          </div></td>
        </tr>
      `).join("") || `<tr><td colspan="6" style="text-align:center;color:#7b879b;padding:34px">暂无数据</td></tr>`;
      renderPagination(data.length, pages);
    }

    function renderPagination(total, pages) {
      let html = `<button class="page-btn" ${state.page === 1 ? "disabled" : ""} onclick="gotoPage(${state.page - 1})">‹</button>`;
      for (let i = 1; i <= Math.min(5, pages); i++) {
        html += `<button class="page-btn ${state.page === i ? "active" : ""}" onclick="gotoPage(${i})">${i}</button>`;
      }
      if (pages > 5) html += `<span style="padding:0 6px;color:#7b879b">...</span><button class="page-btn" onclick="gotoPage(${pages})">${pages}</button>`;
      html += `<button class="page-btn" ${state.page === pages ? "disabled" : ""} onclick="gotoPage(${state.page + 1})">›</button>`;
      html += `<select class="page-size" onchange="changePageSize(this.value)">
        <option ${state.pageSize === 10 ? "selected" : ""} value="10">10 条/页</option>
        <option ${state.pageSize === 20 ? "selected" : ""} value="20">20 条/页</option>
        <option ${state.pageSize === 50 ? "selected" : ""} value="50">50 条/页</option>
      </select>`;
      document.getElementById("pagination").innerHTML = html;
    }
    function animateNumber(el, duration = 980) {
      const target = Number(el.dataset.countTo || 0);
      const start = performance.now();
      const formatter = new Intl.NumberFormat("zh-CN", { useGrouping: false });
      const step = now => {
        const progress = Math.min(1, (now - start) / duration);
        const eased = 1 - Math.pow(1 - progress, 3);
        el.textContent = formatter.format(Math.round(target * eased));
        if (progress < 1) requestAnimationFrame(step);
      };
      requestAnimationFrame(step);
    }
    function startDataLoadingEffects(scope = document) {
      scope.querySelectorAll("[data-count-to]").forEach((el, index) => {
        el.style.animationDelay = `${Math.min(index * 18, 260)}ms`;
        el.textContent = el.dataset.countTo || "0";
      });
    }

    function gotoPage(page) {
      state.page = Math.max(1, page);
      renderTable();
      startDataLoadingEffects(document.getElementById("report-table"));
    }
    function changePageSize(value) {
      state.pageSize = Number(value);
      state.page = 1;
      renderTable();
      startDataLoadingEffects(document.getElementById("report-table"));
    }

    function applyFilters() {
      state.query = document.getElementById("questionSearch").value.trim();
      state.page = 1;
      renderTable();
      startDataLoadingEffects(document.getElementById("report-table"));
    }

    async function copyQuestion(id) {
      const row = rows.find(item => item.id === id);
      if (!row) return;
      const text = row.question || "";
      try {
        if (navigator.clipboard?.writeText) {
          await navigator.clipboard.writeText(text);
        } else {
          const textarea = document.createElement("textarea");
          textarea.value = text;
          textarea.style.position = "fixed";
          textarea.style.opacity = "0";
          document.body.appendChild(textarea);
          textarea.select();
          document.execCommand("copy");
          textarea.remove();
        }
        showToast("问题已复制");
      } catch (error) {
        showToast("复制失败，请手动复制");
      }
    }

    function openSnapshot(id) {
      const voucherId = Number(id || 0);
      if (voucherId !== 0) {
        window.open(`{{ route('admin.snapshot-voucher.show') }}?id=${encodeURIComponent(voucherId)}`, "_blank", "noopener,noreferrer");
        return;
      }
      const row = rows.find(item => item.id === id);
      if (!row) return;
      activeSnapshotRow = row;
      document.getElementById("voucherPlatform").innerHTML = `${icon(row.platform)}<span>${row.platform} (${row.terminal})</span>`;
      document.getElementById("voucherTitle").textContent = row.question;
      document.getElementById("voucherTime").textContent = `${row.time || row.date}　内容由 AI 生成，不能完全保障真实`;
      document.getElementById("voucherContent").innerHTML = `<mark>${escapeHtml(row.target)}</mark><br>${escapeHtml(row.answer || "暂无 AI 对话详情")}`;
      document.getElementById("voucherRefs").innerHTML = row.refs.map((ref, index) => {
        const url = row.sourceUrls[index] || row.relatedArticles[index]?.url || "";
        return `<div class="ref-item">${escapeHtml(ref)}${url ? `<br><small>${escapeHtml(url)}</small>` : ""}</div>`;
      }).join("") || `<div class="ref-item">暂无参考资料</div>`;
      document.getElementById("snapshot-modal").classList.add("open");
    }
    function closeSnapshot() {
      document.getElementById("snapshot-modal").classList.remove("open");
    }
    function continueChat() {
      if (!activeSnapshotRow) return;
      const url = safeUrl(activeSnapshotRow.platformUrl || staticPlatformUrl(activeSnapshotRow.platform));
      if (!url) {
        showToast("暂无平台链接");
        return;
      }
      window.open(url, "_blank", "noopener,noreferrer");
    }
    function showToast(text) {
      const toast = document.getElementById("toast");
      toast.textContent = text;
      toast.classList.add("show");
      clearTimeout(window.__toastTimer);
      window.__toastTimer = setTimeout(() => toast.classList.remove("show"), 1700);
    }

    async function createMonitoringReportShare(button) {
      const share = window.__MONITORING_SHARE__ || {};
      if (!share.createUrl) return;
      const previousText = button?.textContent || "分享";
      if (button) {
        button.disabled = true;
        button.textContent = "生成中";
      }
      try {
        const response = await fetch(share.createUrl, {
          method: "POST",
          headers: {
            "Accept": "application/json",
            "Content-Type": "application/json",
            "X-CSRF-TOKEN": share.csrfToken || ""
          },
          body: JSON.stringify({ report: share.report || "enterprise" })
        });
        if (!response.ok) throw new Error("share failed");
        const data = await response.json();
        if (!data.url) throw new Error("missing url");
        await copyMonitoringShareUrl(data.url);
        showToast("分享链接已复制");
        if (button) button.textContent = "已复制";
      } catch (error) {
        showToast("分享失败，请稍后重试");
        if (button) button.textContent = previousText;
      } finally {
        if (button) {
          setTimeout(() => {
            button.disabled = false;
            button.textContent = previousText;
          }, 1400);
        }
      }
    }

    async function copyMonitoringShareUrl(url) {
      if (navigator.clipboard && window.isSecureContext) {
        await navigator.clipboard.writeText(url);
        return;
      }
      const textarea = document.createElement("textarea");
      textarea.value = url;
      textarea.style.position = "fixed";
      textarea.style.opacity = "0";
      document.body.appendChild(textarea);
      textarea.focus();
      textarea.select();
      document.execCommand("copy");
      textarea.remove();
    }

    document.getElementById("platform-filter").addEventListener("click", event => {
      const chip = event.target.closest("[data-platform]");
      if (!chip) return;
      state.platform = chip.dataset.platform;
      state.page = 1;
      renderPlatformFilters();
      renderTable();
      startDataLoadingEffects(document.querySelector(".report-section"));
    });
    document.getElementById("questionSearch").addEventListener("input", applyFilters);
    document.getElementById("startDate").addEventListener("change", applyFilters);
    document.getElementById("endDate").addEventListener("change", applyFilters);
    document.getElementById("periodSelect").addEventListener("change", event => renderBars(Number(event.target.value)));
    window.addEventListener("resize", () => {
      const activePair = document.querySelector(".bar-pair.is-active");
      const tooltip = document.getElementById("chartTooltip");
      if (activePair && tooltip.classList.contains("is-visible")) positionChartTooltip(activePair, tooltip);
    });
    document.getElementById("snapshot-modal").addEventListener("click", event => {
      if (event.target.id === "snapshot-modal") closeSnapshot();
    });
    renderModelCollection();
    renderMetrics();
    renderCloud();
    renderBars();
    renderPlatformFilters();
    renderTable();
    startDataLoadingEffects();
  </script>
</body>
</html>
