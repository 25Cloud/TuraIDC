---
status: current
updated: 2026-10-21
owner: lead
---

# CLA 补签执行包（R1 路径）

> 配套文件：[`CLA.md`](../../../CLA.md)、[签署记录与历史贡献覆盖清单](cla-signing-register.md)、[法务可行性核对](cla-feasibility-2026-10-21.md)。
>
> **为什么必须补签**：AGPL 授权不可撤销，文件级排除不能切断授权链（AGPL 第 0 条把「修改」定义为复制或改编全部/部分作品，第 5(c) 条要求按 AGPL 授权整体作品）。补签是让「修改后闭源」合法成立的唯一机制，也是成本最低的一条路。
>
> **本文件不含个人邮箱**：联系邮箱请从 git 历史作者信息或 GitHub 公开资料取用，不要写回仓库。

## 1. 执行范围

需补签的历史贡献者身份组（共 7 组、298 次提交；`xiobb` 与 `xioiox`、`YeHuaiJing` 与 `FurLLCN` 均经项目方确认为同一自然人）：

| 身份组                       | GitHub 用户名                                       | 提交数 | 联系状态 | 签署范围   | 签署时间 |
| ---------------------------- | --------------------------------------------------- | -----: | -------- | ---------- | -------- |
| yealqp / 仙林loy             | `yealqp`、`仙林loy`                                 |    115 | 待联系   | 含历史贡献 | —        |
| YeHuaiJing 叶淮璟 / FurLLCN  | `YeHuaiJing`、`叶淮璟`、`FurLLCN`                   |     52 | 待联系   | 含历史贡献 | —        |
| Shanshui 山重水复            | `Shanshui-山重水复`、`Shanshui2024`、`Shanshui2023` |     46 | 待联系   | 含历史贡献 | —        |
| PigFully / PingFully         | `PigFully`、`PingFully`                             |     41 | 待联系   | 含历史贡献 | —        |
| xiobb / xioiox               | `xiobb`、`xioiox`、`yellowface233`                  |     32 | 待联系   | 含历史贡献 | —        |
| lan134342                    | `lan134342`                                         |      8 | 待联系   | 含历史贡献 | —        |
| 咕咕摆烂真君 / Gugubailan520 | `咕咕摆烂真君`、`Gugubailan520`                     |      4 | 待联系   | 含历史贡献 | —        |

> `Cascade`（`cascade@example.local`，1 次提交、1 个文件）来源不明，暂不联系，在权属确认前按「不包含」处理。

## 2. 签署声明原文（两种，择一）

### 2.1 仅覆盖生效日之后的新贡献

```text
I have read the CLA Document and I hereby sign the CLA（我已阅读并同意 CLA.md）
```

### 2.2 同时覆盖 2026-10-21 之前的历史贡献（**本轮请用这一条**）

```text
I have read the CLA Document and I hereby sign the CLA（我已阅读并同意 CLA.md），
and I confirm this CLA also covers my prior contributions before 2026-10-21
（并适用于我在 2026-10-21 前已提交的全部贡献）。
```

> 只用 2.1 的话，历史贡献仍按 AGPL 处理，不能进商业版本。

## 3. 联系函模板（中文）

```text
主题：TuraIDC CLA 签署邀请（历史贡献追溯覆盖）

你好，

感谢你此前为 TuraIDC（https://github.com/25Cloud/TuraIDC）作出的贡献。

说明一下背景：TuraIDC 采用 AGPL-3.0-or-later 与商业许可的双许可模式——任何人可以免费按 AGPL 使用；不愿承担 AGPL 强制开源义务的组织可以取得商业许可闭源使用。
要让商业许可在法律上成立，项目方需要拥有把贡献同时按 AGPL 与商业许可分发的再许可授权，因此我们从 2026-10-21 起启用了贡献者许可协议（CLA）。

**这不是追溯适用**：2026-10-21 之前你已提交的贡献，继续按原来的 AGPL 授权有效，项目方不会、也无法单方面改动它。你的署名与贡献记录也完整保留在仓库历史里。
我们只是希望**由你自愿补签**，把 2026-10-21 之前的贡献一并纳入双许可授权，这样你参与过的代码才能进入闭源商业版本。

如果你愿意，请在任意一个 Pull Request 的评论里回复下面这段声明（可署名，也可匿名/化名）：

    I have read the CLA Document and I hereby sign the CLA（我已阅读并同意 CLA.md），
    and I confirm this CLA also covers my prior contributions before 2026-10-21
    （并适用于我在 2026-10-21 前已提交的全部贡献）。

也可以直接回复本邮件表示同意，我们会把你的声明登记进项目方的签署记录（只登记 GitHub 用户名、签署范围、时间与声明原文，不对外公开邮箱）。

你的选择：
1. 同意补签 —— 回复上面的声明即可；
2. 暂不补签 —— 完全没有关系，你的贡献继续按 AGPL 有效，不影响署名，也不会被从仓库移除；
3. 有疑问 —— 直接回信，我们把 CLA 全文（https://github.com/25Cloud/TuraIDC/blob/main/CLA.md）发给你。

顺带一提，CLA 全文里也写明：你不授予的，项目方不会拿去商用；未取得再许可授权的历史贡献不会进入商业版本。

谢谢
TuraIDC 项目方
contact@turaidc.com
```

## 4. 联系函模板（英文）

```text
Subject: TuraIDC CLA signing request (retroactive coverage of past contributions)

Hi,

Thanks for your earlier contributions to TuraIDC (https://github.com/25Cloud/TuraIDC).

A short background: TuraIDC is dual-licensed — AGPL-3.0-or-later for everyone, plus a
commercial license for organisations that cannot take on AGPL's source-disclosure obligations.
For that commercial license to be legally clean, the project needs the right to relicense
contributions under both AGPL and the commercial terms, so we introduced a CLA effective 2026-10-21.

**This is not retroactive.** Contributions you made before 2026-10-21 remain under AGPL exactly as
before; we cannot and will not change that unilaterally. Your attribution stays in the repository history.
We would simply like you to **opt in**, so that the code you contributed can also be included in the
closed-source commercial edition.

If you are willing, please reply with the following statement in any pull request comment
(you may sign your name, or use a pseudonym):

    I have read the CLA Document and I hereby sign the CLA,
    and I confirm this CLA also covers my prior contributions before 2026-10-21.

Alternatively, just reply to this email — we will record the statement (GitHub username, scope, date
and the original text only; your email is never published).

Your options: (1) sign, (2) don't sign — nothing changes for you, your contribution stays AGPL and
your attribution is preserved, (3) ask questions — we will send you the full CLA text.

Thanks
The TuraIDC project
contact@turaidc.com
```

## 5. 发送方式与话术边界

- **优先 GitHub**：在贡献者仍有活动的仓库下开 issue 或在其 PR 下评论，天然带 GitHub 账号与时间戳，取证成本最低。
- **邮件兜底**：主题「CLA 签署 - GitHub 用户名」，正文写明 2.2 的声明原文与签署范围。
- **不要说**「不签就把你的代码从商业版删掉」这类施压表述；正确表述是「未取得再许可授权的贡献不会进入商业版本」，这是许可证的客观规则，不是对个人的要求。
- **不要说**「你已经默认同意了 CLA」——生效日之前的贡献不自动适用 CLA。
- 可以提供署名匿名/化名的选项（`CLA.md` 第 9 条第 3 项）。

## 6. 签署记录登记

收到声明后按 [清单 §6](cla-signing-register.md) 的字段登记，签署记录存项目方私有记录（邮件归档），仓库内只公示已签署者名单与聚合统计。

| 身份组 | GitHub 用户名 | 签署范围 | 签署时间 | 声明原文出处 | 登记人 |
| ------ | ------------- | -------- | -------- | ------------ | ------ |
| —      | —             | —        | —        | —            | —      |

## 7. 完成后要做的事

1. 全部外部贡献者补签完成后，在[清单 §5](cla-signing-register.md)里把「仅外部写过」与「混合」两类文件整体放行，解除 R1 门禁；
2. 商业版构建产物做一次逐文件比对并归档记录（清单 §7 第 3 条）；
3. 同步更新 `CLA.md` 第四部分的待办状态与 `LICENSE-COMMERCIAL.md` 第 9 条第 6 项。

## 8. 商业许可赠与（一年期）

对 8 组外部贡献者各赠与一年期商业许可。**赠与是无条件的**：无论对方是否签署 CLA 都会给，
不要把赠与与签署声明绑定成条件或对价（那会变成施压，也是法务审查点名要避免的表述）。

### 8.1 赠与范围

| 项   | 内容                                                               |
| ---- | ------------------------------------------------------------------ |
| 期限 | 自开通日起 1 年，不自动续期                                        |
| 计数 | 1 个组织、1 个生产部署                                             |
| 权益 | 授权范围内闭源使用与二次开发，免除 AGPL 第 13 条的网络源码提供义务 |
| 限制 | 不可转让、不可再许可；不授予商标与品牌使用权                       |
| 不含 | 付费客户的技术支持 / SLA / 定制开发（如需，另行商定）              |

依据：`LICENSE-COMMERCIAL.md` 第 2.5 条（赠与与优惠）与第 3 条（赠与同样取得完整授权）。

### 8.2 赠与函模板

```text
主题：TuraIDC 商业许可赠与函（致 {GitHub 用户名}）

{称呼}：

感谢你早期对 TuraIDC（https://github.com/25Cloud/TuraIDC）的贡献。随附一份商业许可赠与：

  - 授权范围：1 个组织、1 个生产部署
  - 有效期：自开通日起 1 年（不自动续期）
  - 权益：在授权范围内可闭源使用与二次开发，免除 AGPL-3.0-or-later 第 13 条
    「向网络用户提供对应源码」的义务
  - 限制：不可转让、不可再许可；不授予 TuraIDC 名称、Logo、商标的使用权
  - 说明：本赠与为无偿赠与，不收取费用；到期后不自动续期，如需继续使用请另行商定

授权编号：{授权编号}
开通日期：{开通日期}
到期日期：{到期日期}

本赠与与你是否签署 CLA 无关，不影响你在 AGPL 下的既有权利与署名。

TuraIDC 项目方
contact@turaidc.com
```

### 8.3 登记与留痕

- 《商业许可赠与函》与授权凭证（授权编号、被授权方、有效期、生产部署数量）存项目方私有记录；
- 发票口径：出具 0 元发票，或在赠与函写明「无偿赠与」；受赠方的个人所得税处理由受赠方自行申报
  （财税口径待确认，见 `LICENSE-COMMERCIAL.md` 第 9 条第 12 项）；
- 授权到期后按 `LICENSE-COMMERCIAL.md` 第 6 条处理，不再续赠；
- 在[清单 §6](cla-signing-register.md)的记录表里同步登记赠与事实（赠与与签署状态互相独立记录）。

## 9. 发信通道（2026-10-21 建立）

| 项       | 值                                                                      |
| -------- | ----------------------------------------------------------------------- |
| 发件地址 | `contact@turaidc.com`（对外公开的单一联系邮箱，CLA 与商业许可都写这个） |
| 备件地址 | `cla@turaidc.com`（按主题分流用；也可改成同一收件箱的别名）             |
| SMTP     | `mail.corptcha.com`：465 隐式 TLS（认证投递）、25 STARTTLS（本域投递）  |
| Webmail  | `https://mail.25y.cn/`（登录后进入 SOGo 邮箱）                          |
| 凭据存放 | 项目方私有记录（仓库内不落任何密码、邮箱凭据）                          |

**发送前检查**

1. **SPF**：`turaidc.com` 当前是 `v=spf1 -all`，等于不授权本机发信，收件方 SPF 校验必失败。
   建议改为 `v=spf1 mx -all`（MX 指向 `mail.corptcha.com`）。DKIM 已就绪
   （`dkim._domainkey.turaidc.com`），DMARC 是 `p=reject`，靠 DKIM 对齐过关，SPF 也别落下。
2. **认证**：API 建号的密码哈希与 dovecot 校验口径不一致，表现为 SMTP/IMAP 认证 535、
   日志里是 `Password mismatch`（面板与网页邮箱登录正常）。在邮件服务器上重置一次即可：
   `docker exec -i mailcow-mailcow doveadm pw -u contact@turaidc.com`（按提示输入两遍新密码；
   新版 mailcow 容器名为 `mailcow-dovecot-mailcow`）。
3. **取证**：发出的声明以「PR 评论」为最佳（自带账号与时间戳），邮件为兜底；
   收到的声明按[清单 §6](cla-signing-register.md)登记。
