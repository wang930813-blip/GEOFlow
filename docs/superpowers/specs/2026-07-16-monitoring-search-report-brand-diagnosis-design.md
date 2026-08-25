# 搜索报表接入品牌诊断动态数据设计

## 目标

企业舆情分析报表的“搜索报表”默认使用当前账号、当前站点的品牌诊断结果，不再默认展示页面内置演示数据。保留虚拟数据开关代码，仅供以后演示时显式启用。

## 数据范围

- 数据源为 `brand_diagnosis_results`，沿用 `MonitoringReportDataService` 的当前站点和账号归属过滤。
- 只展示状态为 `success` 且回答不为空的结果，按 `checked_at`、`id` 倒序，最多 80 条。
- 每条结果关联诊断问题、目标品牌提及和引用来源，不跨账号或跨站点读取。

## 字段映射

- 问题：`brand_diagnosis_questions.question`
- 平台：`BrandDiagnosisResult.platform`
- 查询时间：优先使用 `checked_at`
- 转化目标：目标品牌提及记录；没有提及则为 `-`
- 官方链接：经过平台官方域名校验的 `official_share_url`
- 快照凭证：`/brand-diagnosis/snapshot/{snapshot_token}`
- 转到平台：系统维护的模型平台官网地址
- 回答、引用来源：继续用于快照详情和报表数据结构

## 页面行为

- 动态模式下，官方链接为空时提示“暂无官方对话链接”。
- 快照凭证直接打开随机 token 的公开冻结快照，不再使用可枚举的结果 ID 页面。
- 转到平台继续打开对应模型官网。
- 平台筛选数量根据动态行实时统计；无数据时列表为空，不回退到静态演示数据。

## 配置

- 本地 `.env` 将 `GEOFLOW_MONITORING_REPORT_VIRTUAL_DATA` 设置为 `false`。
- `.env.example` 和 `.env.prod.example` 明确提供默认值 `false`。
- 代码保留 `true` 模式，只有显式开启时才使用静态演示数据。

## 测试

- 单元测试验证账号/站点范围、官方链接、token 快照链接、平台链接和查询时间。
- 页面测试验证动态行读取 `snapshot_url`，官方链接不再回退到相关文章。
- 验证动态模式空数据时不显示静态列表。

