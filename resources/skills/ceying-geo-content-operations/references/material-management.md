# 素材管理

## 素材类型

| 业务类型 | `type` | 条目能力 |
| --- | --- | --- |
| 分类 | `categories` | 无独立条目 |
| 作者 | `authors` | 无独立条目 |
| 关键词库 | `keyword-libraries` | 查询、新增、批量删除关键词 |
| 标题库 | `title-libraries` | 查询、新增、批量删除标题 |
| 图片库 | `image-libraries` | 查询、新增、批量删除图片元数据 |
| 知识库 | `knowledge-bases` | 正文自动切块，切块只读 |

## 查询

1. 先调用 `geo_get_material_summary` 确认当前站点素材规模。
2. 使用 `geo_list_materials` 按类型和名称定位素材。
3. 使用 `geo_get_material` 核对单个素材完整信息。
4. 对支持条目的素材库使用 `geo_list_material_items`。分类和作者没有条目接口。

## 创建和更新

1. 创建前先按名称查询，避免重复素材库、作者或分类。
2. 只提交 MCP 工具 Schema 声明的字段，不根据其他项目经验补充字段。
3. 调用 `geo_create_material`、`geo_update_material` 或 `geo_create_material_item` 时生成并保留本次操作的幂等键。
4. 更新知识库正文后由 ceying-geo 自动重新切块，不直接创建或删除知识库切块。
5. 写入成功后重新读取目标素材，核对实际保存结果。

## 删除

1. 删除前读取目标素材或条目，列出类型、编号、名称或内容摘要及删除数量。
2. 得到用户明确确认后调用 `geo_delete_material` 或 `geo_delete_material_items`。
3. 批量删除只提交用户确认过的条目编号，最多遵循工具 Schema 的数量限制。
4. 素材被任务、文章或其他业务数据引用时接受 ceying-geo 的拒绝结果，不尝试绕过引用约束。
5. 删除请求结果未知时使用原参数和原幂等键重试，不扩展删除范围。
