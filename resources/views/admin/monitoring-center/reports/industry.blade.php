<!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>行业竞争力分析报表</title>
  <script>
    (() => {
      const [navigation] = performance.getEntriesByType("navigation");
      if (navigation && navigation.type === "reload") {
        window.location.replace("geo-dashboard-replica.html");
      }
    })();
  </script>
  <style>
    :root {
      --ink: #333;
      --muted: #666;
      --blue: #386aff;
      --violet: #9265ff;
      --soft: #eff0ff;
      --line: #dddddd;
      --header: #d0dcff;
    }

    * { box-sizing: border-box; }
    html, body { width: 100%; min-height: 100%; }
    body {
      margin: 0;
      overflow: hidden;
      color: var(--ink);
      font-family: -apple-system, BlinkMacSystemFont, "Helvetica Neue", Helvetica, "Segoe UI", Arial, Roboto, "PingFang SC", "Microsoft YaHei", sans-serif;
      background: #e3ebff;
      letter-spacing: 0;
    }
    img { max-width: 100%; }

    .competitiveness_analysis_report_web {
      position: relative;
      width: 100%;
      height: 100vh;
      min-width: 0;
      overflow: auto;
      background-image: url("https://geo.zxaigc.com/assets/images/bg-48ef5166.png");
      background-size: 100%;
      background-position: top;
      background-repeat: no-repeat;
    }

    header {
      height: 88px;
      padding: 14px 18px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      position: relative;
      z-index: 1000;
    }
    .logo {
      display: block;
      width: min(320px, 100%);
      height: 54px;
      margin: 0;
      object-fit: contain;
      object-position: left center;
    }
    .right_content {
      display: flex;
      align-items: center;
      justify-content: flex-end;
      gap: 18px;
      position: relative;
      z-index: 1001;
    }
    .company-meta {
      width: 300px;
      flex: 0 0 300px;
      color: #20263a;
      text-align: center;
      font-size: 16px;
      line-height: 1.55;
      white-space: nowrap;
    }
    .report-menu {
      position: relative;
      width: 220px;
      flex: 0 0 220px;
      min-width: 220px;
      color: #fff;
      font-size: 14px;
      z-index: 1002;
    }
    .report-menu[open] { z-index: 2000; }
    .report-menu summary {
      height: 40px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      padding: 0 16px 0 18px;
      list-style: none;
      border-radius: 24px;
      color: #fff;
      background: linear-gradient(135deg, #0f72ff, #a44eff);
      box-shadow: 0 10px 22px rgba(78, 94, 245, .22);
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
      border: 1px solid rgba(139, 154, 194, .42);
      border-radius: 7px;
      background: rgba(247, 250, 255, .98);
      box-shadow: 0 18px 38px rgba(66, 82, 140, .18);
    }
    .report-menu-list a,
    .report-menu-list span {
      display: block;
      padding: 8px 12px;
      border-radius: 5px;
      color: #20263a;
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
      background: linear-gradient(135deg, #0f72ff, #a44eff);
      margin-bottom: 4px;
    }

    .logo_content {
      height: 300px;
      display: flex;
      align-items: center;
      justify-content: center;
      overflow: visible;
    }
    .ai_logo {
      display: block;
      height: 120%;
      margin-top: 90px;
      margin-right: -45px;
    }
    .aisearch_logo {
      display: block;
      height: 60%;
    }

    main {
      position: absolute;
      top: 340px;
      left: 15px;
      right: 15px;
      z-index: 2;
    }
    .star1 {
      position: absolute;
      top: -60px;
      right: 0;
      height: 55px;
    }
    .star2 {
      position: absolute;
      top: 450px;
      left: -14px;
      height: 18px;
    }
    .star3 {
      position: absolute;
      bottom: 5px;
      left: -15px;
      height: 50px;
      z-index: 20;
    }
    .main_inner {
      min-height: 500px;
      margin-bottom: 26px;
      padding: 20px;
      background: #fff;
      border-radius: 8px;
    }
    .desc {
      margin: 0 0 15px;
      color: #333;
      font: 400 13px/1.5 "Microsoft YaHei", "Microsoft YaHei", sans-serif;
      text-align: justify;
    }
    .desc b {
      color: #165dff;
      font-weight: 400;
    }

    .competitiveness_analysis_com_title {
      position: relative;
      margin: 0 0 10px;
      color: #333;
      font-size: 16px;
      line-height: 24px;
      font-weight: 600;
      font-style: italic;
    }
    .competitiveness_analysis_com_title .title-bg {
      position: absolute;
      bottom: 1px;
      left: 5px;
      width: 86px;
      height: 14px;
      object-fit: cover;
      z-index: 1;
    }
    .competitiveness_analysis_com_title .title {
      position: relative;
      z-index: 2;
    }

    .card_list2 {
      display: grid;
      grid-template-columns: repeat(4, minmax(0, 1fr));
      gap: 20px;
      margin-bottom: 20px;
    }
    .summary_card {
      height: 110px;
      display: flex;
      align-items: center;
      justify-content: flex-start;
      border-radius: 8px;
      background: linear-gradient(180deg, rgba(240, 243, 255, .96), rgba(255,255,255,.98));
      box-shadow: 0 12px 28px rgba(94, 117, 190, .08);
    }
    .summary_card .left {
      margin: 0 20px 0 30px;
    }
    .icon_wrap {
      width: 50px;
      height: 50px;
      display: flex;
      align-items: center;
      justify-content: center;
      border-radius: 50%;
      background: var(--icon-bg);
    }
    .icon_wrap .icon,
    .platform-icons img {
      display: block;
    }
    .icon_wrap .icon {
      width: 22px;
      height: 22px;
      object-fit: contain;
    }
    .summary_card .label {
      color: #333;
      font-size: 14px;
      line-height: 20px;
      font-weight: 400;
    }
    .summary_card .value {
      margin-top: 2px;
      color: #333;
      font-family: "D-DIN", "DIN Condensed", "Microsoft YaHei", sans-serif;
      font-size: 34px;
      line-height: 1;
      font-weight: 700;
    }
    .platform-icons {
      position: absolute;
      bottom: -23px;
      left: 0;
      display: flex;
      align-items: center;
      gap: 5px;
      white-space: nowrap;
    }
    .summary_card .right {
      position: relative;
    }
    .platform-icons i {
      width: 18px;
      height: 18px;
      display: inline-grid;
      place-items: center;
      margin-right: 5px;
      color: #fff;
      border-radius: 50%;
      font-size: 11px;
      font-style: normal;
      font-weight: 700;
      background: var(--c);
    }
    .model-logo {
      width: 22px;
      height: 22px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      flex: 0 0 auto;
      overflow: hidden;
      border-radius: 6px;
      background: #fff;
      box-shadow: 0 4px 12px rgba(60, 86, 170, .16);
      vertical-align: -6px;
    }
    .model-logo img {
      width: 100%;
      height: 100%;
      display: block;
      border-radius: inherit;
      object-fit: cover;
    }
    .model-name {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 7px;
      min-width: 0;
      white-space: nowrap;
    }
    .platform-icons .model-logo {
      width: 18px;
      height: 18px;
      border-radius: 50%;
      box-shadow: 0 3px 8px rgba(60, 86, 170, .12);
    }
    .ref_table_header .model-name,
    .ref_table_row .model-name {
      width: 100%;
    }

    .brand_intro2 {
      width: 100%;
      height: 320px;
      display: flex;
      gap: 100px;
      margin-bottom: 20px;
      padding: 25px 25px 25px;
      border-radius: 8px;
      background-color: #eff0ff;
      background-image: url("https://geo.zxaigc.com/assets/images/photo_bg-ee15c02b.png");
      background-size: 100% 100%;
      background-position: top;
    }
    .brand_intro2 .photo {
      width: 353px;
      height: 265px;
      display: block;
      border-radius: 2px;
      object-fit: cover;
    }
    .brand_intro2 .right {
      width: min(850px, calc(100% - 470px));
      max-width: 850px;
      padding-top: 25px;
    }
    .brand_intro2 .item {
      margin-bottom: 15px;
    }
    .brand_intro2 .text {
      margin-bottom: 5px;
      color: #666;
      font-size: 12px;
      line-height: 18px;
      font-weight: 400;
    }
    .brand_intro2 .content {
      color: #333;
      font-size: 14px;
      line-height: 21px;
      font-weight: 500;
      word-break: break-all;
      text-align: justify;
    }

    .aisearch_row {
      min-height: 337px;
      padding: 25px;
      border-radius: 8px;
      background: var(--soft);
      margin-bottom: 15px;
    }
    .aisearch_title {
      display: inline-block;
      color: transparent;
      background: linear-gradient(90deg, #386aff, #9265ff);
      -webkit-background-clip: text;
      background-clip: text;
      font-size: 18px;
      line-height: 27px;
      font-weight: 600;
      font-style: italic;
    }
    .overview_content {
      display: grid;
      grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
      gap: 30px;
      min-height: 260px;
      align-items: center;
    }
    .overview_left {
      position: relative;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 20px;
      min-height: 235px;
      border-right: 1px solid #ddd;
    }
    .donut {
      width: 230px;
      height: 230px;
      border-radius: 50%;
      background: conic-gradient(#5c79ff 0 61.64%, #dfe5ff 61.64% 100%);
      position: relative;
      box-shadow: 0 12px 24px rgba(87, 107, 180, .12);
    }
    .donut::after {
      content: "";
      position: absolute;
      inset: 48px;
      border-radius: 50%;
      background: var(--soft);
      box-shadow: inset 0 0 24px rgba(110, 136, 230, .12);
    }
    .donut .center {
      position: absolute;
      inset: 0;
      z-index: 1;
      display: grid;
      place-items: center;
      text-align: center;
    }
    .donut .number {
      display: block;
      color: #333;
      font-size: 30px;
      line-height: 1;
      font-weight: 700;
    }
    .donut .text {
      color: #666;
      font-size: 12px;
      line-height: 18px;
    }
    .donut_cards {
      display: flex;
      flex-direction: column;
      gap: 20px;
      width: 190px;
    }
    .donut_cards .card {
      min-width: 160px;
      padding: 5px 15px 8px;
      border-radius: 8px;
      background: #fff;
      box-shadow: 0 4px 10px #dde6ff;
      white-space: nowrap;
      position: relative;
    }
    .donut_cards .card::before {
      content: "";
      position: absolute;
      top: 50%;
      left: -8px;
      transform: translateY(-50%);
      border-top: 8px solid transparent;
      border-bottom: 8px solid transparent;
      border-right: 8px solid #fff;
    }
    .card_top {
      color: #5258ff;
      font-size: 22px;
      line-height: 1.1;
      font-weight: 700;
    }
    .card_bottom {
      color: rgba(51,51,51,.8);
      font-size: 13px;
      line-height: 20px;
      font-weight: 500;
    }
    .overview_right {
      display: grid;
      gap: 24px;
      padding: 0 20px;
    }
    .share_row {
      display: grid;
      grid-template-columns: 32px 1fr 70px;
      align-items: center;
      gap: 16px;
      color: #333;
      font-weight: 600;
    }
    .medal {
      width: 28px;
      height: 28px;
      display: grid;
      place-items: center;
      border-radius: 50%;
      color: #fff;
      background: var(--medal);
      font-style: normal;
      font-weight: 700;
    }
    .bar_track {
      height: 10px;
      border-radius: 10px;
      background: rgba(221,221,221,.58);
      overflow: hidden;
    }
    .bar_track span {
      display: block;
      height: 100%;
      width: var(--w);
      background: linear-gradient(90deg, #386aff, #8f64ff);
      border-radius: inherit;
    }
    .share_row .percent {
      color: #6669ff;
      font-weight: 700;
      text-align: right;
    }

    .data_analysis {
      min-height: 200px;
      padding: 20px;
      margin-bottom: 20px;
      overflow-x: auto;
      border-radius: 8px;
      background: var(--soft);
    }
    .data_analysis .text {
      margin-bottom: 10px;
      color: #333;
      font-size: 16px;
      line-height: 24px;
      font-weight: 500;
    }
    .ref_table {
      min-width: 1040px;
    }
    .platform_table { min-width: 0; width: 100%; }
    .ref_table_header,
    .ref_table_row {
      display: grid;
      align-items: center;
      column-gap: 10px;
    }
    .platform_table .ref_table_header,
    .platform_table .ref_table_row {
      grid-template-columns: 220px repeat(8, minmax(110px, 1fr));
    }
    .competitor_table .ref_table_header,
    .competitor_table .ref_table_row {
      grid-template-columns: 160px minmax(360px, 1.4fr) repeat(5, minmax(120px, 1fr));
    }
    .ref_table_header {
      height: 30px;
      margin-bottom: 15px;
      border-radius: 30px;
      background: var(--header);
    }
    .ref_table_header > div,
    .ref_table_row > div {
      display: flex;
      align-items: center;
      justify-content: center;
      min-width: 0;
      text-align: center;
      white-space: nowrap;
    }
    .ref_table_header > div {
      font-size: 13px;
      line-height: 20px;
      font-weight: 500;
    }
    .ref_table_row {
      min-height: 40px;
      margin-bottom: 6px;
      color: #333;
      font-size: 13px;
      line-height: 20px;
    }
    .ref_table_row:nth-child(even) {
      background: #f8f8ff;
    }
    .ref_table_row .tag {
      padding: 3px 14px;
      border-radius: 999px;
      color: #5d62f5;
      background: #dfe6ff;
      font-weight: 500;
    }
    .ref_table_row .name_cell {
      justify-content: flex-start;
      padding-left: 20px;
    }

    .analysis_chart {
      margin-bottom: 20px;
    }
    .chart_grid {
      display: grid;
      grid-template-columns: repeat(3, minmax(0, 1fr));
      gap: 20px;
    }
    .chart {
      min-height: 307px;
      position: relative;
      border-radius: 8px;
      border: 1px solid var(--line);
      background: #fff;
      overflow: hidden;
    }
    .chart .title {
      position: absolute;
      top: 10px;
      left: 10px;
      color: #333;
      font-size: 12px;
      line-height: 18px;
      font-weight: 500;
      z-index: 2;
    }
    .index_chart {
      height: 255px;
      margin: 42px 18px 0;
      display: grid;
      grid-template-columns: repeat(5, 1fr);
      align-items: end;
      gap: 16px;
      border-bottom: 1px solid #e2e6f5;
    }
    .index_col {
      height: 100%;
      display: grid;
      align-items: end;
      gap: 8px;
      text-align: center;
      color: #666;
      font-size: 12px;
    }
    .index_bar {
      width: 44px;
      height: var(--h);
      margin: 0 auto;
      border-radius: 7px 7px 0 0;
      background: linear-gradient(180deg, #6988ff, #3fd1e5);
    }
    .rate_list {
      display: grid;
      gap: 20px;
      padding: 58px 26px 0;
    }
    .rate_item {
      display: grid;
      grid-template-columns: 90px 1fr 68px;
      align-items: center;
      gap: 12px;
      color: #333;
      font-size: 13px;
      font-weight: 500;
    }
    .rate_track {
      height: 10px;
      overflow: hidden;
      border-radius: 10px;
      background: rgba(221,221,221,.58);
    }
    .rate_track span {
      display: block;
      width: var(--w);
      height: 100%;
      border-radius: inherit;
      background: linear-gradient(90deg, #386aff, #8f64ff);
    }
    .rate_item b {
      color: #6669ff;
      text-align: right;
    }

    .sentiment_grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 20px;
    }
    .score_chart,
    .chart2 {
      min-height: 356px;
      border-radius: 8px;
      background: var(--soft);
    }
    .score_chart {
      display: grid;
      place-items: center;
      text-align: center;
    }
    .score_chart b {
      display: block;
      color: #35b98d;
      font-size: 72px;
      line-height: 1;
      font-weight: 700;
    }
    .score_chart span {
      color: #666;
      font-size: 16px;
      line-height: 28px;
      font-weight: 500;
    }
    .chart2 .title2 {
      height: 60px;
      padding-left: 30px;
      color: #333;
      font: 700 16px/60px "Microsoft YaHei", "Microsoft YaHei", sans-serif;
    }
    .public-opinion-share-chart {
      height: 296px;
      overflow: auto;
      padding: 0 30px 18px;
    }
    .public-opinion-share-item {
      margin-bottom: 20px;
    }
    .public-opinion-share-item .top_content {
      display: flex;
      justify-content: space-between;
      margin-bottom: 5px;
    }
    .public-opinion-share-item .left {
      color: #333;
      font-size: 14px;
      font-weight: 500;
    }
    .public-opinion-share-item .right {
      color: #6669ff;
      font-size: 16px;
      font-weight: 700;
    }
    .public-opinion-share-item .bottom_content {
      height: 10px;
      overflow: hidden;
      border-radius: 10px;
      background: rgba(221,221,221,.58);
    }
    .public-opinion-share-item .bottom_content span {
      display: block;
      height: 100%;
      width: var(--w);
      border-radius: inherit;
      background: linear-gradient(90deg, #3fc88e, #67d9f8);
    }

    @media (max-width: 1280px) {
      body { overflow: auto; overflow-x: hidden; }
      .competitiveness_analysis_report_web {
        width: 100%;
        max-width: 100%;
        min-width: 0;
        height: auto;
        min-height: 100vh;
        overflow-x: hidden;
        background-size: auto 320px;
      }
      .logo_content {
        height: 220px;
        gap: 8px;
        padding: 8px 12px;
        overflow: hidden;
      }
      .ai_logo {
        width: min(46vw, 280px);
        height: auto;
        max-height: 220px;
        margin-top: 40px;
        margin-right: -10px;
        object-fit: contain;
      }
      .aisearch_logo {
        width: min(48vw, 320px);
        height: auto;
        max-height: 120px;
        object-fit: contain;
      }
      main {
        position: relative;
        top: auto;
        left: auto;
        right: auto;
        margin: -10px 12px 24px;
      }
      .main_inner {
        padding: 18px;
      }
      .card_list2 {
        grid-template-columns: repeat(2, minmax(0, 1fr));
      }
      .overview_content,
      .chart_grid,
      .sentiment_grid {
        grid-template-columns: 1fr;
      }
      .summary_card {
        height: auto;
        min-height: 110px;
        padding: 18px 16px;
      }
      .brand_intro2 {
        height: auto;
        flex-direction: column;
        gap: 18px;
        padding: 18px;
        background-size: cover;
      }
      .brand_intro2 .photo {
        width: min(353px, 100%);
        height: auto;
        aspect-ratio: 353 / 265;
      }
      .brand_intro2 .right {
        width: 100%;
        max-width: none;
        padding-top: 0;
      }
      .overview_content {
        min-height: 0;
        gap: 20px;
      }
      .overview_left,
      .overview_right {
        width: 100%;
        min-width: 0;
      }
      .overview_left {
        flex-wrap: wrap;
        border-right: none;
        border-bottom: 1px solid #ddd;
        padding-bottom: 22px;
      }
      .data_analysis { padding: 14px; }
      .platform_table { min-width: 900px; }
      .competitor_table { min-width: 860px; }
    }

    @media (max-width: 1100px) {
      header {
        height: auto;
        min-height: 80px;
        flex-direction: column;
        align-items: stretch;
        gap: 12px;
        padding: 12px 14px 0;
      }
      .logo {
        width: min(360px, 100%);
        height: auto;
        margin: 0;
      }
      .right_content {
        width: 100%;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 10px;
      }
      .report-menu {
        width: 220px;
      }
    }

    @media (max-width: 720px) {
      header {
        min-height: 0;
        padding: 10px 10px 0;
      }
      .logo {
        width: min(300px, 100%);
      }
      .right_content {
        flex-direction: column;
        align-items: stretch;
      }
      .company-meta,
      .report-menu {
        width: 100%;
      }
      .company-meta { flex: none; text-align: left; white-space: normal; }
      .report-menu summary {
        height: auto;
        min-height: 40px;
        padding: 8px 14px;
        font-size: 13px;
        text-align: center;
      }
      .report-menu-list {
        left: 0;
        right: 0;
        min-width: 0;
      }
      .logo_content {
        height: 170px;
        padding: 0 8px;
      }
      .ai_logo {
        width: 44%;
        max-height: 160px;
        margin-top: 30px;
        margin-right: -14px;
      }
      .aisearch_logo {
        width: 54%;
        max-height: 90px;
      }
      main {
        margin: 0 8px 18px;
      }
      .main_inner {
        padding: 12px;
      }
      .desc {
        font-size: 12px;
        line-height: 1.65;
      }
      .competitiveness_analysis_com_title {
        font-size: 15px;
      }
      .card_list2 {
        grid-template-columns: 1fr;
        gap: 12px;
      }
      .summary_card {
        min-height: 88px;
        padding: 14px;
      }
      .summary_card .left {
        margin: 0 14px 0 8px;
      }
      .summary_card .value {
        font-size: 30px;
      }
      .platform-icons {
        position: static;
        margin-top: 6px;
        white-space: normal;
      }
      .brand_intro2 {
        gap: 12px;
        padding: 14px;
      }
      .brand_intro2 .photo {
        width: 100%;
        max-height: 220px;
        object-fit: cover;
      }
      .aisearch_row {
        min-height: 0;
        padding: 14px;
      }
      .overview_left {
        min-height: 0;
        flex-direction: column;
        gap: 14px;
        padding-bottom: 18px;
      }
      .donut {
        width: min(220px, 72vw);
        height: min(220px, 72vw);
      }
      .donut::after {
        inset: 20%;
      }
      .donut_cards {
        width: 100%;
        flex-direction: row;
        flex-wrap: wrap;
        justify-content: center;
        gap: 10px;
      }
      .donut_cards .card {
        flex: 1 1 130px;
        min-width: 130px;
        white-space: normal;
      }
      .overview_right {
        gap: 14px;
        padding: 0;
      }
      .share_row {
        grid-template-columns: 28px minmax(0, 1fr) 58px;
        gap: 10px;
      }
      .data_analysis {
        padding: 12px;
      }
      .analysis_chart,
      .data_analysis {
        margin-bottom: 15px;
      }
      .chart_grid,
      .sentiment_grid {
        gap: 12px;
      }
      .chart {
        min-height: 260px;
      }
      .index_chart {
        height: 210px;
        margin: 42px 12px 0;
        gap: 8px;
      }
      .index_bar {
        width: clamp(24px, 8vw, 44px);
      }
      .rate_list {
        gap: 16px;
        padding: 52px 12px 0;
      }
      .rate_item {
        grid-template-columns: 72px minmax(0, 1fr) 56px;
        gap: 8px;
        font-size: 12px;
      }
      .score_chart,
      .chart2 {
        min-height: 240px;
      }
      .score_chart b {
        font-size: 54px;
      }
      .score_chart span {
        font-size: 14px;
      }
      .chart2 .title2 {
        height: 48px;
        padding-left: 18px;
        line-height: 48px;
      }
      .public-opinion-share-chart {
        height: auto;
        max-height: 280px;
        padding: 0 18px 16px;
      }
      .star1,
      .star2,
      .star3 {
        display: none;
      }
    }

    @media (max-width: 420px) {
      .logo_content {
        height: 140px;
      }
      .ai_logo {
        width: 42%;
        margin-top: 24px;
      }
      .aisearch_logo {
        width: 56%;
      }
      .main_inner,
      .data_analysis {
        padding: 10px;
      }
      .platform_table { min-width: 900px; }
      .competitor_table { min-width: 780px; }
      .share_row {
        grid-template-columns: 26px minmax(0, 1fr) 54px;
      }
    }

    /* Live-page module replica styles */
    .arco-row {
      display: flex;
      flex-flow: row wrap;
    }
    .arco-row-align-start { align-items: flex-start; }
    .arco-row-justify-start { justify-content: flex-start; }
    .arco-col {
      position: relative;
      min-height: 1px;
    }
    .arco-col-xs-24 {
      flex: 0 0 100%;
      max-width: 100%;
    }
    @media (min-width: 576px) {
      .arco-col-sm-12 {
        flex: 0 0 50%;
        max-width: 50%;
      }
    }
    @media (min-width: 768px) {
      .arco-col-md-6 {
        flex: 0 0 25%;
        max-width: 25%;
      }
    }

    .report-summary-row {
      margin-bottom: 20px !important;
    }
    .competitiveness_analysis_report_summary_card {
      height: 110px;
      display: flex;
      align-items: center;
      justify-content: flex-start;
      border-radius: 8px;
      background: linear-gradient(rgb(236, 237, 255) 0%, rgb(255, 255, 255) 100%);
      box-shadow: 0 12px 28px rgba(94, 117, 190, .08);
    }
    .competitiveness_analysis_report_summary_card .left {
      margin: 0 20px 0 30px;
    }
    .competitiveness_analysis_report_summary_card .icon_wrap {
      width: 50px;
      height: 50px;
      display: flex;
      align-items: center;
      justify-content: center;
      border-radius: 50%;
      background: var(--icon-bg);
    }
    .competitiveness_analysis_report_summary_card .summary-icon {
      width: 28px;
      height: 28px;
      color: #fff;
      display: block;
    }
    .competitiveness_analysis_report_summary_card .icon {
      width: 22px;
      height: 22px;
      display: block;
      object-fit: contain;
    }
    .competitiveness_analysis_report_summary_card .right {
      position: relative;
    }
    .competitiveness_analysis_report_summary_card .label {
      color: #333;
      font: 400 14px/20px "PingFang SC", "Microsoft YaHei", sans-serif;
    }
    .competitiveness_analysis_report_summary_card .value {
      margin-top: 2px;
      color: #333;
      font-family: "D-DIN", "DIN Condensed", "Microsoft YaHei", sans-serif;
      font-size: 28px;
      line-height: 1;
      font-weight: 700;
    }
    .competitiveness_analysis_report_summary_card .platform-icons {
      position: absolute;
      bottom: -23px;
      left: 0;
    }

    .aisearch_row {
      min-height: 337px;
      padding: 25px;
      border-radius: 8px;
      background: #eff0ff;
      margin-bottom: 20px;
      box-sizing: border-box;
    }
    .aisearch_row .aisearch_title {
      display: inline-block;
      color: transparent;
      background: linear-gradient(90deg, #386aff, #9265ff);
      -webkit-background-clip: text;
      background-clip: text;
      font-size: 18px;
      font-weight: 600;
      font-style: italic;
    }
    .aisearch_left {
      margin-top: 20px;
      display: flex;
      align-items: center;
      justify-content: center;
    }
    .aisearch_left_line {
      position: relative;
    }
    .aisearch_left_line::before {
      content: "";
      position: absolute;
      top: 50%;
      right: 40px;
      width: 1px;
      height: 115%;
      transform: translateY(-50%);
      background-color: #ddd;
    }
    .aisearch_left_inner {
      display: flex;
      gap: 20px;
      box-sizing: border-box;
    }
    .aisearch_left_inner .left {
      position: relative;
    }
    .aisearch_left_inner .left img {
      height: 230px;
      display: block;
    }
    .aisearch_left_inner .left .text {
      position: absolute;
      top: 50%;
      left: 50%;
      transform: translate(-50%, -50%);
      color: #386aff;
      font: 700 18px/1 "D-DIN", "DIN Condensed", "Microsoft YaHei", sans-serif;
    }
    .right_card {
      width: 100%;
      margin-bottom: 20px;
      display: flex;
      flex-direction: column;
      justify-content: center;
      gap: 20px;
      box-sizing: border-box;
    }
    .right_card .card {
      width: 100%;
      min-width: 160px;
      margin-bottom: 15px;
      padding: 5px 15px 8px;
      position: relative;
      border-radius: 8px;
      background: #fff;
      box-shadow: 0 4px 10px #dde6ff;
      white-space: nowrap;
      box-sizing: border-box;
    }
    .right_card .card:last-child { margin-bottom: 0; }
    .right_card .card2::before {
      content: "";
      position: absolute;
      top: 50%;
      left: -8px;
      transform: translateY(-50%);
      border-top: 8px solid transparent;
      border-bottom: 8px solid transparent;
      border-right: 8px solid #fff;
    }
    .right_card .card_top {
      margin-bottom: 2px;
      color: #5258ff;
      font-family: "D-DIN", "DIN Condensed", "Microsoft YaHei", sans-serif;
      font-weight: 700;
    }
    .right_card .card_bottom {
      color: rgba(51,51,51,.8);
      font-weight: 500;
    }
    .aisearch_right {
      padding: 0 0 0 20px;
    }
    .aisearch_right_item {
      margin-bottom: 30px;
    }
    .aisearch_right_item:last-child {
      margin-bottom: 0;
    }
    .ai-data-overview-progress {
      height: 28px;
      position: relative;
      border-radius: 88px;
      background: #dedff7;
      color: #fff;
      font-family: "D-DIN", "DIN Condensed", "Microsoft YaHei", sans-serif;
      font-size: 16px;
      font-weight: 600;
      box-sizing: border-box;
    }
    .ai-data-overview-progress .icon {
      position: absolute;
      top: 50%;
      left: -15px;
      height: 120%;
      z-index: 10;
      transform: translateY(-50%);
    }
    .ai-data-overview-progress .progress {
      position: absolute;
      top: 0;
      left: 0;
      height: 100%;
      display: flex;
      align-items: center;
      justify-content: flex-end;
      border-radius: 88px;
      box-sizing: border-box;
    }
    .ai-data-overview-progress span {
      position: absolute;
      top: -55%;
      right: 5px;
      z-index: 11;
      color: #5258ff;
      transform: translateY(-50%);
      font-size: 15px;
    }

    .data_analysis {
      background-color: #eff0ff;
    }
    .ref_table_header,
    .ref_table_row {
      gap: 10px;
    }
    .ref_table_header {
      height: 30px;
      background: #d0dcff;
      border-radius: 30px;
    }
    .ref_table_row {
      margin-bottom: 6px;
      min-height: 40px;
    }
    .ref_table_row:nth-child(even) {
      background: transparent;
    }
    .ref_table_row > div {
      min-height: 40px;
      background: #fff;
      color: #333;
      font-family: "PingFang SC", "Microsoft YaHei", sans-serif;
    }
    .ref_table_row > div.heat-45,
    .ref_table_row > div.heat-50,
    .ref_table_row > div.heat-60,
    .ref_table_row > div.heat-65 {
      color: #fff;
    }
    .heat-45 { background-color: rgba(110, 113, 252, .45) !important; }
    .heat-50 { background-color: rgba(110, 113, 252, .5) !important; }
    .heat-60 { background-color: rgba(110, 113, 252, .6) !important; }
    .heat-65 { background-color: rgba(110, 113, 252, .65) !important; }
    .ref_table_row .tag {
      width: 100%;
      height: 100%;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 0;
      border-radius: 0;
      color: inherit;
      background: transparent;
    }

    .analysis_chart {
      margin-bottom: 20px;
    }
    .chart_grid {
      display: grid;
      grid-template-columns: repeat(3, minmax(0, 1fr));
      gap: 20px;
    }
    .chart {
      min-height: 307px;
      height: 307px;
      position: relative;
      overflow: hidden;
      border-radius: 8px;
      border: 1px solid #ddd;
      background: #fff;
    }
    .chart .title {
      position: absolute;
      top: 10px;
      left: 10px;
      color: #333;
      font: 500 12px/18px "PingFang SC", "Microsoft YaHei", sans-serif;
      z-index: 2;
    }
    .chart svg {
      width: 100%;
      height: 100%;
      display: block;
    }
    .radar-svg text,
    .bar-svg text,
    .source-donut-card text {
      fill: #333;
      font-family: "PingFang SC", "Microsoft YaHei", sans-serif;
      font-size: 12px;
    }
    .axis-label { fill: #666 !important; }
    .bar-fill {
      fill: #6f70ef;
    }
    .chart-legend {
      position: absolute;
      left: 0;
      right: 0;
      bottom: 14px;
      display: flex;
      justify-content: center;
      gap: 16px;
      color: #666;
      font-size: 12px;
      white-space: nowrap;
    }
    .legend-dot {
      width: 9px;
      height: 9px;
      display: inline-block;
      margin-right: 5px;
      border-radius: 50%;
      background: var(--dot);
      vertical-align: 0;
    }
    .donut-chart {
      width: 190px;
      height: 190px;
      margin: 60px auto 0;
      position: relative;
      border-radius: 50%;
      background: conic-gradient(var(--segments));
    }
    .donut-chart::after {
      content: "";
      position: absolute;
      inset: 46px;
      border-radius: 50%;
      background: #fff;
    }
    .source-label {
      position: absolute;
      color: #333;
      font-size: 12px;
      white-space: nowrap;
    }
    .source-label.l1 { right: 32px; top: 132px; }
    .source-label.l2 { left: 70px; top: 215px; }
    .source-label.l3 { left: 42px; top: 152px; }
    .source-label.l4 { left: 120px; top: 78px; }
    .source-label.l5 { right: 98px; top: 63px; }

    .sentiment_grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 20px;
    }
    .score_chart,
    .chart2 {
      min-height: 356px;
      border-radius: 8px;
      background: #eff0ff;
    }
    .score_chart {
      position: relative;
      display: flex;
      align-items: center;
      justify-content: center;
    }
    .sentiment-donut {
      width: 220px;
      height: 220px;
      position: relative;
      border-radius: 50%;
      background:
        conic-gradient(
          #6669ff 0 91.45%,
          rgba(236, 248, 255, .9) 91.45% 92%,
          #ff9245 92% 99.45%,
          rgba(236, 248, 255, .9) 99.45% 100%
        );
    }
    .sentiment-donut::after {
      content: "";
      position: absolute;
      inset: 62px;
      border-radius: 50%;
      background: #eff0ff;
    }
    .sentiment-donut .center {
      position: absolute;
      inset: 0;
      z-index: 1;
      display: grid;
      place-items: center;
      color: #333;
      text-align: center;
    }
    .sentiment-donut b {
      display: block;
      color: #333;
      font-size: 30px;
      line-height: 1.1;
    }
    .sentiment-donut span {
      color: #666;
      font-size: 12px;
      line-height: 20px;
    }
    .score_chart .chart-legend {
      bottom: 26px;
    }
    .chart2 {
      height: 356px;
    }
    .chart2 .title2 {
      height: 60px;
      padding-left: 30px;
      color: #333;
      font: 700 16px/60px "Microsoft YaHei", "Microsoft YaHei", sans-serif;
    }
    .public-opinion-share-chart {
      height: 296px;
      overflow: auto;
      padding: 0 50px;
      box-sizing: border-box;
    }
    .public-opinion-share-item {
      margin-bottom: 20px;
    }
    .public-opinion-share-item .top_content {
      display: flex;
      justify-content: space-between;
      margin-bottom: 5px;
    }
    .public-opinion-share-item .left {
      display: flex;
      align-items: center;
      color: #333;
      font-size: 14px;
      font-weight: 500;
    }
    .public-opinion-share-item .left .model-logo {
      width: 17px;
      height: 17px;
      margin-right: 8px;
      border-radius: 50%;
      box-shadow: none;
    }
    .public-opinion-share-item .right {
      color: #6669ff;
      font: 700 16px/1.5 "Microsoft YaHei", "Microsoft YaHei", sans-serif;
    }
    .public-opinion-share-item .bottom_content {
      height: 10px;
      display: flex;
      overflow: hidden;
      border-radius: 30px;
      background-color: rgba(221, 221, 221, .58);
    }
    .public-opinion-share-item .bottom_content div {
      height: 100%;
    }
    .front { background-color: #6669ff; }
    .neutral { background-color: #ff9245; }
    .back { background-color: #f53f3f; }

    @media (max-width: 1280px) {
      .aisearch_left_line::before {
        display: none;
      }
      .chart_grid,
      .sentiment_grid {
        grid-template-columns: 1fr;
      }
      .chart {
        height: auto;
        min-height: 307px;
      }
    }
    @media (max-width: 720px) {
      .competitiveness_analysis_report_summary_card {
        min-height: 88px;
        height: auto;
        padding: 14px;
      }
      .competitiveness_analysis_report_summary_card .left {
        margin: 0 14px 0 8px;
      }
      .competitiveness_analysis_report_summary_card .platform-icons {
        position: static;
        margin-top: 6px;
      }
      .aisearch_row {
        min-height: 0;
        padding: 14px;
      }
      .aisearch_left_inner {
        flex-direction: column;
        align-items: center;
      }
      .aisearch_right {
        padding: 0;
      }
      .public-opinion-share-chart {
        padding: 0 18px 16px;
      }
    }

    /* Code-built industry report dark sci-fi skin based on the reference image */
    :root {
      --industry-bg: #010915;
      --industry-panel: rgba(3, 23, 50, .78);
      --industry-panel-2: rgba(4, 32, 69, .66);
      --industry-cyan: #00d7ff;
      --industry-blue: #198cff;
      --industry-line: rgba(0, 194, 255, .68);
      --industry-line-soft: rgba(29, 155, 255, .34);
      --industry-text: #ecf8ff;
      --industry-muted: #a9c3df;
      --industry-purple: #7b5cff;
      --industry-orange: #ff9a3c;
      --industry-hero-bg-width: max(1780px, 100vw);
    }
    html, body {
      overflow-x: hidden;
      background:
        radial-gradient(circle at 50% 0%, rgba(0, 142, 255, .24), transparent 42%),
        linear-gradient(180deg, #020a16 0%, #031426 45%, #010813 100%);
      color: var(--industry-text);
    }
    body {
      overflow: auto;
    }
    .competitiveness_analysis_report_web {
      width: 100%;
      max-width: none;
      height: auto;
      min-height: 100vh;
      margin: 0;
      overflow: visible;
      background-color: var(--industry-bg);
      background-image:
        linear-gradient(180deg, rgba(1, 12, 32, .04) 0%, rgba(1, 10, 28, .18) 38%, rgba(0, 6, 18, .38) 100%),
        url("assets/backgrounds/enterprise-space-bg.png"),
        linear-gradient(90deg, #020817, #061933 48%, #020817);
      background-size: auto, var(--industry-hero-bg-width) auto, 100% 100%;
      background-position: top center, top center, top center;
      background-repeat: no-repeat;
      box-shadow: inset 0 0 42px rgba(0, 126, 255, .18);
    }
    .competitiveness_analysis_report_web::before,
    .competitiveness_analysis_report_web::after {
      content: "";
      position: fixed;
      inset: 0;
      z-index: -1;
      pointer-events: none;
    }
    .competitiveness_analysis_report_web::before {
      background:
        radial-gradient(circle at 15% 12%, rgba(15, 146, 255, .24), transparent 24%),
        radial-gradient(circle at 86% 8%, rgba(95, 85, 255, .18), transparent 28%);
    }
    .competitiveness_analysis_report_web::after {
      opacity: .18;
      background-image:
        linear-gradient(rgba(49, 141, 255, .2) 1px, transparent 1px),
        linear-gradient(90deg, rgba(49, 141, 255, .16) 1px, transparent 1px);
      background-size: 42px 42px;
      mask-image: linear-gradient(180deg, transparent, #000 18%, #000 82%, transparent);
    }
    header {
      position: absolute;
      top: 0;
      left: 0;
      right: 0;
      height: 58px;
      min-height: 0;
      padding: 0;
      z-index: 20;
      pointer-events: none;
    }
    .logo {
      display: none;
    }
    .right_content {
      position: absolute;
      top: 16px;
      right: 20px;
      gap: 10px;
      pointer-events: auto;
    }
    .company-meta {
      display: none;
    }
    .report-menu {
      width: 150px;
      min-width: 150px;
      flex-basis: 150px;
      font-size: 12px;
    }
    .report-menu summary {
      height: 28px;
      min-height: 28px;
      padding: 0 14px;
      color: transparent;
      background: transparent;
      border: 0;
      box-shadow: none;
    }
    .report-menu summary::after {
      opacity: 0;
    }
    .report-menu-list {
      top: calc(100% + 8px);
      right: 0;
      min-width: 182px;
      padding: 8px;
      border: 1px solid rgba(0, 207, 255, .45);
      border-radius: 3px;
      background: rgba(4, 19, 41, .96);
      box-shadow: 0 12px 34px rgba(0, 194, 255, .28), inset 0 0 18px rgba(19, 122, 255, .18);
      backdrop-filter: blur(10px);
    }
    .report-menu-list a,
    .report-menu-list span {
      padding: 8px 10px;
      border-radius: 2px;
      color: #d8f4ff;
      font-weight: 500;
      font-size: 13px;
    }
    .report-menu-list span,
    .report-menu-list a:hover {
      color: #fff;
      background: linear-gradient(90deg, rgba(0, 119, 255, .72), rgba(0, 216, 255, .32));
      box-shadow: inset 0 0 14px rgba(0, 222, 255, .22);
    }
    .logo_content {
      height: 250px;
      display: block;
      overflow: hidden;
    }
    .logo_content img,
    .star1,
    .star2,
    .star3 {
      display: none;
    }
    main {
      position: relative;
      inset: auto;
      width: 100%;
      margin: -46px auto 0;
      padding: 0 8px 32px;
      z-index: 3;
    }
    .main_inner {
      min-height: 0;
      margin: 0;
      padding: 0 0 18px;
      border-radius: 0;
      background: transparent;
      color: var(--industry-text);
    }
    .main_inner::after {
      display: none;
    }
    .desc {
      min-height: 46px;
      margin: 0 0 4px;
      padding: 10px 14px 8px;
      border-top: 1px solid rgba(0, 151, 255, .38);
      border-bottom: 1px solid rgba(0, 151, 255, .32);
      color: #c7daf2;
      font-size: 12px;
      line-height: 1.45;
      background: linear-gradient(90deg, rgba(0, 44, 89, .6), rgba(2, 23, 50, .7), rgba(0, 44, 89, .42));
      box-shadow: inset 0 0 16px rgba(0, 174, 255, .12);
    }
    .desc span {
      float: right;
      margin-left: 20px;
      color: #00dfff !important;
      white-space: nowrap;
    }
    .main_inner > section {
      position: relative;
      margin: 0 0 10px;
      padding: 36px 14px 12px;
      border: 1px solid var(--industry-line);
      border-radius: 2px;
      background:
        linear-gradient(135deg, rgba(3, 36, 75, .74), rgba(2, 16, 38, .88) 46%, rgba(3, 33, 69, .72)),
        radial-gradient(circle at 86% 18%, rgba(0, 163, 255, .16), transparent 22%);
      box-shadow:
        inset 0 0 28px rgba(0, 127, 255, .14),
        0 0 16px rgba(0, 165, 255, .1);
      overflow: hidden;
    }
    .main_inner > section::before,
    .main_inner > section::after {
      content: "";
      position: absolute;
      pointer-events: none;
    }
    .main_inner > section::before {
      inset: 0;
      background-image:
        linear-gradient(rgba(53, 169, 255, .06) 1px, transparent 1px),
        linear-gradient(90deg, rgba(53, 169, 255, .05) 1px, transparent 1px);
      background-size: 18px 18px;
      opacity: .55;
    }
    .main_inner > section::after {
      right: -1px;
      top: -1px;
      width: 34px;
      height: 34px;
      border-top: 2px solid #00e7ff;
      border-right: 2px solid #00e7ff;
      filter: drop-shadow(0 0 8px rgba(0, 223, 255, .8));
    }
    .competitiveness_analysis_com_title {
      position: absolute;
      top: 0;
      left: 0;
      z-index: 2;
      width: 280px;
      height: 32px;
      margin: 0;
      padding: 5px 16px 0 15px;
      color: #fff;
      font-size: 16px;
      line-height: 24px;
      font-weight: 800;
      font-style: italic;
      text-shadow: 0 0 8px rgba(0, 212, 255, .65);
    }
    .competitiveness_analysis_com_title .title-bg {
      display: none;
    }
    .competitiveness_analysis_com_title::before {
      content: "";
      position: absolute;
      inset: 0;
      z-index: -1;
      background:
        linear-gradient(90deg, rgba(0, 119, 255, .7), rgba(1, 31, 73, .9) 52%, transparent 100%);
      clip-path: polygon(0 0, 100% 0, 86% 100%, 0 100%);
      border-bottom: 1px solid rgba(0, 232, 255, .9);
      box-shadow: 0 0 14px rgba(0, 192, 255, .48);
    }
    .competitiveness_analysis_com_title::after {
      content: "";
      position: absolute;
      left: 190px;
      top: 14px;
      width: 86px;
      height: 8px;
      background: repeating-linear-gradient(115deg, rgba(0, 220, 255, .85) 0 4px, transparent 4px 8px);
      opacity: .65;
    }
    .report-summary-row {
      position: relative;
      z-index: 1;
      margin-bottom: 0 !important;
    }
    .competitiveness_analysis_report_summary_card {
      height: 90px;
      border: 1px solid rgba(0, 170, 255, .58);
      border-radius: 2px;
      background:
        linear-gradient(90deg, rgba(8, 44, 86, .82), rgba(6, 22, 50, .7)),
        radial-gradient(circle at 0 100%, rgba(0, 148, 255, .22), transparent 42%);
      box-shadow: inset 0 0 18px rgba(0, 146, 255, .15), 0 0 12px rgba(0, 130, 255, .08);
    }
    .report-summary-row .arco-col:nth-child(2) .competitiveness_analysis_report_summary_card {
      border-color: rgba(150, 70, 255, .78);
      box-shadow: inset 0 0 18px rgba(142, 75, 255, .16), 0 0 12px rgba(142, 75, 255, .1);
    }
    .report-summary-row .arco-col:nth-child(3) .competitiveness_analysis_report_summary_card {
      border-color: rgba(44, 225, 210, .68);
      box-shadow: inset 0 0 18px rgba(44, 225, 210, .14), 0 0 12px rgba(44, 225, 210, .08);
    }
    .report-summary-row .arco-col:nth-child(4) .competitiveness_analysis_report_summary_card {
      border-color: rgba(255, 153, 52, .72);
      box-shadow: inset 0 0 18px rgba(255, 153, 52, .12), 0 0 12px rgba(255, 153, 52, .08);
    }
    .competitiveness_analysis_report_summary_card .left {
      margin: 0 22px 0 26px;
    }
    .competitiveness_analysis_report_summary_card .icon_wrap,
    .icon_wrap {
      width: 54px;
      height: 54px;
      border-radius: 14px;
      background:
        radial-gradient(circle at 50% 45%, rgba(255,255,255,.18), transparent 32%),
        linear-gradient(135deg, color-mix(in srgb, var(--icon-bg) 85%, #fff 15%), rgba(7, 30, 66, .3));
      border: 1px solid color-mix(in srgb, var(--icon-bg) 65%, #fff 35%);
      box-shadow: 0 0 18px color-mix(in srgb, var(--icon-bg) 54%, transparent), inset 0 0 18px rgba(255,255,255,.08);
      clip-path: polygon(50% 0, 92% 24%, 92% 76%, 50% 100%, 8% 76%, 8% 24%);
    }
    .competitiveness_analysis_report_summary_card .label {
      color: #b8d4ef;
      font-size: 13px;
      line-height: 19px;
    }
    .competitiveness_analysis_report_summary_card .value {
      color: #f5fbff;
      font-size: 28px;
      font-weight: 900;
      letter-spacing: 1px;
      text-shadow: 0 0 10px rgba(68, 202, 255, .28);
    }
    .model-logo {
      background: rgba(255,255,255,.92);
      box-shadow: 0 0 10px rgba(0, 203, 255, .25);
    }
    .brand_intro2 {
      position: relative;
      z-index: 1;
      height: 174px;
      min-height: 174px;
      display: block;
      margin: 0;
      padding: 28px 250px 18px 340px;
      border-radius: 0;
      overflow: hidden;
      background:
        linear-gradient(90deg, rgba(2, 14, 36, .9) 0%, rgba(3, 23, 54, .78) 36%, rgba(3, 20, 48, .45) 70%, rgba(2, 12, 30, .74) 100%),
        linear-gradient(180deg, rgba(0, 40, 88, .34), rgba(0, 9, 28, .76)),
        url("assets/industry-report/brand-profile-bg.jpg") center center / cover no-repeat;
    }
    .brand_intro2::after {
      content: "";
      position: absolute;
      top: 12px;
      right: 18px;
      width: 245px;
      height: 170px;
      border-radius: 50%;
      background:
        radial-gradient(circle at 50% 48%, rgba(0, 220, 255, .22), transparent 34%),
        repeating-radial-gradient(circle at 50% 50%, rgba(33, 180, 255, .34) 0 1px, transparent 1px 13px),
        repeating-conic-gradient(from 20deg, rgba(0, 191, 255, .24) 0 7deg, transparent 7deg 18deg);
      filter: drop-shadow(0 0 18px rgba(0, 193, 255, .35));
      mask-image: radial-gradient(circle at 50% 50%, #000 0 58%, transparent 72%);
      opacity: .82;
      pointer-events: none;
    }
    .brand_intro2 .left {
      position: absolute;
      top: 30px;
      left: 18px;
      z-index: 1;
    }
    .brand_intro2 .photo {
      width: 307px;
      height: 137px;
      border: 1px solid rgba(0, 190, 255, .72);
      border-radius: 2px;
      object-fit: cover;
      box-shadow: 0 0 18px rgba(0, 166, 255, .24);
    }
    .brand_intro2 .right {
      position: relative;
      z-index: 1;
      width: 100%;
      max-width: none;
      padding-top: 8px;
    }
    .brand_intro2 .item {
      margin-bottom: 14px;
    }
    .brand_intro2 .text {
      display: inline;
      margin: 0;
      color: #a8c6e4;
      font-size: 13px;
      line-height: 20px;
      font-weight: 700;
    }
    .brand_intro2 .content {
      display: inline;
      color: #eef8ff;
      font-size: 13px;
      line-height: 20px;
      font-weight: 500;
      text-shadow: 0 0 8px rgba(0, 173, 255, .16);
    }
    .aisearch_row,
    .data_analysis,
    .chart,
    .score_chart,
    .chart2 {
      position: relative;
      z-index: 1;
      border: 1px solid rgba(0, 174, 255, .38);
      border-radius: 2px;
      background:
        linear-gradient(180deg, rgba(4, 34, 70, .66), rgba(2, 18, 41, .8)),
        radial-gradient(circle at 50% 0%, rgba(0, 154, 255, .16), transparent 42%);
      box-shadow: inset 0 0 20px rgba(0, 137, 255, .14);
    }
    .aisearch_row {
      min-height: 170px;
      padding: 12px 18px 14px;
      margin: 0;
    }
    .aisearch_row .aisearch_title {
      display: none;
    }
    .aisearch_left {
      margin-top: 0;
    }
    .aisearch_left_line::before {
      right: -12px;
      background: rgba(0, 184, 255, .54);
      box-shadow: 0 0 10px rgba(0, 211, 255, .6);
    }
    .aisearch_left_inner {
      gap: 18px;
      align-items: center;
    }
    .aisearch_left_inner .left img {
      height: 130px;
      filter: saturate(1.35) drop-shadow(0 0 16px rgba(0, 209, 255, .32));
    }
    .aisearch_left_inner .left .text {
      color: #70ecff;
      font-size: 18px;
      text-shadow: 0 0 12px rgba(0, 229, 255, .75);
    }
    .right_card {
      gap: 12px;
      margin: 0;
      width: 150px;
    }
    .right_card .card {
      min-width: 150px;
      margin: 0;
      padding: 8px 12px;
      border: 1px solid rgba(0, 178, 255, .46);
      border-radius: 2px;
      background: rgba(4, 31, 68, .78);
      box-shadow: inset 0 0 12px rgba(0, 170, 255, .16);
    }
    .right_card .card2::before {
      border-right-color: rgba(0, 178, 255, .46);
    }
    .right_card .card_top {
      color: #35dcff;
      text-shadow: 0 0 10px rgba(0, 212, 255, .42);
    }
    .right_card .card_bottom {
      color: #c2ddf4;
    }
    .aisearch_right {
      padding: 4px 12px 0 28px;
    }
    .aisearch_right_item {
      margin-bottom: 13px;
    }
    .ai-data-overview-progress {
      height: 13px;
      background: rgba(28, 62, 113, .72);
      box-shadow: inset 0 0 9px rgba(0, 13, 30, .72);
    }
    .ai-data-overview-progress .icon {
      left: -28px;
      height: 28px;
      filter: drop-shadow(0 0 8px rgba(0, 203, 255, .26));
    }
    .ai-data-overview-progress .progress {
      box-shadow: 0 0 12px currentColor;
    }
    .ai-data-overview-progress span {
      top: 50%;
      right: -48px;
      color: #43dfff;
      font-size: 12px;
      text-shadow: 0 0 8px rgba(0, 224, 255, .36);
    }
    .data_analysis {
      min-height: 0;
      margin: 0 0 8px;
      padding: 10px 12px;
      overflow-x: auto;
    }
    .data_analysis .text {
      color: #c9def5;
      font-size: 13px;
      line-height: 20px;
      margin-bottom: 8px;
    }
    .ref_table_header {
      height: 28px;
      margin-bottom: 0;
      border-radius: 6px 6px 0 0;
      background: rgba(20, 86, 153, .45);
      border: 1px solid rgba(0, 176, 255, .28);
      color: #c8e7ff;
    }
    .ref_table_header > div {
      color: #c8e7ff;
      font-size: 12px;
      font-weight: 600;
    }
    .ref_table_row {
      min-height: 26px;
      margin-bottom: 0;
      color: #e8f6ff;
      border-left: 1px solid rgba(0, 176, 255, .2);
      border-right: 1px solid rgba(0, 176, 255, .2);
    }
    .ref_table_row > div {
      min-height: 26px;
      color: #e8f6ff;
      font-size: 12px;
      background: rgba(3, 22, 49, .5);
      border-right: 1px solid rgba(0, 156, 255, .22);
      border-bottom: 1px solid rgba(0, 156, 255, .22);
    }
    .ref_table_row:nth-child(even) > div {
      background: rgba(5, 34, 72, .54);
    }
    .ref_table_row .tag,
    .ref_table_row .name_cell {
      color: #dbefff;
    }
    .heat-45 { background-color: rgba(99, 84, 255, .45) !important; }
    .heat-50 { background-color: rgba(99, 84, 255, .56) !important; }
    .heat-60 { background-color: rgba(99, 84, 255, .66) !important; }
    .heat-65 { background-color: rgba(99, 84, 255, .76) !important; }
    .analysis_chart {
      margin-bottom: 0;
    }
    .chart_grid {
      gap: 8px;
    }
    .chart {
      height: 154px;
      min-height: 154px;
    }
    .chart .title,
    .chart2 .title2 {
      color: #d8efff;
      text-shadow: 0 0 8px rgba(0, 195, 255, .28);
    }
    .chart .title {
      top: 7px;
      left: 9px;
      font-size: 12px;
    }
    .chart svg {
      transform: scale(.62);
      transform-origin: 50% 0;
    }
    .radar-svg polygon,
    .radar-svg line {
      stroke: rgba(71, 171, 255, .42);
    }
    .radar-svg text,
    .bar-svg text,
    .axis-label {
      fill: #b8d6ef !important;
    }
    .bar-svg line {
      stroke: rgba(80, 170, 255, .28);
    }
    .bar-fill {
      fill: #685cff;
      filter: drop-shadow(0 0 6px rgba(104, 92, 255, .55));
    }
    .donut-chart {
      width: 88px;
      height: 88px;
      margin: 38px auto 0;
      box-shadow: 0 0 18px rgba(69, 118, 255, .32);
    }
    .donut-chart::after {
      inset: 28px;
      background: #061a38;
    }
    .source-label,
    .chart-legend {
      color: #a9c8e9;
      font-size: 10px;
    }
    .source-label.l1 { right: 14px; top: 55px; }
    .source-label.l2 { left: 46px; top: 112px; }
    .source-label.l3 { left: 14px; top: 80px; }
    .source-label.l4 { left: 80px; top: 36px; }
    .source-label.l5 { right: 62px; top: 32px; }
    .chart-legend {
      bottom: 8px;
      gap: 8px;
    }
    .sentiment_grid {
      gap: 20px;
    }
    .score_chart,
    .chart2 {
      height: 170px;
      min-height: 170px;
    }
    .sentiment-donut {
      width: 110px;
      height: 110px;
      box-shadow: 0 0 22px rgba(104, 93, 255, .35);
    }
    .sentiment-donut::after {
      inset: 30px;
      background: #061a38;
    }
    .sentiment-donut .center {
      color: #fff;
    }
    .sentiment-donut b {
      color: #fff;
      font-size: 24px;
      text-shadow: 0 0 10px rgba(80, 195, 255, .38);
    }
    .sentiment-donut span {
      color: #c7def5;
      font-size: 12px;
    }
    .score_chart .chart-legend {
      bottom: 14px;
    }
    .chart2 .title2 {
      height: 38px;
      padding-left: 18px;
      font-size: 13px;
      line-height: 38px;
    }
    .public-opinion-share-chart {
      height: 128px;
      padding: 0 44px 10px 26px;
      overflow: hidden;
    }
    .public-opinion-share-item {
      margin-bottom: 9px;
    }
    .public-opinion-share-item .left,
    .public-opinion-share-item .right {
      color: #dcefff;
      font-size: 12px;
    }
    .public-opinion-share-item .right {
      color: #4de4ff;
      font-size: 12px;
    }
    .public-opinion-share-item .bottom_content {
      height: 8px;
      background: rgba(28, 62, 113, .72);
      box-shadow: inset 0 0 8px rgba(0, 13, 30, .7);
    }
    .front { background: linear-gradient(90deg, #6b5cff, #8a73ff); }
    .neutral { background: #ff9a3c; }
    .back { background: #ff5252; }

    /* Full-width readable scale pass */
    main {
      padding: 0 14px 36px;
    }
    .main_inner > section {
      margin-bottom: 14px;
      padding: 44px 18px 16px;
    }
    .desc {
      min-height: 54px;
      padding: 13px 18px 11px;
      font-size: 14px;
      line-height: 1.55;
    }
    .competitiveness_analysis_com_title {
      width: 330px;
      height: 38px;
      padding: 7px 18px 0;
      font-size: 19px;
      line-height: 28px;
    }
    .competitiveness_analysis_com_title::after {
      left: 230px;
      top: 17px;
      width: 88px;
    }
    .competitiveness_analysis_report_summary_card {
      height: 112px;
    }
    .competitiveness_analysis_report_summary_card .icon_wrap,
    .icon_wrap {
      width: 64px;
      height: 64px;
    }
    .competitiveness_analysis_report_summary_card .icon {
      width: 28px;
      height: 28px;
    }
    .competitiveness_analysis_report_summary_card .label {
      font-size: 15px;
      line-height: 22px;
    }
    .competitiveness_analysis_report_summary_card .value {
      font-size: 38px;
    }
    .platform-icons .model-logo {
      width: 21px;
      height: 21px;
    }
    .brand_intro2 {
      height: 224px;
      min-height: 224px;
      padding: 34px 300px 22px 390px;
    }
    .brand_intro2 .left {
      top: 36px;
      left: 22px;
    }
    .brand_intro2 .photo {
      width: 350px;
      height: 156px;
    }
    .brand_intro2::after {
      top: 20px;
      right: 34px;
      width: 245px;
      height: 170px;
    }
    .brand_intro2 .item {
      margin-bottom: 18px;
    }
    .brand_intro2 .text,
    .brand_intro2 .content {
      font-size: 15px;
      line-height: 24px;
    }
    .aisearch_row {
      min-height: 228px;
      padding: 18px 24px;
    }
    .aisearch_left_inner .left img {
      height: 174px;
    }
    .aisearch_left_inner .left .text {
      font-size: 22px;
    }
    .right_card {
      width: 190px;
      gap: 16px;
    }
    .right_card .card {
      min-width: 190px;
      padding: 12px 16px;
    }
    .right_card .card_top {
      font-size: 32px !important;
    }
    .right_card .card_bottom {
      font-size: 15px !important;
    }
    .aisearch_right {
      padding: 8px 18px 0 42px;
    }
    .aisearch_right_item {
      margin-bottom: 22px;
    }
    .ai-data-overview-progress {
      height: 18px;
    }
    .ai-data-overview-progress .icon {
      left: -34px;
      height: 34px;
    }
    .ai-data-overview-progress span {
      right: -58px;
      font-size: 14px;
    }
    .data_analysis {
      padding: 14px 16px;
    }
    .data_analysis .text {
      font-size: 16px;
      line-height: 24px;
    }
    .ref_table_header {
      height: 36px;
    }
    .ref_table_header > div {
      font-size: 14px;
    }
    .ref_table_row {
      min-height: 36px;
    }
    .ref_table_row > div {
      min-height: 36px;
      font-size: 14px;
    }
    .model-logo {
      width: 26px;
      height: 26px;
      border-radius: 7px;
    }
    .platform_table {
      width: 100%;
      min-width: 0;
    }
    .data_analysis:has(.platform_table) {
      padding: 14px 20px;
      background: transparent;
      border: 0;
      box-shadow: none;
      overflow: visible;
    }
    .platform_table .ref_table_header,
    .platform_table .ref_table_row {
      column-gap: 0;
      gap: 0;
      grid-template-columns: minmax(160px, 1.25fr) repeat(8, minmax(92px, 1fr));
    }
    .platform_table .ref_table_header {
      border-radius: 6px 6px 0 0;
      overflow: hidden;
      border: 1px solid rgba(0, 176, 255, .28);
      border-bottom: 0;
    }
    .platform_table .ref_table_header > div,
    .platform_table .ref_table_row > div {
      min-height: 38px;
      border-right: 1px solid rgba(0, 156, 255, .28);
    }
    .platform_table .ref_table_header > div:last-child,
    .platform_table .ref_table_row > div:last-child {
      border-right: 0;
    }
    .platform_table .ref_table_row {
      border-left: 1px solid rgba(0, 176, 255, .22);
      border-right: 1px solid rgba(0, 176, 255, .22);
    }
    .platform_table .ref_table_row:last-child {
      border-bottom: 1px solid rgba(0, 176, 255, .22);
      border-radius: 0 0 4px 4px;
      overflow: hidden;
    }
    .chart_grid {
      gap: 12px;
    }
    .chart {
      height: 238px;
      min-height: 238px;
    }
    .chart .title {
      top: 10px;
      left: 12px;
      font-size: 14px;
    }
    .chart svg {
      transform: scale(.82);
      transform-origin: 50% 2%;
    }
    .donut-chart {
      width: 136px;
      height: 136px;
      margin-top: 55px;
    }
    .donut-chart::after {
      inset: 40px;
    }
    .source-label,
    .chart-legend {
      font-size: 12px;
    }
    .source-label.l1 { right: 28px; top: 100px; }
    .source-label.l2 { left: 58px; top: 172px; }
    .source-label.l3 { left: 28px; top: 120px; }
    .source-label.l4 { left: 118px; top: 62px; }
    .source-label.l5 { right: 88px; top: 56px; }
    .score_chart,
    .chart2 {
      height: 236px;
      min-height: 236px;
    }
    .sentiment-donut {
      width: 150px;
      height: 150px;
      background:
        conic-gradient(
          #6669ff 0 91.35%,
          rgba(236, 248, 255, .86) 91.35% 92%,
          #ff9245 92% 99.35%,
          rgba(236, 248, 255, .86) 99.35% 100%
        );
    }
    .sentiment-donut::after {
      inset: 36px;
    }
    .sentiment-donut b {
      font-size: 32px;
    }
    .sentiment-donut span {
      font-size: 14px;
    }
    .chart2 .title2 {
      height: 48px;
      padding-left: 24px;
      font-size: 16px;
      line-height: 48px;
    }
    .public-opinion-share-chart {
      height: 172px;
      padding: 0 54px 12px 32px;
    }
    .public-opinion-share-item {
      margin-bottom: 13px;
    }
    .public-opinion-share-item .left,
    .public-opinion-share-item .right {
      font-size: 14px;
    }
    .public-opinion-share-item .bottom_content {
      height: 11px;
    }
    .aisearch_row > .arco-row {
      display: grid;
      grid-template-columns: minmax(520px, .9fr) minmax(640px, 1.1fr);
      gap: 34px;
      align-items: stretch;
      margin: 0 !important;
    }
    .aisearch_row > .arco-row > .arco-col {
      max-width: none !important;
      flex: none !important;
      padding: 0 !important;
    }
    .aisearch_row {
      min-height: 250px;
      padding: 18px 24px;
    }
    .aisearch_left {
      height: 210px;
      margin: 0;
      justify-content: center;
      border: 1px solid rgba(0, 155, 255, .36);
      background:
        radial-gradient(circle at 28% 50%, rgba(0, 220, 255, .12), transparent 34%),
        linear-gradient(90deg, rgba(3, 22, 52, .52), rgba(6, 31, 66, .36));
      box-shadow: inset 0 0 22px rgba(0, 154, 255, .1);
    }
    .aisearch_left_line::before {
      display: none;
    }
    .aisearch_left_inner {
      width: min(640px, 100%);
      display: grid;
      grid-template-columns: 230px 280px;
      justify-content: center;
      align-items: center;
      gap: 40px;
    }
    .aisearch_left_inner .left {
      display: grid;
      place-items: center;
    }
    .aisearch_left_inner .left img {
      width: 210px;
      height: 210px;
      object-fit: contain;
    }
    .aisearch_left_inner .left .text {
      font-size: 24px;
    }
    .right_card {
      width: 280px;
      gap: 22px;
    }
    .right_card .card {
      width: 280px;
      min-width: 280px;
      min-height: 76px;
      padding: 13px 22px;
      display: grid;
      align-content: center;
    }
    .right_card .card_top {
      font-size: 36px !important;
      line-height: 1.05;
    }
    .right_card .card_bottom {
      margin-top: 5px;
      font-size: 17px !important;
      line-height: 1.2;
    }
    .aisearch_right {
      height: 210px;
      padding: 20px 46px 18px 52px;
      border: 1px solid rgba(0, 155, 255, .36);
      background:
        radial-gradient(circle at 70% 45%, rgba(69, 92, 255, .1), transparent 38%),
        linear-gradient(90deg, rgba(3, 22, 52, .48), rgba(6, 31, 66, .35));
      box-shadow: inset 0 0 22px rgba(0, 154, 255, .1);
    }
    .aisearch_right_item {
      margin-bottom: 19px;
    }
    .aisearch_right_item:last-child {
      margin-bottom: 0;
    }
    .ai-data-overview-progress {
      height: 22px;
      overflow: visible;
      border-radius: 999px;
      background: rgba(21, 51, 97, .72);
    }
    .ai-data-overview-progress .icon {
      left: -44px;
      height: 42px;
    }
    .ai-data-overview-progress .progress {
      min-width: 38px;
      border-radius: 999px;
    }
    .ai-data-overview-progress span {
      right: -42px;
      font-size: 16px;
      font-weight: 800;
    }
    .aisearch_row {
      min-height: 174px;
      padding: 12px 18px;
      overflow: hidden;
    }
    .aisearch_row > .arco-row {
      grid-template-columns: minmax(430px, .42fr) minmax(620px, .58fr);
      gap: 30px;
      align-items: center;
    }
    .aisearch_left,
    .aisearch_right {
      height: 142px;
      border: 0;
      background: transparent;
      box-shadow: none;
    }
    .aisearch_left {
      justify-content: flex-start;
      padding-left: 24px;
    }
    .aisearch_left_inner {
      width: 100%;
      grid-template-columns: 210px 210px;
      justify-content: center;
      gap: 24px;
    }
    .aisearch_left_inner .left img {
      width: 150px;
      height: 150px;
      filter:
        hue-rotate(155deg)
        saturate(1.55)
        brightness(.62)
        contrast(1.35)
        drop-shadow(0 0 13px rgba(0, 211, 255, .62));
      opacity: .86;
    }
    .aisearch_left_inner .left::before {
      content: "";
      position: absolute;
      width: 164px;
      height: 164px;
      border-radius: 50%;
      background:
        repeating-conic-gradient(from -20deg, rgba(0, 224, 255, .42) 0 8deg, transparent 8deg 12deg),
        radial-gradient(circle, transparent 0 44%, rgba(0, 141, 255, .34) 45% 48%, transparent 49% 100%);
      filter: drop-shadow(0 0 13px rgba(0, 214, 255, .5));
      opacity: .82;
    }
    .aisearch_left_inner .left .text {
      font-size: 18px;
      color: #7feeff;
      text-shadow: 0 0 13px rgba(0, 229, 255, .86);
      z-index: 2;
    }
    .right_card {
      width: 210px;
      gap: 14px;
    }
    .right_card .card {
      width: 210px;
      min-width: 210px;
      min-height: 56px;
      padding: 9px 14px;
      border-color: rgba(76, 171, 255, .52);
      background: rgba(2, 21, 48, .68);
      box-shadow: inset 0 0 15px rgba(0, 167, 255, .12);
    }
    .right_card .card2::before {
      left: -11px;
      border-top-width: 9px;
      border-bottom-width: 9px;
      border-right-color: rgba(0, 122, 219, .72);
    }
    .right_card .card_top {
      font-size: 25px !important;
      color: #56eaff;
    }
    .right_card .card_bottom {
      margin-top: 3px;
      font-size: 13px !important;
      color: #c8d9ef;
    }
    .aisearch_right {
      padding: 12px 48px 10px 40px;
      display: grid;
      align-content: center;
    }
    .aisearch_right_item {
      margin-bottom: 12px;
    }
    .ai-data-overview-progress {
      height: 14px;
      background: rgba(28, 57, 103, .82);
      box-shadow: inset 0 0 10px rgba(0, 8, 24, .82), 0 0 9px rgba(42, 104, 255, .08);
    }
    .ai-data-overview-progress .icon {
      left: -32px;
      height: 30px;
    }
    .ai-data-overview-progress .progress {
      min-width: 24px;
      box-shadow: 0 0 11px rgba(255,255,255,.24);
    }
    .ai-data-overview-progress span {
      right: -42px;
      font-size: 12px;
      color: #68e8ff;
    }
    header {
      position: relative;
      height: 88px;
      min-height: 88px;
      padding: 14px 18px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      pointer-events: auto;
      background:
        linear-gradient(90deg, rgba(0, 16, 40, .72), rgba(0, 45, 96, .38), rgba(0, 16, 40, .72));
      border-bottom: 1px solid rgba(0, 201, 255, .28);
      box-shadow: 0 8px 24px rgba(0, 119, 255, .18);
    }
    .logo {
      display: block;
      width: clamp(180px, 18vw, 260px);
      height: 56px;
      margin: 0;
      object-fit: contain;
      object-position: left center;
      filter: none;
    }
    .right_content {
      position: relative;
      top: auto;
      right: auto;
      display: flex;
      align-items: center;
      justify-content: flex-end;
      gap: 18px;
      pointer-events: auto;
    }
    .company-meta {
      display: block;
      width: auto;
      min-width: 320px;
      flex: 0 0 auto;
      color: #d9f2ff;
      text-align: right;
      font-size: 15px;
      line-height: 1.55;
      text-shadow: 0 0 10px rgba(0, 195, 255, .22);
      white-space: nowrap;
    }
    .report-menu {
      width: 210px;
      min-width: 210px;
      flex-basis: 210px;
      font-size: 14px;
    }
    .report-menu summary {
      height: 40px;
      min-height: 40px;
      padding: 0 16px 0 18px;
      color: #f4fbff;
      border: 1px solid rgba(0, 210, 255, .45);
      border-radius: 22px;
      background:
        linear-gradient(135deg, rgba(10, 101, 255, .92), rgba(0, 213, 255, .24));
      box-shadow:
        inset 0 0 18px rgba(0, 221, 255, .18),
        0 0 18px rgba(0, 156, 255, .28);
    }
    .report-menu summary::after {
      opacity: 1;
      border-top-color: #f4fbff;
    }
    .logo_content {
      height: 220px;
      position: relative;
      display: grid;
      place-items: center;
    }
    .logo_content::before {
      content: "AI搜索";
      position: absolute;
      top: 42px;
      left: 50%;
      transform: translateX(-50%);
      color: #f4fbff;
      font-size: clamp(44px, 6vw, 76px);
      line-height: 1;
      font-weight: 900;
      letter-spacing: 2px;
      font-style: italic;
      text-shadow:
        0 0 8px rgba(255,255,255,.72),
        0 0 18px rgba(0, 194, 255, .78),
        0 9px 0 rgba(23, 81, 170, .48);
      white-space: nowrap;
    }
    .logo_content::after {
      content: "行业竞争力分析报告";
      position: absolute;
      top: 116px;
      left: 50%;
      transform: translateX(-50%);
      color: #f7fbff;
      font-size: clamp(24px, 3.2vw, 42px);
      line-height: 1;
      font-weight: 900;
      letter-spacing: 1px;
      font-style: italic;
      text-shadow:
        0 0 8px rgba(255,255,255,.55),
        0 0 18px rgba(0, 194, 255, .7),
        0 6px 0 rgba(23, 81, 170, .42);
      white-space: nowrap;
    }
    .logo_content::before,
    .logo_content::after {
      display: none;
    }
    main {
      margin-top: -34px;
    }

    /* AI search overview: compact editable reconstruction from the reference image. */
    .aisearch_row {
      min-height: 272px;
      padding: 24px 24px 26px;
      border-color: rgba(20, 143, 255, .52);
      background:
        linear-gradient(90deg, rgba(2, 16, 42, .66), rgba(4, 23, 55, .58)),
        linear-gradient(rgba(71, 177, 255, .055) 1px, transparent 1px),
        linear-gradient(90deg, rgba(71, 177, 255, .055) 1px, transparent 1px);
      background-size: auto, 34px 34px, 34px 34px;
      box-shadow:
        inset 0 0 18px rgba(0, 156, 255, .12),
        0 0 16px rgba(0, 174, 255, .12);
      overflow: hidden;
    }
    .aisearch_row .aisearch_title {
      display: none;
    }
    .aisearch_row > .arco-row {
      grid-template-columns: minmax(420px, .43fr) minmax(620px, .57fr);
      gap: 36px;
      align-items: center;
      min-height: 222px;
    }
    .aisearch_left,
    .aisearch_right {
      height: 222px;
      border: 0;
      background: transparent;
      box-shadow: none;
    }
    .aisearch_left {
      position: relative;
      justify-content: flex-start;
      padding: 0 18px 0 56px;
    }
    .aisearch_left::after {
      display: none;
    }
    .aisearch_left_inner {
      width: auto;
      grid-template-columns: 240px 230px;
      gap: 28px;
      justify-content: flex-start;
      align-items: center;
    }
    .aisearch_left_inner .left {
      position: relative;
      width: 230px;
      height: 190px;
      display: grid;
      place-items: center;
      isolation: isolate;
    }
    .aisearch_left_inner .left img {
      display: none;
    }
    .aisearch_left_inner .left::before {
      content: "";
      position: absolute;
      width: 172px;
      height: 172px;
      border-radius: 50%;
      background:
        radial-gradient(circle, #020613 0 42%, transparent 43%),
        conic-gradient(from -102deg,
          #73df94 0 61.64%,
          #65bdff 61.64% 100%);
      box-shadow: none;
      z-index: 1;
    }
    .aisearch_left_inner .left::after {
      display: none;
    }
    .aisearch_left_inner .left .text {
      width: 96px;
      height: 96px;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      border: 0;
      border-radius: 50%;
      background: #020613;
      box-shadow: none;
      z-index: 3;
      color: #f3f8ff;
      font-size: 0;
      line-height: 1;
      font-weight: 800;
      letter-spacing: 0;
      text-shadow: none;
    }
    .aisearch_left_inner .left .text::after {
      content: "数据展示";
      margin-top: 0;
      color: #f3f8ff;
      font-size: 20px;
      line-height: 1;
      font-weight: 800;
      letter-spacing: 0;
    }
    .donut-meta {
      position: absolute;
      left: 50%;
      bottom: -19px;
      z-index: 4;
      display: flex;
      gap: 14px;
      transform: translateX(-50%);
      color: #d6e5f9;
      font-size: 12px;
      font-weight: 700;
      line-height: 1;
      white-space: nowrap;
    }
    .donut-meta span {
      display: inline-flex;
      align-items: center;
      gap: 5px;
      text-shadow: 0 0 8px rgba(0, 174, 255, .5);
    }
    .donut-meta i {
      width: 9px;
      height: 9px;
      border-radius: 50%;
      background: var(--dot);
      box-shadow: 0 0 8px var(--dot);
    }
    .right_card {
      width: 230px;
      gap: 16px;
      margin-bottom: 0;
    }
    .right_card .card {
      width: 230px;
      min-width: 230px;
      min-height: 66px;
      margin-bottom: 0;
      padding: 10px 16px;
      border: 1px solid rgba(65, 157, 238, .68);
      border-radius: 2px;
      background: linear-gradient(90deg, rgba(7, 32, 69, .84), rgba(5, 24, 56, .62));
      box-shadow: inset 0 0 14px rgba(0, 166, 255, .13);
    }
    .right_card .card2::before {
      left: -12px;
      border-top-width: 10px;
      border-bottom-width: 10px;
      border-right-color: rgba(30, 121, 205, .78);
    }
    .right_card .card_top {
      color: #65eaff;
      font-size: 29px !important;
      line-height: 1.05;
      text-shadow: 0 0 12px rgba(0, 215, 255, .5);
    }
    .right_card .card_bottom {
      margin-top: 4px;
      color: #d6e5f9;
      font-size: 15px !important;
      line-height: 1.15;
      font-weight: 700;
    }
    .aisearch_right {
      padding: 22px 58px 22px 46px;
      display: grid;
      align-content: center;
    }
    .aisearch_right_item {
      margin-bottom: 31px;
    }
    .ai-data-overview-progress {
      height: 15px;
      background:
        linear-gradient(90deg, rgba(37, 77, 136, .92), rgba(23, 50, 94, .78)),
        repeating-linear-gradient(90deg, rgba(126, 196, 255, .16) 0 1px, transparent 1px 54px);
      outline: 1px solid rgba(77, 146, 220, .22);
      box-shadow:
        inset 0 0 12px rgba(0, 7, 22, .74),
        inset 0 1px 0 rgba(159, 220, 255, .13),
        0 0 12px rgba(45, 119, 255, .14);
    }
    .ai-data-overview-progress .icon {
      left: -35px;
      height: 33px;
      filter: drop-shadow(0 0 6px rgba(0, 214, 255, .3));
    }
    .ai-data-overview-progress .progress {
      min-width: 26px;
      box-shadow: 0 0 10px rgba(255, 255, 255, .22);
    }
    .ai-data-overview-progress span {
      top: 50%;
      right: -42px;
      color: #75edff;
      font-size: 12px;
      font-weight: 800;
      text-shadow: 0 0 8px rgba(0, 218, 255, .7);
    }

    @media (max-width: 980px) {
      .aisearch_row > .arco-row {
        grid-template-columns: 1fr;
        gap: 14px;
      }
      .aisearch_left,
      .aisearch_right {
        height: auto;
      }
      .aisearch_left {
        min-height: 210px;
        padding-left: 24px;
      }
      .aisearch_left_inner {
        grid-template-columns: 230px minmax(260px, 360px);
        gap: 28px;
      }
      .right_card,
      .right_card .card {
        width: 100%;
        min-width: 0;
      }
      .aisearch_right {
        min-height: 220px;
        padding: 20px 56px 20px 58px;
      }
    }

    @media (max-width: 860px) {
      .aisearch_left_inner {
        grid-template-columns: 1fr;
        gap: 16px;
      }
      .aisearch_right {
        padding: 20px 52px;
      }
      main {
        margin-top: -28px;
        padding: 0 8px 24px;
      }
      .logo_content {
        height: clamp(158px, 24.75vw, 250px);
      }
      .desc span {
        float: none;
        display: block;
        margin: 4px 0 0;
      }
      .brand_intro2 {
        height: auto;
        min-height: 0;
        padding: 16px;
      }
      .brand_intro2 .left {
        position: static;
      }
      .brand_intro2 .photo {
        width: min(307px, 100%);
        height: auto;
        aspect-ratio: 307 / 137;
      }
      .brand_intro2 .right {
        margin-top: 14px;
      }
      .brand_intro2::after {
        opacity: .28;
      }
      .aisearch_left_line::before {
        display: none;
      }
      .chart {
        height: 260px;
        min-height: 260px;
      }
      .chart svg {
        transform: none;
      }
      .donut-chart {
        width: 160px;
        height: 160px;
      }
      .donut-chart::after {
        inset: 42px;
      }
      .source-label.l1 { right: 32px; top: 132px; }
      .source-label.l2 { left: 70px; top: 215px; }
      .source-label.l3 { left: 42px; top: 152px; }
      .source-label.l4 { left: 120px; top: 78px; }
      .source-label.l5 { right: 98px; top: 63px; }
      .sentiment_grid {
        grid-template-columns: 1fr;
      }
      .score_chart,
      .chart2 {
        height: 240px;
        min-height: 240px;
      }
      .public-opinion-share-chart {
        height: auto;
        overflow: auto;
        padding: 0 18px 16px;
      }
    }
    @media (max-width: 520px) {
      header {
        min-height: 0;
        height: auto;
        flex-direction: column;
        align-items: stretch;
        gap: 10px;
        padding: 10px;
      }
      .logo {
        width: min(220px, 64vw);
        height: 46px;
      }
      .right_content {
        position: static;
        flex-direction: column;
        align-items: stretch;
        gap: 8px;
      }
      .company-meta {
        min-width: 0;
        width: 100%;
        text-align: left;
        white-space: normal;
        font-size: 13px;
      }
      .report-menu {
        width: 100%;
        min-width: 0;
        flex-basis: auto;
      }
      .main_inner > section {
        padding: 34px 10px 10px;
      }
      .competitiveness_analysis_com_title {
        width: 230px;
        font-size: 14px;
      }
      .report-summary-row .arco-col {
        padding: 5px 0 !important;
      }
      .competitiveness_analysis_report_summary_card {
        height: auto;
        min-height: 84px;
      }
      .aisearch_left_inner {
        flex-direction: column;
      }
      .right_card {
        width: 100%;
      }
      .right_card .card {
        min-width: 0;
      }
      .aisearch_right {
        padding: 12px 0 0 28px;
      }
      .ai-data-overview-progress span {
        right: 0;
        transform: translateY(-50%) translateX(0);
      }
    }

    header .right_content {
      position: absolute;
      top: 27px;
      right: 18px;
      width: 560px;
      flex: 0 0 560px;
      gap: 18px;
      justify-content: flex-end;
      height: 47px;
      align-items: center;
      flex-direction: row;
      flex-wrap: nowrap;
      align-self: start;
      margin-top: 0;
    }
    header .company-meta {
      display: block;
      width: 282px;
      min-width: 282px;
      flex: 0 0 282px;
      text-align: center;
      font-size: 15px;
      line-height: 1.55;
      white-space: nowrap;
    }
    header .report-menu {
      width: 260px;
      min-width: 260px;
      flex: 0 0 260px;
      font-size: 16px;
      align-self: flex-start;
    }
    header .report-menu summary {
      box-sizing: border-box;
      width: 260px;
      height: 44px;
      min-height: 44px;
      padding: 0 18px 0 22px;
      border: 0;
      border-radius: 24px;
      color: #fff;
      font-size: 16px;
      font-weight: 850;
      line-height: 44px;
      background: linear-gradient(135deg, #2f7cff 0%, #4e60f5 58%, #9456ff 100%);
      box-shadow: inset 0 0 0 1px rgba(167, 214, 255, .45), 0 8px 24px rgba(53, 95, 255, .26);
    }
    header .report-menu summary::after {
      opacity: 1;
      border-top-color: #fff;
    }
    header .report-menu-list {
      width: 260px;
      min-width: 260px;
      border-radius: 7px;
      top: calc(100% + 6px);
      padding: 6px;
      border: 1px solid rgba(60, 190, 255, .46);
      color: #eaf7ff;
      background: rgba(3, 19, 42, .96);
      box-shadow: 0 18px 38px rgba(0, 10, 36, .58), 0 0 18px rgba(0, 171, 255, .22);
      backdrop-filter: none;
    }
    header .report-menu-list a,
    header .report-menu-list span {
      padding: 8px 12px;
      border-radius: 5px;
      color: #d9f4ff;
      font-size: 16px;
      font-weight: 750;
      line-height: 20px;
      box-shadow: none;
    }
    header .report-menu-list a:hover {
      color: #fff;
      background: linear-gradient(135deg, #2e7dff, #8b49ff);
      box-shadow: none;
    }
    header .report-menu-list span {
      color: #fff;
      background: linear-gradient(135deg, #0f72ff, #844eff);
      box-shadow: none;
      margin-bottom: 4px;
    }
    @media (max-width: 1100px) and (min-width: 721px) {
      header {
        align-items: center;
      }
      header .right_content {
        position: absolute;
        top: 27px;
        right: 50%;
        width: 560px;
        flex: 0 0 auto;
        height: 47px;
        flex-direction: row;
        align-items: center;
        flex-wrap: nowrap;
        margin-left: auto;
        margin-right: auto;
        margin-top: 0;
        transform: translateX(50%);
      }
    }
    @media (max-width: 720px) {
      header {
        padding-left: 10px;
        padding-right: 10px;
      }
      header .right_content {
        position: relative;
        top: auto;
        right: auto;
        transform: none;
        width: 100%;
        flex: 0 1 auto;
        height: auto;
        flex-direction: column;
        flex-wrap: nowrap;
        align-self: auto;
        margin-top: 0;
      }
      header .company-meta {
        width: 100%;
        min-width: 0;
        flex: 0 1 auto;
        text-align: left;
        white-space: normal;
      }
      header .report-menu,
      header .report-menu summary,
      header .report-menu-list {
        width: 100%;
        min-width: 0;
      }
      header .report-menu {
        flex: 0 0 auto;
      }
      header .report-menu summary {
        height: 44px;
        min-height: 44px;
        padding: 0 18px 0 22px;
        font-size: 16px;
        line-height: 44px;
      }
    }

    .platform-charts .chart svg {
      transform: scale(.96);
      transform-origin: 50% 50%;
    }
    .platform-charts .chart svg.bar-svg {
      transform: translateY(8px) scale(1.12, 1);
    }

    .data_analysis:has(.platform_table) {
      padding-right: 0;
      padding-left: 0;
    }
    section:has(.platform_table) > .data_analysis,
    section:has(.platform_table) > .platform-charts {
      width: 100%;
      margin-right: 0;
      margin-left: 0;
    }
    .platform_table .ref_table_row > div:first-child {
      justify-content: flex-start;
      padding-left: 58px;
      text-align: left;
    }
    .platform_table .ref_table_row > div:first-child .model-name {
      width: auto;
      justify-content: flex-start;
    }

    .data_analysis.competitor_area {
      min-height: 0;
      margin-bottom: 6px;
      padding: 10px 0 0;
      border: 0;
      border-radius: 0;
      background: transparent;
      box-shadow: none;
      overflow-x: auto;
    }
    .competitor_area .text {
      margin: 0 0 14px;
      color: #d8eaff;
      font-size: 18px;
      line-height: 28px;
      font-weight: 700;
    }
    .competitor_table {
      width: 100%;
      min-width: 1280px;
    }
    .competitor_table .ref_table_header,
    .competitor_table .ref_table_row {
      gap: 0;
      column-gap: 0;
      grid-template-columns: 170px minmax(380px, 1.45fr) repeat(5, minmax(150px, 1fr));
    }
    .competitor_table .ref_table_header {
      height: 52px;
      margin-bottom: 0;
      border-bottom: 0;
      border-radius: 6px 6px 0 0;
      overflow: hidden;
    }
    .competitor_table .ref_table_row {
      min-height: 50px;
      margin-bottom: 0;
    }
    .competitor_table .ref_table_header > div,
    .competitor_table .ref_table_row > div {
      min-height: 50px;
      padding: 0 14px;
      font-size: 16px;
      font-weight: 700;
      border-right: 1px solid rgba(0, 156, 255, .28);
    }
    .competitor_table .ref_table_header > div:last-child,
    .competitor_table .ref_table_row > div:last-child {
      border-right: 0;
    }
    .competitor_table .ref_table_row:last-child {
      border-bottom: 1px solid rgba(0, 176, 255, .22);
      border-radius: 0 0 4px 4px;
      overflow: hidden;
    }
    .competitor_table .ref_table_row .name_cell {
      justify-content: flex-start;
      padding-left: 26px;
      font-size: 16px;
    }
    .competitor_table .model-name {
      gap: 8px;
      justify-content: center;
    }
    .competitor_table .model-logo {
      width: 32px;
      height: 32px;
      border-radius: 8px;
    }
    @media (min-width: 861px) {
      .data_analysis.competitor_area {
        width: calc(100% + 36px);
        margin-right: -18px;
        margin-left: -18px;
        padding: 10px 12px 0;
      }
    }
    @media (max-width: 720px) {
      .competitor_area .text {
        font-size: 15px;
        line-height: 22px;
      }
      .competitor_table {
        min-width: 860px;
      }
      .competitor_table .ref_table_header,
      .competitor_table .ref_table_row {
        grid-template-columns: 120px minmax(240px, 1.4fr) repeat(5, minmax(100px, 1fr));
      }
      .competitor_table .ref_table_header {
        height: 40px;
      }
      .competitor_table .ref_table_row {
        min-height: 40px;
      }
      .competitor_table .ref_table_header > div,
      .competitor_table .ref_table_row > div {
        min-height: 40px;
        padding: 0 10px;
        font-size: 13px;
      }
      .competitor_table .model-logo {
        width: 24px;
        height: 24px;
      }
    }

    .report-header {
      position: relative;
      top: auto;
      left: auto;
      right: auto;
      z-index: 5000;
      height: 126px;
      min-height: 0;
      padding: 0 18px;
      display: grid;
      grid-template-columns: 410px 1fr 560px;
      align-items: center;
      gap: 18px;
      margin: 0 18px;
      background: transparent;
      border-bottom: 0;
      box-shadow: none;
      pointer-events: auto;
    }
    .report-header .brand-strip {
      position: absolute;
      top: 72px;
      left: 18px;
      z-index: 1001;
      display: flex;
      align-items: center;
      min-width: 0;
      transform: translateY(-50%);
    }
    .report-header .brand-logo {
      display: block;
      width: min(320px, 100%);
      height: 54px;
      object-fit: contain;
      object-position: left center;
      filter: none;
    }
    .report-header .report-title {
      position: absolute;
      left: 50%;
      top: 72px;
      z-index: 1001;
      width: max-content;
      margin: 0;
      transform: translate(-50%, -50%);
      color: #f4fbff;
      font-size: clamp(28px, 3vw, 46px);
      font-weight: 950;
      font-style: italic;
      line-height: 1.15;
      text-align: center;
      text-shadow:
        0 0 8px rgba(87, 181, 255, .82),
        0 3px 0 rgba(22, 83, 170, .58);
    }
    .report-header .company-box {
      position: absolute;
      top: 72px;
      right: 18px;
      z-index: 1001;
      width: 560px;
      height: 47px;
      display: flex;
      align-items: center;
      justify-content: flex-end;
      gap: 18px;
      min-width: 0;
      transform: translateY(-50%);
      pointer-events: auto;
    }
    .report-header .company-meta {
      display: block;
      width: 282px;
      min-width: 282px;
      flex: 0 0 282px;
      color: #e8f6ff;
      font-size: 15px;
      line-height: 1.55;
      text-align: center;
      white-space: nowrap;
      text-shadow: 0 0 8px rgba(34, 165, 255, .36);
    }
    .report-header .report-menu {
      width: 260px;
      min-width: 260px;
      flex: 0 0 260px;
      font-size: 16px;
      align-self: flex-start;
    }
    .report-header .report-menu summary {
      box-sizing: border-box;
      width: 260px;
      height: 44px;
      min-height: 44px;
      padding: 0 18px 0 22px;
      border: 0;
      border-radius: 24px;
      color: #fff;
      font-size: 16px;
      font-weight: 850;
      line-height: 44px;
      background: linear-gradient(135deg, #2f7cff 0%, #4e60f5 58%, #9456ff 100%);
      box-shadow: inset 0 0 0 1px rgba(167, 214, 255, .45), 0 8px 24px rgba(53, 95, 255, .26);
    }
    .report-header .report-menu summary::after {
      opacity: 1;
      border-top-color: #fff;
    }
    .report-header .report-menu-list {
      width: 260px;
      min-width: 260px;
      top: calc(100% + 6px);
      padding: 6px;
      border: 1px solid rgba(60, 190, 255, .46);
      border-radius: 7px;
      color: #eaf7ff;
      background: rgba(3, 19, 42, .96);
      box-shadow: 0 18px 38px rgba(0, 10, 36, .58), 0 0 18px rgba(0, 171, 255, .22);
      backdrop-filter: none;
    }
    .report-header .report-menu-list a,
    .report-header .report-menu-list span {
      padding: 8px 12px;
      border-radius: 5px;
      color: #d9f4ff;
      font-size: 16px;
      font-weight: 750;
      line-height: 20px;
      box-shadow: none;
    }
    .report-header .report-menu-list a:hover {
      color: #fff;
      background: linear-gradient(135deg, #2e7dff, #8b49ff);
      box-shadow: none;
    }
    .report-header .report-menu-list span {
      color: #fff;
      background: linear-gradient(135deg, #0f72ff, #844eff);
      box-shadow: none;
      margin-bottom: 4px;
    }
    @media (max-width: 1100px) {
      .report-header {
        position: relative;
        height: auto;
        padding: 12px 10px;
        display: grid;
        grid-template-columns: 1fr;
        gap: 10px;
        pointer-events: auto;
      }
      .report-header .brand-strip,
      .report-header .report-title,
      .report-header .company-box {
        position: static;
        transform: none;
      }
      .report-header .brand-strip {
        justify-content: center;
      }
      .report-header .brand-logo {
        height: 46px;
      }
      .report-header .report-title {
        width: auto;
        font-size: clamp(24px, 8vw, 32px);
        line-height: 1.1;
      }
      .report-header .company-box {
        width: 100%;
        height: auto;
        flex: 0 1 auto;
        justify-content: center;
      }
    }
    @media (max-width: 720px) {
      .report-header .company-box {
        flex-direction: column;
        align-items: stretch;
      }
      .report-header .company-meta {
        width: 100%;
        min-width: 0;
        flex: 0 1 auto;
        text-align: left;
        white-space: normal;
      }
    .report-header .report-menu,
    .report-header .report-menu summary,
    .report-header .report-menu-list {
      width: 100%;
      min-width: 0;
    }
    }

    .logo_content {
      display: none;
    }
    .desc {
      min-height: 0;
      margin: 0 0 12px;
      padding: 0 18px;
      border-top: 0;
      border-bottom: 0;
      background: transparent;
      box-shadow: none;
    }
    main {
      margin-top: 32px;
    }

    .report-summary-row .arco-col:nth-child(3) .competitiveness_analysis_report_summary_card .right {
      display: grid;
      align-content: center;
    }
    .report-summary-row .arco-col:nth-child(3) .platform-icons {
      position: static;
      margin-top: 5px;
    }
    .main_inner > section:first-of-type {
      padding-top: 54px;
      padding-bottom: 24px;
    }
    .main_inner > section:first-of-type .competitiveness_analysis_report_summary_card {
      height: 128px;
      align-items: center;
    }
    .main_inner > section:first-of-type .report-summary-row {
      margin-top: 0 !important;
    }
    .brand_intro2::after {
      display: none;
    }

    @media (max-width: 860px) {
      .platform-charts .chart svg {
        transform: none;
      }
    }

    .sentiment-overview-card {
      min-height: 430px;
      padding: 22px 28px 20px;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      background: transparent;
      border-radius: 0;
      box-shadow: none;
    }
    .sentiment-overview-card .sentiment-donut {
      width: 300px;
      height: auto;
      aspect-ratio: 1 / 1;
      flex: 0 0 300px;
      margin: 0;
      box-shadow: none;
      background:
        conic-gradient(
          #6669ff 0 92%,
          #ff9245 92% 100%,
          #e94f4c 100% 100%
        );
    }
    .sentiment-overview-card .sentiment-donut::after {
      inset: 50px;
      background: #061a38;
    }
    .sentiment-overview-card .sentiment-donut .center {
      color: #fff;
    }
    .sentiment-overview-card .sentiment-donut b {
      color: #fff;
      font-size: 54px;
      line-height: 1;
      font-weight: 700;
      text-shadow: 0 0 14px rgba(80, 195, 255, .35);
    }
    .sentiment-overview-card .sentiment-donut span {
      margin-top: 20px;
      display: block;
      color: #dcefff;
      font-size: 24px;
      line-height: 1;
      font-weight: 400;
    }
    .sentiment-overview-card .chart-legend {
      position: static;
      margin-top: 40px;
      display: flex;
      justify-content: center;
      gap: 28px;
      color: #dcefff;
      font-size: 24px;
      line-height: 1;
      font-weight: 400;
    }
    .sentiment-overview-card .chart-legend span {
      display: inline-flex;
      align-items: center;
      gap: 10px;
      white-space: nowrap;
    }
    .sentiment-overview-card .legend-dot {
      width: 20px;
      height: 20px;
      border-radius: 50%;
      background: var(--dot);
      box-shadow: none;
    }
    .sentiment_grid {
      align-items: stretch;
    }
    .sentiment_grid .sentiment-overview-card,
    .sentiment_grid .chart2 {
      height: 480px;
      min-height: 480px;
    }
    .sentiment_grid .chart2 {
      display: flex;
      flex-direction: column;
      overflow: hidden;
    }
    .sentiment_grid .chart2 .title2 {
      flex: 0 0 68px;
      height: 68px;
      padding-left: 48px;
      font-size: 24px;
      line-height: 68px;
    }
    .sentiment_grid .public-opinion-share-chart {
      flex: 1 1 auto;
      height: auto;
      padding: 12px 70px 30px 48px;
      overflow: visible;
    }
    .sentiment_grid .public-opinion-share-item {
      margin-bottom: 22px;
    }
    .sentiment_grid .public-opinion-share-item:last-child {
      margin-bottom: 0;
    }
    .sentiment_grid .public-opinion-share-item .top_content {
      margin-bottom: 10px;
      min-height: 28px;
    }
    .sentiment_grid .public-opinion-share-item .left,
    .sentiment_grid .public-opinion-share-item .right {
      font-size: 18px;
      line-height: 28px;
    }
    .sentiment_grid .public-opinion-share-item .left .model-logo {
      width: 24px;
      height: 24px;
      margin-right: 10px;
    }
    .sentiment_grid .public-opinion-share-item .bottom_content {
      height: 14px;
      border-radius: 999px;
    }
    .main_inner > section:nth-of-type(3) .aisearch_row {
      min-height: 354px;
      padding-top: 42px;
      padding-bottom: 42px;
    }
    .main_inner > section:nth-of-type(3) .aisearch_row > .arco-row {
      min-height: 270px;
      align-items: center;
    }
    .main_inner > section:nth-of-type(3) .aisearch_left,
    .main_inner > section:nth-of-type(3) .aisearch_right {
      height: 270px;
    }
    .brand_intro2 {
      height: 270px;
      min-height: 270px;
      padding: 42px 320px 34px 430px;
      background: transparent;
    }
    .main_inner > section:has(.brand_intro2) {
      background:
        linear-gradient(90deg, rgba(2, 14, 36, .9) 0%, rgba(3, 23, 54, .72) 34%, rgba(3, 20, 48, .34) 70%, rgba(2, 12, 30, .7) 100%),
        linear-gradient(180deg, rgba(0, 40, 88, .22), rgba(0, 9, 28, .68)),
        url("assets/industry-report/brand-profile-bg.jpg") right center / cover no-repeat;
    }
    .main_inner > section:has(.brand_intro2)::before {
      background:
        linear-gradient(rgba(53, 169, 255, .04) 1px, transparent 1px),
        linear-gradient(90deg, rgba(53, 169, 255, .035) 1px, transparent 1px);
      background-size: 18px 18px;
      opacity: .5;
    }
    .brand_intro2 .left {
      top: 44px;
      left: 28px;
    }
    .brand_intro2 .photo {
      width: 378px;
      height: 178px;
    }
    .brand_intro2 .right {
      padding-top: 10px;
    }
    .brand_intro2 .item {
      margin-bottom: 24px;
    }
    .brand_intro2 .text,
    .brand_intro2 .content {
      font-size: 15px;
      line-height: 28px;
    }
    .brand_intro2::after {
      top: 34px;
      right: 52px;
      width: 270px;
      height: 188px;
    }
  </style>
  <link rel="stylesheet" href="assets/responsive-report.css" />
</head>
<body>
  <div class="competitiveness_analysis_report_web competitiveness_analysis_report_bg">
    <header class="report-header">
      <div class="brand-strip">
        <img class="brand-logo" src="ceying-ai-logo.png" alt="策影AI" />
      </div>
      <h1 class="report-title">行业竞争力分析报表</h1>
      <div class="company-box">
        <div class="company-meta">
          @php($monitoringContext = $reportData['context'] ?? [])
          <div>{{ $monitoringContext['company_name'] ?? '未识别企业' }}</div>
          <div>数据更新日期：{{ $monitoringContext['date'] ?? now()->format('Y-m-d') }}</div>
          <div>新知地（成都）人工智能科技有限公司</div>
          <div>数据更新日期：2026-06-17</div>
        </div>
        <details class="report-menu">
          <summary>行业竞争力分析报表</summary>
          <div class="report-menu-list">
            <span>行业竞争力分析报表</span>
            <a href="geo-dashboard-replica.html">企业輿情分析报表</a>
          </div>
        </details>
      </div>
    </header>

    <div class="logo_content">
      <img class="ai_logo" src="https://geo.zxaigc.com/assets/images/ai-cfbe718f.png" alt="" />
      <img class="aisearch_logo" src="https://geo.zxaigc.com/assets/images/aisearch-text-d98afb14.png" alt="AI搜索 行业竞争力分析报告" />
    </div>

    <main>
      <img class="star1" src="https://geo.zxaigc.com/assets/images/star1-9750deaf.png" alt="" />
      <img class="star2" src="https://geo.zxaigc.com/assets/images/star2-7d99c8d7.png" alt="" />
      <img class="star3" src="https://geo.zxaigc.com/assets/images/star3-0ca694cc.png" alt="" />

      <div class="main_inner">
        <div class="desc">
          报告说明：基于品牌所有历史搜索关键词的收录数据，通过 AI 深度分析，全面掌握大模型对您品牌的认知情况。报告每周一自动更新，
          <span style="color: rgb(5, 92, 255);">最近更新时间：2026-06-15</span>
        </div>


        <section>
          <div class="competitiveness_analysis_com_title">
            <img class="title-bg" src="https://geo.zxaigc.com/assets/images/title-bg-163046e6.png" alt="" />
            <span class="title">一.&nbsp;&nbsp;&nbsp;报告概述</span>
          </div>
          <div class="arco-row arco-row-align-start arco-row-justify-start report-summary-row" style="margin: -5px -10px;">
            <div class="arco-col arco-col-xs-24 arco-col-sm-12 arco-col-md-6" style="padding: 5px 10px;">
              <div class="competitiveness_analysis_report_summary_card">
                <div class="left">
                  <div class="icon_wrap" style="--icon-bg:#055cff">
                    <img class="icon" src="assets/live-summary-icons/icon-1.png" alt="" />
                  </div>
                </div>
                <div class="right"><div class="label">蒸馏词数量(个)</div><div class="value">229</div></div>
              </div>
            </div>
            <div class="arco-col arco-col-xs-24 arco-col-sm-12 arco-col-md-6" style="padding: 5px 10px;">
              <div class="competitiveness_analysis_report_summary_card">
                <div class="left">
                  <div class="icon_wrap" style="--icon-bg:#9265ff">
                    <img class="icon" src="assets/live-summary-icons/icon-2.png" alt="" />
                  </div>
                </div>
                <div class="right"><div class="label">AI搜索竞争力分析数量(次)</div><div class="value">392</div></div>
              </div>
            </div>
            <div class="arco-col arco-col-xs-24 arco-col-sm-12 arco-col-md-6" style="padding: 5px 10px;">
              <div class="competitiveness_analysis_report_summary_card">
                <div class="left">
                  <div class="icon_wrap" style="--icon-bg:#37babb">
                    <img class="icon" src="assets/live-summary-icons/icon-3.png" alt="" />
                  </div>
                </div>
                <div class="right">
                  <div class="label">覆盖AI平台</div>
                  <div class="value">5</div>
                  <div class="platform-icons">
                    <span class="model-logo"><img src="assets/ai-platforms/doubao.png" alt="豆包"></span>
                    <span class="model-logo"><img src="assets/ai-platforms/deepseek.png" alt="DeepSeek"></span>
                    <span class="model-logo"><img src="assets/ai-platforms/yuanbao.png" alt="腾讯元宝"></span>
                    <span class="model-logo"><img src="assets/ai-platforms/wenxin.png" alt="文心一言"></span>
                    <span class="model-logo"><img src="assets/ai-platforms/qianwen.png" alt="千问"></span>
                  </div>
                </div>
              </div>
            </div>
            <div class="arco-col arco-col-xs-24 arco-col-sm-12 arco-col-md-6" style="padding: 5px 10px;">
              <div class="competitiveness_analysis_report_summary_card">
                <div class="left">
                  <div class="icon_wrap" style="--icon-bg:#f0934e">
                    <img class="icon" src="assets/live-summary-icons/icon-4.png" alt="" />
                  </div>
                </div>
                <div class="right"><div class="label">引用信源平台数(个)</div><div class="value">9608</div></div>
              </div>
            </div>
          </div>
        </section>

        <section>
          <div class="competitiveness_analysis_com_title">
            <img class="title-bg" src="https://geo.zxaigc.com/assets/images/title-bg-163046e6.png" alt="" />
            <span class="title">二.&nbsp;&nbsp;&nbsp;品牌画像</span>
          </div>
          <div class="brand_intro2">
            <div class="left">
              <img class="photo" src="https://geo.zxaigc.com/assets/images/photo-40729b51.png" alt="" />
            </div>
            <div class="right">
              <div class="item">
                <div class="text">公司名称：</div>
                <div class="content">新知地（成都）人工智能科技有限公司</div>
              </div>
              <div class="item">
                <div class="text">品牌名称：</div>
                <div class="content">新知地（成都）人工智能科技有限公司,新知地,新知地AI</div>
              </div>
              <div class="item">
                <div class="text">核心服务：</div>
                <div class="content">数字化服务,软件定制开发,AI应用开发,企业智能化解决方案</div>
              </div>
            </div>
          </div>
        </section>

        <section>
          <div class="competitiveness_analysis_com_title">
            <img class="title-bg" src="https://geo.zxaigc.com/assets/images/title-bg-163046e6.png" alt="" />
            <span class="title">三.&nbsp;&nbsp;&nbsp;AI搜索整体概况</span>
          </div>
          <div class="aisearch_row">
            <div class="aisearch_title">品牌前五综合占比</div>
            <div class="arco-row arco-row-align-start arco-row-justify-start" style="margin: -5px -10px;">
              <div class="arco-col arco-col-xs-24 arco-col-sm-12" style="padding: 5px 10px;">
                <div class="aisearch_left aisearch_left_line">
                  <div class="aisearch_left_inner">
                    <div class="left">
                      <img src="https://geo.zxaigc.com/assets/images/round-8f2394a9.png" alt="" />
                      <div class="text">61.64%</div>
                      <div class="donut-meta">
                        <span><i style="--dot:#73df94"></i>品牌前五 61.64%</span>
                        <span><i style="--dot:#65bdff"></i>其他 38.36%</span>
                      </div>
                    </div>
                    <div class="right_card">
                      <div class="card card2"><div class="card_top" style="font-size: 28px;">61.64%</div><div class="card_bottom" style="font-size: 14px;">品牌前五占比</div></div>
                      <div class="card card2"><div class="card_top" style="font-size: 28px;">242</div><div class="card_bottom" style="font-size: 14px;">品牌前五次数</div></div>
                    </div>
                  </div>
                </div>
              </div>
              <div class="arco-col arco-col-xs-24 arco-col-sm-12" style="padding: 5px 10px;">
                <div class="aisearch_right">
                  <div class="aisearch_right_item"><div class="ai-data-overview-progress"><img class="icon" src="https://geo.zxaigc.com/assets/images/top1-c1bd254d.png" alt="" /><div class="progress" style="background-color: rgb(255, 177, 122); width: 22.6%;"></div><span>22.6%</span></div></div>
                  <div class="aisearch_right_item"><div class="ai-data-overview-progress"><img class="icon" src="https://geo.zxaigc.com/assets/images/top2-8eb03e02.png" alt="" /><div class="progress" style="background-color: rgb(52, 123, 255); width: 10.96%;"></div><span>10.96%</span></div></div>
                  <div class="aisearch_right_item"><div class="ai-data-overview-progress"><img class="icon" src="https://geo.zxaigc.com/assets/images/top3-873efdd1.png" alt="" /><div class="progress" style="background-color: rgb(255, 134, 134); width: 4.79%;"></div><span>4.79%</span></div></div>
                  <div class="aisearch_right_item"><div class="ai-data-overview-progress"><img class="icon" src="https://geo.zxaigc.com/assets/images/top4-8b699781.png" alt="" /><div class="progress" style="background-color: rgb(37, 191, 171); width: 10.96%;"></div><span>10.96%</span></div></div>
                  <div class="aisearch_right_item"><div class="ai-data-overview-progress"><img class="icon" src="https://geo.zxaigc.com/assets/images/top5-1f653c40.png" alt="" /><div class="progress" style="background-color: rgb(64, 196, 127); width: 12.33%;"></div><span>12.33%</span></div></div>
                </div>
              </div>
            </div>
          </div>
        </section>

        <section>
          <div class="competitiveness_analysis_com_title">
            <img class="title-bg" src="https://geo.zxaigc.com/assets/images/title-bg-163046e6.png" alt="" />
            <span class="title">四.&nbsp;&nbsp;&nbsp;AI搜索平台分析</span>
          </div>
          <div class="data_analysis">
            <div class="ref_table platform_table">
              <div class="ref_table_header">
                <div>对比维度</div><div>搜索推荐分析次数</div><div>品牌词TOP1占比</div><div>品牌词TOP2占比</div><div>品牌词TOP3占比</div><div>品牌词TOP4占比</div><div>品牌词TOP5占比</div><div>正向舆情占比</div><div>信源站点数</div>
              </div>
              <div class="ref_table_row"><div><span class="model-name"><span class="model-logo"><img src="assets/ai-platforms/doubao.png" alt="豆包"></span>豆包</span></div><div>124</div><div>31.58%</div><div>2.63%</div><div>2.63%</div><div>21.05%</div><div>7.89%</div><div>100%</div><div>4852</div></div>
              <div class="ref_table_row"><div><span class="model-name"><span class="model-logo"><img src="assets/ai-platforms/deepseek.png" alt="DeepSeek"></span>DeepSeek</span></div><div>52</div><div>100%</div><div>0%</div><div>0%</div><div>0%</div><div>0%</div><div>100%</div><div>730</div></div>
              <div class="ref_table_row"><div><span class="model-name"><span class="model-logo"><img src="assets/ai-platforms/yuanbao.png" alt="腾讯元宝"></span>腾讯元宝</span></div><div>108</div><div>7.5%</div><div>2.5%</div><div>2.5%</div><div>0%</div><div>27.5%</div><div>75%</div><div>1642</div></div>
              <div class="ref_table_row"><div><span class="model-name"><span class="model-logo"><img src="assets/ai-platforms/wenxin.png" alt="文心一言"></span>文心一言</span></div><div>64</div><div>11.54%</div><div>0%</div><div>11.54%</div><div>15.38%</div><div>15.38%</div><div>96.15%</div><div>1688</div></div>
              <div class="ref_table_row"><div><span class="model-name"><span class="model-logo"><img src="assets/ai-platforms/qianwen.png" alt="千问"></span>千问</span></div><div>44</div><div>18.18%</div><div>42.42%</div><div>6.06%</div><div>12.12%</div><div>0%</div><div>96.97%</div><div>696</div></div>
            </div>
          </div>
          <div class="analysis_chart platform-charts">
            <div class="chart_grid">
              <div class="chart">
                <div class="title">品牌推荐分析次数指数</div>
                <svg class="radar-svg" viewBox="0 0 430 307" role="img" aria-label="品牌推荐分析次数指数雷达图">
                  <g transform="translate(215 166)" fill="none">
                    <polygon points="0,-92 87.5,-28.4 54.1,74.4 -54.1,74.4 -87.5,-28.4" stroke="#e4e6f3"/>
                    <polygon points="0,-72 68.5,-22.3 42.3,58.2 -42.3,58.2 -68.5,-22.3" stroke="#e4e6f3"/>
                    <polygon points="0,-52 49.5,-16.1 30.6,42.1 -30.6,42.1 -49.5,-16.1" stroke="#e4e6f3"/>
                    <polygon points="0,-32 30.4,-9.9 18.8,25.9 -18.8,25.9 -30.4,-9.9" stroke="#e4e6f3"/>
                    <line x1="0" y1="0" x2="0" y2="-92" stroke="#e4e6f3"/>
                    <line x1="0" y1="0" x2="87.5" y2="-28.4" stroke="#e4e6f3"/>
                    <line x1="0" y1="0" x2="54.1" y2="74.4" stroke="#e4e6f3"/>
                    <line x1="0" y1="0" x2="-54.1" y2="74.4" stroke="#e4e6f3"/>
                    <line x1="0" y1="0" x2="-87.5" y2="-28.4" stroke="#e4e6f3"/>
                    <polygon points="0,-92 24.6,-8 24.1,33.1 -46,63.3 -36.7,-11.9" fill="rgba(105,108,240,.18)" stroke="#7778f5" stroke-width="2"/>
                    <circle cx="0" cy="-92" r="4" fill="#7778f5"/>
                    <circle cx="24.6" cy="-8" r="4" fill="#7778f5"/>
                    <circle cx="24.1" cy="33.1" r="4" fill="#7778f5"/>
                    <circle cx="-46" cy="63.3" r="4" fill="#7778f5"/>
                    <circle cx="-36.7" cy="-11.9" r="4" fill="#7778f5"/>
                  </g>
                  <text class="axis-label" x="215" y="54" text-anchor="middle">豆包</text>
                  <text class="axis-label" x="330" y="139" text-anchor="middle">千问</text>
                  <text class="axis-label" x="286" y="263" text-anchor="middle">文心一言</text>
                  <text class="axis-label" x="144" y="263" text-anchor="middle">腾讯元宝</text>
                  <text class="axis-label" x="92" y="139" text-anchor="middle">DeepSeek</text>
                </svg>
              </div>
              <div class="chart">
                <div class="title">品牌在各大模型的首位推荐率</div>
                <svg class="bar-svg" viewBox="0 0 430 307" role="img" aria-label="品牌在各大模型的首位推荐率柱状图">
                  <line x1="46" y1="245" x2="410" y2="245" stroke="#e5e5e5"/>
                  <line x1="46" y1="55" x2="46" y2="245" stroke="#e5e5e5"/>
                  <text class="axis-label" x="34" y="249" text-anchor="end">0%</text>
                  <text class="axis-label" x="34" y="205" text-anchor="end">20%</text>
                  <text class="axis-label" x="34" y="165" text-anchor="end">40%</text>
                  <text class="axis-label" x="34" y="125" text-anchor="end">60%</text>
                  <text class="axis-label" x="34" y="85" text-anchor="end">80%</text>
                  <text class="axis-label" x="34" y="45" text-anchor="end">100%</text>
                  <rect class="bar-fill" x="65" y="185" width="34" height="60"/>
                  <rect class="bar-fill" x="145" y="55" width="34" height="190"/>
                  <rect class="bar-fill" x="225" y="230" width="34" height="15"/>
                  <rect class="bar-fill" x="305" y="223" width="34" height="22"/>
                  <rect class="bar-fill" x="375" y="210" width="34" height="35"/>
                  <text class="axis-label" x="82" y="266" text-anchor="middle">豆包</text>
                  <text class="axis-label" x="162" y="266" text-anchor="middle">DeepSeek</text>
                  <text class="axis-label" x="242" y="266" text-anchor="middle">腾讯元宝</text>
                  <text class="axis-label" x="322" y="266" text-anchor="middle">文心一言</text>
                  <text class="axis-label" x="392" y="266" text-anchor="middle">千问</text>
                </svg>
              </div>
              <div class="chart">
                <div class="title">品牌各平台信源占比</div>
                <div class="donut-chart" style="--segments:#7da0f2 0 50.5%, #b8d1fb 50.5% 68.1%, #95bff4 68.1% 85.2%, #4d7fff 85.2% 92.8%, #2d65f3 92.8% 100%;"></div>
                <span class="source-label l1">豆包 4852</span>
                <span class="source-label l2">DeepSeek 730</span>
                <span class="source-label l3">腾讯元宝 1642</span>
                <span class="source-label l4">文心一言 1688</span>
                <span class="source-label l5">千问 696</span>
                <div class="chart-legend">
                  <span><i class="legend-dot" style="--dot:#7da0f2"></i>豆包</span>
                  <span><i class="legend-dot" style="--dot:#b8d1fb"></i>DeepSeek</span>
                  <span><i class="legend-dot" style="--dot:#95bff4"></i>腾讯元宝</span>
                  <span><i class="legend-dot" style="--dot:#4d7fff"></i>文心一言</span>
                  <span><i class="legend-dot" style="--dot:#2d65f3"></i>千问</span>
                </div>
              </div>
            </div>
          </div>
        </section>

        <section>
          <div class="competitiveness_analysis_com_title">
            <img class="title-bg" src="https://geo.zxaigc.com/assets/images/title-bg-163046e6.png" alt="" />
            <span class="title">五.&nbsp;&nbsp;&nbsp;竞品数据分析</span>
          </div>
          <div class="data_analysis competitor_area">
            <div class="text">同行竞品大模型能见度分析</div>
            <div class="ref_table competitor_table">
              <div class="ref_table_header"><div>类型</div><div>公司名称</div><div><span class="model-name"><span class="model-logo"><img src="assets/ai-platforms/deepseek.png" alt="DeepSeek"></span>DeepSeek</span></div><div><span class="model-name"><span class="model-logo"><img src="assets/ai-platforms/doubao.png" alt="豆包"></span>豆包</span></div><div><span class="model-name"><span class="model-logo"><img src="assets/ai-platforms/yuanbao.png" alt="腾讯元宝"></span>腾讯元宝</span></div><div><span class="model-name"><span class="model-logo"><img src="assets/ai-platforms/wenxin.png" alt="文心一言"></span>文心一言</span></div><div><span class="model-name"><span class="model-logo"><img src="assets/ai-platforms/qianwen.png" alt="千问"></span>千问</span></div></div>
              <div class="ref_table_row"><div><span class="tag">推荐竞品</span></div><div class="name_cell">新知地（成都）人工智能科技有限公司</div><div>0%</div><div>0%</div><div class="heat-50">10%</div><div class="heat-65">19.23%</div><div>0%</div></div>
              <div class="ref_table_row"><div><span class="tag">推荐竞品</span></div><div class="name_cell">成都新智地科技有限公司</div><div>0%</div><div>0%</div><div>0%</div><div class="heat-65">19.23%</div><div class="heat-60">12.12%</div></div>
              <div class="ref_table_row"><div><span class="tag">推荐竞品</span></div><div class="name_cell">成都智地人工智能科技有限公司</div><div>0%</div><div>0%</div><div>0%</div><div>0%</div><div class="heat-65">24.24%</div></div>
              <div class="ref_table_row"><div><span class="tag">推荐竞品</span></div><div class="name_cell">成都新知数智科技有限公司</div><div>0%</div><div>0%</div><div>0%</div><div>0%</div><div class="heat-65">21.21%</div></div>
              <div class="ref_table_row"><div><span class="tag">推荐竞品</span></div><div class="name_cell">成都知地智能科技有限公司</div><div class="heat-45">2.63%</div><div>0%</div><div class="heat-50">5%</div><div class="heat-60">11.54%</div><div>0%</div></div>
              <div class="ref_table_row"><div><span class="tag">推荐竞品</span></div><div class="name_cell">四川新知地数字科技有限公司</div><div class="heat-50">5.26%</div><div class="heat-60">11.11%</div><div class="heat-50">7.5%</div><div>0%</div><div>0%</div></div>
              <div class="ref_table_row"><div><span class="tag">推荐竞品</span></div><div class="name_cell">成都智境人工智能有限公司</div><div>0%</div><div>0%</div><div class="heat-50">10%</div><div class="heat-50">7.69%</div><div>0%</div></div>
              <div class="ref_table_row"><div><span class="tag">推荐竞品</span></div><div class="name_cell">成都知行智算科技有限公司</div><div>0%</div><div>0%</div><div>0%</div><div class="heat-65">23.08%</div><div>0%</div></div>
              <div class="ref_table_row"><div><span class="tag">推荐竞品</span></div><div class="name_cell">成都新地智能科技有限公司</div><div>0%</div><div>0%</div><div class="heat-60">12.5%</div><div>0%</div><div>0%</div></div>
              <div class="ref_table_row"><div><span class="tag">推荐竞品</span></div><div class="name_cell">四川知地云智能科技有限公司</div><div>0%</div><div>0%</div><div class="heat-50">10%</div><div class="heat-45">3.85%</div><div>0%</div></div>
            </div>
          </div>
        </section>

        <section>
          <div class="competitiveness_analysis_com_title">
            <img class="title-bg" src="https://geo.zxaigc.com/assets/images/title-bg-163046e6.png" alt="" />
            <span class="title">六.&nbsp;&nbsp;&nbsp;AI 搜索舆情概况</span>
          </div>
          <div class="analysis_chart">
            <div class="sentiment_grid">
              <div class="score_chart sentiment-overview-card">
                <div class="sentiment-donut"><div class="center"><div><b>92%</b><span>情感正向率</span></div></div></div>
                <div class="chart-legend">
                  <span><i class="legend-dot" style="--dot:#6669ff"></i>92% 正面</span>
                  <span><i class="legend-dot" style="--dot:#ff9245"></i>8% 中性</span>
                  <span><i class="legend-dot" style="--dot:#e94f4c"></i>0% 负面</span>
                </div>
              </div>
              <div class="chart2">
                <div class="title2">各平台舆情占比</div>
                <div class="public-opinion-share-chart">
                  <div class="public-opinion-share-item"><div class="top_content"><div class="left"><span class="model-logo"><img src="assets/ai-platforms/doubao.png" alt="豆包"></span>豆包</div><div class="right">正面100%</div></div><div class="bottom_content"><div class="front" style="width:100%"></div><div class="neutral" style="width:0%"></div><div class="back" style="width:0%"></div></div></div>
                  <div class="public-opinion-share-item"><div class="top_content"><div class="left"><span class="model-logo"><img src="assets/ai-platforms/deepseek.png" alt="DeepSeek"></span>DeepSeek</div><div class="right">正面100%</div></div><div class="bottom_content"><div class="front" style="width:100%"></div><div class="neutral" style="width:0%"></div><div class="back" style="width:0%"></div></div></div>
                  <div class="public-opinion-share-item"><div class="top_content"><div class="left"><span class="model-logo"><img src="assets/ai-platforms/qianwen.png" alt="千问"></span>千问</div><div class="right">正面97%</div></div><div class="bottom_content"><div class="front" style="width:97%"></div><div class="neutral" style="width:3%"></div><div class="back" style="width:0%"></div></div></div>
                  <div class="public-opinion-share-item"><div class="top_content"><div class="left"><span class="model-logo"><img src="assets/ai-platforms/wenxin.png" alt="文心一言"></span>文心一言</div><div class="right">正面96%</div></div><div class="bottom_content"><div class="front" style="width:96%"></div><div class="neutral" style="width:4%"></div><div class="back" style="width:0%"></div></div></div>
                  <div class="public-opinion-share-item"><div class="top_content"><div class="left"><span class="model-logo"><img src="assets/ai-platforms/yuanbao.png" alt="腾讯元宝"></span>腾讯元宝</div><div class="right">正面75%</div></div><div class="bottom_content"><div class="front" style="width:75%"></div><div class="neutral" style="width:25%"></div><div class="back" style="width:0%"></div></div></div>
                </div>
              </div>
            </div>
          </div>
        </section>
      </div>
    </main>
  </div>
  <script>
    window.__MONITORING_REPORT__ = {!! json_encode($reportData ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) !!};
  </script>
</body>
</html>
