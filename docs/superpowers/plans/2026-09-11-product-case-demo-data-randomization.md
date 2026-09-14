# 产品案例演示数据随机化实施计划

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 让案例库导入的演示诊断数据在保留 4 个固定 AI 模型的前提下呈现稳定差异，并且不再生成或展示行业竞品。

**Architecture:** 仅调整案例库演示数据服务和案例详情视图。每个品牌使用 SHA-256 派生的稳定种子决定问题数量、品牌提及、排名、情感和信源数量，重复导入结果一致；案例详情继续按站点、管理员和品牌读取真实诊断任务，手工创建案例的竞品数据不受影响。

**Tech Stack:** Laravel、Eloquent、Blade、PHPUnit、Docker Compose。

---

### Task 1: 固定导入数据的新行为

**Files:**
- Modify: `tests/Feature/ImportProductCasesCommandTest.php`

- [ ] **Step 1: 调整导入断言**

断言导入案例只产生目标品牌提及，不产生 `is_target_brand=false` 的竞品记录；断言案例正文不包含“数据说明”；断言两个示例案例的问题数或结果数允许不同但都使用 4 个平台。

- [ ] **Step 2: 增加差异性断言**

读取两个案例对应的完成任务，断言问题数、信源域名数或任务提及率至少有一项不同，并断言每条任务的平台集合等于 `BrandDiagnosisPlatform::keys()`。

- [ ] **Step 3: 运行测试确认先失败**

运行：

```powershell
docker compose exec -T app php artisan test tests/Feature/ImportProductCasesCommandTest.php
```

预期：由于当前服务仍创建竞品且问题数、信源数固定，新增断言失败。

### Task 2: 实现稳定随机的无竞品演示数据

**Files:**
- Modify: `app/Services/ProductCases/ProductCaseDemoDataService.php`

- [ ] **Step 1: 为每个品牌选择稳定的问题数量**

保留问题模板池，使用品牌种子把问题数量稳定映射到 6 至 9 条，并按顺序截取模板；4 个平台仍遍历 `BrandDiagnosisPlatform::keys()`。

- [ ] **Step 2: 为每个结果生成差异化指标**

使用品牌种子、问题索引和平台索引派生独立数值，生成 1 至 4 次提及、1 至 5 名排名和正面/中性/负面情感；让一部分结果不提及品牌并移除答案中的品牌名，从而形成非 100% 的提及率，同时保证每个平台至少有目标品牌结果。

- [ ] **Step 3: 生成可变信源**

从固定的演示域名池按派生索引选择 1 至 4 个来源，来源只用于演示引用，不生成竞品记录；删除竞品答案文本、竞品方法和竞品 `BrandDiagnosisBrandMention` 写入。

- [ ] **Step 4: 保持幂等和统计刷新**

保留现有 `product_case_seed` 清理、重建和 `BrandDiagnosisMetricsCalculator::refreshRun()` 流程，确保同一品牌重复导入的指标不漂移。

- [ ] **Step 5: 运行导入测试确认通过**

运行同一 PHPUnit 命令，预期全部通过。

### Task 3: 不显示无竞品案例的竞品面板

**Files:**
- Modify: `resources/views/product-cases/show.blade.php`
- Modify: `tests/Feature/ImportProductCasesCommandTest.php`

- [ ] **Step 1: 增加视图断言**

导入案例详情不应出现“竞品表现”或导入生成的竞品名称；保留 AI 平台表现、搜索报表和 GEO 总览。

- [ ] **Step 2: 条件渲染竞品面板**

仅当报告存在竞品数组时渲染 `Competitors/竞品表现` 面板；无竞品时不显示空占位，不影响有真实竞品数据的手工案例。

- [ ] **Step 3: 运行案例模块测试**

运行：

```powershell
docker compose exec -T app php artisan test tests/Feature/ProductCaseModuleTest.php
```

预期：现有手工案例竞品测试继续通过。

### Task 4: 本地导入与回归验证

**Files:**
- None

- [ ] **Step 1: 运行导入相关测试**

```powershell
docker compose exec -T app php artisan test tests/Unit/ProductCaseSpreadsheetReaderTest.php tests/Feature/ImportProductCasesCommandTest.php
```

- [ ] **Step 2: 执行本地正式导入**

```powershell
docker compose exec -T app php artisan geoflow:import-product-cases
```

预期：10 条案例更新，失败数为 0，图片复用，不重复上传。

- [ ] **Step 3: 抽查数据库**

确认 `product_case_seed` 任务均绑定超管默认站点，竞品提及数为 0，4 个平台都存在结果，且不同品牌的任务指标存在差异。

- [ ] **Step 4: 运行完整案例模块测试**

```powershell
docker compose exec -T app php artisan test tests/Feature/ProductCaseModuleTest.php
```

预期：全部通过。
