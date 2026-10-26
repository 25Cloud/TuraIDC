# 文档术语表

翻译时统一用词。新增术语先加到本表，再动译文；同一概念全站一个译法。
繁体两列由 OpenCC 生成后人工复核过，遇到脚本覆盖不到的词，改
`backend/scripts/i18n/docs-i18n.mjs` 的 `TW_TERMS` / `HK_PRE_TERMS` / `HK_POST_TERMS`，不要手改生成物。

| 简体     | English                | 繁體（台灣） | 繁體（香港） |
| -------- | ---------------------- | ------------ | ------------ |
| 站长     | site operator          | 站長         | 站長         |
| 运营     | operations             | 營運         | 營運         |
| 上游     | upstream               | 上游         | 上游         |
| 供应商   | provider               | 供應商       | 供應商       |
| 开通     | provisioning           | 開通         | 開通         |
| 自动开通 | automatic provisioning | 自動開通     | 自動開通     |
| 实例     | instance               | 實例         | 實例         |
| 服务     | service                | 服務         | 服務         |
| 工单     | ticket                 | 工單         | 工單         |
| 对账     | reconciliation         | 對帳         | 對賬         |
| 商品     | product                | 商品         | 商品         |
| 商品分组 | product group          | 商品分組     | 商品分組     |
| 计费     | billing                | 計費         | 計費         |
| 账单     | invoice                | 帳單         | 賬單         |
| 资金流水 | balance history        | 資金流水     | 資金流水     |
| 余额     | balance                | 餘額         | 餘額         |
| 充值     | top-up                 | 儲值         | 充值         |
| 退款     | refund                 | 退款         | 退款         |
| 优惠券   | coupon                 | 優惠券       | 優惠券       |
| 分销返佣 | referral commission    | 分銷返傭     | 分銷返傭     |
| 代理折扣 | agent discount         | 代理折扣     | 代理折扣     |
| 实名认证 | real-name verification | 實名認證     | 實名認證     |
| 会员等级 | membership tier        | 會員等級     | 會員等級     |
| 控制台   | console                | 控制台       | 控制台       |
| 管理端   | admin panel            | 管理端       | 管理端       |
| 官网     | official website       | 官網         | 官網         |
| 主机     | host                   | 主機         | 主機         |
| 裸机     | bare metal             | 裸機         | 裸機         |
| 面板     | panel                  | 面板         | 面板         |
| 部署     | deployment             | 部署         | 部署         |
| 调度     | scheduling             | 排程         | 調度         |
| 计划任务 | scheduled task         | 排程任務     | 計劃任務     |
| 队列     | queue                  | 佇列         | 隊列         |
| 心跳     | heartbeat              | 心跳         | 心跳         |
| 日志归档 | log archiving          | 日誌歸檔     | 日誌歸檔     |
| 备份     | backup                 | 備份         | 備份         |
| 恢复     | restore                | 還原         | 恢復         |
| 迁移     | migration              | 移轉         | 遷移         |
| 升级     | upgrade                | 升級         | 升級         |
| 续费     | renewal                | 續約         | 續費         |
| 暂停     | suspend                | 暫停         | 暫停         |
| 释放     | release                | 釋放         | 釋放         |
| 配置项   | config option          | 設定項       | 配置項       |
| 库存     | stock                  | 庫存         | 庫存         |
| 插件     | plugin                 | 外掛         | 插件         |
| 接口     | API                    | 介面         | 接口         |
| 数据库   | database               | 資料庫       | 數據庫       |
| 网络     | network                | 網路         | 網絡         |
| 软件     | software               | 軟體         | 軟件         |
| 默认     | default                | 預設         | 默認         |
| 邮箱     | email                  | 電子郵件     | 電郵         |
| 文件     | file                   | 檔案         | 文件         |
| 数据     | data                   | 資料         | 數據         |
| 视频     | video                  | 影片         | 視頻         |
| 用户     | user                   | 使用者       | 用戶         |

## 不翻译的词

品牌与产品名保持原文：TuraIDC、ZJMF Finance、BaoTa Panel、1Panel、Docker、MySQL、Redis、Nginx、PHP、Laravel、Vue 3、TDesign、Element Plus、AGPL-3.0-or-later、GitHub、API。

协议字段、配置键、环境变量、命令、路径、状态字面量（`pending` / `active`）、占位符（`{{var}}`、`%s`）一律保持原样。
